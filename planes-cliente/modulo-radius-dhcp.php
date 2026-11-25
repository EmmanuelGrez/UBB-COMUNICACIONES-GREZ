<?php
/*
 * CLIENTES DHCP EN RADIUS
 * Módulo independiente del portal (se incluye desde index.php con ?modulo=radius-dhcp).
 *
 * Qué hace:
 *   - Lista los clientes DHCP dados de alta en RADIUS por VLAN (IP fija, plan, estado, sesión).
 *   - Da de alta un cliente nuevo (MAC + IP fija) igual que alta-dhcp.sh.
 *   - Suspende y reactiva al momento, igual que ubb-suspension.sh:
 *       1) cambia la lista en radius.radreply (queda guardado aunque el router se reinicie)
 *       2) avisa al MikroTik por su API (bloqueo/desbloqueo inmediato y renovación de la concesión)
 *
 * Usa del index.php: $dbConfig, $mikrotikConfig, obtenerPDO(), obtenerPDOJamileth(),
 * obtenerMapaIntegracionDhcp(), crearClienteMikrotik(), escapar().
 */

use RouterOS\Client;
use RouterOS\Query;

require_once __DIR__ . '/radius-dhcp-acciones.php';

$vlansRadiusDhcp = rdVlans();

$vlanRd = (string) ($_GET['vlan'] ?? '1503');
if (!isset($vlansRadiusDhcp[$vlanRd])) {
    $vlanRd = '1503';
}
$cfgRd = $vlansRadiusDhcp[$vlanRd];

$csrfRd = $_SESSION['csrf_radius_dhcp'] ?? '';
if (!is_string($csrfRd) || $csrfRd === '') {
    $csrfRd = bin2hex(random_bytes(32));
    $_SESSION['csrf_radius_dhcp'] = $csrfRd;
}

$mensajeRd = (string) ($_SESSION['flash_radius_dhcp_ok'] ?? '');
$errorRd = (string) ($_SESSION['flash_radius_dhcp_error'] ?? '');
unset($_SESSION['flash_radius_dhcp_ok'], $_SESSION['flash_radius_dhcp_error']);

/* ---------- Conexión a la base radius ---------- */

$pdoRd = null;
$tablaSuspensionesRd = false;
try {
    $configRd = $dbConfig;
    $configRd['database'] = 'radius';
    $pdoRd = obtenerPDO($configRd);
    $tablaSuspensionesRd = (bool) $pdoRd->query("SHOW TABLES LIKE 'ubb_suspensiones'")->fetchColumn();
} catch (Throwable $e) {
    error_log('UBB RADIUS DHCP: sin conexión a radius: ' . $e->getMessage());
    $errorRd = 'No hay conexión con la base radius.';
}

/* ---------- Firma de estado: el navegador la consulta para refrescarse solo ---------- */

function rdFirmaEstado(PDO $pdo): string
{
    $f = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(CRC32(CONCAT(username, '=', value))), 0) AS s
                      FROM radreply WHERE attribute IN ('Mikrotik-Address-List', 'Framed-IP-Address')")->fetch();
    return $f['n'] . '-' . $f['s'];
}

if (($_GET['estado'] ?? '') === '1') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    try {
        echo json_encode(['firma' => $pdoRd instanceof PDO ? rdFirmaEstado($pdoRd) : '']);
    } catch (Throwable $e) {
        echo json_encode(['firma' => '']);
    }
    exit;
}

