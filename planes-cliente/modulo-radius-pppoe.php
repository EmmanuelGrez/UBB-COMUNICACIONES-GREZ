<?php
/*
 * CLIENTES PPPoE EN RADIUS  (?modulo=radius-pppoe)
 * Fase 1 - SOLO CONSULTA: lee el router (usuarios, sesiones, listas) y la base radius.
 * No cambia nada en MikroTik ni en RADIUS. Los botones se activarán por cliente
 * cuando ese cliente esté migrado a RADIUS.
 *
 * Usa del index.php: $dbConfig, $mikrotikConfig, obtenerPDO(), crearClienteMikrotik(), escapar().
 */

use RouterOS\Client;
use RouterOS\Query;

const PP_ROUTER = '10.0.1.6';
const PP_LISTA_DEUDORES = 'CLIENTES-DEUDORES';
const PP_LISTA_UISP = 'BLOCKED_USERS';

function ppLeer(Client $router, Query $query): array
{
    $respuesta = $router->query($query)->read();
    if (isset($respuesta['after']['message'])) {
        throw new RuntimeException('MikroTik: ' . $respuesta['after']['message']);
    }
    return array_values(array_filter($respuesta, fn ($f) => is_array($f) && isset($f['.id'])));
}

function ppPlan(string $perfil): string
{
    if (preg_match('/(\d+)\s*m$/i', $perfil, $m)) {
        return $m[1] . 'M';
    }
    if (stripos($perfil, 'basico') !== false) {
        return 'Básico';
    }
    return $perfil !== '' ? $perfil : '—';
}

/* ---------- Datos ---------- */

$errorPp = '';
$clientesPp = [];
$enRadiusPp = [];
$perfilesPp = [];

try {
    $configRouterPp = $mikrotikConfig;
    $configRouterPp['host'] = PP_ROUTER;
    $routerPp = crearClienteMikrotik($configRouterPp);

    $activos = [];
    foreach (ppLeer($routerPp, new Query('/ppp/active/print', ['=.proplist=.id,name,address,uptime,caller-id'])) as $a) {
        $activos[strtolower((string) ($a['name'] ?? ''))] = $a;
    }
    $bloqueadosUisp = [];
    foreach (ppLeer($routerPp, (new Query('/ip/firewall/address-list/print', ['=.proplist=.id,address']))->where('list', PP_LISTA_UISP)) as $f) {
        $bloqueadosUisp[(string) $f['address']] = true;
    }
    $deudores = [];
    foreach (ppLeer($routerPp, (new Query('/ip/firewall/address-list/print', ['=.proplist=.id,address']))->where('list', PP_LISTA_DEUDORES)) as $f) {
        $deudores[(string) $f['address']] = true;
    }
    foreach (ppLeer($routerPp, new Query('/ppp/profile/print', ['=.proplist=.id,name,rate-limit'])) as $p) {
        $perfilesPp[(string) $p['name']] = (string) ($p['rate-limit'] ?? '');
    }
    foreach (ppLeer($routerPp, new Query('/ppp/secret/print', ['=.proplist=.id,name,profile,remote-address,disabled,comment,last-logged-out,last-caller-id'])) as $s) {
        $usuario = (string) ($s['name'] ?? '');
        if ($usuario === '') {
            continue;
        }
        $ip = (string) ($s['remote-address'] ?? '');
        $activo = $activos[strtolower($usuario)] ?? null;
        $clientesPp[] = [
            'usuario' => $usuario,
            'ip' => $ip,
            'perfil' => (string) ($s['profile'] ?? ''),
            'plan' => ppPlan((string) ($s['profile'] ?? '')),
            'desactivado' => in_array(strtolower((string) ($s['disabled'] ?? 'false')), ['true', 'yes'], true),
            'comentario' => (string) ($s['comment'] ?? ''),
            'en_linea' => $activo !== null,
            'uptime' => (string) ($activo['uptime'] ?? ''),
            'mac' => (string) ($activo['caller-id'] ?? ($s['last-caller-id'] ?? '')),
            'ultima' => (string) ($s['last-logged-out'] ?? ''),
            'uisp' => $ip !== '' && isset($bloqueadosUisp[$ip]),
            'suspendido' => $ip !== '' && isset($deudores[$ip]),
        ];
    }
    usort($clientesPp, fn ($a, $b) => strnatcasecmp($a['ip'], $b['ip']) ?: strnatcasecmp($a['usuario'], $b['usuario']));
} catch (Throwable $e) {
    error_log('UBB RADIUS PPPoE: ' . $e->getMessage());
    $errorPp = 'No fue posible leer los clientes PPPoE del router (' . PP_ROUTER . ').';
}

