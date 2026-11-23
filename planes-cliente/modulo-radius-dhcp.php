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

require_once __DIR__ . '/uisp-puente.php';

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

/* ---------- Avisar a UISP (si está encendido: /etc/ubb-panel/uisp-sync-activo) ---------- */

/** Tras suspender en el panel: suspende también el servicio en UISP. Devuelve texto para el mensaje. */
function rdUispTrasSuspender(PDO $pdo, Client $router, string $mac, string $ip): string
{
    if (!uispSyncActivo()) {
        return '';
    }
    try {
        $id = uispServicioDe($pdo, $mac, $ip);
        if ($id === null) {
            return ' No encontré su servicio en UISP: suspéndelo también allá.';
        }
        $r = uispSuspenderServicio($router, $id);
        if (!$r['ok']) {
            return " UISP no lo suspendió (servicio #$id: {$r['error']}). Hazlo allá a mano.";
        }
        uispMarcarGracia($pdo, $mac);
        // Queda como suspensión de UISP: cuando UISP lo reactive (p. ej. al pagar), sync-uisp lo reactiva aquí.
        $pdo->prepare("UPDATE ubb_suspensiones SET origen = 'UISP' WHERE username = ?")->execute([$mac]);
        rdBitacora($pdo, $mac, $ip, 'suspender-uisp', "servicio UISP #$id");
        return " También quedó suspendido en UISP (servicio #$id).";
    } catch (Throwable $e) {
        error_log('UBB RADIUS DHCP: aviso a UISP al suspender ' . $mac . ': ' . $e->getMessage());
        return ' No se pudo avisar a UISP: ' . $e->getMessage();
    }
}

/**
 * Antes de reactivar en el panel: quita la suspensión en UISP.
 * Si la suspensión es de UISP y no se pudo quitar allá, lanza error (sync-uisp lo volvería a suspender).
 */
