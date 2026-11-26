<?php
/*
 * relacion-pppoe.php - SOLO LEE. Propone qué cuenta PPPoE de RADIUS corresponde a cada servicio de la
 * plataforma de Jamileth que todavía no tiene relación, cruzando:
 *   servicio (plataforma) -> su número de UISP -> IPs de ese servicio en UISP (radius.ubb_uisp_mapa)
 *   -> cuenta PPPoE de RADIUS que tiene esa IP fija.
 * Es una PROPUESTA para que Jamileth la revise y cargue lo que confirme. No cambia nada en ningún lado.
 *
 * Uso (dentro del contenedor):
 *   php relacion-pppoe.php          resumen
 *   php relacion-pppoe.php --csv    lista completa (para Excel)
 *   --sin-inventario                no consulta el inventario de la plataforma (más rápido)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
date_default_timezone_set('America/Mexico_City');

function rpBD(): PDO
{
    $host = trim((string) (getenv('DB_HOST') ?: ''));
    $socket = getenv('DB_SOCKET') ?: '/var/run/mysqld/mysqld.sock';
    $dsn = $host !== ''
        ? 'mysql:host=' . $host . ';port=' . ((int) (getenv('DB_PORT') ?: 3306)) . ';dbname=radius;charset=utf8mb4'
        : 'mysql:unix_socket=' . $socket . ';dbname=radius;charset=utf8mb4';
    return new PDO($dsn, (string) getenv('DB_USER'), (string) getenv('DB_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

$csv = in_array('--csv', $argv, true);
$radius = rpBD();
$jam = new PDO('mysql:host=192.168.100.221;port=3306;dbname=ubb_plataforma;charset=utf8mb4', 'equipo_ubb',
    rtrim((string) file_get_contents('/etc/ubb/jamileth.pass'), "\r\n"),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 10]);

/* 1. Columnas de UISP en servicios: número de servicio (uisp_service_id) y sitio (uisp_location_id) */
$columnas = array_column($jam->query('SHOW COLUMNS FROM servicios')->fetchAll(), 'Field');
if (!in_array('uisp_service_id', $columnas, true)) {
    fwrite(STDERR, "No existe servicios.uisp_service_id. Columnas: " . implode(', ', $columnas) . "\n");
    exit(1);
}
$colSitio = in_array('uisp_location_id', $columnas, true) ? 'uisp_location_id' : null;

