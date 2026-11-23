<?php
/*
 * uisp-puente.php - El panel habla con la API de UISP a través del router MKR-FSL02.
 *
 * El servidor del panel no llega a UISP (redes distintas), pero el router sí.
 * Por eso el panel le pide al router que haga la llamada (/tool fetch) y le
 * devuelva la respuesta. La llave de UISP vive en el servidor
 * (/etc/ubb-panel/uisp-app.key) y solo pasa por el router en el momento de cada llamada.
 *
 * Como biblioteca:  require_once __DIR__ . '/uisp-puente.php';
 *                   $r = uispPorRouter($router, 'GET', '/clients?limit=1');
 *
 * Como prueba (solo consulta, no cambia nada):
 *   docker exec -it servidor-aviso php /var/www/html/planes-cliente/uisp-puente.php
 *   ... uisp-puente.php --explorar       (campos de servicios y motivos de suspensión)
 *   ... uisp-puente.php --explorar-nms   (camino servicio -> sitio -> equipo -> IP; requiere token NMS)
 */

use RouterOS\Client;
use RouterOS\Query;

const UISP_PUENTE_API = 'https://192.168.102.253:8445/crm/api/v1.0';
const UISP_PUENTE_LLAVE = '/etc/ubb-panel/uisp-app.key';
const UISP_PUENTE_NMS = 'https://192.168.102.253:8445/nms/api/v2.1';
const UISP_PUENTE_TOKEN_NMS = '/etc/ubb-panel/uisp-nms.token';

/** Lee la llave de la API de UISP guardada en el servidor. */
function uispLlave(): string
{
    $llave = trim((string) @file_get_contents(UISP_PUENTE_LLAVE));
    if ($llave === '') {
        throw new RuntimeException('Falta la llave de UISP en ' . UISP_PUENTE_LLAVE . '.');
    }
    return $llave;
}

/** Lee el token (solo lectura) de la API de red de UISP (NMS). */
function uispTokenNms(): string
{
    $t = trim((string) @file_get_contents(UISP_PUENTE_TOKEN_NMS));
    if ($t === '') {
        throw new RuntimeException('Falta el token de UISP NMS en ' . UISP_PUENTE_TOKEN_NMS . '.');
    }
    return $t;
}

/**
 * Llama a la API de UISP usando el router como intermediario.
 * $api = 'crm' (clientes y servicios) o 'nms' (equipos y sitios).
 * Devuelve ['ok' => bool, 'datos' => string (cuerpo de la respuesta), 'error' => string].
 */
