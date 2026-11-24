<?php
/*
 * tokens.php - Administra los tokens e IPs permitidas de la API RADIUS (solo desde la terminal).
 *
 * El token se muestra UNA vez al crearlo; en el servidor solo se guarda su huella (sha256)
 * en /etc/ubb-panel/api-radius.json.
 *
 *   php tokens.php ver
 *   php tokens.php crear pruebas <id_servicio>     token que SOLO puede usar ese servicio
 *   php tokens.php crear produccion                token para todos los servicios
 *   php tokens.php crear lectura [id_servicio...]  token SOLO DE CONSULTA (GET): todos los servicios,
 *                                                  o solo los que se indiquen; no puede suspender ni reactivar
 *   php tokens.php borrar <nombre>
 *   php tokens.php ip-permitir <ip>
 *   php tokens.php ip-quitar <ip>
 *
 * Dentro del contenedor:
 *   docker exec -it servidor-aviso php /var/www/html/api-radius/tokens.php ver
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const ARCHIVO = '/etc/ubb-panel/api-radius.json';

date_default_timezone_set('America/Mexico_City');

function leer(): array
{
    $cfg = json_decode((string) @file_get_contents(ARCHIVO), true);
    return is_array($cfg) ? $cfg + ['ips' => [], 'tokens' => []]
        : ['ips' => ['192.168.100.222'], 'tokens' => []];   // por defecto: servidor de UBB Pagos
}

function guardar(array $cfg): void
{
    if (!is_dir(dirname(ARCHIVO))) {
        mkdir(dirname(ARCHIVO), 0750, true);
    }
    file_put_contents(ARCHIVO, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    @chgrp(ARCHIVO, 'www-data');
    chmod(ARCHIVO, 0640);
}

function fallar(string $msg): never
{
    fwrite(STDERR, $msg . "\n");
    exit(1);
}

$orden = $argv[1] ?? 'ver';
$cfg = leer();

switch ($orden) {
    case 'ver':
        echo "IPs permitidas: " . ($cfg['ips'] ? implode(', ', $cfg['ips']) : '(ninguna)') . "\n";
        echo "Tokens:\n";
        foreach ($cfg['tokens'] as $t) {
            echo '  - ' . $t['nombre'] . ' (creado ' . ($t['creado'] ?? '?') . '): '
                . (is_array($t['servicios'] ?? null) ? 'solo servicio(s) ' . implode(', ', $t['servicios']) : 'todos los servicios')
                . (!empty($t['solo_lectura']) ? ' · SOLO CONSULTA' : '') . "\n";
        }
        if (!$cfg['tokens']) {
            echo "  (ninguno)\n";
        }
        break;

    case 'crear':
        $nombre = $argv[2] ?? '';
        if (!in_array($nombre, ['pruebas', 'produccion', 'lectura'], true)) {
            fallar('Uso: crear pruebas <id_servicio>   |   crear produccion   |   crear lectura [id_servicio...]');
        }
        $servicios = null;
        if ($nombre === 'lectura') {
            $ids = array_slice($argv, 3);
            if (array_filter($ids, fn ($i) => !ctype_digit($i))) {
                fallar('Los id_servicio deben ser números: crear lectura 1339 1347');
            }
            $servicios = $ids === [] ? null : array_map('intval', $ids);
        }
        if ($nombre === 'pruebas') {
            $ids = array_slice($argv, 3);
            if ($ids === [] || array_filter($ids, fn ($i) => !ctype_digit($i))) {
                fallar('El token de pruebas necesita el id_servicio de prueba: crear pruebas 1234');
            }
            $servicios = array_map('intval', $ids);
        }
        $token = 'ubb_' . $nombre . '_' . bin2hex(random_bytes(24));
        $cfg['tokens'] = array_values(array_filter($cfg['tokens'], fn ($t) => ($t['nombre'] ?? '') !== $nombre));
        $cfg['tokens'][] = [
            'nombre' => $nombre,
            'sha256' => hash('sha256', $token),
            'servicios' => $servicios,
            'solo_lectura' => $nombre === 'lectura',
            'creado' => date('Y-m-d H:i'),
        ];
        guardar($cfg);
        echo "\nToken '$nombre' creado" . ($servicios ? ' (solo servicio ' . implode(', ', $servicios) . ')' : '')
            . ($nombre === 'lectura' ? ' · SOLO CONSULTA: no puede suspender ni reactivar' : '') . ".\n";
        echo "Cópialo AHORA; no se vuelve a mostrar (si se pierde, se crea otro):\n\n  $token\n\n";
        echo "Entrégalo por un medio privado, no por chat ni correo. Si ya existía uno '$nombre', quedó reemplazado.\n";
        break;

    case 'borrar':
        $nombre = $argv[2] ?? '';
        $antes = count($cfg['tokens']);
        $cfg['tokens'] = array_values(array_filter($cfg['tokens'], fn ($t) => ($t['nombre'] ?? '') !== $nombre));
        guardar($cfg);
        echo $antes === count($cfg['tokens']) ? "No existía el token '$nombre'.\n" : "Token '$nombre' borrado: ya no funciona.\n";
        break;

    case 'ip-permitir':
    case 'ip-quitar':
        $ip = $argv[2] ?? '';
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            fallar('IP no válida.');
        }
        $cfg['ips'] = array_values(array_unique(array_diff($cfg['ips'], [$ip])));
        if ($orden === 'ip-permitir') {
            $cfg['ips'][] = $ip;
        }
        guardar($cfg);
        echo "IPs permitidas: " . ($cfg['ips'] ? implode(', ', $cfg['ips']) : '(ninguna)') . "\n";
        break;

    default:
        fallar('Órdenes: ver | crear pruebas <id> | crear produccion | borrar <nombre> | ip-permitir <ip> | ip-quitar <ip>');
}
