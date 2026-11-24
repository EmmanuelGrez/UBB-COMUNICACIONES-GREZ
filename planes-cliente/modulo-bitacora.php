<?php
/*
 * BITÁCORA  (?modulo=bitacora)
 * Solo consulta. Muestra en un solo lugar:
 *   - Cambios: todo lo que se ha hecho a clientes (tabla radius.ubb_bitacora), venga del panel,
 *     de UBB Pagos (API) o de UISP.
 *   - API UBB Pagos: cada llamada que recibió la API (tabla radius.ubb_api_bitacora).
 * No escribe nada.
 *
 * Usa del index.php: $dbConfig, obtenerPDO(), escapar().
 */

const BT_POR_PAGINA = 100;

$vistaBt = ($_GET['vista'] ?? '') === 'api' ? 'api' : 'cambios';
$origenBt = strtoupper(trim((string) ($_GET['origen'] ?? '')));
$accionBt = trim((string) ($_GET['accion'] ?? ''));
$buscarBt = trim((string) ($_GET['q'] ?? ''));
$desdeBt = (string) ($_GET['desde'] ?? '');
$hastaBt = (string) ($_GET['hasta'] ?? '');
$paginaBt = max(1, (int) ($_GET['p'] ?? 1));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desdeBt)) {
    $desdeBt = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hastaBt)) {
    $hastaBt = '';
}

$errorBt = '';
$filasBt = [];
$totalBt = 0;
$origenesBt = [];
$accionesBt = [];
$resumenBt = [];

try {
    $configBt = $dbConfig;
    $configBt['database'] = 'radius';
    $pdoBt = obtenerPDO($configBt);

    $where = [];
    $param = [];
    if ($desdeBt !== '') {
        $where[] = 'fecha >= ?';
        $param[] = $desdeBt . ' 00:00:00';
    }
    if ($hastaBt !== '') {
        $where[] = 'fecha <= ?';
        $param[] = $hastaBt . ' 23:59:59';
    }

    if ($vistaBt === 'cambios') {
        $origenesBt = $pdoBt->query('SELECT DISTINCT origen FROM ubb_bitacora ORDER BY origen')->fetchAll(PDO::FETCH_COLUMN);
        $accionesBt = $pdoBt->query('SELECT DISTINCT accion FROM ubb_bitacora ORDER BY accion')->fetchAll(PDO::FETCH_COLUMN);
        if ($origenBt !== '' && in_array($origenBt, $origenesBt, true)) {
            $where[] = 'origen = ?';
            $param[] = $origenBt;
        }
        if ($accionBt !== '' && in_array($accionBt, $accionesBt, true)) {
            $where[] = 'accion = ?';
            $param[] = $accionBt;
        }
        if ($buscarBt !== '') {
            $where[] = '(mac LIKE ? OR ip LIKE ? OR detalle LIKE ?)';
            $like = '%' . $buscarBt . '%';
            array_push($param, $like, $like, $like);
        }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $st = $pdoBt->prepare('SELECT origen, accion, COUNT(*) AS n FROM ubb_bitacora' . $sqlWhere . ' GROUP BY origen, accion ORDER BY origen, accion');
        $st->execute($param);
        $resumenBt = $st->fetchAll();

        $st = $pdoBt->prepare('SELECT COUNT(*) FROM ubb_bitacora' . $sqlWhere);
        $st->execute($param);
        $totalBt = (int) $st->fetchColumn();

        $st = $pdoBt->prepare('SELECT fecha, mac, ip, accion, detalle, origen FROM ubb_bitacora' . $sqlWhere
            . ' ORDER BY id DESC LIMIT ' . BT_POR_PAGINA . ' OFFSET ' . (($paginaBt - 1) * BT_POR_PAGINA));
        $st->execute($param);
        $filasBt = $st->fetchAll();
    } else {
        $accionesBt = $pdoBt->query('SELECT DISTINCT resultado FROM ubb_api_bitacora ORDER BY resultado')->fetchAll(PDO::FETCH_COLUMN);
        if ($accionBt !== '' && in_array($accionBt, $accionesBt, true)) {
            $where[] = 'resultado = ?';
            $param[] = $accionBt;
        }
        if ($buscarBt !== '') {
            $where[] = '(ruta LIKE ? OR solicitud_id LIKE ? OR CAST(id_servicio AS CHAR) = ? OR ip_origen LIKE ?)';
            $like = '%' . $buscarBt . '%';
            array_push($param, $like, $like, $buscarBt, $like);
        }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $st = $pdoBt->prepare('SELECT resultado, COUNT(*) AS n FROM ubb_api_bitacora' . $sqlWhere . ' GROUP BY resultado ORDER BY n DESC');
        $st->execute($param);
        $resumenBt = $st->fetchAll();

        $st = $pdoBt->prepare('SELECT COUNT(*) FROM ubb_api_bitacora' . $sqlWhere);
        $st->execute($param);
        $totalBt = (int) $st->fetchColumn();

        $st = $pdoBt->prepare('SELECT fecha, ip_origen, token, metodo, ruta, solicitud_id, id_servicio, http, resultado, mensaje FROM ubb_api_bitacora'
            . $sqlWhere . ' ORDER BY id DESC LIMIT ' . BT_POR_PAGINA . ' OFFSET ' . (($paginaBt - 1) * BT_POR_PAGINA));
        $st->execute($param);
        $filasBt = $st->fetchAll();
    }
} catch (Throwable $e) {
    error_log('UBB BITACORA: ' . $e->getMessage());
    $errorBt = $vistaBt === 'api'
        ? 'No se pudo leer la bitácora de la API. Si es un problema de permisos, en el servidor: GRANT SELECT ON radius.ubb_api_bitacora TO \'ubb_web\'@\'localhost\';'
        : 'No se pudo leer la bitácora de cambios.';
}

