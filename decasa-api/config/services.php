<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // "Entrar con Google". Sólo el client_id: el ID token se verifica contra
    // Google y no hay secreto que guardar. Si no está puesto, la app no ofrece
    // el botón y todo el mundo sigue entrando con su contraseña.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],

    // Facturación electrónica (docs/plan-facturacion-alegra.md). El token sale
    // de Alegra → Configuración → "API - Integraciones con otros sistemas" y va
    // SOLO en las variables de Render, nunca en el repo. Con el JWT nuevo
    // ("eyJ…") basta ALEGRA_TOKEN; con el token clásico va también el correo.
    'alegra' => [
        'url'     => env('ALEGRA_URL', 'https://api.alegra.com/api/v1'),
        'email'   => env('ALEGRA_EMAIL'),
        'token'   => env('ALEGRA_TOKEN'),
        'timeout' => (int) env('ALEGRA_TIMEOUT', 60),
    ],

    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key'    => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
    ],

];
