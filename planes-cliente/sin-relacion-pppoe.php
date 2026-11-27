<?php
/*
 * SOLO LECTURA. Lista las cuentas PPPoE de RADIUS que en el panel salen "— sin relación —"
 * (en la plataforma de Jamileth no están RELACIONADO a un servicio) y dice por qué.
 *
 *   php sin-relacion-pppoe.php          resumen + lista en pantalla
 *   php sin-relacion-pppoe.php --csv    lista para Excel / para Jamileth
 *   --router=10.0.1.6   (por defecto) solo cuentas de ese router; --router=todos para ver todas
 *
 * No escribe nada ni en RADIUS ni en la plataforma.
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

function srHex(string $mac): string { return strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac)); }
function srMac(string $mac): string { $h = srHex($mac); return strlen($h) === 12 ? implode(':', str_split($h, 2)) : $h; }
/* ¿La MAC cuadra con el nombre? El nombre lleva los últimos hex de la etiqueta; la MAC real puede ser etiqueta +0..+8. */
function srCuadra(string $nombre, string $mac): ?bool
{
    $h = srHex($mac);
    if (strlen($h) !== 12 || !preg_match('/([0-9A-F]{4,5})$/i', $nombre, $m)) return null;
    $n = strlen($m[1]);
    $deNombre = hexdec($m[1]);
    $deMac = hexdec(substr($h, -$n));
    $dif = ($deMac - $deNombre + (16 ** $n)) % (16 ** $n);
    return $dif <= 8;
}

/* RADIUS: cuentas PPPoE (las que tienen contraseña), plan, suspendidas, sesión abierta y MAC */
$cuentas = [];
foreach ($radius->query("SELECT username FROM radcheck WHERE attribute = 'Cleartext-Password'") as $f) {
    $cuentas[strtolower($f['username'])] = ['usuario' => $f['username'], 'plan' => '', 'ip' => '', 'suspendido' => false, 'sesion' => '', 'mac' => ''];
}
foreach ($radius->query("SELECT username, attribute, value FROM radreply WHERE attribute IN ('Mikrotik-Group','Framed-IP-Address')") as $f) {
    $k = strtolower($f['username']);
    if (!isset($cuentas[$k])) continue;
    if ($f['attribute'] === 'Mikrotik-Group') $cuentas[$k]['plan'] = $f['value'];
    else $cuentas[$k]['ip'] = $f['value'];
}
foreach ($radius->query("SELECT username FROM ubb_suspensiones") as $f) {
    if (isset($cuentas[strtolower($f['username'])])) $cuentas[strtolower($f['username'])]['suspendido'] = true;
}
foreach ($radius->query("SELECT username, callingstationid, acctstarttime FROM radacct WHERE acctstoptime IS NULL ORDER BY acctstarttime") as $f) {
    $k = strtolower($f['username']);
    if (!isset($cuentas[$k])) continue;
    $cuentas[$k]['sesion'] = (string) $f['acctstarttime'];
    $cuentas[$k]['mac'] = srMac((string) $f['callingstationid']);
}
/* Solo las cuentas del router elegido: por el último router donde se conectó (radacct.nasipaddress);
   si nunca se ha conectado, por su IP (las de FSL02 usan 10.2.0.0/22). */
