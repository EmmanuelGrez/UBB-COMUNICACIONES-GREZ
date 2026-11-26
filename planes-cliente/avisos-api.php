<?php
/*
 * Acceso a la API de la plataforma de Jamileth (avisos de equipos). Lo usan modulo-avisos.php y
 * "Actividad reciente" en Perfiles MikroTik. Llave: /etc/ubb-panel/jamileth-api.key (X-API-Key).
 */

const AV_API = 'http://192.168.100.221:8081/api/v1';
const AV_LLAVE = '/etc/ubb-panel/jamileth-api.key';
const AV_MODELOS = ['TP5', 'TP4', 'TPX', 'TPC', 'TPE', 'UAC'];

/** Llama a la API de la plataforma. Devuelve ['ok' => bool, 'datos' => array, 'error' => string]. */
function avApi(string $metodo, string $ruta, int $espera = 8): array
{
    $llave = trim((string) @file_get_contents(AV_LLAVE));
    if ($llave === '') {
        return ['ok' => false, 'datos' => [], 'error' => 'Falta la llave de la plataforma (' . AV_LLAVE . ').'];
    }
    $ctx = stream_context_create(['http' => [
        'method' => $metodo,
        'header' => "X-API-Key: $llave\r\nAccept: application/json\r\nContent-Length: 0\r\n",
        'timeout' => $espera,
        'ignore_errors' => true,
    ]]);
    $cuerpo = @file_get_contents(AV_API . $ruta, false, $ctx);
    if ($cuerpo === false) {
        return ['ok' => false, 'datos' => [], 'error' => 'No responde la plataforma (' . AV_API . ').'];
    }
    $datos = json_decode($cuerpo, true);
    if (!is_array($datos)) {
        return ['ok' => false, 'datos' => [], 'error' => 'La plataforma respondió algo que no se entiende.'];
    }
    if (($datos['ok'] ?? false) !== true) {
        return ['ok' => false, 'datos' => $datos, 'error' => (string) ($datos['mensaje'] ?? 'La plataforma respondió con error.')];
    }
    return ['ok' => true, 'datos' => $datos, 'error' => ''];
}

/** Saca MAC y nombre de red del aviso (campos propios si existen; si no, del texto del mensaje). */
function avDatosEquipo(array $a): array
{
    $msg = (string) ($a['mensaje'] ?? '');
    $mac = (string) ($a['mac'] ?? '');
    if ($mac === '' && preg_match('/([0-9A-F]{2}(?::[0-9A-F]{2}){5})/i', $msg, $m)) {
        $mac = $m[1];
    }
    $nombre = (string) ($a['nombre_red'] ?? '');
    if ($nombre === '' && preg_match('/nombre de red\s+([A-Za-z0-9_-]+)/i', $msg, $m)) {
        $nombre = $m[1];
    }
    $modelo = '';
    if (preg_match('/^UBB-([A-Z0-9]{3})/i', $nombre, $m) && in_array(strtoupper($m[1]), AV_MODELOS, true)) {
        $modelo = strtoupper($m[1]);
    }
    return ['mac' => strtoupper($mac), 'nombre' => $nombre, 'modelo' => $modelo];
}

/**
 * ¿Está esta MAC en el inventario de la plataforma? Prueba la MAC tal cual y las vecinas (±1),
 * porque en los TP-Link la MAC de la etiqueta (LAN) y la que ve el router (WAN) difieren en 1.
 * estado: EN_INVENTARIO | NO_REGISTRADA | SIN_RESPUESTA (la plataforma no contestó: no se sabe).
 */
function avInventario(string $mac): array
{
    $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac));
    if (strlen($hex) !== 12) {
        return ['estado' => 'NO_REGISTRADA', 'mensaje' => 'MAC no válida.'];
    }
    $base = hexdec($hex);
    $sinRespuesta = '';
    foreach ([0, -1, 1] as $delta) {
        $h = str_pad(strtoupper(dechex($base + $delta)), 12, '0', STR_PAD_LEFT);
        $m = implode(':', str_split($h, 2));
        $r = avApi('GET', '/equipos.php?mac=' . rawurlencode($m), 5);
        if ($r['ok'] && !empty($r['datos']['equipo'])) {
            $eq = $r['datos']['equipo'];
            $serv = $r['datos']['servicio'] ?? null;
            return [
                'estado' => 'EN_INVENTARIO',
                'mac' => $m,
                'vecina' => $delta !== 0,
                'nombre_red' => (string) ($eq['nombre_red'] ?? ''),
                'estado_inventario' => (string) ($eq['estado_inventario'] ?? ''),
                'asignado' => (bool) ($r['datos']['asignado'] ?? false),
                'servicio' => is_array($serv) ? (string) ($serv['id_servicio'] ?? $serv['codigo'] ?? json_encode($serv)) : (string) ($serv ?? ''),
            ];
        }
        // Respondió "no está registrada" (ok=false con mensaje) vs. no respondió (sin datos).
        if (!$r['ok'] && $r['datos'] === []) {
            $sinRespuesta = $r['error'];
        }
    }
    if ($sinRespuesta !== '') {
        return ['estado' => 'SIN_RESPUESTA', 'mensaje' => $sinRespuesta];
    }
    return ['estado' => 'NO_REGISTRADA', 'mensaje' => 'La MAC no está registrada en el inventario de la plataforma.'];
}

/** Revisa el inventario antes de un alta. Lanza error si la MAC no está; devuelve una nota para el mensaje final. */
function avExigirInventario(string $mac): string
{
    $inv = avInventario($mac);
    if ($inv['estado'] === 'NO_REGISTRADA') {
        throw new RuntimeException("La MAC $mac no está en el inventario de la plataforma (tampoco la de la etiqueta ±1). "
            . 'Pídele a Jamileth que registre primero el equipo. No se dio de alta.');
    }
    if ($inv['estado'] === 'SIN_RESPUESTA') {
        return ' OJO: no se pudo revisar el inventario de la plataforma (' . $inv['mensaje'] . '); confírmalo con Jamileth.';
    }
    return ' Inventario: ' . ($inv['nombre_red'] ?: 'sin nombre de red') . ' (' . $inv['mac'] . ($inv['vecina'] ? ', MAC de la etiqueta' : '') . ')'
        . ($inv['asignado'] ? ', asignado' . ($inv['servicio'] !== '' ? ' al servicio ' . $inv['servicio'] : '') : ', todavía sin asignar a un servicio') . '.';
}