/* ---------- Acciones (POST) ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdoRd instanceof PDO) {
    try {
        if (!hash_equals($csrfRd, (string) ($_POST['csrf_radius_dhcp'] ?? ''))) {
            throw new RuntimeException('La sesión del formulario venció. Recarga la pantalla.');
        }
        $accion = (string) ($_POST['accion'] ?? '');
        $mac = rdNormalizarMac((string) ($_POST['mac'] ?? ''));

        if ($accion === 'alta') {
            $ip = trim((string) ($_POST['ip'] ?? ''));
            $octeto = substr($ip, strlen($cfgRd['prefijo']));
            if (strpos($ip, $cfgRd['prefijo']) !== 0 || !ctype_digit($octeto)
                || (int) $octeto < $cfgRd['desde'] || (int) $octeto > $cfgRd['hasta']) {
                throw new RuntimeException("La IP $ip no pertenece a la VLAN $vlanRd ("
                    . $cfgRd['prefijo'] . $cfgRd['desde'] . ' a ' . $cfgRd['prefijo'] . $cfgRd['hasta'] . ').');
            }
            $st = $pdoRd->prepare("SELECT COUNT(*) FROM radcheck WHERE username = ?");
            $st->execute([$mac]);
            if ((int) $st->fetchColumn() > 0) {
                throw new RuntimeException("La MAC $mac ya está dada de alta en RADIUS.");
            }
            $st = $pdoRd->prepare("SELECT username FROM radreply WHERE attribute = 'Framed-IP-Address' AND value = ?");
            $st->execute([$ip]);
            if ($ocupada = $st->fetchColumn()) {
                throw new RuntimeException("La IP $ip ya está asignada a $ocupada.");
            }
            $pdoRd->beginTransaction();
            $pdoRd->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Accept')")
                ->execute([$mac]);
            $ins = $pdoRd->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, ?, ':=', ?)");
            $ins->execute([$mac, 'Framed-IP-Address', $ip]);
            $ins->execute([$mac, 'Mikrotik-Address-List', $cfgRd['lista']]);
            $pdoRd->commit();
            $_SESSION['flash_radius_dhcp_ok'] = "Alta lista: $mac recibirá siempre la IP $ip ("
                . $cfgRd['lista'] . '). Al conectarse o renovar, el router le dará esa IP.';
        } elseif ($accion === 'pasar_radius') {
            // Acción delicada: además de la confirmación pide la clave de acciones.
            $bloqueo = (int) ($_SESSION['clave_rd_bloqueo'] ?? 0);
            if ($bloqueo > time()) {
                throw new RuntimeException('Demasiados intentos con clave incorrecta. Espera ' . (int) ceil(($bloqueo - time()) / 60) . ' min.');
            }
            if (!rdClaveConfigurada()) {
                throw new RuntimeException('Falta configurar la clave de acciones en el servidor.');
            }
            if (!rdVerificarClave((string) ($_POST['clave'] ?? ''))) {
                $fallos = (int) ($_SESSION['clave_rd_fallos'] ?? 0) + 1;
                $_SESSION['clave_rd_fallos'] = $fallos;
                error_log('UBB RADIUS DHCP: clave de acciones incorrecta (' . $fallos . ') desde ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
                if ($fallos >= 5) {
                    $_SESSION['clave_rd_bloqueo'] = time() + 600;
                    $_SESSION['clave_rd_fallos'] = 0;
                }
                throw new RuntimeException('Clave incorrecta. No se cambió nada.');
            }
            $_SESSION['clave_rd_fallos'] = 0;
            $configRouter = $mikrotikConfig;
            $configRouter['host'] = $cfgRd['router'];
            $router = crearClienteMikrotik($configRouter);
            $_SESSION['flash_radius_dhcp_ok'] = rdPasarARadius($pdoRd, $router, $cfgRd, $mac, rdPlanesDisponibles($router, $vlanRd));
        } elseif ($accion === 'suspender' || $accion === 'reactivar') {
            if (!$tablaSuspensionesRd) {
                throw new RuntimeException('Falta la tabla radius.ubb_suspensiones. Créala antes de suspender.');
            }
            $cliente = rdLeerCliente($pdoRd, $mac);
            $ip = $cliente['ip'];
            if (strpos($ip, $cfgRd['prefijo']) !== 0) {
                throw new RuntimeException("La MAC $mac no pertenece a la VLAN $vlanRd.");
            }
            $configRouter = $mikrotikConfig;
            $configRouter['host'] = $cfgRd['router'];
            $router = crearClienteMikrotik($configRouter);

            if ($accion === 'suspender') {
                $_SESSION['flash_radius_dhcp_ok'] = rdSuspender($pdoRd, $router, $mac);
            } else {
                $_SESSION['flash_radius_dhcp_ok'] = rdReactivar($pdoRd, $router, $cfgRd, $mac);
            }
        } elseif ($accion === 'cambiar_plan') {
            rdExigirClave((string) ($_POST['clave'] ?? '')); // cambio de velocidad: pide la clave de acciones
            $configRouter = $mikrotikConfig;
            $configRouter['host'] = $cfgRd['router'];
            $router = crearClienteMikrotik($configRouter);
            $_SESSION['flash_radius_dhcp_ok'] = rdCambiarPlan($pdoRd, $router, $cfgRd + ['vlan' => $vlanRd], $mac, (string) ($_POST['plan'] ?? ''));
        } else {
            throw new RuntimeException('Operación no reconocida.');
        }
    } catch (Throwable $e) {
        if ($pdoRd->inTransaction()) {
            $pdoRd->rollBack();
        }
        error_log('UBB RADIUS DHCP: ' . $e->getMessage());
        $msg = $e->getMessage();
        if ($e instanceof PDOException) {
            $msg = 'La base radius rechazó el cambio (revisa los permisos del usuario SQL del portal).';
        }
        $_SESSION['flash_radius_dhcp_error'] = $msg;
    }
    header('Location: ?modulo=radius-dhcp&vlan=' . rawurlencode($vlanRd));
    exit;
}

/* ---------- Datos para la tabla ---------- */

