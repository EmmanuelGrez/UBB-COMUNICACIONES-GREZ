<?php
/*
 * AVISOS DE EQUIPOS  (?modulo=avisos)
 * Lee los avisos de la plataforma de Jamileth (inventario): equipo asignado / retirado de un servicio,
 * y solicitudes de cambio de paquete.
 * - Asignado: "Dar de alta PPPoE" (abre el alta con el modelo y la MAC ya llenos).
 * - Cambio de paquete: busca el equipo del servicio (misma relación que usa la API), muestra plan actual
 *   y pedido, y "Aplicar cambio" lo cambia en RADIUS + router (con clave de acciones) y marca el aviso.
 *   "Marcar revisado" no se deja mientras el equipo no tenga el plan pedido.
 * "Marcar revisado" avisa a la plataforma (POST notificaciones.php?revisada=ID).
 *
 * Llave: /etc/ubb-panel/jamileth-api.key (X-API-Key). API: http://192.168.100.221:8081/api/v1
 */

require_once __DIR__ . '/avisos-api.php';
require_once __DIR__ . '/radius-dhcp-acciones.php';

const AV_TIPO_CAMBIO = 'SOLICITUD_CAMBIO_PAQUETE';
/* Velocidad comercial (Mbps del plan) -> lista del router (prefijo "NM"). */
const AV_MBPS_A_LISTA = [25 => '20M', 35 => '30M', 60 => '60M', 65 => '60M'];

$csrfAv = $_SESSION['csrf_avisos'] ?? '';
if (!is_string($csrfAv) || $csrfAv === '') {
    $csrfAv = bin2hex(random_bytes(32));
    $_SESSION['csrf_avisos'] = $csrfAv;
}

/** Datos de una solicitud de cambio: servicio, planes de / a (nombre y Mbps). Null si no se entiende. */
function avDatosCambio(array $a): ?array
{
    $msg = (string) ($a['mensaje'] ?? '');
    $id = (int) ($a['id_servicio'] ?? 0);
    if ($id <= 0 && preg_match('/^\s*SRV-?\s*(\d+)\s*$/i', (string) ($a['codigo_servicio'] ?? ''), $m)) {
        $id = (int) $m[1];
    }
    // "servicio #1339" o "servicio SRV-1347"
    if ($id <= 0 && preg_match('/servicio\s*(?:#|SRV-?)\s*(\d+)/iu', $msg, $m)) {
        $id = (int) $m[1];
    }
    if ($id <= 0 || !preg_match('/\bde\s+(\S+)\s+(\d+)\s*Mbps.*?\ba\s+(\S+)\s+(\d+)\s*Mbps/iu', $msg, $m)) {
        return null;
    }
    return ['id_servicio' => $id, 'de_nombre' => $m[1], 'de_mbps' => (int) $m[2], 'a_nombre' => $m[3], 'a_mbps' => (int) $m[4],
            'a_prefijo' => AV_MBPS_A_LISTA[(int) $m[4]] ?? null];
}

/**
 * Equipo DHCP del servicio, con la misma regla que la API: una sola fila RELACIONADO de tipo DHCP
 * en integracion_winbox, en una VLAN migrada y dada de alta en RADIUS.
 */
