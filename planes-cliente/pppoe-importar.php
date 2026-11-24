<?php
/*
 * pppoe-importar.php - Fase 1 de la migración PPPoE: copia las cuentas PPPoE del router a RADIUS.
 *
 * Es seguro hacerlo con todos a la vez: el router siempre busca primero su cuenta local
 * (/ppp secret) y solo pregunta a RADIUS si no la encuentra. Mientras la cuenta local
 * siga encendida, cargarla en RADIUS no cambia nada para el cliente.
 *
 * Entrada: un export del router CON contraseñas:
 *     /ppp secret export show-sensitive file=pppoe-secrets
 * El archivo tiene las contraseñas de los clientes: va directo al servidor, fuera de la
 * carpeta del proyecto (que es la página web), y se borra al terminar.
 *
 * Uso (dentro del contenedor):
 *   php pppoe-importar.php /tmp/pppoe-secrets.rsc              SIMULA: revisa todo y muestra qué haría
 *   php pppoe-importar.php /tmp/pppoe-secrets.rsc --aplicar    escribe en RADIUS
 *   php pppoe-importar.php --deshacer --aplicar                borra de RADIUS lo que cargó este script
 *
 * Por cuenta escribe en radius:
 *   radcheck  Cleartext-Password := <contraseña>
 *   radreply  Framed-IP-Address  := <remote-address>
 *   radreply  Mikrotik-Group     := <profile>
 * y anota la cuenta en ubb_pppoe_importados (para poder deshacer).
 * Se puede correr cada día para sincronizar: carga las cuentas nuevas y actualiza (contraseña, IP, perfil)
 * las que este mismo script cargó antes. Nunca toca otras cuentas que ya existan en RADIUS
 * (p. ej. las de pruebas o las del router 10.0.2.230).
 * Nunca muestra contraseñas.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('America/Mexico_City');

const PERFILES_PERMITIDOS = ['pro-pppoe18-20M', 'pro-pppoe18-30M', 'pro-pppoe18-60M', 'pro-pppoe19-80m', 'pro-pppoe19-basico'];
const RED_PPPOE = '10.2.0.0/22';
const IP_GATEWAY = '10.2.0.1';

$aplicar = in_array('--aplicar', $argv, true);
$deshacer = in_array('--deshacer', $argv, true);
$archivo = '';
foreach (array_slice($argv, 1) as $a) {
    if ($a !== '' && $a[0] !== '-') {
        $archivo = $a;
    }
}

function salida(string $msg): void
{
    echo $msg . PHP_EOL;
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

/** Quita los escapes de RouterOS de un valor: \" \\ \$ \? \_ (espacio) y \HH (hexadecimal). */
function desescapar(string $v): string
{
    if (strlen($v) >= 2 && $v[0] === '"' && substr($v, -1) === '"') {
        $v = substr($v, 1, -1);
    }
    return preg_replace_callback('/\\\\([0-9A-Fa-f]{2}|.)/s', function ($m) {
        if (strlen($m[1]) === 2) {
            return chr(hexdec($m[1]));
        }
        return $m[1] === '_' ? ' ' : $m[1];
    }, $v);
}

/** Lee las líneas "add ..." de la sección /ppp secret de un export de RouterOS. */
function leerSecrets(string $texto): array
{
    $texto = str_replace(["\r\n", "\r"], "\n", $texto);
    $texto = preg_replace('/\\\\\n\s*/', '', $texto);   // une las líneas partidas con "\"
    $cuentas = [];
    $enSeccion = false;
    foreach (explode("\n", $texto) as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] === '#') {
            continue;
        }
        if ($linea[0] === '/') {
            $enSeccion = $linea === '/ppp secret';
            continue;
        }
        if (!$enSeccion || strpos($linea, 'add ') !== 0) {
            continue;
        }
        preg_match_all('/([a-z-]+)=("(?:[^"\\\\]|\\\\.)*"|\S+)/', substr($linea, 4), $m, PREG_SET_ORDER);
        $c = [];
        foreach ($m as $par) {
            $c[$par[1]] = desescapar($par[2]);
        }
        $cuentas[] = $c;
    }
    return $cuentas;
}

function ipEnRed(string $ip, string $cidr): bool
{
    [$red, $bits] = explode('/', $cidr);
    $mascara = -1 << (32 - (int) $bits);
    return (ip2long($ip) & $mascara) === (ip2long($red) & $mascara);
}

