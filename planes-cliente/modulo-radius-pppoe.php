<?php
/*
 * CLIENTES PPPoE EN RADIUS  (?modulo=radius-pppoe)
 * Lee el router (usuarios, sesiones, listas) y la base radius; no cambia nada en MikroTik.
 * Única escritura: "Dar de alta una cuenta PPPoE nueva en RADIUS" (radcheck/radreply), que nunca
 * modifica cuentas existentes. Los botones de suspender/cambiar plan se activan en la fase 2.
 *
 * Usa del index.php: $dbConfig, $mikrotikConfig, obtenerPDO(), crearClienteMikrotik(), escapar().
 */

use RouterOS\Client;
use RouterOS\Query;

require_once __DIR__ . '/radius-dhcp-acciones.php'; // clave de acciones y bitácora (compartidas con DHCP)
require_once __DIR__ . '/avisos-api.php';          // inventario de la plataforma (no dar de alta MACs sin registrar)

const PP_ROUTER = '10.0.1.6';
const PP_LISTA_DEUDORES = 'CLIENTES-DEUDORES';
const PP_LISTA_UISP = 'BLOCKED_USERS';

function ppLeer(Client $router, Query $query): array
{
    $respuesta = $router->query($query)->read();
    if (isset($respuesta['after']['message'])) {
        throw new RuntimeException('MikroTik: ' . $respuesta['after']['message']);
    }
    return array_values(array_filter($respuesta, fn ($f) => is_array($f) && isset($f['.id'])));
}

/** "20000000/25000000" (bits) -> "20M/25M". Subida/bajada, como lo muestra Winbox. */
function ppVelocidad(string $maxLimit): string
{
    $partes = [];
    foreach (explode('/', $maxLimit) as $v) {
        $n = (int) $v;
        $partes[] = $n >= 1000000 ? rtrim(rtrim(number_format($n / 1000000, 1, '.', ''), '0'), '.') . 'M'
            : ($n >= 1000 ? round($n / 1000) . 'k' : (string) $n);
    }
    return implode('/', $partes);
}

/* Bloques /22 de PPPoE habilitados en el router, en orden de uso (10.2.0.0/22 = 10.2.0.1 a 10.2.3.254).
   Cuando se acabe el primero sigue 10.2.4.0/22; para usarlo el ingeniero debe agregarlo al router
   (pool, ruta, colas) y aquí se añade a la lista. */
const PP_BLOQUES = ['10.2.0.0'];
const PP_GATEWAY = '10.2.0.1';
const PP_MODELOS = ['TP5', 'TP4', 'TPX', 'TPC', 'TPE', 'UAC'];

/** Perfiles que se pueden asignar en una alta (los de clientes; nunca los de prueba). */
function ppPerfilesAlta(array $perfiles): array
{
    return array_values(array_filter(array_keys($perfiles), fn ($p) => stripos($p, 'pro-pppoe') === 0));
}

/** Bloque /22 habilitado al que pertenece la IP (número de red), o null. */
function ppBloqueDe(int $n): ?int
{
    foreach (PP_BLOQUES as $b) {
        $red = ip2long($b);
        if ($n >= $red && $n <= $red + 1023) {
            return $red;
        }
    }
    return null;
}

/** IP que no se puede usar nunca: red y broadcast del /22, y el gateway. (Las .0 y .255 de en medio sí sirven.) */
function ppIpReservada(int $n): bool
{
    $red = ppBloqueDe($n);
    return $red === null || $n === $red || $n === $red + 1023 || $n === ip2long(PP_GATEWAY);
}

/**
 * $usadas: todas las IPs ocupadas (nunca se sugieren). $regla: solo las fijas de cuentas reales; la más
 * baja de estas marca dónde va la regla (las pruebas y las sesiones dinámicas no la mueven).
 * Regla de asignación: las IPs se dan de arriba hacia abajo. La siguiente es la que está justo debajo
 * de la más baja ya usada en el bloque (ej. si la última fue 10.2.1.39, sigue 10.2.1.38), saltando las
 * ocupadas. Cuando el bloque se acaba, se pasa al siguiente bloque habilitado empezando por arriba.
 */
function ppIpLibre(array $usadas, ?array $regla = null): string
{
    $regla ??= $usadas;
    foreach (PP_BLOQUES as $b) {
        $red = ip2long($b);
        $tope = $red + 1022;
        $minima = null;
        foreach (array_keys($regla) as $ip) {
            $n = ip2long((string) $ip);
            if ($n !== false && $n > $red && $n <= $tope && $n !== ip2long(PP_GATEWAY)) {
                $minima = $minima === null ? $n : min($minima, $n);
            }
        }
        for ($n = $minima === null ? $tope : $minima - 1; $n > $red; $n--) {
            if (!ppIpReservada($n) && !isset($usadas[long2ip($n)])) {
                return long2ip($n);
            }
        }
    }
    return '';
}

/** Cuentas de prueba (PRUEBA-...): apartan su IP pero no mueven la regla de asignación. */
function ppEsPrueba(string $usuario): bool
{
    return stripos($usuario, 'PRUEBA') === 0;
}

/** Siguiente bloque /22 que habría que habilitar en el router cuando se acaben los actuales. */
function ppSiguienteBloque(): string
{
    return long2ip(ip2long(PP_BLOQUES[count(PP_BLOQUES) - 1]) + 1024) . '/22';
}

/** Revisa una IP escrita a mano. Devuelve null si se puede usar, o el motivo en palabras. */
function ppRevisarIp(string $ip, array $usadas): ?string
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return 'No es una IP válida.';
    }
    $n = ip2long($ip);
    if (ppBloqueDe($n) === null) {
        return 'Está fuera de la red PPPoE habilitada (' . implode(', ', array_map(fn ($b) => $b . '/22', PP_BLOQUES)) . ').';
    }
    if (ppIpReservada($n)) {
        return 'No se puede usar: es la dirección de red, la de broadcast o el gateway ' . PP_GATEWAY . '.';
    }
    if (isset($usadas[$ip])) {
        return 'Ya la usa ' . $usadas[$ip] . '.';
    }
    return null;
}

/** Usuario según la regla: UBB- + modelo (3) + últimos 5 caracteres de la MAC LAN. Ej. TP5 + ..:0E:5D:C8 -> UBB-TP5E5DC8 */
function ppUsuarioDesdeMac(string $modelo, string $mac): ?string
{
    $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac));
    if (!in_array($modelo, PP_MODELOS, true) || strlen($hex) !== 12) {
        return null;
    }
    return 'UBB-' . $modelo . substr($hex, -5);
}