try {
    $configRdPp = $dbConfig;
    $configRdPp['database'] = 'radius';
    $pdoPp = obtenerPDO($configRdPp);
    foreach ($pdoPp->query("SELECT username FROM radcheck WHERE attribute = 'Cleartext-Password'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        $enRadiusPp[strtolower((string) $u)] = true;
    }
} catch (Throwable $e) {
    error_log('UBB RADIUS PPPoE: sin base radius: ' . $e->getMessage());
}

foreach ($clientesPp as &$c) {
    $c['radius'] = isset($enRadiusPp[strtolower($c['usuario'])]);
}
unset($c);

$totalPp = count($clientesPp);
$enLineaPp = count(array_filter($clientesPp, fn ($c) => $c['en_linea']));
$uispPp = count(array_filter($clientesPp, fn ($c) => $c['uisp']));
$migradosPp = count(array_filter($clientesPp, fn ($c) => $c['radius']));
$porPlanPp = [];
foreach ($clientesPp as $c) {
    $porPlanPp[$c['plan']] = ($porPlanPp[$c['plan']] ?? 0) + 1;
}
uksort($porPlanPp, fn ($a, $b) => ((int) $a ?: 999) <=> ((int) $b ?: 999));

require_once __DIR__ . '/layout-portal.php';
portalInicio('Clientes PPPoE en RADIUS', 'Usuarios PPPoE de MKR-FSL02 (' . PP_ROUTER . '): IP fija, plan, conexión y bloqueos.', 'radius-pppoe');
?>

    <div class="fase"><b>Fase 1 · solo consulta.</b> Esta pantalla no cambia nada. Los botones se activan para cada cliente cuando esté migrado a RADIUS
        (<?= $migradosPp ?> de <?= $totalPp ?> hoy). Para suspender o reactivar mientras tanto, usa UISP.</div>

    <?php if ($errorPp !== ''): ?><div class="error"><?= escapar($errorPp) ?></div><?php endif; ?>

    <section class="general">
        <div class="g-total"><b><?= $totalPp ?></b><span>clientes PPPoE<br>en el router</span></div>
        <div><b><?= $enLineaPp ?></b><span>en línea</span></div>
        <div><b><?= $uispPp ?></b><span>bloqueados en UISP</span></div>
        <div><b><?= $migradosPp ?></b><span>migrados a RADIUS</span></div>
        <div class="g-planes"><span>Por plan</span>
            <?php foreach ($porPlanPp as $p => $n): ?><em><?= escapar($p) ?> · <?= $n ?></em><?php endforeach; ?>
        </div>
    </section>

    <div class="filtros">
        <input id="buscar" type="search" placeholder="Buscar usuario, IP o MAC" autocomplete="off">
        <select id="f-estado">
            <option value="">Todos</option>
            <option value="linea">En línea</option>
            <option value="fuera">Desconectados</option>
            <option value="uisp">Bloqueados en UISP</option>
            <option value="radius">Migrados a RADIUS</option>
        </select>
        <select id="f-plan">
            <option value="">Todos los planes</option>
            <?php foreach ($porPlanPp as $p => $n): ?><option value="<?= escapar($p) ?>"><?= escapar($p) ?></option><?php endforeach; ?>
        </select>
        <span class="cuenta" id="cuenta"></span>
    </div>

    <section class="caja">
        <div class="tabla">
        <table>
            <thead><tr><th>IP fija</th><th>Usuario</th><th>Plan</th><th>Conexión</th><th>Estado</th><th></th></tr></thead>
            <tbody id="filas">
            <?php if ($clientesPp === []): ?>
                <tr><td colspan="6">No hay datos del router.</td></tr>
            <?php endif; ?>
            <?php foreach ($clientesPp as $c):
                $estados = [];
                if ($c['en_linea']) $estados[] = 'linea'; else $estados[] = 'fuera';
                if ($c['uisp']) $estados[] = 'uisp';
                if ($c['radius']) $estados[] = 'radius';
            ?>
                <tr data-buscar="<?= escapar(strtolower($c['usuario'] . ' ' . $c['ip'] . ' ' . $c['mac'] . ' ' . $c['comentario'])) ?>"
                    data-estado="<?= escapar(implode(' ', $estados)) ?>" data-plan="<?= escapar($c['plan']) ?>">
                    <td data-t="IP" class="mono"><?= escapar($c['ip']) ?></td>
                    <td data-t="Usuario"><b><?= escapar($c['usuario']) ?></b>
                        <?php if ($c['comentario'] !== ''): ?><br><small><?= escapar($c['comentario']) ?></small><?php endif; ?></td>
                    <td data-t="Plan"><?= escapar($c['plan']) ?><br><small class="mono"><?= escapar($c['perfil']) ?></small></td>
                    <td data-t="Conexión">
                        <?php if ($c['en_linea']): ?>
                            <span class="pill ok">En línea</span> <small><?= escapar($c['uptime']) ?></small>
                        <?php else: ?>
                            <span class="pill gris">Desconectado</span>
                            <?php if ($c['ultima'] !== ''): ?><br><small>desde <?= escapar($c['ultima']) ?></small><?php endif; ?>
                        <?php endif; ?>
                        <?php if ($c['mac'] !== ''): ?><br><small class="mono"><?= escapar($c['mac']) ?></small><?php endif; ?>
                    </td>
                    <td data-t="Estado">
                        <?php if ($c['desactivado']): ?><span class="pill gris">Desactivado</span><?php endif; ?>
                        <?php if ($c['uisp']): ?><span class="pill uisp">Bloqueado en UISP</span><?php endif; ?>
                        <?php if ($c['suspendido']): ?><span class="pill susp">Suspendido RADIUS</span><?php endif; ?>
                        <?php if (!$c['uisp'] && !$c['suspendido'] && !$c['desactivado']): ?><span class="pill ok">Activo</span><?php endif; ?>
                        <br><span class="pill <?= $c['radius'] ? 'rad' : 'gris' ?>"><?= $c['radius'] ? 'En RADIUS' : 'En router' ?></span>
                    </td>
                    <td><div class="acciones">
                        <?php if ($c['radius']): ?>
                            <button class="b-plan" disabled title="Se activa en la fase 2">Cambiar plan</button>
                            <button class="b-susp" disabled title="Se activa en la fase 2">Suspender</button>
                        <?php else: ?>
                            <span class="tenue" title="Los botones se activan cuando el cliente esté migrado a RADIUS">Solo consulta</span>
                        <?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
<?php portalFin(); ?>
<script>
(function () {
    var filas = Array.prototype.slice.call(document.querySelectorAll('#filas tr[data-buscar]'));
    var buscar = document.getElementById('buscar'), estado = document.getElementById('f-estado'),
        plan = document.getElementById('f-plan'), cuenta = document.getElementById('cuenta');
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
    }
    buscar.addEventListener('input', filtrar);
    estado.addEventListener('change', filtrar);
    plan.addEventListener('change', filtrar);
    filtrar();
})();
</script>
</body>
</html>