try {
    $pdo = conectarBD();
    $tabla = count($pdo->query("SHOW TABLES LIKE 'ubb\\_pppoe\\_importados'")->fetchAll()) === 1;
    if (!$tabla) {
        salida('ERROR: falta la tabla radius.ubb_pppoe_importados. Créala con root (SQL en las instrucciones).');
        exit(1);
    }

    /* ---------- Deshacer ---------- */
    if ($deshacer) {
        $nombres = $pdo->query('SELECT username FROM ubb_pppoe_importados')->fetchAll(PDO::FETCH_COLUMN);
        salida('Cuentas cargadas por este script: ' . count($nombres));
        if (!$aplicar) {
            salida('[SIMULADO] Se borrarían de radcheck/radreply. Agrega --aplicar para hacerlo.');
            exit(0);
        }
        $pdo->beginTransaction();
        foreach ($nombres as $n) {
            $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Cleartext-Password'")->execute([$n]);
            $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute IN ('Framed-IP-Address', 'Mikrotik-Group')")->execute([$n]);
            $pdo->prepare('DELETE FROM ubb_pppoe_importados WHERE username = ?')->execute([$n]);
        }
        $pdo->commit();
        salida('Listo: ' . count($nombres) . ' cuentas borradas de RADIUS.');
        exit(0);
    }

    /* ---------- Revisar el export ---------- */
    if ($archivo === '' || !is_readable($archivo)) {
        salida('Uso: php pppoe-importar.php /tmp/pppoe-secrets.rsc [--aplicar]');
        exit(1);
    }
    $cuentas = leerSecrets((string) file_get_contents($archivo));
    salida('Archivo: ' . basename($archivo) . ' | cuentas en /ppp secret: ' . count($cuentas));
    if ($cuentas === []) {
        salida('ERROR: no encontré líneas "add" en la sección /ppp secret.');
        exit(1);
    }

    // Lo que ya existe en RADIUS
    $existentes = array_flip($pdo->query('SELECT DISTINCT username FROM radcheck')->fetchAll(PDO::FETCH_COLUMN));
    $ipsRadius = [];
    foreach ($pdo->query("SELECT username, value FROM radreply WHERE attribute = 'Framed-IP-Address'") as $f) {
        $ipsRadius[$f['value']] = $f['username'];
    }

    // Cuentas que cargó este script antes: si en el router cambió su plan, IP o contraseña, se actualizan.
    $importadas = array_flip($pdo->query('SELECT username FROM ubb_pppoe_importados')->fetchAll(PDO::FETCH_COLUMN));
    $actualRadius = [];
    foreach ($pdo->query("SELECT username, attribute, value FROM radcheck WHERE attribute = 'Cleartext-Password'
                          UNION ALL SELECT username, attribute, value FROM radreply WHERE attribute IN ('Framed-IP-Address', 'Mikrotik-Group')") as $f) {
        $actualRadius[$f['username']][$f['attribute']] = $f['value'];
    }

    $nombres = array_count_values(array_map(fn ($c) => (string) ($c['name'] ?? ''), $cuentas));
    $ips = array_count_values(array_map(fn ($c) => (string) ($c['remote-address'] ?? ''), $cuentas));

    $cargar = [];
    $actualizar = [];
    $problemas = [];
    $omitidas = [];
    foreach ($cuentas as $c) {
        $n = (string) ($c['name'] ?? '');
        $ip = (string) ($c['remote-address'] ?? '');
        $perfil = (string) ($c['profile'] ?? '');
        $pass = (string) ($c['password'] ?? '');
        $motivo = null;

        if ($n === '' || !preg_match('/^[A-Za-z0-9._@-]{1,64}$/', $n)) {
            $motivo = 'nombre vacío o con caracteres raros';
        } elseif ($nombres[$n] > 1) {
            $motivo = "nombre repetido {$nombres[$n]} veces en el router";
        } elseif (($c['disabled'] ?? 'no') === 'yes') {
            $omitidas[] = "$n (deshabilitada en el router)";
            continue;
        } elseif (isset($c['service']) && !in_array($c['service'], ['pppoe', 'any'], true)) {
            $motivo = 'service=' . $c['service'] . ' (no es pppoe)';
        } elseif ($pass === '') {
            $motivo = 'sin contraseña en el archivo (¿export sin show-sensitive?)';
        } elseif (!preg_match('/^[\x20-\x7E]{1,128}$/', $pass)) {
            $motivo = 'contraseña con caracteres no imprimibles';
        } elseif (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !ipEnRed($ip, RED_PPPOE) || $ip === IP_GATEWAY) {
            $motivo = "IP no válida o fuera de " . RED_PPPOE . ": '$ip'";
        } elseif ($ips[$ip] > 1) {
            $motivo = "IP $ip repetida {$ips[$ip]} veces en el router";
        } elseif (!in_array($perfil, PERFILES_PERMITIDOS, true)) {
            $motivo = "perfil '$perfil' no está en la lista permitida";
        } elseif (isset($existentes[$n]) && !isset($importadas[$n])) {
            $omitidas[] = "$n (ya existe en RADIUS y no la cargó este script: no se toca)";
            continue;
        } elseif (isset($ipsRadius[$ip]) && $ipsRadius[$ip] !== $n) {
            $motivo = "la IP $ip ya la tiene en RADIUS el usuario {$ipsRadius[$ip]}";
        }

        if ($motivo !== null) {
            $problemas[] = ($n !== '' ? $n : '(sin nombre)') . ': ' . $motivo;
        } elseif (isset($importadas[$n])) {
            $act = $actualRadius[$n] ?? [];
            $cambios = [];
            if (($act['Cleartext-Password'] ?? null) !== $pass) {
                $cambios[] = 'contraseña';
            }
            if (($act['Framed-IP-Address'] ?? null) !== $ip) {
                $cambios[] = 'IP ' . ($act['Framed-IP-Address'] ?? '-') . ' -> ' . $ip;
            }
            if (($act['Mikrotik-Group'] ?? null) !== $perfil) {
                $cambios[] = 'perfil ' . ($act['Mikrotik-Group'] ?? '-') . ' -> ' . $perfil;
            }
            if ($cambios !== []) {
                $actualizar[] = ['name' => $n, 'ip' => $ip, 'perfil' => $perfil, 'pass' => $pass, 'cambios' => implode(', ', $cambios)];
            } else {
                $omitidas[] = "$n (ya está igual en RADIUS)";
            }
        } else {
            $cargar[] = ['name' => $n, 'ip' => $ip, 'perfil' => $perfil, 'pass' => $pass];
        }
    }

    $porPerfil = array_count_values(array_column($cargar, 'perfil'));
    ksort($porPerfil);
    salida('');
    salida('Listas para cargar: ' . count($cargar));
    foreach ($porPerfil as $p => $k) {
        salida("   $p: $k");
    }
    salida('Para actualizar (cambiaron en el router): ' . count($actualizar));
    foreach ($actualizar as $u) {
        salida("   - {$u['name']}: {$u['cambios']}");
    }
    $iguales = count(array_filter($omitidas, fn ($o) => str_contains($o, 'ya está igual')));
    salida('Ya iguales en RADIUS: ' . $iguales);
    $otras = array_values(array_filter($omitidas, fn ($o) => !str_contains($o, 'ya está igual')));
    salida('Omitidas (no se tocan): ' . count($otras));
    foreach ($otras as $o) {
        salida("   - $o");
    }
    salida('Con problema (NO se cargan): ' . count($problemas));
    foreach ($problemas as $p) {
        salida("   - $p");
    }

    if (!$aplicar) {
        salida('');
        salida('[SIMULADO] No se escribió nada. Si el resumen está bien, repite con --aplicar.');
        exit(0);
    }

    /* ---------- Escribir ---------- */
    $pdo->beginTransaction();
    $insCheck = $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)");
    $insReply = $pdo->prepare('INSERT INTO radreply (username, attribute, op, value) VALUES (?, ?, \':=\', ?)');
    $insLog = $pdo->prepare('INSERT INTO ubb_pppoe_importados (username, ip, perfil) VALUES (?, ?, ?)');
    foreach ($cargar as $c) {
        $insCheck->execute([$c['name'], $c['pass']]);
        $insReply->execute([$c['name'], 'Framed-IP-Address', $c['ip']]);
        $insReply->execute([$c['name'], 'Mikrotik-Group', $c['perfil']]);
        $insLog->execute([$c['name'], $c['ip'], $c['perfil']]);
    }
    foreach ($actualizar as $c) {
        $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Cleartext-Password'")->execute([$c['name']]);
        $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute IN ('Framed-IP-Address', 'Mikrotik-Group')")->execute([$c['name']]);
        $insCheck->execute([$c['name'], $c['pass']]);
        $insReply->execute([$c['name'], 'Framed-IP-Address', $c['ip']]);
        $insReply->execute([$c['name'], 'Mikrotik-Group', $c['perfil']]);
        $pdo->prepare('UPDATE ubb_pppoe_importados SET ip = ?, perfil = ? WHERE username = ?')->execute([$c['ip'], $c['perfil'], $c['name']]);
    }
    $pdo->commit();
    salida('');
    salida('Listo: ' . count($cargar) . ' cuentas nuevas y ' . count($actualizar) . ' actualizadas en RADIUS. Las cuentas locales del router siguen mandando.');
    salida('Recuerda borrar el archivo con contraseñas: rm ' . $archivo);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    salida('ERROR: ' . $e->getMessage() . ' (no se escribió nada)');
    exit(1);
}