/** Contraseña fácil de dictar: 10 caracteres sin letras/números que se confunden. */
function ppGenerarPass(): string
{
    $abc = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $p = '';
    for ($i = 0; $i < 10; $i++) {
        $p .= $abc[random_int(0, strlen($abc) - 1)];
    }
    return $p;
}

function ppPlan(string $perfil): string
{
    if (preg_match('/(\d+)\s*m$/i', $perfil, $m)) {
        return $m[1] . 'M';
    }
    if (stripos($perfil, 'basico') !== false) {
        return 'Básico';
    }
    return $perfil !== '' ? $perfil : '—';
}

/* ---------- Datos ---------- */

$errorPp = '';
$clientesPp = [];
$enRadiusPp = [];
$perfilesPp = [];
$ipsUsadasPp = [];
$ipsReglaPp = []; // IPs fijas de cuentas reales (no pruebas, no sesiones dinámicas): marcan dónde va la regla
$secretsPp = [];
$soloRadiusPp = [];
$routerOkPp = false;
$colasDinPp = 0;
$colasFijasPp = 0;
$colasPp = [];
$colasOkPp = false;
$pdoPp = null;

$csrfPp = $_SESSION['csrf_radius_pppoe'] ?? '';
if (!is_string($csrfPp) || $csrfPp === '') {
    $csrfPp = bin2hex(random_bytes(32));
    $_SESSION['csrf_radius_pppoe'] = $csrfPp;
}
$flashPpOk = $_SESSION['flash_radius_pppoe_ok'] ?? '';
$flashPpError = $_SESSION['flash_radius_pppoe_error'] ?? '';
unset($_SESSION['flash_radius_pppoe_ok'], $_SESSION['flash_radius_pppoe_error']);

try {
    $configRouterPp = $mikrotikConfig;
    $configRouterPp['host'] = PP_ROUTER;
    $routerPp = crearClienteMikrotik($configRouterPp);

    $activos = [];
    foreach (ppLeer($routerPp, new Query('/ppp/active/print', ['=.proplist=.id,name,address,uptime,caller-id,radius'])) as $a) {
        $activos[strtolower((string) ($a['name'] ?? ''))] = $a;
        if (!empty($a['address'])) {
            $ipsUsadasPp[(string) $a['address']] = 'la sesión activa de ' . (string) ($a['name'] ?? '?');
        }
    }
    /* Colas simples: el router crea una dinámica "<pppoe-USUARIO>" por cada cliente conectado.
       Solo se leen para contarlas y mostrar la velocidad; no se cambian. */
    try {
        foreach (ppLeer($routerPp, new Query('/queue/simple/print', ['=.proplist=.id,name,dynamic,max-limit'])) as $q) {
            $dinamica = in_array(strtolower((string) ($q['dynamic'] ?? 'false')), ['true', 'yes'], true);
            if (!$dinamica) {
                $colasFijasPp++;
                continue;
            }
            $colasDinPp++;
            if (preg_match('/^<pppoe-(.+)>$/', (string) ($q['name'] ?? ''), $m)) {
                $colasPp[strtolower($m[1])] = ppVelocidad((string) ($q['max-limit'] ?? ''));
            }
        }
        $colasOkPp = true;
    } catch (Throwable $e) {
        error_log('UBB RADIUS PPPoE: sin colas: ' . $e->getMessage());
    }

    $bloqueadosUisp = [];
    foreach (ppLeer($routerPp, (new Query('/ip/firewall/address-list/print', ['=.proplist=.id,address']))->where('list', PP_LISTA_UISP)) as $f) {
        $bloqueadosUisp[(string) $f['address']] = true;
    }
    $deudores = [];
    foreach (ppLeer($routerPp, (new Query('/ip/firewall/address-list/print', ['=.proplist=.id,address']))->where('list', PP_LISTA_DEUDORES)) as $f) {
        $deudores[(string) $f['address']] = true;
    }
    foreach (ppLeer($routerPp, new Query('/ppp/profile/print', ['=.proplist=.id,name,rate-limit'])) as $p) {
        $perfilesPp[(string) $p['name']] = (string) ($p['rate-limit'] ?? '');
    }
    foreach (ppLeer($routerPp, new Query('/ppp/secret/print', ['=.proplist=.id,name,profile,remote-address,disabled,comment,last-logged-out,last-caller-id'])) as $s) {
        $usuario = (string) ($s['name'] ?? '');
        if ($usuario === '') {
            continue;
        }
        $ip = (string) ($s['remote-address'] ?? '');
        $secretsPp[strtolower($usuario)] = true;
        if ($ip !== '') {
            $ipsUsadasPp[$ip] = $usuario . ' (cuenta del router)';
            if (!ppEsPrueba($usuario)) {
                $ipsReglaPp[$ip] = true;
            }
        }
        $activo = $activos[strtolower($usuario)] ?? null;
        $clientesPp[] = [
            'usuario' => $usuario,
            'ip' => $ip,
            'perfil' => (string) ($s['profile'] ?? ''),
            'plan' => ppPlan((string) ($s['profile'] ?? '')),
            'desactivado' => in_array(strtolower((string) ($s['disabled'] ?? 'false')), ['true', 'yes'], true),
            'comentario' => (string) ($s['comment'] ?? ''),
            'en_linea' => $activo !== null,
            'por_radius' => $activo !== null && in_array(strtolower((string) ($activo['radius'] ?? 'false')), ['true', 'yes'], true),
            'uptime' => (string) ($activo['uptime'] ?? ''),
            'cola' => $colasPp[strtolower($usuario)] ?? '',
            'mac' => (string) ($activo['caller-id'] ?? ($s['last-caller-id'] ?? '')),
            'ultima' => (string) ($s['last-logged-out'] ?? ''),
            'uisp' => $ip !== '' && isset($bloqueadosUisp[$ip]),
            'suspendido' => $ip !== '' && isset($deudores[$ip]),
        ];
    }
    usort($clientesPp, fn ($a, $b) => strnatcasecmp($a['ip'], $b['ip']) ?: strnatcasecmp($a['usuario'], $b['usuario']));
    $routerOkPp = true;
} catch (Throwable $e) {
    error_log('UBB RADIUS PPPoE: ' . $e->getMessage());
    $errorPp = 'No fue posible leer los clientes PPPoE del router (' . PP_ROUTER . ').';
}

