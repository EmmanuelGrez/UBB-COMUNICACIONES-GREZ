<?php
/*
 * radius-dhcp-acciones.php - Lógica compartida de los clientes DHCP en RADIUS.
 *
 * La usan la pantalla "Clientes DHCP RADIUS" (modulo-radius-dhcp.php) y la API
 * para UBB Pagos (api-radius/). Así el botón del panel y la API hacen exactamente lo mismo.
 * Este archivo solo define constantes y funciones; no imprime nada.
 */

use RouterOS\Client;
use RouterOS\Query;

require_once __DIR__ . '/uisp-puente.php';

const RD_LISTA_DEUDORES = 'CLIENTES-DEUDORES';
const RD_COMENTARIO_TEMPORAL = 'TEMP-PLAN';

/** VLAN migradas a RADIUS. Para agregar otra, copia un bloque. */
function rdVlans(): array
{
    return [
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
}

/** Configuración de VLAN por nombre de servidor DHCP (p. ej. DHCP-SERVICE1503). */
function rdVlanPorServidor(string $servidor): ?array
{
    foreach (rdVlans() as $vlan => $cfg) {
        if (strcasecmp($cfg['servidor'], trim($servidor)) === 0) {
            return $cfg + ['vlan' => (string) $vlan];
        }
    }
    return null;
}

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
function rdBitacora(PDO $pdo, string $mac, string $ip, string $accion, string $detalle, string $origen = 'PANEL'): void
{
    try {
        $pdo->prepare("INSERT INTO ubb_bitacora (mac, ip, accion, detalle, origen) VALUES (?, ?, ?, ?, ?)")
            ->execute([$mac, $ip, $accion, $detalle, $origen]);
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
function rdUispTrasSuspender(PDO $pdo, Client $router, string $mac, string $ip, string $origen = 'PANEL'): string
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
        rdBitacora($pdo, $mac, $ip, 'suspender-uisp', "servicio UISP #$id", $origen);
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
function rdUispAntesDeReactivar(PDO $pdo, Client $router, string $mac, string $ip, string $origenSusp, string $origen = 'PANEL'): string
{
    $deUisp = $origenSusp === 'UISP';
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
    rdBitacora($pdo, $mac, $ip, 'reactivar-uisp', "servicio UISP #$id", $origen);
    return " También quedó activo en UISP (servicio #$id).";
}

/* ---------- Concesiones fijas (static leases) que no pasan por RADIUS ---------- */

const RD_CLAVE_ACCIONES = '/etc/ubb-panel/clave-acciones.hash';

/** ¿Hay clave configurada para las acciones delicadas del panel? */
function rdClaveConfigurada(): bool
{
    return trim((string) @file_get_contents(RD_CLAVE_ACCIONES)) !== '';
}

/** Compara la clave escrita con la guardada (solo se guarda su hash). */
function rdVerificarClave(string $clave): bool
{
    $hash = trim((string) @file_get_contents(RD_CLAVE_ACCIONES));
    return $hash !== '' && $clave !== '' && password_verify($clave, $hash);
}

/**
 * Concesiones fijas de un servidor DHCP que no pasan por RADIUS
 * (sin contar las dinámicas, las de RADIUS, las deshabilitadas ni las de bloqueo).
 */
function rdConcesionesFijas(Client $router, string $servidor): array
{
    $si = fn (array $f, string $k): bool => in_array(strtolower((string) ($f[$k] ?? 'false')), ['true', 'yes'], true);
    $fijas = [];
    foreach (rdRouter($router, (new Query('/ip/dhcp-server/lease/print'))->where('server', $servidor)) as $f) {
        if (!is_array($f) || !isset($f['.id'])
            || $si($f, 'dynamic') || $si($f, 'radius') || $si($f, 'block-access') || $si($f, 'disabled')) {
            continue;
        }
        $fijas[] = [
            'id' => (string) $f['.id'],
            'mac' => strtoupper((string) ($f['mac-address'] ?? '')),
            'ip' => (string) ($f['address'] ?? ''),
            'listas' => (string) ($f['address-lists'] ?? ''),
            'equipo' => (string) ($f['host-name'] ?? ''),
            'comentario' => (string) ($f['comment'] ?? ''),
            'estado' => (string) ($f['status'] ?? ''),
        ];
    }
    usort($fijas, fn ($a, $b) => ip2long($a['ip']) <=> ip2long($b['ip']));
    return $fijas;
}

/**
 * Revisa si una concesión fija se puede pasar a RADIUS.
 * Devuelve ['plan' => lista a usar, 'problema' => null o el motivo por el que no se puede].
 */
function rdRevisarFija(PDO $pdo, array $cfg, array $fija, array $planes): array
{
    $plan = $cfg['lista'];
    foreach (array_filter(array_map('trim', explode(',', $fija['listas']))) as $l) {
        if (in_array($l, $planes, true)) {
            $plan = $l;
            break;
        }
    }
    try {
        $mac = rdNormalizarMac($fija['mac']);
    } catch (Throwable $e) {
        return ['plan' => $plan, 'problema' => 'La MAC no es válida.'];
    }
    $octeto = substr($fija['ip'], strlen($cfg['prefijo']));
    if (strpos($fija['ip'], $cfg['prefijo']) !== 0 || !ctype_digit($octeto)
        || (int) $octeto < $cfg['desde'] || (int) $octeto > $cfg['hasta']) {
        return ['plan' => $plan, 'problema' => 'Su IP está fuera del rango de la VLAN.'];
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM radcheck WHERE username = ?');
    $st->execute([$mac]);
    if ((int) $st->fetchColumn() > 0) {
        return ['plan' => $plan, 'problema' => 'Esa MAC ya está en RADIUS; la concesión fija sobra (revísalo con el ingeniero).'];
    }
    $st = $pdo->prepare("SELECT username FROM radreply WHERE attribute = 'Framed-IP-Address' AND value = ?");
    $st->execute([$fija['ip']]);
    if ($otro = $st->fetchColumn()) {
        return ['plan' => $plan, 'problema' => "Esa IP ya la tiene en RADIUS $otro."];
    }
    return ['plan' => $plan, 'problema' => null];
}

/**
 * Pasa a RADIUS un equipo que hoy tiene concesión fija: lo da de alta con su misma IP y su plan,
 * le pone su plan temporal en el router y quita la concesión fija (solo la de esta VLAN).
 * Si algo falla en el router, RADIUS no cambia.
 */
function rdPasarARadius(PDO $pdo, Client $router, array $cfg, string $mac, array $planes, string $origen = 'PANEL'): string
{
    $mac = rdNormalizarMac($mac);
    $fijas = array_values(array_filter(rdConcesionesFijas($router, $cfg['servidor']), fn ($f) => $f['mac'] === $mac));
    if (count($fijas) !== 1) {
        throw new RuntimeException("No encontré una única concesión fija de $mac en " . $cfg['servidor'] . '. Recarga la pantalla.');
    }
    $fija = $fijas[0];
    $rev = rdRevisarFija($pdo, $cfg, $fija, $planes);
    if ($rev['problema'] !== null) {
        throw new RuntimeException("$mac: " . $rev['problema']);
    }
    $ip = $fija['ip'];
    $plan = $rev['plan'];
    $renueva = rdSegundosParaRenovar($router, $mac, $ip, $cfg['servidor']);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Accept')")->execute([$mac]);
        $ins = $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, ?, ':=', ?)");
        $ins->execute([$mac, 'Framed-IP-Address', $ip]);
        $ins->execute([$mac, 'Mikrotik-Address-List', $plan]);
        // Primero el plan temporal, luego quitar la concesión fija: así no se queda ni un momento sin plan.
        $nota = rdPuenteTemporal($router, $mac, $ip, $plan, $renueva);
        rdRouter($router, (new Query('/ip/dhcp-server/lease/remove'))->equal('.id', $fija['id']));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        try {
            rdQuitarTemporales($router, $ip);
        } catch (Throwable $e2) {
        }
        throw new RuntimeException("No se pasó $mac a RADIUS (no cambió nada): " . $e->getMessage());
    }
    $detalle = "concesión fija -> RADIUS ($plan)" . ($fija['comentario'] !== '' ? '; comentario: ' . $fija['comentario'] : '');
    rdBitacora($pdo, $mac, $ip, 'pasar-radius', $detalle, $origen);
    return "Pasado a RADIUS: $mac conserva la IP $ip con $plan. $nota";
}

/* ---------- Suspender y reactivar (mismo código para el panel y la API) ---------- */

/**
 * Suspende un cliente DHCP: bloqueo inmediato en el router, guarda su plan y lo marca deudor en RADIUS.
 * $origen: 'PANEL' o 'PAGOS' (queda en la bitácora y en ubb_suspensiones). Devuelve el mensaje para el usuario.
 */
function rdSuspender(PDO $pdo, Client $router, string $mac, string $origen = 'PANEL'): string
{
    $cliente = rdLeerCliente($pdo, $mac);
    $ip = $cliente['ip'];
    if ($cliente['lista'] === RD_LISTA_DEUDORES) {
        throw new RuntimeException("$mac ($ip) ya estaba suspendido.");
    }
    // 0. Avisar al monitoreo ANTES de cortar, para que lo cuente como corte por pago y no como falla.
    rdAvisarMonitoreo($pdo, 'BAJA_PAGO', ['mac' => $mac], strtoupper($origen) === 'PAGOS' ? 'Adeudo mensual' : 'Suspensión desde el panel', $origen, $ip);
    // 1. Bloqueo inmediato en el router (si falla, RADIUS no cambia)
    if (rdIdsLista($router, $ip) === []) {
        rdRouter($router, (new Query('/ip/firewall/address-list/add'))
            ->equal('list', RD_LISTA_DEUDORES)
            ->equal('address', $ip)
            ->equal('comment', 'SUSPENDIDO ' . $mac));
    }
    // 2. Guardar lista original y marcar como deudor en RADIUS
    $pdo->beginTransaction();
    $pdo->prepare("REPLACE INTO ubb_suspensiones (username, lista_original, ip, origen) VALUES (?, ?, ?, ?)")
        ->execute([$mac, $cliente['lista'], $ip, $origen]);
    $pdo->prepare("UPDATE radreply SET value = ? WHERE username = ? AND attribute = 'Mikrotik-Address-List'")
        ->execute([RD_LISTA_DEUDORES, $mac]);
    $pdo->commit();
    // 3. Renovar concesión para que tome lo nuevo de RADIUS
    rdQuitarConcesion($router, $mac);
    rdQuitarTemporales($router, $ip);
    rdBitacora($pdo, $mac, $ip, 'suspender', 'estaba ' . $cliente['lista'], $origen);
    return "Suspendido: $mac ($ip). Ya ve la página de aviso." . rdUispTrasSuspender($pdo, $router, $mac, $ip, $origen);
}

/**
 * Reactiva un cliente DHCP: (si aplica) quita la suspensión en UISP, regresa su plan en RADIUS,
 * quita el bloqueo del router y le pone su plan temporal hasta que renueve. Devuelve el mensaje.
 */
function rdReactivar(PDO $pdo, Client $router, array $cfg, string $mac, string $origen = 'PANEL'): string
{
    $cliente = rdLeerCliente($pdo, $mac);
    $ip = $cliente['ip'];
    $st = $pdo->prepare("SELECT lista_original FROM ubb_suspensiones WHERE username = ?");
    $st->execute([$mac]);
    $original = (string) ($st->fetchColumn() ?: '');
    if ($original === '') {
        if ($cliente['lista'] !== RD_LISTA_DEUDORES) {
            throw new RuntimeException("$mac ($ip) no está suspendido.");
        }
        $original = $cfg['lista']; // suspendido sin registro: se usa el plan de la VLAN
    }
    // 0. Si corresponde, quitar primero la suspensión en UISP (si falla y es de UISP, no se cambia nada)
    $st = $pdo->prepare("SELECT origen FROM ubb_suspensiones WHERE username = ?");
    $st->execute([$mac]);
    $notaUisp = rdUispAntesDeReactivar($pdo, $router, $mac, $ip, strtoupper((string) ($st->fetchColumn() ?: 'PANEL')), $origen);
    // 1. Regresar su plan en RADIUS
    $pdo->prepare("UPDATE radreply SET value = ? WHERE username = ? AND attribute = 'Mikrotik-Address-List'")
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
    $renuevaEn = rdSegundosParaRenovar($router, $mac, $ip, $cfg['servidor']);
    rdQuitarConcesion($router, $mac);
    $nota = rdPuenteTemporal($router, $mac, $ip, $original, $renuevaEn);
    $pdo->prepare("DELETE FROM ubb_suspensiones WHERE username = ?")->execute([$mac]);
    rdBitacora($pdo, $mac, $ip, 'reactivar', 'vuelve a ' . $original, $origen);
    rdAvisarMonitoreo($pdo, 'REACTIVACION', ['mac' => $mac], strtoupper($origen) === 'PAGOS' ? 'Pago registrado' : 'Reactivación desde el panel', $origen, $ip);
    return "Reactivado: $mac ($ip) con $original. $nota" . $notaUisp;
}

/**
 * Cambia el plan (lista de velocidad) de un cliente DHCP en RADIUS y lo aplica en el router.
 * Si está suspendido, solo guarda el plan para cuando se reactive. $cfg = config de la VLAN con 'vlan'.
 */
function rdCambiarPlan(PDO $pdo, Client $router, array $cfg, string $mac, string $plan, string $origen = 'PANEL', string $nota = ''): string
{
    $cliente = rdLeerCliente($pdo, $mac);
    $ip = $cliente['ip'];
    if (strpos($ip, $cfg['prefijo']) !== 0) {
        throw new RuntimeException("La MAC $mac no pertenece a la VLAN {$cfg['vlan']}.");
    }
    if (!in_array($plan, rdPlanesDisponibles($router, (string) $cfg['vlan']), true)) {
        throw new RuntimeException("El plan $plan no existe en el router para la VLAN {$cfg['vlan']}. No se cambió nada.");
    }
    if ($cliente['lista'] === RD_LISTA_DEUDORES) {
        // Suspendido: se guarda el plan nuevo para cuando se reactive.
        $st = $pdo->prepare("UPDATE ubb_suspensiones SET lista_original = ? WHERE username = ?");
        $st->execute([$plan, $mac]);
        if ($st->rowCount() === 0) {
            throw new RuntimeException("$mac está suspendido pero no tiene registro de suspensión. No se cambió nada.");
        }
        $mensaje = "Plan guardado: $mac ($ip) tendrá $plan cuando se reactive.";
    } else {
        if ($cliente['lista'] === $plan) {
            throw new RuntimeException("$mac ($ip) ya tiene el plan $plan.");
        }
        $renuevaEn = rdSegundosParaRenovar($router, $mac, $ip, $cfg['servidor']);
        $pdo->prepare("UPDATE radreply SET value = ? WHERE username = ? AND attribute = 'Mikrotik-Address-List'")
            ->execute([$plan, $mac]);
        rdQuitarConcesion($router, $mac);
        $aviso = rdPuenteTemporal($router, $mac, $ip, $plan, $renuevaEn);
        rdCortarConexiones($router, $ip);
        $mensaje = "Plan cambiado: $mac ($ip) de {$cliente['lista']} a $plan. $aviso";
    }
    rdBitacora($pdo, $mac, $ip, 'cambiar_plan', trim($cliente['lista'] . ' -> ' . $plan . ' ' . $nota), $origen);
    $de = $cliente['lista'] === RD_LISTA_DEUDORES ? 'suspendido' : strtok($cliente['lista'], '_');
    rdAvisarMonitoreo($pdo, 'CAMBIO_PLAN', ['mac' => $mac], "Cambio de $de a " . strtok($plan, '_'), $origen, $ip);
    return $mensaje;
}

/** Plan vigente de un cliente: su lista, o la que tendrá al reactivarse si está suspendido. */
function rdPlanVigente(PDO $pdo, string $mac): string
{
    $lista = rdLeerCliente($pdo, $mac)['lista'];
    if ($lista !== RD_LISTA_DEUDORES) {
        return $lista;
    }
    $st = $pdo->prepare("SELECT lista_original FROM ubb_suspensiones WHERE username = ?");
    $st->execute([$mac]);
    return (string) ($st->fetchColumn() ?: $lista);
}

/** Revisa la clave de acciones con el mismo bloqueo por intentos que el resto del panel. */
function rdExigirClave(string $clave): void
{
    $bloqueo = (int) ($_SESSION['clave_rd_bloqueo'] ?? 0);
    if ($bloqueo > time()) {
        throw new RuntimeException('Demasiados intentos con clave incorrecta. Espera ' . (int) ceil(($bloqueo - time()) / 60) . ' min.');
    }
    if (!rdClaveConfigurada()) {
        throw new RuntimeException('Falta configurar la clave de acciones en el servidor.');
    }
    if (!rdVerificarClave($clave)) {
        $fallos = (int) ($_SESSION['clave_rd_fallos'] ?? 0) + 1;
        $_SESSION['clave_rd_fallos'] = $fallos;
        error_log('UBB panel: clave de acciones incorrecta (' . $fallos . ') desde ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        if ($fallos >= 5) {
            $_SESSION['clave_rd_bloqueo'] = time() + 600;
            $_SESSION['clave_rd_fallos'] = 0;
        }
        throw new RuntimeException('Clave incorrecta. No se cambió nada.');
    }
    $_SESSION['clave_rd_fallos'] = 0;
}

/* ---------- Aviso al monitoreo de Cristian (webhook administrativo) ---------- */

const RD_MONITOREO_URL = 'http://192.168.100.224:5000/api/v1/webhook/administrativo';
const RD_MONITOREO_LLAVE = '/etc/ubb-panel/monitoreo-webhook.key';

/**
 * Avisa al monitoreo que un corte, reactivación o cambio de plan es administrativo (no una falla).
 * Apagado si no existe la llave. Nunca detiene la acción: si falla, queda en el log y en la bitácora.
 * $quien = ['mac' => ..., 'usuario' => ...] (lo que se conozca).
 */
function rdAvisarMonitoreo(?PDO $pdo, string $accion, array $quien, string $motivo, string $origen, string $ip = ''): void
{
    $llave = trim((string) @file_get_contents(RD_MONITOREO_LLAVE));
    if ($llave === '') {
        return;
    }
    $datos = ['accion' => $accion];
    foreach (['mac', 'usuario'] as $campo) {
        if (!empty($quien[$campo])) {
            $datos[$campo] = (string) $quien[$campo];
        }
    }
    $datos += ['motivo' => $motivo, 'ejecutado_por' => strtoupper($origen) === 'PAGOS' ? 'Pagos' : 'Panel'];
    $cuerpo = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nX-API-Key: $llave\r\nContent-Length: " . strlen($cuerpo) . "\r\n",
        'content' => $cuerpo,
        'timeout' => 3,
        'ignore_errors' => true,
    ]]);
    $respuesta = @file_get_contents(RD_MONITOREO_URL, false, $ctx);
    $codigo = 0;
    $cabeceras = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    if (isset($cabeceras[0]) && preg_match('/\s(\d{3})\s/', $cabeceras[0] . ' ', $m)) {
        $codigo = (int) $m[1];
    }
    if ($respuesta !== false && $codigo >= 200 && $codigo < 300) {
        return;
    }
    $error = $respuesta === false ? 'sin respuesta' : "HTTP $codigo";
    error_log("UBB monitoreo: $accion " . ($datos['mac'] ?? $datos['usuario'] ?? '?') . ": $error");
    if ($pdo instanceof PDO) {
        rdBitacora($pdo, (string) ($datos['mac'] ?? $datos['usuario'] ?? ''), $ip, 'aviso-monitoreo', "No se avisó $accion al monitoreo ($error)", $origen);
    }
}
