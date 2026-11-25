<?php
/*
 * pppoe-lotes.php - Genera los comandos de RouterOS para migrar clientes PPPoE a RADIUS por lotes,
 * igual que se hizo con UBB-BOTIJASBEBE: desactivar la cuenta local (eso corta la sesión) y el módem
 * se reconecta solo por RADIUS. SOLO IMPRIME comandos; no cambia nada.
 *
 * Toma únicamente cuentas que:
 *   - existen en el router y están ACTIVAS (no migradas todavía),
 *   - existen en RADIUS con contraseña, IP fija y perfil,
 *   - tienen la MISMA IP y el MISMO perfil en el router y en RADIUS,
 *   - no son de prueba (PRUEBA-*).
 *
 * Uso (dentro del contenedor):
 *   php pppoe-lotes.php                 lote de 10
 *   php pppoe-lotes.php --tamano=20     lote de 20
 *   php pppoe-lotes.php --resumen       solo cuántas faltan
 *
 * El script que imprime, al pegarlo en la terminal del router:
 *   1) anota quién estaba conectado, 2) desactiva las cuentas del lote, 3) espera 90 s,
 *   4) a quien estaba conectado y no volvió, le reactiva su cuenta local (regreso automático),
 *   5) muestra OK / REGRESADO por cliente.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/uisp-puente.php';

use RouterOS\Query;

$tamano = 10;
foreach ($argv as $a) {
    if (preg_match('/^--tamano=(\d{1,3})$/', $a, $m)) {
        $tamano = max(1, min(100, (int) $m[1]));
    }
}
$soloResumen = in_array('--resumen', $argv, true);

function plBD(): PDO
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

$pdo = plBD();
$rad = [];
foreach ($pdo->query("SELECT c.username,
        (SELECT value FROM radreply r WHERE r.username = c.username AND r.attribute = 'Framed-IP-Address' LIMIT 1) AS ip,
        (SELECT value FROM radreply r WHERE r.username = c.username AND r.attribute = 'Mikrotik-Group' LIMIT 1) AS perfil
        FROM radcheck c WHERE c.attribute = 'Cleartext-Password' AND c.value <> ''") as $f) {
    $rad[strtolower($f['username'])] = $f;
}

$router = uispConectarRouter('10.0.1.6');
$respuesta = $router->query(new Query('/ppp/secret/print', ['=.proplist=name,remote-address,profile,disabled']))->read();

$listos = [];
$motivos = ['ya migradas' => 0, 'no están en RADIUS' => 0, 'IP o perfil distinto' => 0, 'de prueba' => 0];
foreach ($respuesta as $s) {
    if (!is_array($s) || !isset($s['name'])) {
        continue;
    }
    $u = (string) $s['name'];
    if (stripos($u, 'PRUEBA') === 0) {
        $motivos['de prueba']++;
        continue;
    }
    if (in_array(strtolower((string) ($s['disabled'] ?? 'false')), ['true', 'yes'], true)) {
        $motivos['ya migradas']++;
        continue;
    }
    $r = $rad[strtolower($u)] ?? null;
    if ($r === null || empty($r['ip']) || empty($r['perfil'])) {
        $motivos['no están en RADIUS']++;
        continue;
    }
    if ((string) ($s['remote-address'] ?? '') !== $r['ip'] || (string) ($s['profile'] ?? '') !== $r['perfil']) {
        $motivos['IP o perfil distinto']++;
        continue;
    }
    if (!preg_match('/^[A-Za-z0-9._@-]+$/', $u)) {
        $motivos['no están en RADIUS']++; // nombre con caracteres raros: mejor a mano
        continue;
    }
    $listos[] = ['u' => $u, 'ip' => $r['ip']];
}
usort($listos, fn ($a, $b) => ip2long($a['ip']) <=> ip2long($b['ip']));

$out = "# Cuentas listas para migrar: " . count($listos) . "\n";
foreach ($motivos as $k => $n) {
    $out .= "# No se incluyen ($k): $n\n";
}
if ($soloResumen || $listos === []) {
    echo $out;
    exit;
}

$lote = array_slice($listos, 0, $tamano);
$nombres = implode(';', array_map(fn ($c) => '"' . $c['u'] . '"', $lote));
$out .= "# Lote de " . count($lote) . " (" . date('Y-m-d H:i') . "): " . implode(', ', array_column($lote, 'u')) . "\n";
$out .= "# 1) Cuenta antes:  /ppp active print count-only ; /queue simple print count-only\n";
$out .= "# 2) Pega TODO el bloque de abajo en la terminal del router. Tarda ~90 segundos.\n\n";
$ros = <<<'ROS'
{
:local lote {__NOMBRES__}
:local antes [:toarray ""]
:foreach u in=$lote do={
  :if ([:len [/ppp active find where name=$u]] > 0) do={
    :set antes ($antes, $u)
  } else={
    :put ("DESCONECTADO " . $u . " (no estaba conectado; entrara por RADIUS cuando se conecte)")
  }
  /ppp secret disable [find where name=$u]
}
:put "Desactivadas. Esperando 90 s a que se reconecten por RADIUS..."
:delay 90s
:foreach u in=$antes do={
  :local a [/ppp active find where name=$u]
  :if ([:len $a] = 0) do={
    /ppp secret enable [find where name=$u]
    :log warning ("MIGRACION RADIUS: " . $u . " no volvio, se regreso a su cuenta local")
    :put ("REGRESADO  " . $u . " (no volvio; ya tiene otra vez su cuenta local)")
  } else={
    :put ("OK         " . $u . "  " . [/ppp active get ($a->0) address] . "  radius=" . [/ppp active get ($a->0) radius])
  }
}
:put "Listo."
}

ROS;
$out .= str_replace('__NOMBRES__', $nombres, $ros);
$out .= "\n# 3) Cuenta despues (debe quedar igual o casi): /ppp active print count-only ; /queue simple print count-only\n";
$out .= "# 4) Si quieres regresar TODO este lote a sus cuentas locales:\n";
$out .= "#    :foreach u in={" . $nombres . "} do={ /ppp secret enable [find where name=\$u] }\n";
echo $out;
