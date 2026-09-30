<?php

return [

    'default' => env('BROADCAST_CONNECTION', 'sse'),

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

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
