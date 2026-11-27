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
 *   ... uisp-puente.php --explorar-planes   (planes de UISP con su velocidad y periodos; y el plan de un servicio con --servicio=ID)
 *   ... uisp-puente.php --cambiar-plan=SERVICIO_UISP --a=30 [--aplicar]   (prueba con un servicio; sin --aplicar solo simula)
 *   ... uisp-puente.php --explorar-sitio=ID   (cuántos equipos tiene un sitio del NMS, ej. PENDIENTES; solo conteos)
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

/**
 * Igual que uispPorRouter (solo GET), pero para respuestas grandes (> 64 KB): el router guarda la respuesta
 * en un archivo temporal, se lee por partes y se borra. Requiere que el grupo del usuario del panel
 * tenga permiso de leer archivos (si no, devuelve el error del router).
 */
function uispPorRouterGrande(Client $router, string $ruta, string $api = 'nms'): array
{
    $cabecera = $api === 'nms' ? 'x-auth-token: ' . uispTokenNms() : 'X-Auth-App-Key: ' . uispLlave();
    $archivo = 'ubb-tmp-' . bin2hex(random_bytes(4)) . '.json';
    $leer = function (Query $q) use ($router): array {
        $nivel = error_reporting(error_reporting() & ~E_DEPRECATED);
        try {
            $r = $router->query($q)->read(false);
        } finally {
            error_reporting($nivel);
        }
        $out = ['data' => '', 'error' => '', 'status' => ''];
        foreach ($r as $linea) {
            if (preg_match('/^=([a-z-]+)=(.*)$/s', (string) $linea, $m)) {
                if ($m[1] === 'data') {
                    $out['data'] .= $m[2];
                } elseif ($m[1] === 'message' || $m[1] === 'failure') {
                    $out['error'] = $m[2];
                } elseif ($m[1] === 'status') {
                    $out['status'] = $m[2];
                }
            }
        }
        return $out;
    };
    try {
        $f = $leer((new Query('/tool/fetch'))
            ->equal('url', ($api === 'nms' ? UISP_PUENTE_NMS : UISP_PUENTE_API) . $ruta)
            ->equal('check-certificate', 'no')
            ->equal('http-header-field', $cabecera)
            ->equal('output', 'file')
            ->equal('dst-path', $archivo));
        if ($f['error'] !== '' || $f['status'] !== 'finished') {
            return ['ok' => false, 'datos' => '', 'error' => $f['error'] ?: 'estado: ' . $f['status']];
        }
        $datos = '';
        for ($offset = 0, $vueltas = 0; $vueltas < 200; $vueltas++) {
            $r = $leer((new Query('/file/read'))->equal('file', $archivo)->equal('offset', (string) $offset)->equal('chunk-size', '32768'));
            if ($r['error'] !== '') {
                return ['ok' => false, 'datos' => '', 'error' => 'leyendo el archivo: ' . $r['error']];
            }
            $datos .= $r['data'];
            if (strlen($r['data']) < 32768) {
                break;
            }
            $offset += strlen($r['data']);
        }
        return ['ok' => true, 'datos' => $datos, 'error' => ''];
    } finally {
        try {
            $leer((new Query('/file/remove'))->equal('numbers', $archivo));
        } catch (Throwable $e) {
            // Si no se pudo borrar, queda un archivo ubb-tmp-*.json sin datos de clientes sensibles en Files.
        }
    }
}

/**
 * Camino directo a UISP sin el límite de 64 KB: el router MKR-FSL02 reenvía 10.0.1.6:18445 -> UISP 8445
 * (regla NAT "Panel UBB -> UISP", solo para este servidor). Solo GET.
 */
const UISP_DIRECTO = 'https://10.0.1.6:18445';

function uispDirecto(string $ruta, string $api = 'nms'): array
{
    $base = UISP_DIRECTO . ($api === 'nms' ? '/nms/api/v2.1' : '/crm/api/v1.0');
    $cabecera = $api === 'nms' ? 'x-auth-token: ' . uispTokenNms() : 'X-Auth-App-Key: ' . uispLlave();
    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'header' => $cabecera . "\r\nAccept: application/json\r\n", 'timeout' => 60, 'ignore_errors' => true],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $datos = @file_get_contents($base . $ruta, false, $ctx);
    $codigo = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $codigo = (int) $m[1];
        }
    }
    if ($datos === false || $codigo === 0) {
        return ['ok' => false, 'datos' => '', 'error' => 'sin conexión directa con UISP (' . UISP_DIRECTO . '). ¿Sigue la regla NAT "Panel UBB -> UISP"?'];
    }
    return ['ok' => $codigo >= 200 && $codigo < 300, 'datos' => (string) $datos, 'error' => $codigo >= 300 ? "HTTP $codigo" : ''];
}

