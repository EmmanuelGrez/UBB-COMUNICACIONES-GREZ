<?php
/*
 * CLIENTES DHCP EN RADIUS
 * Módulo independiente del portal (se incluye desde index.php con ?modulo=radius-dhcp).
 *
 * Qué hace:
 *   - Lista los clientes DHCP dados de alta en RADIUS por VLAN (IP fija, plan, estado, sesión).
 *   - Da de alta un cliente nuevo (MAC + IP fija) igual que alta-dhcp.sh.
 *   - Suspende y reactiva al momento, igual que ubb-suspension.sh:
 *       1) cambia la lista en radius.radreply (queda guardado aunque el router se reinicie)
 *       2) avisa al MikroTik por su API (bloqueo/desbloqueo inmediato y renovación de la concesión)
 *
 * Usa del index.php: $dbConfig, $mikrotikConfig, obtenerPDO(), obtenerPDOJamileth(),
 * obtenerMapaIntegracionDhcp(), crearClienteMikrotik(), escapar().
 */

use RouterOS\Client;
use RouterOS\Query;

const RD_LISTA_DEUDORES = 'CLIENTES-DEUDORES';
const RD_COMENTARIO_TEMPORAL = 'TEMP-PLAN';

// VLAN migradas a RADIUS. Para agregar otra, copia un bloque.
$vlansRadiusDhcp = [
    '1503' => [
        'prefijo' => '192.168.12.',
        'desde' => 2,
        'hasta' => 253,
        'lista' => '20M_VLAN1503',
        'servidor' => 'DHCP-SERVICE1503',
        'router' => '10.0.1.6',
    ],
    '1507' => [
        'prefijo' => '172.16.10.',
        'desde' => 2,
        'hasta' => 253,
        'lista' => '20M_VLAN1507',
        'servidor' => 'DHCP-SERVICE1507',
        'router' => '10.0.1.6',
    ],
    '1601' => [
        'prefijo' => '192.168.40.',
        'desde' => 2,
        'hasta' => 253,
        'lista' => '20M_VLAN1601',
        'servidor' => 'DHCP-SERVICE1601',
        'router' => '10.0.1.6',
    ],
    '1605' => [
        'prefijo' => '192.168.43.',
        'desde' => 2,
        'hasta' => 253,
        'lista' => '20M_VLAN1605',
        'servidor' => 'DHCP-SERVICE1605',
        'router' => '10.0.1.6',
    ],
    '1603' => [
        'prefijo' => '192.168.42.',
        'desde' => 2,
        'hasta' => 253,
        'lista' => '20M_VLAN1603',
        'servidor' => 'DHCP-SERVICE1603',
        'router' => '10.0.1.6',
    ],
    '1501' => [
        'prefijo' => '172.17.0.',
        'desde' => 2,
        'hasta' => 253,
        'lista' => '20M_VLAN1501',
        'servidor' => 'DHCP-SERVICE1501',
        'router' => '10.0.1.6',
    ],
    '1506' => [
        'prefijo' => '192.168.15.',
        'desde' => 2,
        'hasta' => 253,
        'lista' => '20M_VLAN1506',
        'servidor' => 'DHCP-SERVICE1506',
        'router' => '10.0.1.6',
    ],
    '1500' => [
        'prefijo' => '192.168.7.',
        'desde' => 2,
        'hasta' => 253,
        'lista' => '20M_VLAN1500',
        'servidor' => 'DHCP-SERVICE1500',
        'router' => '10.0.1.6',
    ],
    '1499' => [
        'prefijo' => '192.168.149.',
        'desde' => 2,
        'hasta' => 253,
        'lista' => '20M_VLAN1499',
        'servidor' => 'DHCP-SERVICE1499',
        'router' => '10.0.1.6',
    ],
    '41' => [
        'prefijo' => '10.1.41.',
        'desde' => 2,
        'hasta' => 253,
        'lista' => '20M_VLAN41',
        'servidor' => 'DHCP-SERVICE41',
        'router' => '10.0.1.6',
    ],
    '1600' => [
        'prefijo' => '172.17.3.',
        'desde' => 2,
        'hasta' => 253,
        'lista' => '20M_VLAN1600',
        'servidor' => 'DHCP-SERVICE1600',
        'router' => '10.0.1.6',
    ],
];

$vlanRd = (string) ($_GET['vlan'] ?? '1503');
if (!isset($vlansRadiusDhcp[$vlanRd])) {
    $vlanRd = '1503';
}
$cfgRd = $vlansRadiusDhcp[$vlanRd];

$csrfRd = $_SESSION['csrf_radius_dhcp'] ?? '';
if (!is_string($csrfRd) || $csrfRd === '') {
    $csrfRd = bin2hex(random_bytes(32));
    $_SESSION['csrf_radius_dhcp'] = $csrfRd;
}

$mensajeRd = (string) ($_SESSION['flash_radius_dhcp_ok'] ?? '');
$errorRd = (string) ($_SESSION['flash_radius_dhcp_error'] ?? '');
unset($_SESSION['flash_radius_dhcp_ok'], $_SESSION['flash_radius_dhcp_error']);

/* ---------- Funciones auxiliares ---------- */