function uispPorRouter(Client $router, string $metodo, string $ruta, ?array $cuerpo = null, string $api = 'crm'): array
{
    $cabecera = $api === 'nms'
        ? 'x-auth-token: ' . uispTokenNms()
        : 'X-Auth-App-Key: ' . uispLlave();
    $q = (new Query('/tool/fetch'))
        ->equal('url', ($api === 'nms' ? UISP_PUENTE_NMS : UISP_PUENTE_API) . $ruta)
        ->equal('http-method', strtolower($metodo))
        ->equal('check-certificate', 'no')
        ->equal('http-header-field', $cabecera . ',Content-Type: application/json')
        ->equal('output', 'user')
        ->equal('as-value', '');
    if ($cuerpo !== null) {
        $q->equal('http-data', json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $datos = '';
    $estado = '';
    $error = '';
    // La librería del router lanza avisos "Deprecated: chr()" con palabras largas; no afectan.
    $nivel = error_reporting(error_reporting() & ~E_DEPRECATED);
    try {
        $respuesta = $router->query($q)->read(false);
    } finally {
        error_reporting($nivel);
    }
    // Respuesta cruda: líneas como "!re", "=status=finished", "=data=...", "!trap", "=message=..."
    foreach ($respuesta as $linea) {
        $linea = (string) $linea;
        if (preg_match('/^=([a-z-]+)=(.*)$/s', $linea, $m)) {
            if ($m[1] === 'status') {
                $estado = $m[2];
            } elseif ($m[1] === 'data') {
                $datos .= $m[2];
            } elseif ($m[1] === 'message' || $m[1] === 'failure') {
                $error = $m[2];
            }
        }
    }
    $ok = $error === '' && $estado === 'finished';
    if (!$ok && $error === '') {
        $error = $estado !== '' ? "estado: $estado" : 'sin respuesta del router';
    }
    return ['ok' => $ok, 'datos' => $datos, 'error' => $error];
}

/* ---------- Prueba desde la terminal ---------- */

$ejecutadoDirecto = isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__);

if (!$ejecutadoDirecto) {
    return; // incluido como biblioteca
}
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

$usuario = getenv('MIKROTIK_USER') ?: 'api-ubb';
$pass = trim((string) (getenv('MIKROTIK_PASS') ?: @file_get_contents('/etc/ubb-panel/mikrotik-api.pass')));
if ($pass === '') {
    echo "Contraseña de la API del router para '$usuario' (no se ve al escribir): ";
    system('stty -echo');
    $pass = trim((string) fgets(STDIN));
    system('stty echo');
    echo "\n";
}

try {
    uispLlave();
    echo "1) Llave de UISP: encontrada ✓\n";
} catch (Throwable $e) {
    echo "1) Llave de UISP: " . $e->getMessage() . "\n";
    exit(1);
}

try {
    $router = new Client([
        'host' => getenv('MIKROTIK_HOST') ?: '10.0.1.6',
        'user' => $usuario,
        'pass' => $pass,
        'port' => (int) (getenv('MIKROTIK_PORT') ?: 8728),
        'timeout' => 10,
    ]);
    echo "2) Router: conectado ✓\n";
} catch (Throwable $e) {
    echo "2) Router: no conecta (" . $e->getMessage() . ")\n";
    exit(1);
}

$paso = 3;
foreach (['/version', '/clients?limit=1'] as $ruta) {
    try {
        $r = uispPorRouter($router, 'GET', $ruta);
        echo $r['ok']
            ? "$paso) UISP $ruta: responde ✓ (" . strlen($r['datos']) . " bytes)\n"
            : "$paso) UISP $ruta: falló -> {$r['error']}\n";
        if ($r['ok'] && $ruta === '/version') {
            echo '   ' . substr($r['datos'], 0, 120) . "\n";
        }
    } catch (Throwable $e) {
        echo "$paso) UISP $ruta: falló -> " . $e->getMessage() . "\n";
    }
    $paso++;
}

/* --explorar: muestra cómo guarda UISP los servicios (solo nombres de campos, sin datos de clientes). */
if (in_array('--explorar', $argv ?? [], true)) {
    echo "\n--- Explorar (solo consulta) ---\n";
    $r = uispPorRouter($router, 'GET', '/service-suspension-reasons');
    echo "Motivos de suspensión: ";
    if ($r['ok']) {
        foreach ((array) json_decode($r['datos'], true) as $m) {
            echo "\n   id " . ($m['id'] ?? '?') . ': ' . ($m['name'] ?? '?');
        }
        echo "\n";
    } else {
        echo "falló -> {$r['error']}\n";
    }

    foreach ([1, 25, 50] as $n) {
        $r = uispPorRouter($router, 'GET', "/clients/services?limit=$n");
        echo "Servicios (limit=$n): " . ($r['ok'] ? strlen($r['datos']) . ' bytes' : "falló -> {$r['error']}") . "\n";
        if ($n === 1 && $r['ok']) {
            $lista = json_decode($r['datos'], true);
            $serv = is_array($lista) ? ($lista[0] ?? []) : [];
            echo "Campos de un servicio:\n   " . implode(', ', array_keys($serv)) . "\n";
            foreach ($serv as $campo => $valor) {
                if (stripos($campo, 'ip') !== false || stripos($campo, 'status') !== false
                    || stripos($campo, 'site') !== false || stripos($campo, 'suspen') !== false) {
                    echo "   $campo => " . (is_scalar($valor) || $valor === null
                        ? var_export($valor, true)
                        : 'tipo ' . gettype($valor) . ' con ' . count((array) $valor) . ' elementos'
                            . (is_array($valor) && $valor !== [] ? ' (llaves: ' . implode(',', array_keys((array) (array_values($valor)[0] ?? []))) . ')' : '')) . "\n";
                }
            }
        }
    }
}

/* --explorar-nms: sigue el camino servicio CRM -> sitio -> equipos NMS -> IP, con 3 servicios. */
if (in_array('--explorar-nms', $argv ?? [], true)) {
    echo "\n--- Explorar NMS (solo consulta) ---\n";
    try {
        uispTokenNms();
        echo "Token NMS: encontrado ✓\n";
    } catch (Throwable $e) {
        echo $e->getMessage() . "\n";
        exit(1);
    }
    $r = uispPorRouter($router, 'GET', '/clients/services?limit=10&offset=10');
    echo "Servicios (limit=10, offset=10): " . ($r['ok'] ? strlen($r['datos']) . ' bytes' : "falló -> {$r['error']}") . "\n";
    $servicios = $r['ok'] ? (array) json_decode($r['datos'], true) : [];
    $probados = 0;
    foreach ($servicios as $sv) {
        $sitio = (string) ($sv['unmsClientSiteId'] ?? '');
        if ($sitio === '' || $probados >= 3) {
            continue;
        }
        $probados++;
        echo "\nServicio " . ($sv['id'] ?? '?') . " (estado " . ($sv['status'] ?? '?') . "), sitio $sitio\n";
        $d = uispPorRouter($router, 'GET', '/devices?siteId=' . rawurlencode($sitio), null, 'nms');
        if (!$d['ok']) {
            echo "   equipos: falló -> {$d['error']}\n";
            continue;
        }
        $equipos = (array) json_decode($d['datos'], true);
        echo "   equipos en el sitio: " . count($equipos) . " (" . strlen($d['datos']) . " bytes)\n";
        foreach (array_slice($equipos, 0, 3) as $eq) {
            echo "   - modelo " . ($eq['identification']['model'] ?? '?')
                . ", rol " . ($eq['identification']['role'] ?? '?')
                . ", ipAddress " . var_export($eq['ipAddress'] ?? null, true)
                . ", mac " . ($eq['identification']['mac'] ?? '?') . "\n";
        }
        if ($probados === 1 && isset($equipos[0])) {
            echo "   campos de un equipo: " . implode(', ', array_keys($equipos[0])) . "\n";
        }
    }
    if ($probados === 0) {
        echo "Ninguno de esos 10 servicios tiene sitio enlazado.\n";
    }
}
echo "Listo. No se cambió nada.\n";
