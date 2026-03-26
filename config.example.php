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
    'uploads_dir'    => __DIR__ . '/uploads'
];
