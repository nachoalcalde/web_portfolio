<?php
/**
 * Plantilla del fichero de credenciales que usa public/api/contact.php.
 *
 * DÓNDE VA: en el servidor, UN NIVEL POR ENCIMA de public_html, con el nombre
 * `contact-secrets.php`. Es decir, al lado de la carpeta public_html, no dentro.
 * Ahí el servidor web no lo sirve nunca, así que la contraseña no queda
 * expuesta aunque alguien adivine la ruta.
 *
 * QUÉ NO HACER: no lo metas en public_html, ni en la carpeta public/ del
 * proyecto, ni lo subas al repositorio. Este fichero de ejemplo no lleva
 * contraseña y no se publica: `astro build` solo copia public/.
 *
 * SI NO EXISTE, el endpoint sigue funcionando: envía con mail() del servidor
 * web, que es lo que acaba en spam. Con él, sale por SMTP autenticado.
 */

return [
    // El buzón del dominio que autentica el envío. El From: del correo será
    // este mismo, que es lo que hace que SPF y DKIM cuadren.
    'user' => 'no-reply@nachoalcaldecid.com',

    // La contraseña de ese buzón. Se escribe aquí, en el servidor, y no viaja
    // por ningún otro sitio.
    'pass' => 'PON-AQUI-LA-CONTRASENA',

    // Opcionales. Solo hacen falta si hPanel te da un servidor SMTP distinto
    // al de por defecto (smtp.hostinger.com, puerto 465 con SSL).
    // 'host' => 'smtp.hostinger.com',
    // 'port' => 465,
];

// El nombre que se ve como remitente en la bandeja de entrada NO se pone aquí:
// está en la constante FROM_NAME de api/contact.php, porque no es un secreto.
