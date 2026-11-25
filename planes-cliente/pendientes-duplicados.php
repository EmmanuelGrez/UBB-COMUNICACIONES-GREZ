<?php
/*
 * pendientes-duplicados.php - SOLO LEE UISP. Busca, para cada equipo del sitio PENDIENTES, otro registro
 * con el mismo nombre o la misma MAC que ya esté en el sitio de un cliente (típico: el equipo cambió de IP
 * y UISP lo dio de alta otra vez en PENDIENTES; el registro viejo quedó desconectado en el sitio del cliente).
 *
 * Uso (dentro del contenedor):
 *   php pendientes-duplicados.php          resumen y lista
 *   php pendientes-duplicados.php --csv    la lista en CSV (para Excel)
 *
 * No cambia nada en UISP, RADIUS ni el router.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/uisp-puente.php';
date_default_timezone_set('America/Mexico_City');
set_time_limit(0);

const PD_SITIO_PENDIENTES = '37f85909-e467-46f9-8d31-07d3417937fb';
$csv = in_array('--csv', $argv, true);

$r = uispDirecto('/devices');
if (!$r['ok']) {
    fwrite(STDERR, "No se pudo leer la lista de equipos de UISP: {$r['error']}\n");
    exit(1);
}
$equipos = json_decode($r['datos'], true);
if (!is_array($equipos)) {
    fwrite(STDERR, "UISP respondió algo que no es una lista.\n");
    exit(1);
}

function pdNombre(string $n): string
{
    return strtoupper(trim($n));
}

$pendientes = [];
$porNombre = [];
$porMac = [];
foreach ($equipos as $eq) {
    $d = [
        'nombre' => trim((string) ($eq['identification']['name'] ?? '')),
        'mac' => strtoupper((string) ($eq['identification']['mac'] ?? '')),
        'ip' => trim(explode('/', (string) ($eq['ipAddress'] ?? ''))[0]),
        'sitio_id' => (string) ($eq['identification']['site']['id'] ?? ''),
        'sitio' => trim((string) ($eq['identification']['site']['name'] ?? '')),
        'tipo_sitio' => (string) ($eq['identification']['site']['type'] ?? ''),
        'estado' => (string) ($eq['overview']['status'] ?? ''),
    ];
    if ($d['sitio_id'] === PD_SITIO_PENDIENTES) {
        $pendientes[] = $d;
        continue;
    }
    if ($d['nombre'] !== '') {
        $porNombre[pdNombre($d['nombre'])][] = $d;
    }
    if ($d['mac'] !== '') {
        $porMac[$d['mac']][] = $d;
    }
}

$filas = [];
foreach ($pendientes as $p) {
    $candidatos = [];
    foreach ($porMac[$p['mac']] ?? [] as $c) {
        $candidatos[$c['sitio_id'] . '|' . $c['ip']] = $c + ['por' => 'misma MAC'];
    }
    foreach ($porNombre[pdNombre($p['nombre'])] ?? [] as $c) {
        $k = $c['sitio_id'] . '|' . $c['ip'];
        if (!isset($candidatos[$k])) {
            $candidatos[$k] = $c + ['por' => 'mismo nombre'];
        }
    }
    $sitios = array_unique(array_map(fn ($c) => $c['sitio'], $candidatos));
    if ($candidatos === []) {
        $resultado = 'SIN COINCIDENCIA';
    } elseif (count($sitios) === 1) {
        $resultado = 'UN SITIO: mover ahí';
    } else {
        $resultado = 'VARIOS SITIOS: revisar';
    }
    $filas[] = ['p' => $p, 'c' => array_values($candidatos), 'resultado' => $resultado];
}
usort($filas, fn ($a, $b) => strcmp($a['resultado'], $b['resultado']) ?: strnatcmp($a['p']['ip'], $b['p']['ip']));

if ($csv) {
    $out = fopen('php://output', 'w');
    fputcsv($out, ['resultado', 'equipo_pendientes', 'ip', 'mac', 'estado', 'coincide_por', 'sitio_cliente', 'tipo_sitio', 'ip_registro_otro', 'estado_registro_otro'], ',', '"', '');
    foreach ($filas as $f) {
        $p = $f['p'];
        if ($f['c'] === []) {
            fputcsv($out, [$f['resultado'], $p['nombre'], $p['ip'], $p['mac'], $p['estado'], '', '', '', '', ''], ',', '"', '');
        }
        foreach ($f['c'] as $c) {
            fputcsv($out, [$f['resultado'], $p['nombre'], $p['ip'], $p['mac'], $p['estado'], $c['por'], $c['sitio'], $c['tipo_sitio'], $c['ip'], $c['estado']], ',', '"', '');
        }
    }
    exit;
}

$cuenta = array_count_values(array_map(fn ($f) => $f['resultado'], $filas));
echo 'SOLO LECTURA · duplicados de PENDIENTES · ' . date('Y-m-d H:i') . "\n";
echo 'Equipos en UISP: ' . count($equipos) . ' · en PENDIENTES: ' . count($pendientes) . "\n\n";
foreach ($cuenta as $k => $n) {
    printf("  %-28s %4d\n", $k, $n);
}
echo "\nCon coincidencia (equipo | IP | sitio donde ya existe | IP del otro registro | estado del otro):\n";
foreach ($filas as $f) {
    foreach ($f['c'] as $c) {
        printf("  %-18s %-16s -> %-40s %-16s %s (%s)\n", $f['p']['nombre'], $f['p']['ip'], $c['sitio'], $c['ip'], $c['estado'], $c['por']);
    }
}
echo "\nLista completa: php pendientes-duplicados.php --csv\n";