function rdNormalizarMac(string $mac): string
{
    $mac = strtoupper(trim(str_replace('-', ':', $mac)));
    if (!preg_match('/^(?:[0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) {
        throw new RuntimeException('La MAC no es válida. Usa el formato AA:BB:CC:DD:EE:FF.');
    }
    return $mac;
}

/** Ejecuta una consulta en RouterOS y lanza excepción si el router responde con error. */
function rdRouter(Client $router, Query $query): array
{
    $respuesta = $router->query($query)->read();
    if (isset($respuesta['after']['message'])) {
        throw new RuntimeException('MikroTik: ' . $respuesta['after']['message']);
    }
    foreach ($respuesta as $fila) {
        if (is_array($fila) && isset($fila['message']) && count($fila) <= 2) {
            throw new RuntimeException('MikroTik: ' . $fila['message']);
        }
    }
    return $respuesta;
}

function rdIdsLista(Client $router, string $ip): array
{
    $filas = rdRouter($router, (new Query('/ip/firewall/address-list/print'))
        ->where('list', RD_LISTA_DEUDORES)
        ->where('address', $ip));
    return array_values(array_filter(array_map(fn ($f) => (string) ($f['.id'] ?? ''), $filas)));
}

function rdQuitarConcesion(Client $router, string $mac): int
{
    $filas = rdRouter($router, (new Query('/ip/dhcp-server/lease/print'))->where('mac-address', $mac));
    $borradas = 0;
    foreach ($filas as $fila) {
        $id = (string) ($fila['.id'] ?? '');
        if ($id !== '') {
            rdRouter($router, (new Query('/ip/dhcp-server/lease/remove'))->equal('.id', $id));
            $borradas++;
        }
    }
    return $borradas;
}

function rdLeerCliente(PDO $pdo, string $mac): array
{
    $st = $pdo->prepare("SELECT attribute, value FROM radreply
                         WHERE username = ? AND attribute IN ('Framed-IP-Address','Mikrotik-Address-List')");
    $st->execute([$mac]);
    $datos = ['ip' => '', 'lista' => ''];
    foreach ($st->fetchAll() as $f) {
        if ($f['attribute'] === 'Framed-IP-Address') $datos['ip'] = (string) $f['value'];
        if ($f['attribute'] === 'Mikrotik-Address-List') $datos['lista'] = (string) $f['value'];
    }
    if ($datos['ip'] === '' || $datos['lista'] === '') {
        throw new RuntimeException("La MAC $mac no tiene IP fija o lista en RADIUS.");
    }
    return $datos;
}

/** Planes que existen en el router para la VLAN: listas usadas por reglas mangle activas. */
function rdPlanesDisponibles(Client $router, string $vlan): array
{
    $planes = [];
    foreach (rdRouter($router, (new Query('/ip/firewall/mangle/print'))->where('action', 'mark-connection')) as $regla) {
        $lista = (string) ($regla['src-address-list'] ?? '');
        $desactivada = in_array(strtolower((string) ($regla['disabled'] ?? 'false')), ['true', 'yes'], true);
        if (!$desactivada && preg_match('/^(\d+)M_VLAN' . preg_quote($vlan, '/') . '$/', $lista, $m)) {
            $planes[$lista] = (int) $m[1];
        }
    }
    asort($planes);
    return array_keys($planes);
}

/** Bitacora de cambios hechos desde el panel (si la tabla no existe, no detiene nada). */
function rdBitacora(PDO $pdo, string $mac, string $ip, string $accion, string $detalle): void
{
    try {
        $pdo->prepare("INSERT INTO ubb_bitacora (mac, ip, accion, detalle, origen) VALUES (?, ?, ?, ?, 'PANEL')")
            ->execute([$mac, $ip, $accion, $detalle]);
    } catch (Throwable $e) {
        error_log('UBB RADIUS DHCP: sin bitacora: ' . $e->getMessage());
    }
}

/** Convierte una duración de RouterOS ("1d2h3m4s", "14m57s" o "00:14:57") a segundos. */
function rdSegundos(string $duracion): int
{
    $duracion = trim($duracion);
    if ($duracion === '') {
        return 0;
    }
    $total = 0;
    if (preg_match('/^(?:(\d+)w)?(?:(\d+)d)?(?:(\d+):(\d+):(\d+))?$/', $duracion, $m) && $duracion !== '') {
        if (isset($m[3]) && $m[3] !== '') {
            return (int) ($m[1] ?? 0) * 604800 + (int) ($m[2] ?? 0) * 86400
                + (int) $m[3] * 3600 + (int) $m[4] * 60 + (int) $m[5];
        }
    }
    if (preg_match_all('/(\d+)(w|d|h|ms|m|s)/', $duracion, $partes, PREG_SET_ORDER)) {
        $valor = ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1, 'ms' => 0];
        foreach ($partes as $p) {
            $total += (int) $p[1] * $valor[$p[2]];
        }
    }
    return $total;
}

/**
 * Lee cuántos segundos faltan para que el cliente renueve su IP (antes de borrar su concesión).
 * Devuelve null si no tiene concesión (equipo apagado): entonces no hace falta lista temporal.
 */
function rdSegundosParaRenovar(Client $router, string $mac, string $ip, string $servidor): ?int
{
    $vence = null;
    foreach (rdRouter($router, (new Query('/ip/dhcp-server/lease/print'))->where('mac-address', $mac)) as $fila) {
        if (isset($fila['expires-after'])) {
            $vence = rdSegundos((string) $fila['expires-after']);
        }
    }
    if ($vence === null || $vence <= 0) {
        // Sin concesión: si ya tiene una temporal de un cambio anterior, su tiempo dice cuándo renueva.
        foreach (rdRouter($router, (new Query('/ip/firewall/address-list/print'))->where('address', $ip)) as $fila) {
            if (strpos((string) ($fila['comment'] ?? ''), RD_COMENTARIO_TEMPORAL) === 0 && isset($fila['timeout'])) {
                return rdSegundos((string) $fila['timeout']) + 60;
            }
        }
        return null;
    }
    $duracion = 1800; // 30m por defecto
    foreach (rdRouter($router, (new Query('/ip/dhcp-server/print'))->where('name', $servidor)) as $fila) {
        if (isset($fila['lease-time'])) {
            $duracion = rdSegundos((string) $fila['lease-time']) ?: $duracion;
        }
    }
    return max(0, $vence - intdiv($duracion, 2)); // el equipo renueva a la mitad de la concesión
}

/**
 * Pone la IP en su lista nueva de inmediato con una entrada temporal que se borra sola
 * 1 minuto antes de que el equipo renueve, para no chocar con la lista que pone RADIUS.
 * Devuelve un texto corto para el mensaje del panel.
 */
function rdQuitarTemporales(Client $router, string $ip): void
{
    foreach (rdRouter($router, (new Query('/ip/firewall/address-list/print'))->where('address', $ip)) as $fila) {
        if (strpos((string) ($fila['comment'] ?? ''), RD_COMENTARIO_TEMPORAL) === 0 && isset($fila['.id'])) {
            rdRouter($router, (new Query('/ip/firewall/address-list/remove'))->equal('.id', (string) $fila['.id']));
        }
    }
}

function rdPuenteTemporal(Client $router, string $mac, string $ip, string $lista, ?int $renuevaEn): string
{
    rdQuitarTemporales($router, $ip); // por si cambian el plan dos veces seguidas
    if ($renuevaEn === null) {
        return 'No tiene concesión activa (apagado o renovando): toma el plan nuevo en cuanto pida IP, máximo 15 minutos.';
    }
    $duracion = $renuevaEn - 60;
    if ($duracion < 60) {
        return 'Su renovación ya está en curso: el plan nuevo se aplica en unos minutos.';
    }
    rdRouter($router, (new Query('/ip/firewall/address-list/add'))
        ->equal('list', $lista)
        ->equal('address', $ip)
        ->equal('timeout', $duracion . 's')
        ->equal('comment', RD_COMENTARIO_TEMPORAL . ' ' . $mac));
    return 'Aplicado de inmediato (lista temporal por ' . (int) ceil($duracion / 60) . ' min hasta que renueve).';
}

/** Corta las conexiones abiertas de la IP para que se vuelvan a marcar con el plan nuevo. */
function rdCortarConexiones(Client $router, string $ip): void
{
    try {
        $patron = '^' . str_replace('.', '\\\\.', $ip) . ':';
        rdRouter($router, (new Query('/execute'))
            ->equal('script', '/ip firewall connection remove [find where src-address~"' . $patron . '"]'));
    } catch (Throwable $e) {
        error_log('UBB RADIUS DHCP: no se cortaron conexiones de ' . $ip . ': ' . $e->getMessage());
    }
}

/* ---------- Conexión a la base radius ---------- */

$pdoRd = null;
$tablaSuspensionesRd = false;
try {
    $configRd = $dbConfig;
    $configRd['database'] = 'radius';
    $pdoRd = obtenerPDO($configRd);
    $tablaSuspensionesRd = (bool) $pdoRd->query("SHOW TABLES LIKE 'ubb_suspensiones'")->fetchColumn();
} catch (Throwable $e) {
    error_log('UBB RADIUS DHCP: sin conexión a radius: ' . $e->getMessage());
    $errorRd = 'No hay conexión con la base radius.';
}

/* ---------- Firma de estado: el navegador la consulta para refrescarse solo ---------- */

function rdFirmaEstado(PDO $pdo): string
{
    $f = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(CRC32(CONCAT(username, '=', value))), 0) AS s
                      FROM radreply WHERE attribute IN ('Mikrotik-Address-List', 'Framed-IP-Address')")->fetch();
    return $f['n'] . '-' . $f['s'];
}

