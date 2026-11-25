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

