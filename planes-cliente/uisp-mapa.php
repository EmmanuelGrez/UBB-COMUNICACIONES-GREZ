<?php
/*
 * uisp-mapa.php - Arma la tabla radius.ubb_uisp_mapa: qué servicio de UISP corresponde a cada IP/MAC.
 *
 * Camino: servicio (UISP CRM) -> sitio del cliente -> equipos del sitio (UISP NMS) -> IP y MAC.
 * Todo pasa por el router MKR-FSL02 (uisp-puente.php). Solo LEE de UISP; solo escribe en la tabla del mapa.
 *
 * Uso (dentro del contenedor):
 *   php uisp-mapa.php             arma el mapa completo (tarda varios minutos)
 *   php uisp-mapa.php --simular   recorre todo y muestra el resumen, sin guardar
 *   php uisp-mapa.php --max=30    solo los primeros 30 servicios (para probar)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/uisp-puente.php';

date_default_timezone_set('America/Mexico_City');
set_time_limit(0);

$simular = in_array('--simular', $argv, true);
$max = 0;
foreach ($argv as $a) {
    if (preg_match('/^--max=(\d+)$/', $a, $m)) {
        $max = (int) $m[1];
    }
}

function salida(string $msg): void
{
    echo date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL;
}

function conectarBD(): PDO
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

/** IP sin la máscara ("192.168.8.67/24" -> "192.168.8.67"). */
function soloIp(string $ip): string
{
    $ip = trim(explode('/', $ip)[0]);
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

try {
    $pdo = conectarBD();
    if (!$simular) {
        try {
            uispCrearTablas($pdo);
        } catch (Throwable $e) {
            salida('ERROR: no pude crear las tablas ubb_uisp_mapa / ubb_uisp_gracia (' . $e->getMessage() . ').');
            salida('Créalas a mano en la base radius con un usuario que tenga permiso CREATE.');
            exit(1);
        }
    }
    $router = uispConectarRouter();
    uispTokenNms();
    uispLlave();

    $filas = [];
    $servicios = 0;
    $sinSitio = 0;
    $erroresNms = 0;
    $offset = 0;
    $lote = 10;
    $inicio = time();

    while (true) {
        $r = uispPorRouter($router, 'GET', "/clients/services?limit=$lote&offset=$offset");
        if ($r['ok'] && strlen($r['datos']) >= 64000 && $lote > 2) {
            $lote = max(2, intdiv($lote, 2));   // respuesta cortada por el router: pedir menos a la vez
            continue;
        }
        if (!$r['ok']) {
            throw new RuntimeException("UISP no devolvió servicios (offset $offset): {$r['error']}");
        }
        $lista = json_decode($r['datos'], true);
        if (!is_array($lista)) {
            throw new RuntimeException("Respuesta no válida de UISP en offset $offset.");
        }

        foreach ($lista as $sv) {
            $servicios++;
            $sitio = (string) ($sv['unmsClientSiteId'] ?? '');
            if ($sitio === '') {
                $sinSitio++;
                continue;
            }
            $d = uispPorRouter($router, 'GET', '/devices?siteId=' . rawurlencode($sitio), null, 'nms');
            if (!$d['ok']) {
                $erroresNms++;
                continue;
            }
            foreach ((array) json_decode($d['datos'], true) as $eq) {
                $ip = soloIp((string) ($eq['ipAddress'] ?? ''));
                $mac = strtoupper((string) ($eq['identification']['mac'] ?? ''));
                if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) {
                    $mac = '';
                }
                if ($ip === '' && $mac === '') {
                    continue;
                }
                $filas[] = [
                    (int) $sv['id'],
                    isset($sv['clientId']) ? (int) $sv['clientId'] : null,
                    isset($sv['status']) ? (int) $sv['status'] : null,
                    $sitio,
                    $ip,
                    $mac,
                    (string) ($eq['identification']['role'] ?? ''),
                ];
            }
            if ($max > 0 && $servicios >= $max) {
                break 2;
            }
        }

        if (count($lista) < $lote) {
            break;   // última página
        }
        $offset += count($lista);
        if ($servicios % 100 < $lote) {
            salida("... $servicios servicios leídos (" . (time() - $inicio) . 's)');
        }
    }

    $conIp = count(array_unique(array_column($filas, 0)));
    salida("Servicios leídos: $servicios | con equipos: $conIp | sin sitio: $sinSitio | errores NMS: $erroresNms | filas: " . count($filas)
        . ' | ' . (time() - $inicio) . 's');

    if ($simular) {
        salida('[SIMULADO] No se guardó nada.');
        exit(0);
    }
    if ($filas === [] || ($servicios > 20 && $erroresNms > $servicios / 2)) {
        salida('AVISO: demasiados errores o mapa vacío; se conserva el mapa anterior.');
        exit(1);
    }
    if ($max > 0) {
        salida("Prueba con --max=$max: se guardan solo estos servicios (el mapa anterior se reemplaza).");
    }

    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM ubb_uisp_mapa');
    $ins = $pdo->prepare('INSERT INTO ubb_uisp_mapa (servicio_id, cliente_id, estado, sitio_id, ip, mac, rol) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($filas as $f) {
        $ins->execute($f);
    }
    $pdo->commit();
    salida('Mapa guardado: ' . count($filas) . ' filas.');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    salida('ERROR: ' . $e->getMessage());
    exit(1);
}
