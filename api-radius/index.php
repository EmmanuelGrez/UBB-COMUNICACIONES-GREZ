<?php
/*
 * API RADIUS para UBB Pagos (v1).
 *
 *   GET  /api-radius/v1/salud
 *   GET  /api-radius/v1/servicios/{id_servicio}
 *   POST /api-radius/v1/servicios/{id_servicio}/suspender
 *   POST /api-radius/v1/servicios/{id_servicio}/reactivar
 *
 * Contrato: documento "API RADIUS para UBB Pagos: suspensiones y reactivaciones".
 * Usa exactamente las mismas funciones que los botones del panel (radius-dhcp-acciones.php),
 * con origen PAGOS en la bitácora. Tokens e IPs permitidas: /etc/ubb-panel/api-radius.json
 * (se administran con api-radius/tokens.php desde la terminal).
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../planes-cliente/radius-dhcp-acciones.php';

date_default_timezone_set('America/Mexico_City');
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

const API_CONFIG = '/etc/ubb-panel/api-radius.json';
const API_PREFIJO = '/api-radius/v1';
const API_ORIGEN = 'PAGOS';
const API_CREDENCIAL_JAMILETH = '/etc/ubb/jamileth.pass';

final class ApiError extends RuntimeException
{
    public function __construct(public int $http, public string $resultado, string $mensaje)
    {
        parent::__construct($mensaje);
    }
}

/* ---------- Respuesta y bitácora ---------- */

$apiContexto = [
    'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    'metodo' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    'ruta' => '',
    'token' => '',
    'solicitud_id' => null,
    'id_servicio' => null,
];
$apiPdo = null;

function apiResponder(int $http, array $datos): never
{
    global $apiContexto, $apiPdo;
    $datos = ['ok' => $http < 400] + $datos + ['fecha' => date('c')];
    if ($apiPdo instanceof PDO) {
        try {
            $apiPdo->prepare('INSERT INTO ubb_api_bitacora (ip_origen, token, metodo, ruta, solicitud_id, id_servicio, http, resultado, mensaje)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $apiContexto['ip'], $apiContexto['token'], $apiContexto['metodo'], substr($apiContexto['ruta'], 0, 120),
                    $apiContexto['solicitud_id'], $apiContexto['id_servicio'], $http,
                    (string) ($datos['resultado'] ?? ''), substr((string) ($datos['mensaje'] ?? ''), 0, 255),
                ]);
        } catch (Throwable $e) {
            error_log('API RADIUS: sin bitácora: ' . $e->getMessage());
        }
    }
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/* ---------- Conexiones ---------- */

function apiPdoRadius(): PDO
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

function apiPdoJamileth(): PDO
{
    $pass = rtrim((string) @file_get_contents(API_CREDENCIAL_JAMILETH), "\r\n");
    if ($pass === '') {
        throw new RuntimeException('No se puede leer la credencial de la base de UBB Pagos.');
    }
    return new PDO('mysql:host=192.168.100.221;port=3306;dbname=ubb_plataforma;charset=utf8mb4', 'equipo_ubb', $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
}

/* ---------- Acceso: IP permitida + token ---------- */

function apiConfig(): array
{
    $cfg = json_decode((string) @file_get_contents(API_CONFIG), true);
    return is_array($cfg) ? $cfg : ['ips' => [], 'tokens' => []];
}

function apiTokenRecibido(): string
{
    $cabecera = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($cabecera === '' && function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $nombre => $valor) {
            if (strcasecmp((string) $nombre, 'Authorization') === 0) {
                $cabecera = (string) $valor;
            }
        }
    }
    return preg_match('/^Bearer\s+(\S+)$/i', trim($cabecera), $m) ? $m[1] : '';
}

/** Devuelve el token que coincide: ['nombre' => ..., 'servicios' => null|[ids]]. */
function apiAutenticar(): array
{
    global $apiContexto;
    $cfg = apiConfig();
    if (!in_array($apiContexto['ip'], (array) ($cfg['ips'] ?? []), true)) {
        throw new ApiError(401, 'NO_AUTORIZADO', 'Acceso no permitido desde esta IP.');
    }
    $token = apiTokenRecibido();
    if ($token !== '') {
        $hash = hash('sha256', $token);
        foreach ((array) ($cfg['tokens'] ?? []) as $t) {
            if (is_array($t) && isset($t['sha256']) && hash_equals((string) $t['sha256'], $hash)) {
                $apiContexto['token'] = (string) ($t['nombre'] ?? '');
                return $t;
            }
        }
    }
    throw new ApiError(401, 'NO_AUTORIZADO', 'Token ausente o incorrecto.');
}

