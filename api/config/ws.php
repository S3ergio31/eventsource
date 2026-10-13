<?php

return [
    // Vida del ticket con el que el navegador abre el WebSocket, en segundos.
    // Solo tiene que alcanzar para el handshake.
    'ticket_ttl' => (int) env('WS_TICKET_TTL', 60),

    // Secreto compartido con API Gateway (o el emulador local). Lo inyecta en
    // cada request a /api/ws/gateway/* como header X-Gateway-Secret; sin el,
    // cualquiera podria inventar connectionIds.
    'gateway_secret' => env('WS_GATEWAY_SECRET', ''),
];