function avEquipoServicio(PDO $jam, PDO $radius, int $idServicio): array
{
    $st = $jam->prepare('SELECT id_cliente FROM servicios WHERE id_servicio = ?');
    $st->execute([$idServicio]);
    $idCliente = $st->fetchColumn();
    if ($idCliente === false) {
        throw new RuntimeException("El servicio $idServicio no existe en UBB Pagos.");
    }
    $st = $jam->prepare("SELECT mac, servidor_dhcp FROM integracion_winbox
                         WHERE id_servicio = ? AND id_cliente = ? AND tipo_fuente = 'DHCP'
                           AND UPPER(estado_vinculacion) LIKE 'RELACIONADO%'
                           AND mac IS NOT NULL AND TRIM(mac) <> '' AND servidor_dhcp IS NOT NULL");
    $st->execute([$idServicio, $idCliente]);
    $equipos = [];
    foreach ($st->fetchAll() as $f) {
        $equipos[strtoupper(trim((string) $f['servidor_dhcp'])) . '|' . strtoupper(trim((string) $f['mac']))] = $f;
    }
    if (count($equipos) !== 1) {
        throw new RuntimeException(count($equipos) === 0
            ? "El servicio $idServicio no tiene un equipo DHCP relacionado en la plataforma."
            : "El servicio $idServicio tiene varios equipos relacionados; no se toca para no afectar al equivocado.");
    }
    $fila = reset($equipos);
    $cfg = rdVlanPorServidor((string) $fila['servidor_dhcp']);
    if ($cfg === null) {
        throw new RuntimeException('El servidor ' . $fila['servidor_dhcp'] . ' todavía no está migrado a RADIUS.');
    }
    $mac = rdNormalizarMac((string) $fila['mac']);
    $cliente = rdLeerCliente($radius, $mac);
    if (strpos($cliente['ip'], $cfg['prefijo']) !== 0) {
        throw new RuntimeException("El equipo $mac tiene una IP que no es de la VLAN {$cfg['vlan']}.");
    }
    return ['mac' => $mac, 'ip' => $cliente['ip'], 'cfg' => $cfg, 'plan' => rdPlanVigente($radius, $mac),
            'suspendido' => $cliente['lista'] === RD_LISTA_DEUDORES];
}

/** Revisa una solicitud de cambio: equipo, plan pedido (lista exacta) y si ya está aplicado. */
function avRevisarCambio(array $a, ?PDO $jam, ?PDO $radius): array
{
    $c = avDatosCambio($a);
    if ($c === null) {
        return ['ok' => false, 'error' => 'No se entendió la solicitud (servicio o planes).'];
    }
    if ($c['a_prefijo'] === null) {
        return ['ok' => false, 'error' => "No hay una lista del router para {$c['a_mbps']} Mbps.", 'cambio' => $c];
    }
    if (!$jam || !$radius) {
        return ['ok' => false, 'error' => 'Sin conexión con UBB Pagos o con RADIUS.', 'cambio' => $c];
    }
    try {
        $eq = avEquipoServicio($jam, $radius, $c['id_servicio']);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'cambio' => $c];
    }
    $destino = $c['a_prefijo'] . '_VLAN' . $eq['cfg']['vlan'];
    return ['ok' => true, 'cambio' => $c, 'equipo' => $eq, 'destino' => $destino, 'aplicado' => $eq['plan'] === $destino];
}

function avConexiones(array $dbConfig): array
{
    $jam = $radius = null;
    try {
        $jam = obtenerPDOJamileth();
    } catch (Throwable $e) {
        error_log('UBB avisos: sin UBB Pagos: ' . $e->getMessage());
    }
    try {
        $cfg = $dbConfig;
        $cfg['database'] = 'radius';
        $radius = obtenerPDO($cfg);
    } catch (Throwable $e) {
        error_log('UBB avisos: sin radius: ' . $e->getMessage());
    }
    return [$jam, $radius];
}