/* ---------- id_servicio -> equipo en RADIUS ---------- */

/**
 * Busca el equipo DHCP de un servicio de UBB Pagos.
 * Devuelve ['mac', 'ip', 'lista', 'cfg' (VLAN), 'tipo' => 'DHCP'].
 */
function apiBuscarEquipo(PDO $radius, int $idServicio): array
{
    try {
        $jam = apiPdoJamileth();
    } catch (Throwable $e) {
        error_log('API RADIUS: sin base de UBB Pagos: ' . $e->getMessage());
        throw new ApiError(500, 'ERROR_INTERNO', 'No se pudo consultar la base de UBB Pagos.');
    }
    $st = $jam->prepare('SELECT id_servicio, id_cliente FROM servicios WHERE id_servicio = ?');
    $st->execute([$idServicio]);
    $servicio = $st->fetch();
    if (!$servicio) {
        throw new ApiError(404, 'NO_EXISTE', "El servicio $idServicio no existe.");
    }

    $st = $jam->prepare("SELECT mac, servidor_dhcp FROM integracion_winbox
                         WHERE id_servicio = ? AND id_cliente = ? AND tipo_fuente = 'DHCP'
                           AND UPPER(estado_vinculacion) LIKE 'RELACIONADO%'
                           AND mac IS NOT NULL AND TRIM(mac) <> '' AND servidor_dhcp IS NOT NULL");
    $st->execute([$idServicio, $servicio['id_cliente']]);
    $equipos = [];
    foreach ($st->fetchAll() as $f) {
        $equipos[strtoupper(trim((string) $f['servidor_dhcp'])) . '|' . strtoupper(trim((string) $f['mac']))] = $f;
    }
    if (count($equipos) !== 1) {
        throw new ApiError(409, 'SIN_RELACION', count($equipos) === 0
            ? "El servicio $idServicio no tiene un equipo DHCP relacionado."
            : "El servicio $idServicio tiene varios equipos relacionados; no se hace nada para no afectar al equivocado.");
    }
    $fila = reset($equipos);
    $cfg = rdVlanPorServidor((string) $fila['servidor_dhcp']);
    if ($cfg === null) {
        throw new ApiError(409, 'SIN_RELACION', 'El servidor DHCP ' . $fila['servidor_dhcp'] . ' todavía no está migrado a RADIUS.');
    }
    try {
        $mac = rdNormalizarMac((string) $fila['mac']);
        $cliente = rdLeerCliente($radius, $mac);
    } catch (Throwable $e) {
        throw new ApiError(409, 'SIN_RELACION', "El equipo del servicio $idServicio no está dado de alta en RADIUS.");
    }
    if (strpos($cliente['ip'], $cfg['prefijo']) !== 0) {
        throw new ApiError(409, 'SIN_RELACION', "El equipo del servicio $idServicio tiene una IP que no corresponde a su VLAN.");
    }
    return ['mac' => $mac, 'ip' => $cliente['ip'], 'lista' => $cliente['lista'], 'cfg' => $cfg, 'tipo' => 'DHCP'];
}

/** Estado y plan actuales del equipo (el plan de un suspendido es el que tenía guardado). */
function apiEstado(PDO $radius, array $eq): array
{
    $suspendido = $eq['lista'] === RD_LISTA_DEUDORES;
    $plan = $eq['lista'];
    $origen = null;
    if ($suspendido) {
        $st = $radius->prepare('SELECT lista_original, origen FROM ubb_suspensiones WHERE username = ?');
        $st->execute([$eq['mac']]);
        $s = $st->fetch() ?: [];
        $plan = (string) ($s['lista_original'] ?? '') ?: $eq['cfg']['lista'];
        $origen = strtoupper((string) ($s['origen'] ?? 'PANEL'));
    }
    return [
        'estado' => $suspendido ? 'SUSPENDIDO' : 'ACTIVO',
        'plan' => $plan,
        'suspendido_por' => $origen,
    ];
}

/* ---------- Programa ---------- */

try {
    $ruta = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $apiContexto['ruta'] = $ruta;
    try {
        $apiPdo = apiPdoRadius();
    } catch (Throwable $e) {
        error_log('API RADIUS: sin base radius: ' . $e->getMessage());
        throw new ApiError(500, 'ERROR_INTERNO', 'No hay conexión con la base RADIUS.');
    }
    $token = apiAutenticar();

    if (strpos($ruta, API_PREFIJO) !== 0) {
        throw new ApiError(404, 'NO_EXISTE', 'Ruta no válida.');
    }
    $sub = rtrim(substr($ruta, strlen(API_PREFIJO)), '/');
    $metodo = $apiContexto['metodo'];

    /* --- GET /salud --- */
    if ($sub === '/salud' && $metodo === 'GET') {
        $partes = ['api' => true, 'radius' => true];
        try {
            apiPdoJamileth()->query('SELECT 1');
            $partes['base_ubb_pagos'] = true;
        } catch (Throwable $e) {
            $partes['base_ubb_pagos'] = false;
        }
        try {
            $router = uispConectarRouter('10.0.1.6');
            rdRouter($router, new RouterOS\Query('/system/identity/print'));
            $partes['router'] = true;
        } catch (Throwable $e) {
            $partes['router'] = false;
        }
        if (uispSyncActivo()) {
            try {
                $partes['uisp'] = isset($router) && uispPorRouter($router, 'GET', '/version')['ok'];
            } catch (Throwable $e) {
                $partes['uisp'] = false;
            }
        }
        $todo = !in_array(false, $partes, true);
        apiResponder($todo ? 200 : 502, [
            'resultado' => $todo ? 'OK' : 'DEGRADADO',
            'componentes' => $partes,
            'token' => $token['nombre'] ?? '',
            'mensaje' => $todo ? 'Todo responde.' : 'Algún componente no responde.',
        ]);
    }

    if (!preg_match('#^/servicios/(\d{1,10})(?:/(suspender|reactivar))?$#', $sub, $m)) {
        throw new ApiError(404, 'NO_EXISTE', 'Ruta no válida.');
    }
    $idServicio = (int) $m[1];
    $accion = $m[2] ?? '';
    $apiContexto['id_servicio'] = $idServicio;

    // Token de pruebas: solo sus servicios.
    if (isset($token['servicios']) && is_array($token['servicios'])
        && !in_array($idServicio, array_map('intval', $token['servicios']), true)) {
        throw new ApiError(401, 'NO_AUTORIZADO', 'Este token solo puede usar el servicio de prueba.');
    }

    /* --- GET /servicios/{id} --- */
    if ($accion === '') {
        if ($metodo !== 'GET') {
            throw new ApiError(404, 'NO_EXISTE', 'Ruta no válida para ' . $metodo . '.');
        }
        $eq = apiBuscarEquipo($apiPdo, $idServicio);
        $estado = apiEstado($apiPdo, $eq);
        apiResponder(200, [
            'resultado' => 'CONSULTA',
            'id_servicio' => $idServicio,
            'estado_actual' => $estado['estado'],
            'suspendido_por' => $estado['suspendido_por'],
            'tipo' => $eq['tipo'],
            'ip' => $eq['ip'],
            'mac' => $eq['mac'],
            'plan' => $estado['plan'],
            'vlan' => $eq['cfg']['vlan'],
            'mensaje' => 'Consulta sin cambios.',
        ]);
    }

    /* --- POST /servicios/{id}/suspender|reactivar --- */
    if ($metodo !== 'POST') {
        throw new ApiError(404, 'NO_EXISTE', 'Usa POST para ' . $accion . '.');
    }
    $cuerpo = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($cuerpo)) {
        throw new ApiError(400, 'DATOS_INVALIDOS', 'El cuerpo debe ser JSON.');
    }
    $solicitud = trim((string) ($cuerpo['solicitud_id'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $solicitud)) {
        throw new ApiError(400, 'DATOS_INVALIDOS', 'Falta solicitud_id o tiene caracteres no permitidos (máx. 64: letras, números . _ : -).');
    }
    $apiContexto['solicitud_id'] = $solicitud;
    $motivo = substr(trim((string) ($cuerpo['motivo'] ?? '')), 0, 100);
    $referencia = substr(trim((string) ($cuerpo['referencia'] ?? '')), 0, 100);
    $simular = ($cuerpo['simular'] ?? false) === true;
    if (isset($cuerpo['simular']) && !is_bool($cuerpo['simular'])) {
        throw new ApiError(400, 'DATOS_INVALIDOS', 'simular debe ser true o false.');
    }

    // Un solicitud_id solo se procesa una vez (con candado para llamadas simultáneas).
    if (!$simular) {
        $apiPdo->query("SELECT GET_LOCK(" . $apiPdo->quote('api-radius:' . $solicitud) . ", 10)");
        $st = $apiPdo->prepare('SELECT id_servicio, accion, respuesta FROM ubb_api_solicitudes WHERE solicitud_id = ?');
        $st->execute([$solicitud]);
        if ($previa = $st->fetch()) {
            if ((int) $previa['id_servicio'] !== $idServicio || $previa['accion'] !== $accion) {
                throw new ApiError(400, 'DATOS_INVALIDOS', 'Ese solicitud_id ya se usó para otra operación.');
            }
            $original = json_decode((string) $previa['respuesta'], true) ?: [];
            apiResponder(200, ['resultado' => 'REPETIDO', 'resultado_original' => $original['resultado'] ?? null] + $original
                + ['mensaje' => 'Esta solicitud ya se había procesado; no se repitió la acción.']);
        }
    }

    $eq = apiBuscarEquipo($apiPdo, $idServicio);
    $antes = apiEstado($apiPdo, $eq);
    $quiere = $accion === 'suspender' ? 'SUSPENDIDO' : 'ACTIVO';
    $base = [
        'solicitud_id' => $solicitud,
        'id_servicio' => $idServicio,
        'accion' => $accion,
        'estado_anterior' => $antes['estado'],
        'tipo' => $eq['tipo'],
        'ip' => $eq['ip'],
        'plan' => $antes['plan'],
    ];

    if ($antes['estado'] === $quiere) {
        $respuesta = ['resultado' => 'SIN_CAMBIOS', 'estado_actual' => $antes['estado']] + $base
            + ['mensaje' => 'El servicio ya estaba ' . strtolower($quiere) . '; no se cambió nada.'];
    } elseif ($simular) {
        apiResponder(200, ['resultado' => 'SIMULADO', 'estado_actual' => $antes['estado'], 'estado_si_se_aplica' => $quiere] + $base
            + ['mensaje' => 'Simulación: el servicio se ' . ($accion === 'suspender' ? 'suspendería' : 'reactivaría') . '. No se cambió nada.']);
    } else {
        try {
            $router = uispConectarRouter($eq['cfg']['router']);
            rdIdsLista($router, $eq['ip']);   // comprueba que el router responde antes de cambiar nada
        } catch (Throwable $e) {
            error_log('API RADIUS: router sin respuesta: ' . $e->getMessage());
            throw new ApiError(502, 'ROUTER_SIN_RESPUESTA', 'El router no respondió; no se cambió nada. Se puede reintentar con el mismo solicitud_id.');
        }
        try {
            $mensaje = $accion === 'suspender'
                ? rdSuspender($apiPdo, $router, $eq['mac'], API_ORIGEN)
                : rdReactivar($apiPdo, $router, $eq['cfg'], $eq['mac'], API_ORIGEN);
        } catch (RuntimeException $e) {
            // Solo las reglas del negocio (RuntimeException "simple", p. ej. una suspensión de UISP que no se
            // pudo quitar allá) se devuelven como 409: en esos casos no se cambió nada. Fallas de base de datos
            // o del router a medio camino se tratan como error interno.
            if (get_class($e) !== RuntimeException::class) {
                throw $e;
            }
            throw new ApiError(409, 'NO_APLICADO', $e->getMessage());
        }
        if ($motivo !== '' || $referencia !== '') {
            rdBitacora($apiPdo, $eq['mac'], $eq['ip'], $accion . '-pagos', trim("solicitud $solicitud $motivo $referencia"), API_ORIGEN);
        }
        $despues = apiEstado($apiPdo, ['lista' => rdLeerCliente($apiPdo, $eq['mac'])['lista']] + $eq);
        $respuesta = ['resultado' => 'APLICADO', 'estado_actual' => $despues['estado']] + $base
            + ['plan' => $despues['plan'], 'mensaje' => $mensaje];
    }

    $guardar = ['ok' => true] + $respuesta + ['fecha' => date('c')];
    $apiPdo->prepare('INSERT INTO ubb_api_solicitudes (solicitud_id, id_servicio, accion, respuesta) VALUES (?, ?, ?, ?)')
        ->execute([$solicitud, $idServicio, $accion, json_encode($guardar, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    apiResponder(200, $respuesta);
} catch (ApiError $e) {
    apiResponder($e->http, ['resultado' => $e->resultado, 'solicitud_id' => $apiContexto['solicitud_id'], 'id_servicio' => $apiContexto['id_servicio'], 'mensaje' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('API RADIUS: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    apiResponder(500, ['resultado' => 'ERROR_INTERNO', 'solicitud_id' => $apiContexto['solicitud_id'], 'id_servicio' => $apiContexto['id_servicio'], 'mensaje' => 'Falla inesperada; quedó registrada.']);
}