$clientesRd = [];
$ipSugeridaRd = '';
if ($pdoRd instanceof PDO) {
    try {
        $st = $pdoRd->prepare("
            SELECT ip.username AS mac, ip.value AS ip, li.value AS lista,
                   (SELECT MIN(a.acctstarttime) FROM radacct a
                     WHERE a.username = ip.username AND a.acctstoptime IS NULL) AS sesion_desde
            FROM radreply ip
            LEFT JOIN radreply li
                   ON li.username = ip.username AND li.attribute = 'Mikrotik-Address-List'
            WHERE ip.attribute = 'Framed-IP-Address' AND ip.value LIKE ?
            ORDER BY INET_ATON(ip.value)
        ");
        $st->execute([$cfgRd['prefijo'] . '%']);
        $clientesRd = $st->fetchAll();

        $usadas = array_column($clientesRd, 'ip');
        for ($i = $cfgRd['desde']; $i <= $cfgRd['hasta']; $i++) {
            if (!in_array($cfgRd['prefijo'] . $i, $usadas, true)) {
                $ipSugeridaRd = $cfgRd['prefijo'] . $i;
                break;
            }
        }
    } catch (Throwable $e) {
        error_log('UBB RADIUS DHCP: error de consulta: ' . $e->getMessage());
        $errorRd = $errorRd ?: 'No fue posible consultar los clientes en RADIUS.';
    }

    // Nombre del cliente desde la base de Jamileth (opcional)
    try {
        $mapaJamRd = obtenerMapaIntegracionDhcp(obtenerPDOJamileth());
        foreach ($clientesRd as &$c) {
            $rel = $mapaJamRd[strtolower($cfgRd['servidor']) . '|' . strtoupper($c['mac'])] ?? null;
            $c['nombre'] = $rel['nombre_cliente'] ?? '';
        }
        unset($c);
    } catch (Throwable $e) {
        error_log('UBB RADIUS DHCP: sin nombres de Jamileth: ' . $e->getMessage());
    }
}

$planesRd = [];
$errorPlanesRd = '';
$fijasRd = [];
$errorFijasRd = '';
try {
    $configPlanesRd = $mikrotikConfig;
    $configPlanesRd['host'] = $cfgRd['router'];
    $routerVistaRd = crearClienteMikrotik($configPlanesRd);
    $planesRd = rdPlanesDisponibles($routerVistaRd, $vlanRd);
    try {
        foreach (rdConcesionesFijas($routerVistaRd, $cfgRd['servidor']) as $f) {
            $f += ($pdoRd instanceof PDO) ? rdRevisarFija($pdoRd, $cfgRd, $f, $planesRd) : ['plan' => $cfgRd['lista'], 'problema' => 'Sin base RADIUS.'];
            $fijasRd[] = $f;
        }
    } catch (Throwable $e) {
        error_log('UBB RADIUS DHCP: sin concesiones fijas: ' . $e->getMessage());
        $errorFijasRd = 'No se pudo revisar si hay concesiones fijas fuera de RADIUS.';
    }
} catch (Throwable $e) {
    error_log('UBB RADIUS DHCP: sin planes del router: ' . $e->getMessage());
    $errorPlanesRd = 'No se pudo consultar los planes en el router; el cambio de plan no está disponible.';
}
$claveListaRd = rdClaveConfigurada();

/* Conteo general de servicios DHCP en RADIUS (todas las VLAN) */
$globalRd = ['total' => 0, 'suspendidos' => 0, 'enLinea' => 0, 'porVlan' => [], 'porPlan' => []];
if ($pdoRd instanceof PDO) {
    try {
        $filasGlobal = $pdoRd->query("
            SELECT ip.value AS ip, li.value AS lista,
                   EXISTS (SELECT 1 FROM radacct a
                            WHERE a.username = ip.username AND a.acctstoptime IS NULL) AS en_linea
            FROM radreply ip
            LEFT JOIN radreply li
                   ON li.username = ip.username AND li.attribute = 'Mikrotik-Address-List'
            WHERE ip.attribute = 'Framed-IP-Address'
        ")->fetchAll();
        foreach ($filasGlobal as $f) {
            foreach ($vlansRadiusDhcp as $v => $cfgV) {
                if (strpos((string) $f['ip'], $cfgV['prefijo']) === 0) {
                    $globalRd['total']++;
                    $globalRd['porVlan'][$v] = ($globalRd['porVlan'][$v] ?? 0) + 1;
                    if ($f['lista'] === RD_LISTA_DEUDORES) {
                        $globalRd['suspendidos']++;
                    } elseif (preg_match('/^(\d+M)_VLAN/', (string) $f['lista'], $m)) {
                        $globalRd['porPlan'][$m[1]] = ($globalRd['porPlan'][$m[1]] ?? 0) + 1;
                    }
                    if ((int) $f['en_linea'] === 1) {
                        $globalRd['enLinea']++;
                    }
                    break;
                }
            }
        }
        uksort($globalRd['porPlan'], fn ($a, $b) => (int) $a <=> (int) $b);
    } catch (Throwable $e) {
        error_log('UBB RADIUS DHCP: sin conteo general: ' . $e->getMessage());
    }
}

/* Quién suspendió a cada cliente (UISP o el panel) y desde cuándo */
$origenSuspRd = [];
if ($pdoRd instanceof PDO && $tablaSuspensionesRd) {
    try {
        foreach ($pdoRd->query("SELECT username, origen, fecha FROM ubb_suspensiones") as $s) {
            $origenSuspRd[(string) $s['username']] = ['origen' => strtoupper((string) ($s['origen'] ?? 'PANEL')), 'fecha' => (string) ($s['fecha'] ?? '')];
        }
    } catch (Throwable $e) {
        error_log('UBB RADIUS DHCP: sin origen de suspensiones: ' . $e->getMessage());
    }
}

/* Servicio de UISP de cada cliente (tabla que arma uisp-mapa.php) */
$uispActivoRd = uispSyncActivo();
$servicioUispRd = [];
if ($pdoRd instanceof PDO) {
    try {
        foreach ($pdoRd->query("SELECT mac, ip, servicio_id FROM ubb_uisp_mapa WHERE estado IN (1, 3)") as $m) {
            if ($m['mac'] !== '') {
                $servicioUispRd['mac:' . $m['mac']][] = (int) $m['servicio_id'];
            }
            if ($m['ip'] !== '') {
                $servicioUispRd['ip:' . $m['ip']][] = (int) $m['servicio_id'];
            }
        }
    } catch (Throwable $e) {
        // Todavía no existe el mapa: se muestra sin número de servicio.
    }
}

/* Historial de esta VLAN (últimos cambios hechos desde el panel) */
$historialRd = [];
if ($pdoRd instanceof PDO) {
    try {
        $st = $pdoRd->prepare("SELECT fecha, mac, ip, accion, detalle, origen FROM ubb_bitacora WHERE ip LIKE ? ORDER BY id DESC LIMIT 40");
        $st->execute([$cfgRd['prefijo'] . '%']);
        $historialRd = $st->fetchAll();
    } catch (Throwable $e) {
        error_log('UBB RADIUS DHCP: sin historial: ' . $e->getMessage());
    }
}

$totalRd = count($clientesRd);
$suspendidosRd = count(array_filter($clientesRd, fn ($c) => $c['lista'] === RD_LISTA_DEUDORES));
$enLineaRd = count(array_filter($clientesRd, fn ($c) => !empty($c['sesion_desde'])));

$firmaRd = '';
if ($pdoRd instanceof PDO) {
    try { $firmaRd = rdFirmaEstado($pdoRd); } catch (Throwable $e) { $firmaRd = ''; }
}

require_once __DIR__ . '/layout-portal.php';
portalInicio('Clientes DHCP en RADIUS', 'IP fija, plan y suspensión de cada cliente. Los cambios se guardan en RADIUS y se aplican al momento en el router.', 'radius-dhcp');
?>

    <section class="general">
        <div class="g-total"><b><?= $globalRd['total'] ?></b><span>servicios DHCP en total<br><?= count($vlansRadiusDhcp) ?> VLAN</span></div>
        <div><b><?= $globalRd['total'] - $globalRd['suspendidos'] ?></b><span>activos</span></div>
        <div><b><?= $globalRd['suspendidos'] ?></b><span>suspendidos</span></div>
        <div><b><?= $globalRd['enLinea'] ?></b><span>con sesión abierta</span></div>
        <div class="g-planes"><span>Por plan (activos)</span>
            <?php foreach ($globalRd['porPlan'] as $p => $n): ?>
                <em><?= escapar($p) ?> · <?= $n ?></em>
            <?php endforeach; ?>
            <?php if ($globalRd['porPlan'] === []): ?><em>—</em><?php endif; ?>
        </div>
    </section>

    <nav class="tabs">
        <?php foreach ($vlansRadiusDhcp as $v => $_): ?>
            <a class="tab<?= $v === $vlanRd ? ' activo' : '' ?>" href="?modulo=radius-dhcp&vlan=<?= escapar($v) ?>">VLAN <?= escapar($v) ?> <small><?= (int) ($globalRd['porVlan'][$v] ?? 0) ?></small></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($mensajeRd !== ''): ?><div class="mensaje"><?= escapar($mensajeRd) ?></div><?php endif; ?>
    <?php if ($errorRd !== ''): ?><div class="error"><?= escapar($errorRd) ?></div><?php endif; ?>
    <?php if ($pdoRd instanceof PDO && !$tablaSuspensionesRd): ?>
        <div class="aviso">Falta la tabla <b>radius.ubb_suspensiones</b>: se puede consultar y dar de alta, pero los botones de suspender no funcionarán hasta crearla.</div>
    <?php endif; ?>

    <?php if ($errorPlanesRd !== ''): ?><div class="aviso"><?= escapar($errorPlanesRd) ?></div><?php endif; ?>
    <p class="sub">Planes con velocidad configurada en el router para esta VLAN:
        <b><?= $planesRd !== [] ? escapar(implode(', ', array_map(fn ($x) => strtok($x, '_'), $planesRd))) : 'ninguno detectado' ?></b>.
        Solo esos se pueden asignar.</p>

    <section class="resumen">
        <div class="tarjeta"><b><?= $totalRd ?></b><span>clientes en esta VLAN</span></div>
        <div class="tarjeta"><b><?= $enLineaRd ?></b><span>con sesión abierta</span></div>
        <div class="tarjeta"><b><?= $suspendidosRd ?></b><span>suspendidos</span></div>
        <div class="tarjeta"><b class="mono" style="font-size:18px"><?= escapar($cfgRd['prefijo']) ?>0/24</b><span><?= escapar($cfgRd['servidor']) ?> · <?= escapar($cfgRd['lista']) ?></span></div>
    </section>

    <section class="caja">
        <div class="barra">
            <?= portalBuscador('buscar', 'IP, MAC o nombre') ?>
            <select id="f-estado" aria-label="Estado">
                <option value="">Todos</option>
                <option value="activo">Activos</option>
                <option value="susp">Suspendidos</option>
                <option value="susp-uisp">Suspendidos por UISP</option>
                <option value="susp-panel">Suspendidos desde el panel</option>
                <option value="linea">En línea</option>
                <option value="fuera">Sin sesión</option>
                <option value="sinrel">Sin relación</option>
            </select>
            <select id="f-plan" aria-label="Plan">
                <option value="">Todos los planes</option>
                <?php foreach ($planesRd as $planOp): ?><option value="<?= escapar($planOp) ?>"><?= escapar(strtok($planOp, '_')) ?></option><?php endforeach; ?>
            </select>
            <span class="cuenta" id="cuenta"></span>
        </div>
        <div class="tabla">
        <table>
            <thead><tr><th>IP fija</th><th>MAC</th><th>Cliente</th><th>Estado</th><th>Sesión</th><th></th></tr></thead>
            <tbody id="filas">
            <?php if ($clientesRd === []): ?>
                <tr><td colspan="6">No hay clientes de esta VLAN en RADIUS.</td></tr>
            <?php endif; ?>
            <?php foreach ($clientesRd as $c):
                $suspendido = $c['lista'] === RD_LISTA_DEUDORES;
                $susp = $origenSuspRd[$c['mac']] ?? null;
                $porUisp = $suspendido && ($susp['origen'] ?? '') === 'UISP';
                $sinRel = ($c['nombre'] ?? '') === '';
                $nombreRd = $sinRel ? $c['ip'] : $c['nombre'] . ' (' . $c['ip'] . ')';
                $idsUisp = array_values(array_unique($servicioUispRd['mac:' . strtoupper($c['mac'])] ?? $servicioUispRd['ip:' . $c['ip']] ?? []));
                $enLinea = !empty($c['sesion_desde']);
                $velocidad = (int) strtok((string) $c['lista'], 'M');
                $estados = [$suspendido ? 'susp' : 'activo', $enLinea ? 'linea' : 'fuera'];
                if ($suspendido) $estados[] = $porUisp ? 'susp-uisp' : 'susp-panel';
                if ($sinRel) $estados[] = 'sinrel';
            ?>
                <tr data-buscar="<?= escapar(strtolower($c['ip'] . ' ' . $c['mac'] . ' ' . ($c['nombre'] ?? ''))) ?>"
                    data-estado="<?= escapar(implode(' ', $estados)) ?>" data-plan="<?= escapar($c['lista']) ?>">
                    <td data-t="IP" class="mono"><b><?= escapar($c['ip']) ?></b></td>
                    <td data-t="MAC" class="mono"><?= escapar($c['mac']) ?></td>
                    <td data-t="Cliente"><?= $sinRel ? '<span class="tenue">— sin relación —</span>' : escapar($c['nombre']) ?>
                        <?php if (count($idsUisp) === 1): ?><small class="bloque">UISP servicio #<?= (int) $idsUisp[0] ?></small>
                        <?php elseif (count($idsUisp) > 1): ?><small class="bloque" style="color:var(--red)">UISP: varios servicios (#<?= escapar(implode(', #', $idsUisp)) ?>)</small><?php endif; ?></td>
                    <td data-t="Estado">
                        <?php if ($suspendido): ?>
                            <span class="pill susp">Suspendido</span>
                            <small class="bloque">por <?= $porUisp ? 'UISP' : 'el panel' ?><?= ($susp['fecha'] ?? '') !== '' ? ' · ' . escapar(date('d/m H:i', strtotime($susp['fecha']))) : '' ?></small>
                        <?php else: ?>
                            <span class="pill plan v<?= $velocidad >= 60 ? 60 : ($velocidad >= 30 ? 30 : 20) ?>"><?= escapar(strtok((string) ($c['lista'] ?: 'sin plan'), '_')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-t="Sesión">
                        <?php if ($enLinea): ?>
                            <span class="punto ok"></span>En línea <small class="bloque">desde <?= escapar(date('d/m H:i', strtotime((string) $c['sesion_desde']))) ?></small>
                        <?php else: ?>
                            <span class="punto gris"></span><span class="tenue">Sin sesión</span>
                        <?php endif; ?>
                    </td>
                    <td><div class="acciones">
                        <?php if (!$suspendido && count($planesRd) > 1): ?>
                        <form class="inline" method="post" action="?modulo=radius-dhcp&vlan=<?= escapar($vlanRd) ?>"
                              data-confirmar="¿Cambiar el plan de <?= escapar($nombreRd) ?>?"
                              data-detalle="La nueva velocidad se aplica al momento; el equipo puede reconectarse unos segundos."
                              data-verbo="Cambiar plan" data-pedir-clave="1">
                            <input type="hidden" name="csrf_radius_dhcp" value="<?= escapar($csrfRd) ?>">
                            <input type="hidden" name="mac" value="<?= escapar($c['mac']) ?>">
                            <input type="hidden" name="accion" value="cambiar_plan">
                            <input type="hidden" name="clave" value="">
                            <select name="plan" aria-label="Plan">
                                <?php foreach ($planesRd as $planOp): ?>
                                    <option value="<?= escapar($planOp) ?>" <?= $planOp === $c['lista'] ? 'selected' : '' ?>><?= escapar(strtok($planOp, '_')) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="b-plan" type="submit">Cambiar</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($porUisp && !$uispActivoRd): ?>
                            <span class="pill uisp" title="UISP lo tiene bloqueado. Si se reactiva aquí, el sincronizador lo vuelve a suspender en un minuto.">Reactivar en UISP</span>
                        <?php else: ?>
                        <form class="inline" method="post" action="?modulo=radius-dhcp&vlan=<?= escapar($vlanRd) ?>"
                              data-confirmar="<?= $suspendido ? '¿Reactivar' : '¿Suspender' ?> a <?= escapar($nombreRd) ?>?"
                              data-detalle="<?= $suspendido ? 'Vuelve a tener internet con su plan de siempre.' : 'Se le corta el internet de inmediato y verá el aviso de suspensión. Puedes reactivarlo después.' ?> MAC <?= escapar($c['mac']) ?>.<?= $uispActivoRd ? (count($idsUisp) === 1 ? ' También se ' . ($suspendido ? 'reactiva' : 'suspende') . ' en UISP (servicio #' . $idsUisp[0] . ').' : ' No tiene servicio de UISP enlazado: en UISP no cambia.') : '' ?>"
                              data-verbo="<?= $suspendido ? 'Reactivar' : 'Suspender' ?> <?= escapar($c['ip']) ?>"
                              data-tono="<?= $suspendido ? 'bien' : 'peligro' ?>">
                            <input type="hidden" name="csrf_radius_dhcp" value="<?= escapar($csrfRd) ?>">
                            <input type="hidden" name="mac" value="<?= escapar($c['mac']) ?>">
                            <?php if ($suspendido): ?>
                                <button class="b-react" name="accion" value="reactivar">Reactivar</button>
                            <?php else: ?>
                                <button class="b-susp" name="accion" value="suspender">Suspender</button>
                            <?php endif; ?>
                        </form>
                        <?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            <tr id="sin-resultados" style="display:none"><td colspan="6" class="tenue">Ningún cliente coincide con la búsqueda.</td></tr>
            </tbody>
        </table>
        </div>
    </section>

    <?php $fijasListasRd = count(array_filter($fijasRd, fn ($f) => $f['problema'] === null)); ?>
    <?php if ($fijasRd !== [] || $errorFijasRd !== ''): ?>
    <details class="caja plegable" <?= $fijasRd !== [] ? 'open' : '' ?>>
        <summary>Concesiones fijas fuera de RADIUS en la VLAN <?= escapar($vlanRd) ?> <small>(<?= count($fijasRd) ?>)</small></summary>
        <?php if ($errorFijasRd !== ''): ?>
            <p class="tenue"><?= escapar($errorFijasRd) ?></p>
        <?php else: ?>
            <p class="sub" style="margin:0 0 12px">Estos equipos tienen IP fija hecha a mano en el router: tienen internet, pero no pasan por RADIUS,
                así que el panel y UISP no pueden suspenderlos. «Pasar a RADIUS» los da de alta con su misma IP y plan y quita la concesión fija.
                <?php if ($fijasListasRd > 0): ?>Pide confirmación y la clave de acciones.<?php endif; ?></p>
            <?php if (!$claveListaRd): ?>
                <div class="aviso">Falta configurar la clave de acciones en el servidor; mientras tanto el botón no está disponible.</div>
            <?php endif; ?>
            <div class="tabla">
            <table>
                <thead><tr><th>IP</th><th>MAC</th><th>Equipo / comentario</th><th>Plan</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($fijasRd as $f): ?>
                    <tr>
                        <td data-t="IP" class="mono"><b><?= escapar($f['ip']) ?></b></td>
                        <td data-t="MAC" class="mono"><?= escapar($f['mac']) ?></td>
                        <td data-t="Equipo"><?= escapar($f['equipo'] !== '' ? $f['equipo'] : '—') ?>
                            <?php if ($f['comentario'] !== ''): ?><small class="bloque"><?= escapar($f['comentario']) ?></small><?php endif; ?></td>
                        <td data-t="Plan"><span class="pill plan"><?= escapar($f['plan']) ?></span></td>
                        <td>
                            <?php if ($f['problema'] !== null): ?>
                                <small class="bloque" style="color:var(--red)"><?= escapar($f['problema']) ?></small>
                            <?php elseif ($claveListaRd): ?>
                                <form class="inline" method="post" action="?modulo=radius-dhcp&vlan=<?= escapar($vlanRd) ?>"
                                      data-confirmar="¿Pasar <?= escapar($f['ip']) ?> a RADIUS?"
                                      data-detalle="Se da de alta con la IP <?= escapar($f['ip']) ?> y el plan <?= escapar($f['plan']) ?>, y se quita su concesión fija del router. No pierde internet. MAC <?= escapar($f['mac']) ?>."
                                      data-verbo="Pasar a RADIUS"
                                      data-pedir-clave="1">
                                    <input type="hidden" name="csrf_radius_dhcp" value="<?= escapar($csrfRd) ?>">
                                    <input type="hidden" name="accion" value="pasar_radius">
                                    <input type="hidden" name="mac" value="<?= escapar($f['mac']) ?>">
                                    <input type="hidden" name="clave" value="">
                                    <button class="b-alta" type="submit">Pasar a RADIUS</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </details>
    <?php endif; ?>

    <details class="caja plegable">
        <summary>Dar de alta un cliente en la VLAN <?= escapar($vlanRd) ?></summary>
        <form class="alta" method="post" action="?modulo=radius-dhcp&vlan=<?= escapar($vlanRd) ?>"
              data-confirmar="¿Dar de alta este equipo en la VLAN <?= escapar($vlanRd) ?>?"
              data-detalle="Queda con IP fija y el plan <?= escapar($cfgRd['lista']) ?>. Conectará en su siguiente renovación."
              data-verbo="Dar de alta">
            <input type="hidden" name="csrf_radius_dhcp" value="<?= escapar($csrfRd) ?>">
            <input type="hidden" name="accion" value="alta">
            <div>
                <label for="mac">MAC del equipo</label>
                <input id="mac" name="mac" placeholder="AA:BB:CC:DD:EE:FF" required>
            </div>
            <div>
                <label for="ip">IP fija</label>
                <input id="ip" name="ip" value="<?= escapar($ipSugeridaRd) ?>" required>
            </div>
            <button class="b-alta" type="submit">Dar de alta</button>
        </form>
        <p class="sub" style="margin:10px 0 0">Se sugiere la siguiente IP libre. Plan asignado: <b><?= escapar($cfgRd['lista']) ?></b>.</p>
    </details>

    <details class="caja plegable">
        <summary>Historial de cambios en la VLAN <?= escapar($vlanRd) ?> <small>(últimos <?= count($historialRd) ?>)</small></summary>
        <?php if ($historialRd === []): ?>
            <p class="tenue">Todavía no hay cambios registrados desde el panel en esta VLAN.</p>
        <?php else: ?>
        <div class="tabla">
        <table class="historial">
            <thead><tr><th>Fecha</th><th>IP</th><th>Acción</th><th>Detalle</th><th>Origen</th></tr></thead>
            <tbody>
            <?php foreach ($historialRd as $h): ?>
                <tr>
                    <td data-t="Fecha"><?= escapar(date('d/m H:i', strtotime((string) $h['fecha']))) ?></td>
                    <td data-t="IP" class="mono"><?= escapar($h['ip']) ?></td>
                    <td data-t="Acción"><span class="pill acc-<?= escapar(preg_replace('/[^a-z_]/', '', (string) $h['accion'])) ?>"><?= escapar(str_replace('_', ' ', (string) $h['accion'])) ?></span></td>
                    <td data-t="Detalle"><?= escapar($h['detalle']) ?></td>
                    <td data-t="Origen" class="tenue"><?= escapar($h['origen']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </details>

    <p class="nota">Lo que suspende UISP se reactiva en UISP: el panel lo refleja solo en unos minutos. Este portal todavía no tiene usuarios individuales.</p>
    <div id="hay-cambios" class="aviso" style="display:none;position:fixed;bottom:16px;left:50%;transform:translateX(-50%);box-shadow:0 4px 16px rgba(0,0,0,.15);z-index:9">
        Hubo cambios (por ejemplo, una suspensión desde UISP). <a href="?modulo=radius-dhcp&vlan=<?= rawurlencode($vlanRd) ?>">Actualizar ahora</a>
    </div>
<?php portalFin(); ?>
<script>
(function () {
    var firma = <?= json_encode($firmaRd) ?>;
    if (!firma) return;
    var url = '?modulo=radius-dhcp&vlan=<?= rawurlencode($vlanRd) ?>&estado=1';
    var ocupado = function () {
        var a = document.activeElement;
        return document.querySelector('dialog[open]') || (a && /^(INPUT|SELECT|TEXTAREA)$/.test(a.tagName));
    };
    setInterval(function () {
        if (document.hidden) return;
        fetch(url, {cache: 'no-store', credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.firma || d.firma === firma) return;
                if (ocupado()) {
                    document.getElementById('hay-cambios').style.display = 'block';
                } else {
                    location.href = location.pathname + '?modulo=radius-dhcp&vlan=<?= rawurlencode($vlanRd) ?>';
                }
            })
            .catch(function () {});
    }, 20000);
})();
</script>
<script>
(function () {
    var filas = Array.prototype.slice.call(document.querySelectorAll('#filas tr[data-buscar]'));
    var buscar = document.getElementById('buscar'), estado = document.getElementById('f-estado'),
        plan = document.getElementById('f-plan'), cuenta = document.getElementById('cuenta'),
        vacio = document.getElementById('sin-resultados'), clave = 'filtros-radius-dhcp-<?= rawurlencode($vlanRd) ?>';
    if (!buscar) return;
    try {
        var guardado = JSON.parse(sessionStorage.getItem(clave) || '{}');
        buscar.value = guardado.q || ''; estado.value = guardado.e || ''; plan.value = guardado.p || '';
    } catch (e) {}
    var desdeUrl = new URLSearchParams(location.search).get('buscar');
    if (desdeUrl) { buscar.value = desdeUrl; estado.value = ''; plan.value = ''; } // viene de "Ver equipo" en Avisos
    function filtrar() {
        var q = buscar.value.trim().toLowerCase(), e = estado.value, p = plan.value, n = 0;
        filas.forEach(function (tr) {
            var ver = (!q || tr.dataset.buscar.indexOf(q) !== -1)
                && (!e || (' ' + tr.dataset.estado + ' ').indexOf(' ' + e + ' ') !== -1)
                && (!p || tr.dataset.plan === p);
            tr.style.display = ver ? '' : 'none';
            if (ver) n++;
        });
        cuenta.textContent = n + ' de ' + filas.length + ' clientes';
        if (vacio) vacio.style.display = (filas.length && !n) ? '' : 'none';
        try { sessionStorage.setItem(clave, JSON.stringify({q: buscar.value, e: estado.value, p: plan.value})); } catch (err) {}
    }
    buscar.addEventListener('input', filtrar);
    estado.addEventListener('change', filtrar);
    plan.addEventListener('change', filtrar);
    filtrar();
})();
</script>
</body>
</html>
