<?php
/*
 * Cuentas PPPoE DE PRUEBA en RADIUS, sin pasar por el inventario (solo para pruebas del ingeniero).
 * El usuario siempre empieza con PRUEBA- para que el panel, los reportes y la API las traten como prueba.
 *
 *   php alta-prueba-pppoe.php                                  lista las cuentas PRUEBA que hay
 *   php alta-prueba-pppoe.php --usuario=PRUEBA-1500            simula el alta (no cambia nada)
 *   php alta-prueba-pppoe.php --usuario=PRUEBA-1500 --aplicar  la da de alta (perfil pro-pppoe18-20M, IP libre)
 *        opcional: --perfil=pro-pppoe18-30M  --ip=10.2.3.250
 *   php alta-prueba-pppoe.php --baja=PRUEBA-1500 [--aplicar]   la borra de RADIUS
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function apBD(): PDO
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
$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) $opt[$m[1]] = $m[2] ?? true;
}
$aplicar = isset($opt['aplicar']);
$db = apBD();

$lista = function () use ($db) {
    $st = $db->query("SELECT c.username, ip.value AS ip, g.value AS perfil FROM radcheck c
        LEFT JOIN radreply ip ON ip.username = c.username AND ip.attribute = 'Framed-IP-Address'
        LEFT JOIN radreply g ON g.username = c.username AND g.attribute = 'Mikrotik-Group'
        WHERE c.attribute = 'Cleartext-Password' AND c.username LIKE 'PRUEBA%' ORDER BY c.username");
    echo "Cuentas de prueba en RADIUS:\n";
    foreach ($st as $r) printf("  %-18s %-14s %s\n", $r['username'], $r['ip'] ?? '-', $r['perfil'] ?? '-');
};

if (isset($opt['baja'])) {
    $u = (string) $opt['baja'];
    if (stripos($u, 'PRUEBA-') !== 0) exit("Solo se pueden borrar cuentas que empiezan con PRUEBA-.\n");
    if (!$aplicar) { echo "SIMULACIÓN: se borraría $u de radcheck y radreply. Agrega --aplicar.\n"; exit; }
    $db->prepare('DELETE FROM radcheck WHERE username = ?')->execute([$u]);
    $db->prepare('DELETE FROM radreply WHERE username = ?')->execute([$u]);
    $db->prepare("INSERT INTO ubb_bitacora (mac, ip, accion, detalle, origen) VALUES ('', '', 'baja-prueba', ?, 'PANEL')")->execute(["usuario $u"]);
    echo "Borrada $u. Si estaba conectada, desconéctala en el router: /ppp active remove [find name=\"$u\"]\n";
    exit;
}
if (!isset($opt['usuario'])) { $lista(); exit; }

$u = (string) $opt['usuario'];
if (!preg_match('/^PRUEBA-[A-Za-z0-9._-]{1,40}$/', $u)) exit("El usuario debe empezar con PRUEBA- (ej. PRUEBA-1500).\n");
$perfil = (string) ($opt['perfil'] ?? 'pro-pppoe18-20M');
if (!preg_match('/^pro-pppoe18-(20|30|60)M$/', $perfil)) exit("Perfil no válido: usa pro-pppoe18-20M, 30M o 60M.\n");
$st = $db->prepare('SELECT COUNT(*) FROM radcheck WHERE username = ?');
$st->execute([$u]);
if ((int) $st->fetchColumn() > 0) exit("$u ya existe en RADIUS.\n");

$usadas = [];
foreach ($db->query("SELECT value FROM radreply WHERE attribute = 'Framed-IP-Address' AND value LIKE '10.2.%'") as $r) $usadas[trim($r['value'])] = true;
foreach ($db->query("SELECT DISTINCT framedipaddress FROM radacct WHERE acctstoptime IS NULL AND framedipaddress LIKE '10.2.%'") as $r) $usadas[trim($r['framedipaddress'])] = true;
if (isset($opt['ip'])) {
    $ip = (string) $opt['ip'];
    if (!filter_var($ip, FILTER_VALIDATE_IP) || strpos($ip, '10.2.') !== 0) exit("IP no válida (debe ser 10.2.x.x).\n");
    if (isset($usadas[$ip])) exit("La IP $ip ya está en uso.\n");
} else {
    // Desde el final del bloque hacia atrás, lejos de las altas normales.
    $ip = '';
    for ($n = ip2long('10.2.3.254'); $n > ip2long('10.2.3.200'); $n--) {
        if (!isset($usadas[long2ip($n)])) { $ip = long2ip($n); break; }
    }
    if ($ip === '') exit("No encontré IP libre entre 10.2.3.201 y 10.2.3.254; indica una con --ip=.\n");
}
$pass = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 12);

echo ($aplicar ? '' : "SIMULACIÓN (no se cambió nada; agrega --aplicar)\n")
   . "  Usuario:   $u\n  IP fija:   $ip\n  Perfil:    $perfil\n"
   . "  Antes de aplicar revisa en el router que la IP esté libre:\n"
   . "    /ppp secret print where remote-address=$ip\n    /ppp active print where address=$ip\n";
if (!$aplicar) exit;

$db->beginTransaction();
$db->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)")->execute([$u, $pass]);
$ins = $db->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, ?, ':=', ?)");
$ins->execute([$u, 'Framed-IP-Address', $ip]);
$ins->execute([$u, 'Mikrotik-Group', $perfil]);
$db->commit();
$db->prepare("INSERT INTO ubb_bitacora (mac, ip, accion, detalle, origen) VALUES ('', ?, 'alta-prueba', ?, 'PANEL')")->execute([$ip, "usuario $u, perfil $perfil"]);
echo "  Contraseña: $pass   (anótala para el módem)\nListo: se conecta en cuanto el módem tenga ese usuario y contraseña.\n";
