<?php
/*
 * sync-pendientes.php - SIMULACIÓN: qué pasaría con los equipos del sitio PENDIENTES de UISP.
 *
 * Regla propuesta: equipo en el sitio PENDIENTES (aún sin cliente) -> lista CLIENTES-DEUDORES (página de adeudo).
 * Esta versión SOLO LEE (UISP por el camino directo 10.0.1.6:18445 y la base radius) y muestra el resultado.
 * No cambia RADIUS, el router ni UISP. Aplicar se hará en otra versión, después de revisar esta lista.
 *
 * Uso (dentro del contenedor):
 *   php sync-pendientes.php            resumen y lista
 *   php sync-pendientes.php --csv      la misma lista en CSV (para revisarla en Excel)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/uisp-puente.php';
require_once __DIR__ . '/radius-dhcp-acciones.php';

date_default_timezone_set('America/Mexico_City');
set_time_limit(0);

const SP_SITIO_PENDIENTES = '37f85909-e467-46f9-8d31-07d3417937fb';
const SP_RED_PPPOE = '10.2.';

$csv = in_array('--csv', $argv, true);

function spBD(): PDO
{
    $host = trim((string) (getenv('DB_HOST') ?: ''));
    $socket = getenv('DB_SOCKET') ?: '/var/run/mysqld/mysqld.sock';
    $dsn = $host !== ''
        ? 'mysql:host=' . $host . ';port=' . ((int) (getenv('DB_PORT') ?: 3306)) . ';dbname=radius;charset=utf8mb4'
        : 'mysql:unix_socket=' . $socket . ';dbname=radius;charset=utf8mb4';
    return new PDO($dsn, (string) getenv('DB_USER'), (string) getenv('DB_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

$r = uispDirecto('/devices?siteId=' . SP_SITIO_PENDIENTES);
if (!$r['ok']) {
    fwrite(STDERR, "No se pudo leer PENDIENTES: {$r['error']}\n");
    exit(1);
}
$equipos = json_decode($r['datos'], true);
if (!is_array($equipos)) {
    fwrite(STDERR, "UISP respondió algo que no es una lista (" . strlen($r['datos']) . " bytes).\n");
    exit(1);
}

$pdo = spBD();
$porIp = $pdo->prepare("SELECT username FROM radreply WHERE attribute = 'Framed-IP-Address' AND value = ?");
$grupo = $pdo->prepare("SELECT value FROM radreply WHERE attribute = 'Mikrotik-Group' AND username = ?");
$mapa = [];
try {
    foreach ($pdo->query('SELECT servicio_id, ip, mac FROM ubb_uisp_mapa')->fetchAll() as $m) {
        if ($m['mac'] !== '') {
            $mapa['mac:' . strtoupper($m['mac'])] = (int) $m['servicio_id'];
        }
        if ($m['ip'] !== '') {
            $mapa['ip:' . $m['ip']] = (int) $m['servicio_id'];
        }
    }
} catch (Throwable $e) {
    // sin mapa: no se puede avisar de equipos que también están ligados a un servicio
}

/* Cruce 1: servicio en UBB Pagos (base de Jamileth): MAC (DHCP) o usuario PPPoE -> id_servicio y estado. */
$pagos = [];
$pagosOk = false;
try {
    $pass = rtrim((string) @file_get_contents('/etc/ubb/jamileth.pass'), "\r\n");
    $jam = new PDO('mysql:host=192.168.100.221;port=3306;dbname=ubb_plataforma;charset=utf8mb4', 'equipo_ubb', $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5,
    ]);
    foreach ($jam->query("SELECT UPPER(TRIM(i.mac)) AS mac, LOWER(TRIM(i.pppoe_name)) AS pppoe, i.id_servicio, s.estado
                          FROM integracion_winbox i
                          JOIN servicios s ON s.id_servicio = i.id_servicio AND s.id_cliente = i.id_cliente
                          WHERE UPPER(i.estado_vinculacion) LIKE 'RELACIONADO%'") as $f) {
        $dato = 'servicio ' . $f['id_servicio'] . ' (' . $f['estado'] . ')';
        if (!empty($f['mac'])) {
            $pagos['mac:' . str_replace('-', ':', $f['mac'])] = $dato;
        }
        if (!empty($f['pppoe'])) {
            $pagos['ppp:' . $f['pppoe']] = $dato;
        }
    }
    $pagosOk = true;
} catch (Throwable $e) {
    fwrite(STDERR, "Aviso: no se pudo leer UBB Pagos ({$e->getMessage()}); la columna de servicio saldrá vacía.\n");
}

/* Cruce 2: ¿conectado ahora? DHCP = sesión abierta en radacct; PPPoE = sesión activa en el router. */
$sesionDhcp = $pdo->prepare('SELECT COUNT(*) FROM radacct WHERE username = ? AND acctstoptime IS NULL');
$activosPpp = [];
$routerOk = false;
try {
    $router = uispConectarRouter('10.0.1.6');
    foreach ($router->query(new RouterOS\Query('/ppp/active/print', ['=.proplist=name']))->read() as $a) {
        if (isset($a['name'])) {
            $activosPpp[strtolower((string) $a['name'])] = true;
        }
    }
    $routerOk = true;
} catch (Throwable $e) {
    fwrite(STDERR, "Aviso: no se pudo leer el router ({$e->getMessage()}); PPPoE saldrá como 'no se sabe'.\n");
}

