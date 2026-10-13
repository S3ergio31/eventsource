<?php

return [

    'default' => env('BROADCAST_CONNECTION', 'sse'),

    // Conexiones por las que sale UserNotification. Con las dos, un mismo
    // demo:notify llega por SSE y por API Gateway para compararlos.
    'fanout' => array_values(array_filter(explode(',', env('BROADCAST_FANOUT', 'sse,apigateway')))),

    'connections' => [

        // Nuestro driver custom. El nombre 'sse' del campo driver es el que
        // registramos con Broadcast::extend() en BroadcastServiceProvider.
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

        // Segundo driver custom, en paralelo al de SSE: los WebSockets los
        // sostiene API Gateway y el estado vive en DynamoDB, sin Redis.
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

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
