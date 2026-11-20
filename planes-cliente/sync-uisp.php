<?php
/*
 * sync-uisp.php - Copia las suspensiones de UISP hacia RADIUS.
 *
 * UISP manda: mete o saca IPs de la lista BLOCKED_USERS del MikroTik.
 * Este script, para cada cliente que ya esta en RADIUS:
 *   - si su IP esta en BLOCKED_USERS y no esta suspendido  -> lo suspende (origen UISP)
 *   - si su IP ya NO esta en BLOCKED_USERS y fue suspendido por UISP -> lo reactiva
 * Las suspensiones hechas a mano desde el panel (origen PANEL) no se tocan.
 *
 * Uso (dentro del contenedor):  php sync-uisp.php            aplica los cambios
 *                               php sync-uisp.php --simular  solo muestra lo que haria
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use RouterOS\Client;
use RouterOS\Query;

date_default_timezone_set('America/Mexico_City');

const LISTA_DEUDORES = 'CLIENTES-DEUDORES';
const LISTA_UISP = 'BLOCKED_USERS';
const MAX_REACTIVAR = 25; // freno: si UISP libera mas que esto de golpe, no se reactiva a nadie (posible error)

$simular = in_array('--simular', $argv ?? [], true);

function salida(string $msg): void
{
    echo date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL;
}

function conectarBD(): PDO
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

function conectarRouter(): Client
{
    $pass = trim((string) (getenv('MIKROTIK_PASS') ?: ''));
    if ($pass === '') {
        throw new RuntimeException('Falta MIKROTIK_PASS en el contenedor.');
    }
    return new Client([
        'host' => getenv('MIKROTIK_HOST') ?: '10.0.1.6',
        'user' => getenv('MIKROTIK_USER') ?: 'api-ubb',
        'pass' => $pass,
        'port' => (int) (getenv('MIKROTIK_PORT') ?: 8728),
        'timeout' => 10,
    ]);
}

function ros(Client $router, Query $query): array
{
    $r = $router->query($query)->read();
    if (isset($r['after']['message'])) {
        throw new RuntimeException('MikroTik: ' . $r['after']['message']);
    }
    return $r;
}

function idsDeudor(Client $router, string $ip): array
{
    $filas = ros($router, (new Query('/ip/firewall/address-list/print'))
        ->where('list', LISTA_DEUDORES)->where('address', $ip));
    return array_values(array_filter(array_map(fn ($f) => (string) ($f['.id'] ?? ''), $filas)));
}

function quitarConcesion(Client $router, string $mac): void
{
    foreach (ros($router, (new Query('/ip/dhcp-server/lease/print'))->where('mac-address', $mac)) as $f) {
        if (!empty($f['.id'])) {
            ros($router, (new Query('/ip/dhcp-server/lease/remove'))->equal('.id', $f['.id']));
        }
    }
}

try {
    $pdo = conectarBD();
    $router = conectarRouter();

    // 1. IPs bloqueadas por UISP
    $bloqueadas = [];
    foreach (ros($router, (new Query('/ip/firewall/address-list/print'))->where('list', LISTA_UISP)) as $f) {
        if (!empty($f['address'])) {
            $bloqueadas[(string) $f['address']] = true;
        }
    }
    if ($bloqueadas === []) {
        // Si UISP o el router devolvieran la lista vacia por un error, no reactivamos a todos.
        salida('AVISO: BLOCKED_USERS vino vacia; por seguridad no se hace nada.');
        exit(0);
    }

    // 2. Clientes en RADIUS y sus suspensiones
    $clientes = $pdo->query("
        SELECT ip.username AS mac, ip.value AS ip, li.value AS lista
        FROM radreply ip
        JOIN radreply li ON li.username = ip.username AND li.attribute = 'Mikrotik-Address-List'
        WHERE ip.attribute = 'Framed-IP-Address'
    ")->fetchAll();
    $suspensiones = [];
    foreach ($pdo->query("SELECT username, origen FROM ubb_suspensiones") as $s) {
        $suspensiones[$s['username']] = (string) $s['origen'];
    }

    // 3. Decidir cambios
    $suspender = [];
    $reactivar = [];
    foreach ($clientes as $c) {
        $bloqueado = isset($bloqueadas[$c['ip']]);
        $suspendido = $c['lista'] === LISTA_DEUDORES;
        if ($bloqueado && !$suspendido) {
            $suspender[] = $c;
        } elseif (!$bloqueado && $suspendido && ($suspensiones[$c['mac']] ?? '') === 'UISP') {
            $reactivar[] = $c;
        }
    }

    $total = count($suspender) + count($reactivar);
    if ($total === 0) {
        exit(0);   // nada que hacer: no ensucia la bitacora
    }
    if (count($reactivar) > MAX_REACTIVAR) {
        salida('AVISO: ' . count($reactivar) . ' reactivaciones de golpe (limite ' . MAX_REACTIVAR . '); no se reactiva a nadie. Revisar a mano.');
        $reactivar = [];
    }

    foreach ($suspender as $c) {
        salida(($simular ? '[SIMULADO] ' : '') . "SUSPENDER {$c['mac']} {$c['ip']} (estaba {$c['lista']})");
        if ($simular) continue;
        if (idsDeudor($router, $c['ip']) === []) {
            ros($router, (new Query('/ip/firewall/address-list/add'))
                ->equal('list', LISTA_DEUDORES)->equal('address', $c['ip'])
                ->equal('comment', 'SUSPENDIDO ' . $c['mac'] . ' (UISP)'));
        }
        $pdo->beginTransaction();
        $pdo->prepare("REPLACE INTO ubb_suspensiones (username, lista_original, ip, origen) VALUES (?, ?, ?, 'UISP')")
            ->execute([$c['mac'], $c['lista'], $c['ip']]);
        $pdo->prepare("UPDATE radreply SET value = ? WHERE username = ? AND attribute = 'Mikrotik-Address-List'")
            ->execute([LISTA_DEUDORES, $c['mac']]);
        $pdo->commit();
        quitarConcesion($router, $c['mac']);
    }

    foreach ($reactivar as $c) {
        $st = $pdo->prepare("SELECT lista_original FROM ubb_suspensiones WHERE username = ?");
        $st->execute([$c['mac']]);
        $original = (string) $st->fetchColumn();
        salida(($simular ? '[SIMULADO] ' : '') . "REACTIVAR {$c['mac']} {$c['ip']} (vuelve a $original)");
        if ($simular || $original === '') continue;
        $pdo->prepare("UPDATE radreply SET value = ? WHERE username = ? AND attribute = 'Mikrotik-Address-List'")
            ->execute([$original, $c['mac']]);
        foreach (idsDeudor($router, $c['ip']) as $id) {
            try {
                ros($router, (new Query('/ip/firewall/address-list/remove'))->equal('.id', $id));
            } catch (Throwable $e) {
                // las dinamicas se van al borrar la concesion
            }
        }
        quitarConcesion($router, $c['mac']);
        $pdo->prepare("DELETE FROM ubb_suspensiones WHERE username = ?")->execute([$c['mac']]);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    salida('ERROR: ' . $e->getMessage());
    exit(1);
}
