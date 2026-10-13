# Driver `mercure`: hub Mercure dentro de FrankenPHP

El navegador sigue recibiendo SSE, pero la conexión la sostiene el **hub
Mercure** (Go, una goroutine por suscriptor) que corre en el mismo FrankenPHP.
Laravel solo hace dos cosas: firma el JWT de suscriptor después de pasar por
`routes/channels.php`, y publica un POST privado al hub por evento.

[← Volver al índice](README.md)

## Diagrama de flujo

```mermaid
flowchart TD
    A(["Angular: MercureService.connect(token, channel)"]) --> T["POST /api/mercure/token { channel }<br/>Authorization: Bearer JWT"]
    T --> TJ{"EnsureJwt: ¿JWT válido?"}
    TJ -- "No (401)" --> FATAL
    TJ -- Sí --> TC{"¿channel presente?"}
    TC -- "No (400)" --> FATAL
    TC -- Sí --> CA{"MercureBroadcaster::canAccess()<br/>callbacks de routes/channels.php"}
    CA -- "No (403)" --> FATAL
    CA -- Sí --> ST["subscriberToken([channel])<br/>JWT HS256 { mercure.subscribe: [topic] }, exp = TTL"]
    ST --> RESP["{ token, expires_in, hub_url, topic, server_time }<br/>el cliente estima el desfase de reloj"]
    TC -. "error de red" .-> RENEW

    RESP --> SUB["fetchEventSource GET /.well-known/mercure?topic=private-user.1<br/>Authorization: Bearer JWT suscriptor<br/>Last-Event-ID desde localStorage"]
    SUB --> CAD{"Caddy route:<br/>¿/.well-known/mercure?"}
    CAD -- "Sí: hub Go, sin pasar por PHP" --> HV{"Hub: ¿JWT de suscriptor válido?"}
    CAD -- "No" --> PHP["php_server (Laravel)"]
    HV -- "No / vencido (401)" --> TR["TokenRejected"] --> RENEW
    HV -- Sí --> LE{"¿Last-Event-ID?"}
    LE -- Sí --> HIST[("Repone desde el historial Bolt<br/>(últimos 1000 updates)")] --> LIVE
    LE -- No --> LIVE["Stream abierto: estado 'open'"]

    subgraph PUB ["Publicación"]
        P1(["demo:notify"]) --> P2["MercureBroadcaster::broadcast()"]
        P2 --> P3["Hub::publish(Update)<br/>topics = canal, private = true, type = notification<br/>JWT publicador { publish: ['*'] }, 60 s"]
        P3 --> P4["POST /.well-known/mercure (localhost)"]
        P4 --> P5[("Hub asigna id urn:uuid<br/>y lo guarda en Bolt")]
        P5 --> P6{"¿el suscriptor tiene el topic<br/>en mercure.subscribe?"}
        P6 -- No --> P7["No se le entrega (sin error)"]
        P6 -- Sí --> P8["Cliente recibe id / event / data<br/>saveLastId(), replayed si sentAt < apertura"]
    end
    LIVE -.-> P8

    LIVE -- "corte de red / el hub cierra" --> RETRY["fetch-event-source reintenta<br/>con Last-Event-ID"] --> SUB
    RENEW["Cliente: 'retrying'<br/>pide token nuevo tras 1 s"] --> T
    FATAL["Cliente: FatalError<br/>estado 'error', no reintenta"]
```

## Diagrama de secuencia

```mermaid
sequenceDiagram
    autonumber
    participant W as Angular<br/>MercureService
    participant C as Caddy / FrankenPHP<br/>:8000
    participant L as Laravel<br/>MercureTokenController
    participant B as MercureBroadcaster
    participant CH as routes/channels.php
    participant H as Hub Mercure<br/>(Go)
    participant DB as Bolt<br/>mercure.db
    participant CLI as demo:notify

    rect rgba(128,128,128,0.08)
    Note over W,CH: 1. Canje por un JWT de suscriptor
    W->>C: POST /api/mercure/token { channel }<br/>Authorization: Bearer JWT
    C->>L: php_server → EnsureJwt
    L->>B: canAccess(request, channel)
    B->>CH: user.id === userId
    alt canal ajeno
        L-->>W: 403 (FatalError)
    end
    L->>B: subscriberToken([channel])
    B-->>L: JWT { mercure: { subscribe: [topic] }, exp }
    L-->>W: { token, hub_url, topic, server_time }
    W->>W: clockOffset = server_time − punto medio del viaje
    end

    rect rgba(128,128,128,0.08)
    Note over W,DB: 2. Suscripción al hub (Laravel ya no participa)
    W->>C: GET /.well-known/mercure?topic=private-user.1<br/>Authorization: Bearer JWT suscriptor<br/>Last-Event-ID (si hay en localStorage)
    C->>H: route → directiva mercure
    H->>H: valida el JWT con subscriber_jwt (HS256)
    alt JWT inválido o vencido
        H-->>W: 401 → TokenRejected → volver a 1
    end
    opt Last-Event-ID presente
        H->>DB: updates posteriores a ese id
        DB-->>H: eventos perdidos
        H-->>W: eventos repuestos
    end
    H-->>W: 200 text/event-stream (una goroutine)
    end

    rect rgba(128,128,128,0.08)
    Note over CLI,W: 3. Publicación: un solo POST, el fan-out lo hace el hub
    CLI->>B: UserNotification::dispatch() → broadcast()
    B->>B: JWT publicador { mercure.publish: ['*'] }, 60 s
    B->>H: POST localhost:8000/.well-known/mercure<br/>topic=private-user.1, private=on, type=notification, data
    H->>DB: guarda el update con id urn:uuid:…
    H-->>B: 200 id
    par a cada suscriptor autorizado para el topic
        H-->>W: id: urn:uuid:…<br/>event: notification<br/>data: { userId, message, sentAt }
    end
    W->>W: saveLastId() en localStorage, dedupe por id
    Note over H: Un suscriptor sin el topic en su JWT<br/>simplemente no recibe el update
    end

    rect rgba(128,128,128,0.08)
    Note over W,DB: 4. Reconexión
    alt corte de red
        H--xW: stream perdido
        W->>C: GET /.well-known/mercure?topic=…<br/>Last-Event-ID (lo maneja la librería)
        C->>H: route
        H->>DB: repone desde el id
        H-->>W: eventos perdidos y luego en vivo
    else JWT de suscriptor vencido (MERCURE_SUBSCRIBER_TTL)
        H-->>W: 401
        W->>C: POST /api/mercure/token (token nuevo)
        C->>L: canAccess() otra vez
        L-->>W: { token }
        W->>C: GET /.well-known/mercure con Last-Event-ID de localStorage
    end
    end
```

## Piezas

| Qué | Dónde |
|---|---|
| Driver | [MercureBroadcaster.php](../api/app/Broadcasting/MercureBroadcaster.php) |
| Canje del JWT de suscriptor | [MercureTokenController.php](../api/app/Http/Controllers/MercureTokenController.php) |
| Hub y JWT publicador | [BroadcastServiceProvider.php](../api/app/Providers/BroadcastServiceProvider.php) |
| Autorización compartida | [AuthorizesChannelAccess.php](../api/app/Broadcasting/AuthorizesChannelAccess.php), [channels.php](../api/routes/channels.php) |
| Hub (directiva `mercure`, Bolt, claves) | [Caddyfile](../api/docker/Caddyfile) |
| Configuración | [broadcasting.php](../api/config/broadcasting.php) |
| Cliente | [mercure.service.ts](../web/src/app/mercure.service.ts) |