$filas = [];
$cuenta = [];
foreach ($equipos as $eq) {
    $nombre = (string) ($eq['identification']['name'] ?? '');
    $rol = (string) ($eq['identification']['role'] ?? '');
    $ip = trim(explode('/', (string) ($eq['ipAddress'] ?? ''))[0]);
    $mac = strtoupper((string) ($eq['identification']['mac'] ?? ''));
    $tipo = 'SIN RADIUS';
    $usuario = '';
    $actual = '';
    $quedaria = '';

    // 1) DHCP por MAC
    if ($mac !== '') {
        try {
            $cli = rdLeerCliente($pdo, rdNormalizarMac($mac));
            $tipo = 'DHCP';
            $usuario = $mac;
            $actual = $cli['lista'];
        } catch (Throwable $e) {
        }
    }
    // 2) Por IP (DHCP con otra MAC, o PPPoE)
    if ($tipo === 'SIN RADIUS' && $ip !== '') {
        $porIp->execute([$ip]);
        $u = (string) ($porIp->fetchColumn() ?: '');
        if ($u !== '') {
            $usuario = $u;
            if (preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/i', $u)) {
                $tipo = 'DHCP';
                try {
                    $actual = rdLeerCliente($pdo, strtoupper($u))['lista'];
                } catch (Throwable $e) {
                }
            } else {
                $tipo = 'PPPoE';
                $grupo->execute([$u]);
                $actual = 'perfil ' . (string) ($grupo->fetchColumn() ?: '?');
            }
        }
    }

    $ligado = $mapa['mac:' . $mac] ?? ($ip !== '' ? ($mapa['ip:' . $ip] ?? null) : null);
    $conectado = '';
    $servicio = '';
    if ($tipo === 'DHCP') {
        $sesionDhcp->execute([strtoupper($usuario)]);
        $conectado = (int) $sesionDhcp->fetchColumn() > 0 ? 'sí' : 'no';
        $servicio = $pagos['mac:' . strtoupper($usuario)] ?? ($pagos['mac:' . $mac] ?? '');
    } elseif ($tipo === 'PPPoE') {
        $conectado = $routerOk ? (isset($activosPpp[strtolower($usuario)]) ? 'sí' : 'no') : '?';
        $servicio = $pagos['ppp:' . strtolower($usuario)] ?? '';
    }
    if ($tipo === 'SIN RADIUS') {
        $quedaria = 'nada (no está en RADIUS)';
    } elseif ($servicio !== '') {
        $quedaria = "CLIENTE en UBB Pagos ($servicio): corregir en UISP, NO cortar";
    } elseif ($ligado !== null) {
        $quedaria = "REVISAR: también ligado al servicio UISP #$ligado";
    } elseif ($actual === RD_LISTA_DEUDORES) {
        $quedaria = 'ya está en CLIENTES-DEUDORES';
    } elseif ($conectado === 'sí') {
        $quedaria = 'DUDOSO: conectado y sin servicio en UBB Pagos';
    } elseif ($tipo === 'DHCP') {
        $quedaria = 'PENDIENTE real -> CLIENTES-DEUDORES (efecto inmediato)';
    } else {
        $quedaria = 'PENDIENTE real -> CLIENTES-DEUDORES (cuando se encienda RADIUS para PPPoE)';
    }
    $clave = $tipo . ' · ' . preg_replace('/ \(.*$|: .*$| -> .*$/', '', $quedaria);
    $cuenta[$clave] = ($cuenta[$clave] ?? 0) + 1;
    $filas[] = [$tipo, $nombre, $rol, $ip, $mac, $usuario, $actual, $conectado, $servicio, $quedaria];
}

usort($filas, fn ($a, $b) => [$a[0], $a[9], $a[3]] <=> [$b[0], $b[9], $b[3]]);

if ($csv) {
    $out = fopen('php://output', 'w');
    fputcsv($out, ['tipo', 'equipo', 'rol', 'ip', 'mac', 'usuario_radius', 'lista_o_perfil_actual', 'conectado', 'servicio_ubb_pagos', 'resultado'], ',', '"', '');
    foreach ($filas as $f) {
        fputcsv($out, $f, ',', '"', '');
    }
    exit;
}

echo "SIMULACIÓN · sitio PENDIENTES · " . date('Y-m-d H:i') . " · no se cambió nada\n";
echo "Equipos en PENDIENTES: " . count($equipos) . ($pagosOk ? '' : ' · SIN cruce con UBB Pagos') . ($routerOk ? '' : ' · SIN sesiones PPPoE') . "\n\nResumen:\n";
ksort($cuenta);
foreach ($cuenta as $k => $n) {
    printf("  %-60s %4d\n", $k, $n);
}
echo "\nDetalle sin los clientes de UBB Pagos (tipo | equipo | IP | conectado | resultado):\n";
foreach ($filas as $f) {
    if ($f[0] === 'SIN RADIUS' || $f[8] !== '') {
        continue;
    }
    printf("  %-6s %-22s %-16s %-3s %s\n", $f[0], mb_substr($f[1], 0, 22), $f[3], $f[7], $f[9]);
}
echo "\nLos SIN RADIUS no se muestran (no cambiaría nada). Lista completa: php sync-pendientes.php --csv\n";
