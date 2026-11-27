<?php
/*
 * cortar-pendientes.php - Corta por lotes los equipos DUDOSOS del sitio PENDIENTES de UISP
 * (conectados y sin servicio ligado). Autorizado por el ingeniero el 25/11/2026.
 *
 * La lista sale EN VIVO de sync-pendientes.php (solo los DUDOSO). Por defecto se excluyen los que
 * pendientes-duplicados.php relaciona con el sitio de un cliente en UISP (probablemente sí son clientes).
 *
 * Uso (dentro del contenedor):
 *   php cortar-pendientes.php                         SIMULA: muestra a quién cortaría (no cambia nada)
 *   php cortar-pendientes.php --aplicar --lote=20     corta los primeros 20 que falten
 *   php cortar-pendientes.php --aplicar --lote=20 --solo=dhcp|pppoe
 *   php cortar-pendientes.php --incluir-duplicados    no excluye a los que tienen coincidencia en UISP
 *   php cortar-pendientes.php --incluir-desconectados también corta los "PENDIENTE real" (no conectados)
 *   php cortar-pendientes.php --solo-bajas            corta SOLO los que tienen servicio dado de BAJA en UBB Pagos
 *   php cortar-pendientes.php --deshacer              SIMULA reactivar a todos los que cortó esta herramienta
 *   php cortar-pendientes.php --deshacer --aplicar    los reactiva
 *   php cortar-pendientes.php --deshacer=UBB-TP5DDB13 --aplicar   reactiva solo a uno (MAC o usuario PPPoE)
 *   php cortar-pendientes.php --deshacer=UBB-A,UBB-B,AA:BB:CC:DD:EE:FF   varios, separados por coma
 *
 * Cortar = lo mismo que "Suspender" del panel: CLIENTES-DEUDORES (página de adeudo), bitácora,
 * aviso al monitoreo. Cada corte queda marcado en ubb_bitacora (accion 'pendientes') para poder deshacerlo.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/uisp-puente.php';
require_once __DIR__ . '/radius-dhcp-acciones.php';

use RouterOS\Client;
use RouterOS\Query;

date_default_timezone_set('America/Mexico_City');
set_time_limit(0);

$aplicar = in_array('--aplicar', $argv, true);
$incluirDuplicados = in_array('--incluir-duplicados', $argv, true);
$incluirDesconectados = in_array('--incluir-desconectados', $argv, true);
$soloBajas = in_array('--solo-bajas', $argv, true);
$lote = 20;
$solo = '';
$deshacer = null;
foreach ($argv as $a) {
    if (preg_match('/^--lote=(\d+)$/', $a, $m)) $lote = max(1, (int) $m[1]);
    if (preg_match('/^--solo=(dhcp|pppoe)$/i', $a, $m)) $solo = strtoupper($m[1]) === 'DHCP' ? 'DHCP' : 'PPPoE';
    if ($a === '--deshacer') $deshacer = '*';
    if (preg_match('/^--deshacer=(.+)$/', $a, $m)) $deshacer = trim($m[1]);
}

function cpBD(): PDO
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

/** Corre otro script del panel con --csv y devuelve sus filas (ignora avisos antes del encabezado). */
function cpCsv(string $script, string $encabezado): array
{
    $salida = (string) shell_exec('php ' . escapeshellarg(__DIR__ . '/' . $script) . ' --csv 2>/dev/null');
    $lineas = preg_split('/\r?\n/', $salida);
    while ($lineas && strpos((string) $lineas[0], $encabezado) !== 0) {
        array_shift($lineas);
    }
    if (!$lineas) {
        throw new RuntimeException("$script no devolvió datos (¿UISP o la base no responden?).");
    }
    $cab = str_getcsv(array_shift($lineas), ',', '"', '');
    $filas = [];
    foreach ($lineas as $l) {
        if (trim($l) === '') continue;
        $v = str_getcsv($l, ',', '"', '');
        if (count($v) === count($cab)) $filas[] = array_combine($cab, $v);
    }
    return $filas;
}

function cpVlanDeIp(string $ip): ?array
{
    foreach (rdVlans() as $vlan => $cfg) {
        if (strpos($ip, $cfg['prefijo']) === 0) return $cfg + ['vlan' => (string) $vlan];
    }
    return null;
}

