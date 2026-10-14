<?php

return [

    'default' => env('BROADCAST_CONNECTION', 'sse'),

    // Conexiones por las que sale UserNotification. Con los tres drivers
    // candidatos, un mismo demo:notify llega por SSE, API Gateway y Mercure y
    // se comparan sobre el mismo evento. En produccion quedaria solo el elegido.
    'fanout' => array_values(array_filter(explode(',', env('BROADCAST_FANOUT', 'sse,apigateway,mercure')))),

    'connections' => [

        // Driver custom 'sse': Laravel sostiene el stream SSE y el estado vive
        // en Redis Streams. El nombre del campo driver es el que registramos
        // con Broadcast::extend() en BroadcastServiceProvider.
        'sse' => [
            'driver' => 'sse',

            // Conexion Redis para escribir (XADD). Peticion corta, timeouts normales.
            'connection' => 'default',

            // Conexion Redis separada para leer (XREAD BLOCK). Necesita
            // read_timeout alto, ver config/database.php.
            'read_connection' => 'sse',

            // Prefijo de las claves de stream: sse:stream:private-user.1
            'prefix' => env('SSE_STREAM_PREFIX', 'sse:stream:'),

            // Cuantos eventos guarda cada stream para reponer reconexiones.
            'retain' => (int) env('SSE_STREAM_RETAIN', 100),
        ],

        // Driver custom 'apigateway': los WebSockets los sostiene API Gateway
        // y el estado vive en DynamoDB, sin Redis.
        // Registrado con Broadcast::extend('apigateway') en BroadcastServiceProvider.
        'apigateway' => [
            'driver' => 'apigateway',

            // Endpoint de @connections: https://{api-id}.execute-api.{region}.amazonaws.com/{stage}
            // En local, el emulador de gateway-local/.
            'management_endpoint' => env('WS_MANAGEMENT_ENDPOINT', 'http://gateway-local:3001'),

            // Vacio en AWS; en local apunta a DynamoDB Local.
            'dynamodb_endpoint' => env('DYNAMODB_ENDPOINT'),

            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),

            // Tablas: {prefix}connections, {prefix}subscriptions, {prefix}events.
            'table_prefix' => env('WS_TABLE_PREFIX', 'ws_'),

            // Segundos. Una conexion de API Gateway vive como mucho 2 h.
            'connection_ttl' => (int) env('WS_CONNECTION_TTL', 10800),

            // Segundos que se guarda cada evento para reponer reconexiones.
            'events_ttl' => (int) env('WS_EVENTS_TTL', 86400),
        ],

        // Driver custom 'mercure': el hub Mercure integrado en FrankenPHP sostiene
        // las conexiones SSE y Laravel solo publica. Ver docker/Caddyfile.
        // Registrado con Broadcast::extend('mercure') en BroadcastServiceProvider.
        'mercure' => [
            'driver' => 'mercure',

            // Donde publica Laravel. El hub vive en el mismo FrankenPHP, asi
            // que desde adentro del contenedor es localhost.
            'hub_url' => env('MERCURE_URL', 'http://localhost:8000/.well-known/mercure'),

            // Donde se suscribe el navegador.
            'public_url' => env('MERCURE_PUBLIC_URL', 'http://localhost:8000/.well-known/mercure'),

            // Las mismas claves que usa el hub (Caddyfile). Caddy las lee del
            // entorno del contenedor, no del .env: ver docker-compose.yml.
            'publisher_key' => env('MERCURE_PUBLISHER_JWT_KEY'),
            'subscriber_key' => env('MERCURE_SUBSCRIBER_JWT_KEY'),

            // Segundos de vida del JWT de suscriptor. El hub no vuelve a
            // consultar a Laravel mientras sea valido: es la ventana en la que
            // un acceso revocado todavia funciona.
            'subscriber_ttl' => (int) env('MERCURE_SUBSCRIBER_TTL', 3600),
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