/* 2. Servicios de la plataforma y los que ya tienen relación */
$servicios = $jam->query("SELECT s.id_servicio, s.uisp_service_id AS uisp, " . ($colSitio ? "s.`$colSitio`" : "''") . " AS sitio, s.estado, s.tecnologia,
                                 CONCAT_WS(' ', c.nombre, c.apellido_paterno, c.apellido_materno) AS cliente
                          FROM servicios s JOIN clientes c ON c.id_cliente = s.id_cliente")->fetchAll();
$conRelacion = [];
foreach ($jam->query("SELECT DISTINCT id_servicio FROM integracion_winbox
                      WHERE UPPER(estado_vinculacion) LIKE 'RELACIONADO%' AND id_servicio IS NOT NULL
                        AND ((mac IS NOT NULL AND TRIM(mac) <> '') OR (pppoe_name IS NOT NULL AND TRIM(pppoe_name) <> ''))") as $r) {
    $conRelacion[(int) $r['id_servicio']] = true;
}

/* Cuentas PPPoE que ya tienen dueño en la plataforma (no se proponen a otro servicio) */
$duenoCuenta = [];
foreach ($jam->query("SELECT LOWER(TRIM(pppoe_name)) AS u, id_servicio FROM integracion_winbox
                      WHERE UPPER(estado_vinculacion) LIKE 'RELACIONADO%' AND pppoe_name IS NOT NULL AND TRIM(pppoe_name) <> ''") as $r) {
    $duenoCuenta[$r['u']][(int) $r['id_servicio']] = true;
}

/* 3. UISP: IPs de cada servicio */
$ipsUisp = [];
$ipsSitio = [];
foreach ($radius->query("SELECT servicio_id, sitio_id, ip FROM ubb_uisp_mapa WHERE ip IS NOT NULL AND ip <> ''") as $r) {
    $ipsUisp[(int) $r['servicio_id']][trim($r['ip'])] = true;
    if (!empty($r['sitio_id'])) $ipsSitio[strtolower((string) $r['sitio_id'])][trim($r['ip'])] = true;
}

/* 4. RADIUS: cuentas PPPoE (por IP, por nombre y por la MAC con que se conectan) */
$cuentaPorIp = [];
$cuentaPorNombre = [];
foreach ($radius->query("SELECT c.username, ip.value AS ip, g.value AS perfil
                         FROM radcheck c
                         LEFT JOIN radreply ip ON ip.username = c.username AND ip.attribute = 'Framed-IP-Address'
                         LEFT JOIN radreply g ON g.username = c.username AND g.attribute = 'Mikrotik-Group'
                         WHERE c.attribute = 'Cleartext-Password'") as $r) {
    if (preg_match('/^(?:[0-9A-F]{2}:){5}[0-9A-F]{2}$/i', $r['username'])) continue;
    $r['ip'] = trim((string) $r['ip']);
    if ($r['ip'] !== '') $cuentaPorIp[$r['ip']][] = $r;
    $cuentaPorNombre[strtolower(trim($r['username']))] = $r;
}
function rpHex(string $mac): ?int
{
    $h = preg_replace('/[^0-9A-Fa-f]/', '', $mac);
    return strlen($h) === 12 ? hexdec($h) : null;
}
$cuentaPorMac = [];   // MAC (como número) -> usuarios PPPoE que se han conectado con ella
foreach ($radius->query("SELECT DISTINCT username, callingstationid FROM radacct WHERE callingstationid <> ''") as $r) {
    $u = strtolower(trim($r['username']));
    $h = rpHex((string) $r['callingstationid']);
    if ($h !== null && isset($cuentaPorNombre[$u])) $cuentaPorMac[$h][$u] = true;
}

/* Pistas de la plataforma por servicio: filas de integracion_winbox aunque no estén RELACIONADO (MAC, hostname, pppoe_name) */
$pistas = [];
foreach ($jam->query("SELECT id_servicio, mac, hostname, pppoe_name FROM integracion_winbox WHERE id_servicio IS NOT NULL") as $r) {
    $pistas[(int) $r['id_servicio']][] = $r;
}
$usarInventario = !in_array('--sin-inventario', $argv, true);
if ($usarInventario) require_once __DIR__ . '/avisos-api.php';

/* 5. Propuesta: cada cuenta candidata junta por qué coincide */
$filas = [];
$usoCuenta = [];
$diag = ['servicios' => 0, 'con_nombre' => 0, 'con_mac' => 0, 'inv_ok' => 0, 'inv_error' => 0, 'inv_ejemplo' => null, 'error_ejemplo' => null, 'nombres_ejemplo' => [], 'macs_ejemplo' => []];
foreach ($servicios as $s) {
    $id = (int) $s['id_servicio'];
    if (isset($conRelacion[$id])) continue;
    $uisp = (int) $s['uisp'];
    $cands = [];
    $agregar = function (array $cta, string $por) use (&$cands) {
        $u = $cta['username'];
        $cands[$u] = ($cands[$u] ?? $cta + ['por' => []]);
        $cands[$u]['por'][$por] = true;
    };
    // a) UISP: IPs del servicio y del sitio
    $ipsDelServicio = $ipsUisp[$uisp] ?? [];
    $ipsDelSitio = $ipsSitio[strtolower((string) $s['sitio'])] ?? [];
    foreach (array_keys($ipsDelServicio) as $ip) foreach ($cuentaPorIp[$ip] ?? [] as $c) $agregar($c, 'UISP servicio');
    foreach (array_keys($ipsDelSitio) as $ip) foreach ($cuentaPorIp[$ip] ?? [] as $c) $agregar($c, 'UISP sitio');
    // b) Nombre y MAC que la plataforma ya tiene para el servicio (importación) y su inventario
    $nombres = [];
    $macs = [];
    foreach ($pistas[$id] ?? [] as $p) {
        foreach (['pppoe_name', 'hostname'] as $k) if (!empty($p[$k])) $nombres[] = trim((string) $p[$k], " ,");
        if (!empty($p['mac'])) $macs[] = (string) $p['mac'];
    }
    if ($usarInventario && $uisp > 0) {
        $r = avApi('GET', '/equipos.php?servicio=' . $uisp, 5);
        if ($r['ok']) { $diag['inv_ok']++; $diag['inv_ejemplo'] ??= ['servicio_uisp' => $uisp] + $r['datos']; }
        else { $diag['inv_error']++; $diag['error_ejemplo'] ??= "servicio_uisp $uisp: " . ($r['error'] ?? ''); }
        $lista = $r['ok'] ? ($r['datos']['equipos'] ?? (isset($r['datos']['equipo']) ? [$r['datos']['equipo']] : [])) : [];
        foreach ((array) $lista as $e) {
            if (!empty($e['nombre_red'])) $nombres[] = (string) $e['nombre_red'];
            if (!empty($e['mac'])) $macs[] = (string) $e['mac'];
        }
    }
    $diag['servicios']++;
    if ($nombres) { $diag['con_nombre']++; if (count($diag['nombres_ejemplo']) < 5) $diag['nombres_ejemplo'][] = "$id: " . implode(' / ', array_unique($nombres)); }
    if ($macs) { $diag['con_mac']++; if (count($diag['macs_ejemplo']) < 5) $diag['macs_ejemplo'][] = "$id: " . implode(' / ', array_unique($macs)); }
    foreach (array_unique($nombres) as $n) {
        if (isset($cuentaPorNombre[strtolower($n)])) $agregar($cuentaPorNombre[strtolower($n)], 'nombre del módem');
    }
    foreach (array_unique($macs) as $m) {
        $h = rpHex($m);
        if ($h === null) continue;
        foreach ([$h, $h - 1, $h + 1] as $hh) {
            foreach (array_keys($cuentaPorMac[$hh] ?? []) as $u) $agregar($cuentaPorNombre[$u], 'MAC del módem');
        }
    }
    foreach ($cands as $u => $_) $usoCuenta[$u][] = $id;
    $filas[] = ['s' => $s, 'uisp' => $uisp, 'cands' => array_values($cands)];
}
$cuenta = ['SEGURO' => 0, 'DUDOSO' => 0, 'SIN CUENTA PPPoE' => 0];
foreach ($filas as &$f) {
    $n = count($f['cands']);
    foreach ($f['cands'] as &$c) {
        $c['por_txt'] = implode(' + ', array_keys($c['por']));
    }
    unset($c);
    if ($n === 0) {
        $f['conf'] = 'SIN CUENTA PPPoE';
        $cuenta[$f['conf']]++;
        continue;
    }
    if ($n > 1) {
        $f['conf'] = 'DUDOSO';
        $f['nota'] = 'varias cuentas posibles para este servicio';
        $cuenta['DUDOSO']++;
        continue;
    }
    $c = $f['cands'][0];
    $u = $c['username'];
    $yaTiene = array_keys($duenoCuenta[strtolower($u)] ?? []);
    $fuerte = isset($c['por']['nombre del módem']) || isset($c['por']['MAC del módem'])
        || (isset($c['por']['UISP servicio']) && (isset($c['por']['UISP sitio']) || $colSitio === null));
    if ($yaTiene !== []) {
        $f['conf'] = 'DUDOSO';
        $f['nota'] = 'esa cuenta ya está ligada en la plataforma al servicio ' . implode(', ', $yaTiene) . ': revisar';
    } elseif (count($usoCuenta[$u]) > 1) {
        $f['conf'] = 'DUDOSO';
        $f['nota'] = 'la misma cuenta sale también para el servicio ' . implode(', ', array_diff($usoCuenta[$u], [(int) $f['s']['id_servicio']]));
    } elseif (!$fuerte) {
        $f['conf'] = 'DUDOSO';
        $f['nota'] = 'solo coincide por ' . $c['por_txt'];
    } else {
        $f['conf'] = 'SEGURO';
    }
    $cuenta[$f['conf']]++;
}
unset($f);

if (in_array('--diag', $argv, true)) {
    echo "DIAGNÓSTICO\n";
    echo "  Cuentas PPPoE en RADIUS: " . count($cuentaPorNombre) . "\n";
    echo "  MACs vistas en conexiones PPPoE (radacct): " . count($cuentaPorMac) . "\n";
    echo "  Servicios sin relación revisados: {$diag['servicios']}\n";
    echo "  ...con nombre de módem conocido: {$diag['con_nombre']}\n";
    echo "  ...con MAC conocida: {$diag['con_mac']}\n";
    echo "  Inventario: {$diag['inv_ok']} respuestas bien, {$diag['inv_error']} con error\n";
    if ($diag['error_ejemplo']) echo "  Error ejemplo: {$diag['error_ejemplo']}\n";
    if ($diag['inv_ejemplo']) echo "  Respuesta ejemplo: " . mb_substr(json_encode($diag['inv_ejemplo'], JSON_UNESCAPED_UNICODE), 0, 600) . "\n";
    foreach ($diag['nombres_ejemplo'] as $x) echo "  nombre  $x\n";
    foreach ($diag['macs_ejemplo'] as $x) echo "  mac     $x\n";
    $ej = $radius->query("SELECT username, callingstationid FROM radacct WHERE username NOT LIKE '__:__:__:__:__:__' ORDER BY radacctid DESC LIMIT 3")->fetchAll(PDO::FETCH_NUM);
    foreach ($ej as $x) echo "  radacct PPPoE: {$x[0]}  MAC={$x[1]}\n";
    exit;
}

if ($csv) {
    $out = fopen('php://output', 'w');
    fputcsv($out, ['confianza', 'id_servicio', 'cliente', 'estado', 'tecnologia', 'servicio_uisp', 'usuario_pppoe', 'ip', 'perfil', 'coincide_por', 'nota'], ',', '"', '');
    foreach ($filas as $f) {
        if ($f['cands'] === []) {
            fputcsv($out, [$f['conf'], $f['s']['id_servicio'], $f['s']['cliente'], $f['s']['estado'], $f['s']['tecnologia'], $f['uisp'], '', '', '', '', ''], ',', '"', '');
        }
        foreach ($f['cands'] as $c) {
            fputcsv($out, [$f['conf'], $f['s']['id_servicio'], $f['s']['cliente'], $f['s']['estado'], $f['s']['tecnologia'], $f['uisp'], $c['username'], $c['ip'], $c['perfil'], $c['por_txt'], $f['nota'] ?? ''], ',', '"', '');
        }
    }
    exit;
}

echo 'SOLO LECTURA · propuesta de relación servicio -> cuenta PPPoE · ' . date('Y-m-d H:i') . "\n";
echo "Cruce por UISP (servicio y sitio), nombre del módem y MAC del módem" . ($usarInventario ? ' (con inventario)' : '') . "\n";
echo 'Servicios en la plataforma: ' . count($servicios) . ' · ya relacionados: ' . count($conRelacion) . ' · sin relación: ' . count($filas) . "\n\n";
foreach ($cuenta as $k => $n) printf("  %-18s %4d\n", $k, $n);
echo "\nEjemplos SEGUROS:\n";
$i = 0;
foreach ($filas as $f) {
    if ($f['conf'] !== 'SEGURO') continue;
    printf("  servicio %-6s %-30s -> %-16s %-12s %s\n", $f['s']['id_servicio'], mb_substr($f['s']['cliente'], 0, 30), $f['cands'][0]['username'], $f['cands'][0]['ip'], $f['cands'][0]['por_txt']);
    if (++$i >= 10) break;
}
echo "\nLista completa: php relacion-pppoe.php --csv\n";
