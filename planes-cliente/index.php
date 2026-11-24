<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/layout-portal.php';

use RouterOS\Client;

use RouterOS\Query;

session_start();

date_default_timezone_set('America/Mexico_City');

// "Gestión de cambios" (solicitudes/historial) se retiró del menú; el código y la tabla historial_cambios
// se conservan. Los enlaces viejos llevan a la Bitácora.
$modulosPermitidos = ['inicio', 'catalogo', 'perfiles', 'radius-dhcp', 'radius-pppoe', 'bitacora'];
if (in_array($_GET['modulo'] ?? '', ['solicitudes', 'historial'], true)) {
    header('Location: ?modulo=bitacora');
    exit;
}
$moduloActual = (string) ($_GET['modulo'] ?? 'inicio');
if (!in_array($moduloActual, $modulosPermitidos, true)) {
    $moduloActual = 'inicio';
}

$mikrotikConfig = [

    'host' => getenv('MIKROTIK_HOST') ?: '10.0.1.6',

    'user' => getenv('MIKROTIK_USER') ?: 'api-ubb',

    // La contraseña debe definirse en la variable de entorno MIKROTIK_PASS.

    'pass' => trim((string) (getenv('MIKROTIK_PASS') ?: 'Grez75820')),

    'port' => (int) (getenv('MIKROTIK_PORT') ?: 8728),

    'timeout' => 5,

];

$dbConfig = [

    // Si DB_HOST no se establece, se utiliza el socket local de MariaDB montado en Docker.

    'host' => trim((string) (getenv('DB_HOST') ?: '')),

    'port' => (int) (getenv('DB_PORT') ?: 3306),

    'socket' => getenv('DB_SOCKET') ?: '/var/run/mysqld/mysqld.sock',

    'database' => 'ubb_gestion_planes',

    'user' => getenv('DB_USER') ?: '',

    'pass' => getenv('DB_PASS') ?: '',

];

function escapar($valor): string

{

    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');

}


function claseEstadoCambio(string $estado): string
{
    return match (strtoupper(trim($estado))) {
        'APLICADO', 'VALIDADO', 'AUTORIZADO', 'CORRECTA' => 'estado-ok',
        'ERROR', 'RECHAZADO', 'FALLIDA' => 'estado-error',
        default => 'estado-pendiente',
    };
}

function jsonRespuesta(array $datos, int $codigo = 200): never

{

    http_response_code($codigo);

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;

}

function obtenerPDO(array $config): PDO

{

    if ($config['user'] === '' || $config['pass'] === '') {

        throw new RuntimeException(

            'Configura DB_USER y DB_PASS para la aplicación PHP.'

        );

    }

    if ($config['host'] !== '') {

        $dsn = 'mysql:host=' . $config['host']

            . ';port=' . $config['port']

            . ';dbname=' . $config['database']

            . ';charset=utf8mb4';

    } else {

        if (!file_exists($config['socket'])) {

            throw new RuntimeException(

                'No se encuentra el socket de MariaDB dentro del contenedor. Monta /run/mysqld en Docker o configura DB_HOST.'

            );

        }

        $dsn = 'mysql:unix_socket=' . $config['socket']

            . ';dbname=' . $config['database']

            . ';charset=utf8mb4';

    }

    return new PDO(

        $dsn,

        $config['user'],

        $config['pass'],

        [

            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

            PDO::ATTR_EMULATE_PREPARES => false,

            PDO::ATTR_TIMEOUT => 5,

        ]

    );

}


function obtenerPDOJamileth(): PDO
{
    $archivo = '/etc/ubb/jamileth.pass';

    if (!is_readable($archivo)) {
        throw new RuntimeException('No se puede leer la credencial de Jamileth.');
    }

    $pass = rtrim((string) file_get_contents($archivo), "\r\n");

    if ($pass === '') {
        throw new RuntimeException('La credencial de Jamileth está vacía.');
    }

    return new PDO(
        'mysql:host=192.168.100.221;port=3306;dbname=ubb_plataforma;charset=utf8mb4',
        'equipo_ubb',
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]
    );
}

function crearClienteMikrotik(array $config): Client

{

    if ($config['pass'] === '') {

        throw new RuntimeException(

            'Configura MIKROTIK_PASS en el contenedor; la contraseña no debe quedar dentro de index.php.'

        );

    }

    return new Client($config);

}

function obtenerVelocidad(string $rateLimit): string

{

    $partes = preg_split('/\s+/', trim($rateLimit));

    $velocidad = $partes[0] ?? '';

    return $velocidad !== ''

        ? str_replace('/', ' / ', $velocidad)

        : 'No definida';

}

function formatearDuracionRadius($segundos): string
{
    if ($segundos === null || $segundos === '') {
        return '—';
    }

    $total = max(0, (int) $segundos);
    $dias = intdiv($total, 86400);
    $horas = intdiv($total % 86400, 3600);
    $minutos = intdiv($total % 3600, 60);
    $seg = $total % 60;

    if ($dias > 0) {
        return $dias . ' d ' . $horas . ' h';
    }
    if ($horas > 0) {
        return $horas . ' h ' . $minutos . ' min';
    }
    if ($minutos > 0) {
        return $minutos . ' min ' . $seg . ' s';
    }
    return $seg . ' s';
}

function formatearFechaRadius($fecha): string
{
    if ($fecha === null || trim((string) $fecha) === '') {
        return '—';
    }
    $marca = strtotime((string) $fecha);
    return $marca === false ? '—' : date('d/m/Y H:i:s', $marca);
}

function separarVelocidades(string $rateLimit): array

{

    $partes = preg_split('/\s+/', trim($rateLimit));

    $velocidades = explode('/', $partes[0] ?? '');

    return [

        'subida' => trim($velocidades[0] ?? ''),

        'bajada' => trim($velocidades[1] ?? ''),

    ];

}

function obtenerNombreComercialPerfil(string $perfil): string

{

    return match (strtolower(trim($perfil))) {

        'pro-pppoe18-20m' => 'Básico',

        'pro-pppoe18-30m' => 'Intermedio',

        'pro-pppoe18-60m' => 'Avanzado',

        default => 'Plan por confirmar',

    };

}

function perfilVisibleParaUsuario(string $perfil): bool

{

    return in_array(

        strtolower(trim($perfil)),

        [

            'pro-pppoe18-20m',

            'pro-pppoe18-30m',

            'pro-pppoe18-60m',

        ],

        true

    );

}

/*

 * Recupera los usuarios PPPoE de clientes_servicios.

 * Incluye registros que todavía no tengan id_servicio.

 * Si existen varios registros para el mismo PPPoE, conserva

 * una relación válida antes que un registro provisional.

 */


function obtenerMapaServicios(PDO $pdoJamileth): array
{
    $sql = "
        SELECT
            s.id_servicio,
            CONCAT_WS(' ',
                c.nombre,
                c.apellido_paterno,
                c.apellido_materno
            ) AS nombre_cliente,
            s.paquete_referencia,
            s.tecnologia,
            s.estado AS estado_servicio,
            i.pppoe_name,
            i.perfil_pppoe,
            COALESCE(
                NULLIF(TRIM(i.remote_address), ''),
                NULLIF(TRIM(i.ip), ''),
                ''
            ) AS ip
        FROM integracion_winbox i
        INNER JOIN servicios s
            ON s.id_servicio = i.id_servicio
            AND s.id_cliente = i.id_cliente
        INNER JOIN clientes c
            ON c.id_cliente = s.id_cliente
        WHERE i.estado_vinculacion = 'RELACIONADO'
          AND i.tipo_fuente IN ('PPPOE', 'DHCP_PPPOE')
          AND i.pppoe_name IS NOT NULL
          AND TRIM(i.pppoe_name) <> ''
        ORDER BY
            CASE WHEN i.tipo_fuente = 'PPPOE' THEN 0 ELSE 1 END,
            i.id_integracion ASC
    ";

    $mapa = [];
    $ambiguos = [];

    foreach ($pdoJamileth->query($sql) as $fila) {
        $nombrePPPoE = trim((string) ($fila['pppoe_name'] ?? ''));

        if ($nombrePPPoE === '') {
            continue;
        }

        $clave = mb_strtolower($nombrePPPoE, 'UTF-8');

        if (isset($ambiguos[$clave])) {
            continue;
        }

        $idNuevo = trim((string) ($fila['id_servicio'] ?? ''));

        $registroNuevo = [
            'id_servicio' => $idNuevo,
            'nombre_cliente' => (string) ($fila['nombre_cliente'] ?? ''),
            'paquete_referencia' => (string) ($fila['paquete_referencia'] ?? ''),
            'tecnologia' => (string) ($fila['tecnologia'] ?? ''),
            'estado_servicio' => (string) ($fila['estado_servicio'] ?? ''),
            'perfil_bd' => (string) ($fila['perfil_pppoe'] ?? ''),
            'ip' => (string) ($fila['ip'] ?? ''),
        ];

        if (!isset($mapa[$clave])) {
            $mapa[$clave] = $registroNuevo;
            continue;
        }

        $idExistente = trim((string) ($mapa[$clave]['id_servicio'] ?? ''));

        if ($idExistente !== '' && $idNuevo !== '' && $idExistente !== $idNuevo) {
            unset($mapa[$clave]);
            $ambiguos[$clave] = true;
            continue;
        }

        if ($idExistente === '' && $idNuevo !== '') {
            $mapa[$clave] = $registroNuevo;
        }
    }

    return $mapa;
}

/**
 * Devuelve el estado de vinculación de cada cuenta PPPoE según Jamileth.
 * Solo considera RELACIONADO cuando cliente y servicio existen y pertenecen
 * entre sí. Deduplica filas idénticas; nunca elige entre pares de IDs distintos.
 */
function obtenerMapaEstadoPppoe(PDO $pdoJamileth): array
{
    $sql = "
        SELECT
            i.pppoe_name,
            i.tipo_fuente,
            i.estado_vinculacion,
            i.id_cliente,
            i.id_servicio,
            c.id_cliente AS cliente_existente,
            s.id_servicio AS servicio_existente,
            s.id_cliente AS servicio_id_cliente
        FROM integracion_winbox i
        LEFT JOIN clientes c
            ON c.id_cliente = i.id_cliente
        LEFT JOIN servicios s
            ON s.id_servicio = i.id_servicio
           AND s.id_cliente = i.id_cliente
        WHERE i.tipo_fuente IN ('PPPOE', 'DHCP_PPPOE')
          AND i.pppoe_name IS NOT NULL
          AND TRIM(i.pppoe_name) <> ''
        ORDER BY i.id_integracion
    ";

    $grupos = [];
    foreach ($pdoJamileth->query($sql) as $fila) {
        $nombre = mb_strtolower(trim((string) ($fila['pppoe_name'] ?? '')), 'UTF-8');
        if ($nombre === '') {
            continue;
        }
        $grupos[$nombre][] = $fila;
    }

    $mapa = [];
    foreach ($grupos as $nombre => $filas) {
        $paresValidos = [];
        $hayPendiente = false;
        $hayRelacionadoInvalido = false;
        $tiposValidos = [];

        foreach ($filas as $fila) {
            $estado = strtoupper(trim((string) ($fila['estado_vinculacion'] ?? '')));
            $tipo = strtoupper(trim((string) ($fila['tipo_fuente'] ?? '')));
            if ($estado === 'PENDIENTE') {
                $hayPendiente = true;
            }

            $idCliente = trim((string) ($fila['id_cliente'] ?? ''));
            $idServicio = trim((string) ($fila['id_servicio'] ?? ''));
            $valido = $estado === 'RELACIONADO'
                && $idCliente !== '' && $idCliente !== '0'
                && $idServicio !== '' && $idServicio !== '0'
                && !empty($fila['cliente_existente'])
                && !empty($fila['servicio_existente'])
                && (string) $fila['servicio_id_cliente'] === $idCliente;

            if ($valido) {
                $clavePar = $idCliente . '|' . $idServicio;
                $paresValidos[$clavePar] = $fila;
                $tiposValidos[$tipo] = true;
            } elseif ($estado === 'RELACIONADO') {
                $hayRelacionadoInvalido = true;
            }
        }

        if (count($paresValidos) > 1) {
            $estadoFinal = 'RELACION_AMBIGUA';
            $filaFinal = null;
        } elseif (count($paresValidos) === 1) {
            $filaFinal = reset($paresValidos);
            $estadoFinal = isset($tiposValidos['PPPOE'])
                ? 'RELACIONADO'
                : 'RELACIONADO_DHCP_PPPOE';
        } elseif ($hayPendiente) {
            $estadoFinal = 'PENDIENTE';
            $filaFinal = $filas[0];
        } elseif ($hayRelacionadoInvalido) {
            $estadoFinal = 'REVISAR_RELACION';
            $filaFinal = $filas[0];
        } else {
            $estadoFinal = 'SIN_REGISTRO_VALIDO';
            $filaFinal = $filas[0];
        }

        $mapa[$nombre] = [
            'estado' => $estadoFinal,
            'tipo_fuente' => (string) ($filaFinal['tipo_fuente'] ?? ''),
            'id_cliente' => $estadoFinal === 'RELACIONADO' || $estadoFinal === 'RELACIONADO_DHCP_PPPOE'
                ? (string) ($filaFinal['id_cliente'] ?? '') : '',
            'id_servicio' => $estadoFinal === 'RELACIONADO' || $estadoFinal === 'RELACIONADO_DHCP_PPPOE'
                ? (string) ($filaFinal['id_servicio'] ?? '') : '',
        ];
    }

    return $mapa;
}

/*
 * Recupera la integración DHCP desde Jamileth en modo de solo lectura.
 * La clave técnica es servidor DHCP + MAC; la MAC por sí sola no es única
 * entre todos los servidores. Solo se considera relacionada una fila cuya
 * relación servicio-cliente existe realmente en ambas tablas.
 */
function obtenerMapaIntegracionDhcp(PDO $pdoJamileth): array
{
    $sql = "
        SELECT
            i.mac,
            i.ip,
            i.hostname,
            i.servidor_dhcp,
            i.id_cliente,
            i.id_servicio,
            i.estado_vinculacion,
            s.id_servicio AS servicio_existente,
            c.id_cliente AS cliente_existente,
            CONCAT_WS(' ', c.nombre, c.apellido_paterno, c.apellido_materno) AS nombre_cliente,
            s.tecnologia,
            s.estado AS estado_servicio
        FROM integracion_winbox i
        LEFT JOIN servicios s
            ON s.id_servicio = i.id_servicio
            AND s.id_cliente = i.id_cliente
        LEFT JOIN clientes c
            ON c.id_cliente = i.id_cliente
        WHERE i.tipo_fuente = 'DHCP'
          AND i.mac IS NOT NULL
          AND TRIM(i.mac) <> ''
          AND i.servidor_dhcp IS NOT NULL
          AND TRIM(i.servidor_dhcp) <> ''
        ORDER BY i.servidor_dhcp, i.mac, i.id_integracion
    ";

    $mapa = [];
    $ambiguos = [];

    foreach ($pdoJamileth->query($sql) as $fila) {
        $servidor = trim((string) ($fila['servidor_dhcp'] ?? ''));
        $mac = strtoupper(trim((string) ($fila['mac'] ?? '')));
        if ($servidor === '' || $mac === '') {
            continue;
        }

        $clave = strtolower($servidor) . '|' . $mac;
        if (isset($ambiguos[$clave])) {
            continue;
        }

        $idCliente = trim((string) ($fila['id_cliente'] ?? ''));
        $idServicio = trim((string) ($fila['id_servicio'] ?? ''));
        $relacionValida = strtoupper((string) ($fila['estado_vinculacion'] ?? '')) === 'RELACIONADO'
            && $idCliente !== ''
            && $idServicio !== ''
            && !empty($fila['cliente_existente'])
            && !empty($fila['servicio_existente']);

        $registro = [
            'id_cliente' => $relacionValida ? $idCliente : '',
            'id_servicio' => $relacionValida ? $idServicio : '',
            'nombre_cliente' => $relacionValida ? (string) ($fila['nombre_cliente'] ?? '') : '',
            'tecnologia' => $relacionValida ? (string) ($fila['tecnologia'] ?? '') : '',
            'estado_servicio' => $relacionValida ? (string) ($fila['estado_servicio'] ?? '') : '',
            'hostname' => (string) ($fila['hostname'] ?? ''),
            'ip_registrada' => (string) ($fila['ip'] ?? ''),
            'estado_integracion' => $relacionValida
                ? 'RELACIONADO'
                : (strtoupper((string) ($fila['estado_vinculacion'] ?? '')) === 'PENDIENTE'
                    ? 'PENDIENTE'
                    : 'REVISAR RELACIÓN'),
            'relacion_valida' => $relacionValida,
        ];

        if (!isset($mapa[$clave])) {
            $mapa[$clave] = $registro;
            continue;
        }

        $existente = $mapa[$clave];
        $idsDiferentes = $existente['relacion_valida'] && $relacionValida
            && ($existente['id_cliente'] !== $idCliente || $existente['id_servicio'] !== $idServicio);

        if ($idsDiferentes) {
            unset($mapa[$clave]);
            $ambiguos[$clave] = true;
            continue;
        }

        if (!$existente['relacion_valida'] && $relacionValida) {
            $mapa[$clave] = $registro;
        }
    }

    foreach ($ambiguos as $clave => $_) {
        $mapa[$clave] = [
            'id_cliente' => '',
            'id_servicio' => '',
            'nombre_cliente' => '',
            'tecnologia' => '',
            'estado_servicio' => '',
            'hostname' => '',
            'ip_registrada' => '',
            'estado_integracion' => 'RELACIÓN AMBIGUA',
            'relacion_valida' => false,
        ];
    }

    return $mapa;
}


