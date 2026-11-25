<?php
/*
 * NOTIFICACIONES EN VIVO  (?modulo=notificaciones&bit=<último id visto>)  -> JSON
 * Lo consulta cada 30 s el aviso flotante de la esquina inferior derecha (portalNotificacionesJs).
 * Devuelve:
 *   - avisos de equipos sin revisar de la plataforma (asignado / retirado),
 *   - suspensiones y reactivaciones de la bitácora con id mayor a "bit".
 * Solo lectura. Usa del index.php: $dbConfig, obtenerPDO().
 */

require_once __DIR__ . '/avisos-api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respuesta = ['bit_max' => 0, 'avisos' => [], 'eventos' => [], 'errores' => []];
$desde = max(0, (int) ($_GET['bit'] ?? 0));

$ra = avApi('GET', '/notificaciones.php', 3);
if ($ra['ok']) {
    foreach ((array) ($ra['datos']['avisos'] ?? []) as $a) {
        $eq = avDatosEquipo($a);
        $asignado = ($a['tipo'] ?? '') === 'EQUIPO_ASIGNADO';
        $respuesta['avisos'][] = [
            'id' => (int) ($a['id'] ?? 0),
            'tipo' => $asignado ? 'asignado' : 'retirado',
            'titulo' => ($asignado ? 'Equipo asignado' : 'Equipo retirado') . ' · ' . (string) ($a['codigo_servicio'] ?? ''),
            'texto' => trim(($eq['nombre'] !== '' ? $eq['nombre'] . ' · ' : '') . $eq['mac'] . ' · ' . (string) ($a['cliente'] ?? ''), ' ·'),
            'enlace' => '?modulo=avisos',
        ];
    }
} else {
    $respuesta['errores'][] = 'avisos';
}

try {
    $config = $dbConfig;
    $config['database'] = 'radius';
    $pdo = obtenerPDO($config);
    $respuesta['bit_max'] = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM ubb_bitacora')->fetchColumn();
    if ($desde > 0) {
        $st = $pdo->prepare("SELECT id, mac, ip, accion, origen FROM ubb_bitacora
                             WHERE id > ? AND accion IN ('suspender', 'reactivar') ORDER BY id LIMIT 20");
        $st->execute([$desde]);
        foreach ($st->fetchAll() as $b) {
            $susp = $b['accion'] === 'suspender';
            $respuesta['eventos'][] = [
                'id' => (int) $b['id'],
                'tipo' => $susp ? 'suspendido' : 'reactivado',
                'titulo' => ($susp ? 'Cliente suspendido' : 'Cliente reactivado') . ' · ' . (string) $b['ip'],
                'texto' => (string) $b['mac'] . ' · desde ' . ([
                    'PANEL' => 'el panel', 'PAGOS' => 'UBB Pagos', 'UISP' => 'UISP',
                ][(string) $b['origen']] ?? (string) $b['origen']),
                'enlace' => '?modulo=bitacora&accion=' . ($susp ? 'suspender' : 'reactivar'),
            ];
        }
    }
} catch (Throwable $e) {
    error_log('UBB notificaciones: ' . $e->getMessage());
    $respuesta['errores'][] = 'bitacora';
}

echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);
