# Driver `sse`: Server-Sent Events + Redis Streams

Laravel escribe cada evento en un Redis Stream por canal (`XADD`). El navegador
abre un `GET /api/sse` que **no termina**: un hilo PHP queda bloqueado en
`XREAD BLOCK` y escribe cada evento como SSE. Reanudar es `Last-Event-ID`
nativo, porque el id de `XADD` se usa tal cual como `id:` de SSE.

[← Volver al índice](README.md)

## Diagrama de flujo

```mermaid
flowchart TD
    A(["Angular: SseService.connect(token, channel)"]) --> B["fetchEventSource GET /api/sse?channel=private-user.1<br/>Authorization: Bearer JWT<br/>Last-Event-ID solo al reconectar"]
    B --> C{"EnsureJwt:<br/>¿Bearer válido y usuario existe?"}
    C -- No --> X401["401"] --> FATAL
    C -- Sí --> D{"¿?channel presente?"}
    D -- No --> X400["400"] --> FATAL
    D -- Sí --> E{"SseBroadcaster::canAccess()<br/>callbacks de routes/channels.php"}
    E -- No --> X403["403"] --> FATAL
    E -- Sí --> F["lastId = Last-Event-ID o '$'"]
    F --> G["StreamedResponse text/event-stream<br/>sin buffers, X-Accel-Buffering: no"]
    G --> H["retry: N<br/>event: sse:connected"]
    H --> LOOP{"¿connection_aborted()?"}
    LOOP -- Sí --> FIN(["El hilo PHP se libera"])
    LOOP -- No --> LT{"¿max_lifetime vencido?"}
    LT -- Sí --> RC1["event: sse:reconnect<br/>max_lifetime_reached"] --> CLOSE
    LT -- No --> XR["readAfter(): XREAD BLOCK block_ms<br/>sse:stream:private-user.1 desde lastId"]
    XR -- "excepción Redis" --> RC2["event: sse:reconnect<br/>redis_unavailable"] --> CLOSE
    XR -- "sin eventos" --> HB[":heartbeat"] --> LOOP
    XR -- "eventos" --> SEND["id / event / data por evento + flush<br/>lastId = último id"] --> LOOP

    CLOSE["El servidor cierra el stream"] --> RETRY
    FATAL["Cliente: FatalError<br/>estado 'error', no reintenta"]
    RETRY["Cliente: onclose / onerror<br/>estado 'retrying'"] -->|"espera retry: y reenvía Last-Event-ID"| B

    subgraph PUB ["Publicación"]
        P1(["demo:notify"]) --> P2["UserNotification"] --> P3["SseBroadcaster::broadcast()"]
        P3 --> P4[("XADD sse:stream:private-user.1 * MAXLEN ~100<br/>event, data")]
    end
    P4 -. "despierta el XREAD" .-> XR
```

## Diagrama de secuencia

```mermaid
sequenceDiagram
    autonumber
    actor U as Usuario
    participant W as Angular<br/>SseService
    participant F as FrankenPHP<br/>(hilo PHP)
    participant J as EnsureJwt
    participant C as SseController
    participant B as SseBroadcaster
    participant CH as routes/channels.php
    participant R as Redis
    participant CLI as demo:notify

    rect rgba(128,128,128,0.08)
    Note over U,F: 1. Login
    U->>W: email + password
    W->>F: POST /api/login
    F-->>W: { token: JWT de sesión }
    end

    rect rgba(128,128,128,0.08)
    Note over W,CH: 2. Abrir el stream y autorizar el canal
    W->>F: GET /api/sse?channel=private-user.1<br/>Authorization: Bearer JWT
    F->>J: handle()
    J->>J: JwtService::verify() + User::find()
    alt JWT inválido
        J-->>W: 401 (FatalError, no reintenta)
    end
    J->>C: setUserResolver(user)
    C->>B: canAccess(request, channel)
    B->>CH: verifyUserCanAccessChannel()<br/>user.id === userId
    alt canal ajeno
        CH-->>B: false
        C-->>W: 403 (FatalError, no reintenta)
    end
    CH-->>B: true
    C-->>W: 200 text/event-stream
    C-->>W: retry: N
    C-->>W: event: sse:connected { resumedFrom: "$" }
    end

    rect rgba(128,128,128,0.08)
    Note over C,R: 3. Bucle del stream (un hilo PHP por cliente)
    loop mientras no connection_aborted()
        C->>B: readAfter(channel, lastId, block_ms)
        B->>R: XREAD BLOCK block_ms sse:stream:private-user.1 lastId
        alt sin eventos en block_ms
            R-->>B: nil
            C-->>W: :heartbeat (comentario, la librería no lo entrega)
        end
    end
    end

    rect rgba(128,128,128,0.08)
    Note over CLI,W: 4. Publicación y entrega
    CLI->>B: UserNotification::dispatch() → broadcast()
    B->>R: XADD sse:stream:private-user.1 * MAXLEN ~100 event data
    R-->>B: id 1727...-0
    R-->>B: el XREAD bloqueado devuelve el evento
    B-->>C: [{ id, event, data }]
    C-->>W: id: 1727...-0<br/>event: notification<br/>data: { userId, message, sentAt }
    W->>W: events.update(), latencia = now - sentAt
    end

    rect rgba(128,128,128,0.08)
    Note over W,R: 5. Corte y reanudación
    alt max_lifetime o Redis caído
        C-->>W: event: sse:reconnect { reason }
        C--xW: cierra el stream
    else red cortada
        F--xW: conexión perdida
    end
    W->>W: onerror → 'retrying', espera retry:
    W->>F: GET /api/sse?channel=…<br/>Last-Event-ID: 1727...-0
    F->>C: (mismo pipeline de auth)
    C->>B: readAfter(channel, "1727...-0")
    B->>R: XREAD desde 1727...-0
    R-->>B: eventos perdidos
    C-->>W: eventos perdidos y luego en vivo
    Note over W: El último id vive solo en memoria:<br/>un F5 arranca de nuevo en "$"
    end
```

## Piezas

| Qué | Dónde |
|---|---|
| Driver | [SseBroadcaster.php](../api/app/Broadcasting/SseBroadcaster.php) |
| Endpoint del stream | [SseController.php](../api/app/Http/Controllers/SseController.php) |
| Middleware JWT | [EnsureJwt.php](../api/app/Http/Middleware/EnsureJwt.php) |
| Autorización de canales | [channels.php](../api/routes/channels.php) |
| Configuración | [broadcasting.php](../api/config/broadcasting.php), [sse.php](../api/config/sse.php) |
| Servidor (hilos, sin `encode`) | [Caddyfile](../api/docker/Caddyfile) |
| Cliente | [sse.service.ts](../web/src/app/sse.service.ts) |
