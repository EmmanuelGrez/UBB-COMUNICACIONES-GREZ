<?php
/*
 * Alta DHCP en RADIUS SIN revisar el inventario de la plataforma (casos que autoriza el ingeniero).
 * Hace lo mismo que "Dar de alta" del panel (MAC + IP fija + plan 20M de la VLAN) y lo anota en la bitácora
 * como 'alta-sin-inventario' para registrar el equipo con Jamileth después.
 *
 *   php alta-dhcp-sin-inventario.php --vlan=1500 --mac=5C:E9:31:D6:09:F5             simula (no cambia nada)
 *   php alta-dhcp-sin-inventario.php --vlan=1500 --mac=5C:E9:31:D6:09:F5 --aplicar    da de alta
 *        opcional: --ip=192.168.7.50
 *   La MAC es la de Internet (WAN) que muestra el módem en Status, la que ve el router.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/radius-dhcp-acciones.php';

function adBD(): PDO
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
try {
    $vlans = rdVlans();
    $vlan = (string) ($opt['vlan'] ?? '');
    if (!isset($vlans[$vlan])) throw new RuntimeException('Indica --vlan= con una de: ' . implode(', ', array_keys($vlans)));
    $cfg = $vlans[$vlan];
    $mac = rdNormalizarMac((string) ($opt['mac'] ?? ''));
    $db = adBD();
    $st = $db->prepare('SELECT COUNT(*) FROM radcheck WHERE username = ?');
    $st->execute([$mac]);
    if ((int) $st->fetchColumn() > 0) throw new RuntimeException("La MAC $mac ya está dada de alta en RADIUS.");

    $st = $db->prepare("SELECT value FROM radreply WHERE attribute = 'Framed-IP-Address' AND value LIKE ?");
    $st->execute([$cfg['prefijo'] . '%']);
    $usadas = array_flip(array_map('trim', $st->fetchAll(PDO::FETCH_COLUMN)));
    if (isset($opt['ip'])) {
        $ip = (string) $opt['ip'];
        $oct = substr($ip, strlen($cfg['prefijo']));
        if (strpos($ip, $cfg['prefijo']) !== 0 || !ctype_digit($oct) || (int) $oct < $cfg['desde'] || (int) $oct > $cfg['hasta']) {
            throw new RuntimeException("La IP $ip no es de la VLAN $vlan ({$cfg['prefijo']}{$cfg['desde']} a {$cfg['prefijo']}{$cfg['hasta']}).");
        }
        if (isset($usadas[$ip])) throw new RuntimeException("La IP $ip ya está asignada en RADIUS.");
    } else {
        $ip = '';
        for ($i = $cfg['desde']; $i <= $cfg['hasta']; $i++) {
            if (!isset($usadas[$cfg['prefijo'] . $i])) { $ip = $cfg['prefijo'] . $i; break; }
        }
        if ($ip === '') throw new RuntimeException("No hay IP libre en la VLAN $vlan.");
    }
    $aplicar = isset($opt['aplicar']);
    echo ($aplicar ? '' : "SIMULACIÓN (no se cambió nada; agrega --aplicar)\n")
        . "  VLAN:   $vlan\n  MAC:    $mac\n  IP:     $ip\n  Plan:   {$cfg['lista']}\n"
        . "  Sin revisar el inventario de la plataforma.\n"
        . "  Antes de aplicar revisa en el router que la IP no la use nadie:\n"
        . "    /ip dhcp-server lease print where address=$ip\n";
    if (!$aplicar) exit(0);
    $db->beginTransaction();
    $db->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Accept')")->execute([$mac]);
    $ins = $db->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, ?, ':=', ?)");
    $ins->execute([$mac, 'Framed-IP-Address', $ip]);
    $ins->execute([$mac, 'Mikrotik-Address-List', $cfg['lista']]);
    $db->commit();
    rdBitacora($db, $mac, $ip, 'alta-sin-inventario', "VLAN $vlan, {$cfg['lista']}; falta registrarlo con la plataforma", 'PANEL');
    echo "Alta lista. Reinicia el módem o espera su renovación; debe tomar la IP $ip.\n"
        . "Pendiente: que Jamileth registre el equipo en su inventario.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'No se hizo: ' . $e->getMessage() . "\n");
    exit(1);
}