/* CLIENTES DHCP EN RADIUS: alta, suspension y reactivacion (modulo aparte). */
if ($moduloActual === 'radius-dhcp') {
    require __DIR__ . '/modulo-radius-dhcp.php';
    exit;
}

/* BITÁCORA: historial de cambios y de llamadas a la API (solo consulta). */
if ($moduloActual === 'bitacora') {
    require __DIR__ . '/modulo-bitacora.php';
    exit;
}

/* CLIENTES PPPoE EN RADIUS (fase 1: solo consulta). */
if ($moduloActual === 'radius-pppoe') {
    require __DIR__ . '/modulo-radius-pppoe.php';
    exit;
}

$pdo = null; // Modo consulta: no se abre conexión a inventario ni se escriben avisos.
$pdoJamileth = null;
$errorConexion = null;
$errorBaseDatos = null;
$errorAvisos = null;
$clientes = [];
$perfiles = [];
$eventos = [];
$mapaServicios = [];
$mapaEstadoPppoe = [];
$jamilethPppoeDisponible = false;
$mapaInventarioPppoe = [];
$mapaInventarioServicios = [];
$inventarioPppoeDisponible = false;
$errorInventarioPppoe = null;
$usuariosDuplicadosMikrotik = [];
$totalConectados = 0;
$usuariosActivos = [];
$mapaIntegracionDhcp = [];
$concesionesDhcp = [];
$errorIntegracionDhcp = null;
$errorConsultaDhcp = null;
$dhcpConRelacion = 0;
$dhcpPendientes = 0;
$dhcpBound = 0;
$dhcpConListaRadius = 0;

try {
    $pdoJamileth = obtenerPDOJamileth();
    $mapaServicios = obtenerMapaServicios($pdoJamileth);
    $mapaEstadoPppoe = obtenerMapaEstadoPppoe($pdoJamileth);
    $jamilethPppoeDisponible = true;
} catch (Throwable $e) {
    error_log('UBB planes: error al consultar Jamileth: ' . $e->getMessage());
    $errorBaseDatos = 'No se pudo consultar la base de clientes. Verifica el acceso de solo lectura.';
    $mapaServicios = [];
}

