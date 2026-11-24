<?php
/*
 * AVISOS DE EQUIPOS  (?modulo=avisos)
 * Lee los avisos de la plataforma de Jamileth (inventario): equipo asignado / retirado de un servicio.
 * Para cada asignación ofrece "Dar de alta PPPoE" (abre el alta con el modelo y la MAC ya llenos).
 * "Marcar revisado" avisa a la plataforma (POST notificaciones.php?revisada=ID). No toca RADIUS ni el router.
 *
 * Llave: /etc/ubb-panel/jamileth-api.key (X-API-Key). API: http://192.168.100.221:8081/api/v1
 */

const AV_API = 'http://192.168.100.221:8081/api/v1';
const AV_LLAVE = '/etc/ubb-panel/jamileth-api.key';
const AV_MODELOS = ['TP5', 'TP4', 'TPX', 'TPC', 'TPE', 'UAC'];

/** Llama a la API de la plataforma. Devuelve ['ok' => bool, 'datos' => array, 'error' => string]. */
function avApi(string $metodo, string $ruta): array
{
    $llave = trim((string) @file_get_contents(AV_LLAVE));
    if ($llave === '') {
        return ['ok' => false, 'datos' => [], 'error' => 'Falta la llave de la plataforma (' . AV_LLAVE . ').'];
    }
    $ctx = stream_context_create(['http' => [
        'method' => $metodo,
        'header' => "X-API-Key: $llave\r\nAccept: application/json\r\nContent-Length: 0\r\n",
        'timeout' => 8,
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

$csrfAv = $_SESSION['csrf_avisos'] ?? '';
if (!is_string($csrfAv) || $csrfAv === '') {
    $csrfAv = bin2hex(random_bytes(32));
    $_SESSION['csrf_avisos'] = $csrfAv;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'revisado') {
    $id = (int) ($_POST['id'] ?? 0);
    if (!hash_equals($csrfAv, (string) ($_POST['csrf_avisos'] ?? '')) || $id <= 0) {
        $_SESSION['flash_avisos'] = ['error', 'La sesión del formulario venció. Recarga la pantalla.'];
    } else {
        $r = avApi('POST', '/notificaciones.php?revisada=' . $id);
        $_SESSION['flash_avisos'] = $r['ok'] ? ['ok', "Aviso #$id marcado como revisado."] : ['error', "No se pudo marcar el aviso #$id: {$r['error']}"];
    }
    header('Location: ?modulo=avisos');
    exit;
}
$flashAv = $_SESSION['flash_avisos'] ?? null;
unset($_SESSION['flash_avisos']);

$respAv = avApi('GET', '/notificaciones.php');
$avisos = $respAv['ok'] ? (array) ($respAv['datos']['avisos'] ?? []) : [];

require_once __DIR__ . '/layout-portal.php';
portalInicio('Avisos de equipos', 'Equipos que la plataforma asignó o retiró de un servicio. Da de alta en RADIUS solo MACs que estén aquí.', 'avisos');
?>

    <?php if ($flashAv): ?><div class="<?= $flashAv[0] === 'ok' ? 'ok' : 'error' ?>"><?= escapar($flashAv[1]) ?></div><?php endif; ?>
    <?php if (!$respAv['ok']): ?><div class="error"><?= escapar($respAv['error']) ?></div><?php endif; ?>

    <section class="general">
        <div class="g-total"><b><?= count($avisos) ?></b><span>avisos pendientes<br>de revisar</span></div>
        <div><b><?= count(array_filter($avisos, fn ($a) => ($a['tipo'] ?? '') === 'EQUIPO_ASIGNADO')) ?></b><span>asignados</span></div>
        <div><b><?= count(array_filter($avisos, fn ($a) => ($a['tipo'] ?? '') === 'EQUIPO_RETIRADO')) ?></b><span>retirados</span></div>
    </section>

    <section class="caja">
        <div class="tabla">
        <table>
            <thead><tr><th>Fecha</th><th>Aviso</th><th>Servicio</th><th>Equipo</th><th></th></tr></thead>
            <tbody>
            <?php if ($avisos === [] && $respAv['ok']): ?><tr><td colspan="5">No hay avisos pendientes.</td></tr><?php endif; ?>
            <?php foreach ($avisos as $a):
                $eq = avDatosEquipo($a);
                $tipo = (string) ($a['tipo'] ?? '');
                $asignado = $tipo === 'EQUIPO_ASIGNADO';
            ?>
                <tr>
                    <td data-t="Fecha" class="mono"><?= escapar(date('d/m H:i', strtotime((string) ($a['fecha_creacion'] ?? 'now')))) ?></td>
                    <td data-t="Aviso">
                        <span class="pill <?= $asignado ? 'ok' : ($tipo === 'EQUIPO_RETIRADO' ? 'susp' : 'gris') ?>"><?= escapar($asignado ? 'Asignado' : ($tipo === 'EQUIPO_RETIRADO' ? 'Retirado' : str_replace('_', ' ', $tipo))) ?></span>
                        <br><small><?= escapar((string) ($a['mensaje'] ?? '')) ?></small>
                    </td>
                    <td data-t="Servicio"><b><?= escapar((string) ($a['codigo_servicio'] ?? '')) ?></b>
                        <?php if (!empty($a['uisp_service_id'])): ?><br><small>UISP #<?= escapar((string) $a['uisp_service_id']) ?></small><?php endif; ?>
                        <br><small><?= escapar((string) ($a['cliente'] ?? '')) ?></small></td>
                    <td data-t="Equipo" class="mono"><?= escapar($eq['nombre'] ?: '—') ?><br><small><?= escapar($eq['mac'] ?: '—') ?></small></td>
                    <td><div class="acciones">
                        <?php if ($asignado && $eq['mac'] !== ''): ?>
                            <a class="boton b-alta" href="?modulo=radius-pppoe&<?= escapar(http_build_query(['alta_mac' => $eq['mac'], 'alta_modelo' => $eq['modelo']])) ?>#pp-alta">Dar de alta PPPoE</a>
                        <?php elseif ($tipo === 'EQUIPO_RETIRADO'): ?>
                            <span class="tenue">Revisar si hay que quitarlo de RADIUS</span>
                        <?php endif; ?>
                        <form method="post" action="?modulo=avisos" style="display:inline">
                            <input type="hidden" name="csrf_avisos" value="<?= escapar($csrfAv) ?>">
                            <input type="hidden" name="accion" value="revisado">
                            <input type="hidden" name="id" value="<?= (int) ($a['id'] ?? 0) ?>">
                            <button class="b-sec" type="submit">Marcar revisado</button>
                        </form>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>

    <p class="nota">"Dar de alta PPPoE" abre el alta con el modelo y la MAC ya llenos; ahí revisas IP y perfil y confirmas. Los avisos de SRV-1342 son pruebas de la plataforma.</p>
    <style>a.boton.b-alta{display:inline-block;padding:8px 14px;border-radius:9px;font-weight:600;font-size:13px;text-decoration:none;margin-right:6px}</style>
<?php portalFin(); ?>
