<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:4200')),
    'allowed_origins_patterns' => [],
    // Incluye Authorization y Last-Event-ID, los dos headers que manda
    // @microsoft/fetch-event-source.
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    // El driver mercure entrega el JWT de suscriptor en una cookie: sin
    // credenciales el navegador descarta el Set-Cookie de una respuesta de
    // otro origen (Angular corre en :4200, la API en :8000).
    'supports_credentials' => true,
];
