<?php
/*
 * clave-acciones.php - Configura la clave que el panel pide en acciones delicadas
 * (por ahora: "Pasar a RADIUS" las concesiones fijas). Solo desde la terminal.
 *
 * Solo se guarda su huella (password_hash) en /etc/ubb-panel/clave-acciones.hash; la clave no queda escrita.
 *
 *   docker exec -it servidor-aviso php /var/www/html/planes-cliente/clave-acciones.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const ARCHIVO_CLAVE = '/etc/ubb-panel/clave-acciones.hash';

function pedir(string $texto): string
{
    echo $texto;
    system('stty -echo');
    $v = trim((string) fgets(STDIN));
    system('stty echo');
    echo "\n";
    return $v;
}

$clave = pedir('Nueva clave de acciones (mínimo 8 caracteres, no se ve al escribir): ');
if (strlen($clave) < 8) {
    fwrite(STDERR, "Muy corta: usa al menos 8 caracteres.\n");
    exit(1);
}
if (pedir('Repítela: ') !== $clave) {
    fwrite(STDERR, "No coinciden. No se cambió nada.\n");
    exit(1);
}
if (!is_dir(dirname(ARCHIVO_CLAVE))) {
    mkdir(dirname(ARCHIVO_CLAVE), 0750, true);
}
file_put_contents(ARCHIVO_CLAVE, password_hash($clave, PASSWORD_DEFAULT) . "\n");
@chgrp(ARCHIVO_CLAVE, 'www-data');
chmod(ARCHIVO_CLAVE, 0640);
echo "Listo: clave de acciones guardada (solo su huella). La anterior, si había, ya no sirve.\n";
