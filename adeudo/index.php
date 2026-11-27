<?php
// Evita que el navegador guarde esta página en caché.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$ipCliente = htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '', ENT_QUOTES, 'UTF-8');

$whatsappNumero  = '522382114997';
$whatsappMensaje = 'Hola, quiero reactivar mi servicio de internet.'
                 . ($ipCliente !== '' ? ' Mi referencia es ' . $ipCliente . '.' : '');
// Abre la aplicación de WhatsApp directo (wa.me necesita internet para redirigir y el cliente cortado no tiene).
$whatsappUrl     = 'whatsapp://send?phone=' . $whatsappNumero . '&text=' . rawurlencode($whatsappMensaje);
?>
<!DOCTYPE html>
<html lang="es">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Tu servicio está en pausa - UBB Comunicaciones</title>
   <link rel="stylesheet" href="/assets/css/style.css">

   <style>
      .aviso-grid { display: grid; grid-template-columns: 1fr; gap: 0; }
      .saludo { font-size: 17px; color: #444; }
      .pasos { text-align: left; background: #f7f1f9; border-radius: 10px; padding: 18px 20px 6px; margin: 20px 0; }
      .pasos h2 { font-size: 17px; color: #9254a4; margin-bottom: 12px; }
      .pasos ol { padding-left: 22px; color: #555; }
      .pasos li { margin-bottom: 10px; line-height: 1.5; }
      .nota-pagado { border-left: 4px solid #25d366; background: #f0fbf4; text-align: left; padding: 12px 16px; border-radius: 6px; margin-top: 20px; }
      .nota-pagado p { margin-bottom: 0; font-size: 15px; }
      .horario { margin-top: 20px; font-size: 14px; color: #666; line-height: 1.6; }
      .horario strong { color: #9254a4; }
      .numero { font-weight: bold; color: #333; white-space: nowrap; }
      .referencia { margin-top: 14px; font-size: 12px; color: #999; }
      .gracias { margin-top: 18px; font-style: italic; color: #9254a4; }
      .nota-datos { font-size: 13px; color: #666; margin-top: 10px; line-height: 1.5; }

      @media (min-width: 860px) {
         .contenedor-aviso { max-width: 920px; padding: 44px 48px; text-align: left; }
         .encabezado { text-align: center; margin-bottom: 10px; }
         .aviso-grid { grid-template-columns: 1.15fr 1fr; gap: 40px; align-items: start; }
         .columna-acciones { background: #faf7fb; border-radius: 12px; padding: 24px; text-align: center; }
         .columna-acciones .nota-pagado { margin-top: 18px; }
         .boton-contacto { display: block; margin-top: 0; }
         h1 { font-size: 28px; }
         .saludo { font-size: 18px; }
         .pasos { margin-bottom: 0; }
      }
   </style>
</head>

<body>
   <div id="contenedor-burbujas"></div>

   <main class="contenedor-aviso">

      <div class="encabezado">
         <img src="/assets/img/logo-ubb.jpg" alt="Logo UBB Comunicaciones" class="logo">
         <h1>Tu servicio está en pausa</h1>
      </div>

      <div class="aviso-grid">

         <section class="columna-mensaje">
            <p class="saludo">¡Hola! Queremos avisarte que tu servicio de internet se encuentra
               temporalmente en pausa porque tenemos registrado un pago pendiente.</p>

            <p>No te preocupes, renovar tu conexión es muy sencillo y estamos aquí para ayudarte.</p>

            <div class="pasos">
               <h2>¿Cómo renuevo mi servicio?</h2>
               <ol>
                  <li>Realiza tu pago por los medios habituales.</li>
                  <li>Envíanos tu comprobante por WhatsApp al
                     <span class="numero">238 211 4997</span>.</li>
                  <li>¡Listo! Tu conexión se restablecerá en unos minutos.</li>
               </ol>
            </div>
         </section>

         <aside class="columna-acciones">
            <a href="<?= htmlspecialchars($whatsappUrl, ENT_QUOTES, 'UTF-8') ?>" class="boton-contacto">
               Enviar mi comprobante por WhatsApp
            </a>
            <p class="nota-datos">Se abre tu WhatsApp. Si el mensaje no se envía, desactiva el WiFi y usa tus datos
               móviles: con el servicio en pausa no hay internet por el WiFi.</p>

            <div class="nota-pagado">
               <p><strong>¿Ya realizaste tu pago?</strong> ¡Muchas gracias! Si en unos minutos
                  tu servicio no se restablece, reinicia tu módem o escríbenos y con gusto te ayudamos.</p>
            </div>

            <div class="horario">
               <strong>Horario de atención</strong><br>
               Lunes a viernes: 9:00 a 14:00 y 16:00 a 20:00<br>
               Sábados: 10:00 a 19:00
            </div>

            <p class="gracias">Gracias por confiar en UBB Comunicaciones.</p>

            <?php if ($ipCliente !== ''): ?>
               <p class="referencia">Referencia para soporte: <?= $ipCliente ?></p>
            <?php endif; ?>
         </aside>

      </div>
   </main>

   <footer>
      <p>¿Tienes alguna duda? Estamos para ayudarte.</p>
      <p>UBB Comunicaciones | Tel. y WhatsApp: 238 211 4997</p>
      <p>Correo: ubbnetworks@gmail.com</p>
   </footer>

   <script src="/assets/js/main.js"></script>
</body>

</html>
