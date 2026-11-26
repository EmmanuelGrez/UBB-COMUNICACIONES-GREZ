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

/* 4. RADIUS: cuentas PPPoE por IP */
$cuentaPorIp = [];
foreach ($radius->query("SELECT c.username, ip.value AS ip, g.value AS perfil
                         FROM radcheck c
                         JOIN radreply ip ON ip.username = c.username AND ip.attribute = 'Framed-IP-Address'
                         LEFT JOIN radreply g ON g.username = c.username AND g.attribute = 'Mikrotik-Group'
                         WHERE c.attribute = 'Cleartext-Password'") as $r) {
    if (preg_match('/^(?:[0-9A-F]{2}:){5}[0-9A-F]{2}$/i', $r['username'])) continue;
    $cuentaPorIp[trim($r['ip'])][] = $r;
}

/* 5. Propuesta */
$filas = [];
$usoCuenta = [];
foreach ($servicios as $s) {
    $id = (int) $s['id_servicio'];
    if (isset($conRelacion[$id])) continue;
    $uisp = (int) $s['uisp'];
    $cands = [];
    $ipsDelServicio = $ipsUisp[$uisp] ?? [];
    $ipsDelSitio = $ipsSitio[strtolower((string) $s['sitio'])] ?? [];
    foreach (array_keys($ipsDelServicio + $ipsDelSitio) as $ip) {
        foreach ($cuentaPorIp[$ip] ?? [] as $cta) {
            // por = servicio y sitio coinciden (más seguro) / solo uno de los dos
            $por = isset($ipsDelServicio[$ip]) && isset($ipsDelSitio[$ip]) ? 'servicio y sitio' : (isset($ipsDelServicio[$ip]) ? 'solo servicio' : 'solo sitio');
            $cands[$cta['username']] = $cta + ['por' => $por];
        }
    }
    foreach ($cands as $u => $_) $usoCuenta[$u][] = $id;
    $filas[] = ['s' => $s, 'uisp' => $uisp, 'cands' => array_values($cands)];
}
$cuenta = ['SEGURO' => 0, 'DUDOSO' => 0, 'SIN CUENTA PPPoE' => 0];
foreach ($filas as &$f) {
    $n = count($f['cands']);
    $yaTiene = $n === 1 ? array_keys($duenoCuenta[strtolower($f['cands'][0]['username'])] ?? []) : [];
    if ($yaTiene !== []) {
        $f['conf'] = 'DUDOSO';
        $f['nota'] = 'esa cuenta ya está ligada en la plataforma al servicio ' . implode(', ', $yaTiene) . ' (UISP dice este): revisar';
        $cuenta['DUDOSO']++;
        continue;
    }
    if ($n === 1 && count($usoCuenta[$f['cands'][0]['username']]) === 1 && $f['uisp'] > 0
        && ($f['cands'][0]['por'] === 'servicio y sitio' || $colSitio === null)) {
        $f['conf'] = 'SEGURO';
    } elseif ($n === 0) {
        $f['conf'] = 'SIN CUENTA PPPoE';
    } else {
        $f['conf'] = 'DUDOSO';
        $f['nota'] = $n > 1 ? 'varias cuentas para este servicio'
            : (count($usoCuenta[$f['cands'][0]['username']]) > 1
                ? 'la cuenta también aparece en otro servicio (' . implode(', ', $usoCuenta[$f['cands'][0]['username']]) . ')'
                : 'coincide ' . $f['cands'][0]['por'] . ', no los dos');
    }
    $cuenta[$f['conf']]++;
}
unset($f);

if ($csv) {
    $out = fopen('php://output', 'w');
    fputcsv($out, ['confianza', 'id_servicio', 'cliente', 'estado', 'tecnologia', 'servicio_uisp', 'usuario_pppoe', 'ip', 'perfil', 'coincide_por', 'nota'], ',', '"', '');
    foreach ($filas as $f) {
        if ($f['cands'] === []) {
            fputcsv($out, [$f['conf'], $f['s']['id_servicio'], $f['s']['cliente'], $f['s']['estado'], $f['s']['tecnologia'], $f['uisp'], '', '', '', '', ''], ',', '"', '');
        }
        foreach ($f['cands'] as $c) {
            fputcsv($out, [$f['conf'], $f['s']['id_servicio'], $f['s']['cliente'], $f['s']['estado'], $f['s']['tecnologia'], $f['uisp'], $c['username'], $c['ip'], $c['perfil'], $c['por'], $f['nota'] ?? ''], ',', '"', '');
        }
    }
    exit;
}

echo 'SOLO LECTURA · propuesta de relación servicio -> cuenta PPPoE · ' . date('Y-m-d H:i') . "\n";
echo "Cruce por uisp_service_id" . ($colSitio ? " y $colSitio (sitio)" : '') . "\n";
echo 'Servicios en la plataforma: ' . count($servicios) . ' · ya relacionados: ' . count($conRelacion) . ' · sin relación: ' . count($filas) . "\n\n";
foreach ($cuenta as $k => $n) printf("  %-18s %4d\n", $k, $n);
echo "\nEjemplos SEGUROS:\n";
$i = 0;
foreach ($filas as $f) {
    if ($f['conf'] !== 'SEGURO') continue;
    printf("  servicio %-6s %-34s -> %-16s %s\n", $f['s']['id_servicio'], mb_substr($f['s']['cliente'], 0, 34), $f['cands'][0]['username'], $f['cands'][0]['ip']);
    if (++$i >= 10) break;
}
echo "\nLista completa: php relacion-pppoe.php --csv\n";