try {
    $configRdPp = $dbConfig;
    $configRdPp['database'] = 'radius';
    $pdoPp = obtenerPDO($configRdPp);
    foreach ($pdoPp->query("SELECT username FROM radcheck WHERE attribute = 'Cleartext-Password'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        $enRadiusPp[strtolower((string) $u)] = true;
    }
} catch (Throwable $e) {
    error_log('UBB RADIUS PPPoE: sin base radius: ' . $e->getMessage());
}

foreach ($clientesPp as &$c) {
    $c['radius'] = isset($enRadiusPp[strtolower($c['usuario'])]);
}
unset($c);

/* Servicio y cliente de cada cuenta según la plataforma de Jamileth (solo lectura). */
$serviciosPp = [];
try {
    $serviciosPp = obtenerServiciosPppoe(obtenerPDOJamileth());
} catch (Throwable $e) {
    error_log('UBB RADIUS PPPoE: sin servicios de la plataforma: ' . $e->getMessage());
}

/* Cuentas PPPoE dadas de alta solo en RADIUS (IP de la red PPPoE y sin cuenta local en el router) */
if ($pdoPp instanceof PDO) {
    try {
        $st = $pdoPp->query("SELECT ip.username, ip.value AS ip, gr.value AS perfil
                             FROM radreply ip
                             LEFT JOIN radreply gr ON gr.username = ip.username AND gr.attribute = 'Mikrotik-Group'
                             WHERE ip.attribute = 'Framed-IP-Address' AND ip.value LIKE '10.2.%'");
        foreach ($st->fetchAll() as $f) {
            $ipsUsadasPp[(string) $f['ip']] = (string) $f['username'] . ' (RADIUS)';
            if (!ppEsPrueba((string) $f['username'])) {
                $ipsReglaPp[(string) $f['ip']] = true;
            }
            if (!isset($secretsPp[strtolower((string) $f['username'])])) {
                $soloRadiusPp[] = $f;
                if ($routerOkPp) {
                    /* Alta nueva (sin cuenta en el router): entra por RADIUS igual que un migrado,
                       así que va en la tabla general con sus botones. */
                    $usuario = (string) $f['username'];
                    $ip = (string) $f['ip'];
                    $activo = $activos[strtolower($usuario)] ?? null;
                    $clientesPp[] = [
                        'usuario' => $usuario,
                        'ip' => $ip,
                        'perfil' => (string) ($f['perfil'] ?? ''),
                        'plan' => ppPlan((string) ($f['perfil'] ?? '')),
                        'desactivado' => true,
                        'solo_radius' => true,
                        'radius' => true,
                        'comentario' => '',
                        'en_linea' => $activo !== null,
                        'por_radius' => $activo !== null,
                        'uptime' => (string) ($activo['uptime'] ?? ''),
                        'cola' => $colasPp[strtolower($usuario)] ?? '',
                        'mac' => (string) ($activo['caller-id'] ?? ''),
                        'ultima' => '',
                        'uisp' => isset($bloqueadosUisp[$ip]),
                        'suspendido' => isset($deudores[$ip]),
                    ];
                }
            }
        }
        usort($soloRadiusPp, fn ($a, $b) => strnatcasecmp($a['ip'], $b['ip']));
        usort($clientesPp, fn ($a, $b) => strnatcasecmp($a['ip'], $b['ip']) ?: strnatcasecmp($a['usuario'], $b['usuario']));
    } catch (Throwable $e) {
        error_log('UBB RADIUS PPPoE: sin cuentas de RADIUS: ' . $e->getMessage());
    }
}
$perfilesAltaPp = ppPerfilesAlta($perfilesPp);
$ipSugeridaPp = ppIpLibre($ipsUsadasPp, $ipsReglaPp);

/* ---------- Alta de una cuenta PPPoE nueva en RADIUS ---------- */
/* ---------- Fase 2: suspender / reactivar / cambiar plan (solo clientes migrados) ---------- */

/** Corta la sesión PPPoE para que el módem se reconecte y tome lo nuevo de RADIUS. Devuelve si había sesión. */
function ppReconectar(Client $router, string $usuario): bool
{
    $hubo = false;
    foreach (ppLeer($router, (new Query('/ppp/active/print', ['=.proplist=.id']))->where('name', $usuario)) as $a) {
        ppLeer($router, (new Query('/ppp/active/remove'))->equal('.id', (string) $a['.id']));
        $hubo = true;
    }
    return $hubo;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['accion'] ?? '', ['pp_suspender', 'pp_reactivar', 'pp_plan'], true)) {
    $accionPp = (string) $_POST['accion'];
    try {
        if (!hash_equals($csrfPp, (string) ($_POST['csrf_radius_pppoe'] ?? ''))) {
            throw new RuntimeException('La sesión del formulario venció. Recarga la pantalla.');
        }
        if (!$routerOkPp || !($pdoPp instanceof PDO)) {
            throw new RuntimeException('Sin conexión con el router o con RADIUS. No se cambió nada.');
        }
        $bloqueo = (int) ($_SESSION['clave_rd_bloqueo'] ?? 0);
        if ($bloqueo > time()) {
            throw new RuntimeException('Demasiados intentos con clave incorrecta. Espera ' . (int) ceil(($bloqueo - time()) / 60) . ' min.');
        }
        if (!rdClaveConfigurada()) {
            throw new RuntimeException('Falta configurar la clave de acciones en el servidor.');
        }
        if (!rdVerificarClave((string) ($_POST['clave'] ?? ''))) {
            $fallos = (int) ($_SESSION['clave_rd_fallos'] ?? 0) + 1;
            $_SESSION['clave_rd_fallos'] = $fallos;
            if ($fallos >= 5) {
                $_SESSION['clave_rd_bloqueo'] = time() + 600;
                $_SESSION['clave_rd_fallos'] = 0;
            }
            throw new RuntimeException('Clave incorrecta. No se cambió nada.');
        }
        $_SESSION['clave_rd_fallos'] = 0;

        $usuario = (string) ($_POST['usuario'] ?? '');
        $cli = null;
        foreach ($clientesPp as $c) {
            if (strcasecmp($c['usuario'], $usuario) === 0) {
                $cli = $c;
                break;
            }
        }
        if ($cli === null) {
            throw new RuntimeException("No encontré la cuenta $usuario en el router.");
        }
        // (los contadores de la pantalla se calculan más abajo; aquí se revisa con los datos del momento)
        $cli['migrado'] = $cli['desactivado'] && $cli['radius'];
        $st = $pdoPp->prepare("SELECT COUNT(*) FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Address-List' AND value = ?");
        $st->execute([$cli['usuario'], PP_LISTA_DEUDORES]);
        $cli['susp_radius'] = (int) $st->fetchColumn() > 0;
        if (!$cli['migrado']) {
            throw new RuntimeException("$usuario todavía entra con su cuenta del router: cambiarlo en RADIUS no tendría efecto. Primero hay que migrarlo.");
        }
        $u = $cli['usuario'];
        $ip = $cli['ip'];

        if ($accionPp === 'pp_suspender') {
            if ($cli['susp_radius']) {
                throw new RuntimeException("$u ya estaba suspendido.");
            }
            $pdoPp->beginTransaction();
            $pdoPp->prepare("REPLACE INTO ubb_suspensiones (username, lista_original, ip, origen) VALUES (?, ?, ?, 'PANEL')")
                ->execute([$u, 'perfil ' . $cli['perfil'], $ip]);
            $pdoPp->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Address-List'")->execute([$u]);
            $pdoPp->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Address-List', ':=', ?)")
                ->execute([$u, PP_LISTA_DEUDORES]);
            $pdoPp->commit();
            // Antes de desconectarlo: el monitoreo lo cuenta como corte administrativo y no como falla.
            rdAvisarMonitoreo($pdoPp, 'BAJA_PAGO', ['usuario' => $u], 'Suspensión desde el panel', 'PANEL', $ip);
            $conectado = ppReconectar($routerPp, $u);
            rdBitacora($pdoPp, $u, $ip, 'suspender', 'PPPoE, estaba ' . $cli['perfil'], 'PANEL');
            $_SESSION['flash_radius_pppoe_ok'] = "Suspendido: $u ($ip). " . ($conectado ? 'Se reconectó y ya ve la página de adeudo.' : 'No estaba conectado; al conectarse verá la página de adeudo.');
        } elseif ($accionPp === 'pp_reactivar') {
            if (!$cli['susp_radius']) {
                throw new RuntimeException("$u no está suspendido por RADIUS.");
            }
            $pdoPp->beginTransaction();
            $pdoPp->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Address-List'")->execute([$u]);
            $pdoPp->prepare("DELETE FROM ubb_suspensiones WHERE username = ?")->execute([$u]);
            $pdoPp->commit();
            $conectado = ppReconectar($routerPp, $u);
            rdBitacora($pdoPp, $u, $ip, 'reactivar', 'PPPoE, vuelve a ' . $cli['perfil'], 'PANEL');
            rdAvisarMonitoreo($pdoPp, 'REACTIVACION', ['usuario' => $u], 'Reactivación desde el panel', 'PANEL', $ip);
            $_SESSION['flash_radius_pppoe_ok'] = "Reactivado: $u ($ip) con su plan " . ppPlan($cli['perfil']) . '. ' . ($conectado ? 'Se reconectó.' : 'Tomará su plan al conectarse.');
        } else {
            $perfil = (string) ($_POST['perfil'] ?? '');
            if (!in_array($perfil, $perfilesAltaPp, true)) {
                throw new RuntimeException('Elige un perfil de la lista.');
            }
            $st = $pdoPp->prepare("SELECT value FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Group'");
            $st->execute([$u]);
            $anterior = (string) ($st->fetchColumn() ?: '');
            if ($anterior === $perfil) {
                throw new RuntimeException("$u ya tiene el perfil $perfil.");
            }
            $pdoPp->beginTransaction();
            $pdoPp->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Group'")->execute([$u]);
            $pdoPp->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Group', ':=', ?)")->execute([$u, $perfil]);
            $pdoPp->commit();
            // La cuenta local (desactivada) se deja igual: es el respaldo para regresarlo si algo falla.
            $conectado = $cli['susp_radius'] ? false : ppReconectar($routerPp, $u);
            rdBitacora($pdoPp, $u, $ip, 'cambiar_plan', "PPPoE, $anterior -> $perfil", 'PANEL');
            rdAvisarMonitoreo($pdoPp, 'CAMBIO_PLAN', ['usuario' => $u], 'Cambio de ' . ppPlan($anterior) . ' a ' . ppPlan($perfil), 'PANEL', $ip);
            $_SESSION['flash_radius_pppoe_ok'] = "Plan cambiado: $u ahora tiene $perfil. "
                . ($cli['susp_radius'] ? 'Sigue suspendido; al reactivarlo tendrá el nuevo plan.' : ($conectado ? 'Se reconectó con la nueva velocidad.' : 'Tomará la nueva velocidad al conectarse.'));
        }
    } catch (Throwable $e) {
        if ($pdoPp instanceof PDO && $pdoPp->inTransaction()) {
            $pdoPp->rollBack();
        }
        $_SESSION['flash_radius_pppoe_error'] = $e instanceof RuntimeException ? $e->getMessage() : 'No se pudo completar la acción. No se cambió nada.';
        error_log('UBB RADIUS PPPoE ' . $accionPp . ': ' . $e->getMessage());
    }
    header('Location: ?modulo=radius-pppoe');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'alta_pppoe') {
    try {
        if (!hash_equals($csrfPp, (string) ($_POST['csrf_radius_pppoe'] ?? ''))) {
            throw new RuntimeException('La sesión del formulario venció. Recarga la pantalla.');
        }
        if (!$routerOkPp || !($pdoPp instanceof PDO)) {
            throw new RuntimeException('Sin conexión con el router o con RADIUS: no se puede revisar que el usuario y la IP estén libres.');
        }
        $usuario = ppUsuarioDesdeMac((string) ($_POST['modelo'] ?? ''), (string) ($_POST['mac'] ?? ''));
        if ($usuario === null) {
            throw new RuntimeException('Elige el modelo del módem y escribe su MAC LAN completa (12 caracteres, ej. 98:25:4A:EF:AA:8F).');
        }
        $perfil = (string) ($_POST['perfil'] ?? '');
        $pass = trim((string) ($_POST['pass'] ?? ''));
        $generada = false;
        if ($pass === '') {
            $pass = ppGenerarPass();
            $generada = true;
        }
        if (!preg_match('/^[A-Za-z0-9._@-]{3,64}$/', $usuario)) {
            throw new RuntimeException('Usuario no válido: 3 a 64 caracteres, solo letras, números y . _ @ -');
        }
        if (!preg_match('/^[A-Za-z0-9._@#*+=!?-]{6,64}$/', $pass)) {
            throw new RuntimeException('Contraseña no válida: 6 a 64 caracteres, sin espacios ni acentos.');
        }
        if (!in_array($perfil, $perfilesAltaPp, true)) {
            throw new RuntimeException('Elige un perfil de la lista.');
        }
        if (isset($secretsPp[strtolower($usuario)])) {
            throw new RuntimeException("El usuario $usuario ya existe en el router (/ppp secret). No se dio de alta.");
        }
        $st = $pdoPp->prepare('SELECT COUNT(*) FROM radcheck WHERE username = ?');
        $st->execute([$usuario]);
        if ((int) $st->fetchColumn() > 0) {
            throw new RuntimeException("El usuario $usuario ya existe en RADIUS. No se dio de alta.");
        }
        $ip = trim((string) ($_POST['ip'] ?? ''));
        if ($ip === '') {
            $ip = ppIpLibre($ipsUsadasPp, $ipsReglaPp);
            if ($ip === '') {
                throw new RuntimeException('Se acabaron las IPs del bloque PPPoE. El ingeniero debe habilitar el siguiente bloque ('
                    . ppSiguienteBloque() . ') en el router antes de dar más altas.');
            }
        } elseif (($motivoIp = ppRevisarIp($ip, $ipsUsadasPp)) !== null) {
            // Se revisa otra vez al guardar con los datos del momento, no solo en la pantalla.
            throw new RuntimeException("IP $ip: $motivoIp No se dio de alta.");
        }
        $notaInv = avExigirInventario((string) ($_POST['mac'] ?? ''));
        $pdoPp->beginTransaction();
        $pdoPp->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)")
            ->execute([$usuario, $pass]);
        $ins = $pdoPp->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, ?, ':=', ?)");
        $ins->execute([$usuario, 'Framed-IP-Address', $ip]);
        $ins->execute([$usuario, 'Mikrotik-Group', $perfil]);
        $pdoPp->commit();
        try {
            $pdoPp->prepare("INSERT INTO ubb_bitacora (mac, ip, accion, detalle, origen) VALUES ('', ?, 'alta-pppoe', ?, 'PANEL')")
                ->execute([$ip, "usuario $usuario, perfil $perfil"]);
        } catch (Throwable $e) {
            error_log('UBB RADIUS PPPoE: sin bitácora: ' . $e->getMessage());
        }
        $_SESSION['flash_radius_pppoe_ok'] = "Alta lista en RADIUS: usuario $usuario, IP fija $ip, perfil $perfil."
            . ($generada ? " Contraseña generada: $pass (anótala para configurar el módem; no se vuelve a mostrar)." : '')
            . ' Se conectará por RADIUS en cuanto el módem tenga ese usuario y contraseña.' . $notaInv;
    } catch (Throwable $e) {
        if ($pdoPp instanceof PDO && $pdoPp->inTransaction()) {
            $pdoPp->rollBack();
        }
        $_SESSION['flash_radius_pppoe_error'] = $e->getMessage();
    }
    header('Location: ?modulo=radius-pppoe');
    exit;
}