// Inventario: lectura opcional. No se intenta escribir ni conceder permisos aquí.
try {
    $configInventario = $dbConfig;
    $configInventario['database'] = 'inventario';
    $pdoInventario = obtenerPDO($configInventario);

    try {
        foreach ($pdoInventario->query("
            SELECT name
            FROM pppoe_secrets
            WHERE name IS NOT NULL AND TRIM(name) <> ''
        ") as $filaInventario) {
            $nombreInventario = mb_strtolower(trim((string) $filaInventario['name']), 'UTF-8');
            if ($nombreInventario !== '') {
                $mapaInventarioPppoe[$nombreInventario] = true;
            }
        }
        $inventarioPppoeDisponible = true;
    } catch (Throwable $e) {
        error_log('UBB PPPoE: sin permiso de lectura para inventario.pppoe_secrets: ' . $e->getMessage());
        $errorInventarioPppoe = 'La aplicación todavía no puede consultar inventario.pppoe_secrets; el estado de inventario se marcará como no verificado.';
    }

    // Esta tabla sí es una referencia de servicios, no sustituye al padrón pppoe_secrets.
    try {
        foreach ($pdoInventario->query("
            SELECT pppoe_name, id_cliente, id_servicio
            FROM clientes_servicios
            WHERE pppoe_name IS NOT NULL AND TRIM(pppoe_name) <> ''
        ") as $filaServicioInventario) {
            $nombreInventario = mb_strtolower(trim((string) $filaServicioInventario['pppoe_name']), 'UTF-8');
            if ($nombreInventario !== '') {
                $mapaInventarioServicios[$nombreInventario] = [
                    'id_cliente' => (string) ($filaServicioInventario['id_cliente'] ?? ''),
                    'id_servicio' => (string) ($filaServicioInventario['id_servicio'] ?? ''),
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('UBB PPPoE: no se pudo consultar inventario.clientes_servicios: ' . $e->getMessage());
    }
} catch (Throwable $e) {
    $errorInventarioPppoe = 'No hay conexión de lectura con inventario; sus registros no se usarán para clasificar cuentas.';
    error_log('UBB PPPoE: error de conexión con inventario: ' . $e->getMessage());
}

// El historial de avisos no se consulta desde inventario en esta versión.
$errorAvisos = 'Actividad reciente pendiente de integrar con una fuente autorizada.';


/* CATALOGO COMERCIAL UBB: SOLO LECTURA */

$pdoCatalogo = null;
$planesCatalogo = [];
$velocidadesPorPlan = [];
$planesPorServicio = [];
$errorCatalogo = null;

try {
    $configCatalogo = $dbConfig;
    $configCatalogo['database'] = 'ubb_gestion_planes';

    $pdoCatalogo = obtenerPDO($configCatalogo);

    $sqlCatalogo = "
        SELECT
            p.id_plan,
            p.nombre_plan,
            p.precio_mensual,
            p.estado,
            v.tecnologia,
            v.velocidad_mbps,
            v.velocidad_bajada_mbps,
            v.velocidad_subida_mbps
        FROM planes p
        LEFT JOIN plan_velocidades v
            ON v.id_plan = p.id_plan
        WHERE p.estado = 'ACTIVO'
        ORDER BY p.id_plan,
            CASE
                WHEN v.tecnologia = 'INALAMBRICO' THEN 1
                WHEN v.tecnologia = 'FIBRA' THEN 2
                ELSE 3
            END
    ";

    $filasCatalogo = $pdoCatalogo->query($sqlCatalogo)->fetchAll();
    $planesAgrupados = [];

    foreach ($filasCatalogo as $fila) {
        $id = (int) $fila['id_plan'];

        if (!isset($planesAgrupados[$id])) {
            $planesAgrupados[$id] = [
                'nombre_plan' => $fila['nombre_plan'],
                'precio_mensual' => $fila['precio_mensual'],
                'estado' => $fila['estado'],
                'velocidades' => [],
                'velocidades_detalle' => []
            ];
        }

        $tecnologia = strtoupper(trim((string) ($fila['tecnologia'] ?? '')));

        if (
            in_array($tecnologia, ['INALAMBRICO', 'FIBRA'], true)
            && $fila['velocidad_mbps'] !== null
        ) {
            $planesAgrupados[$id]['velocidades'][$tecnologia] =
                (float) $fila['velocidad_mbps'];

            $planesAgrupados[$id]['velocidades_detalle'][$tecnologia] = [
                'descarga' => $fila['velocidad_bajada_mbps'] !== null
                    ? (float) $fila['velocidad_bajada_mbps'] : null,
                'subida' => $fila['velocidad_subida_mbps'] !== null
                    ? (float) $fila['velocidad_subida_mbps'] : null,
            ];
        }
    }

    $velocidadesPorPlan = $planesAgrupados;
    $planesCatalogo = array_values($planesAgrupados);

    $sqlAsignaciones = "
        SELECT
            sp.id_servicio,
            sp.id_plan,
            sp.estado_asignacion,
            p.nombre_plan,
            p.precio_mensual,
            p.estado AS estado_plan
        FROM servicios_planes sp
        INNER JOIN planes p
            ON p.id_plan = sp.id_plan
        ORDER BY sp.id_servicio
    ";

    foreach ($pdoCatalogo->query($sqlAsignaciones) as $fila) {
        $planesPorServicio[(string) $fila['id_servicio']] = [
            'id_plan' => (int) $fila['id_plan'],
            'nombre_plan' => (string) $fila['nombre_plan'],
            'precio_mensual' => $fila['precio_mensual'],
            'estado_asignacion' => (string) $fila['estado_asignacion'],
            'estado_plan' => (string) $fila['estado_plan'],
        ];
    }

} catch (Throwable $e) {
    error_log('UBB planes: error en catálogo comercial: ' . $e->getMessage());
    $errorCatalogo = 'No fue posible consultar el catálogo comercial.';
}


/* HISTORIAL: CONSULTA DE SOLO LECTURA */
$historialCambios = [];
$errorHistorial = null;

if ($moduloActual === 'historial') {
    try {
        if (!($pdoCatalogo instanceof PDO)) {
            throw new RuntimeException('Sin conexión a ubb_gestion_planes');
        }

        $sqlHistorial = "
            SELECT h.*,
                   pa.nombre_plan AS plan_anterior,
                   pn.nombre_plan AS plan_nuevo,
                   ppa.nombre_perfil AS perfil_anterior,
                   ppn.nombre_perfil AS perfil_nuevo
            FROM historial_cambios h
            LEFT JOIN planes pa ON pa.id_plan = h.id_plan_anterior
            LEFT JOIN planes pn ON pn.id_plan = h.id_plan_nuevo
            LEFT JOIN perfiles_mikrotik ppa ON ppa.id_perfil = h.id_perfil_anterior
            LEFT JOIN perfiles_mikrotik ppn ON ppn.id_perfil = h.id_perfil_nuevo
            ORDER BY h.fecha_cambio DESC, h.id_cambio DESC
            LIMIT 200
        ";

        $historialCambios = $pdoCatalogo->query($sqlHistorial)->fetchAll();
    } catch (Throwable $e) {
        error_log('Error consultando historial UBB: ' . $e->getMessage());
        $errorHistorial = 'No fue posible consultar el historial de cambios.';
    }
}

/* API: bloqueada en esta entrega de modo consulta. */

if (isset($_GET['api'])) {
    jsonRespuesta([
        'ok' => false,
        'error' => 'Esta pantalla está en modo consulta. No se permiten cambios en MikroTik, RADIUS ni bases de datos.',
    ], 423);
}

/* Consulta de perfiles y cuentas PPPoE en MikroTik */

try {

    $router = crearClienteMikrotik($mikrotikConfig);

    $respuestaPerfiles = $router

        ->query(new Query('/ppp/profile/print'))

        ->read();

    foreach ($respuestaPerfiles as $perfil) {

        $nombre = (string) ($perfil['name'] ?? '');

        if ($nombre === '') {

            continue;

        }

        $perfiles[] = [

            'nombre' => $nombre,

            'rate_limit' => (string) ($perfil['rate-limit'] ?? ''),

            'velocidad' => obtenerVelocidad(

                (string) ($perfil['rate-limit'] ?? '')

            ),

        ];

    }

    $mapaCallerIdActivo = [];
    $usuariosActivos = [];

    $respuestaActivos = $router

        ->query(new Query('/ppp/active/print'))

        ->read();

    foreach ($respuestaActivos as $activo) {

        $usuarioActivo = mb_strtolower(

            trim((string) ($activo['name'] ?? '')),

            'UTF-8'

        );

        $macActiva = trim((string) ($activo['caller-id'] ?? ''));

        if ($usuarioActivo !== '') {
            $usuariosActivos[$usuarioActivo] = true;
        }

        if ($usuarioActivo !== '' && $macActiva !== '') {

            $mapaCallerIdActivo[$usuarioActivo][] = $macActiva;

        }

    }

    $totalConectados = count($usuariosActivos);

    $respuestaClientes = $router

        ->query(new Query('/ppp/secret/print'))

        ->read();

    // Una fila por nombre PPPoE; si RouterOS devuelve el mismo nombre más de una vez,
    // se conserva una fila para el directorio y se marca explícitamente para revisión.
    $clientesRouterUnicos = [];
    foreach ($respuestaClientes as $registroRouter) {
        $nombreRouter = trim((string) ($registroRouter['name'] ?? ''));
        if ($nombreRouter === '') {
            continue;
        }
        $claveRouter = mb_strtolower($nombreRouter, 'UTF-8');
        if (isset($clientesRouterUnicos[$claveRouter])) {
            $usuariosDuplicadosMikrotik[$claveRouter] = true;
            continue;
        }
        $clientesRouterUnicos[$claveRouter] = $registroRouter;
    }

    foreach ($clientesRouterUnicos as $claveUsuario => $cliente) {

        $usuario = (string) ($cliente['name'] ?? '');

        if ($usuario === '') {

            continue;

        }

        $perfilActual = (string) ($cliente['profile'] ?? 'default');

        $perfilEncontrado = null;

        foreach ($perfiles as $perfil) {

            if ($perfil['nombre'] === $perfilActual) {

                $perfilEncontrado = $perfil;

                break;

            }

        }

        $servicio = $mapaServicios[$claveUsuario] ?? null;
        $estadoJamileth = $mapaEstadoPppoe[$claveUsuario] ?? null;

        $callerIdsActivos = array_values(array_unique(

            $mapaCallerIdActivo[$claveUsuario] ?? []

        ));

        $callerIdActual = implode(', ', $callerIdsActivos);

        if ($callerIdActual === '') {

            // caller-id es la restricción configurada; last-caller-id es el último equipo que usó la cuenta.
            $callerIdActual = trim((string) ($cliente['caller-id'] ?? ''));

            if ($callerIdActual === '') {
                $callerIdActual = trim((string) ($cliente['last-caller-id'] ?? ''));
            }

        }

        $idServicioCliente = trim(
            (string) ($servicio['id_servicio'] ?? ($estadoJamileth['id_servicio'] ?? ''))
        );

        $asignacionComercial = $idServicioCliente !== ''
            ? ($planesPorServicio[$idServicioCliente] ?? null)
            : null;

        $paqueteJamileth = trim(
            (string) ($servicio['paquete_referencia'] ?? '')
        );

        $planMostrar = $asignacionComercial['nombre_plan']
            ?? (
                $paqueteJamileth !== ''
                    ? $paqueteJamileth
                    : (!empty($servicio['id_servicio'])
                        ? 'Paquete sin registrar'
                        : 'Plan por confirmar')
            );

        $clientes[] = [

            'usuario' => $usuario,

            'service' => (string) ($cliente['service'] ?? 'pppoe'),

            'perfil' => $perfilActual,

            'plan' => $planMostrar,

            'precio_mensual' => $asignacionComercial['precio_mensual'] ?? null,

            'estado_asignacion_comercial' =>
                $asignacionComercial['estado_asignacion'] ?? '',

            'velocidad' => $perfilEncontrado['velocidad'] ?? 'No definida',

            'remote_address' => (string) (
                trim((string) ($cliente['remote-address'] ?? '')) !== ''
                    ? $cliente['remote-address']
                    : ($servicio['ip'] ?? '')
            ),

            'caller_id' => $callerIdActual,

            'disabled' => ($cliente['disabled'] ?? 'false') === 'true',

            'comment' => (string) ($cliente['comment'] ?? ''),

            'id_servicio' => $servicio['id_servicio'] ?? '',
            'tecnologia' => $servicio['tecnologia'] ?? '',
            'estado_servicio' => $servicio['estado_servicio'] ?? '',

            'nombre_cliente' => $servicio['nombre_cliente'] ?? '',

            'estado_integracion' => $estadoJamileth['estado'] ?? (
                $jamilethPppoeDisponible ? 'SIN_REGISTRO_JAMILETH' : 'NO_VERIFICABLE_JAMILETH'
            ),

            'tipo_fuente_jamileth' => $estadoJamileth['tipo_fuente'] ?? '',

            'estado_inventario' => $inventarioPppoeDisponible
                ? (isset($mapaInventarioPppoe[$claveUsuario]) ? 'EN PPPOE_SECRETS' : 'NO EN PPPOE_SECRETS')
                : (isset($mapaInventarioServicios[$claveUsuario])
                    ? 'REFERENCIA EN CLIENTES_SERVICIOS'
                    : 'NO VERIFICADO'),

            'inventario_servicio_id' => $mapaInventarioServicios[$claveUsuario]['id_servicio'] ?? '',

            'duplicado_mikrotik' => isset($usuariosDuplicadosMikrotik[$claveUsuario]),

        ];

    }

    usort(

        $clientes,

        fn($a, $b) => strcasecmp($a['usuario'], $b['usuario'])

    );

    usort(

        $perfiles,

        fn($a, $b) => strcasecmp($a['nombre'], $b['nombre'])

    );

} catch (Throwable $e) {

    error_log('UBB planes: error de conexión MikroTik: ' . $e->getMessage());

    $errorConexion = 'No fue posible consultar MikroTik. Verifica MIKROTIK_HOST, MIKROTIK_USER y MIKROTIK_PASS.';

}

// Relaciona cuentas y concesiones con atributos RADIUS en modo de solo lectura.
$identidadesRadius = [];
$atributosRadius = [];
$errorConsultaRadius = null;
$pdoRadiusRead = null;
try {
    $radiusReadConfig = $dbConfig;
    $radiusReadConfig['database'] = 'radius';
    $pdoRadiusRead = obtenerPDO($radiusReadConfig);

    $identidadesRadius = [];
    foreach ($pdoRadiusRead->query("SELECT username FROM radcheck") as $filaRadius) {
        $usuarioRadius = strtoupper(trim((string) ($filaRadius['username'] ?? '')));
        if ($usuarioRadius !== '') {
            $identidadesRadius[$usuarioRadius] = true;
        }
    }

    $atributosRadius = [];
    $sqlAtributosRadius = "SELECT username, attribute, value FROM radreply WHERE attribute IN ('Mikrotik-Rate-Limit', 'Mikrotik-Address-List', 'Mikrotik-Group')";
    foreach ($pdoRadiusRead->query($sqlAtributosRadius) as $filaRadius) {
        $usuarioRadius = strtoupper(trim((string) ($filaRadius['username'] ?? '')));
        $atributoRadius = (string) ($filaRadius['attribute'] ?? '');
        if ($usuarioRadius === '' || $atributoRadius === '') {
            continue;
        }
        $atributosRadius[$usuarioRadius][$atributoRadius][] = (string) ($filaRadius['value'] ?? '');
    }

    foreach ($clientes as &$clienteRadius) {
        // En PPPoE, FreeRADIUS identifica la cuenta por el nombre del secret.
        // La MAC (caller-id) es un dato técnico adicional, no el username PPPoE.
        $usuarioRadius = strtoupper(trim((string) ($clienteRadius['usuario'] ?? '')));
        $rateRows = $usuarioRadius !== '' ? ($atributosRadius[$usuarioRadius]['Mikrotik-Rate-Limit'] ?? []) : [];
        $listRows = $usuarioRadius !== '' ? ($atributosRadius[$usuarioRadius]['Mikrotik-Address-List'] ?? []) : [];
        $confirmada = $usuarioRadius !== ''
            && isset($identidadesRadius[$usuarioRadius])
            && count($rateRows) === 1;

        $clienteRadius['radius_username'] = $usuarioRadius;
        $clienteRadius['radius_identidad_confirmada'] = $confirmada;
        $clienteRadius['radius_rate_limit'] = count($rateRows) === 1 ? (string) $rateRows[0] : '';
        $clienteRadius['radius_lista'] = count($listRows) === 1 ? (string) $listRows[0] : '';
        $clienteRadius['velocidad_mostrar'] = $confirmada
            ? obtenerVelocidad((string) $rateRows[0])
            : (string) ($clienteRadius['velocidad'] ?? 'No definida');
    }
    unset($clienteRadius);
} catch (Throwable $e) {
    error_log('UBB planes: no se pudo consultar identidad RADIUS: ' . $e->getMessage());
    $errorConsultaRadius = 'No se pudo consultar RADIUS; no se habilitarán cambios hasta resolver la consulta.';
    $identidadesRadius = [];
    $atributosRadius = [];
    foreach ($clientes as &$clienteRadius) {
        $clienteRadius['radius_username'] = strtoupper(trim((string) ($clienteRadius['usuario'] ?? '')));
        $clienteRadius['radius_identidad_confirmada'] = false;
        $clienteRadius['radius_rate_limit'] = '';
        $clienteRadius['radius_lista'] = '';
        $clienteRadius['velocidad_mostrar'] = (string) ($clienteRadius['velocidad'] ?? 'No definida');
    }
    unset($clienteRadius);
}


/* SOLICITUDES: DATOS Y REGISTRO CONTROLADO */
$relacionesSolicitud = [];
$planesSolicitud = [];
$serviciosSolicitud = [];
$errorRelacionesSolicitud = null;
$errorFormularioSolicitud = null;
$flashSolicitud = null;

try {
    if (!($pdoCatalogo instanceof PDO)) {
        throw new RuntimeException('No hay conexión con ubb_gestion_planes.');
    }

    $sqlRelaciones = "
        SELECT
            pp.id_plan,
            pp.id_perfil,
            pp.estado_validacion,
            p.nombre_plan,
            pm.nombre_perfil,
            pm.rate_limit,
            v.velocidad_bajada_mbps,
            v.velocidad_subida_mbps
        FROM plan_perfiles pp
        INNER JOIN planes p
            ON p.id_plan = pp.id_plan
        INNER JOIN perfiles_mikrotik pm
            ON pm.id_perfil = pp.id_perfil
        LEFT JOIN plan_velocidades v
            ON v.id_plan = pp.id_plan
            AND BINARY v.tecnologia = BINARY pp.tecnologia
        WHERE pp.tecnologia = 'INALAMBRICO'
          AND pp.estado_validacion IN ('PENDIENTE', 'CONFIRMADO')
          AND p.estado = 'ACTIVO'
          AND pm.estado = 'ACTIVO'
        ORDER BY p.id_plan, pm.nombre_perfil
    ";

    $relacionesSolicitud = $pdoCatalogo->query($sqlRelaciones)->fetchAll();

    foreach ($relacionesSolicitud as $relacion) {
        $idPlan = (string) $relacion['id_plan'];
        $planesSolicitud[$idPlan] = [
            'id_plan' => $idPlan,
            'nombre' => $relacion['nombre_plan'],
            'descarga' => $relacion['velocidad_bajada_mbps'],
            'subida' => $relacion['velocidad_subida_mbps']
        ];
    }
} catch (Throwable $e) {
    error_log('UBB solicitudes: error al consultar relaciones: ' . $e->getMessage());
    $errorRelacionesSolicitud = 'No fue posible consultar las relaciones entre planes y perfiles.';
}

foreach ($clientes as $clienteSolicitud) {
    $id = trim((string) ($clienteSolicitud['id_servicio'] ?? ''));
    $asignacion = $id !== '' ? ($planesPorServicio[$id] ?? null) : null;

    if (
        $id === ''
        || !empty($clienteSolicitud['disabled'])
        || strtoupper(trim((string) ($clienteSolicitud['tecnologia'] ?? ''))) !== 'INALAMBRICO'
        || strtoupper(trim((string) ($clienteSolicitud['estado_servicio'] ?? ''))) !== 'ACTIVO'
        || !$asignacion
        || ($asignacion['estado_asignacion'] ?? '') !== 'ACTIVA'
    ) {
        continue;
    }

    $serviciosSolicitud[$id] = [
        'id_servicio' => $id,
        'nombre' => (string) ($clienteSolicitud['nombre_cliente'] ?? ''),
        'usuario' => (string) ($clienteSolicitud['usuario'] ?? ''),
        'plan_actual' => (string) ($asignacion['nombre_plan'] ?? '')
    ];
}

$csrfSolicitud = $_SESSION['csrf_solicitud'] ?? '';
if ($csrfSolicitud === '') {
    $csrfSolicitud = bin2hex(random_bytes(32));
    $_SESSION['csrf_solicitud'] = $csrfSolicitud;
}

if (isset($_SESSION['flash_solicitud'])) {
    $flashSolicitud = $_SESSION['flash_solicitud'];
    unset($_SESSION['flash_solicitud']);
}

/* Registro mediante el procedimiento almacenado. */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['accion'] ?? '') === 'registrar_solicitud'
) {
    try {
        if (!hash_equals($csrfSolicitud, (string) ($_POST['csrf_solicitud'] ?? ''))) {
            throw new RuntimeException('La sesión del formulario venció. Recarga la página.');
        }

        $idServicio = filter_var($_POST['id_servicio'] ?? '', FILTER_VALIDATE_INT);
        $idPlan = filter_var($_POST['id_plan_nuevo'] ?? '', FILTER_VALIDATE_INT);
        $idPerfil = filter_var($_POST['id_perfil_nuevo'] ?? '', FILTER_VALIDATE_INT);
        $responsable = trim((string) ($_POST['responsable'] ?? ''));
        $motivo = trim((string) ($_POST['motivo'] ?? ''));

        if (!$idServicio || !$idPlan || !$idPerfil) {
            throw new RuntimeException('Selecciona un servicio, plan y perfil válidos.');
        }

        if (!isset($serviciosSolicitud[(string) $idServicio])) {
            throw new RuntimeException('El servicio no está disponible para solicitar cambios.');
        }

        if ($responsable === '' || strlen($responsable) > 100) {
            throw new RuntimeException('Escribe un responsable válido (máximo 100 caracteres).');
        }

        if ($motivo === '' || strlen($motivo) > 255) {
            throw new RuntimeException('Escribe un motivo de hasta 255 caracteres.');
        }

        $relacionPermitida = false;
        foreach ($relacionesSolicitud as $relacion) {
            if (
                (int) $relacion['id_plan'] === $idPlan
                && (int) $relacion['id_perfil'] === $idPerfil
            ) {
                $relacionPermitida = true;
                break;
            }
        }

        if (!$relacionPermitida) {
            throw new RuntimeException('La combinación de plan y perfil no está disponible.');
        }

        if (!($pdoCatalogo instanceof PDO)) {
            throw new RuntimeException('No hay conexión con la base de gestión de planes.');
        }

        $stmt = $pdoCatalogo->prepare(
            'CALL sp_registrar_solicitud_cambio(?, ?, ?, ?, ?, ?)'
        );

        // Las cuentas que aparecen aquí provienen del directorio PPPoE.
        $stmt->execute([
            $idServicio,
            $idPlan,
            $idPerfil,
            'PPPOE',
            $responsable,
            $motivo
        ]);

        $resultado = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $stmt->closeCursor();

        $idCambio = (int) ($resultado['id_cambio'] ?? 0);
        if ($idCambio <= 0) {
            throw new RuntimeException('El procedimiento no devolvió un folio de solicitud.');
        }

        $_SESSION['flash_solicitud'] = [
            'id' => $idCambio,
            'tipo' => 'success'
        ];

        header('Location: ?modulo=solicitudes');
        exit;

    } catch (Throwable $e) {
        error_log('UBB solicitudes: ' . $e->getMessage());

        if ($e instanceof PDOException && ($e->errorInfo[0] ?? '') === '45000') {
            $errorFormularioSolicitud = (string) ($e->errorInfo[2] ?? 'La base de datos rechazó la solicitud.');
        } elseif ($e instanceof RuntimeException) {
            $errorFormularioSolicitud = $e->getMessage();
        } else {
            $errorFormularioSolicitud = 'No fue posible registrar la solicitud. Revisa la conexión y vuelve a intentarlo.';
        }
    }
}


$totalClientes = count($clientes);

$clientesHabilitados = 0;

$clientesDeshabilitados = 0;

$clientesRelacionados = 0;
$clientesPppoePendientes = 0;
$clientesSinRegistroJamileth = 0;
$clientesRelacionadosHibridos = 0;
$clientesRelacionAmbigua = 0;
$clientesRevisarRelacion = 0;
$clientesDuplicadosMikrotik = 0;
$clientesInventarioEnSecrets = 0;
$usoPerfiles = [];

foreach ($clientes as $cliente) {

    if ($cliente['disabled']) {

        $clientesDeshabilitados++;

    } else {

        $clientesHabilitados++;

    }

    switch ($cliente['estado_integracion']) {
        case 'RELACIONADO':
            $clientesRelacionados++;
            break;
        case 'RELACIONADO_DHCP_PPPOE':
            $clientesRelacionadosHibridos++;
            break;
        case 'PENDIENTE':
            $clientesPppoePendientes++;
            break;
        case 'SIN_REGISTRO_JAMILETH':
            $clientesSinRegistroJamileth++;
            break;
        case 'RELACION_AMBIGUA':
            $clientesRelacionAmbigua++;
            break;
        case 'REVISAR_RELACION':
            $clientesRevisarRelacion++;
            break;
    }

    if (!empty($cliente['duplicado_mikrotik'])) {
        $clientesDuplicadosMikrotik++;
    }
    if (($cliente['estado_inventario'] ?? '') === 'EN PPPOE_SECRETS') {
        $clientesInventarioEnSecrets++;
    }

    if (perfilVisibleParaUsuario($cliente['perfil'])) {

        $plan = obtenerNombreComercialPerfil($cliente['perfil']);

        $usoPerfiles[$plan] = ($usoPerfiles[$plan] ?? 0) + 1;

    }

}

$ordenPlanes = ['Básico', 'Intermedio', 'Avanzado'];

$datosGraficaNombres = [];

$datosGraficaValores = [];

foreach ($ordenPlanes as $plan) {

    $datosGraficaNombres[] = $plan;

    $datosGraficaValores[] = $usoPerfiles[$plan] ?? 0;

}

$planesDisponibles = array_values(array_filter(

    $perfiles,

    fn($perfil) => perfilVisibleParaUsuario($perfil['nombre'])

));

usort($planesDisponibles, function ($a, $b) use ($ordenPlanes) {

    return array_search(

        obtenerNombreComercialPerfil($a['nombre']),

        $ordenPlanes,

        true

    ) <=> array_search(

        obtenerNombreComercialPerfil($b['nombre']),

        $ordenPlanes,

        true

    );

});

?>

<!DOCTYPE html>

<html lang="es">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title><?= escapar((['inicio'=>'Inicio','catalogo'=>'Catálogo comercial','perfiles'=>'Perfiles MikroTik','historial'=>'Gestión de cambios','solicitudes'=>'Gestión de cambios'])[$moduloActual] ?? 'Inicio') ?> | UBB Comunicaciones</title>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
:root{
    color-scheme:light;
    --bg:#f5f4fa;
    --surface:#ffffff;
    --surface-soft:#faf9fd;
    --ink:#292435;
    --muted:#777287;
    --line:#e9e6f0;
    --line-strong:#dcd6e8;
    --plum:#322744;
    --plum-2:#4b3b68;
    --plum-3:#7054c7;
    --lavender:#b9a9ef;
    --lavender-soft:#f0ecfb;
    --lavender-pale:#f8f6fd;
    --green:#25815d;
    --green-soft:#e9f7ef;
    --red:#bb555d;
    --red-soft:#fff0f1;
    --shadow:0 12px 34px rgba(46,35,75,.065);
    --shadow-hover:0 18px 42px rgba(46,35,75,.11);
    --radius:18px;
    --radius-sm:12px;
}
*{box-sizing:border-box}
html{scroll-behavior:smooth;scroll-padding-top:22px}
body{margin:0;background:radial-gradient(ellipse at 80% 0%,rgba(187,169,239,.16),transparent 33%),var(--bg);color:var(--ink);font-family:Inter,"Segoe UI",Arial,Helvetica,sans-serif;font-size:13px;line-height:1.55;-webkit-font-smoothing:antialiased}
button,input{font:inherit}
button{cursor:pointer}
button:disabled{cursor:not-allowed;opacity:.48}
a{color:inherit;text-decoration:none}
::selection{background:#e6ddff;color:#33244f}
.app-shell{min-height:100vh;display:grid;grid-template-columns:258px minmax(0,1fr)}
.sidebar{position:sticky;top:0;height:100vh;display:flex;flex-direction:column;padding:26px 17px 19px;background:linear-gradient(160deg,#302440 0%,#3e2d59 52%,#2e2540 100%);color:#f8f6ff;overflow-y:auto;box-shadow:8px 0 34px rgba(35,24,54,.10);z-index:20}
.sidebar:before{content:"";position:absolute;inset:auto -65px 18% auto;width:190px;height:190px;border-radius:50%;background:rgba(164,136,245,.12);filter:blur(2px);pointer-events:none}
.sidebar-brand{position:relative;display:flex;align-items:center;gap:12px;padding:3px 9px 25px;border-bottom:1px solid rgba(255,255,255,.12)}
.brand-monogram{width:45px;height:45px;display:grid;place-items:center;border:1px solid rgba(255,255,255,.22);border-radius:14px;background:linear-gradient(145deg,rgba(255,255,255,.18),rgba(255,255,255,.06));box-shadow:0 8px 20px rgba(0,0,0,.13);font-weight:850;font-size:14px;letter-spacing:.7px}
.sidebar-brand strong{display:block;font-size:13px;letter-spacing:.6px}
.sidebar-brand small{display:block;margin-top:3px;color:#c9bfdc;font-size:9px;letter-spacing:1.3px;text-transform:uppercase}
.sidebar-label{padding:26px 12px 10px;color:#a99cbd;font-size:9px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase}
.sidebar-nav{display:grid;gap:15px}
.nav-group{display:grid;gap:5px;min-width:0}
.nav-group-label{padding:0 12px 3px;color:#a99cbd;font-size:9px;font-weight:850;letter-spacing:1.1px;text-transform:uppercase}
.nav-link{position:relative;display:flex;align-items:center;gap:12px;padding:11px 13px;border:1px solid transparent;border-radius:12px;color:#ded7eb;font-size:12px;font-weight:650;transition:background .2s,border-color .2s,transform .2s}
.nav-link:hover{background:rgba(255,255,255,.09);border-color:rgba(255,255,255,.08);transform:translateX(2px)}
.nav-link.active{background:linear-gradient(100deg,rgba(154,126,235,.34),rgba(154,126,235,.13));border-color:rgba(205,190,255,.22);color:#fff;box-shadow:inset 3px 0 #c8b8ff,0 7px 18px rgba(20,13,34,.12)}
.nav-icon{width:20px;text-align:center;color:#c7b9ed;font-size:17px}
.sidebar-spacer{flex:1;min-height:25px}
.sidebar-status{position:relative;padding:14px 15px;border:1px solid rgba(255,255,255,.13);border-radius:14px;background:rgba(255,255,255,.065);backdrop-filter:blur(8px)}
.sidebar-status-title{margin-bottom:10px;color:#c6bbd8;font-size:9px;font-weight:800;letter-spacing:1.15px;text-transform:uppercase}
.status-line{display:flex;align-items:center;gap:10px;padding:7px 0;color:#f6f3fb;font-size:11px}
.status-dot{width:8px;height:8px;flex:0 0 8px;border-radius:50%;background:#60d69b;box-shadow:0 0 0 4px rgba(96,214,155,.13)}
.status-dot.error{background:#ff8c96;box-shadow:0 0 0 4px rgba(255,140,150,.13)}
.status-line small{display:block;color:#bfb5cf;font-size:9px}
.main-area{min-width:0}
.contenedor{width:min(1820px,100%);margin:0 auto;padding:28px 32px 20px}
.page-eyebrow{margin:0 0 10px 3px;color:#827b94;font-size:10px;font-weight:850;letter-spacing:1.6px;text-transform:uppercase}
.encabezado{position:relative;isolation:isolate;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;overflow:hidden;margin-bottom:18px;padding:22px 25px;border:1px solid rgba(255,255,255,.15);border-radius:21px;background:linear-gradient(115deg,#38294f 0%,#59427e 52%,#7960bd 100%);box-shadow:0 15px 36px rgba(66,48,105,.18)}
.encabezado:before,.encabezado:after{content:"";position:absolute;z-index:-1;border:1px solid rgba(255,255,255,.10);border-radius:50%;pointer-events:none}
.encabezado:before{width:360px;height:360px;right:10%;top:-245px;background:rgba(255,255,255,.035)}
.encabezado:after{width:230px;height:230px;right:-65px;bottom:-160px;background:rgba(255,255,255,.05)}
.marca{display:flex;align-items:center;gap:17px;min-width:0}
.logo{width:72px;height:72px;flex:0 0 72px;object-fit:contain;background:#fff;border:1px solid rgba(255,255,255,.8);border-radius:16px;padding:4px;box-shadow:0 8px 20px rgba(20,12,34,.16)}
.marca h1{margin:0 0 5px;color:#fff;font-size:clamp(20px,2vw,28px);line-height:1.18;letter-spacing:-.8px;font-weight:800}
.marca p{margin:0;color:#e3ddef;font-size:12px}
.estado-conexion{display:inline-flex;align-items:center;gap:9px;padding:10px 14px;border:1px solid rgba(255,255,255,.20);border-radius:11px;background:rgba(255,255,255,.12);color:#fff;font-size:10px;font-weight:750;backdrop-filter:blur(8px)}
.punto{width:8px;height:8px;border-radius:50%;background:#64dda0;box-shadow:0 0 0 4px rgba(100,221,160,.14)}
.punto.error{background:#ff969d;box-shadow:0 0 0 4px rgba(255,150,157,.14)}
.aviso{margin:0 0 15px;padding:13px 16px;border:1px solid #e7e0f5;border-left:4px solid #8a70d9;border-radius:12px;background:linear-gradient(100deg,#fff 0%,#faf8ff 100%);color:#706a7d;font-size:11px;line-height:1.65;box-shadow:0 5px 18px rgba(46,35,75,.035)}
.aviso strong{color:var(--plum-2)}
.aviso code{padding:2px 5px;border-radius:5px;background:#f0ecfa;color:#6047a2;font-size:10px}
.aviso.error{border-color:#f0d5d8;border-left-color:var(--red);background:var(--red-soft);color:#91434a}
.estadisticas{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;margin:0 0 19px}
.tarjeta-estadistica{position:relative;min-width:0;overflow:hidden;padding:16px 16px 15px;border:1px solid #ebe7f1;border-radius:15px;background:var(--surface);box-shadow:var(--shadow);transition:transform .2s,box-shadow .2s,border-color .2s}
.tarjeta-estadistica:before{content:"";position:absolute;top:0;left:0;width:100%;height:3px;background:linear-gradient(90deg,#8066d4,#b4a2f1);opacity:.85}
.tarjeta-estadistica:hover{transform:translateY(-3px);border-color:#d9cff3;box-shadow:var(--shadow-hover)}
.tarjeta-estadistica:nth-child(2){background:linear-gradient(145deg,#f5f1ff,#fff 72%)}
.tarjeta-estadistica:nth-child(2):before{background:linear-gradient(90deg,#6545c6,#b59cf3)}
.tarjeta-pendiente:before{background:linear-gradient(90deg,#d18b58,#f1c28f)}
.estadistica-etiqueta{color:#777187;font-size:10px;font-weight:750;line-height:1.4}
.estadistica-numero{margin-top:8px;color:#3c2d59;font-size:27px;font-weight:850;line-height:1.1;letter-spacing:-.8px;font-variant-numeric:tabular-nums}
.estadistica-descripcion{margin-top:6px;color:#9690a2;font-size:9px;line-height:1.45}
.seccion-titulo{margin:0 0 13px;color:var(--plum);font-size:17px;font-weight:800;letter-spacing:-.35px}
.panel{min-width:0;border:1px solid #e9e5ef;border-radius:18px;background:var(--surface);box-shadow:var(--shadow)}
.catalogo-panel{margin:0 0 19px;padding:20px}
.panel-cabecera{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:15px}
.panel-cabecera h2{margin:0 0 4px;color:#392b52;font-size:15px;font-weight:800;letter-spacing:-.2px}
.panel-cabecera p{margin:0;color:#817b8f;font-size:10px;line-height:1.55}
.etiqueta{display:inline-flex;align-items:center;padding:6px 10px;border:1px solid #e4def1;border-radius:8px;background:#f7f4fd;color:#674da6;font-size:9px;font-weight:800;white-space:nowrap}
.catalogo-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
.plan-card{position:relative;min-width:0;overflow:hidden;padding:17px;border:1px solid #e8e3f0;border-radius:15px;background:linear-gradient(145deg,#fff 0%,#fbfaff 100%);transition:transform .2s,border-color .2s,box-shadow .2s}
.plan-card:before{content:"";position:absolute;inset:0 auto 0 0;width:3px;background:linear-gradient(180deg,#8b70dc,#c0b0f0)}
.plan-card:hover{transform:translateY(-3px);border-color:#d8cdf2;box-shadow:0 12px 25px rgba(64,45,105,.08)}
.plan-card-top{display:flex;align-items:flex-start;justify-content:space-between;gap:8px}
.plan-card h3{margin:0;color:#3e3057;font-size:14px;font-weight:800}
.plan-status{padding:5px 8px;border-radius:7px;background:#e9f7ef;color:#287d5a;font-size:9px;font-weight:850}
.plan-price{margin:13px 0 2px;color:#473466;font-size:27px;font-weight:850;letter-spacing:-.9px}
.plan-price small{margin-left:4px;color:#8b8497;font-size:10px;font-weight:550;letter-spacing:0}
.plan-speeds{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px;padding-top:11px;border-top:1px solid #eeeaf4}
.plan-speed{padding:7px 8px;border-radius:8px;background:#f8f6fc}
.plan-speed small{display:block;margin-bottom:3px;color:#888196;font-size:9px}
.plan-speed strong{color:#3e3450;font-size:11px}
.dashboard-grid{display:grid;grid-template-columns:minmax(0,1fr) 315px;gap:17px;align-items:start}
.directorio-section{min-width:0}
.panel-clientes{padding:19px}
.panel-directorio-cabecera{align-items:center;margin-bottom:14px}
.barra-busqueda{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-bottom:12px}
.buscador{position:relative;flex:1;min-width:210px}
.buscador:before{content:"⌕";position:absolute;top:50%;left:13px;transform:translateY(-54%);color:#8b7cae;font-size:19px;pointer-events:none}
.buscador input{width:100%;padding:12px 14px 12px 38px;border:1px solid #e2dcea;border-radius:11px;outline:none;background:#fbfaff;color:var(--ink);font-size:11px;transition:border-color .2s,box-shadow .2s,background .2s}
.buscador input:focus{border-color:#9b83df;background:#fff;box-shadow:0 0 0 4px rgba(132,105,211,.12)}
.buscador input::placeholder{color:#a19aac}
.contador{color:#817b8e;font-size:10px;font-weight:650;white-space:nowrap}
.boton{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:36px;padding:9px 13px;border:1px solid transparent;border-radius:9px;font-size:10px;font-weight:800;transition:transform .16s,background .16s,border-color .16s,box-shadow .16s}
.boton:active{transform:translateY(1px)}
.boton-rosa{background:linear-gradient(135deg,#7054c7,#8a6edc);color:#fff;box-shadow:0 5px 12px rgba(112,84,199,.18)}
.boton-rosa:hover:not(:disabled){background:linear-gradient(135deg,#6044b5,#795bce);box-shadow:0 7px 16px rgba(112,84,199,.25)}
.boton-secundario{border-color:#e3ddec;background:#faf9fd;color:#5c4d78}
.boton-secundario:hover:not(:disabled){border-color:#cfc2ec;background:#f1edfa}
.filtros-rapidos{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin:0 0 13px}
.filtro-rapido{display:inline-flex;align-items:center;gap:7px;padding:8px 11px;border:1px solid #e9e5ef;border-radius:9px;background:#fff;color:#817b8f;font-size:10px;font-weight:700;transition:all .16s}
.filtro-rapido:hover{border-color:#cbbcf0;background:#f8f5ff;color:#5b438f}
.filtro-rapido.activo{border-color:#7054c7;background:linear-gradient(120deg,#7054c7,#8469d6);color:#fff;box-shadow:0 4px 11px rgba(112,84,199,.16)}
.filtro-cantidad{display:inline-flex;min-width:19px;justify-content:center;padding:2px 5px;border-radius:6px;background:#f0edf6;color:#675b7d;font-size:9px}
.filtro-rapido.activo .filtro-cantidad{background:rgba(255,255,255,.18);color:#fff}
.tabla-contenedor{overflow:auto;overscroll-behavior-x:contain;border:1px solid #e9e5ef;border-radius:12px;background:#fff;scrollbar-color:#b9a8e9 #f4f1f9;scrollbar-width:thin}
.tabla-contenedor::-webkit-scrollbar{height:9px;width:9px}
.tabla-contenedor::-webkit-scrollbar-track{background:#f4f1f9}
.tabla-contenedor::-webkit-scrollbar-thumb{background:#b9a8e9;border:2px solid #f4f1f9;border-radius:10px}
table{width:100%;min-width:1120px;border-collapse:separate;border-spacing:0}
thead{position:sticky;top:0;background:#45345f;color:#fff}
th{padding:12px 10px;text-align:left;color:#fff;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.55px;white-space:nowrap}
td{padding:12px 10px;border-bottom:1px solid #f0edf4;color:#514b5d;font-size:10px;vertical-align:middle;overflow-wrap:anywhere}
tbody tr{transition:background .15s}
tbody tr:nth-child(even){background:#fcfbfe}
tbody tr:hover{background:#f5f1fd}
tbody tr:last-child td{border-bottom:0}
.usuario-nombre{font-size:11px;font-weight:800;color:#30283e}
.usuario-subtexto{margin-top:4px;color:#91899d;font-size:9px}
.perfil-etiqueta{display:inline-block;padding:5px 8px;border:1px solid #e8e1f5;border-radius:7px;background:#f3effb;color:#604b91;font-size:9px;font-weight:800}
.estado{display:inline-block;padding:5px 8px;border-radius:7px;font-size:9px;font-weight:800;white-space:nowrap}
.estado.activo,.estado.relacionado{background:#e8f6ee;color:#287a57}
.estado.inactivo,.estado.pendiente{background:#fff0f0;color:#aa5056}
.controles-tabla{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:13px;padding-top:13px;border-top:1px solid #eeeaf4}
.desplazamiento-tabla{display:flex;align-items:center;gap:7px}
.ayuda-desplazamiento{margin-right:3px;color:#858094;font-size:9px}
.boton-flecha-tabla{width:33px;height:33px;border:1px solid #e4deed;border-radius:9px;background:#fff;color:#5e4c82;font-weight:800;transition:all .15s}
.boton-flecha-tabla:hover:not(:disabled){background:#f2eefb;border-color:#cbbdf0}
.paginacion{display:flex;align-items:center;justify-content:flex-end;gap:12px;flex-wrap:wrap;margin-left:auto}
.paginacion-listado{margin:14px 0 4px;width:100%;justify-content:space-between;padding:10px 2px;border-top:1px solid #eeeaf3}
.paginacion-resumen{color:#858094;font-size:9px}
.paginacion-controles{display:flex;align-items:center;gap:7px}
.boton-paginacion{min-height:33px;padding:8px 11px;border:1px solid #e6e1ee;border-radius:9px;background:#fff;color:#5d4d7a;font-size:9px;font-weight:750;transition:all .15s}
.boton-paginacion:hover:not(:disabled){background:#f5f1fd;border-color:#cbbdf0}
.boton-paginacion-principal{border-color:#7054c7;background:#7054c7;color:#fff}
.boton-paginacion-principal:hover:not(:disabled){background:#5e43b2}
.pagina-indicador{min-width:78px;color:#65577d;font-size:9px;text-align:center;font-weight:800}
.insights-column{display:grid;gap:15px;min-width:0}
.insight-panel{padding:17px}
.insight-panel .panel-cabecera{margin-bottom:12px}
.insight-panel .panel-cabecera h2{font-size:13px}
.grafica-contenedor{position:relative;height:255px;padding:5px 0}
.activity-empty{display:flex;gap:11px;align-items:flex-start;padding:14px;border:1px dashed #dcd4eb;border-radius:11px;background:#faf8fe}
.activity-mark{width:32px;height:32px;flex:0 0 32px;display:grid;place-items:center;border-radius:9px;background:#eee8fa;color:#6b50b2;font-size:15px;font-weight:800}
.activity-empty strong{display:block;margin-bottom:4px;color:#423357;font-size:10px}
.activity-empty p{margin:0;color:#827b90;font-size:9px;line-height:1.6}
.pie{padding:21px 0 5px;color:#928ca0;text-align:center;font-size:9px}
.directorio-nota{margin:0 0 13px;padding:10px 12px;border:1px solid #e8e2f2;border-radius:9px;background:#faf8fe;color:#81798f;font-size:9px;line-height:1.55}
.empty-copy{margin:9px 0;color:#847d91;font-size:10px}
.sin-resultados{text-align:center;padding:25px!important;color:#8b8498!important}
.modal{position:fixed;inset:0;z-index:1000;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(30,22,43,.66);backdrop-filter:blur(7px)}
.modal.abierto{display:flex}
.modal-contenido{width:min(940px,100%);max-height:92vh;overflow-y:auto;border:1px solid rgba(255,255,255,.65);border-radius:20px;background:#fff;box-shadow:0 28px 80px rgba(21,13,34,.3)}
.modal-cabecera{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:21px 23px;border-bottom:1px solid rgba(255,255,255,.12);background:linear-gradient(115deg,#38294f,#7054a8);color:#fff}
.modal-cabecera h2{margin:0 0 5px;font-size:18px;letter-spacing:-.3px}
.modal-cabecera p{margin:0;color:#e5def1;font-size:10px;line-height:1.55}
.modal-cerrar{width:34px;height:34px;border:1px solid rgba(255,255,255,.24);border-radius:9px;background:rgba(255,255,255,.11);color:#fff;font-size:21px;transition:background .15s}
.modal-cerrar:hover{background:rgba(255,255,255,.22)}
.modal-cuerpo{padding:22px}
.informacion-cliente{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:21px}
.dato-cliente{min-width:0;padding:12px;border:1px solid #eae5f1;border-radius:11px;background:linear-gradient(145deg,#fff,#faf8fe)}
.dato-cliente small{display:block;margin-bottom:5px;color:#898296;font-size:8px;font-weight:850;letter-spacing:.6px;text-transform:uppercase}
.dato-cliente strong{display:block;overflow-wrap:anywhere;color:#342944;font-size:10px}
.titulo-perfiles{margin:0 0 11px;color:#3d2e57;font-size:14px;font-weight:800}
.perfiles-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.perfil-card{position:relative;padding:15px;border:1px solid #e8e2f0;border-radius:11px;background:#fff;text-align:left;transition:all .17s}
.perfil-card:hover{transform:translateY(-2px);border-color:#bca9ec;background:#faf8ff;box-shadow:0 8px 18px rgba(71,51,116,.08)}
.perfil-card.seleccionado{border:2px solid #7658cf;background:#f5f1ff}
.perfil-card.es-perfil-actual{border:2px solid #70b58d;background:#eff9f2}
.perfil-card strong{display:block;margin-bottom:6px;color:#342944;font-size:12px}
.perfil-card span{display:block;margin-top:4px;color:#837c91;font-size:9px}
.perfil-actual-texto{min-height:14px;color:#287a57!important;font-weight:800}
.comparacion-perfiles{display:grid;grid-template-columns:1fr 1fr;gap:11px;margin-top:17px}
.comparacion-item{padding:14px;border:1px solid #e9e4ef;border-radius:11px}
.comparacion-item.actual{border-color:#cce5d5;background:#f0f9f3}
.comparacion-item.nuevo{border-color:#e4dcf5;background:#f8f5fe}
.comparacion-item small{display:block;margin-bottom:6px;color:#81798e;font-size:8px;font-weight:850;text-transform:uppercase;letter-spacing:.5px}
.comparacion-item strong{display:block;overflow-wrap:anywhere;color:#45335f;font-size:13px}
.comparacion-item p{margin:5px 0 0;color:#81798e;font-size:9px}
.mensaje-solicitud{display:none;white-space:pre-line;margin-top:15px;padding:13px;border:1px solid #e4dcf2;border-radius:10px;background:#f8f5fe;color:#5e4a88;font-size:10px;line-height:1.6}
.mensaje-solicitud.visible{display:block}
.modal-pie{display:flex;justify-content:flex-end;flex-wrap:wrap;gap:8px;padding:15px 22px;border-top:1px solid #ece8f2;background:#faf9fc}
@media(max-width:1550px){.estadisticas{grid-template-columns:repeat(3,minmax(0,1fr))}.dashboard-grid{grid-template-columns:minmax(0,1fr) 295px}}
@media(max-width:1180px){.app-shell{grid-template-columns:220px minmax(0,1fr)}.dashboard-grid{grid-template-columns:1fr}.insights-column{grid-template-columns:repeat(2,minmax(0,1fr))}.grafica-contenedor{height:230px}.contenedor{padding:23px}}
@media(max-width:900px){.app-shell{display:block}.sidebar{position:relative;height:auto;padding:13px 16px;display:block;overflow:visible}.sidebar-brand{padding:0 3px 11px;border:0}.sidebar-brand small{display:none}.sidebar-label,.sidebar-spacer,.sidebar-status{display:none}.sidebar-nav{display:flex;align-items:flex-start;gap:14px;overflow-x:auto;padding:4px 0 7px;scrollbar-width:thin}.nav-group{display:flex;flex:0 0 auto;flex-direction:column;gap:4px}.nav-group-label{padding:0 8px 2px;font-size:8px}.nav-link{flex:0 0 auto;padding:9px 11px;font-size:10px}.nav-icon{font-size:14px}.contenedor{padding:19px}.dashboard-grid{grid-template-columns:1fr}}
@media(max-width:640px){.contenedor{padding:12px}.page-eyebrow{font-size:9px}.encabezado{padding:15px;gap:13px;border-radius:16px}.logo{width:58px;height:58px;flex-basis:58px;border-radius:12px}.marca{gap:11px}.marca h1{font-size:18px}.marca p{font-size:10px}.estado-conexion{font-size:9px;padding:8px 10px}.estadisticas{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.tarjeta-estadistica{padding:13px}.estadistica-numero{font-size:23px}.catalogo-panel,.panel-clientes,.insight-panel{padding:13px}.catalogo-grid{grid-template-columns:1fr}.insights-column{grid-template-columns:1fr}.informacion-cliente{grid-template-columns:repeat(2,minmax(0,1fr))}.perfiles-grid{grid-template-columns:1fr 1fr}.comparacion-perfiles{grid-template-columns:1fr}.controles-tabla{align-items:stretch}.desplazamiento-tabla{justify-content:flex-end}.paginacion{width:100%;justify-content:space-between;margin-left:0}.paginacion-resumen{width:100%}.paginacion-controles{width:100%;justify-content:space-between}.modal{padding:8px}.modal-cuerpo{padding:14px}.modal-pie{padding:12px}}
@media(max-width:390px){.marca h1{font-size:16px}.marca p{font-size:9px}.informacion-cliente{grid-template-columns:1fr}.perfiles-grid{grid-template-columns:1fr}.filtro-rapido{padding:7px 8px;font-size:9px}}
@media(prefers-reduced-motion:reduce){*,*:before,*:after{scroll-behavior:auto!important;transition:none!important;animation:none!important}}


/* Navegación por módulos */
.module-placeholder{display:none;min-height:340px;padding:24px;border:1px solid var(--line);border-radius:var(--radius);background:var(--surface);box-shadow:var(--shadow)}
.module-placeholder h2{margin:0 0 8px;color:var(--plum);font-size:20px}
.module-placeholder>p{max-width:760px;margin:0 0 18px;color:var(--muted);font-size:12px;line-height:1.7}
.module-status{padding:15px;border:1px solid #eadff8;border-radius:12px;background:#faf7ff;color:#594978}
.module-status strong{display:block;margin-bottom:4px;color:#45345f;font-size:12px}
.module-status span{font-size:11px;line-height:1.6}
.module-steps{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:18px}
.module-step{padding:14px;border:1px solid var(--line);border-radius:11px;background:#fff}
.module-step b{display:block;margin-bottom:6px;color:var(--plum);font-size:11px}
.module-step small{color:var(--muted);font-size:10px;line-height:1.55}
.module-tag{display:inline-flex;margin-bottom:12px;padding:5px 9px;border-radius:7px;background:#f0ecfb;color:#624b9a;font-size:9px;font-weight:800;text-transform:uppercase}

body[data-modulo="inicio"] #catalogo,
body[data-modulo="inicio"] .dashboard-grid,
body[data-modulo="inicio"] .module-placeholder{display:none!important}

body[data-modulo="catalogo"] #inicio,
body[data-modulo="catalogo"] .estadisticas,
body[data-modulo="catalogo"] .dashboard-grid,
body[data-modulo="catalogo"] .module-placeholder{display:none!important}
body[data-modulo="catalogo"] #catalogo{display:block!important}

body[data-modulo="perfiles"] #inicio,
body[data-modulo="perfiles"] .estadisticas,
body[data-modulo="perfiles"] #catalogo,
body[data-modulo="perfiles"] .module-placeholder{display:none!important}
body[data-modulo="perfiles"] .dashboard-grid{display:grid!important;grid-template-columns:1fr!important}
body[data-modulo="perfiles"] .directorio-section,
body[data-modulo="perfiles"] #actividad{display:none!important}
body[data-modulo="perfiles"] .insights-column{display:grid!important;grid-template-columns:1fr!important}

body[data-modulo="solicitudes"] #inicio,
body[data-modulo="solicitudes"] .estadisticas,
body[data-modulo="solicitudes"] #catalogo,
body[data-modulo="solicitudes"] .dashboard-grid,
body[data-modulo="solicitudes"] #moduloHistorial{display:none!important}
body[data-modulo="solicitudes"] #moduloSolicitudes{display:block!important}

body[data-modulo="historial"] #inicio,
body[data-modulo="historial"] .estadisticas,
body[data-modulo="historial"] #catalogo,
body[data-modulo="historial"] .dashboard-grid,
body[data-modulo="historial"] #moduloSolicitudes{display:none!important}
body[data-modulo="historial"] #moduloHistorial{display:block!important}


.radius-panel{min-width:0}
.radius-cabecera{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:18px}
.radius-cabecera h2{margin:0 0 6px;color:var(--plum);font-size:20px}
.radius-cabecera p{margin:0;color:var(--muted);font-size:11px}
.radius-resumen{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin:0 0 18px}
.radius-nas-card{padding:18px;border:1px solid var(--line);border-radius:14px;background:#fff;box-shadow:var(--shadow)}
.radius-nas-top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}
.radius-nas-tipo{color:var(--muted);font-size:10px;font-weight:800;letter-spacing:.7px;text-transform:uppercase}
.radius-nas-card h3{margin:0 0 4px;color:var(--plum);font-size:19px}
.radius-nas-ip{color:var(--muted);font-size:10px}
.radius-nas-stats{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:15px}
.radius-nas-stat{padding:10px;border-radius:9px;background:var(--surface-soft)}
.radius-nas-stat small{display:block;color:var(--muted);font-size:9px}
.radius-nas-stat strong{display:block;margin-top:4px;color:var(--plum);font-size:19px;font-variant-numeric:tabular-nums}
.radius-ultima{margin-top:12px;padding-top:11px;border-top:1px solid var(--line);font-size:10px;line-height:1.55;color:var(--muted)}
.radius-ultima strong{color:var(--ink)}
.radius-estado{display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;font-size:9px;font-weight:800;background:#eaf3ed;color:#3f7658}
.radius-estado.sin-registros{background:#fff4df;color:#8b681f}
.radius-nota{margin:0 0 16px;padding:12px 14px;border:1px solid #e8e2f2;border-radius:10px;background:#faf8fe;color:#746d82;font-size:10px;line-height:1.6}
.radius-filtros{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:16px 0 12px}
.radius-filtros-grupo{display:flex;gap:7px;flex-wrap:wrap}
.radius-filtro{display:inline-flex;align-items:center;padding:9px 13px;border:1px solid var(--line);border-radius:9px;background:#fff;color:var(--plum);font-size:10px;font-weight:750;text-decoration:none}
.radius-filtro.activo{border-color:var(--plum-3);background:var(--lavender-pale);box-shadow:inset 0 0 0 1px var(--plum-3)}
.radius-tabla table{min-width:1120px}
.radius-tabla td{vertical-align:top}
.radius-secundario{display:block;margin-top:3px;color:var(--muted);font-size:9px;line-height:1.45}
.radius-vacio{padding:22px;border:1px dashed var(--line-strong);border-radius:11px;background:var(--surface-soft);color:var(--muted);font-size:11px}
@media(max-width:720px){.radius-resumen{grid-template-columns:1fr}.radius-nas-card{padding:15px}.radius-panel{padding:16px}.radius-filtros{align-items:flex-start}}

.dhcp-panel{min-width:0}
.dhcp-resumen{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px;margin:0 0 16px}
.dhcp-metrica{padding:15px;border:1px solid var(--line);border-radius:13px;background:#fff;box-shadow:var(--shadow)}
.dhcp-metrica small{display:block;color:var(--muted);font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.5px}
.dhcp-metrica strong{display:block;margin-top:7px;color:var(--plum);font-size:23px;font-weight:850;font-variant-numeric:tabular-nums}
.dhcp-tools{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin:14px 0}
.dhcp-buscador{flex:1;min-width:220px}
.dhcp-buscador input{width:100%;padding:11px 13px;border:1px solid var(--line-strong);border-radius:10px;background:#fff;color:var(--ink);font:inherit;font-size:11px}
.dhcp-tabla table{min-width:1250px}
.dhcp-subtexto{display:block;margin-top:3px;color:var(--muted);font-size:9px}
.dhcp-nota{margin:12px 0 0;padding:11px 13px;border:1px solid #e8e2f2;border-radius:9px;background:#faf8fe;color:#746d82;font-size:10px;line-height:1.6}
@media(max-width:720px){.dhcp-resumen{grid-template-columns:repeat(2,minmax(0,1fr))}.dhcp-panel{padding:16px}}
@media(max-width:720px){
    .module-placeholder{padding:16px}
    .module-steps{grid-template-columns:1fr 1fr}
}


.historial-panel{min-width:0}
.historial-cabecera{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-bottom:18px}
.historial-cabecera h2{margin:0 0 6px;color:var(--plum);font-size:20px}
.historial-cabecera p{margin:0;color:var(--muted);font-size:11px}
.historial-resumen{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;padding:12px;border:1px solid var(--line);border-radius:10px;background:var(--surface-soft);font-size:10px;color:var(--muted)}
.historial-resumen strong{color:var(--plum)}
.historial-tabla table{min-width:1100px}
.historial-tabla td{vertical-align:top;max-width:300px}
.historial-subtexto{margin-top:4px;color:var(--muted);font-size:9px;overflow-wrap:anywhere}
.historial-vacio{padding:20px;border:1px dashed var(--line-strong);border-radius:10px;background:var(--surface-soft);color:var(--plum)}
.pppoe-mini-resumen{display:flex;gap:8px 16px;flex-wrap:wrap;margin:0 0 16px;padding:11px 14px;border:1px solid var(--line);border-radius:11px;background:var(--surface-soft);color:var(--muted);font-size:10px;line-height:1.5}.pppoe-mini-resumen strong{color:var(--plum);font-variant-numeric:tabular-nums}.estado.estado-ok{background:#eaf3ed;color:#3f7658}
.estado.estado-pendiente{background:#fff4df;color:#8b681f}
.estado.estado-error{background:#f8eeee;color:#a44f4f}


.solicitud-panel{min-width:0}
.solicitud-cabecera{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:18px}
.solicitud-cabecera h2{margin:0 0 6px;color:var(--plum);font-size:20px}
.solicitud-cabecera p{margin:0;color:var(--muted);font-size:11px}
.solicitud-form{display:grid;grid-template-columns:1fr 1fr;gap:15px;padding:18px;border:1px solid var(--line);border-radius:12px;background:#fff}
.solicitud-campo{display:grid;align-content:start;gap:7px;min-width:0}
.solicitud-campo label{color:var(--plum);font-size:11px;font-weight:750}
.solicitud-campo input,.solicitud-campo select,.solicitud-campo textarea{width:100%;min-width:0;padding:11px 12px;border:1px solid var(--line-strong);border-radius:9px;background:#fff;color:var(--ink);font:inherit;font-size:11px}
.solicitud-campo input:focus,.solicitud-campo select:focus,.solicitud-campo textarea:focus{outline:none;border-color:var(--plum-3);box-shadow:0 0 0 3px rgba(102,91,114,.1)}
.solicitud-campo small{color:var(--muted);font-size:9px;line-height:1.5}
.solicitud-campo-completo{grid-column:1/-1}
.solicitud-resumen{display:grid;gap:4px;padding:13px;border:1px solid var(--line);border-radius:9px;background:var(--lavender-pale);color:var(--muted);font-size:10px;line-height:1.6}
.solicitud-resumen strong{color:var(--plum)}
.solicitud-acciones{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.solicitud-acciones span{color:var(--muted);font-size:10px}
.solicitud-mensaje{display:grid;gap:4px;margin-bottom:14px;padding:13px;border-radius:9px;font-size:10px;line-height:1.5}
.solicitud-mensaje.correcto{border:1px solid #c9ded0;background:#eaf3ed;color:#3f7658}
.solicitud-mensaje.error{border:1px solid #e8cdcd;background:#f8eeee;color:#954f4f}
@media(max-width:640px){.solicitud-form{grid-template-columns:1fr;padding:13px}.solicitud-campo-completo{grid-column:auto}}



/* Directorio PPPoE: columnas con medidas estables para evitar que los datos técnicos se apilen. */
.ayuda-tabla-clientes {
    margin: 0 0 8px;
    color: #847c93;
    font-size: 9px;
    line-height: 1.5;
}
.panel-clientes .tabla-contenedor {
    display: block;
    width: 100%;
    max-width: 100%;
    overflow-x: auto;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
}
.panel-clientes .tabla-contenedor table {
    width: 100%;
    min-width: 1620px;
    table-layout: fixed;
    border-collapse: separate;
}
.panel-clientes .tabla-contenedor th,
.panel-clientes .tabla-contenedor td {
    box-sizing: border-box;
    padding: 12px 11px;
}
.panel-clientes .tabla-contenedor th:nth-child(1),
.panel-clientes .tabla-contenedor td:nth-child(1) { width: 175px; }
.panel-clientes .tabla-contenedor th:nth-child(2),
.panel-clientes .tabla-contenedor td:nth-child(2) { width: 100px; }
.panel-clientes .tabla-contenedor th:nth-child(3),
.panel-clientes .tabla-contenedor td:nth-child(3) { width: 160px; }
.panel-clientes .tabla-contenedor th:nth-child(4),
.panel-clientes .tabla-contenedor td:nth-child(4) { width: 105px; }
.panel-clientes .tabla-contenedor th:nth-child(5),
.panel-clientes .tabla-contenedor td:nth-child(5) { width: 110px; }
.panel-clientes .tabla-contenedor th:nth-child(6),
.panel-clientes .tabla-contenedor td:nth-child(6) { width: 160px; }
.panel-clientes .tabla-contenedor th:nth-child(7),
.panel-clientes .tabla-contenedor td:nth-child(7) { width: 130px; }
.panel-clientes .tabla-contenedor th:nth-child(8),
.panel-clientes .tabla-contenedor td:nth-child(8) { width: 155px; }
.panel-clientes .tabla-contenedor th:nth-child(9),
.panel-clientes .tabla-contenedor td:nth-child(9) { width: 170px; }
.panel-clientes .tabla-contenedor th:nth-child(10),
.panel-clientes .tabla-contenedor td:nth-child(10) { width: 145px; }
.panel-clientes .tabla-contenedor th:nth-child(11),
.panel-clientes .tabla-contenedor td:nth-child(11) { width: 115px; }
.panel-clientes .tabla-contenedor th:nth-child(12),
.panel-clientes .tabla-contenedor td:nth-child(12) { width: 95px; }

/* Campos técnicos en una sola línea: IP, MAC, perfil, velocidad e identificador. */
.panel-clientes .tabla-contenedor td:nth-child(1),
.panel-clientes .tabla-contenedor td:nth-child(2),
.panel-clientes .tabla-contenedor td:nth-child(4),
.panel-clientes .tabla-contenedor td:nth-child(5),
.panel-clientes .tabla-contenedor td:nth-child(6),
.panel-clientes .tabla-contenedor td:nth-child(7),
.panel-clientes .tabla-contenedor td:nth-child(8),
.panel-clientes .tabla-contenedor td:nth-child(11),
.panel-clientes .tabla-contenedor td:nth-child(12) {
    white-space: nowrap;
    overflow-wrap: normal;
    word-break: normal;
}
.panel-clientes .tabla-contenedor td:nth-child(3),
.panel-clientes .tabla-contenedor td:nth-child(9),
.panel-clientes .tabla-contenedor td:nth-child(10) {
    white-space: normal;
    overflow-wrap: break-word;
    word-break: normal;
    line-height: 1.5;
}
.panel-clientes .tabla-contenedor td:nth-child(7),
.panel-clientes .tabla-contenedor td:nth-child(8) {
    font-variant-numeric: tabular-nums;
    letter-spacing: .1px;
}
.panel-clientes .tabla-contenedor .perfil-etiqueta {
    max-width: 100%;
    white-space: normal;
    line-height: 1.4;
}
.panel-clientes .tabla-contenedor .estado {
    max-width: 100%;
    white-space: normal;
    line-height: 1.4;
}
.panel-clientes .tabla-contenedor .boton-ver-planes {
    width: 100%;
    min-width: 82px;
    min-height: 34px;
    padding: 8px 10px;
    white-space: nowrap;
}
@media (max-width: 720px) {
    .panel-clientes .tabla-contenedor table {
        width: 100%;
        min-width: 1620px;
    }
    .panel-clientes .tabla-contenedor th,
    .panel-clientes .tabla-contenedor td {
        padding: 10px 9px;
    }
}

</style>
<style>
<?= portalCssMarco() ?>
/* Ajustes de diseño (auditoría UX) */
body{background:var(--bg);font-size:14px}
body:not([data-modulo="inicio"]) .pppoe-mini-resumen{display:none!important}
.titulo-pagina{margin:0 0 4px;color:var(--plum);font-size:clamp(20px,2.2vw,28px);font-weight:600;letter-spacing:-.6px;line-height:1.2}
.sub{margin:0 0 18px;color:var(--muted);font-size:14px}
.page-eyebrow{font-size:12px;font-weight:600;letter-spacing:1.2px;margin:0 0 6px 2px}
.aviso{font-size:14px;background:var(--surface);box-shadow:none}
.aviso code{font-size:13px}
.aviso.error{background:var(--red-soft)}
.aviso-detalle{margin-top:6px;font-size:12px;color:#91434a}
.aviso-detalle summary{cursor:pointer;font-weight:600}
.tarjeta-estadistica:before{display:none}
.tarjeta-estadistica:nth-child(2){background:var(--surface)}
.tarjeta-estadistica:hover{transform:none;box-shadow:var(--shadow)}
.estadistica-etiqueta{font-size:13px;font-weight:600}
.estadistica-descripcion{font-size:12.5px}
.pppoe-mini-resumen{font-size:13px}
.pie{font-size:12px}
.module-tag,.etiqueta{font-size:12px}
.panel-cabecera p{font-size:13px}
.boton{font-size:13px;font-weight:600}
.boton-paginacion,.filtro-rapido{font-size:12.5px}
.pagina-indicador{font-size:12.5px}
@media(max-width:640px){.page-eyebrow{font-size:12px}}
</style>

</head>

<body data-modulo="<?= escapar($moduloActual) ?>">
<div class="app-shell">
    <?= portalMenu($moduloActual, ['MikroTik' => !$errorConexion, 'RADIUS' => !$errorConsultaRadius]) ?>

    <div class="main-area">
        <main class="contenedor">
            <p class="page-eyebrow">Administración WISP · UBB Comunicaciones</p>
<?php
    $paginas = [
        'inicio' => ['Inicio', 'Resumen de cuentas PPPoE y su relación con la base de clientes.'],
        'catalogo' => ['Catálogo comercial', 'Planes vigentes y sus velocidades.'],
        'perfiles' => ['Perfiles MikroTik', 'Cuántas cuentas usa cada perfil del router.'],
        'historial' => ['Gestión de cambios', 'Solicitudes de cambio de plan, quién las pidió y cómo terminaron.'],
        'solicitudes' => ['Gestión de cambios', 'Solicitudes de cambio de plan, quién las pidió y cómo terminaron.'],
    ];
    [$tituloPagina, $subPagina] = $paginas[$moduloActual] ?? $paginas['inicio'];
    $fallas = array_values(array_filter([
        $errorConexion ? 'el router MikroTik' : '',
        $errorConsultaRadius ? 'RADIUS' : '',
        $errorBaseDatos ? 'la base de clientes' : '',
    ]));
    ?>
    <h1 class="titulo-pagina"><?= escapar($tituloPagina) ?></h1>
    <p class="sub"><?= escapar($subPagina) ?></p>
    <div class="aviso" id="inicio">
        <strong>Integración por servicio:</strong>
        esta pantalla utiliza <code>id_servicio</code> para relacionar la información de clientes y catálogo.
        Funciona en modo consulta: no escribe en inventario, no modifica la base de Jamileth ni aplica cambios en MikroTik o RADIUS.
    </div>
    <?php if ($fallas !== []): ?>
        <div class="aviso error" role="status">
            <strong>Sin conexión con <?= escapar(count($fallas) > 1 ? implode(', ', array_slice($fallas, 0, -1)) . ' y ' . end($fallas) : $fallas[0]) ?>.</strong>
            Lo que ves puede no estar al día. Recarga en unos minutos; si sigue igual, revisa que el servidor llegue a esos equipos.
            <details class="aviso-detalle"><summary>Detalle técnico</summary>
                <?= escapar(implode(' · ', array_filter([$errorConexion, $errorConsultaRadius, $errorBaseDatos]))) ?>
            </details>
        </div>
    <?php endif; ?>

    <section class="estadisticas">

        <article class="tarjeta-estadistica">

            <div class="estadistica-etiqueta">Cuentas PPPoE</div>

            <div class="estadistica-numero"><?= $totalClientes ?></div>

            <div class="estadistica-descripcion">Registradas en MikroTik</div>

        </article>

        <article class="tarjeta-estadistica">

            <div class="estadistica-etiqueta">Conectados ahora</div>

            <div class="estadistica-numero"><?= $totalConectados ?></div>

            <div class="estadistica-descripcion">Sesiones PPP activas en MikroTik</div>

        </article>

        <article class="tarjeta-estadistica">

            <div class="estadistica-etiqueta">Habilitadas</div>

            <div class="estadistica-numero"><?= $clientesHabilitados ?></div>

            <div class="estadistica-descripcion">Cuentas habilitadas</div>

        </article>

        <article class="tarjeta-estadistica">

            <div class="estadistica-etiqueta">Deshabilitadas</div>

            <div class="estadistica-numero"><?= $clientesDeshabilitados ?></div>

            <div class="estadistica-descripcion">Cuentas deshabilitadas</div>

        </article>

        <article class="tarjeta-estadistica">

            <div class="estadistica-etiqueta">Relacionados en Jamileth</div>

            <div class="estadistica-numero"><?= $clientesRelacionados ?></div>

            <div class="estadistica-descripcion">PPPoE con cliente y servicio válidos</div>

        </article>

        <article class="tarjeta-estadistica tarjeta-pendiente">

            <div class="estadistica-etiqueta">Pendientes en Jamileth</div>

            <div class="estadistica-numero"><?= $clientesPppoePendientes ?></div>

            <div class="estadistica-descripcion">Sin id_cliente e id_servicio</div>

        </article>

    </section>
    <div class="pppoe-mini-resumen">
        <span><strong><?= $clientesSinRegistroJamileth ?></strong> sin registro PPPoE en Jamileth</span>
        <span><strong><?= $clientesRelacionadosHibridos ?></strong> relaciones DHCP_PPPOE</span>
        <span><strong><?= $clientesDuplicadosMikrotik ?></strong> nombres duplicados en MikroTik</span>
        <span><strong><?= $clientesRelacionAmbigua + $clientesRevisarRelacion ?></strong> relaciones para revisar</span>
        <span><strong><?= $inventarioPppoeDisponible ? $clientesInventarioEnSecrets . ' / ' . $totalClientes : 'No verificado' ?></strong> en inventario.pppoe_secrets</span>
    </div>
    <section class="panel catalogo-panel" id="catalogo">
        <div class="panel-cabecera">
            <div>
                <h2>Catálogo comercial</h2>
                <p>Planes vigentes consultados desde ubb_gestion_planes.</p>
            </div>
            <span class="etiqueta"><?= count($planesCatalogo) ?> planes activos</span>
        </div>

        <?php if ($errorCatalogo): ?>
            <div class="aviso error"><?= escapar($errorCatalogo) ?></div>
        <?php elseif (!$planesCatalogo): ?>
            <p class="empty-copy">No hay planes comerciales activos registrados.</p>
        <?php else: ?>
            <div class="catalogo-grid">
                <?php foreach ($planesCatalogo as $plan): ?>
                    <article class="plan-card">
                        <div class="plan-card-top">
                            <h3><?= escapar($plan['nombre_plan']) ?></h3>
                            <span class="plan-status"><?= escapar($plan['estado']) ?></span>
                        </div>
                        <div class="plan-price">
                            <?php if ($plan['precio_mensual'] !== null && $plan['precio_mensual'] !== ''): ?>
                                $<?= number_format((float) $plan['precio_mensual'], 2) ?>
                            <?php else: ?>
                                Por definir
                            <?php endif; ?>
                            <small>mensual</small>
                        </div>
                        <div class="plan-speeds">
                            <div class="plan-speed">
                                <small>Inalámbrico</small>
                                <strong><?php if (isset($plan['velocidades']['INALAMBRICO'])): ?><?= number_format($plan['velocidades']['INALAMBRICO'], 0) ?> Mbps<?php else: ?>Por definir<?php endif; ?></strong>
                            </div>
                            <div class="plan-speed">
                                <small>Fibra</small>
                                <strong><?php if (isset($plan['velocidades']['FIBRA'])): ?><?= number_format($plan['velocidades']['FIBRA'], 0) ?> Mbps<?php else: ?>Por definir<?php endif; ?></strong>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

            <div class="dashboard-grid">
        <aside class="insights-column">
            <section class="panel insight-panel" id="perfiles">
                <div class="panel-cabecera">
                    <div>
                        <h2>Uso de perfiles</h2>
                        <p>Distribución de cuentas según el perfil de MikroTik.</p>
                    </div>
                    <span class="etiqueta"><?= count($planesDisponibles) ?> perfiles</span>
                </div>
                <?php if ($totalClientes > 0): ?>
                    <div class="grafica-contenedor"><canvas id="graficaPlanes"></canvas></div>
                <?php else: ?>
                    <p class="empty-copy">No hay datos suficientes para mostrar la gráfica.</p>
                <?php endif; ?>
            </section>

            <section class="panel insight-panel" id="actividad">
                <div class="panel-cabecera">
                    <div>
                        <h2>Actividad reciente</h2>
                        <p>Estado de integración de avisos entre módulos.</p>
                    </div>
                    <span class="etiqueta">Pendiente</span>
                </div>
                <div class="activity-empty">
                    <div class="activity-mark">i</div>
                    <div>
                        <strong>Historial no conectado</strong>
                        <p>Los avisos no se consultan desde inventario en esta versión. Se mostrarán cuando exista una fuente autorizada para el módulo de actividad.</p>
                    </div>
                </div>
            </section>
        </aside>

            </div>


<section class="module-placeholder solicitud-panel" id="moduloSolicitudes">
    <div class="solicitud-cabecera">
        <div>
            <span class="module-tag">Gestión de servicios · PPPoE inalámbrico</span>
            <h2>Nueva solicitud de cambio</h2>
            <p>Registra una solicitud para revisión. No modifica el servicio ni el router.</p>
        </div>
        <a class="boton boton-secundario" href="?modulo=historial">Ver historial de cambios</a>
    </div>

    <?php if ($flashSolicitud): ?>
        <div class="solicitud-mensaje correcto">
            <strong>¡Solicitud registrada!</strong>
            <span>Folio #<?= (int) ($flashSolicitud['id'] ?? 0) ?>. Consulta su seguimiento en Historial de cambios.</span>
        </div>
    <?php endif; ?>

    <?php if ($errorFormularioSolicitud): ?>
        <div class="solicitud-mensaje error">
            <strong>No se pudo registrar</strong>
            <span><?= escapar($errorFormularioSolicitud) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($errorRelacionesSolicitud): ?>
        <div class="module-status">
            <strong>Catálogo de relaciones no disponible</strong>
            <span><?= escapar($errorRelacionesSolicitud) ?></span>
        </div>
    <?php endif; ?>

    <form method="post" action="?modulo=solicitudes" class="solicitud-form">
        <input type="hidden" name="accion" value="registrar_solicitud">
        <input type="hidden" name="csrf_solicitud" value="<?= escapar($csrfSolicitud) ?>">

        <div class="solicitud-campo solicitud-campo-completo">
            <label for="servicio_solicitud">Cliente y servicio</label>
            <select id="servicio_solicitud" name="id_servicio" required>
                <option value="">Selecciona un servicio</option>
                <?php foreach ($serviciosSolicitud as $servicioSolicitud): ?>
                    <option value="<?= escapar($servicioSolicitud['id_servicio']) ?>">
                        <?= escapar($servicioSolicitud['nombre'] ?: 'Cliente sin nombre') ?>
                        · Servicio <?= escapar($servicioSolicitud['id_servicio']) ?>
                        · <?= escapar($servicioSolicitud['usuario']) ?>
                        · Actual: <?= escapar($servicioSolicitud['plan_actual']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (!$serviciosSolicitud): ?>
                <small>No hay servicios inalámbricos PPPoE elegibles con asignación activa.</small>
            <?php endif; ?>
        </div>

        <div class="solicitud-campo">
            <label for="plan_nuevo">Nuevo plan comercial</label>
            <select id="plan_nuevo" name="id_plan_nuevo" required>
                <option value="">Selecciona un plan</option>
                <?php foreach ($planesSolicitud as $planSolicitud): ?>
                    <option value="<?= escapar($planSolicitud['id_plan']) ?>">
                        <?= escapar($planSolicitud['nombre']) ?>
                        · <?= escapar($planSolicitud['descarga'] ?? '—') ?> Mbps
                        bajada / <?= escapar($planSolicitud['subida'] ?? '—') ?> Mbps subida
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="solicitud-campo">
            <label for="perfil_nuevo">Perfil MikroTik relacionado</label>
            <select id="perfil_nuevo" name="id_perfil_nuevo" required>
                <option value="">Primero selecciona un plan</option>
                <?php foreach ($relacionesSolicitud as $relacion): ?>
                    <option
                        value="<?= (int) $relacion['id_perfil'] ?>"
                        data-plan="<?= (int) $relacion['id_plan'] ?>"
                        data-validacion="<?= escapar($relacion['estado_validacion']) ?>"
                        disabled
                    >
                        <?= escapar($relacion['nombre_perfil']) ?>
                        · <?= escapar($relacion['estado_validacion']) ?>
                        · <?= escapar($relacion['rate_limit'] ?? 'Sin rate-limit') ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small>Las relaciones pendientes requieren confirmación antes de poder validar el cambio.</small>
        </div>

        <div class="solicitud-campo">
            <label for="responsable_solicitud">Responsable de la solicitud</label>
            <input
                type="text"
                id="responsable_solicitud"
                name="responsable"
                maxlength="100"
                required
                placeholder="Nombre del responsable"
            >
        </div>

        <div class="solicitud-campo">
            <label for="motivo_solicitud">Motivo del cambio</label>
            <textarea
                id="motivo_solicitud"
                name="motivo"
                rows="3"
                maxlength="255"
                required
                placeholder="Describe por qué se solicita el cambio..."
            ></textarea>
        </div>

        <div class="solicitud-resumen solicitud-campo-completo">
            <strong>¿Qué ocurrirá al registrar?</strong>
            <span>Se guardará una solicitud pendiente en ubb_gestion_planes. No se modifica la asignación actual, RADIUS ni MikroTik.</span>
        </div>

        <div class="solicitud-acciones solicitud-campo-completo">
            <button
                type="submit"
                class="boton boton-rosa"
                <?= (!$serviciosSolicitud || !$relacionesSolicitud || $errorRelacionesSolicitud) ? 'disabled' : '' ?>
            >Registrar solicitud</button>
            <span>Tipo de conexión: PPPoE</span>
        </div>
    </form>
</section>



<section class="module-placeholder historial-panel" id="moduloHistorial">
    <div class="historial-cabecera">
        <div>
            <span class="module-tag">Consulta en vivo</span>
            <h2>Historial de cambios</h2>
            <p>Solicitudes, responsables, validaciones, autorizaciones y resultados.</p>
        </div>
        <div class="historial-acciones" style="display:flex;gap:8px;flex-wrap:wrap">
            <a class="boton boton-rosa" href="?modulo=solicitudes">＋ Nueva solicitud</a>
            <a class="boton boton-secundario" href="?modulo=historial">↻ Actualizar</a>
        </div>
    </div>

    <?php if ($errorHistorial): ?>
        <div class="module-status">
            <strong>No se pudo cargar el historial</strong>
            <span><?= escapar($errorHistorial) ?></span>
        </div>
    <?php elseif (!$historialCambios): ?>
        <div class="historial-vacio">
            <strong>Aún no hay cambios registrados</strong>
            <p>Cuando se registren solicitudes, aparecerán aquí. Esta pantalla solamente consulta información.</p>
        </div>
    <?php else: ?>
        <div class="historial-resumen">
            <strong><?= count($historialCambios) ?> registros cargados</strong>
            <span>Máximo 200 movimientos recientes.</span>
        </div>
        <div class="tabla-contenedor historial-tabla">
            <table>
                <thead>
                    <tr>
                        <th>Folio / servicio</th>
                        <th>Fecha / responsable</th>
                        <th>Cambio solicitado</th>
                        <th>Validación</th>
                        <th>Autorización</th>
                        <th>Resultado / verificación</th>
                    </tr>
                </thead>
                <tbody id="tablaHistorial">
                    <?php foreach ($historialCambios as $cambio): ?>
                    <tr>
                        <td>
                            <strong>#<?= (int)$cambio['id_cambio'] ?></strong>
                            <div class="historial-subtexto">Servicio <?= escapar($cambio['id_servicio']) ?></div>
                        </td>
                        <td>
                            <strong><?= escapar(date('d/m/Y H:i', strtotime($cambio['fecha_cambio']))) ?></strong>
                            <div class="historial-subtexto"><?= escapar($cambio['usuario_responsable'] ?? 'No indicado') ?></div>
                        </td>
                        <td>
                            <strong><?= escapar($cambio['plan_anterior'] ?? 'Sin plan anterior') ?> → <?= escapar($cambio['plan_nuevo'] ?? 'Sin plan nuevo') ?></strong>
                            <div class="historial-subtexto"><?= escapar($cambio['perfil_anterior'] ?? '—') ?> → <?= escapar($cambio['perfil_nuevo'] ?? '—') ?></div>
                            <div class="historial-subtexto"><?= escapar($cambio['motivo'] ?? '') ?></div>
                        </td>
                        <td>
                            <span class="estado <?= escapar(claseEstadoCambio($cambio['estado_validacion'])) ?>"><?= escapar($cambio['estado_validacion']) ?></span>
                            <div class="historial-subtexto"><?= escapar($cambio['usuario_validacion'] ?? 'Sin responsable') ?></div>
                        </td>
                        <td>
                            <span class="estado <?= escapar(claseEstadoCambio($cambio['estado_autorizacion'])) ?>"><?= escapar($cambio['estado_autorizacion']) ?></span>
                            <div class="historial-subtexto"><?= escapar($cambio['usuario_autorizacion'] ?? 'Sin responsable') ?></div>
                        </td>
                        <td>
                            <span class="estado <?= escapar(claseEstadoCambio($cambio['resultado_aplicacion'])) ?>"><?= escapar($cambio['resultado_aplicacion']) ?></span>
                            <div class="historial-subtexto">Verificación: <?= escapar($cambio['estado_verificacion']) ?></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <nav class="paginacion paginacion-listado" id="paginacionHistorial" aria-label="Paginación del historial de cambios">
            <div class="paginacion-resumen" id="resumenPaginacionHistorial">Mostrando registros</div>
            <div class="paginacion-controles">
                <button type="button" class="boton-paginacion" id="paginaAnteriorHistorial" aria-label="Página anterior del historial">← Anterior</button>
                <span class="pagina-indicador" id="indicadorPaginaHistorial" aria-live="polite">Página 1 de 1</span>
                <button type="button" class="boton-paginacion boton-paginacion-principal" id="paginaSiguienteHistorial" aria-label="Página siguiente del historial">Siguiente →</button>
            </div>
        </nav>
    <?php endif; ?>
</section>


            <footer class="pie">UBB Comunicaciones · Gestión de clientes, paquetes, perfiles y velocidades · Modo consulta</footer>
        </main>
    </div>
</div>

<div class="modal" id="modalPlanes" role="dialog" aria-modal="true" aria-labelledby="tituloModal">

    <div class="modal-contenido">

        <div class="modal-cabecera">

            <div>

                <h2 id="tituloModal">Resumen del servicio</h2>

                <p>Consulta la información comercial y técnica del servicio. La aplicación de cambios está deshabilitada en esta versión.</p>

            </div>

            <button type="button" class="modal-cerrar" id="cerrarModal" aria-label="Cerrar">×</button>

        </div>

        <div class="modal-cuerpo">

            <div class="informacion-cliente">

                <div class="dato-cliente"><small>Usuario</small><strong id="modalUsuario">—</strong></div>

                <div class="dato-cliente"><small>id_servicio</small><strong id="modalIdServicio">—</strong></div>
                <div class="dato-cliente"><small>Plan comercial</small><strong id="modalPlanComercial">—</strong></div>
                <div class="dato-cliente"><small>Precio mensual</small><strong id="modalPrecioMensual">—</strong></div>

                <div class="dato-cliente"><small>Estado</small><strong id="modalEstado">—</strong></div>

                <div class="dato-cliente"><small>IP remota</small><strong id="modalIp">—</strong></div>

                <div class="dato-cliente"><small>MAC / Caller ID</small><strong id="modalMac">—</strong></div>

                <div class="dato-cliente"><small>Perfil PPPoE local</small><strong id="modalPerfilActual">—</strong></div>
                <div class="dato-cliente"><small>Identidad RADIUS por MAC</small><strong id="modalRadiusEstado">—</strong></div>
                <div class="dato-cliente"><small>Velocidad guardada en RADIUS</small><strong id="modalRadiusVelocidad">—</strong></div>
                <div class="dato-cliente"><small>Lista RADIUS</small><strong id="modalRadiusLista">—</strong></div>

            </div>

            <h3 class="titulo-perfiles">Paquetes disponibles</h3>

            <div class="perfiles-grid">

                <?php foreach ($planesDisponibles as $perfil): ?>

                    <button

                        type="button"

                        class="perfil-card"

                        data-nombre="<?= escapar($perfil['nombre']) ?>"

                        data-etiqueta="<?= escapar(obtenerNombreComercialPerfil($perfil['nombre'])) ?>"

                        data-velocidad="<?= escapar($perfil['velocidad']) ?>"

                        data-rate-limit="<?= escapar($perfil['rate_limit']) ?>"

                    >

                        <strong><?= escapar(obtenerNombreComercialPerfil($perfil['nombre'])) ?></strong>

                        <span>Velocidad configurada: <?= escapar($perfil['velocidad']) ?></span>

                        <span class="perfil-actual-texto"></span>

                    </button>

                <?php endforeach; ?>

            </div>

            <div class="comparacion-perfiles">

                <div class="comparacion-item actual">

                    <small>Plan actual DHCP/RADIUS</small>

                    <strong id="comparacionActual">—</strong>

                    <p id="velocidadActual">Velocidad: —</p>

                </div>

                <div class="comparacion-item nuevo">

                    <small>Paquete propuesto</small>

                    <strong id="comparacionNuevo">Selecciona un paquete</strong>

                    <p id="velocidadNueva">Velocidad: —</p>

                </div>

            </div>
<div class="mensaje-solicitud visible" id="mensajeSolicitud" role="status" aria-live="polite">Modo consulta: puedes comparar la información de los perfiles, pero no se guardarán cambios en RADIUS ni en MikroTik.</div>

        </div>

        <div class="modal-pie">

            <button type="button" class="boton boton-secundario" id="cancelarModal">Cerrar</button>

            <button type="button" class="boton boton-rosa" id="prepararSolicitud" disabled title="Operación deshabilitada en modo consulta">Aplicación deshabilitada</button>

        </div>

    </div>

</div>

<script>

const nombresGrafica = <?= json_encode($datosGraficaNombres, JSON_UNESCAPED_UNICODE) ?>;

const valoresGrafica = <?= json_encode($datosGraficaValores, JSON_NUMERIC_CHECK) ?>;

const lienzoGrafica = document.getElementById('graficaPlanes');

if (lienzoGrafica) {

    const etiquetasValores = {
        id: 'etiquetasValores',
        afterDatasetsDraw(chart) {
            const ctx = chart.ctx;
            const area = chart.chartArea;
            chart.data.datasets.forEach(function (dataset, datasetIndex) {
                const meta = chart.getDatasetMeta(datasetIndex);
                meta.data.forEach(function (barra, indice) {
                    const valor = Number(dataset.data[indice] ?? 0);
                    const posicion = barra.getProps(['x', 'y'], true);
                    const cercaDelBorde = posicion.x > area.right - 42;
                    ctx.save();
                    ctx.font = '700 11px Arial, Helvetica, sans-serif';
                    ctx.textBaseline = 'middle';
                    ctx.textAlign = cercaDelBorde ? 'right' : 'left';
                    ctx.fillStyle = cercaDelBorde ? '#FFFFFF' : '#4D4056';
                    ctx.fillText(
                        valor.toLocaleString('es-MX'),
                        cercaDelBorde ? posicion.x - 9 : posicion.x + 9,
                        posicion.y
                    );
                    ctx.restore();
                });
            });
        }
    };

    new Chart(lienzoGrafica, {
        type: 'bar',
        data: {
            labels: nombresGrafica,
            datasets: [{
                label: 'Servicios',
                data: valoresGrafica,
                backgroundColor: ['#7054C7', '#987FE0', '#C0B0F0'],
                hoverBackgroundColor: ['#5E43B2', '#8267CF', '#AA97E6'],
                borderRadius: 8,
                borderSkipped: false,
                maxBarThickness: 46,
                barPercentage: .76,
                categoryPercentage: .72
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 650, easing: 'easeOutQuart' },
            layout: { padding: { top: 8, right: 34, bottom: 4, left: 2 } },
            plugins: {
                legend: { display: false },
                tooltip: {
                    displayColors: false,
                    backgroundColor: '#38294F',
                    titleColor: '#FFFFFF',
                    bodyColor: '#FFFFFF',
                    padding: 12,
                    cornerRadius: 9,
                    callbacks: {
                        label: function (context) {
                            return Number(context.parsed.x).toLocaleString('es-MX') + ' servicios';
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    grace: '12%',
                    border: { display: false },
                    grid: { color: 'rgba(129,121,135,.14)', drawTicks: false },
                    ticks: {
                        precision: 0,
                        maxTicksLimit: 6,
                        color: '#77717E',
                        font: { size: 10 },
                        callback: function (valor) {
                            return Number(valor).toLocaleString('es-MX');
                        }
                    }
                },
                y: {
                    border: { display: false },
                    grid: { display: false },
                    ticks: {
                        color: '#514D58',
                        padding: 10,
                        font: { size: 11, weight: '600' }
                    }
                }
            }
        },
        plugins: [etiquetasValores]
    });

}

if (document.getElementById('tablaClientes')) { // la tabla de clientes ya no existe en el portal
const buscador = document.getElementById('buscarCliente');
const filas = Array.from(document.querySelectorAll('#tablaClientes tr[data-busqueda]'));
const contadorVisible = document.getElementById('contadorVisible');
const cuerpoTabla = document.getElementById('tablaClientes');
const envolturaTabla = document.querySelector('.tabla-contenedor');
const botonPaginaAnterior = document.getElementById('paginaAnterior');
const botonPaginaSiguiente = document.getElementById('paginaSiguiente');
const indicadorPagina = document.getElementById('indicadorPagina');
const resumenPaginacion = document.getElementById('resumenPaginacion');
const botonDesplazarIzquierda = document.getElementById('desplazarIzquierda');
const botonDesplazarDerecha = document.getElementById('desplazarDerecha');
const botonesFiltroCliente = Array.from(document.querySelectorAll('[data-filtro-cliente]'));
const botonExportarCSV = document.getElementById('exportarCSV');
const tamanoPagina = 10;
let filtroClienteActual = 'todos';
let paginaActual = 1;
let filasFiltradas = filas.slice();
let filaSinResultados = null;

if (filas.length > 0) {
    filaSinResultados = document.createElement('tr');
    filaSinResultados.innerHTML = '<td colspan="12" class="sin-resultados">No se encontraron clientes con ese criterio.</td>';
    filaSinResultados.style.display = 'none';
    cuerpoTabla.appendChild(filaSinResultados);
}

function actualizarFlechasHorizontales() {
    if (!envolturaTabla) return;
    botonDesplazarIzquierda.disabled = envolturaTabla.scrollLeft <= 2;
    botonDesplazarDerecha.disabled = envolturaTabla.scrollLeft + envolturaTabla.clientWidth >= envolturaTabla.scrollWidth - 2;
}

function dibujarPagina() {
    const total = filasFiltradas.length;
    const totalPaginas = Math.max(1, Math.ceil(total / tamanoPagina));
    paginaActual = Math.min(Math.max(1, paginaActual), totalPaginas);
    const inicio = total === 0 ? 0 : (paginaActual - 1) * tamanoPagina;
    const fin = Math.min(inicio + tamanoPagina, total);

    filas.forEach(function (fila) { fila.style.display = 'none'; });
    filasFiltradas.slice(inicio, fin).forEach(function (fila) { fila.style.display = ''; });

    if (filaSinResultados) filaSinResultados.style.display = total === 0 ? '' : 'none';

    contadorVisible.textContent = total + (total === 1 ? ' coincidencia' : ' coincidencias');
    resumenPaginacion.textContent = total === 0
        ? 'Sin clientes que mostrar'
        : 'Mostrando ' + (inicio + 1) + '–' + fin + ' de ' + total.toLocaleString('es-MX') + ' clientes';
    indicadorPagina.textContent = 'Página ' + paginaActual + ' de ' + totalPaginas;
    botonPaginaAnterior.disabled = paginaActual <= 1 || total === 0;
    botonPaginaSiguiente.disabled = paginaActual >= totalPaginas || total === 0;
    actualizarFlechasHorizontales();
}

function aplicarFiltro() {
    const texto = buscador.value.trim().toLocaleLowerCase('es');
    filasFiltradas = filas.filter(function (fila) {
        const coincideTexto = (fila.dataset.busqueda || '').toLocaleLowerCase('es').includes(texto);
        let coincideFiltro = true;

        if (filtroClienteActual === 'conectado') coincideFiltro = fila.dataset.conectado === '1';
        else if (filtroClienteActual === 'habilitado') coincideFiltro = fila.dataset.estado === 'habilitado';
        else if (filtroClienteActual === 'deshabilitado') coincideFiltro = fila.dataset.estado === 'deshabilitado';
        else if (filtroClienteActual === 'relacionado') coincideFiltro = fila.dataset.relacion === 'relacionado';
        else if (filtroClienteActual === 'pendiente') coincideFiltro = fila.dataset.relacion === 'pendiente';
        else if (filtroClienteActual === 'sin_registro') coincideFiltro = fila.dataset.relacion === 'sin_registro';
        else if (filtroClienteActual === 'duplicado') coincideFiltro = fila.dataset.duplicado === '1';

        return coincideTexto && coincideFiltro;
    });
    paginaActual = 1;
    dibujarPagina();
}

buscador.addEventListener('input', aplicarFiltro);

botonesFiltroCliente.forEach(function (boton) {
    boton.addEventListener('click', function () {
        filtroClienteActual = boton.dataset.filtroCliente || 'todos';
        botonesFiltroCliente.forEach(function (otro) {
            const activo = otro === boton;
            otro.classList.toggle('activo', activo);
            otro.setAttribute('aria-pressed', activo ? 'true' : 'false');
        });
        aplicarFiltro();
    });
});

if (botonExportarCSV) {
    botonExportarCSV.addEventListener('click', function () {
        const encabezados = Array.from(document.querySelectorAll('.tabla-contenedor thead th'))
            .slice(0, 11)
            .map(function (celda) { return celda.innerText.trim(); });
        const datos = filasFiltradas.map(function (fila) {
            return Array.from(fila.cells).slice(0, 11).map(function (celda) {
                return '"' + celda.innerText.trim().replace(/\s+/g, ' ').replace(/"/g, '""') + '"';
            });
        });
        const contenido = '\uFEFF' + [encabezados, ...datos].map(function (fila) { return fila.join(','); }).join('\r\n');
        const archivo = new Blob([contenido], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(archivo);
        const enlace = document.createElement('a');
        enlace.href = url;
        enlace.download = 'clientes-pppoe-' + new Date().toISOString().slice(0, 10) + '.csv';
        document.body.appendChild(enlace);
        enlace.click();
        enlace.remove();
        URL.revokeObjectURL(url);
    });
}

botonPaginaAnterior.addEventListener('click', function () {
    if (paginaActual > 1) {
        paginaActual--;
        dibujarPagina();
        envolturaTabla.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
});
botonPaginaSiguiente.addEventListener('click', function () {
    const totalPaginas = Math.max(1, Math.ceil(filasFiltradas.length / tamanoPagina));
    if (paginaActual < totalPaginas) {
        paginaActual++;
        dibujarPagina();
        envolturaTabla.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
});

function desplazarTabla(direccion) {
    if (!envolturaTabla) return;
    envolturaTabla.scrollBy({
        left: direccion * Math.max(280, Math.round(envolturaTabla.clientWidth * 0.75)),
        behavior: 'smooth'
    });
}

botonDesplazarIzquierda.addEventListener('click', function () { desplazarTabla(-1); });
botonDesplazarDerecha.addEventListener('click', function () { desplazarTabla(1); });
if (envolturaTabla) {
    envolturaTabla.addEventListener('scroll', actualizarFlechasHorizontales, { passive: true });
    window.addEventListener('resize', actualizarFlechasHorizontales);
}

dibujarPagina();
} // fin tabla de clientes

const modal = document.getElementById('modalPlanes');

const tarjetasPerfiles = Array.from(document.querySelectorAll('.perfil-card'));

const mensajeSolicitud = document.getElementById('mensajeSolicitud');

let servicioSeleccionado = null;

let perfilPropuesto = null;

function texto(id, valor) {

    document.getElementById(id).textContent = valor || '—';

}

function abrirModal(datos) {

    servicioSeleccionado = datos;

    perfilPropuesto = null;

    texto('modalUsuario', datos.usuario);

    texto('modalIdServicio', datos.idServicio || 'SIN RELACIÓN');
    texto('modalPlanComercial', datos.plan || 'Sin asignación');
    texto('modalPrecioMensual', datos.precio ? '$' + datos.precio : 'No registrado');

    texto('modalEstado', datos.disabled ? 'Deshabilitada' : 'Habilitada');

    texto('modalIp', datos.ip);

    texto('modalMac', datos.mac);

    texto('modalPerfilActual', datos.perfil);
    texto('modalRadiusEstado', datos.radiusConfirmada ? 'IDENTIDAD CONFIRMADA' : 'SIN IDENTIDAD RADIUS CONFIRMADA');
    texto('modalRadiusVelocidad', datos.radiusVelocidad || 'No disponible');
    texto('modalRadiusLista', datos.radiusLista || 'No definida');
    const botonAplicarRadius = document.getElementById('prepararSolicitud');
    botonAplicarRadius.disabled = true;
    botonAplicarRadius.title = 'Operación deshabilitada en modo consulta';

    const normalizarRateLimit = valor =>
        String(valor || '').trim().split(/\s+/).filter(Boolean).join(' ');

    const rateRadiusActual = normalizarRateLimit(datos.radiusVelocidad);
    const perfilRadiusActual = datos.radiusConfirmada && rateRadiusActual !== ''
        ? (tarjetasPerfiles.find(tarjeta =>
            normalizarRateLimit(tarjeta.dataset.rateLimit) === rateRadiusActual
        ) || null)
        : null;

    texto('comparacionActual', perfilRadiusActual
        ? perfilRadiusActual.dataset.etiqueta
        : (datos.radiusConfirmada ? 'Plan RADIUS por identificar' : 'Sin datos RADIUS'));

    const velocidadRadiusActual = perfilRadiusActual
        ? perfilRadiusActual.dataset.velocidad
        : (datos.radiusVelocidad || 'No disponible');

    texto('velocidadActual',
        'Velocidad: ' + velocidadRadiusActual +
        ' · Lista: ' + (datos.radiusLista || 'No definida'));

    texto('comparacionNuevo', 'Selecciona un paquete');

    texto('velocidadNueva', 'Velocidad: —');

    mensajeSolicitud.textContent = 'Modo consulta: puedes comparar la información de los perfiles, pero no se guardarán cambios en RADIUS ni en MikroTik.';
    mensajeSolicitud.classList.add('visible');

    tarjetasPerfiles.forEach(function (tarjeta) {
        const actual = tarjeta === perfilRadiusActual;

        tarjeta.className = 'perfil-card' +
            (actual ? ' es-perfil-actual seleccionado' : '');

        tarjeta.querySelector('.perfil-actual-texto').textContent =
            actual ? '✓ Plan DHCP/RADIUS actual' : '';

        if (actual) {
            perfilPropuesto = {
                nombre: tarjeta.dataset.nombre,
                etiqueta: tarjeta.dataset.etiqueta,
                velocidad: tarjeta.dataset.velocidad,
                rateLimit: tarjeta.dataset.rateLimit
            };

            texto('comparacionNuevo', perfilPropuesto.etiqueta);
            texto('velocidadNueva', 'Velocidad: ' + perfilPropuesto.velocidad);
        }
    });

    modal.classList.add('abierto');

    document.body.style.overflow = 'hidden';

}

function cerrarModal() {

    modal.classList.remove('abierto');
    document.body.style.overflow = '';

}

document.querySelectorAll('.boton-ver-planes').forEach(function (boton) {

    boton.addEventListener('click', function () {

        abrirModal({

            usuario: this.dataset.usuario,

            idServicio: this.dataset.idServicio,

            perfil: this.dataset.perfil,

            plan: this.dataset.plan,
            precio: this.dataset.precio,

            velocidad: this.dataset.velocidad,

            ip: this.dataset.ip,

            mac: this.dataset.mac,

            radiusConfirmada: this.dataset.radiusConfirmada === '1',
            radiusVelocidad: this.dataset.radiusVelocidad,
            radiusLista: this.dataset.radiusLista,
            radiusUsername: this.dataset.radiusUsername,

            disabled: this.dataset.disabled === '1'

        });

    });

});

tarjetasPerfiles.forEach(function (tarjeta) {

    tarjeta.addEventListener('click', function () {

        if (!servicioSeleccionado) return;

        perfilPropuesto = {

            nombre: this.dataset.nombre,

            etiqueta: this.dataset.etiqueta,

            velocidad: this.dataset.velocidad,
            rateLimit: this.dataset.rateLimit

        };

        tarjetasPerfiles.forEach(function (otra) {

            const rateActualRadius = String(servicioSeleccionado.radiusVelocidad || '')
                .trim().split(/\s+/).filter(Boolean).join(' ');
            const rateTarjeta = String(otra.dataset.rateLimit || '')
                .trim().split(/\s+/).filter(Boolean).join(' ');
            const actual = servicioSeleccionado.radiusConfirmada
                && rateActualRadius !== ''
                && rateTarjeta === rateActualRadius;

            const seleccionada = otra === tarjeta;

            otra.className = 'perfil-card' +

                (actual ? ' es-perfil-actual' : '') +

                (seleccionada ? ' seleccionado' : '');

            otra.querySelector('.perfil-actual-texto').textContent =

                actual ? '✓ Plan DHCP/RADIUS actual' : '';

        });

        texto('comparacionNuevo', perfilPropuesto.etiqueta);

        texto('velocidadNueva', 'Velocidad: ' + perfilPropuesto.velocidad);

        mensajeSolicitud.textContent = 'Modo consulta: los perfiles son referencias para comparar; no se aplicará ningún cambio.';
        mensajeSolicitud.classList.add('visible');

    });

});

// La operación de escritura se mantiene deshabilitada en modo consulta.

document.getElementById('cerrarModal').addEventListener('click', cerrarModal);

document.getElementById('cancelarModal').addEventListener('click', cerrarModal);

modal.addEventListener('click', function (evento) {

    if (evento.target === modal) cerrarModal();

});

document.addEventListener('keydown', function (evento) {

    if (evento.key === 'Escape' && modal.classList.contains('abierto')) {

        cerrarModal();

    }

});

</script>

<script>
(function () {
    const TAMANO_PAGINA = 10;

    function configurarPaginacionTabla(config) {
        const tbody = document.getElementById(config.tbodyId);
        const anterior = document.getElementById(config.prevId);
        const siguiente = document.getElementById(config.nextId);
        const indicador = document.getElementById(config.pageId);
        const resumen = document.getElementById(config.summaryId);
        const nav = document.getElementById(config.navId);
        if (!tbody || !anterior || !siguiente || !indicador || !resumen || !nav) return null;

        const filas = Array.from(tbody.querySelectorAll(config.rowSelector || 'tr'));
        let paginaActual = 1;
        let filasFiltradas = filas.slice();

        function renderizar(paginaSolicitada) {
            const total = filasFiltradas.length;
            const totalPaginas = Math.max(1, Math.ceil(total / TAMANO_PAGINA));
            paginaActual = Math.min(Math.max(1, paginaSolicitada || 1), totalPaginas);
            const inicio = total === 0 ? 0 : (paginaActual - 1) * TAMANO_PAGINA;
            const fin = Math.min(inicio + TAMANO_PAGINA, total);
            const visibles = new Set(filasFiltradas.slice(inicio, fin));

            filas.forEach(function (fila) {
                fila.style.display = visibles.has(fila) ? '' : 'none';
            });

            resumen.textContent = total === 0
                ? 'Sin registros que mostrar'
                : 'Mostrando ' + (inicio + 1) + '–' + fin + ' de ' + total.toLocaleString('es-MX') + ' registros';
            indicador.textContent = 'Página ' + paginaActual + ' de ' + totalPaginas;
            anterior.disabled = paginaActual <= 1 || total === 0;
            siguiente.disabled = paginaActual >= totalPaginas || total === 0;
            nav.style.display = filas.length > 0 ? 'flex' : 'none';
        }

        function establecerFilas(nuevasFilas) {
            filasFiltradas = nuevasFilas;
            renderizar(1);
        }

        anterior.addEventListener('click', function () {
            if (paginaActual > 1) renderizar(paginaActual - 1);
        });
        siguiente.addEventListener('click', function () {
            const totalPaginas = Math.max(1, Math.ceil(filasFiltradas.length / TAMANO_PAGINA));
            if (paginaActual < totalPaginas) renderizar(paginaActual + 1);
        });

        renderizar(1);
        return { filas, establecerFilas, getFilasFiltradas: () => filasFiltradas };
    }

    // DHCP: filtros y búsqueda consideran todos los registros; se muestran 10 por página.
    const tablaDhcp = document.getElementById('tablaDhcp');
    const buscadorDhcp = document.getElementById('buscarDhcp');
    const contadorDhcp = document.getElementById('contadorDhcp');
    const botonesDhcp = Array.from(document.querySelectorAll('[data-filtro-dhcp]'));
    const exportarDhcp = document.getElementById('exportarDhcpCSV');

    if (tablaDhcp && buscadorDhcp && contadorDhcp) {
        const paginadorDhcp = configurarPaginacionTabla({
            tbodyId: 'tablaDhcp', navId: 'paginacionDhcp',
            summaryId: 'resumenPaginacionDhcp', pageId: 'indicadorPaginaDhcp',
            prevId: 'paginaAnteriorDhcp', nextId: 'paginaSiguienteDhcp',
            rowSelector: 'tr[data-busqueda]'
        });
        let filtroDhcp = 'todos';
        let filasVisiblesDhcp = paginadorDhcp ? paginadorDhcp.filas.slice() : [];

        function aplicarFiltroDhcp() {
            const texto = buscadorDhcp.value.trim().toLocaleLowerCase('es');
            filasVisiblesDhcp = (paginadorDhcp ? paginadorDhcp.filas : []).filter(function (fila) {
                const coincideTexto = (fila.dataset.busqueda || '').toLocaleLowerCase('es').includes(texto);
                let coincideFiltro = true;
                if (filtroDhcp === 'bound') coincideFiltro = fila.dataset.estado === 'bound';
                else if (filtroDhcp === 'relacionado') coincideFiltro = fila.dataset.relacion === 'relacionado';
                else if (filtroDhcp === 'pendiente') coincideFiltro = fila.dataset.relacion === 'pendiente';
                else if (filtroDhcp === 'radius') coincideFiltro = fila.dataset.radius === '1';
                return coincideTexto && coincideFiltro;
            });
            contadorDhcp.textContent = filasVisiblesDhcp.length + (filasVisiblesDhcp.length === 1 ? ' coincidencia' : ' coincidencias');
            if (paginadorDhcp) paginadorDhcp.establecerFilas(filasVisiblesDhcp);
        }

        buscadorDhcp.addEventListener('input', aplicarFiltroDhcp);
        botonesDhcp.forEach(function (boton) {
            boton.addEventListener('click', function () {
                filtroDhcp = boton.dataset.filtroDhcp || 'todos';
                botonesDhcp.forEach(function (otro) {
                    const activo = otro === boton;
                    otro.classList.toggle('activo', activo);
                    otro.setAttribute('aria-pressed', activo ? 'true' : 'false');
                });
                aplicarFiltroDhcp();
            });
        });

        if (exportarDhcp) {
            exportarDhcp.addEventListener('click', function () {
                const encabezados = Array.from(tablaDhcp.closest('table').querySelectorAll('thead th'))
                    .map(function (celda) { return celda.innerText.trim(); });
                const datos = filasVisiblesDhcp.map(function (fila) {
                    return Array.from(fila.cells).map(function (celda) {
                        return '"' + celda.innerText.trim().replace(/\s+/g, ' ').replace(/"/g, '""') + '"';
                    });
                });
                const contenido = '\uFEFF' + [encabezados, ...datos]
                    .map(function (fila) { return fila.join(','); }).join('\r\n');
                const archivo = new Blob([contenido], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(archivo);
                const enlace = document.createElement('a');
                enlace.href = url;
                enlace.download = 'directorio-dhcp-' + new Date().toISOString().slice(0, 10) + '.csv';
                document.body.appendChild(enlace);
                enlace.click();
                enlace.remove();
                URL.revokeObjectURL(url);
            });
        }
    }

    // Historial y sesiones RADIUS: 10 registros por página.
    configurarPaginacionTabla({
        tbodyId: 'tablaHistorial', navId: 'paginacionHistorial',
        summaryId: 'resumenPaginacionHistorial', pageId: 'indicadorPaginaHistorial',
        prevId: 'paginaAnteriorHistorial', nextId: 'paginaSiguienteHistorial'
    });
    configurarPaginacionTabla({
        tbodyId: 'tablaRadius', navId: 'paginacionRadius',
        summaryId: 'resumenPaginacionRadius', pageId: 'indicadorPaginaRadius',
        prevId: 'paginaAnteriorRadius', nextId: 'paginaSiguienteRadius'
    });
})();
</script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const plan = document.getElementById('plan_nuevo');
    const perfil = document.getElementById('perfil_nuevo');
    if (!plan || !perfil) return;

    function filtrarPerfiles() {
        const planSeleccionado = plan.value;
        perfil.value = '';

        let disponibles = 0;
        for (const opcion of perfil.options) {
            if (!opcion.value) continue;
            const coincide = planSeleccionado !== ''
                && opcion.dataset.plan === planSeleccionado;
            opcion.hidden = !coincide;
            opcion.disabled = !coincide;
            if (coincide) disponibles++;
        }

        perfil.options[0].text = disponibles
            ? 'Selecciona un perfil'
            : 'No hay perfiles relacionados para este plan';
    }

    plan.addEventListener('change', filtrarPerfiles);
    filtrarPerfiles();
});
</script>

</body>

</html>