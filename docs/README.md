# Diagramas de los drivers de broadcasting

Este repo evalúa tres drivers de broadcasting de Laravel (dos propios y el
`mercure` nativo del framework) para decidir
cuál adopta la empresa; la comparación y la recomendación están en
[Evaluación y decisión](../README.md#evaluación-y-decisión). Los tres corren
**a la vez**: un mismo `UserNotification` sale por todos los drivers listados en
`BROADCAST_FANOUT` (por defecto `sse,apigateway,mercure`), así se comparan sobre
el mismo evento.

| Driver | Quién sostiene la conexión | Estado / historial | Diagramas |
|---|---|---|---|
| `sse` | Un hilo PHP por cliente (FrankenPHP, `num_threads 64`) | Redis Streams | [sse.md](sse.md) |
| `apigateway` | AWS API Gateway WebSocket (`gateway-local` en docker) | DynamoDB (3 tablas con TTL) | [apigateway.md](apigateway.md) |
| `mercure` | Hub Mercure en Go, dentro de FrankenPHP (driver nativo + Echo) | Bolt (archivo local del hub) | [mercure.md](mercure.md) |

Cada documento tiene un **diagrama de flujo** (decisiones, errores y
reconexión) y un **diagrama de secuencia** end-to-end (autenticación →
suscripción → publicación → entrega → reconexión).

## Registro de los drivers

`BroadcastServiceProvider::boot()` registra los tres drivers con
`Broadcast::extend()`. `mercure` es el driver nativo de Laravel: el `extend()`
solo llama al creador del framework (`createMercureDriver()`, que arma el `Hub`
desde `config/broadcasting.php`). Los callbacks de `routes/channels.php` se
registran solo en el driver por defecto; `apigateway` y `mercure` los copian,
así la autorización tiene una única fuente de verdad.

```mermaid
flowchart TD
    BOOT["BroadcastServiceProvider::boot()"]
    BOOT --> E1["Broadcast::extend('sse')<br/>new SseBroadcaster(Redis)"]
    BOOT --> E2["Broadcast::extend('apigateway')<br/>new ApiGatewayBroadcaster(ConnectionStore, ApiGatewayManagementApiClient)"]
    BOOT --> E3["Broadcast::extend('mercure')<br/>createMercureDriver(config)<br/>Illuminate MercureBroadcaster (nativo)"]

    CH["routes/channels.php<br/>Broadcast::channel('user.{userId}')"] -->|"se registra en el driver por defecto"| E1
    E1 -. "inheritChannels(): getChannels()" .-> E2
    E1 -. "inheritChannels(): getChannels()" .-> E3

    REG["register(): singletons"] --> S1["ConnectionStore (DynamoDbClient)"]
    REG --> S2["ApiGatewayManagementApiClient"]
    S1 --> E2
    S2 --> E2
    CFG["config/broadcasting.php<br/>url, public_url, secrets, cookie_name"] --> E3
```

## Fan-out común

`UserNotification` implementa `ShouldBroadcastNow` (sin cola) y
`broadcastConnections()` devuelve `config('broadcasting.fanout')`. Laravel arma
el payload una sola vez, por eso `sentAt` es idéntico en los tres transportes y
las latencias se pueden comparar.

```mermaid
flowchart LR
    CMD["php artisan demo:notify 1 'hola'"] --> EV["UserNotification::dispatch()<br/>ShouldBroadcastNow"]
    EV --> BM["BroadcastManager<br/>broadcastOn: private-user.1<br/>broadcastAs: notification<br/>broadcastWith: userId, message, sentAt"]
    BM -->|"broadcastConnections()<br/>BROADCAST_FANOUT"| D1["SseBroadcaster::broadcast()"]
    BM --> D2["ApiGatewayBroadcaster::broadcast()"]
    BM --> D3["MercureBroadcaster::broadcast()<br/>(nativo de Laravel)"]

    D1 -->|"XADD MAXLEN ~100"| R[("Redis<br/>sse:stream:private-user.1")]
    D2 -->|"PutItem"| DY[("DynamoDB<br/>ws_events")]
    D2 -->|"PostToConnection x N"| GW["API Gateway"]
    D3 -->|"POST privado"| HUB["Hub Mercure"]

    R -->|"XREAD BLOCK"| C1["SseController<br/>(hilo PHP)"]
    C1 -->|"text/event-stream"| B1["Angular SseService"]
    GW -->|"WebSocket"| B2["Angular WsService"]
    HUB -->|"text/event-stream"| B3["Angular MercureService<br/>(Laravel Echo)"]
```
