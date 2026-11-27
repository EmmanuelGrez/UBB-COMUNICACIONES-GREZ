<?php
/*
 * SOLO LECTURA. Cruza los equipos suspendidos en RADIUS (lista CLIENTES-DEUDORES) con los servicios
 * de la plataforma, para explicar por qué la API /servicios reporta menos SUSPENDIDO que el panel.
 * La API trabaja por servicio: solo ve un equipo si está RELACIONADO a un servicio, y si el servicio
 * tiene un solo equipo vigente.
 *
 *   php suspendidos-vs-api.php          resumen + lista
 *   php suspendidos-vs-api.php --csv    lista para Excel / para Yomara
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$opc = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$host = trim((string) (getenv('DB_HOST') ?: ''));
$dsn = $host !== ''
    ? 'mysql:host=' . $host . ';port=' . ((int) (getenv('DB_PORT') ?: 3306)) . ';dbname=radius;charset=utf8mb4'
    : 'mysql:unix_socket=' . (getenv('DB_SOCKET') ?: '/var/run/mysqld/mysqld.sock') . ';dbname=radius;charset=utf8mb4';
$radius = new PDO($dsn, (string) getenv('DB_USER'), (string) getenv('DB_PASS'), $opc);
$jam = new PDO('mysql:host=192.168.100.221;port=3306;dbname=ubb_plataforma;charset=utf8mb4', 'equipo_ubb',
    rtrim((string) file_get_contents('/etc/ubb/jamileth.pass'), "\r\n"), $opc);

function svMac(string $m): string
{
    $h = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $m));
    return strlen($h) === 12 ? implode(':', str_split($h, 2)) : strtoupper(trim($m));
}

/* Suspendidos en RADIUS */
$susp = [];
foreach ($radius->query("SELECT r.username, MAX(CASE WHEN r.attribute = 'Framed-IP-Address' THEN r.value END) AS ip
                         FROM radreply r
                         WHERE r.username IN (SELECT username FROM radreply WHERE attribute = 'Mikrotik-Address-List' AND value = 'CLIENTES-DEUDORES')
                         GROUP BY r.username") as $f) {
    $u = (string) $f['username'];
    $esMac = (bool) preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/i', $u);
    $susp[] = ['usuario' => $u, 'tipo' => $esMac ? 'DHCP' : 'PPPOE', 'ip' => (string) $f['ip'], 'origen' => ''];
}
$orig = $radius->prepare('SELECT origen FROM ubb_suspensiones WHERE username = ?');
foreach ($susp as &$s) {
    $orig->execute([$s['usuario']]);
    $s['origen'] = strtoupper((string) ($orig->fetchColumn() ?: ''));
}
unset($s);

/* Plataforma */
$filasPorMac = [];
$filasPorCuenta = [];
foreach ($jam->query("SELECT w.id_servicio, w.id_cliente, w.tipo_fuente, w.mac, w.pppoe_name, w.estado_vinculacion,
                             s.estado AS estado_servicio, s.id_cliente AS cliente_servicio,
                             CONCAT_WS(' ', c.nombre, c.apellido_paterno, c.apellido_materno) AS cliente
                      FROM integracion_winbox w
                      LEFT JOIN servicios s ON s.id_servicio = w.id_servicio
                      LEFT JOIN clientes c ON c.id_cliente = s.id_cliente") as $f) {
    if (trim((string) $f['mac']) !== '' && strtoupper((string) $f['tipo_fuente']) !== 'PPPOE') $filasPorMac[svMac((string) $f['mac'])][] = $f;
    if (trim((string) $f['pppoe_name']) !== '') $filasPorCuenta[strtolower(trim((string) $f['pppoe_name']))][] = $f;
}
/* Cuántos equipos vigentes tiene cada servicio (para la regla de la API) */
$vigentes = [];
foreach ($jam->query("SELECT id_servicio, tipo_fuente, mac, pppoe_name FROM integracion_winbox
                      WHERE UPPER(estado_vinculacion) LIKE 'RELACIONADO%' AND id_servicio IS NOT NULL") as $f) {
    $id = (string) $f['id_servicio'];
    if (strtoupper((string) $f['tipo_fuente']) === 'DHCP' && trim((string) $f['mac']) !== '') $vigentes[$id]['dhcp'][svMac((string) $f['mac'])] = true;
    if (trim((string) $f['pppoe_name']) !== '') $vigentes[$id]['pppoe'][strtolower(trim((string) $f['pppoe_name']))] = true;
}

$lista = [];
foreach ($susp as $s) {
    $filas = $s['tipo'] === 'DHCP' ? ($filasPorMac[svMac($s['usuario'])] ?? []) : ($filasPorCuenta[strtolower($s['usuario'])] ?? []);
    $rel = null;
    foreach ($filas as $f) {
        if (stripos((string) $f['estado_vinculacion'], 'RELACIONADO') === 0 && (string) $f['cliente_servicio'] === (string) $f['id_cliente']) { $rel = $f; break; }
    }
    if ($rel) {
        $id = (string) $rel['id_servicio'];
        $nDhcp = count($vigentes[$id]['dhcp'] ?? []);
        $nPp = count($vigentes[$id]['pppoe'] ?? []);
        if ($nDhcp > 0 && $nPp > 0) $api = 'SIN_RELACION (el servicio tiene DHCP y PPPoE)';
        elseif ($nDhcp + $nPp > 1) $api = 'SIN_RELACION (el servicio tiene varios equipos)';
        else $api = 'SUSPENDIDO';
        $grupo = $api === 'SUSPENDIDO' ? '1 La API lo ve SUSPENDIDO' : '2 Ligado, pero la API no decide';
        $servicio = $id;
        $cliente = (string) $rel['cliente'];
        $estServ = (string) $rel['estado_servicio'];
        $plat = 'RELACIONADO';
    } else {
        $grupo = '3 Sin servicio en la plataforma';
        $servicio = '';
        $cliente = '';
        $estServ = '';
        $api = 'no sale (no está ligado a ningún servicio)';
        $plat = $filas ? strtoupper((string) $filas[0]['estado_vinculacion'])
            . (trim((string) $filas[0]['id_servicio']) !== '' ? ' (servicio ' . $filas[0]['id_servicio'] . ')' : ' sin servicio')
            : 'no está en la plataforma';
    }
    $lista[] = ['grupo' => $grupo, 'tipo' => $s['tipo'], 'equipo' => $s['usuario'], 'ip' => $s['ip'],
        'suspendido_por' => $s['origen'] ?: '?', 'plataforma' => $plat, 'id_servicio' => $servicio,
        'cliente' => $cliente, 'estado_servicio' => $estServ, 'api' => $api];
}
usort($lista, fn ($a, $b) => [$a['grupo'], $a['tipo'], (int) $a['id_servicio'], $a['equipo']] <=> [$b['grupo'], $b['tipo'], (int) $b['id_servicio'], $b['equipo']]);

if (in_array('--csv', $argv, true)) {
    $out = fopen('php://stdout', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Grupo', 'Tipo', 'Equipo', 'IP', 'Suspendido por', 'En la plataforma', 'Servicio', 'Cliente', 'Estado del servicio', 'Qué dice la API']);
    foreach ($lista as $f) fputcsv($out, array_values($f));
    exit;
}

$cuenta = [];
foreach ($lista as $f) { $cuenta[$f['grupo']][$f['tipo']] = ($cuenta[$f['grupo']][$f['tipo']] ?? 0) + 1; }
$tot = ['DHCP' => 0, 'PPPOE' => 0];
foreach ($lista as $f) $tot[$f['tipo']]++;
echo "Suspendidos en RADIUS: DHCP {$tot['DHCP']} · PPPoE {$tot['PPPOE']}\n\n";
ksort($cuenta);
foreach ($cuenta as $g => $t) printf("  %-36s DHCP %3d · PPPoE %3d\n", substr($g, 2), $t['DHCP'] ?? 0, $t['PPPOE'] ?? 0);
$ids = array_values(array_unique(array_filter(array_column(array_filter($lista, fn ($f) => $f['grupo'][0] === '1'), 'id_servicio'))));
sort($ids, SORT_NUMERIC);
echo "\nServicios que la API ve SUSPENDIDO (" . count($ids) . "): " . implode(', ', $ids) . "\n";
$enBaja = array_filter($lista, fn ($f) => $f['estado_servicio'] !== '' && strtoupper($f['estado_servicio']) !== 'ACTIVO');
if ($enBaja) echo "De esos ligados, con el servicio en otro estado (BAJA, etc.): " . count($enBaja) . "\n";
echo "\n";
foreach ($lista as $f) {
    printf("%-32s %-5s %-18s %-14s %-6s %-30s %s\n", substr($f['grupo'], 2), $f['tipo'], $f['equipo'], $f['ip'], $f['suspendido_por'],
        $f['id_servicio'] !== '' ? "servicio {$f['id_servicio']} · {$f['cliente']}" : $f['plataforma'], $f['grupo'][0] === '2' ? $f['api'] : '');
}