if (($_GET['estado'] ?? '') === '1') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    try {
        echo json_encode(['firma' => $pdoRd instanceof PDO ? rdFirmaEstado($pdoRd) : '']);
    } catch (Throwable $e) {
        echo json_encode(['firma' => '']);
    }
    exit;
}

/* ---------- Acciones (POST) ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdoRd instanceof PDO) {
    try {
        if (!hash_equals($csrfRd, (string) ($_POST['csrf_radius_dhcp'] ?? ''))) {
            throw new RuntimeException('La sesión del formulario venció. Recarga la pantalla.');
        }
        $accion = (string) ($_POST['accion'] ?? '');
        $mac = rdNormalizarMac((string) ($_POST['mac'] ?? ''));

        if ($accion === 'alta') {
            $ip = trim((string) ($_POST['ip'] ?? ''));
            $octeto = substr($ip, strlen($cfgRd['prefijo']));
            if (strpos($ip, $cfgRd['prefijo']) !== 0 || !ctype_digit($octeto)
                || (int) $octeto < $cfgRd['desde'] || (int) $octeto > $cfgRd['hasta']) {
                throw new RuntimeException("La IP $ip no pertenece a la VLAN $vlanRd ("
                    . $cfgRd['prefijo'] . $cfgRd['desde'] . ' a ' . $cfgRd['prefijo'] . $cfgRd['hasta'] . ').');
            }
            $st = $pdoRd->prepare("SELECT COUNT(*) FROM radcheck WHERE username = ?");
            $st->execute([$mac]);
            if ((int) $st->fetchColumn() > 0) {
                throw new RuntimeException("La MAC $mac ya está dada de alta en RADIUS.");
            }
            $st = $pdoRd->prepare("SELECT username FROM radreply WHERE attribute = 'Framed-IP-Address' AND value = ?");
            $st->execute([$ip]);
            if ($ocupada = $st->fetchColumn()) {
                throw new RuntimeException("La IP $ip ya está asignada a $ocupada.");
            }
            $pdoRd->beginTransaction();
            $pdoRd->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Accept')")
                ->execute([$mac]);
            $ins = $pdoRd->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, ?, ':=', ?)");
            $ins->execute([$mac, 'Framed-IP-Address', $ip]);
            $ins->execute([$mac, 'Mikrotik-Address-List', $cfgRd['lista']]);
            $pdoRd->commit();
            $_SESSION['flash_radius_dhcp_ok'] = "Alta lista: $mac recibirá siempre la IP $ip ("
                . $cfgRd['lista'] . '). Al conectarse o renovar, el router le dará esa IP.';
        } elseif ($accion === 'suspender' || $accion === 'reactivar') {
            if (!$tablaSuspensionesRd) {
                throw new RuntimeException('Falta la tabla radius.ubb_suspensiones. Créala antes de suspender.');
            }
            $cliente = rdLeerCliente($pdoRd, $mac);
            $ip = $cliente['ip'];
            if (strpos($ip, $cfgRd['prefijo']) !== 0) {
                throw new RuntimeException("La MAC $mac no pertenece a la VLAN $vlanRd.");
            }
            $configRouter = $mikrotikConfig;
            $configRouter['host'] = $cfgRd['router'];
            $router = crearClienteMikrotik($configRouter);

            if ($accion === 'suspender') {
                if ($cliente['lista'] === RD_LISTA_DEUDORES) {
                    throw new RuntimeException("$mac ($ip) ya estaba suspendido.");
                }
                // 1. Bloqueo inmediato en el router (si falla, RADIUS no cambia)
                if (rdIdsLista($router, $ip) === []) {
                    rdRouter($router, (new Query('/ip/firewall/address-list/add'))
                        ->equal('list', RD_LISTA_DEUDORES)
                        ->equal('address', $ip)
                        ->equal('comment', 'SUSPENDIDO ' . $mac));
                }
                // 2. Guardar lista original y marcar como deudor en RADIUS
                $pdoRd->beginTransaction();
                $pdoRd->prepare("REPLACE INTO ubb_suspensiones (username, lista_original, ip) VALUES (?, ?, ?)")
                    ->execute([$mac, $cliente['lista'], $ip]);
                $pdoRd->prepare("UPDATE radreply SET value = ? WHERE username = ? AND attribute = 'Mikrotik-Address-List'")
                    ->execute([RD_LISTA_DEUDORES, $mac]);
                $pdoRd->commit();
                // 3. Renovar concesión para que tome lo nuevo de RADIUS
                rdQuitarConcesion($router, $mac);
                rdQuitarTemporales($router, $ip);
                $_SESSION['flash_radius_dhcp_ok'] = "Suspendido: $mac ($ip). Ya ve la página de aviso.";
                rdBitacora($pdoRd, $mac, $ip, 'suspender', 'estaba ' . $cliente['lista']);
            } else {
                $st = $pdoRd->prepare("SELECT lista_original FROM ubb_suspensiones WHERE username = ?");
                $st->execute([$mac]);
                $original = (string) ($st->fetchColumn() ?: '');
                if ($original === '') {
                    if ($cliente['lista'] !== RD_LISTA_DEUDORES) {
                        throw new RuntimeException("$mac ($ip) no está suspendido.");
                    }
                    $original = $cfgRd['lista']; // suspendido sin registro: se usa el plan de la VLAN
                }
                // 1. Regresar su plan en RADIUS
                $pdoRd->prepare("UPDATE radreply SET value = ? WHERE username = ? AND attribute = 'Mikrotik-Address-List'")
                    ->execute([$original, $mac]);
                // 2. Quitar bloqueo del router de inmediato
                foreach (rdIdsLista($router, $ip) as $id) {
                    try {
                        rdRouter($router, (new Query('/ip/firewall/address-list/remove'))->equal('.id', $id));
                    } catch (Throwable $e) {
                        // Las entradas dinámicas se van al borrar la concesión.
                    }
                }
                // 3. Renovar concesión (quita la lista dinámica de deudor) y poner su plan temporal
                $renuevaEn = rdSegundosParaRenovar($router, $mac, $ip, $cfgRd['servidor']);
                rdQuitarConcesion($router, $mac);
                $nota = rdPuenteTemporal($router, $mac, $ip, $original, $renuevaEn);
                $pdoRd->prepare("DELETE FROM ubb_suspensiones WHERE username = ?")->execute([$mac]);
                rdBitacora($pdoRd, $mac, $ip, 'reactivar', 'vuelve a ' . $original);
                $_SESSION['flash_radius_dhcp_ok'] = "Reactivado: $mac ($ip) con $original. $nota";
            }
        } elseif ($accion === 'cambiar_plan') {
            $plan = (string) ($_POST['plan'] ?? '');
            $cliente = rdLeerCliente($pdoRd, $mac);
            $ip = $cliente['ip'];
            if (strpos($ip, $cfgRd['prefijo']) !== 0) {
                throw new RuntimeException("La MAC $mac no pertenece a la VLAN $vlanRd.");
            }
            $configRouter = $mikrotikConfig;
            $configRouter['host'] = $cfgRd['router'];
            $router = crearClienteMikrotik($configRouter);
            if (!in_array($plan, rdPlanesDisponibles($router, $vlanRd), true)) {
                throw new RuntimeException("El plan $plan no existe en el router para la VLAN $vlanRd. No se cambió nada.");
            }
            if ($cliente['lista'] === RD_LISTA_DEUDORES) {
                // Suspendido: se guarda el plan nuevo para cuando se reactive.
                $st = $pdoRd->prepare("UPDATE ubb_suspensiones SET lista_original = ? WHERE username = ?");
                $st->execute([$plan, $mac]);
                if ($st->rowCount() === 0) {
                    throw new RuntimeException("$mac está suspendido pero no tiene registro de suspensión. No se cambió nada.");
                }
                $_SESSION['flash_radius_dhcp_ok'] = "Plan guardado: $mac ($ip) tendrá $plan cuando se reactive.";
            } else {
                if ($cliente['lista'] === $plan) {
                    throw new RuntimeException("$mac ($ip) ya tiene el plan $plan.");
                }
                $renuevaEn = rdSegundosParaRenovar($router, $mac, $ip, $cfgRd['servidor']);
                $pdoRd->prepare("UPDATE radreply SET value = ? WHERE username = ? AND attribute = 'Mikrotik-Address-List'")
                    ->execute([$plan, $mac]);
                rdQuitarConcesion($router, $mac);
                $nota = rdPuenteTemporal($router, $mac, $ip, $plan, $renuevaEn);
                rdCortarConexiones($router, $ip);
                $_SESSION['flash_radius_dhcp_ok'] = "Plan cambiado: $mac ($ip) de {$cliente['lista']} a $plan. $nota";
            }
            rdBitacora($pdoRd, $mac, $ip, 'cambiar_plan', $cliente['lista'] . ' -> ' . $plan);
        } else {
            throw new RuntimeException('Operación no reconocida.');
        }
    } catch (Throwable $e) {
        if ($pdoRd->inTransaction()) {
            $pdoRd->rollBack();
        }
        error_log('UBB RADIUS DHCP: ' . $e->getMessage());
        $msg = $e->getMessage();
        if ($e instanceof PDOException) {
            $msg = 'La base radius rechazó el cambio (revisa los permisos del usuario SQL del portal).';
        }
        $_SESSION['flash_radius_dhcp_error'] = $msg;
    }
    header('Location: ?modulo=radius-dhcp&vlan=' . rawurlencode($vlanRd));
    exit;
}

/* ---------- Datos para la tabla ---------- */

