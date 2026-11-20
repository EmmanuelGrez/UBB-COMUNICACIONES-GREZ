<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Administración de Planes | UBB-COMUNICACIONES</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f6f5f8;
            color: #2d2b32;
        }

        /* ENCABEZADO */

        .encabezado {
            background: #ffffff;
            border-bottom: 1px solid #e8e5eb;
            padding: 22px 40px;

            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .marca {
            font-size: 20px;
            font-weight: bold;
            color: #73527f;
        }

        .subtitulo {
            color: #77727b;
            font-size: 14px;
        }

        /* CONTENIDO */

        .contenido {
            max-width: 1500px;
            margin: auto;
            padding: 35px 40px;
        }

        .titulo {
            margin-bottom: 25px;
        }

        .titulo h1 {
            font-size: 28px;
            margin-bottom: 7px;
        }

        .titulo p {
            color: #77727b;
        }

        /* TARJETAS */

        .tarjetas {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 25px;
        }

        .tarjeta {
            background: #ffffff;
            border: 1px solid #ebe8ee;
            border-radius: 15px;
            padding: 22px;
        }

        .tarjeta span {
            color: #77727b;
            font-size: 14px;
        }

        .tarjeta h2 {
            margin-top: 10px;
            font-size: 28px;
            color: #73527f;
        }

        .tarjeta-enlace {
            display: block;
            margin-top: 8px;
            color: #80608c;
            font-size: 13px;
            text-decoration: none;
        }

        /* BUSCADOR */

        .buscador {
            background: #ffffff;
            border: 1px solid #ebe8ee;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 25px;

            display: flex;
            gap: 12px;
        }

        .buscador input {
            flex: 1;
            padding: 12px 15px;
            border: 1px solid #ddd8e1;
            border-radius: 9px;
            outline: none;
            font-size: 14px;
        }

        .buscador input:focus {
            border-color: #80608c;
        }

        .boton-buscar {
            border: none;
            background: #80608c;
            color: white;
            padding: 12px 22px;
            border-radius: 9px;
            cursor: pointer;
        }

        /* PANELES */

        .panel {
            background: #ffffff;
            border: 1px solid #ebe8ee;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            overflow-x: auto;
        }

        .panel-titulo {
            margin-bottom: 20px;
        }

        .panel-titulo h2 {
            font-size: 20px;
        }

        .panel-titulo p {
            margin-top: 5px;
            color: #77727b;
            font-size: 13px;
        }

        /* TABLAS */

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        th,
        td {
            text-align: left;
            padding: 14px 10px;
            border-bottom: 1px solid #eee;
        }

        th {
            color: #77727b;
            font-size: 13px;
            font-weight: normal;
        }

        td {
            font-size: 14px;
        }

        .cliente {
            font-weight: bold;
            color: #403b44;
        }

        .datos {
            color: #66616b;
            font-size: 13px;
        }

        /* ESTADOS */

        .estado {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 12px;
        }

        .activo {
            background: #eee8f2;
            color: #73527f;
        }

        .pendiente {
            background: #f4edf6;
            color: #8a5b98;
        }

        /* CAMBIO SOLICITADO */

        .cambio {
            font-weight: bold;
            color: #73527f;
        }

        .flecha {
            color: #aaa3ad;
            margin: 0 5px;
        }

        /* BOTONES */

        .boton {
            border: none;
            background: #80608c;
            color: white;
            padding: 8px 14px;
            border-radius: 8px;
            cursor: pointer;
        }

        .boton:hover {
            background: #694875;
        }

        .boton-secundario {
            border: 1px solid #ddd8e1;
            background: white;
            color: #73527f;
            padding: 8px 14px;
            border-radius: 8px;
            cursor: pointer;
        }

        /* RESPONSIVO */

        @media (max-width: 1000px) {

            .tarjetas {
                grid-template-columns: repeat(2, 1fr);
            }

        }

        @media (max-width: 650px) {

            .encabezado {
                padding: 20px;
            }

            .contenido {
                padding: 25px 20px;
            }

            .tarjetas {
                grid-template-columns: 1fr;
            }

        }
    </style>
</head>


<body>

    <!-- ENCABEZADO -->

    <header class="encabezado">

        <div class="marca">
            UBB-COMUNICACIONES
        </div>

        <div class="subtitulo">
            Administración de planes
        </div>

    </header>


    <!-- CONTENIDO -->

    <main class="contenido">

        <!-- TÍTULO -->

        <section class="titulo">

            <h1>Administración de planes</h1>

            <p>
                Consulta los servicios y gestiona las solicitudes de cambio de plan.
            </p>

        </section>


        <!-- RESUMEN -->

        <section class="tarjetas">

            <div class="tarjeta">

                <span>Clientes activos</span>

                <h2>128</h2>

            </div>


            <div class="tarjeta">

                <span>Planes disponibles</span>

                <h2>8</h2>

            </div>


            <div class="tarjeta">

                <span>Cambios pendientes</span>

                <h2>3</h2>

                <a href="#" class="tarjeta-enlace">
                    Ver solicitudes
                </a>

            </div>


            <div class="tarjeta">

                <span>Cambios programados</span>

                <h2>2</h2>

                <a href="#" class="tarjeta-enlace">
                    Ver programados
                </a>

            </div>

        </section>


        <!-- BUSCADOR -->

        <section class="buscador">

            <input
                type="text"
                placeholder="Buscar por cliente, IP o MAC..."
            >

            <button class="boton-buscar">
                Buscar
            </button>

        </section>


        <!-- CAMBIOS PENDIENTES -->

        <section class="panel">

            <div class="panel-titulo">

                <h2>Solicitudes de cambio de plan</h2>

                <p>
                    Clientes que tienen un cambio pendiente de revisión.
                </p>

            </div>


            <table>

                <thead>

                    <tr>
                        <th>Cliente</th>
                        <th>Plan actual</th>
                        <th>Nuevo plan</th>
                        <th>Velocidad</th>
                        <th>Aplicación</th>
                        <th>Estado</th>
                        <th>Acción</th>
                    </tr>

                </thead>


                <tbody>

                    <tr>

                        <td>
                            <div class="cliente">
                                ANA-KAREN
                            </div>
                        </td>

                        <td>
                            Plan Básico
                        </td>

                        <td>
                            <span class="cambio">
                                Plan 100
                            </span>
                        </td>

                        <td>
                            50 → 100 Mbps
                        </td>

                        <td>
                            15/10/2026
                        </td>

                        <td>

                            <span class="estado pendiente">
                                Pendiente
                            </span>

                        </td>

                        <td>

                            <button class="boton">
                                Revisar
                            </button>

                        </td>

                    </tr>


                    <tr>

                        <td>
                            <div class="cliente">
                                PEDRO
                            </div>
                        </td>

                        <td>
                            Plan 30
                        </td>

                        <td>
                            <span class="cambio">
                                Plan 50
                            </span>
                        </td>

                        <td>
                            30 → 50 Mbps
                        </td>

                        <td>
                            Inmediato
                        </td>

                        <td>

                            <span class="estado pendiente">
                                Pendiente
                            </span>

                        </td>

                        <td>

                            <button class="boton">
                                Revisar
                            </button>

                        </td>

                    </tr>

                </tbody>

            </table>

        </section>


        <!-- CLIENTES -->

        <section class="panel">

            <div class="panel-titulo">

                <h2>Clientes</h2>

                <p>
                    Información actual de los servicios.
                </p>

            </div>


            <table>

                <thead>

                    <tr>
                        <th>Cliente</th>
                        <th>Plan actual</th>
                        <th>Velocidad</th>
                        <th>IP</th>
                        <th>MAC</th>
                        <th>Fecha de corte</th>
                        <th>Estado</th>
                        <th>Acción</th>
                    </tr>

                </thead>


                <tbody>

                    <tr>

                        <td>
                            <div class="cliente">
                                ANA-KAREN
                            </div>
                        </td>

                        <td>
                            Plan Básico
                        </td>

                        <td>
                            50 Mbps
                        </td>

                        <td>
                            <span class="datos">
                                10.5.3.198
                            </span>
                        </td>

                        <td>
                            <span class="datos">
                                30:68:93:0C:1C:B5
                            </span>
                        </td>

                        <td>
                            15/10/2026
                        </td>

                        <td>

                            <span class="estado activo">
                                Activo
                            </span>

                        </td>

                        <td>

                            <button class="boton-secundario">
                                Ver
                            </button>

                        </td>

                    </tr>


                    <tr>

                        <td>
                            <div class="cliente">
                                PEDRO
                            </div>
                        </td>

                        <td>
                            Plan 30
                        </td>

                        <td>
                            30 Mbps
                        </td>

                        <td>
                            <span class="datos">
                                10.5.3.150
                            </span>
                        </td>

                        <td>
                            <span class="datos">
                                XX:XX:XX:XX:XX:XX
                            </span>
                        </td>

                        <td>
                            20/10/2026
                        </td>

                        <td>

                            <span class="estado activo">
                                Activo
                            </span>

                        </td>

                        <td>

                            <button class="boton-secundario">
                                Ver
                            </button>

                        </td>

                    </tr>

                </tbody>

            </table>

        </section>

    </main>

</body>

</html>