$router = '10.0.1.6';
foreach ($argv as $a) if (strpos($a, '--router=') === 0) $router = substr($a, 9);
if ($router !== 'todos') {
    $ultimoNas = [];
    foreach ($radius->query("SELECT a.username, a.nasipaddress FROM radacct a
                             JOIN (SELECT username, MAX(radacctid) AS id FROM radacct GROUP BY username) u ON u.id = a.radacctid") as $f) {
        $ultimoNas[strtolower($f['username'])] = (string) $f['nasipaddress'];
    }
    $porIp = ($router === '10.0.1.6') ? '/^10\.2\.[0-3]\./' : null;
    foreach ($cuentas as $k => $c) {
        $nas = $ultimoNas[$k] ?? '';
        $esDelRouter = $nas !== '' ? $nas === $router : ($porIp !== null && preg_match($porIp, $c['ip']));
        if (!$esDelRouter) unset($cuentas[$k]);
    }
}
$cuentaDeMac = [];
foreach ($cuentas as $k => $c) if ($c['mac'] !== '') $cuentaDeMac[$c['mac']][] = $c['usuario'];

/* Plataforma: todas las filas que mencionan una cuenta PPPoE */
$filas = [];
foreach ($jam->query("SELECT id_servicio, id_cliente, tipo_fuente, mac, pppoe_name, estado_vinculacion
                      FROM integracion_winbox WHERE pppoe_name IS NOT NULL AND TRIM(pppoe_name) <> ''") as $f) {
    $filas[strtolower(trim($f['pppoe_name']))][] = $f;
}
$serviciosValidos = [];
foreach ($jam->query("SELECT s.id_servicio, CONCAT_WS(' ', c.nombre, c.apellido_paterno, c.apellido_materno) AS nombre, s.id_cliente
                      FROM servicios s JOIN clientes c ON c.id_cliente = s.id_cliente") as $f) {
    $serviciosValidos[(string) $f['id_servicio']] = $f;
}

$lista = [];
foreach ($cuentas as $k => $c) {
    if (stripos($c['usuario'], 'PRUEBA-') === 0) continue;
    $rows = $filas[$k] ?? [];
    $buena = false;
    foreach ($rows as $r) {
        $sv = $serviciosValidos[(string) $r['id_servicio']] ?? null;
        if (stripos((string) $r['estado_vinculacion'], 'RELACIONADO') === 0 && $sv && (string) $sv['id_cliente'] === (string) $r['id_cliente']) {
            $buena = true;
        }
    }
    if ($buena) continue;

    // Motivo
    if (!$rows) {
        $motivo = 'No está en la plataforma';
    } else {
        $r = $rows[0];
        $est = strtoupper((string) $r['estado_vinculacion']);
        $sid = trim((string) $r['id_servicio']);
        if ($sid === '' || $sid === '0') $motivo = "$est sin servicio";
        elseif (!isset($serviciosValidos[$sid])) $motivo = "$est, el servicio $sid no existe";
        elseif ((string) $serviciosValidos[$sid]['id_cliente'] !== (string) $r['id_cliente']) $motivo = "$est, servicio $sid de otro cliente";
        else $motivo = "$est (servicio $sid)";
    }
    // Detalles de MAC
    $macPlat = $rows ? srMac((string) $rows[0]['mac']) : '';
    $notas = [];
    if ($c['mac'] !== '' && $macPlat !== '' && $macPlat !== $c['mac']) $notas[] = "plataforma tiene otra MAC ($macPlat)";
    if ($macPlat !== '' && srCuadra($c['usuario'], $macPlat) === false) $notas[] = 'la MAC de la plataforma no cuadra con el nombre';
    if ($c['mac'] !== '') {
        foreach ($jam->query("SELECT pppoe_name, hostname FROM integracion_winbox WHERE UPPER(REPLACE(REPLACE(mac,'-',':'),'.','')) = " . $jam->quote($c['mac'])) as $o) {
            $otro = trim((string) ($o['pppoe_name'] ?: $o['hostname']));
            if ($otro !== '' && strtolower($otro) !== $k) $notas[] = "su MAC está registrada en $otro";
        }
    }
    $conectado = $c['sesion'] !== '';
    $grupo = $conectado ? ($c['suspendido'] ? 'B conectado y suspendido' : 'A conectado con internet') : 'C sin conexión';
    $lista[] = [
        'grupo' => $grupo, 'usuario' => $c['usuario'], 'ip' => $c['ip'],
        'plan' => $c['suspendido'] ? 'SUSPENDIDO' : $c['plan'], 'mac_conectada' => $c['mac'],
        'sesion_desde' => $c['sesion'], 'plataforma' => $motivo, 'notas' => implode('; ', array_unique($notas)),
    ];
}
usort($lista, fn ($a, $b) => [$a['grupo'], $a['usuario']] <=> [$b['grupo'], $b['usuario']]);

if (in_array('--csv', $argv, true)) {
    $out = fopen('php://stdout', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Grupo', 'Usuario PPPoE', 'IP', 'Plan', 'MAC conectada', 'Sesión desde', 'En la plataforma', 'Notas']);
    foreach ($lista as $f) fputcsv($out, array_values($f));
    exit;
}

$porGrupo = [];
$porMotivo = [];
foreach ($lista as $f) {
    $porGrupo[$f['grupo']] = ($porGrupo[$f['grupo']] ?? 0) + 1;
    $m = preg_replace('/ \(servicio \d+\)| \d+ /', ' N ', $f['plataforma']);
    $porMotivo[$m] = ($porMotivo[$m] ?? 0) + 1;
}
echo "Router: " . ($router === 'todos' ? 'todos' : $router) . "   ·   cuentas PPPoE: " . count($cuentas) . "   ·   salen \"sin relación\": " . count($lista) . "\n\n";
ksort($porGrupo);
foreach ($porGrupo as $g => $n) printf("  %-28s %4d\n", $g, $n);
echo "\nPor qué (en la plataforma):\n";
arsort($porMotivo);
foreach ($porMotivo as $m => $n) printf("  %-40s %4d\n", $m, $n);
$conNotas = count(array_filter($lista, fn ($f) => $f['notas'] !== ''));
echo "\nCon MAC cruzada o que no cuadra: $conNotas\n\n";
foreach ($lista as $f) {
    printf("%-26s %-16s %-11s %-16s %s%s\n", substr($f['grupo'], 2), $f['usuario'], $f['ip'], $f['plan'],
        $f['plataforma'], $f['notas'] !== '' ? '  ⚠ ' . $f['notas'] : '');
}
