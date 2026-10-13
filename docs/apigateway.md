# Driver `apigateway`: API Gateway WebSocket + DynamoDB

Los WebSockets los sostiene **API Gateway** (en local, el emulador
`gateway-local`). Laravel solo atiende POST cortos: API Gateway le reenvía
`$connect`, `subscribe` y `$disconnect`, y Laravel le escribe a cada cliente
con `PostToConnection`. El estado (conexiones, suscripciones e historial)
vive en DynamoDB.

[← Volver al índice](README.md)

## Diagrama de flujo

```mermaid
flowchart TD
    A(["Angular: WsService.connect(token, channel)"]) --> T["POST /api/ws/ticket<br/>Authorization: Bearer JWT"]
    T --> TJ{"EnsureJwt: ¿JWT válido?"}
    TJ -- "No (401)" --> FATAL
    TJ -- "error de red" --> BACKOFF
    TJ -- Sí --> TK["JwtService::issueWsTicket()<br/>ticket aud=ws, 60 s"]
    TK --> WS["new WebSocket(WS_URL?ticket=…)"]

    WS --> GC["API Gateway: $connect<br/>POST /api/ws/gateway/connect?ticket=…<br/>X-Connection-Id + X-Gateway-Secret"]
    GC --> GS{"EnsureGatewaySecret:<br/>¿secreto correcto?"}
    GS -- "No (403)" --> REJ
    GS -- Sí --> VT{"JwtService::verify(ticket, aud=ws)<br/>¿y el usuario existe?"}
    VT -- "No (401)" --> REJ["Handshake rechazado<br/>el navegador ve cierre 1006"] --> BACKOFF
    VT -- Sí --> OC[("PutItem ws_connections<br/>connectionId → userId, ttl")]
    OC --> OPEN["Socket abierto"]

    OPEN --> SUB["send { action: subscribe, channel, lastId }<br/>lastId desde localStorage"]
    OPEN --> PING["ping cada 5 min<br/>(mock integration, evita el idle de 10 min)"]
    SUB --> GSUB["API Gateway → POST /api/ws/gateway/subscribe"]
    GSUB --> FC{"findConnection()<br/>¿existe?"}
    FC -- No --> X410["410 a API Gateway"]
    FC -- Sí --> HC{"¿channel no vacío?"}
    HC -- No --> E400["PostToConnection { type: error, 400 }"] --> FATAL
    HC -- Sí --> CA{"canAccess()<br/>callbacks de routes/channels.php"}
    CA -- No --> E403["PostToConnection { type: error, 403 }"] --> FATAL
    CA -- Sí --> S1[("subscribe():<br/>PutItem ws_subscriptions<br/>UpdateItem ws_connections ADD channels")]
    S1 --> LI{"¿trae lastId?"}
    LI -- Sí --> RP[("eventsAfter(lastId − 5 s)<br/>Query ws_events")] --> RPS["PostToConnection por cada evento repuesto"] --> ACK
    LI -- No --> ACK["PostToConnection<br/>{ type: subscribed, cursor: ULID }"]
    ACK --> LIVE["Cliente 'open'<br/>si no había lastId guarda el cursor"]

    subgraph PUB ["Publicación"]
        P1(["demo:notify"]) --> P2["ApiGatewayBroadcaster::broadcast()"]
        P2 --> P3[("appendEvent(): PutItem ws_events<br/>id = ULID")]
        P3 --> P4[("subscribersOf(): Query ws_subscriptions")]
        P4 --> P5["postToConnectionAsync x N, en paralelo"]
        P5 --> P6{"¿410 Gone?"}
        P6 -- Sí --> P7[("forget(connectionId)")]
        P6 -- No --> P8["Cliente recibe { type: event, id, data }<br/>dedupe por id, saveLastId()"]
    end
    LIVE -.-> P8

    CLOSE(["Socket cerrado: red, idle 10 min, vida 2 h"]) --> DISC["API Gateway: $disconnect (best-effort)<br/>POST /api/ws/gateway/disconnect"] --> FG[("forget(): borra suscripciones y conexión")]
    CLOSE --> BACKOFF["Cliente: backoff exponencial<br/>min(1 s · 2^n, 30 s) + jitter"] --> T
    FATAL["Cliente: FatalError<br/>estado 'error', no reintenta"]
```

## Diagrama de secuencia