/* ---------- Sincronizar acciones del panel con UISP ---------- */

const UISP_PUENTE_ACTIVO = '/etc/ubb-panel/uisp-sync-activo';   // si existe este archivo, el panel avisa a UISP
const UISP_MOTIVO_SUSPENSION = 1;                                 // "Pagos vencidos"
const UISP_MINUTOS_GRACIA = 10;                                   // sync-uisp no toca a ese cliente mientras UISP actualiza el router

/** ¿Está encendido el aviso panel -> UISP? (se enciende creando el archivo UISP_PUENTE_ACTIVO). */
function uispSyncActivo(): bool
{
    return is_file(UISP_PUENTE_ACTIVO);
}

/** Crea las tablas que usa el enlace con UISP (si el usuario de la base tiene permiso). */
function uispCrearTablas(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS ubb_uisp_mapa (
        servicio_id INT NOT NULL,
        cliente_id INT NULL,
        estado TINYINT NULL,
        sitio_id VARCHAR(64) NOT NULL,
        ip VARCHAR(45) NOT NULL DEFAULT '',
        mac VARCHAR(17) NOT NULL DEFAULT '',
        rol VARCHAR(32) NOT NULL DEFAULT '',
        actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY k_mac (mac), KEY k_ip (ip), KEY k_servicio (servicio_id)
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ubb_uisp_gracia (
        username VARCHAR(64) NOT NULL PRIMARY KEY,
        hasta DATETIME NOT NULL
    ) DEFAULT CHARSET=utf8mb4");
}

/**
 * Número de servicio de UISP de un cliente del panel (primero por MAC, luego por IP).
 * Devuelve null si no está en el mapa. Lanza error si coincide con más de un servicio.
 */
function uispServicioDe(PDO $pdo, string $mac, string $ip): ?int
{
    foreach ([['mac', strtoupper($mac)], ['ip', $ip]] as [$campo, $valor]) {
        if ($valor === '') {
            continue;
        }
        $st = $pdo->prepare("SELECT DISTINCT servicio_id FROM ubb_uisp_mapa WHERE $campo = ? AND estado IN (1, 3)");
        $st->execute([$valor]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if (count($ids) === 1) {
            return $ids[0];
        }
        if (count($ids) > 1) {
            throw new RuntimeException("La $campo $valor aparece en varios servicios de UISP (#" . implode(', #', $ids) . '). Revísalo en UISP.');
        }
    }
    return null;
}

function uispSuspenderServicio(Client $router, int $servicioId): array
{
    return uispPorRouter($router, 'PATCH', "/clients/services/$servicioId/suspend", ['suspensionReasonId' => UISP_MOTIVO_SUSPENSION]);
}

function uispReactivarServicio(Client $router, int $servicioId): array
{
    return uispPorRouter($router, 'PATCH', "/clients/services/$servicioId/cancel-suspend");
}

/* Cambio de plan en UISP: velocidad del panel (Mbps) -> periodo del plan de UISP (de --explorar-planes, 27/11/2026).
   20M -> HOGAR-BASICO 25 Mbps, 30M -> HOGAR-INTERMEDIO 35 Mbps, 60M -> HOGAR-AVANZADO 60 Mbps. */
const UISP_PERIODO_POR_VELOCIDAD = [20 => 26, 30 => 14, 60 => 20];
const UISP_PLAN_ACTIVO = '/etc/ubb-panel/uisp-plan-activo';   // si existe, Cambiar plan del panel también cambia el plan en UISP

function uispCambioPlanActivo(): bool
{
    return is_file(UISP_PLAN_ACTIVO);
}

/** Lee el plan actual de un servicio de UISP: ['periodo' => int, 'plan' => string, 'estado' => int]. */
function uispPlanDeServicio(Client $router, int $servicioId): array
{
    $r = uispPorRouter($router, 'GET', "/clients/services/$servicioId");
    if (!$r['ok']) {
        throw new RuntimeException("UISP no respondió el servicio #$servicioId ({$r['error']}).");
    }
    $sv = (array) json_decode($r['datos'], true);
    return ['periodo' => (int) ($sv['servicePlanPeriodId'] ?? 0), 'plan' => (string) ($sv['servicePlanName'] ?? ''),
        'estado' => (int) ($sv['status'] ?? -1)];
}

/**
 * Cambia el plan de un servicio de UISP a la velocidad indicada y lo vuelve a leer para confirmar.
 * Devuelve un texto corto con el resultado. Lanza error si UISP no lo aceptó.
 */
function uispCambiarPlanServicio(Client $router, int $servicioId, int $mbps): string
{
    $periodo = UISP_PERIODO_POR_VELOCIDAD[$mbps] ?? null;
    if ($periodo === null) {
        throw new RuntimeException("no hay plan de UISP para {$mbps}M");
    }
    $antes = uispPlanDeServicio($router, $servicioId);
    if ($antes['periodo'] === $periodo) {
        return "UISP servicio #$servicioId ya tenía {$antes['plan']}.";
    }
    $r = uispPorRouter($router, 'PATCH', "/clients/services/$servicioId", ['servicePlanPeriodId' => $periodo]);
    if (!$r['ok']) {
        throw new RuntimeException("UISP rechazó el cambio del servicio #$servicioId ({$r['error']})");
    }
    $despues = uispPlanDeServicio($router, $servicioId);
    if ($despues['periodo'] !== $periodo) {
        throw new RuntimeException("UISP respondió, pero el servicio #$servicioId sigue con {$despues['plan']}");
    }
    return "UISP servicio #$servicioId: de {$antes['plan']} a {$despues['plan']}.";
}

/** Durante unos minutos sync-uisp no suspende ni reactiva a este cliente, mientras UISP actualiza el router. */
function uispMarcarGracia(PDO $pdo, string $mac): void
{
    $pdo->prepare("REPLACE INTO ubb_uisp_gracia (username, hasta) VALUES (?, NOW() + INTERVAL " . (int) UISP_MINUTOS_GRACIA . " MINUTE)")
        ->execute([$mac]);
}

/** Conexión al router para scripts de terminal: variable de entorno, archivo o (si hay terminal) se pregunta. */
function uispConectarRouter(?string $host = null): Client
{
    $usuario = getenv('MIKROTIK_USER') ?: 'api-ubb';
    $pass = trim((string) (getenv('MIKROTIK_PASS') ?: @file_get_contents('/etc/ubb-panel/mikrotik-api.pass')));
    if ($pass === '' && PHP_SAPI === 'cli' && function_exists('stream_isatty') && stream_isatty(STDIN)) {
        echo "Contraseña de la API del router para '$usuario' (no se ve al escribir): ";
        system('stty -echo');
        $pass = trim((string) fgets(STDIN));
        system('stty echo');
        echo "\n";
    }
    if ($pass === '') {
        throw new RuntimeException('Falta la contraseña de la API del router (MIKROTIK_PASS o /etc/ubb-panel/mikrotik-api.pass).');
    }
    return new Client([
        'host' => $host ?: (getenv('MIKROTIK_HOST') ?: '10.0.1.6'),
        'user' => $usuario,
        'pass' => $pass,
        'port' => (int) (getenv('MIKROTIK_PORT') ?: 8728),
        'timeout' => 15,
    ]);
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

/* --explorar-planes: planes de servicio de UISP (para saber qué plan corresponde a 20M/30M/60M). Solo consulta. */
if (in_array('--explorar-planes', $argv ?? [], true)) {
    echo "\n--- Planes de UISP (solo consulta) ---\n";
    $r = uispPorRouter($router, 'GET', '/service-plans');
    if (!$r['ok']) {
        echo "falló -> {$r['error']}\n";
    } else {
        foreach ((array) json_decode($r['datos'], true) as $p) {
            if (($p['servicePlanType'] ?? $p['type'] ?? '') !== '' && stripos((string) ($p['servicePlanType'] ?? $p['type']), 'internet') === false) continue;
            $periodos = [];
            foreach ((array) ($p['periods'] ?? []) as $per) {
                if (!empty($per['enabled'])) $periodos[] = 'periodo ' . ($per['id'] ?? '?') . ' (' . ($per['period'] ?? '?') . ' mes, $' . ($per['price'] ?? '?') . ')';
            }
            printf("  plan %-4s %-32s bajada %-6s subida %-6s %s%s\n", $p['id'] ?? '?', $p['name'] ?? '?',
                ($p['downloadSpeed'] ?? '?'), ($p['uploadSpeed'] ?? '?'), implode(' · ', $periodos),
                !empty($p['archived']) ? '  [archivado]' : '');
        }
    }
    foreach ($argv as $a) {
        if (!preg_match('/^--servicio=(\d+)$/', $a, $m)) continue;
        $r = uispPorRouter($router, 'GET', '/clients/services/' . $m[1]);
        if (!$r['ok']) { echo "Servicio {$m[1]}: falló -> {$r['error']}\n"; continue; }
        $sv = (array) json_decode($r['datos'], true);
        echo "\nServicio {$m[1]}: plan " . ($sv['servicePlanId'] ?? '?') . ' ' . ($sv['servicePlanName'] ?? '')
            . ' · periodo ' . ($sv['servicePlanPeriodId'] ?? '?') . ' · bajada ' . ($sv['downloadSpeed'] ?? '?')
            . ' · estado ' . ($sv['status'] ?? '?') . "\n";
        $otros = array_filter(array_keys($sv), fn ($k) => stripos($k, 'plan') !== false || stripos($k, 'speed') !== false || stripos($k, 'defer') !== false);
        echo '   campos de plan: ' . implode(', ', $otros) . "\n";
    }
}

/* --cambiar-plan=SERVICIO_UISP --a=30 [--aplicar]: prueba el cambio de plan en UISP con un solo servicio.
   Sin --aplicar solo muestra qué haría. */
foreach ($argv ?? [] as $a) {
    if (!preg_match('/^--cambiar-plan=(\d+)$/', $a, $m)) continue;
    $sid = (int) $m[1];
    $mbps = 0;
    foreach ($argv as $b) if (preg_match('/^--a=(\d+)M?$/i', $b, $mm)) $mbps = (int) $mm[1];
    echo "\n--- Cambiar plan en UISP · servicio #$sid a {$mbps}M ---\n";
    try {
        $actual = uispPlanDeServicio($router, $sid);
        echo "Ahora tiene: {$actual['plan']} (periodo {$actual['periodo']}, estado {$actual['estado']})\n";
        $destino = UISP_PERIODO_POR_VELOCIDAD[$mbps] ?? null;
        if ($destino === null) {
            echo "No hay plan de UISP para {$mbps}M. Usa --a=20, --a=30 o --a=60.\n";
        } elseif (!in_array('--aplicar', $argv, true)) {
            echo "SIMULACIÓN: lo cambiaría al periodo $destino. Agrega --aplicar para hacerlo.\n";
        } else {
            echo uispCambiarPlanServicio($router, $sid, $mbps) . "\n";
        }
    } catch (Throwable $e) {
        echo 'ERROR: ' . $e->getMessage() . "\n";
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
/* --explorar-sitio=ID: cuántos equipos tiene un sitio del NMS (ej. PENDIENTES) y si caben en una consulta.
   Solo muestra conteos y tamaños, sin nombres ni datos de clientes. */
foreach ($argv ?? [] as $arg) {
    if (!preg_match('/^--explorar-sitio=([0-9a-fA-F-]{8,64})$/', $arg, $m)) {
        continue;
    }
    echo "\n--- Explorar sitio {$m[1]} (solo consulta) ---\n";
    $s = uispPorRouter($router, 'GET', '/sites/' . $m[1], null, 'nms');
    echo "Sitio: " . ($s['ok'] ? strlen($s['datos']) . ' bytes' : "falló -> {$s['error']}") . "\n";
    if ($s['ok']) {
        $sitio = (array) json_decode($s['datos'], true);
        echo "   nombre: " . ($sitio['identification']['name'] ?? '?') . ", tipo: " . ($sitio['identification']['type'] ?? '?') . "\n";
    }
    foreach (['', '&role=router', 'ARCHIVO'] as $filtro) {
        $d = $filtro === 'ARCHIVO'
            ? uispPorRouterGrande($router, '/devices?siteId=' . rawurlencode($m[1]))
            : uispPorRouter($router, 'GET', '/devices?siteId=' . rawurlencode($m[1]) . $filtro, null, 'nms');
        if ($filtro === 'ARCHIVO') {
            $filtro = ' por archivo temporal, sin límite';
        }
        echo "Equipos" . ($filtro === '' ? '' : " ($filtro)") . ": ";
        if (!$d['ok']) {
            echo "falló -> {$d['error']}\n";
            continue;
        }
        $equipos = (array) json_decode($d['datos'], true);
        $porRol = [];
        $red = ['10.2 (PPPoE)' => 0, 'otra IP' => 0, 'sin IP' => 0];
        foreach ($equipos as $eq) {
            $rol = (string) ($eq['identification']['role'] ?? '?');
            $porRol[$rol] = ($porRol[$rol] ?? 0) + 1;
            $ip = (string) preg_replace('#/\d+$#', '', (string) ($eq['ipAddress'] ?? ''));
            $red[$ip === '' ? 'sin IP' : (strpos($ip, '10.2.') === 0 ? '10.2 (PPPoE)' : 'otra IP')]++;
        }
        echo count($equipos) . " (" . strlen($d['datos']) . " bytes)"
            . (strlen($d['datos']) >= 64000 ? " ⚠ llegó al límite de 64 KB: puede estar incompleto" : '') . "\n";
        echo "   por rol: " . json_encode($porRol) . "\n   por IP: " . json_encode($red) . "\n";
    }
}
echo "Listo. No se cambió nada.\n";