$totalPp = count($clientesPp);
$enLineaPp = count(array_filter($clientesPp, fn ($c) => $c['en_linea']));
$uispPp = count(array_filter($clientesPp, fn ($c) => $c['uisp']));
$suspRadiusPp = [];
if ($pdoPp instanceof PDO) {
    try {
        $st = $pdoPp->prepare("SELECT username FROM radreply WHERE attribute = 'Mikrotik-Address-List' AND value = ?");
        $st->execute([PP_LISTA_DEUDORES]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) {
            $suspRadiusPp[strtolower((string) $u)] = true;
        }
    } catch (Throwable $e) {
        error_log('UBB RADIUS PPPoE: sin suspensiones: ' . $e->getMessage());
    }
}
foreach ($clientesPp as &$c) {
    // Migrado = su cuenta local está desactivada y existe en RADIUS: ahora entra por RADIUS.
    $c['migrado'] = $c['desactivado'] && $c['radius'];
    $c['susp_radius'] = isset($suspRadiusPp[strtolower($c['usuario'])]);
}
unset($c);
$copiadosPp = count(array_filter($clientesPp, fn ($c) => $c['radius']));
$migradosPp = count(array_filter($clientesPp, fn ($c) => $c['migrado'] && empty($c['solo_radius'])));
$porRadiusPp = count(array_filter($clientesPp, fn ($c) => $c['por_radius']));
$sinColaPp = $colasOkPp ? count(array_filter($clientesPp, fn ($c) => $c['en_linea'] && $c['cola'] === '')) : 0;
$porPlanPp = [];
foreach ($clientesPp as $c) {
    $porPlanPp[$c['plan']] = ($porPlanPp[$c['plan']] ?? 0) + 1;
}
uksort($porPlanPp, fn ($a, $b) => ((int) $a ?: 999) <=> ((int) $b ?: 999));