function cpDesconectarPppoe(Client $router, string $usuario): bool
{
    $hubo = false;
    foreach (rdRouter($router, (new Query('/ppp/active/print'))->where('name', $usuario)) as $s) {
        if (!empty($s['.id'])) {
            rdRouter($router, (new Query('/ppp/active/remove'))->equal('.id', (string) $s['.id']));
            $hubo = true;
        }
    }
    return $hubo;
}

function cpSecretActivo(Client $router, string $usuario): bool
{
    foreach (rdRouter($router, (new Query('/ppp/secret/print'))->where('name', $usuario)) as $s) {
        if (!in_array(strtolower((string) ($s['disabled'] ?? 'false')), ['true', 'yes'], true)) return true;
    }
    return false;
}

$pdo = cpBD();
$modo = $aplicar ? 'APLICAR' : 'SIMULACIÓN';

/* ------------------------------ Deshacer ------------------------------ */
if ($deshacer !== null) {
    $cortados = $pdo->query("SELECT mac AS quien, MAX(ip) AS ip FROM ubb_bitacora WHERE accion = 'pendientes' AND detalle LIKE 'corte%' GROUP BY mac")->fetchAll();
    if ($deshacer !== '*') {
        $pedidos = array_values(array_filter(array_map(fn ($x) => strtoupper(trim($x)), explode(',', $deshacer))));
        $cortados = array_values(array_filter($cortados, fn ($c) => in_array(strtoupper(trim((string) $c['quien'])), $pedidos, true)));
        $hallados = array_map(fn ($c) => strtoupper(trim((string) $c['quien'])), $cortados);
        foreach (array_diff($pedidos, $hallados) as $falta) {
            echo "  NO lo cortó esta herramienta (no se toca): $falta\n";
        }
    }
    echo "$modo · deshacer cortes de PENDIENTES · " . count($cortados) . " equipo(s)\n";
    $router = $aplicar ? uispConectarRouter('10.0.1.6') : null;
    foreach ($cortados as $c) {
        $u = (string) $c['quien'];
        $st = $pdo->prepare("SELECT value FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Address-List'");
        $st->execute([$u]);
        if ((string) $st->fetchColumn() !== RD_LISTA_DEUDORES) {
            echo "  ya activo      $u\n";
            continue;
        }
        $esDhcp = (bool) preg_match('/^(?:[0-9A-F]{2}:){5}[0-9A-F]{2}$/i', $u);
        echo '  ' . ($aplicar ? 'REACTIVAR' : 'reactivaría') . '  ' . ($esDhcp ? 'DHCP ' : 'PPPoE') . " $u {$c['ip']}\n";
        if (!$aplicar) continue;
        try {
            if ($esDhcp) {
                $cfg = cpVlanDeIp(rdLeerCliente($pdo, $u)['ip']);
                if ($cfg === null) throw new RuntimeException('VLAN no encontrada');
                rdReactivar($pdo, $router, $cfg, $u, 'PANEL');
            } else {
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Address-List'")->execute([$u]);
                $pdo->prepare("DELETE FROM ubb_suspensiones WHERE username = ?")->execute([$u]);
                $pdo->commit();
                cpDesconectarPppoe($router, $u);
                rdBitacora($pdo, $u, (string) $c['ip'], 'reactivar', 'PPPoE, deshacer corte de PENDIENTES', 'PANEL');
                rdAvisarMonitoreo($pdo, 'REACTIVACION', ['usuario' => $u], 'Se deshizo corte de PENDIENTES', 'PANEL', (string) $c['ip']);
            }
            rdBitacora($pdo, $u, (string) $c['ip'], 'pendientes', 'deshecho', 'PANEL');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo "    ERROR: {$e->getMessage()}\n";
        }
    }
    exit(0);
}

/* ------------------------------ Cortar ------------------------------ */
$dudosos = array_values(array_filter(cpCsv('sync-pendientes.php', 'tipo,'), fn ($f) => $soloBajas
    ? (strpos($f['resultado'], 'CLIENTE en UBB Pagos') === 0 && stripos($f['servicio_ubb_pagos'], '(BAJA)') !== false)
    : (strpos($f['resultado'], 'DUDOSO') === 0
       || ($incluirDesconectados && strpos($f['resultado'], 'PENDIENTE real') === 0))));
