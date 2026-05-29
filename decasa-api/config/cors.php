<?php

return [

    'paths' => ['api/*', 'storage/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(
        explode(',', env('CORS_ALLOWED_ORIGINS', 'https://sistema-de-ventas-olive.vercel.app'))
    ),

    'allowed_origins_patterns' => [
        // Permite cualquier preview deploy de Vercel del mismo proyecto
        '#^https://sistema-de-ventas-[a-z0-9\-]+-decasasystem\.vercel\.app$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