$clientesRd = [];
$ipSugeridaRd = '';
if ($pdoRd instanceof PDO) {
    try {
        $st = $pdoRd->prepare("
            SELECT ip.username AS mac, ip.value AS ip, li.value AS lista,
                   (SELECT MIN(a.acctstarttime) FROM radacct a
                     WHERE a.username = ip.username AND a.acctstoptime IS NULL) AS sesion_desde
            FROM radreply ip
            LEFT JOIN radreply li
                   ON li.username = ip.username AND li.attribute = 'Mikrotik-Address-List'
            WHERE ip.attribute = 'Framed-IP-Address' AND ip.value LIKE ?
            ORDER BY INET_ATON(ip.value)
        ");
        $st->execute([$cfgRd['prefijo'] . '%']);
        $clientesRd = $st->fetchAll();

        $usadas = array_column($clientesRd, 'ip');
        for ($i = $cfgRd['desde']; $i <= $cfgRd['hasta']; $i++) {
            if (!in_array($cfgRd['prefijo'] . $i, $usadas, true)) {
                $ipSugeridaRd = $cfgRd['prefijo'] . $i;
                break;
            }
        }
    } catch (Throwable $e) {
        error_log('UBB RADIUS DHCP: error de consulta: ' . $e->getMessage());
        $errorRd = $errorRd ?: 'No fue posible consultar los clientes en RADIUS.';
    }

    // Nombre del cliente desde la base de Jamileth (opcional)
    try {
        $mapaJamRd = obtenerMapaIntegracionDhcp(obtenerPDOJamileth());
        foreach ($clientesRd as &$c) {
            $rel = $mapaJamRd[strtolower($cfgRd['servidor']) . '|' . strtoupper($c['mac'])] ?? null;
            $c['nombre'] = $rel['nombre_cliente'] ?? '';
        }
        unset($c);
    } catch (Throwable $e) {
        error_log('UBB RADIUS DHCP: sin nombres de Jamileth: ' . $e->getMessage());
    }
}

$planesRd = [];
$errorPlanesRd = '';
try {
    $configPlanesRd = $mikrotikConfig;
    $configPlanesRd['host'] = $cfgRd['router'];
    $planesRd = rdPlanesDisponibles(crearClienteMikrotik($configPlanesRd), $vlanRd);
} catch (Throwable $e) {
    error_log('UBB RADIUS DHCP: sin planes del router: ' . $e->getMessage());
    $errorPlanesRd = 'No se pudo consultar los planes en el router; el cambio de plan no está disponible.';
}

/* Conteo general de servicios DHCP en RADIUS (todas las VLAN) */
$globalRd = ['total' => 0, 'suspendidos' => 0, 'enLinea' => 0, 'porVlan' => [], 'porPlan' => []];
if ($pdoRd instanceof PDO) {
    try {
        $filasGlobal = $pdoRd->query("
            SELECT ip.value AS ip, li.value AS lista,
                   EXISTS (SELECT 1 FROM radacct a
                            WHERE a.username = ip.username AND a.acctstoptime IS NULL) AS en_linea
            FROM radreply ip
            LEFT JOIN radreply li
                   ON li.username = ip.username AND li.attribute = 'Mikrotik-Address-List'
            WHERE ip.attribute = 'Framed-IP-Address'
        ")->fetchAll();
        foreach ($filasGlobal as $f) {
            foreach ($vlansRadiusDhcp as $v => $cfgV) {
                if (strpos((string) $f['ip'], $cfgV['prefijo']) === 0) {
                    $globalRd['total']++;
                    $globalRd['porVlan'][$v] = ($globalRd['porVlan'][$v] ?? 0) + 1;
                    if ($f['lista'] === RD_LISTA_DEUDORES) {
                        $globalRd['suspendidos']++;
                    } elseif (preg_match('/^(\d+M)_VLAN/', (string) $f['lista'], $m)) {
                        $globalRd['porPlan'][$m[1]] = ($globalRd['porPlan'][$m[1]] ?? 0) + 1;
                    }
                    if ((int) $f['en_linea'] === 1) {
                        $globalRd['enLinea']++;
                    }
                    break;
                }
            }
        }
        uksort($globalRd['porPlan'], fn ($a, $b) => (int) $a <=> (int) $b);
    } catch (Throwable $e) {
        error_log('UBB RADIUS DHCP: sin conteo general: ' . $e->getMessage());
    }
}

$totalRd = count($clientesRd);
$suspendidosRd = count(array_filter($clientesRd, fn ($c) => $c['lista'] === RD_LISTA_DEUDORES));
$enLineaRd = count(array_filter($clientesRd, fn ($c) => !empty($c['sesion_desde'])));

$firmaRd = '';
if ($pdoRd instanceof PDO) {
    try { $firmaRd = rdFirmaEstado($pdoRd); } catch (Throwable $e) { $firmaRd = ''; }
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Clientes DHCP RADIUS | UBB Comunicaciones</title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;padding:24px;background:#f5f3f8;color:#292333;font-family:Arial,sans-serif}
        main{max-width:1100px;margin:0 auto}
        a{color:#60418e}
        h1{margin:12px 0 4px;color:#49366b}
        .sub{margin:0 0 18px;color:#5d5666;font-size:14px}
        .tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
        .tab{padding:8px 16px;border-radius:999px;background:#fff;border:1px solid #d9cfe6;text-decoration:none;font-weight:bold}
        .tab.activo{background:#6945a2;color:#fff;border-color:#6945a2}
        .tab small{display:inline-block;margin-left:4px;padding:1px 7px;border-radius:999px;background:#efe9f7;color:#49366b;font-size:11px}
        .tab.activo small{background:rgba(255,255,255,.22);color:#fff}
        .general{display:flex;flex-wrap:wrap;gap:22px;align-items:center;background:#49366b;color:#fff;border-radius:14px;padding:14px 20px;margin-bottom:16px}
        .general b{display:block;font-size:24px}
        .general span{font-size:12px;opacity:.85}
        .general .g-total b{font-size:34px}
        .general .g-total{padding-right:22px;border-right:1px solid rgba(255,255,255,.25)}
        .general .g-planes{margin-left:auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center}
        .general em{font-style:normal;background:rgba(255,255,255,.15);padding:4px 10px;border-radius:999px;font-size:13px;font-weight:bold}
        .resumen{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px}
        .tarjeta{background:#fff;border:1px solid #e8e1ef;border-radius:12px;padding:14px}
        .tarjeta b{display:block;font-size:26px;color:#49366b}
        .tarjeta span{font-size:13px;color:#5d5666}
        .caja{background:#fff;border:1px solid #e8e1ef;border-radius:14px;padding:18px;margin-bottom:16px}
        .mensaje,.error,.aviso{padding:12px 14px;border-radius:10px;margin-bottom:14px;font-size:14px}
        .mensaje{background:#eaf7ee;color:#185b31}
        .error{background:#fff0f0;color:#8a2424}
        .aviso{background:#fff8e6;color:#6b4e00}
        table{width:100%;border-collapse:collapse;font-size:14px}
        th,td{padding:10px 8px;border-bottom:1px solid #eee7f4;text-align:left;vertical-align:middle}
        th{font-size:12px;text-transform:uppercase;color:#6d6478}
        .pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:bold}
        .ok{background:#eaf7ee;color:#185b31}
        .susp{background:#fff0f0;color:#8a2424}
        .gris{background:#f0edf4;color:#6d6478}
        button{padding:8px 14px;border:0;border-radius:8px;font-weight:bold;cursor:pointer}
        .b-susp{background:#c0392b;color:#fff}
        .b-react{background:#1e8449;color:#fff}
        .b-plan{background:#6945a2;color:#fff}
        .acciones{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:flex-end}
        .acciones form.inline{display:flex;gap:6px;align-items:center}
        select{padding:7px 8px;border:1px solid #cfc5dc;border-radius:8px;font-size:14px;background:#fff}
        .b-alta{background:#6945a2;color:#fff;padding:11px 18px}
        form.inline{margin:0}
        .alta{display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end}
        label{display:block;font-size:13px;font-weight:bold;margin-bottom:6px}
        input{width:100%;padding:10px;border:1px solid #cfc5dc;border-radius:8px;font-size:15px;font-family:monospace}
        .mono{font-family:monospace}
        @media (max-width:760px){
            body{padding:12px}
            .alta{grid-template-columns:1fr}
            table,thead,tbody,tr,td{display:block}
            thead{display:none}
            tr{border:1px solid #e8e1ef;border-radius:12px;margin-bottom:10px;padding:6px;background:#fff}
            td{border:0;padding:6px 8px}
            td[data-t]::before{content:attr(data-t) ": ";font-weight:bold;color:#6d6478}
        }
    </style>
</head>
<body>
<main>
    <a href="?modulo=inicio">← Volver al portal</a>
    <h1>Clientes DHCP en RADIUS</h1>
    <p class="sub">IP fija, plan y suspensión de cada cliente. Los cambios se guardan en RADIUS y se aplican al momento en el router.</p>

    <section class="general">
        <div class="g-total"><b><?= $globalRd['total'] ?></b><span>servicios DHCP en total<br><?= count($vlansRadiusDhcp) ?> VLAN</span></div>
        <div><b><?= $globalRd['total'] - $globalRd['suspendidos'] ?></b><span>activos</span></div>
        <div><b><?= $globalRd['suspendidos'] ?></b><span>suspendidos</span></div>
        <div><b><?= $globalRd['enLinea'] ?></b><span>con sesión abierta</span></div>
        <div class="g-planes"><span>Por plan (activos)</span>
            <?php foreach ($globalRd['porPlan'] as $p => $n): ?>
                <em><?= escapar($p) ?> · <?= $n ?></em>
            <?php endforeach; ?>
            <?php if ($globalRd['porPlan'] === []): ?><em>—</em><?php endif; ?>
        </div>
    </section>

    <nav class="tabs">
        <?php foreach ($vlansRadiusDhcp as $v => $_): ?>
            <a class="tab<?= $v === $vlanRd ? ' activo' : '' ?>" href="?modulo=radius-dhcp&vlan=<?= escapar($v) ?>">VLAN <?= escapar($v) ?> <small><?= (int) ($globalRd['porVlan'][$v] ?? 0) ?></small></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($mensajeRd !== ''): ?><div class="mensaje"><?= escapar($mensajeRd) ?></div><?php endif; ?>
    <?php if ($errorRd !== ''): ?><div class="error"><?= escapar($errorRd) ?></div><?php endif; ?>
    <?php if ($pdoRd instanceof PDO && !$tablaSuspensionesRd): ?>
        <div class="aviso">Falta la tabla <b>radius.ubb_suspensiones</b>: se puede consultar y dar de alta, pero los botones de suspender no funcionarán hasta crearla.</div>
    <?php endif; ?>

    <?php if ($errorPlanesRd !== ''): ?><div class="aviso"><?= escapar($errorPlanesRd) ?></div><?php endif; ?>
    <p class="sub">Planes con velocidad configurada en el router para esta VLAN:
        <b><?= $planesRd !== [] ? escapar(implode(', ', array_map(fn ($x) => strtok($x, '_'), $planesRd))) : 'ninguno detectado' ?></b>.
        Solo esos se pueden asignar.</p>

    <section class="resumen">
        <div class="tarjeta"><b><?= $totalRd ?></b><span>clientes en esta VLAN</span></div>
        <div class="tarjeta"><b><?= $enLineaRd ?></b><span>con sesión abierta</span></div>
        <div class="tarjeta"><b><?= $suspendidosRd ?></b><span>suspendidos</span></div>
        <div class="tarjeta"><b class="mono" style="font-size:18px"><?= escapar($cfgRd['prefijo']) ?>0/24</b><span><?= escapar($cfgRd['servidor']) ?> · <?= escapar($cfgRd['lista']) ?></span></div>
    </section>

    <section class="caja">
        <table>
            <thead><tr><th>IP fija</th><th>MAC</th><th>Cliente</th><th>Estado</th><th>Sesión</th><th></th></tr></thead>
            <tbody>
            <?php if ($clientesRd === []): ?>
                <tr><td colspan="6">No hay clientes de esta VLAN en RADIUS.</td></tr>
            <?php endif; ?>
            <?php foreach ($clientesRd as $c):
                $suspendido = $c['lista'] === RD_LISTA_DEUDORES; ?>
                <tr>
                    <td data-t="IP" class="mono"><b><?= escapar($c['ip']) ?></b></td>
                    <td data-t="MAC" class="mono"><?= escapar($c['mac']) ?></td>
                    <td data-t="Cliente"><?= escapar(($c['nombre'] ?? '') !== '' ? $c['nombre'] : '— sin relación —') ?></td>
                    <td data-t="Estado">
                        <?php if ($suspendido): ?>
                            <span class="pill susp">Suspendido</span>
                        <?php else: ?>
                            <span class="pill ok">Activo · <?= escapar($c['lista'] ?: 'sin plan') ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-t="Sesión">
                        <?php if (!empty($c['sesion_desde'])): ?>
                            <span class="pill ok">En línea</span> <small>desde <?= escapar(date('d/m H:i', strtotime((string) $c['sesion_desde']))) ?></small>
                        <?php else: ?>
                            <span class="pill gris">Sin sesión</span>
                        <?php endif; ?>
                    </td>
                    <td><div class="acciones">
                        <?php if (!$suspendido && count($planesRd) > 1): ?>
                        <form class="inline" method="post" action="?modulo=radius-dhcp&vlan=<?= escapar($vlanRd) ?>"
                              onsubmit="return confirm('¿Cambiar el plan de <?= escapar($c['ip']) ?>?');">
                            <input type="hidden" name="csrf_radius_dhcp" value="<?= escapar($csrfRd) ?>">
                            <input type="hidden" name="mac" value="<?= escapar($c['mac']) ?>">
                            <input type="hidden" name="accion" value="cambiar_plan">
                            <select name="plan" aria-label="Plan">
                                <?php foreach ($planesRd as $planOp): ?>
                                    <option value="<?= escapar($planOp) ?>" <?= $planOp === $c['lista'] ? 'selected' : '' ?>><?= escapar(strtok($planOp, '_')) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="b-plan" type="submit">Cambiar</button>
                        </form>
                        <?php endif; ?>
                        <form class="inline" method="post" action="?modulo=radius-dhcp&vlan=<?= escapar($vlanRd) ?>"
                              onsubmit="return confirm('<?= $suspendido ? '¿Reactivar' : '¿Suspender' ?> a <?= escapar($c['ip']) ?> (<?= escapar($c['mac']) ?>)?');">
                            <input type="hidden" name="csrf_radius_dhcp" value="<?= escapar($csrfRd) ?>">
                            <input type="hidden" name="mac" value="<?= escapar($c['mac']) ?>">
                            <?php if ($suspendido): ?>
                                <button class="b-react" name="accion" value="reactivar">Reactivar</button>
                            <?php else: ?>
                                <button class="b-susp" name="accion" value="suspender">Suspender</button>
                            <?php endif; ?>
                        </form>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="caja">
        <h2 style="margin-top:0;color:#49366b;font-size:18px">Dar de alta un cliente en la VLAN <?= escapar($vlanRd) ?></h2>
        <form class="alta" method="post" action="?modulo=radius-dhcp&vlan=<?= escapar($vlanRd) ?>"
              onsubmit="return confirm('¿Dar de alta esta MAC con esa IP fija?');">
            <input type="hidden" name="csrf_radius_dhcp" value="<?= escapar($csrfRd) ?>">
            <input type="hidden" name="accion" value="alta">
            <div>
                <label for="mac">MAC del equipo</label>
                <input id="mac" name="mac" placeholder="AA:BB:CC:DD:EE:FF" required>
            </div>
            <div>
                <label for="ip">IP fija</label>
                <input id="ip" name="ip" value="<?= escapar($ipSugeridaRd) ?>" required>
            </div>
            <button class="b-alta" type="submit">Dar de alta</button>
        </form>
        <p class="sub" style="margin:10px 0 0">Se sugiere la siguiente IP libre. Plan asignado: <b><?= escapar($cfgRd['lista']) ?></b>.</p>
    </section>

    <div class="aviso">
        <b>Importante:</b> mientras UISP siga suspendiendo a estos clientes, un cliente reactivado aquí puede seguir bloqueado por UISP (y al revés).
        Este portal no tiene inicio de sesión individual todavía; cualquiera que lo abra puede usar estos botones.
    </div>
    <div id="hay-cambios" class="aviso" style="display:none;position:fixed;bottom:16px;left:50%;transform:translateX(-50%);box-shadow:0 4px 16px rgba(0,0,0,.15);z-index:9">
        Hubo cambios (por ejemplo, una suspensión desde UISP). <a href="?modulo=radius-dhcp&vlan=<?= rawurlencode($vlanRd) ?>">Actualizar ahora</a>
    </div>
</main>
<script>
(function () {
    var firma = <?= json_encode($firmaRd) ?>;
    if (!firma) return;
    var url = '?modulo=radius-dhcp&vlan=<?= rawurlencode($vlanRd) ?>&estado=1';
    var ocupado = function () {
        var a = document.activeElement;
        return a && /^(INPUT|SELECT|TEXTAREA)$/.test(a.tagName);
    };
    setInterval(function () {
        if (document.hidden) return;
        fetch(url, {cache: 'no-store', credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.firma || d.firma === firma) return;
                if (ocupado()) {
                    document.getElementById('hay-cambios').style.display = 'block';
                } else {
                    location.href = location.pathname + '?modulo=radius-dhcp&vlan=<?= rawurlencode($vlanRd) ?>';
                }
            })
            .catch(function () {});
    }, 20000);
})();
</script>
</body>
</html>
