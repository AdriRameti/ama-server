<?php
// Simple config for PHP API - change password before deployment
return [
    'admin_password' => 'CHANGE_ME',
    // Origen permitido para CORS (cambiar en producción al dominio real)
    // Ejemplo producción: 'https://www.amaagullent.com'
    'cors_origin'    => 'http://localhost:3000',
    'content_file'   => __DIR__ . '/content.json',
    'images_file'    => __DIR__ . '/images.json',
    'collections_file' => __DIR__ . '/collections.json',
    'uploads_dir'    => __DIR__ . '/uploads',

    // SMTP config para envío de emails (contacto)
    // Brevo (gratis 300 emails/día): https://www.brevo.com
    // Gmail SMTP: smtp.gmail.com, puerto 587, usar App Password
    'smtp_host'      => 'smtp-relay.brevo.com',
    'smtp_port'      => 587,
    'smtp_user'      => 'TU_EMAIL_SMTP',
    'smtp_pass'      => 'TU_API_KEY_O_PASSWORD',
    'smtp_from'      => 'noreply@amagullent.org',
    'smtp_from_name' => 'AMA Agullent',
    'contact_to'     => 'info@amagullent.org'
];
