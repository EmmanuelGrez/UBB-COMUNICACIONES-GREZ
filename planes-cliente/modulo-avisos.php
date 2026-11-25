<?php
/*
 * AVISOS DE EQUIPOS  (?modulo=avisos)
 * Lee los avisos de la plataforma de Jamileth (inventario): equipo asignado / retirado de un servicio.
 * Para cada asignación ofrece "Dar de alta PPPoE" (abre el alta con el modelo y la MAC ya llenos).
 * "Marcar revisado" avisa a la plataforma (POST notificaciones.php?revisada=ID). No toca RADIUS ni el router.
 *
 * Llave: /etc/ubb-panel/jamileth-api.key (X-API-Key). API: http://192.168.100.221:8081/api/v1
 */

require_once __DIR__ . '/avisos-api.php';

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