$excluidos = [];
if (!$incluirDuplicados) {
    foreach (cpCsv('pendientes-duplicados.php', 'resultado,') as $f) {
        if ($f['resultado'] !== 'SIN COINCIDENCIA') $excluidos[$f['ip']] = $f['sitio_cliente'];
    }
}
$ya = [];
foreach ($pdo->query("SELECT DISTINCT mac FROM ubb_bitacora WHERE accion = 'pendientes' AND detalle LIKE 'corte%'") as $r) {
    $ya[strtoupper($r['mac'])] = true;
}

$lista = [];
$omitidos = [];
foreach ($dudosos as $f) {
    $u = trim($f['usuario_radius']);
    if ($solo !== '' && $f['tipo'] !== $solo) continue;
    if ($u === '' || stripos($u, 'PRUEBA') !== false) { $omitidos[] = [$f, 'sin usuario o es de prueba']; continue; }
    if (isset($excluidos[$f['ip']])) { $omitidos[] = [$f, 'coincide en UISP con: ' . $excluidos[$f['ip']]]; continue; }
    if (isset($ya[strtoupper($u)])) continue;
    $lista[] = $f;
}

echo "$modo · cortar " . ($soloBajas ? 'SERVICIOS EN BAJA' : 'DUDOSOS' . ($incluirDesconectados ? ' y PENDIENTES REALES' : '')) . " de PENDIENTES · " . date('Y-m-d H:i') . "\n";
echo 'Dudosos: ' . count($dudosos) . ' · excluidos: ' . count($omitidos) . ' · ya cortados antes: ' . count($ya)
    . ' · por cortar: ' . count($lista) . ' · este lote: ' . min($lote, count($lista)) . "\n\n";
foreach ($omitidos as [$f, $por]) {
    printf("  NO SE CORTA  %-6s %-18s %-16s %s\n", $f['tipo'], $f['equipo'], $f['ip'], $por);
}
if ($omitidos) echo "\n";

$router = $aplicar ? uispConectarRouter('10.0.1.6') : null;
$hechos = 0;
foreach (array_slice($lista, 0, $lote) as $f) {
    $u = trim($f['usuario_radius']);
    $ip = $f['ip'];
    printf("  %-11s %-6s %-18s %-16s %s\n", $aplicar ? 'CORTAR' : 'cortaría', $f['tipo'], $f['equipo'], $ip, $u);
    if (!$aplicar) continue;
    try {
        if ($f['tipo'] === 'DHCP') {
            $mac = rdNormalizarMac($u);
            rdSuspender($pdo, $router, $mac, 'PANEL');
            $u = $mac;
        } else {
            if (cpSecretActivo($router, $u)) {
                throw new RuntimeException('tiene cuenta activa en el router (no migrado): no se corta');
            }
            $st = $pdo->prepare("SELECT value FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Group'");
            $st->execute([$u]);
            $perfil = (string) ($st->fetchColumn() ?: '');
            $pdo->beginTransaction();
            $pdo->prepare("REPLACE INTO ubb_suspensiones (username, lista_original, ip, origen) VALUES (?, ?, ?, 'PANEL')")
                ->execute([$u, 'perfil ' . $perfil, $ip]);
            $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Address-List'")->execute([$u]);
            $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Address-List', ':=', ?)")
                ->execute([$u, RD_LISTA_DEUDORES]);
            $pdo->commit();
            rdAvisarMonitoreo($pdo, 'BAJA_PAGO', ['usuario' => $u], 'Equipo sin servicio (PENDIENTES)', 'PANEL', $ip);
            cpDesconectarPppoe($router, $u);   // si no estaba conectado no pasa nada
            rdBitacora($pdo, $u, $ip, 'suspender', 'PPPoE, equipo sin servicio en PENDIENTES, estaba ' . $perfil, 'PANEL');
        }
        rdBitacora($pdo, $u, $ip, 'pendientes', 'corte ' . date('Y-m-d H:i'), 'PANEL');
        $hechos++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo "      ERROR: {$e->getMessage()}\n";
    }
}
echo "\n" . ($aplicar ? "Cortados en este lote: $hechos." : 'No se cambió nada. Para cortar este lote: --aplicar') . "\n";
echo "Deshacer: php cortar-pendientes.php --deshacer (simula) · --deshacer --aplicar · --deshacer=USUARIO_O_MAC --aplicar\n";
