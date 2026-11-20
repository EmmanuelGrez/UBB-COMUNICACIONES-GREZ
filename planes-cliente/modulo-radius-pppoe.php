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

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Clientes PPPoE RADIUS | UBB Comunicaciones</title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;padding:24px;background:#f5f3f8;color:#292333;font-family:Arial,sans-serif}
        main{max-width:1100px;margin:0 auto}
        a{color:#60418e}
        h1{margin:12px 0 4px;color:#49366b}
        .sub{margin:0 0 18px;color:#5d5666;font-size:14px}
        .tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
        .tab{padding:8px 16px;border-radius:999px;background:#fff;border:1px solid #d9cfe6;text-decoration:none;font-weight:bold}
        .tab.activo{background:#6945a2;color:#fff;border-color:#6945a2}
        .tab small{display:inline-block;margin-left:4px;padding:1px 7px;border-radius:999px;background:#efe9f7;color:#49366b;font-size:11px}
        .tab.activo small{background:rgba(255,255,255,.22);color:#fff}
        .general{display:flex;flex-wrap:wrap;gap:22px;align-items:center;background:#49366b;color:#fff;border-radius:14px;padding:14px 20px;margin-bottom:16px}
        .general b{display:block;font-size:24px}
        .general span{font-size:12px;opacity:.85}
        .general .g-total b{font-size:34px}
        .general .g-total{padding-right:22px;border-right:1px solid rgba(255,255,255,.25)}
        .general .g-planes{margin-left:auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center}
        .general em{font-style:normal;background:rgba(255,255,255,.15);padding:4px 10px;border-radius:999px;font-size:13px;font-weight:bold}
        .resumen{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px}
        .tarjeta{background:#fff;border:1px solid #e8e1ef;border-radius:12px;padding:14px}
        .tarjeta b{display:block;font-size:26px;color:#49366b}
        .tarjeta span{font-size:13px;color:#5d5666}
        .caja{background:#fff;border:1px solid #e8e1ef;border-radius:14px;padding:18px;margin-bottom:16px}
        .mensaje,.error,.aviso{padding:12px 14px;border-radius:10px;margin-bottom:14px;font-size:14px}
        .mensaje{background:#eaf7ee;color:#185b31}
        .error{background:#fff0f0;color:#8a2424}
        .aviso{background:#fff8e6;color:#6b4e00}
        table{width:100%;border-collapse:collapse;font-size:14px}
        th,td{padding:10px 8px;border-bottom:1px solid #eee7f4;text-align:left;vertical-align:middle}
        th{font-size:12px;text-transform:uppercase;color:#6d6478}
        .pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:bold}
        .ok{background:#eaf7ee;color:#185b31}
        .susp{background:#fff0f0;color:#8a2424}
        .gris{background:#f0edf4;color:#6d6478}
        button{padding:8px 14px;border:0;border-radius:8px;font-weight:bold;cursor:pointer}
        .b-susp{background:#c0392b;color:#fff}
        .b-react{background:#1e8449;color:#fff}
        .b-plan{background:#6945a2;color:#fff}
        .acciones{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:flex-end}
        .acciones form.inline{display:flex;gap:6px;align-items:center}
        select{padding:7px 8px;border:1px solid #cfc5dc;border-radius:8px;font-size:14px;background:#fff}
        .b-alta{background:#6945a2;color:#fff;padding:11px 18px}
        form.inline{margin:0}
        .alta{display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end}
        label{display:block;font-size:13px;font-weight:bold;margin-bottom:6px}
        input{width:100%;padding:10px;border:1px solid #cfc5dc;border-radius:8px;font-size:15px;font-family:monospace}
        .mono{font-family:monospace}
        @media (max-width:760px){
            body{padding:12px}
            .alta{grid-template-columns:1fr}
            table,thead,tbody,tr,td{display:block}
            thead{display:none}
            tr{border:1px solid #e8e1ef;border-radius:12px;margin-bottom:10px;padding:6px;background:#fff}
            td{border:0;padding:6px 8px}
            td[data-t]::before{content:attr(data-t) ": ";font-weight:bold;color:#6d6478}
        }
    </style>

    <style>
        .general{display:flex;flex-wrap:wrap;gap:22px;align-items:center;background:#49366b;color:#fff;border-radius:14px;padding:14px 20px;margin-bottom:16px}
        .general b{display:block;font-size:24px}
        .general span{font-size:12px;opacity:.85}
        .general .g-total b{font-size:34px}
        .general .g-total{padding-right:22px;border-right:1px solid rgba(255,255,255,.25)}
        .general .g-planes{margin-left:auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center}
        .general em{font-style:normal;background:rgba(255,255,255,.15);padding:4px 10px;border-radius:999px;font-size:13px;font-weight:bold}
        .filtros{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
        .filtros input{width:260px;font-family:Arial,sans-serif}
        .filtros .cuenta{margin-left:auto;font-size:13px;color:#5d5666}
        .fase{background:#efe9f7;color:#49366b;padding:12px 14px;border-radius:10px;margin-bottom:14px;font-size:14px}
        button[disabled]{opacity:.45;cursor:not-allowed}
        .uisp{background:#fff3e0;color:#8a4b00}
        .rad{background:#e8f0fe;color:#1a4b9c}
        td small{color:#6d6478}
    </style>
</head>
<body>
<main>
    <a href="?modulo=inicio">← Volver al portal</a>
    <h1>Clientes PPPoE en RADIUS</h1>
    <p class="sub">Usuarios PPPoE de MKR-FSL02 (<?= escapar(PP_ROUTER) ?>): IP fija, plan, conexión y bloqueos.</p>

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
                        <button class="b-plan" disabled title="Disponible cuando el cliente esté migrado a RADIUS">Cambiar plan</button>
                        <button class="b-susp" disabled title="Disponible cuando el cliente esté migrado a RADIUS">Suspender</button>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>
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