function rdUispAntesDeReactivar(PDO $pdo, Client $router, string $mac, string $ip, string $origen): string
{
    $deUisp = $origen === 'UISP';
    if (!uispSyncActivo()) {
        if ($deUisp) {
            throw new RuntimeException("$mac ($ip) lo suspendió UISP: reactívalo en UISP y aquí se reactiva solo.");
        }
        return '';
    }
    $id = uispServicioDe($pdo, $mac, $ip);
    if ($id === null) {
        if ($deUisp) {
            throw new RuntimeException("No encontré el servicio de $mac ($ip) en UISP. Reactívalo en UISP. No se cambió nada.");
        }
        return '';
    }
    $r = uispReactivarServicio($router, $id);
    if (!$r['ok']) {
        if ($deUisp) {
            throw new RuntimeException("UISP no lo reactivó (servicio #$id: {$r['error']}). No se cambió nada.");
        }
        return " (En UISP el servicio #$id no tenía suspensión que quitar.)";
    }
    uispMarcarGracia($pdo, $mac);
    rdBitacora($pdo, $mac, $ip, 'reactivar-uisp', "servicio UISP #$id");
    return " También quedó activo en UISP (servicio #$id).";
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
                $_SESSION['flash_radius_dhcp_ok'] .= rdUispTrasSuspender($pdoRd, $router, $mac, $ip);
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
                // 0. Si corresponde, quitar primero la suspensión en UISP (si falla y es de UISP, no se cambia nada)
                $st = $pdoRd->prepare("SELECT origen FROM ubb_suspensiones WHERE username = ?");
                $st->execute([$mac]);
                $notaUisp = rdUispAntesDeReactivar($pdoRd, $router, $mac, $ip, strtoupper((string) ($st->fetchColumn() ?: 'PANEL')));
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
                $_SESSION['flash_radius_dhcp_ok'] = "Reactivado: $mac ($ip) con $original. $nota" . $notaUisp;
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

/* Quién suspendió a cada cliente (UISP o el panel) y desde cuándo */
$origenSuspRd = [];
if ($pdoRd instanceof PDO && $tablaSuspensionesRd) {
    try {
        foreach ($pdoRd->query("SELECT username, origen, fecha FROM ubb_suspensiones") as $s) {
            $origenSuspRd[(string) $s['username']] = ['origen' => strtoupper((string) ($s['origen'] ?? 'PANEL')), 'fecha' => (string) ($s['fecha'] ?? '')];
        }
    } catch (Throwable $e) {
        error_log('UBB RADIUS DHCP: sin origen de suspensiones: ' . $e->getMessage());
    }
}

/* Servicio de UISP de cada cliente (tabla que arma uisp-mapa.php) */
$uispActivoRd = uispSyncActivo();
$servicioUispRd = [];
if ($pdoRd instanceof PDO) {
    try {
        foreach ($pdoRd->query("SELECT mac, ip, servicio_id FROM ubb_uisp_mapa WHERE estado IN (1, 3)") as $m) {
            if ($m['mac'] !== '') {
                $servicioUispRd['mac:' . $m['mac']][] = (int) $m['servicio_id'];
            }
            if ($m['ip'] !== '') {
                $servicioUispRd['ip:' . $m['ip']][] = (int) $m['servicio_id'];
            }
        }
    } catch (Throwable $e) {
        // Todavía no existe el mapa: se muestra sin número de servicio.
    }
}

/* Historial de esta VLAN (últimos cambios hechos desde el panel) */
$historialRd = [];
if ($pdoRd instanceof PDO) {
    try {
        $st = $pdoRd->prepare("SELECT fecha, mac, ip, accion, detalle, origen FROM ubb_bitacora WHERE ip LIKE ? ORDER BY id DESC LIMIT 40");
        $st->execute([$cfgRd['prefijo'] . '%']);
        $historialRd = $st->fetchAll();
    } catch (Throwable $e) {
        error_log('UBB RADIUS DHCP: sin historial: ' . $e->getMessage());
    }
}

$totalRd = count($clientesRd);
$suspendidosRd = count(array_filter($clientesRd, fn ($c) => $c['lista'] === RD_LISTA_DEUDORES));
$enLineaRd = count(array_filter($clientesRd, fn ($c) => !empty($c['sesion_desde'])));

$firmaRd = '';
if ($pdoRd instanceof PDO) {
    try { $firmaRd = rdFirmaEstado($pdoRd); } catch (Throwable $e) { $firmaRd = ''; }
}

require_once __DIR__ . '/layout-portal.php';
portalInicio('Clientes DHCP en RADIUS', 'IP fija, plan y suspensión de cada cliente. Los cambios se guardan en RADIUS y se aplican al momento en el router.', 'radius-dhcp');
?>

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
        <div class="barra">
            <?= portalBuscador('buscar', 'IP, MAC o nombre') ?>
            <select id="f-estado" aria-label="Estado">
                <option value="">Todos</option>
                <option value="activo">Activos</option>
                <option value="susp">Suspendidos</option>
                <option value="susp-uisp">Suspendidos por UISP</option>
                <option value="susp-panel">Suspendidos desde el panel</option>
                <option value="linea">En línea</option>
                <option value="fuera">Sin sesión</option>
                <option value="sinrel">Sin relación</option>
            </select>
            <select id="f-plan" aria-label="Plan">
                <option value="">Todos los planes</option>
                <?php foreach ($planesRd as $planOp): ?><option value="<?= escapar($planOp) ?>"><?= escapar(strtok($planOp, '_')) ?></option><?php endforeach; ?>
            </select>
            <span class="cuenta" id="cuenta"></span>
        </div>
        <div class="tabla">
        <table>
            <thead><tr><th>IP fija</th><th>MAC</th><th>Cliente</th><th>Estado</th><th>Sesión</th><th></th></tr></thead>
            <tbody id="filas">
            <?php if ($clientesRd === []): ?>
                <tr><td colspan="6">No hay clientes de esta VLAN en RADIUS.</td></tr>
            <?php endif; ?>
            <?php foreach ($clientesRd as $c):
                $suspendido = $c['lista'] === RD_LISTA_DEUDORES;
                $susp = $origenSuspRd[$c['mac']] ?? null;
                $porUisp = $suspendido && ($susp['origen'] ?? '') === 'UISP';
                $sinRel = ($c['nombre'] ?? '') === '';
                $nombreRd = $sinRel ? $c['ip'] : $c['nombre'] . ' (' . $c['ip'] . ')';
                $idsUisp = array_values(array_unique($servicioUispRd['mac:' . strtoupper($c['mac'])] ?? $servicioUispRd['ip:' . $c['ip']] ?? []));
                $enLinea = !empty($c['sesion_desde']);
                $velocidad = (int) strtok((string) $c['lista'], 'M');
                $estados = [$suspendido ? 'susp' : 'activo', $enLinea ? 'linea' : 'fuera'];
                if ($suspendido) $estados[] = $porUisp ? 'susp-uisp' : 'susp-panel';
                if ($sinRel) $estados[] = 'sinrel';
            ?>
                <tr data-buscar="<?= escapar(strtolower($c['ip'] . ' ' . $c['mac'] . ' ' . ($c['nombre'] ?? ''))) ?>"
                    data-estado="<?= escapar(implode(' ', $estados)) ?>" data-plan="<?= escapar($c['lista']) ?>">
                    <td data-t="IP" class="mono"><b><?= escapar($c['ip']) ?></b></td>
                    <td data-t="MAC" class="mono"><?= escapar($c['mac']) ?></td>
                    <td data-t="Cliente"><?= $sinRel ? '<span class="tenue">— sin relación —</span>' : escapar($c['nombre']) ?>
                        <?php if (count($idsUisp) === 1): ?><small class="bloque">UISP servicio #<?= (int) $idsUisp[0] ?></small>
                        <?php elseif (count($idsUisp) > 1): ?><small class="bloque" style="color:var(--red)">UISP: varios servicios (#<?= escapar(implode(', #', $idsUisp)) ?>)</small><?php endif; ?></td>
                    <td data-t="Estado">
                        <?php if ($suspendido): ?>
                            <span class="pill susp">Suspendido</span>
                            <small class="bloque">por <?= $porUisp ? 'UISP' : 'el panel' ?><?= ($susp['fecha'] ?? '') !== '' ? ' · ' . escapar(date('d/m H:i', strtotime($susp['fecha']))) : '' ?></small>
                        <?php else: ?>
                            <span class="pill plan v<?= $velocidad >= 60 ? 60 : ($velocidad >= 30 ? 30 : 20) ?>"><?= escapar(strtok((string) ($c['lista'] ?: 'sin plan'), '_')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-t="Sesión">
                        <?php if ($enLinea): ?>
                            <span class="punto ok"></span>En línea <small class="bloque">desde <?= escapar(date('d/m H:i', strtotime((string) $c['sesion_desde']))) ?></small>
                        <?php else: ?>
                            <span class="punto gris"></span><span class="tenue">Sin sesión</span>
                        <?php endif; ?>
                    </td>
                    <td><div class="acciones">
                        <?php if (!$suspendido && count($planesRd) > 1): ?>
                        <form class="inline" method="post" action="?modulo=radius-dhcp&vlan=<?= escapar($vlanRd) ?>"
                              data-confirmar="¿Cambiar el plan de <?= escapar($nombreRd) ?>?"
                              data-detalle="La nueva velocidad se aplica al momento; el equipo puede reconectarse unos segundos."
                              data-verbo="Cambiar plan">
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
                        <?php if ($porUisp && !$uispActivoRd): ?>
                            <span class="pill uisp" title="UISP lo tiene bloqueado. Si se reactiva aquí, el sincronizador lo vuelve a suspender en un minuto.">Reactivar en UISP</span>
                        <?php else: ?>
                        <form class="inline" method="post" action="?modulo=radius-dhcp&vlan=<?= escapar($vlanRd) ?>"
                              data-confirmar="<?= $suspendido ? '¿Reactivar' : '¿Suspender' ?> a <?= escapar($nombreRd) ?>?"
                              data-detalle="<?= $suspendido ? 'Vuelve a tener internet con su plan de siempre.' : 'Se le corta el internet de inmediato y verá el aviso de suspensión. Puedes reactivarlo después.' ?> MAC <?= escapar($c['mac']) ?>.<?= $uispActivoRd ? (count($idsUisp) === 1 ? ' También se ' . ($suspendido ? 'reactiva' : 'suspende') . ' en UISP (servicio #' . $idsUisp[0] . ').' : ' No tiene servicio de UISP enlazado: en UISP no cambia.') : '' ?>"
                              data-verbo="<?= $suspendido ? 'Reactivar' : 'Suspender' ?> <?= escapar($c['ip']) ?>"
                              data-tono="<?= $suspendido ? 'bien' : 'peligro' ?>">
                            <input type="hidden" name="csrf_radius_dhcp" value="<?= escapar($csrfRd) ?>">
                            <input type="hidden" name="mac" value="<?= escapar($c['mac']) ?>">
                            <?php if ($suspendido): ?>
                                <button class="b-react" name="accion" value="reactivar">Reactivar</button>
                            <?php else: ?>
                                <button class="b-susp" name="accion" value="suspender">Suspender</button>
                            <?php endif; ?>
                        </form>
                        <?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            <tr id="sin-resultados" style="display:none"><td colspan="6" class="tenue">Ningún cliente coincide con la búsqueda.</td></tr>
            </tbody>
        </table>
        </div>
    </section>

    <details class="caja plegable">
        <summary>Dar de alta un cliente en la VLAN <?= escapar($vlanRd) ?></summary>
        <form class="alta" method="post" action="?modulo=radius-dhcp&vlan=<?= escapar($vlanRd) ?>"
              data-confirmar="¿Dar de alta este equipo en la VLAN <?= escapar($vlanRd) ?>?"
              data-detalle="Queda con IP fija y el plan <?= escapar($cfgRd['lista']) ?>. Conectará en su siguiente renovación."
              data-verbo="Dar de alta">
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
    </details>

    <details class="caja plegable">
        <summary>Historial de cambios en la VLAN <?= escapar($vlanRd) ?> <small>(últimos <?= count($historialRd) ?>)</small></summary>
        <?php if ($historialRd === []): ?>
            <p class="tenue">Todavía no hay cambios registrados desde el panel en esta VLAN.</p>
        <?php else: ?>
        <div class="tabla">
        <table class="historial">
            <thead><tr><th>Fecha</th><th>IP</th><th>Acción</th><th>Detalle</th><th>Origen</th></tr></thead>
            <tbody>
            <?php foreach ($historialRd as $h): ?>
                <tr>
                    <td data-t="Fecha"><?= escapar(date('d/m H:i', strtotime((string) $h['fecha']))) ?></td>
                    <td data-t="IP" class="mono"><?= escapar($h['ip']) ?></td>
                    <td data-t="Acción"><span class="pill acc-<?= escapar(preg_replace('/[^a-z_]/', '', (string) $h['accion'])) ?>"><?= escapar(str_replace('_', ' ', (string) $h['accion'])) ?></span></td>
                    <td data-t="Detalle"><?= escapar($h['detalle']) ?></td>
                    <td data-t="Origen" class="tenue"><?= escapar($h['origen']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </details>

    <p class="nota">Lo que suspende UISP se reactiva en UISP: el panel lo refleja solo en unos minutos. Este portal todavía no tiene usuarios individuales.</p>
    <div id="hay-cambios" class="aviso" style="display:none;position:fixed;bottom:16px;left:50%;transform:translateX(-50%);box-shadow:0 4px 16px rgba(0,0,0,.15);z-index:9">
        Hubo cambios (por ejemplo, una suspensión desde UISP). <a href="?modulo=radius-dhcp&vlan=<?= rawurlencode($vlanRd) ?>">Actualizar ahora</a>
    </div>
<?php portalFin(); ?>
<script>
(function () {
    var firma = <?= json_encode($firmaRd) ?>;
    if (!firma) return;
    var url = '?modulo=radius-dhcp&vlan=<?= rawurlencode($vlanRd) ?>&estado=1';
    var ocupado = function () {
        var a = document.activeElement;
        return document.querySelector('dialog[open]') || (a && /^(INPUT|SELECT|TEXTAREA)$/.test(a.tagName));
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
<script>
(function () {
    var filas = Array.prototype.slice.call(document.querySelectorAll('#filas tr[data-buscar]'));
    var buscar = document.getElementById('buscar'), estado = document.getElementById('f-estado'),
        plan = document.getElementById('f-plan'), cuenta = document.getElementById('cuenta'),
        vacio = document.getElementById('sin-resultados'), clave = 'filtros-radius-dhcp-<?= rawurlencode($vlanRd) ?>';
    if (!buscar) return;
    try {
        var guardado = JSON.parse(sessionStorage.getItem(clave) || '{}');
        buscar.value = guardado.q || ''; estado.value = guardado.e || ''; plan.value = guardado.p || '';
    } catch (e) {}
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
        if (vacio) vacio.style.display = (filas.length && !n) ? '' : 'none';
        try { sessionStorage.setItem(clave, JSON.stringify({q: buscar.value, e: estado.value, p: plan.value})); } catch (err) {}
    }
    buscar.addEventListener('input', filtrar);
    estado.addEventListener('change', filtrar);
    plan.addEventListener('change', filtrar);
    filtrar();
})();
</script>
</body>
</html>
