<?php

return [
    // Cuanto bloquea cada XREAD antes de despertar a mandar heartbeat.
    // Tiene que ser menor que cualquier idle timeout de proxy intermedio.
    'block_ms' => (int) env('SSE_BLOCK_MS', 15000),

    // Vida maxima de una conexion, en segundos. Al llegar cerramos limpio y el
    // cliente reconecta con Last-Event-ID (no pierde eventos). 0 = sin limite.
    'max_lifetime' => (int) env('SSE_MAX_LIFETIME', 600),

    // Cada cuanto reintenta el cliente si se cae la conexion (campo retry: de SSE).
    'retry_ms' => (int) env('SSE_RETRY_MS', 3000),
];
