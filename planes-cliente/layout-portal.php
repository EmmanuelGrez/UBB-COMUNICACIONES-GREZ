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
body{margin:0;background:radial-gradient(ellipse at 80% 0%,rgba(187,169,239,.16),transparent 33%),var(--bg);color:var(--ink);font-family:Inter,"Segoe UI",Arial,Helvetica,sans-serif;font-size:13px;line-height:1.55;-webkit-font-smoothing:antialiased}
button,input,select{font:inherit}
button{cursor:pointer}
a{color:var(--plum-3)}
/* Marco: barra lateral + contenido */
.app-shell{min-height:100vh;display:grid;grid-template-columns:258px minmax(0,1fr)}
.sidebar{position:sticky;top:0;height:100vh;display:flex;flex-direction:column;padding:26px 17px 19px;background:linear-gradient(160deg,#302440 0%,#3e2d59 52%,#2e2540 100%);color:#f8f6ff;overflow-y:auto;box-shadow:8px 0 34px rgba(35,24,54,.10);z-index:20}
.sidebar-brand{display:flex;align-items:center;gap:12px;padding:3px 9px 25px;border-bottom:1px solid rgba(255,255,255,.12)}
.brand-monogram{width:45px;height:45px;display:grid;place-items:center;border:1px solid rgba(255,255,255,.22);border-radius:14px;background:linear-gradient(145deg,rgba(255,255,255,.18),rgba(255,255,255,.06));font-weight:850;font-size:14px;letter-spacing:.7px}
.sidebar-brand strong{display:block;font-size:13px;letter-spacing:.6px}
.sidebar-brand small{display:block;margin-top:3px;color:#c9bfdc;font-size:9px;letter-spacing:1.3px;text-transform:uppercase}
.sidebar-nav{display:grid;gap:15px;margin-top:22px}
.nav-group{display:grid;gap:5px;min-width:0}
.nav-group-label{padding:0 12px 3px;color:#a99cbd;font-size:9px;font-weight:850;letter-spacing:1.1px;text-transform:uppercase}
.nav-link{display:flex;align-items:center;gap:12px;padding:11px 13px;border:1px solid transparent;border-radius:12px;color:#ded7eb;font-size:12px;font-weight:650;text-decoration:none;transition:background .2s,transform .2s}
.nav-link:hover{background:rgba(255,255,255,.09);transform:translateX(2px)}
.nav-link.active{background:linear-gradient(100deg,rgba(154,126,235,.34),rgba(154,126,235,.13));border-color:rgba(205,190,255,.22);color:#fff;box-shadow:inset 3px 0 #c8b8ff}
.nav-icon{width:20px;text-align:center;color:#c7b9ed;font-size:17px}
.main-area{min-width:0}
.contenedor{width:min(1500px,100%);margin:0 auto;padding:28px 32px 28px}
.page-eyebrow{margin:0 0 6px 2px;color:#827b94;font-size:10px;font-weight:850;letter-spacing:1.6px;text-transform:uppercase}
.titulo-pagina{margin:0 0 4px;color:var(--plum);font-size:clamp(20px,2.2vw,28px);letter-spacing:-.6px;line-height:1.2}
.sub{margin:0 0 18px;color:var(--muted);font-size:13px}
/* Bloques */
.caja{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:18px;margin-bottom:16px;box-shadow:var(--shadow)}
.general{display:flex;flex-wrap:wrap;gap:24px;align-items:center;background:linear-gradient(115deg,#38294f 0%,#59427e 52%,#7960bd 100%);color:#fff;border-radius:21px;padding:18px 24px;margin-bottom:16px;box-shadow:0 15px 36px rgba(66,48,105,.18)}
.general b{display:block;font-size:24px;line-height:1.2}
.general span{font-size:11.5px;color:#e3ddef}
.general .g-total b{font-size:34px}
.general .g-total{padding-right:24px;border-right:1px solid rgba(255,255,255,.22)}
.general .g-planes{margin-left:auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.general em{font-style:normal;background:rgba(255,255,255,.16);padding:4px 11px;border-radius:999px;font-size:12px;font-weight:700}
.tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.tab{padding:7px 14px;border-radius:999px;background:var(--surface);border:1px solid var(--line-strong);color:var(--plum-2);text-decoration:none;font-weight:700;font-size:12.5px;white-space:nowrap}
.tab:hover{border-color:var(--lavender)}
.tab.activo{background:var(--plum-3);color:#fff;border-color:var(--plum-3)}
.tab small{display:inline-block;margin-left:5px;padding:1px 7px;border-radius:999px;background:var(--lavender-soft);color:var(--plum-2);font-size:10.5px}
.tab.activo small{background:rgba(255,255,255,.22);color:#fff}
.resumen{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
.tarjeta{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-sm);padding:14px 16px;box-shadow:var(--shadow)}
.tarjeta b{display:block;font-size:24px;color:var(--plum)}
.tarjeta span{font-size:12px;color:var(--muted)}
.mensaje,.error,.aviso,.fase{padding:12px 16px;border-radius:12px;margin-bottom:14px;font-size:13px;border:1px solid var(--line);border-left:4px solid var(--plum-3);background:var(--surface)}
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
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:11px 10px;border-bottom:1px solid var(--line);text-align:left;vertical-align:middle}
th{font-size:10.5px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);background:var(--surface);position:sticky;top:0;z-index:1}
tbody tr:hover{background:var(--surface-soft)}
.mono{font-family:ui-monospace,"Cascadia Mono",Consolas,monospace;font-size:12.5px}
.tenue{color:#958ea3}
small.bloque{display:block;margin-top:3px;color:var(--muted);font-size:11.5px}
td small{color:var(--muted)}
/* Etiquetas */
.pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11.5px;font-weight:700;white-space:nowrap}
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
button{padding:8px 14px;border:0;border-radius:9px;font-weight:700;font-size:12.5px}
button[disabled]{opacity:.45;cursor:not-allowed}
.b-susp{background:var(--red);color:#fff}
.b-react{background:var(--green);color:#fff}
.b-plan,.b-alta{background:var(--plum-3);color:#fff}
.b-alta{padding:11px 18px}
.acciones{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:flex-end}
form.inline{margin:0;display:flex;gap:6px;align-items:center}
/* Formularios y plegables */
.alta{display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end}
label{display:block;font-size:12px;font-weight:700;margin-bottom:6px;color:var(--plum-2)}
.alta input{width:100%;font-family:ui-monospace,Consolas,monospace}
details.plegable summary{cursor:pointer;font-weight:800;color:var(--plum-2);font-size:15px;list-style-position:inside}
details.plegable[open] summary{margin-bottom:14px}
table.historial{font-size:12.5px}
.nota{font-size:11.5px;color:var(--muted);margin:4px 2px 24px}
/* Celular y tabletas */
@media(max-width:1180px){.app-shell{grid-template-columns:220px minmax(0,1fr)}.contenedor{padding:22px}}
@media(max-width:1000px){.resumen{grid-template-columns:repeat(2,minmax(0,1fr))}.general .g-planes{margin-left:0;width:100%}}
@media(max-width:900px){
    .app-shell{display:block}
    .sidebar{position:sticky;top:0;height:auto;padding:10px 14px;flex-direction:row;align-items:center;gap:12px;overflow:visible}
    .sidebar-brand{padding:0;border:0}.sidebar-brand small,.sidebar-brand strong{display:none}
    .brand-monogram{width:38px;height:38px;font-size:12px;flex:0 0 38px}
    .sidebar-nav{display:flex;gap:6px;margin:0;overflow-x:auto;scrollbar-width:none;min-width:0}
    .sidebar-nav::-webkit-scrollbar{display:none}
    .nav-group{display:flex;gap:6px;flex:0 0 auto}.nav-group-label{display:none}
    .nav-link{padding:8px 11px;font-size:11.5px;white-space:nowrap}.nav-link:hover{transform:none}
    .contenedor{padding:16px}
    .tabs{flex-wrap:nowrap;overflow-x:auto;padding-bottom:4px;scrollbar-width:thin}
}
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
    td[data-t]::before{content:attr(data-t);display:block;font-size:9.5px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:var(--muted)}
    .acciones{justify-content:flex-start;padding-top:4px}
}
@media(max-width:420px){.resumen{grid-template-columns:1fr 1fr;gap:8px}.tarjeta{padding:12px}.tarjeta b{font-size:20px}.general b{font-size:20px}.general .g-total b{font-size:28px}}
@media(prefers-reduced-motion:reduce){*{transition:none!important}}
    </style>
</head>
<body data-modulo="<?= htmlspecialchars($moduloActual, ENT_QUOTES) ?>">
<div class="app-shell">
    <aside class="sidebar" aria-label="Navegación principal">
        <div class="sidebar-brand">
            <div class="brand-monogram">UBB</div>
            <div><strong>UBB COMUNICACIONES</strong><small>Gestión de red</small></div>
        </div>
        <nav class="sidebar-nav" aria-label="Módulos del sistema">
            <div class="nav-group">
                <span class="nav-group-label">General</span>
                <?= portalEnlace('inicio', $moduloActual, '⌂', 'Inicio') ?>
            </div>
            <div class="nav-group">
                <span class="nav-group-label">Clientes y servicios</span>
                <?= portalEnlace('clientes', $moduloActual, '◉', 'Clientes PPPoE') ?>
            </div>
            <div class="nav-group">
                <span class="nav-group-label">Planes y perfiles</span>
                <?= portalEnlace('catalogo', $moduloActual, '◇', 'Catálogo comercial') ?>
                <?= portalEnlace('perfiles', $moduloActual, '≋', 'Perfiles MikroTik') ?>
            </div>
            <div class="nav-group">
                <span class="nav-group-label">Monitoreo y control</span>
                <?= portalEnlace('centro-radius', $moduloActual, '◉', 'Centro RADIUS') ?>
                <?= portalEnlace('radius-dhcp', $moduloActual, '⇄', 'Clientes DHCP RADIUS') ?>
                <?= portalEnlace('radius-pppoe', $moduloActual, '⇆', 'Clientes PPPoE RADIUS') ?>
                <?= portalEnlace('historial', $moduloActual, '◷', 'Gestión de cambios') ?>
            </div>
        </nav>
    </aside>
    <div class="main-area">
        <main class="contenedor">
            <p class="page-eyebrow">Administración WISP · UBB Comunicaciones</p>
            <h1 class="titulo-pagina"><?= htmlspecialchars($titulo, ENT_QUOTES) ?></h1>
            <p class="sub"><?= htmlspecialchars($subtitulo, ENT_QUOTES) ?></p>
    <?php
}

function portalFin(): void
{
    echo "        </main>\n    </div>\n</div>\n";
}
