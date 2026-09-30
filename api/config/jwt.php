<?php

return [
    'secret' => env('JWT_SECRET', 'change-me-in-production'),
    'algo' => env('JWT_ALGO', 'HS256'),
    'ttl' => (int) env('JWT_TTL', 3600),
    'issuer' => env('JWT_ISSUER', 'eventsource-demo'),
];
