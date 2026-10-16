# API (Laravel)

Backend de la evaluación de drivers de broadcasting. Implementa los drivers
candidatos `sse` y `apigateway`, usa el `mercure` nativo de Laravel y tiene las
piezas que comparten; el propósito, cómo arrancar y la comparación están en el
[README raíz](../README.md).

| Qué | Dónde |
|---|---|
| Los drivers propios (`sse`, `apigateway`) | `app/Broadcasting/` |
| El driver `mercure` (nativo de Laravel, solo configuración) | `config/broadcasting.php`, `app/Providers/BroadcastServiceProvider.php` |
| Registro de los drivers | `app/Providers/BroadcastServiceProvider.php` |
| Configuración y fan-out | `config/broadcasting.php` |
| Endpoints de cada driver | `app/Http/Controllers/` y `routes/api.php` |
| Autorización de canales | `routes/channels.php` |
| Evento y disparador de prueba | `app/Events/UserNotification.php`, `php artisan demo:notify` |
| Imagen FrankenPHP + hub Mercure | `docker/` |

## Tests

```bash
docker compose exec api php artisan test
```

Los tests de `apigateway` y `mercure` reemplazan AWS y el hub por dobles
(`tests/Feature/ApiGateway`, `tests/Feature/Mercure`), así que no necesitan
DynamoDB, `gateway-local` ni red. Los de `mercure` no prueban el framework: fijan
lo que la demo asume del driver nativo (topics, sobre del update, cookie de
suscriptor) y que hereda los canales de `routes/channels.php`.
