<?php
/*
 * SOLO LECTURA. Para cada servicio: qué equipos tiene en la plataforma (RELACIONADO / RETIRADO)
 * y qué dice RADIUS de cada uno (existe, IP, plan o suspendido, sesión abierta).
 *   php revisar-servicios.php 127 328 496
 *   php revisar-servicios.php --equipo=UBB-TP5F1F1        (en qué servicios aparece un equipo)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function rsBD(): PDO
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
$radius = rsBD();
$jam = new PDO('mysql:host=192.168.100.221;port=3306;dbname=ubb_plataforma;charset=utf8mb4', 'equipo_ubb',
    rtrim((string) file_get_contents('/etc/ubb/jamileth.pass'), "\r\n"),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

function rsRadius(PDO $r, string $usuario): string
{
    $st = $r->prepare("SELECT attribute, value FROM radreply WHERE username = ?");
    $st->execute([$usuario]);
    $at = [];
    foreach ($st as $f) $at[$f['attribute']] = $f['value'];
    $st = $r->prepare("SELECT COUNT(*) FROM radcheck WHERE username = ?");
    $st->execute([$usuario]);
    if ((int) $st->fetchColumn() === 0) return 'NO existe en RADIUS';
    $plan = $at['Mikrotik-Address-List'] ?? ($at['Mikrotik-Group'] ?? '-');
    $st = $r->prepare("SELECT COUNT(*) FROM ubb_suspensiones WHERE username = ?");
    $st->execute([$usuario]);
    $susp = (int) $st->fetchColumn() > 0;
    $st = $r->prepare("SELECT acctstarttime FROM radacct WHERE username = ? AND acctstoptime IS NULL ORDER BY acctstarttime DESC LIMIT 1");
    $st->execute([$usuario]);
    $ses = $st->fetchColumn();
    return 'IP ' . ($at['Framed-IP-Address'] ?? '-') . ' · ' . $plan . ($susp ? ' · SUSPENDIDO' : '')
        . ' · ' . ($ses ? "sesión desde $ses" : 'sin sesión');
}
function rsMostrar(PDO $jam, PDO $radius, array $filas): void
{
    foreach ($filas as $f) {
        $tipo = strtoupper((string) $f['tipo_fuente']);
        $usuario = $tipo === 'DHCP' ? strtoupper(trim((string) $f['mac'])) : trim((string) $f['pppoe_name']);
        printf("   %-12s %-5s %-18s %-16s -> %s\n", $f['estado_vinculacion'], $tipo, $usuario !== '' ? $usuario : '(vacío)',
            (string) ($f['hostname'] ?? ''), $usuario !== '' ? rsRadius($radius, $usuario) : '');
    }
}

$equipo = null;
foreach ($argv as $a) if (strpos($a, '--equipo=') === 0) $equipo = substr($a, 9);
$cols = 'w.id_servicio, w.tipo_fuente, w.mac, w.hostname, w.pppoe_name, w.estado_vinculacion';
if ($equipo !== null) {
    $st = $jam->prepare("SELECT $cols FROM integracion_winbox w
        WHERE UPPER(w.pppoe_name) = UPPER(?) OR UPPER(w.hostname) = UPPER(?) OR UPPER(w.mac) = UPPER(?) ORDER BY w.id_servicio");
    $st->execute([$equipo, $equipo, $equipo]);
    echo "Equipo $equipo en la plataforma:\n";
    foreach ($st->fetchAll() as $f) { echo "  servicio {$f['id_servicio']}\n"; rsMostrar($jam, $radius, [$f]); }
    exit;
}
$ids = array_values(array_filter(array_map('intval', array_slice($argv, 1))));
if (!$ids) exit("Uso: php revisar-servicios.php 127 328 …  |  --equipo=UBB-…\n");
$sv = $jam->prepare('SELECT id_servicio, estado, tecnologia, uisp_service_id FROM servicios WHERE id_servicio = ?');
$eq = $jam->prepare("SELECT $cols FROM integracion_winbox w WHERE w.id_servicio = ? ORDER BY w.estado_vinculacion");
echo "SOLO LECTURA · " . date('Y-m-d H:i') . "\n";
foreach ($ids as $id) {
    $sv->execute([$id]);
    $s = $sv->fetch();
    if (!$s) { echo "\nServicio $id: no existe en la plataforma\n"; continue; }
    $cliente = '';
    try {
        $c = $jam->prepare('SELECT CONCAT_WS(\' \', c.nombre, c.apellido_paterno, c.apellido_materno) FROM servicios s JOIN clientes c ON c.id_cliente = s.id_cliente WHERE s.id_servicio = ?');
        $c->execute([$id]);
        $cliente = (string) $c->fetchColumn();
    } catch (Throwable $e) {}
    echo "\nServicio $id" . ($cliente !== '' ? " · $cliente" : '') . " · {$s['estado']} · {$s['tecnologia']} · UISP {$s['uisp_service_id']}\n";
    $eq->execute([$id]);
    $filas = $eq->fetchAll();
    if (!$filas) { echo "   (sin equipos en la plataforma)\n"; continue; }
    rsMostrar($jam, $radius, $filas);
}
