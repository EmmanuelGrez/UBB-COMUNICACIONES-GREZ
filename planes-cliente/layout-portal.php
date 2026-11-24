<?php
/*
 * MARCO COMÚN DEL PORTAL para los módulos que se muestran aparte (RADIUS DHCP, RADIUS PPPoE).
 * Da la misma barra lateral, colores y tipografía que index.php, y se ajusta a celular.
 *
 *   portalInicio('Título', 'Subtítulo', 'radius-dhcp');   // abre la página
 *   ... contenido del módulo ...
 *   portalFin();                                          // cierra el marco (antes de los <script>)
 */

function portalEnlace(string $modulo, string $actual, string $icono, string $texto): string
{
    $activo = $modulo === $actual ? ' active' : '';
    return '<a class="nav-link' . $activo . '" href="?modulo=' . htmlspecialchars($modulo, ENT_QUOTES) . '">'
        . '<span class="nav-icon">' . $icono . '</span>' . htmlspecialchars($texto, ENT_QUOTES) . '</a>';
}

/** CSS del marco (barra lateral, menú, título). Lo usan index.php y los módulos RADIUS para verse igual. */
function portalCssMarco(): string
{
    return <<<'CSS'
/* Marco: barra lateral + contenido */
.app-shell{min-height:100vh;display:grid;grid-template-columns:258px minmax(0,1fr)}
.sidebar{position:sticky;top:0;height:100vh;display:flex;flex-direction:column;padding:26px 17px 19px;background:var(--plum);color:#f8f6ff;overflow-y:auto;z-index:20}
.sidebar-brand{display:flex;align-items:center;gap:12px;padding:3px 9px 25px;border-bottom:1px solid rgba(255,255,255,.12)}
.brand-monogram{width:45px;height:45px;display:grid;place-items:center;border:1px solid rgba(255,255,255,.22);border-radius:14px;background:rgba(255,255,255,.10);font-weight:600;font-size:14px;letter-spacing:.7px}
.sidebar-brand strong{display:block;font-size:13px;letter-spacing:.6px}
.sidebar-brand small{display:block;margin-top:3px;color:#c9bfdc;font-size:12px;letter-spacing:1.3px;text-transform:uppercase}
.sidebar-nav{display:grid;gap:15px;margin-top:22px}
.nav-group{display:grid;gap:5px;min-width:0}
.nav-group-label{padding:0 12px 3px;color:#a99cbd;font-size:12px;letter-spacing:.6px;font-weight:600;letter-spacing:1.1px;text-transform:uppercase}
.nav-link{display:flex;align-items:center;gap:12px;padding:11px 13px;border:1px solid transparent;border-radius:12px;color:#ded7eb;font-size:13.5px;font-weight:600;text-decoration:none;transition:background .2s,transform .2s}
.nav-link:hover{background:rgba(255,255,255,.09);transform:translateX(2px)}
.nav-link.active{background:rgba(154,126,235,.26);border-color:rgba(205,190,255,.22);color:#fff;box-shadow:inset 3px 0 #c8b8ff}
.nav-icon{width:20px;text-align:center;color:#c7b9ed;font-size:17px}
.main-area{min-width:0}
.contenedor{width:min(1500px,100%);margin:0 auto;padding:28px 32px 28px}
.page-eyebrow{margin:0 0 6px 2px;color:#827b94;font-size:12px;font-weight:600;letter-spacing:1.6px;text-transform:uppercase}
.titulo-pagina{margin:0 0 4px;color:var(--plum);font-size:clamp(20px,2.2vw,28px);letter-spacing:-.6px;line-height:1.2}
.sub{margin:0 0 18px;color:var(--muted);font-size:13px}
/* Foco visible con teclado */
a:focus-visible,button:focus-visible,summary:focus-visible{outline:2px solid var(--plum-3);outline-offset:2px}
.sidebar a:focus-visible{outline-color:#c8b8ff}
/* Estado de conexiones (abajo del menú) */
.sidebar-spacer{flex:1;min-height:25px}
.sidebar-status{padding:14px 15px;border:1px solid rgba(255,255,255,.13);border-radius:14px;background:rgba(255,255,255,.06)}
.sidebar-status-title{margin-bottom:8px;color:#c6bbd8;font-size:12px;font-weight:600;letter-spacing:.6px;text-transform:uppercase}
.status-line{display:flex;align-items:center;gap:10px;padding:6px 0;color:#f6f3fb;font-size:13px}
.status-line small{display:block;color:#bfb5cf;font-size:12px}
.status-dot{width:8px;height:8px;flex:0 0 8px;border-radius:50%;background:#60d69b}
.status-dot.error{background:#ff8c96}
.sidebar-label{display:none}
.sidebar:before{display:none}
@media(max-width:1180px){.app-shell{grid-template-columns:220px minmax(0,1fr)}.contenedor{padding:22px}}
@media(max-width:900px){
    .app-shell{display:block}
    .sidebar{position:sticky;top:0;height:auto;padding:10px 14px;flex-direction:row;align-items:center;gap:12px;overflow:visible}
    .sidebar-brand{padding:0;border:0}.sidebar-brand small,.sidebar-brand strong{display:none}
    .brand-monogram{width:38px;height:38px;font-size:12px;flex:0 0 38px}
    .sidebar-nav{display:flex;gap:6px;margin:0;overflow-x:auto;scrollbar-width:none;min-width:0}
    .sidebar-nav::-webkit-scrollbar{display:none}
    .nav-group{display:flex;gap:6px;flex:0 0 auto}.nav-group-label{display:none}
    .nav-link{padding:10px 12px;font-size:13px;white-space:nowrap}.nav-link:hover{transform:none}
    .contenedor{padding:16px}
    .sidebar-nav{-webkit-mask-image:linear-gradient(90deg,#000 82%,transparent);mask-image:linear-gradient(90deg,#000 82%,transparent);padding-right:28px}
    .sidebar-spacer,.sidebar-status{display:none}
    .nav-group{flex-direction:row}
    .nav-icon{font-size:15px}
}
CSS;
}

/** Barra lateral con el menú. $estado opcional: ['MikroTik' => true/false, 'RADIUS' => true/false]. */
function portalMenu(string $moduloActual, array $estado = []): string
{
    $h = '<aside class="sidebar" aria-label="Navegación principal">'
        . '<div class="sidebar-brand"><div class="brand-monogram">UBB</div>'
        . '<div><strong>UBB COMUNICACIONES</strong><small>Gestión de red</small></div></div>'
        . '<nav class="sidebar-nav" aria-label="Módulos del sistema">'
        . '<div class="nav-group"><span class="nav-group-label">General</span>'
        . portalEnlace('inicio', $moduloActual, '⌂', 'Inicio') . '</div>'
        . '<div class="nav-group"><span class="nav-group-label">Planes y perfiles</span>'
        . portalEnlace('catalogo', $moduloActual, '◇', 'Catálogo comercial')
        . portalEnlace('perfiles', $moduloActual, '≋', 'Perfiles MikroTik') . '</div>'
        . '<div class="nav-group"><span class="nav-group-label">Monitoreo y control</span>'
        . portalEnlace('radius-dhcp', $moduloActual, '⇄', 'Clientes DHCP RADIUS')
        . portalEnlace('radius-pppoe', $moduloActual, '⇆', 'Clientes PPPoE RADIUS')
        . portalEnlace('bitacora', $moduloActual, '☰', 'Bitácora')
        . portalEnlace('historial', $moduloActual === 'solicitudes' ? 'historial' : $moduloActual, '◷', 'Gestión de cambios') . '</div>'
        . '</nav>';
    if ($estado !== []) {
        $h .= '<div class="sidebar-spacer"></div><div class="sidebar-status"><div class="sidebar-status-title">Estado de conexiones</div>';
        foreach ($estado as $nombre => $ok) {
            $h .= '<div class="status-line"><span class="status-dot' . ($ok ? '' : ' error') . '"></span><div>'
                . htmlspecialchars($nombre, ENT_QUOTES) . '<small>' . ($ok ? 'Conectado' : 'Sin conexión') . '</small></div></div>';
        }
        $h .= '</div>';
    }
    return $h . '</aside>';
}

function portalInicio(string $titulo, string $subtitulo, string $moduloActual): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($titulo, ENT_QUOTES) ?> | UBB Comunicaciones</title>
    <style>
:root{
    color-scheme:light;
    --bg:#f5f4fa;--surface:#fff;--surface-soft:#faf9fd;--ink:#292435;--muted:#777287;
    --line:#e9e6f0;--line-strong:#dcd6e8;--plum:#322744;--plum-2:#4b3b68;--plum-3:#7054c7;
    --lavender:#b9a9ef;--lavender-soft:#f0ecfb;--lavender-pale:#f8f6fd;
    --green:#25815d;--green-soft:#e9f7ef;--red:#bb555d;--red-soft:#fff0f1;
    --blue:#2f5fae;--blue-soft:#eaf1fc;--amber:#9a5b00;--amber-soft:#fff4e2;
    --shadow:0 12px 34px rgba(46,35,75,.065);--radius:18px;--radius-sm:12px;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,"Segoe UI",Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55;-webkit-font-smoothing:antialiased}
button,input,select{font:inherit}
button{cursor:pointer}
a{color:var(--plum-3)}
<?= portalCssMarco() ?>
/* Bloques */
.caja{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:18px;margin-bottom:16px;box-shadow:var(--shadow)}
.general{display:flex;flex-wrap:wrap;gap:24px;align-items:center;background:var(--plum);color:#fff;border-radius:var(--radius);padding:18px 24px;margin-bottom:16px}
.general b{display:block;font-size:24px;line-height:1.2}
.general span{font-size:12.5px;color:#e3ddef}
.general .g-total b{font-size:34px}
.general .g-total{padding-right:24px;border-right:1px solid rgba(255,255,255,.22)}
.general .g-planes{margin-left:auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.general em{font-style:normal;background:rgba(255,255,255,.16);padding:4px 11px;border-radius:999px;font-size:12px;font-weight:600}
.tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.tab{padding:7px 14px;border-radius:999px;background:var(--surface);border:1px solid var(--line-strong);color:var(--plum-2);text-decoration:none;font-weight:600;font-size:12.5px;white-space:nowrap}
.tab:hover{border-color:var(--lavender)}
.tab.activo{background:var(--plum-3);color:#fff;border-color:var(--plum-3)}
.tab small{display:inline-block;margin-left:5px;padding:1px 7px;border-radius:999px;background:var(--lavender-soft);color:var(--plum-2);font-size:12px}
.tab.activo small{background:rgba(255,255,255,.22);color:#fff}
.resumen{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
.tarjeta{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-sm);padding:14px 16px;box-shadow:var(--shadow)}
.tarjeta b{display:block;font-size:24px;color:var(--plum)}
.tarjeta span{font-size:12px;color:var(--muted)}
.mensaje,.error,.aviso,.fase{padding:12px 16px;border-radius:12px;margin-bottom:14px;font-size:14px;border:1px solid var(--line);border-left:4px solid var(--plum-3);background:var(--surface)}
.mensaje{border-left-color:var(--green);background:var(--green-soft);color:#185b31}
.error{border-left-color:var(--red);background:var(--red-soft);color:#8a2424}
.aviso{border-left-color:#d49a2a;background:var(--amber-soft);color:#6b4e00}
.fase{background:var(--lavender-pale);color:var(--plum-2)}
/* Barra de búsqueda y filtros */
.barra,.filtros{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
.barra input,.filtros input{width:280px;max-width:100%}
.barra .cuenta,.filtros .cuenta{margin-left:auto;font-size:12.5px;color:var(--muted)}
input,select{padding:9px 11px;border:1px solid var(--line-strong);border-radius:10px;background:#fff;color:var(--ink);font-size:13px}
input:focus,select:focus{outline:2px solid var(--lavender);outline-offset:1px}
/* Tablas */
.tabla{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{padding:11px 10px;border-bottom:1px solid var(--line);text-align:left;vertical-align:middle}
th{font-size:12px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);background:var(--surface);position:sticky;top:0;z-index:1}
tbody tr:hover{background:var(--surface-soft)}
.mono{font-family:ui-monospace,"Cascadia Mono",Consolas,monospace;font-size:13.5px}
.tenue{color:#958ea3}
small.bloque{display:block;margin-top:3px;color:var(--muted);font-size:12px}
td small{color:var(--muted)}
/* Etiquetas */
.pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap}
.ok{background:var(--green-soft);color:#185b31}
.susp{background:var(--red-soft);color:#8a2424}
.gris{background:#f0edf4;color:#6d6478}
.rad{background:var(--blue-soft);color:var(--blue)}
.uisp{background:var(--amber-soft);color:var(--amber);cursor:help}
.pill.plan{background:var(--lavender-soft);color:var(--plum-2)}
.pill.plan.v30{background:var(--blue-soft);color:var(--blue)}
.pill.plan.v60{background:var(--green-soft);color:#185b31}
.pill.acc-suspender{background:var(--red-soft);color:#8a2424}
.pill.acc-reactivar{background:var(--green-soft);color:#185b31}
.pill.acc-cambiar_plan{background:var(--blue-soft);color:var(--blue)}
.pill.acc-alta{background:var(--lavender-soft);color:var(--plum-2)}
.punto{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;vertical-align:middle}
.punto.ok{background:#25a36f}.punto.gris{background:#cfc8d9}
/* Botones */
button{min-height:36px;padding:8px 14px;border:0;border-radius:9px;font-weight:600;font-size:13px}
button[disabled]{opacity:.45;cursor:not-allowed}
.b-susp{background:var(--red);color:#fff}
.b-react{background:var(--green);color:#fff}
.b-plan,.b-alta{background:var(--plum-3);color:#fff}
.b-alta{padding:11px 18px}
.acciones{display:flex;gap:8px;flex-wrap:nowrap;align-items:center;justify-content:flex-end}
form.inline{margin:0;display:flex;gap:6px;align-items:center}
/* Formularios y plegables */
.alta{display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end}
label{display:block;font-size:12px;font-weight:600;margin-bottom:6px;color:var(--plum-2)}
.alta input{width:100%;font-family:ui-monospace,Consolas,monospace}
details.plegable summary{cursor:pointer;font-weight:600;color:var(--plum-2);font-size:15px;list-style-position:inside}
details.plegable[open] summary{margin-bottom:14px}
table.historial{font-size:12.5px}
.nota{font-size:12px;color:var(--muted);margin:4px 2px 24px}
/* Buscador con etiqueta visible */
.buscador{display:flex;align-items:center;gap:8px;margin:0}
.buscador span{font-size:13px;font-weight:600;color:var(--plum-2);margin:0}
.b-sec{background:var(--surface);color:var(--plum-2);border:1px solid var(--line-strong)}
/* Confirmación (reemplaza la ventanita del navegador) */
dialog.confirmar{width:min(440px,calc(100vw - 32px));padding:22px;border:1px solid var(--line-strong);border-radius:var(--radius);background:var(--surface);color:var(--ink);box-shadow:0 18px 48px rgba(46,35,75,.07)}
dialog.confirmar::backdrop{background:rgba(41,36,53,.45)}
dialog.confirmar h2{margin:0 0 8px;font-size:18px;font-weight:600;color:var(--plum);line-height:1.3}
dialog.confirmar p{margin:0 0 18px;color:var(--muted)}
.dlg-acciones{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}
.campo-msg{display:block;min-height:16px;margin-top:4px;font-size:12px;font-weight:600;color:var(--muted)}
.campo-msg.mal{color:var(--red)}
.campo-msg.bien{color:#1f7a45}
input.campo-mal{border-color:var(--red);box-shadow:0 0 0 2px rgba(190,50,60,.15)}
button:disabled{opacity:.5;cursor:not-allowed}
.dlg-clave{display:block;margin:0 0 16px;font-size:13px;font-weight:600;color:var(--plum-2)}
.dlg-clave input{display:block;width:100%;margin-top:6px}
@media(max-width:760px){.buscador{width:100%;flex-wrap:wrap}.buscador span{width:100%}}
/* Celular y tabletas */
@media(max-width:1000px){.resumen{grid-template-columns:repeat(2,minmax(0,1fr))}.general .g-planes{margin-left:0;width:100%}}
@media(max-width:900px){.tabs{flex-wrap:nowrap;overflow-x:auto;padding-bottom:4px;scrollbar-width:thin}}
@media(max-width:760px){
    .general{gap:14px;padding:16px}.general .g-total{border:0;padding:0;width:100%}
    .barra input,.filtros input{width:100%}.barra .cuenta,.filtros .cuenta{margin-left:0}
    .alta{grid-template-columns:1fr}
    table,thead,tbody,tr,td{display:block}thead{display:none}
    tbody tr{display:grid;grid-template-columns:1fr 1fr;gap:2px 10px;border:1px solid var(--line);border-radius:14px;margin-bottom:10px;padding:10px 8px;background:#fff;box-shadow:0 4px 14px rgba(46,35,75,.04)}
    tbody tr:hover{background:#fff}
    td{border:0;padding:4px 6px;min-width:0;overflow-wrap:anywhere}
    td:not([data-t]),td[colspan],td[data-t="Cliente"],td[data-t="Usuario"],td[data-t="Detalle"]{grid-column:1/-1}
    .barra select,.filtros select{flex:1 1 140px}
    td[data-t]::before{content:attr(data-t);display:block;font-size:12px;font-weight:600;letter-spacing:.4px;text-transform:uppercase;color:var(--muted)}
    .acciones{justify-content:flex-start;flex-wrap:wrap;padding-top:4px}
    td.mono{overflow-wrap:normal;white-space:nowrap;font-size:12.5px}
}
@media(max-width:420px){.resumen{grid-template-columns:1fr 1fr;gap:8px}.tarjeta{padding:12px}.tarjeta b{font-size:20px}.general b{font-size:20px}.general .g-total b{font-size:28px}}
@media(prefers-reduced-motion:reduce){*{transition:none!important}}
    </style>
</head>
<body data-modulo="<?= htmlspecialchars($moduloActual, ENT_QUOTES) ?>">
<div class="app-shell">
    <?= portalMenu($moduloActual) ?>
    <div class="main-area">
        <main class="contenedor">
            <p class="page-eyebrow">Administración WISP · UBB Comunicaciones</p>
            <h1 class="titulo-pagina"><?= htmlspecialchars($titulo, ENT_QUOTES) ?></h1>
            <p class="sub"><?= htmlspecialchars($subtitulo, ENT_QUOTES) ?></p>
    <?php
}

/** Buscador con etiqueta visible ("Buscar") en vez de solo el texto gris de ejemplo. */
function portalBuscador(string $id, string $ejemplo): string
{
    $id = htmlspecialchars($id, ENT_QUOTES);
    return '<label class="buscador" for="' . $id . '"><span>Buscar</span>'
        . '<input id="' . $id . '" type="search" placeholder="' . htmlspecialchars($ejemplo, ENT_QUOTES) . '" autocomplete="off"></label>';
}

function portalFin(): void
{
    echo "        </main>\n    </div>\n</div>\n";
    ?>
<dialog class="confirmar" id="dlg-confirmar" aria-labelledby="dlg-titulo">
    <form method="dialog">
        <h2 id="dlg-titulo"></h2>
        <p id="dlg-texto"></p>
        <label class="dlg-clave" id="dlg-clave-campo" hidden>Clave de acciones
            <input type="password" id="dlg-clave" autocomplete="off">
        </label>
        <div class="dlg-acciones">
            <button class="b-sec" value="cancelar" formnovalidate autofocus>Cancelar</button>
            <button id="dlg-ok" value="ok"></button>
        </div>
    </form>
</dialog>
<script>
/* Formularios con data-confirmar: piden confirmación en un cuadro propio que nombra al cliente
   y cuyo botón dice la acción ("Suspender 192.168.x.x"), en lugar de "Aceptar". */
(function () {
    var dlg = document.getElementById('dlg-confirmar');
    if (!dlg || typeof dlg.showModal !== 'function') return; // navegador viejo: se envía sin cuadro
    var ok = document.getElementById('dlg-ok'), pendiente = null;
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f.dataset || !f.dataset.confirmar || f.dataset.confirmado) return;
        e.preventDefault();
        pendiente = {form: f, boton: e.submitter || null};
        document.getElementById('dlg-titulo').textContent = f.dataset.confirmar;
        document.getElementById('dlg-texto').textContent = f.dataset.detalle || '';
        ok.textContent = f.dataset.verbo || 'Confirmar';
        ok.className = f.dataset.tono === 'peligro' ? 'b-susp' : (f.dataset.tono === 'bien' ? 'b-react' : 'b-plan');
        var pideClave = !!f.dataset.pedirClave, campo = document.getElementById('dlg-clave-campo'), clave = document.getElementById('dlg-clave');
        campo.hidden = !pideClave;
        clave.required = pideClave;
        clave.value = '';
        dlg.returnValue = '';
        dlg.showModal();
        if (pideClave) clave.focus();
    });
    dlg.addEventListener('close', function () {
        var p = pendiente; pendiente = null;
        if (!p) return;
        var clave = document.getElementById('dlg-clave');
        if (dlg.returnValue === 'ok' && p.form.dataset.pedirClave) {
            var destino = p.form.querySelector('input[name="clave"]');
            if (destino) destino.value = clave.value;
        }
        clave.value = '';
        if (dlg.returnValue === 'ok') {
            p.form.dataset.confirmado = '1';
            if (p.boton && p.form.requestSubmit) p.form.requestSubmit(p.boton); else p.form.submit();
        } else if (p.boton) {
            p.boton.focus();
        }
    });
})();
</script>
<?php
}