```mermaid
sequenceDiagram
    autonumber
    participant W as Angular<br/>WsService
    participant L as Laravel<br/>(peticiones cortas)
    participant G as API Gateway<br/>(gateway-local)
    participant WC as WsGatewayController
    participant B as ApiGatewayBroadcaster
    participant S as ConnectionStore
    participant D as DynamoDB
    participant CLI as demo:notify

    rect rgba(128,128,128,0.08)
    Note over W,L: 1. Canje del JWT de sesión por un ticket
    W->>L: POST /api/ws/ticket<br/>Authorization: Bearer JWT
    L->>L: EnsureJwt → JwtService::issueWsTicket()
    L-->>W: { ticket } (aud=ws, 60 s)
    end

    rect rgba(128,128,128,0.08)
    Note over W,D: 2. Handshake ($connect)
    W->>G: WebSocket ws://localhost:3001?ticket=…
    G->>WC: POST /api/ws/gateway/connect?ticket=…<br/>X-Connection-Id, X-Gateway-Secret
    WC->>WC: EnsureGatewaySecret, verify(ticket, aud=ws)
    alt ticket inválido
        WC-->>G: 401
        G--xW: handshake rechazado (1006) → backoff
    end
    WC->>S: openConnection(connectionId, userId)
    S->>D: PutItem ws_connections
    WC-->>G: 200 { connected: true }
    G-->>W: socket abierto
    end

    rect rgba(128,128,128,0.08)
    Note over W,D: 3. Suscripción y reposición
    W->>G: { action: "subscribe", channel, lastId }
    G->>WC: POST /api/ws/gateway/subscribe (mensaje crudo)
    WC->>S: findConnection(connectionId)
    S->>D: GetItem ws_connections (ConsistentRead)
    WC->>B: canAccess(request, channel)
    alt canal ajeno
        B->>G: PostToConnection { type: error, status: 403 }
        G-->>W: error → FatalError
    end
    WC->>S: subscribe(connectionId, channel)
    S->>D: PutItem ws_subscriptions
    S->>D: UpdateItem ws_connections ADD channels
    opt lastId presente
        WC->>S: eventsAfter(channel, lastId)
        S->>D: Query ws_events eventId > lastId − 5 s
        D-->>S: eventos perdidos
        loop cada evento perdido
            B->>G: POST @connections/{id} { type: event, … }
            G-->>W: evento (marcado «repuesto»)
        end
    end
    B->>G: POST @connections/{id}<br/>{ type: subscribed, replayed, cursor }
    G-->>W: ack → estado 'open'
    WC-->>G: 200
    end

    rect rgba(128,128,128,0.08)
    Note over CLI,W: 4. Publicación y fan-out
    CLI->>B: UserNotification::dispatch() → broadcast()
    B->>S: appendEvent(channel, event, data)
    S->>D: PutItem ws_events (eventId = ULID)
    B->>S: subscribersOf(channel)
    S->>D: Query ws_subscriptions
    D-->>B: [connectionId, …]
    par un PostToConnection por suscriptor
        B->>G: POST @connections/{id} { type: event, id, data }
        G-->>W: evento en vivo
    and conexión que ya no existe
        B->>G: POST @connections/{viejo}
        G-->>B: 410 GoneException
        B->>S: forget(viejo, [channel])
        S->>D: DeleteItem suscripción y conexión
    end
    W->>W: dedupe por id, saveLastId() en localStorage
    end

    rect rgba(128,128,128,0.08)
    Note over W,D: 5. Keep-alive, cierre y reconexión
    loop cada 5 min
        W->>G: { action: "ping" } (mock, no llega a Laravel)
    end
    G--xW: cierre (red, idle 10 min, vida 2 h)
    G->>WC: POST /api/ws/gateway/disconnect (best-effort)
    WC->>S: forget(connectionId)
    S->>D: DeleteItem
    W->>W: backoff exponencial + jitter
    W->>L: POST /api/ws/ticket (ticket nuevo)
    W->>G: WebSocket ?ticket=… → subscribe con lastId de localStorage
    Note over W,G: Se repite 2 y 3: la reposición cubre el hueco<br/>y sobrevive a un F5
    end
```

## Piezas

| Qué | Dónde |
|---|---|
| Driver | [ApiGatewayBroadcaster.php](../api/app/Broadcasting/ApiGatewayBroadcaster.php) |
| Estado en DynamoDB | [ConnectionStore.php](../api/app/Broadcasting/ApiGateway/ConnectionStore.php) |
| Rutas que llama API Gateway | [WsGatewayController.php](../api/app/Http/Controllers/WsGatewayController.php) |
| Ticket del handshake | [WsTicketController.php](../api/app/Http/Controllers/WsTicketController.php), [JwtService.php](../api/app/Services/JwtService.php) |
| Protección de `/api/ws/gateway/*` | [EnsureGatewaySecret.php](../api/app/Http/Middleware/EnsureGatewaySecret.php) |
| Autorización compartida | [AuthorizesChannelAccess.php](../api/app/Broadcasting/AuthorizesChannelAccess.php), [channels.php](../api/routes/channels.php) |
| Emulador local | [gateway-local/server.js](../gateway-local/server.js) |
| Infraestructura AWS | [infra/template.yaml](../infra/template.yaml) |
| Cliente | [ws.service.ts](../web/src/app/ws.service.ts) |