require_once __DIR__ . '/layout-portal.php';
portalInicio('Clientes PPPoE en RADIUS', 'Usuarios PPPoE de MKR-FSL02 (' . PP_ROUTER . '): IP fija, plan, conexión y bloqueos.', 'radius-pppoe');
?>

    <div class="fase"><b>Fase 1 · solo consulta.</b> Esta pantalla no cambia nada. Los botones se activan para cada cliente cuando esté migrado a RADIUS
        (<?= $migradosPp ?> de <?= $totalPp ?> hoy; <?= $copiadosPp ?> ya tienen su copia en RADIUS). Para suspender o reactivar mientras tanto, usa UISP.</div>

    <?php if ($errorPp !== ''): ?><div class="error"><?= escapar($errorPp) ?></div><?php endif; ?>
    <?php if ($flashPpOk !== ''): ?><div class="ok"><?= escapar($flashPpOk) ?></div><?php endif; ?>
    <?php if ($flashPpError !== ''): ?><div class="error"><?= escapar($flashPpError) ?></div><?php endif; ?>

    <?php
    // Viene de "Avisos de equipos": modelo y MAC ya llenos.
    $altaMacPp = preg_match('/^[0-9A-F:.-]{12,17}$/i', (string) ($_GET['alta_mac'] ?? '')) ? strtoupper((string) $_GET['alta_mac']) : '';
    $altaModeloPp = in_array((string) ($_GET['alta_modelo'] ?? ''), PP_MODELOS, true) ? (string) $_GET['alta_modelo'] : '';
    ?>
    <details class="caja plegable" id="pp-alta" <?= ($flashPpError !== '' || $soloRadiusPp !== [] || $altaMacPp !== '') ? 'open' : '' ?>>
        <summary>Dar de alta una cuenta PPPoE nueva en RADIUS</summary>
        <p class="sub" style="margin:0 0 12px">Solo crea cuentas nuevas; no cambia ninguna existente. Revisa que el usuario no exista
            en el router ni en RADIUS. El usuario se arma solo: <b>UBB-</b> + modelo + últimos 5 de la MAC LAN.
            La IP sigue la regla de asignación: la que está justo debajo de la última dada (de arriba hacia abajo), y será siempre la misma.
            Mientras RADIUS para PPPoE no esté encendido en el router, la cuenta queda lista pero no se usa.</p>
        <?php if (!$routerOkPp): ?>
            <div class="aviso">Sin conexión con el router: no se puede dar de alta ahora.</div>
        <?php elseif ($ipSugeridaPp === ''): ?>
            <div class="error">Se acabaron las IPs del bloque PPPoE. El ingeniero debe habilitar el siguiente bloque
                (<?= escapar(ppSiguienteBloque()) ?>) en el router antes de dar más altas.</div>
        <?php else: ?>
        <form class="alta" method="post" action="?modulo=radius-pppoe"
              data-confirmar="¿Dar de alta esta cuenta PPPoE en RADIUS?"
              data-detalle="Se crea el usuario con la IP fija y el perfil elegidos. Antes de guardar se revisa otra vez que estén libres. No cambia ninguna cuenta existente."
              data-verbo="Dar de alta">
            <input type="hidden" name="csrf_radius_pppoe" value="<?= escapar($csrfPp) ?>">
            <input type="hidden" name="accion" value="alta_pppoe">
            <div>
                <label for="pp-modelo">Modelo del módem</label>
                <select id="pp-modelo" name="modelo" required>
                    <option value="">Elige…</option>
                    <?php foreach (PP_MODELOS as $m): ?><option value="<?= escapar($m) ?>" <?= $m === $altaModeloPp ? 'selected' : '' ?>><?= escapar($m) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="pp-mac">MAC LAN del módem</label>
                <input id="pp-mac" name="mac" class="mono" value="<?= escapar($altaMacPp) ?>" placeholder="98:25:4A:EF:AA:8F" required autocomplete="off" aria-describedby="pp-mac-msg">
                <small class="campo-msg" id="pp-mac-msg" aria-live="polite"></small>
            </div>
            <div>
                <label for="pp-usuario">Usuario (se arma solo)</label>
                <input id="pp-usuario" class="mono" readonly tabindex="-1" placeholder="UBB-TP5E5DC8" aria-describedby="pp-usuario-msg">
                <small class="campo-msg" id="pp-usuario-msg" aria-live="polite"></small>
            </div>
            <div>
                <label for="pp-pass">Contraseña</label>
                <input id="pp-pass" name="pass" placeholder="vacía = se genera una" autocomplete="new-password">
            </div>
            <div>
                <label for="pp-perfil">Perfil</label>
                <select id="pp-perfil" name="perfil" required>
                    <option value="">Elige…</option>
                    <?php foreach ($perfilesAltaPp as $p): ?><option value="<?= escapar($p) ?>"><?= escapar($p) ?> (<?= escapar(ppPlan($p)) ?>)</option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="pp-ip">IP fija</label>
                <input id="pp-ip" name="ip" value="<?= escapar($ipSugeridaPp) ?>" class="mono" inputmode="decimal" autocomplete="off"
                       placeholder="vacía = la siguiente según la regla" aria-describedby="pp-ip-msg">
                <small class="campo-msg" id="pp-ip-msg" aria-live="polite"></small>
            </div>
            <button class="b-alta" type="submit" id="pp-guardar">Dar de alta</button>
        </form>
        <script type="application/json" id="pp-ocupados"><?= json_encode([
            'ips' => $ipsUsadasPp,
            'bloques' => PP_BLOQUES,
            'gateway' => PP_GATEWAY,
            'sugerida' => $ipSugeridaPp,
            'usuarios' => array_values(array_unique(array_merge(array_keys($secretsPp), array_keys($enRadiusPp)))),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
        <?php endif; ?>
        <?php if ($soloRadiusPp !== []): ?>
            <h3 style="margin:18px 0 8px;font-size:15px">Cuentas dadas de alta solo en RADIUS (<?= count($soloRadiusPp) ?>)</h3>
            <div class="tabla"><table>
                <thead><tr><th>IP fija</th><th>Usuario</th><th>Perfil</th></tr></thead>
                <tbody>
                <?php foreach ($soloRadiusPp as $f): ?>
                    <tr><td data-t="IP" class="mono"><?= escapar($f['ip']) ?></td><td data-t="Usuario"><b><?= escapar($f['username']) ?></b></td>
                        <td data-t="Perfil" class="mono"><?= escapar((string) ($f['perfil'] ?? '—')) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </details>

    <section class="general">
        <div class="g-total"><b><?= $totalPp ?></b><span>clientes PPPoE<br>router + RADIUS</span></div>
        <div><b><?= $enLineaPp ?></b><span>en línea</span></div>
        <div><b><?= $uispPp ?></b><span>bloqueados en UISP</span></div>
        <div title="Su cuenta del router está desactivada y ahora entra por RADIUS"><b><?= $migradosPp ?></b><span>migrados a RADIUS<br><?= $porRadiusPp ?> conectados por RADIUS</span></div>
        <div title="Tienen su cuenta copiada en RADIUS, pero todavía entran con la cuenta del router"><b><?= $copiadosPp ?></b><span>copiados a RADIUS</span></div>
        <?php if ($colasOkPp): ?>
        <div title="Colas simples del router: las dinámicas las crea el router, una por cliente conectado; las fijas son las de la configuración.">
            <b><?= $colasDinPp + $colasFijasPp ?></b><span>colas simples<br><?= $colasDinPp ?> dinámicas · <?= $colasFijasPp ?> fijas</span></div>
        <?php endif; ?>
        <?php if ($porPlanPp !== []): ?><div class="g-planes"><span>Por plan</span>
            <?php foreach ($porPlanPp as $p => $n): ?><em><?= escapar($p) ?> · <?= $n ?></em><?php endforeach; ?>
        </div><?php endif; ?>
    </section>

    <?php if ($colasOkPp): ?>
        <?php if ($sinColaPp > 0): ?>
            <div class="error">Hay <?= $sinColaPp ?> cliente(s) en línea sin cola de velocidad. Filtra por "En línea sin cola" para verlos.</div>
        <?php else: ?>
            <div class="ok">Revisión: <?= $enLineaPp ?> en línea y <?= $colasDinPp ?> colas dinámicas. Cada cliente conectado tiene su cola de velocidad.</div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="filtros">
        <?= portalBuscador('buscar', 'Usuario, IP o MAC') ?>
        <select id="f-estado" aria-label="Estado">
            <option value="">Todos</option>
            <option value="linea">En línea</option>
            <option value="fuera">Desconectados</option>
            <option value="sincola">En línea sin cola</option>
            <option value="uisp">Bloqueados en UISP</option>
            <option value="migrado">Migrados a RADIUS</option>
            <option value="porradius">Conectados por RADIUS</option>
            <option value="radius">Copiados a RADIUS</option>
        </select>
        <select id="f-plan" aria-label="Plan">
            <option value="">Todos los planes</option>
            <?php foreach ($porPlanPp as $p => $n): ?><option value="<?= escapar($p) ?>"><?= escapar($p) ?></option><?php endforeach; ?>
        </select>
        <span class="cuenta" id="cuenta"></span>
    </div>

    <section class="caja">
        <div class="tabla">
        <table>
            <thead><tr><th>IP fija</th><th>Usuario</th><th>Plan</th><th>Conexión</th><th>Estado</th><th></th></tr></thead>
            <tbody id="filas">
            <?php if ($clientesPp === []): ?>
                <tr><td colspan="6">No hay datos del router.</td></tr>
            <?php endif; ?>
            <?php foreach ($clientesPp as $c):
                $estados = [];
                if ($c['en_linea']) $estados[] = 'linea'; else $estados[] = 'fuera';
                if ($colasOkPp && $c['en_linea'] && $c['cola'] === '') $estados[] = 'sincola';
                if ($c['uisp']) $estados[] = 'uisp';
                if ($c['radius']) $estados[] = 'radius';
                if ($c['migrado']) $estados[] = 'migrado';
                if ($c['por_radius']) $estados[] = 'porradius';
            ?>
                <?php $relPp = $serviciosPp[strtolower($c['usuario'])] ?? null; ?>
                <tr data-buscar="<?= escapar(strtolower($c['usuario'] . ' ' . $c['ip'] . ' ' . $c['mac'] . ' ' . $c['comentario'] . ' ' . ($relPp['nombre'] ?? '') . ' ' . ($relPp['servicio'] ?? ''))) ?>"
                    data-estado="<?= escapar(implode(' ', $estados)) ?>" data-plan="<?= escapar($c['plan']) ?>">
                    <td data-t="IP" class="mono"><?= escapar($c['ip']) ?></td>
                    <td data-t="Usuario"><b><?= escapar($c['usuario']) ?></b>
                        <?php if ($relPp): ?><small class="bloque"><?= escapar($relPp['nombre']) ?> · <b>Servicio <?= escapar($relPp['servicio']) ?></b></small>
                        <?php else: ?><small class="bloque tenue">— sin relación —</small><?php endif; ?>
                        <?php if ($c['comentario'] !== ''): ?><br><small><?= escapar($c['comentario']) ?></small><?php endif; ?></td>
                    <td data-t="Plan"><?= escapar($c['plan']) ?><br><small class="mono"><?= escapar($c['perfil']) ?></small></td>
                    <td data-t="Conexión">
                        <?php if ($c['en_linea']): ?>
                            <span class="pill ok">En línea</span> <small><?= escapar($c['uptime']) ?></small>
                            <?php if ($c['por_radius']): ?><small class="tenue"> · por RADIUS</small><?php endif; ?>
                            <?php if ($c['cola'] !== ''): ?><br><small title="Velocidad de su cola en el router (subida/bajada)">cola <?= escapar($c['cola']) ?></small>
                            <?php elseif ($colasOkPp): ?><br><span class="pill susp">Sin cola</span><?php endif; ?>
                        <?php else: ?>
                            <span class="pill gris">Desconectado</span>
                            <?php if ($c['ultima'] !== ''): ?><br><small>desde <?= escapar($c['ultima']) ?></small><?php endif; ?>
                        <?php endif; ?>
                        <?php if ($c['mac'] !== ''): ?><br><small class="mono"><?= escapar($c['mac']) ?></small><?php endif; ?>
                    </td>
                    <td data-t="Estado">
                        <?php if ($c['desactivado'] && !$c['migrado']): ?><span class="pill gris">Desactivado</span><?php endif; ?>
                        <?php if ($c['uisp']): ?><span class="pill uisp">Bloqueado en UISP</span><?php endif; ?>
                        <?php if ($c['suspendido'] || $c['susp_radius']): ?><span class="pill susp"><?= $c['susp_radius'] ? 'Suspendido RADIUS' : 'En CLIENTES-DEUDORES' ?></span><?php endif; ?>
                        <?php if (!$c['uisp'] && !$c['suspendido'] && !$c['susp_radius'] && (!$c['desactivado'] || $c['migrado'])): ?><span class="pill ok">Activo</span><?php endif; ?>
                        <br><?php if (!empty($c['solo_radius'])): ?><span class="pill rad" title="Dada de alta directo en RADIUS; no tiene cuenta en el router">Alta en RADIUS</span>
                        <?php elseif ($c['migrado']): ?><span class="pill rad" title="Su cuenta del router está desactivada; entra por RADIUS">Migrado a RADIUS</span>
                        <?php elseif ($c['radius']): ?><span class="pill gris" title="Tiene copia en RADIUS, pero todavía entra con la cuenta del router">Copia en RADIUS</span>
                        <?php else: ?><span class="pill gris">Solo en router</span><?php endif; ?>
                    </td>
                    <td><div class="acciones">
                        <?php if ($c['migrado']): ?>
                            <form method="post" action="?modulo=radius-pppoe" class="plan-form"
                                  data-confirmar="¿Cambiar el plan de <?= escapar($c['usuario']) ?>?"
                                  data-detalle="Se cambia su perfil en RADIUS y se reconecta (corte de unos segundos)."
                                  data-verbo="Cambiar plan" data-pedir-clave="1">
                                <input type="hidden" name="csrf_radius_pppoe" value="<?= escapar($csrfPp) ?>">
                                <input type="hidden" name="accion" value="pp_plan">
                                <input type="hidden" name="usuario" value="<?= escapar($c['usuario']) ?>">
                                <input type="hidden" name="clave" value="">
                                <select name="perfil" aria-label="Nuevo perfil" required>
                                    <?php foreach ($perfilesAltaPp as $p): ?><option value="<?= escapar($p) ?>" <?= $p === $c['perfil'] ? 'selected' : '' ?>><?= escapar(ppPlan($p)) ?></option><?php endforeach; ?>
                                </select>
                                <button class="b-plan" type="submit">Cambiar plan</button>
                            </form>
                            <form method="post" action="?modulo=radius-pppoe"
                                  data-confirmar="<?= $c['susp_radius'] ? '¿Reactivar' : '¿Suspender' ?> a <?= escapar($c['usuario']) ?>?"
                                  data-detalle="<?= $c['susp_radius'] ? 'Vuelve a su plan y se reconecta.' : 'Pasa a CLIENTES-DEUDORES (página de adeudo) y se reconecta.' ?> IP <?= escapar($c['ip']) ?>."
                                  data-verbo="<?= $c['susp_radius'] ? 'Reactivar' : 'Suspender' ?>" data-tono="<?= $c['susp_radius'] ? '' : 'peligro' ?>" data-pedir-clave="1">
                                <input type="hidden" name="csrf_radius_pppoe" value="<?= escapar($csrfPp) ?>">
                                <input type="hidden" name="accion" value="<?= $c['susp_radius'] ? 'pp_reactivar' : 'pp_suspender' ?>">
                                <input type="hidden" name="usuario" value="<?= escapar($c['usuario']) ?>">
                                <input type="hidden" name="clave" value="">
                                <button class="<?= $c['susp_radius'] ? 'b-alta' : 'b-susp' ?>" type="submit"><?= $c['susp_radius'] ? 'Reactivar' : 'Suspender' ?></button>
                            </form>
                        <?php elseif ($c['radius']): ?>
                            <span class="tenue" title="Tiene copia en RADIUS, pero entra con la cuenta del router. Se activan al migrarlo.">Aún no migrado</span>
                        <?php else: ?>
                            <span class="tenue" title="Los botones se activan cuando el cliente esté migrado a RADIUS">Solo consulta</span>
                        <?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
<?php portalFin(); ?>
<script>
/* Alta PPPoE: avisa en rojo mientras se escribe (el servidor vuelve a revisar al guardar). */
(function () {
    var datos = document.getElementById('pp-ocupados');
    if (!datos) return;
    var ocupados = JSON.parse(datos.textContent), usuarios = {};
    ocupados.usuarios.forEach(function (u) { usuarios[String(u).toLowerCase()] = true; });
    var u = document.getElementById('pp-usuario'), ip = document.getElementById('pp-ip'), boton = document.getElementById('pp-guardar'),
        modelo = document.getElementById('pp-modelo'), mac = document.getElementById('pp-mac');
    function num(t) {
        var p = t.split('.');
        if (p.length !== 4) return null;
        for (var i = 0; i < 4; i++) { if (!/^\d{1,3}$/.test(p[i]) || +p[i] > 255) return null; }
        return ((+p[0] * 256 + +p[1]) * 256 + +p[2]) * 256 + +p[3];
    }
    function hexMac() { return mac.value.replace(/[^0-9A-Fa-f]/g, '').toUpperCase(); }
    function revisarMac() {
        var h = hexMac();
        if (mac.value.trim() === '') return '';
        if (h.length !== 12) return 'La MAC debe tener 12 caracteres (ej. 98:25:4A:EF:AA:8F). Van ' + h.length + '.';
        return null;
    }
    function revisarUsuario() {
        var h = hexMac();
        u.value = (modelo.value && h.length === 12) ? 'UBB-' + modelo.value + h.slice(-5) : '';
        if (u.value === '') return modelo.value ? '' : (h.length === 12 ? 'Elige el modelo del módem.' : '');
        if (usuarios[u.value.toLowerCase()]) return 'Ese usuario ya existe (en el router o en RADIUS).';
        return null;
    }
    function revisarIp(v) {
        if (v === '') return null;
        var n = num(v);
        if (n === null) return 'No es una IP válida.';
        var red = null;
        ocupados.bloques.forEach(function (b) { var r = num(b); if (n >= r && n <= r + 1023) red = r; });
        if (red === null) return 'Está fuera de la red PPPoE habilitada (' + ocupados.bloques.map(function (b) { return b + '/22'; }).join(', ') + ').';
        if (n === red || n === red + 1023 || v === ocupados.gateway) return 'No se puede usar: es la dirección de red, la de broadcast o el gateway ' + ocupados.gateway + '.';
        if (ocupados.ips[v]) return 'Ya la usa ' + ocupados.ips[v] + '.';
        return null;
    }
    function pintar(campo, msg, okTexto) {
        var m = document.getElementById(campo.id + '-msg');
        campo.classList.toggle('campo-mal', !!msg);
        campo.setAttribute('aria-invalid', msg ? 'true' : 'false');
        m.className = 'campo-msg' + (msg ? ' mal' : (msg === null && okTexto ? ' bien' : ''));
        m.textContent = msg ? msg : (msg === null ? okTexto : '');
        return !msg;
    }
    function revisar() {
        var c = pintar(mac, revisarMac(), 'MAC completa');
        var a = pintar(u, revisarUsuario(), 'Disponible');
        var v = ip.value.trim();
        var b = pintar(ip, revisarIp(v), v === '' ? 'Se usará la siguiente según la regla (' + ocupados.sugerida + ')'
            : (v === ocupados.sugerida ? 'IP libre · la siguiente según la regla' : 'IP libre · ojo: no es la que sigue en la regla (' + ocupados.sugerida + ')'));
        boton.disabled = !(a && b && c) || u.value === '';
    }
    modelo.addEventListener('change', revisar);
    mac.addEventListener('input', revisar);
    ip.addEventListener('input', revisar);
    revisar();
})();
(function () {
    var filas = Array.prototype.slice.call(document.querySelectorAll('#filas tr[data-buscar]'));
    var buscar = document.getElementById('buscar'), estado = document.getElementById('f-estado'),
        plan = document.getElementById('f-plan'), cuenta = document.getElementById('cuenta');
    function filtrar() {
        var q = buscar.value.trim().toLowerCase(), e = estado.value, p = plan.value, n = 0;
        filas.forEach(function (tr) {
            var ver = (!q || tr.dataset.buscar.indexOf(q) !== -1)
                && (!e || (' ' + tr.dataset.estado + ' ').indexOf(' ' + e + ' ') !== -1)
                && (!p || tr.dataset.plan === p);
            tr.style.display = ver ? '' : 'none';
            if (ver) n++;
        });
        cuenta.textContent = n + ' de ' + filas.length + ' clientes';
    }
    var desdeUrl = new URLSearchParams(location.search).get('buscar');
    if (desdeUrl) { buscar.value = desdeUrl; } // viene del buscador general de Clientes DHCP
    buscar.addEventListener('input', filtrar);
    estado.addEventListener('change', filtrar);
    plan.addEventListener('change', filtrar);
    filtrar();
})();
</script>
</body>
</html>