$paginasBt = max(1, (int) ceil($totalBt / BT_POR_PAGINA));

/** Enlace que conserva los filtros actuales y cambia solo lo indicado. */
function btUrl(array $cambios): string
{
    $q = array_merge(['modulo' => 'bitacora'], array_intersect_key($_GET, array_flip(['vista', 'origen', 'accion', 'q', 'desde', 'hasta', 'p'])), $cambios);
    return '?' . http_build_query(array_filter($q, fn ($v) => $v !== '' && $v !== null));
}

require_once __DIR__ . '/layout-portal.php';
portalInicio('Bitácora', 'Todo lo que se ha cambiado a los clientes (panel, UBB Pagos y UISP) y cada llamada a la API. Solo consulta.', 'bitacora');
?>

    <nav class="tabs-bt" aria-label="Vista">
        <a href="<?= escapar(btUrl(['vista' => '', 'accion' => '', 'origen' => '', 'p' => ''])) ?>" class="<?= $vistaBt === 'cambios' ? 'activo' : '' ?>">Cambios a clientes</a>
        <a href="<?= escapar(btUrl(['vista' => 'api', 'accion' => '', 'origen' => '', 'p' => ''])) ?>" class="<?= $vistaBt === 'api' ? 'activo' : '' ?>">Llamadas de UBB Pagos (API)</a>
    </nav>

    <?php if ($errorBt !== ''): ?><div class="error"><?= escapar($errorBt) ?></div><?php endif; ?>

    <section class="general">
        <div class="g-total"><b><?= $totalBt ?></b><span><?= $vistaBt === 'api' ? 'llamadas' : 'registros' ?><br>con estos filtros</span></div>
        <div class="g-planes"><span>Resumen</span>
            <?php foreach ($resumenBt as $r): ?>
                <em><?= escapar($vistaBt === 'api' ? $r['resultado'] : $r['origen'] . ' · ' . str_replace('_', ' ', $r['accion'])) ?> · <?= (int) $r['n'] ?></em>
            <?php endforeach; ?>
            <?php if ($resumenBt === []): ?><em>sin registros</em><?php endif; ?>
        </div>
    </section>

    <form class="filtros" method="get">
        <input type="hidden" name="modulo" value="bitacora">
        <?php if ($vistaBt === 'api'): ?><input type="hidden" name="vista" value="api"><?php endif; ?>
        <label class="buscador" for="q"><span>Buscar</span>
            <input id="q" name="q" type="search" value="<?= escapar($buscarBt) ?>"
                   placeholder="<?= $vistaBt === 'api' ? 'Servicio, solicitud o IP' : 'MAC, IP o detalle' ?>" autocomplete="off"></label>
        <?php if ($vistaBt === 'cambios'): ?>
            <select name="origen" aria-label="Origen">
                <option value="">Todos los orígenes</option>
                <?php foreach ($origenesBt as $o): ?><option value="<?= escapar($o) ?>" <?= $o === $origenBt ? 'selected' : '' ?>><?= escapar($o) ?></option><?php endforeach; ?>
            </select>
        <?php endif; ?>
        <select name="accion" aria-label="<?= $vistaBt === 'api' ? 'Resultado' : 'Acción' ?>">
            <option value=""><?= $vistaBt === 'api' ? 'Todos los resultados' : 'Todas las acciones' ?></option>
            <?php foreach ($accionesBt as $a): ?><option value="<?= escapar($a) ?>" <?= $a === $accionBt ? 'selected' : '' ?>><?= escapar(str_replace('_', ' ', (string) $a)) ?></option><?php endforeach; ?>
        </select>
        <label>Desde <input type="date" name="desde" value="<?= escapar($desdeBt) ?>"></label>
        <label>Hasta <input type="date" name="hasta" value="<?= escapar($hastaBt) ?>"></label>
        <button>Filtrar</button>
        <a href="<?= escapar('?modulo=bitacora' . ($vistaBt === 'api' ? '&vista=api' : '')) ?>">Quitar filtros</a>
    </form>

    <section class="caja">
        <div class="tabla">
        <?php if ($vistaBt === 'cambios'): ?>
        <table class="historial">
            <thead><tr><th>Fecha</th><th>Origen</th><th>Acción</th><th>IP</th><th>MAC / usuario</th><th>Detalle</th></tr></thead>
            <tbody>
            <?php if ($filasBt === []): ?><tr><td colspan="6">No hay registros con estos filtros.</td></tr><?php endif; ?>
            <?php foreach ($filasBt as $h): ?>
                <tr>
                    <td data-t="Fecha" class="mono"><?= escapar(date('d/m/Y H:i', strtotime((string) $h['fecha']))) ?></td>
                    <td data-t="Origen"><b><?= escapar($h['origen']) ?></b></td>
                    <td data-t="Acción"><span class="pill acc-<?= escapar(preg_replace('/[^a-z_]/', '', (string) $h['accion'])) ?>"><?= escapar(str_replace('_', ' ', (string) $h['accion'])) ?></span></td>
                    <td data-t="IP" class="mono"><?= escapar($h['ip']) ?></td>
                    <td data-t="MAC / usuario" class="mono"><?= escapar($h['mac']) ?></td>
                    <td data-t="Detalle"><?= escapar($h['detalle']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <table class="historial">
            <thead><tr><th>Fecha</th><th>Petición</th><th>Servicio</th><th>Resultado</th><th>Mensaje</th><th>Desde</th></tr></thead>
            <tbody>
            <?php if ($filasBt === []): ?><tr><td colspan="6">No hay llamadas con estos filtros.</td></tr><?php endif; ?>
            <?php foreach ($filasBt as $h): ?>
                <tr>
                    <td data-t="Fecha" class="mono"><?= escapar(date('d/m/Y H:i:s', strtotime((string) $h['fecha']))) ?></td>
                    <td data-t="Petición" class="mono"><?= escapar($h['metodo'] . ' ' . $h['ruta']) ?>
                        <?php if (!empty($h['solicitud_id'])): ?><br><small><?= escapar($h['solicitud_id']) ?></small><?php endif; ?></td>
                    <td data-t="Servicio"><?= escapar((string) ($h['id_servicio'] ?? '—')) ?></td>
                    <td data-t="Resultado"><span class="pill <?= (int) $h['http'] < 400 ? 'ok' : 'susp' ?>"><?= escapar($h['resultado']) ?></span> <small><?= (int) $h['http'] ?></small></td>
                    <td data-t="Mensaje"><?= escapar($h['mensaje']) ?></td>
                    <td data-t="Desde" class="tenue"><?= escapar($h['ip_origen']) ?><br><small>token <?= escapar($h['token'] ?: '—') ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        </div>

        <?php if ($paginasBt > 1): ?>
        <nav class="paginas-bt" aria-label="Páginas">
            <?php if ($paginaBt > 1): ?><a href="<?= escapar(btUrl(['p' => $paginaBt - 1])) ?>">← Más recientes</a><?php endif; ?>
            <span>Página <?= $paginaBt ?> de <?= $paginasBt ?></span>
            <?php if ($paginaBt < $paginasBt): ?><a href="<?= escapar(btUrl(['p' => $paginaBt + 1])) ?>">Más antiguos →</a><?php endif; ?>
        </nav>
        <?php endif; ?>
    </section>

    <p class="nota">Origen PANEL: cambios hechos desde este panel. PAGOS: desde UBB Pagos por la API. UISP: suspensiones o reactivaciones que vinieron de UISP.
        Las acciones "suspender uisp" y "reactivar uisp" son los avisos que el panel o la API mandaron a UISP.</p>

    <style>
        .tabs-bt{display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap}
        .tabs-bt a{padding:8px 14px;border-radius:999px;background:var(--surface);border:1px solid #e5e1ec;color:var(--ink);text-decoration:none;font-weight:600;font-size:14px}
        .tabs-bt a.activo{background:var(--plum);color:#fff;border-color:var(--plum)}
        .filtros label:not(.buscador){display:flex;align-items:center;gap:6px;font-size:13px}
        .paginas-bt{display:flex;gap:16px;justify-content:center;align-items:center;padding:12px;font-size:14px}
    </style>
<?php portalFin(); ?>