/** Busca un aviso pendiente por id en la plataforma (no se confía en lo que manda el navegador). */
function avAvisoPorId(int $id): ?array
{
    $r = avApi('GET', '/notificaciones.php');
    foreach ($r['ok'] ? (array) ($r['datos']['avisos'] ?? []) : [] as $a) {
        if ((int) ($a['id'] ?? 0) === $id) {
            return $a;
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['accion'] ?? '', ['revisado', 'aplicar_cambio'], true)) {
    $id = (int) ($_POST['id'] ?? 0);
    try {
        if (!hash_equals($csrfAv, (string) ($_POST['csrf_avisos'] ?? '')) || $id <= 0) {
            throw new RuntimeException('La sesión del formulario venció. Recarga la pantalla.');
        }
        $aviso = avAvisoPorId($id);
        if ($aviso === null) {
            throw new RuntimeException("El aviso #$id ya no está pendiente en la plataforma.");
        }
        $esCambio = ($aviso['tipo'] ?? '') === AV_TIPO_CAMBIO;
        $mensajeOk = '';

        if ($_POST['accion'] === 'aplicar_cambio') {
            if (!$esCambio) {
                throw new RuntimeException("El aviso #$id no es una solicitud de cambio de paquete.");
            }
            rdExigirClave((string) ($_POST['clave'] ?? ''));
            [$jam, $radius] = avConexiones($dbConfig);
            $rev = avRevisarCambio($aviso, $jam, $radius);
            if (!$rev['ok']) {
                throw new RuntimeException($rev['error'] . ' No se cambió nada.');
            }
            if (!$rev['aplicado']) {
                $eq = $rev['equipo'];
                $configRouter = $mikrotikConfig;
                $configRouter['host'] = $eq['cfg']['router'];
                $router = crearClienteMikrotik($configRouter);
                $mensajeOk = rdCambiarPlan($radius, $router, $eq['cfg'], $eq['mac'], $rev['destino'], 'PANEL',
                    "(aviso #$id, servicio {$rev['cambio']['id_servicio']})") . ' ';
            }
        } elseif ($esCambio) {
            // No se marca revisado un cambio de paquete que se pudo revisar y todavía no está aplicado.
            [$jam, $radius] = avConexiones($dbConfig);
            $rev = avRevisarCambio($aviso, $jam, $radius);
            if ($rev['ok'] && !$rev['aplicado']) {
                throw new RuntimeException("Falta aplicar el cambio: el equipo sigue en {$rev['equipo']['plan']} y se pidió {$rev['destino']}.");
            }
        }

        $r = avApi('POST', '/notificaciones.php?revisada=' . $id);
        $_SESSION['flash_avisos'] = $r['ok']
            ? ['ok', $mensajeOk . "Aviso #$id marcado como revisado."]
            : [$mensajeOk !== '' ? 'ok' : 'error', $mensajeOk . "No se pudo marcar el aviso #$id: {$r['error']}"];
    } catch (Throwable $e) {
        error_log('UBB avisos: ' . $e->getMessage());
        $_SESSION['flash_avisos'] = ['error', $e instanceof PDOException ? 'La base rechazó el cambio. No se marcó el aviso.' : $e->getMessage()];
    }
    header('Location: ?modulo=avisos');
    exit;
}
$flashAv = $_SESSION['flash_avisos'] ?? null;
unset($_SESSION['flash_avisos']);

$respAv = avApi('GET', '/notificaciones.php');
$avisos = $respAv['ok'] ? (array) ($respAv['datos']['avisos'] ?? []) : [];
$revisionAv = [];
if (array_filter($avisos, fn ($a) => ($a['tipo'] ?? '') === AV_TIPO_CAMBIO)) {
    [$jamAv, $radiusAv] = avConexiones($dbConfig);
    foreach ($avisos as $a) {
        if (($a['tipo'] ?? '') === AV_TIPO_CAMBIO) {
            $revisionAv[(int) ($a['id'] ?? 0)] = avRevisarCambio($a, $jamAv, $radiusAv);
        }
    }
}

require_once __DIR__ . '/layout-portal.php';
portalInicio('Avisos de equipos', 'Equipos que la plataforma asignó o retiró de un servicio. Da de alta en RADIUS solo MACs que estén aquí.', 'avisos');
?>

    <?php if ($flashAv): ?><div class="<?= $flashAv[0] === 'ok' ? 'ok' : 'error' ?>"><?= escapar($flashAv[1]) ?></div><?php endif; ?>
    <?php if (!$respAv['ok']): ?><div class="error"><?= escapar($respAv['error']) ?></div><?php endif; ?>

    <section class="general">
        <div class="g-total"><b><?= count($avisos) ?></b><span>avisos pendientes<br>de revisar</span></div>
        <div><b><?= count(array_filter($avisos, fn ($a) => ($a['tipo'] ?? '') === 'EQUIPO_ASIGNADO')) ?></b><span>asignados</span></div>
        <div><b><?= count(array_filter($avisos, fn ($a) => ($a['tipo'] ?? '') === 'EQUIPO_RETIRADO')) ?></b><span>retirados</span></div>
        <div><b><?= count($revisionAv) ?></b><span>cambios de paquete</span></div>
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
                $rev = $revisionAv[(int) ($a['id'] ?? 0)] ?? null;
                $bloqueado = $rev && $rev['ok'] && !$rev['aplicado'];
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
                    <?php if ($rev && $rev['ok']): $e = $rev['equipo']; ?>
                        <td data-t="Equipo"><span class="mono"><?= escapar($e['mac']) ?></span><br><small class="mono"><?= escapar($e['ip']) ?> · VLAN <?= escapar($e['cfg']['vlan']) ?></small>
                            <br><small><?= escapar($e['plan']) ?> → <b><?= escapar($rev['destino']) ?></b><?= $e['suspendido'] ? ' (suspendido)' : '' ?></small>
                            <br><a class="tenue" href="?modulo=radius-dhcp&vlan=<?= escapar(rawurlencode($e['cfg']['vlan'])) ?>&buscar=<?= escapar(rawurlencode(strtolower($e['mac']))) ?>">Ver equipo</a></td>
                    <?php elseif ($rev): ?>
                        <td data-t="Equipo"><span class="pill susp">No se pudo revisar</span><br><small><?= escapar($rev['error']) ?></small></td>
                    <?php else: ?>
                        <td data-t="Equipo" class="mono"><?= escapar($eq['nombre'] ?: '—') ?><br><small><?= escapar($eq['mac'] ?: '—') ?></small></td>
                    <?php endif; ?>
                    <td><div class="acciones">
                        <?php if ($asignado && $eq['mac'] !== ''): ?>
                            <a class="boton b-alta" href="?modulo=radius-pppoe&<?= escapar(http_build_query(['alta_mac' => $eq['mac'], 'alta_modelo' => $eq['modelo']])) ?>#pp-alta">Dar de alta PPPoE</a>
                        <?php elseif ($bloqueado): ?>
                            <form method="post" action="?modulo=avisos" style="display:inline"
                                  data-confirmar="¿Aplicar el cambio de paquete del servicio #<?= (int) $rev['cambio']['id_servicio'] ?>?"
                                  data-detalle="Equipo <?= escapar($rev['equipo']['mac']) ?> (<?= escapar($rev['equipo']['ip']) ?>): de <?= escapar($rev['equipo']['plan']) ?> a <?= escapar($rev['destino']) ?> (<?= escapar($rev['cambio']['a_nombre'] . ' ' . $rev['cambio']['a_mbps']) ?> Mbps). Se aplica en el router y el aviso queda revisado."
                                  data-verbo="Aplicar cambio" data-pedir-clave="1">
                                <input type="hidden" name="csrf_avisos" value="<?= escapar($csrfAv) ?>">
                                <input type="hidden" name="accion" value="aplicar_cambio">
                                <input type="hidden" name="id" value="<?= (int) ($a['id'] ?? 0) ?>">
                                <input type="hidden" name="clave" value="">
                                <button class="b-plan" type="submit">Aplicar cambio a <?= escapar(strtok($rev['destino'], '_')) ?></button>
                            </form>
                        <?php elseif ($rev && $rev['ok']): ?>
                            <span class="pill ok">Cambio aplicado</span>
                        <?php elseif ($tipo === 'EQUIPO_RETIRADO'): ?>
                            <span class="tenue">Revisar si hay que quitarlo de RADIUS</span>
                        <?php endif; ?>
                        <form method="post" action="?modulo=avisos" style="display:inline">
                            <input type="hidden" name="csrf_avisos" value="<?= escapar($csrfAv) ?>">
                            <input type="hidden" name="accion" value="revisado">
                            <input type="hidden" name="id" value="<?= (int) ($a['id'] ?? 0) ?>">
                            <button class="b-sec" type="submit"<?= $bloqueado ? ' disabled title="Falta aplicar: sigue en ' . escapar($rev['equipo']['plan']) . '"' : '' ?>>Marcar revisado</button>
                        </form>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>

    <p class="nota">"Dar de alta PPPoE" abre el alta con el modelo y la MAC ya llenos; ahí revisas IP y perfil y confirmas. En un cambio de paquete, "Aplicar cambio" lo hace en RADIUS y en el router y marca el aviso; "Marcar revisado" se activa cuando el equipo ya tiene el plan pedido. Los avisos de SRV-1342 son pruebas de la plataforma.</p>
    <style>a.boton.b-alta{display:inline-block;padding:8px 14px;border-radius:9px;font-weight:600;font-size:13px;text-decoration:none;margin-right:6px}</style>
<?php portalFin(); ?>
