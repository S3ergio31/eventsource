# Driver `mercure`: driver nativo de Laravel + Echo, hub dentro de FrankenPHP

El navegador sigue recibiendo SSE, pero la conexión la sostiene el **hub
Mercure** (Go, una goroutine por suscriptor) que corre en el mismo FrankenPHP.
No hay código propio de transporte: el broadcaster es
`Illuminate\Broadcasting\Broadcasters\MercureBroadcaster` y el cliente es el
conector `mercure` de Laravel Echo. Laravel solo hace dos cosas: deja el JWT de
suscriptor en una cookie después de pasar por `routes/channels.php`, y publica
un POST privado al hub por canal y por evento.

[← Volver al índice](README.md)

## Diagrama de flujo

```mermaid
flowchart TD
    A(["Angular: MercureService.connect(token, channel)<br/>new Echo({ broadcaster: 'mercure' })<br/>echo.private('user.1')"]) --> T["Echo: POST /api/broadcasting/mercure/auth<br/>{ channel_names: ['private-user.1'] }<br/>Authorization: Bearer JWT, credentials: include"]
    T --> TJ{"EnsureJwt: ¿JWT válido?"}
    TJ -- "No (401)" --> EVICT
    TJ -- Sí --> CA{"MercureBroadcaster::auth()<br/>callbacks de routes/channels.php"}
    CA -- "No: denied: true" --> EVICT
    CA -- Sí --> ST["Cookie mercureAuthorization (HttpOnly, SameSite=Strict,<br/>Path=/.well-known/mercure)<br/>JWT { authorization_details: subscribe [topic] }"]
    ST --> RESP["{ channel_names, expires_in, topic_prefix }<br/>Echo agenda la re-autenticación al 80 % de expires_in"]
    T -. "error de red / 5xx" .-> BACKOFF

    RESP --> SUB["new EventSource(/.well-known/mercure?match=https://laravel.alt/echo/channel/private-user.1,<br/>{ withCredentials: true })<br/>el navegador manda la cookie"]
    SUB --> CAD{"Caddy route:<br/>¿/.well-known/mercure?"}
    CAD -- "Sí: hub Go, sin pasar por PHP" --> HV{"Hub: ¿JWT de la cookie válido?<br/>(iss = MERCURE_ISSUER, clave del bloque issuer)"}
    CAD -- "No" --> PHP["php_server (Laravel)"]
    HV -- "No / vencido (401)" --> BACKOFF
    HV -- Sí --> LE{"¿last_event_id en la URL?<br/>(solo en reconexiones de la misma página)"}
    LE -- Sí --> HIST[("Repone desde el historial Bolt<br/>(últimos 1000 updates)")] --> LIVE
    LE -- No --> LIVE["Stream abierto: Echo 'connected'"]

    subgraph PUB ["Publicación"]
        P1(["demo:notify"]) --> P2["MercureBroadcaster::broadcast() (nativo)"]
        P2 --> P3["Hub::publish(Update)<br/>topic = https://laravel.alt/echo/channel/private-user.1, private = true<br/>data = { channels, event: 'notification', payload }"]
        P3 --> P4["POST MERCURE_URL (localhost:8000)<br/>JWT publicador, cacheado"]
        P4 --> P5[("Hub asigna id urn:uuid<br/>y lo guarda en Bolt")]
        P5 --> P6{"¿el suscriptor tiene el topic<br/>en su JWT?"}
        P6 -- No --> P7["No se le entrega (sin error)"]
        P6 -- Sí --> P8["Echo despacha .notification con el payload<br/>(el id SSE queda dentro de Echo)"]
    end
    LIVE -.-> P8

    LIVE -- "corte de red / el hub cierra" --> BACKOFF
    BACKOFF["Echo: 'reconnecting'<br/>backoff 1 s → 30 s, vuelve a autenticar"] --> T
    EVICT["Echo saca el canal y llama a .error()<br/>MercureService: estado 'error', sin reintentos"]
```

## Diagrama de secuencia

```mermaid
sequenceDiagram
    autonumber
    participant W as Angular<br/>MercureService (Echo)
    participant C as Caddy / FrankenPHP<br/>:8000
    participant L as Laravel<br/>/broadcasting/mercure/auth
    participant B as MercureBroadcaster<br/>(nativo)
    participant CH as routes/channels.php
    participant H as Hub Mercure<br/>(Go)
    participant DB as Bolt<br/>mercure.db
    participant CLI as demo:notify

    rect rgba(128,128,128,0.08)
    Note over W,CH: 1. Autenticación: la credencial queda en una cookie
    W->>C: POST /api/broadcasting/mercure/auth<br/>{ channel_names: [private-user.1] }<br/>Authorization: Bearer JWT, credentials: include
    C->>L: php_server → EnsureJwt
    L->>B: Broadcast::connection('mercure')->auth(request)
    B->>CH: user.id === userId
    alt canal ajeno
        B-->>W: 200 { channel_names: [{ name, denied: true }] }
        W->>W: Echo saca el canal → .error() → estado 'error'
    end
    B-->>W: 200 { channel_names, expires_in, topic_prefix }<br/>Set-Cookie: mercureAuthorization=JWT (HttpOnly)
    end

    rect rgba(128,128,128,0.08)
    Note over W,DB: 2. Suscripción al hub (Laravel ya no participa)
    W->>C: GET /.well-known/mercure?match=https://laravel.alt/echo/channel/private-user.1<br/>Cookie: mercureAuthorization (withCredentials)
    C->>H: route → directiva mercure
    H->>H: valida el JWT con subscriber_jwt (HS256)
    alt JWT inválido o vencido
        H-->>W: 401 → Echo 'reconnecting' → volver a 1
    end
    H-->>W: 200 text/event-stream (una goroutine)
    W->>W: Echo 'connected'
    end

    rect rgba(128,128,128,0.08)
    Note over CLI,W: 3. Publicación: un POST por canal, el fan-out lo hace el hub
    CLI->>B: UserNotification::dispatch() → broadcast()
    B->>H: POST MERCURE_URL<br/>topic=https://laravel.alt/echo/channel/private-user.1, private<br/>data={ channels, event, payload }
    H->>DB: guarda el update con id urn:uuid:…
    H-->>B: 200 id
    par a cada suscriptor autorizado para el topic
        H-->>W: id: urn:uuid:…<br/>data: { channels, event: notification, payload }
    end
    W->>W: Echo guarda el id en memoria y despacha .notification
    Note over H: Un suscriptor sin el topic en su JWT<br/>simplemente no recibe el update
    end

    rect rgba(128,128,128,0.08)
    Note over W,DB: 4. Renovación y reconexión
    alt al 80 % de expires_in
        W->>C: POST /api/broadcasting/mercure/auth (cookie nueva)
        C->>L: auth() otra vez, channels.php otra vez
        L-->>W: Set-Cookie nueva, el stream sigue abierto
    else corte de red
        H--xW: stream perdido
        W->>W: Echo 'reconnecting', backoff
        W->>C: POST auth + GET /.well-known/mercure?match=…&last_event_id=urn:uuid:…
        C->>H: route
        H->>DB: repone desde el id
        H-->>W: eventos perdidos y luego en vivo
    else F5
        W->>W: el id estaba solo en memoria: se pierde
        W->>C: POST auth + GET sin last_event_id (solo lo nuevo)
    end
    end
```

## Diferencias con el driver propio anterior

Hasta este cambio el driver era propio (`App\Broadcasting\MercureBroadcaster`,
`MercureTokenController` y `@microsoft/fetch-event-source`). Pasar al nativo:

| | Driver propio (antes) | Nativo + Echo (ahora) |
|---|---|---|
| Código propio | broadcaster, controller del token, cliente | solo configuración, una ruta y el adaptador a `Transport` |
| Credencial de suscriptor | JWT en el JSON, `Authorization: Bearer` | cookie HttpOnly |
| Requisitos de despliegue | ninguno (el hub puede estar en cualquier dominio) | CORS con credenciales, front y hub en el mismo sitio, cookie sin prefijo `__Secure-` en HTTP |
| Reposición tras F5 | sí (`Last-Event-ID` en `localStorage`) | no (Echo guarda el id en memoria) |
| Id del evento en el cliente | sí | no (queda dentro de Echo) |
| `replayed` | reloj del servidor estimado con `server_time` | reloj del navegador |
| Renovación | al recibir 401 del hub | proactiva, al 80 % de `expires_in` |
| Protocolo Mercure | legacy (`topic`, claim `mercure`) | 1.0 (`match`, `last_event_id`, `authorization_details`) |
| Hub | FrankenPHP 1.12 (Mercure 0.24), `publisher_jwt` / `subscriber_jwt` | FrankenPHP 1.13 (Mercure 1.0), bloque `issuer` + `MERCURE_ISSUER` |
| Extra que se gana | — | presence channels, whispers, canales cifrados, varios canales en un `EventSource` |

## Piezas

| Qué | Dónde |
|---|---|
| Driver (nativo) | `Illuminate\Broadcasting\Broadcasters\MercureBroadcaster` (en `api/vendor/laravel/framework`) |
| Registro con los canales heredados | [BroadcastServiceProvider.php](../api/app/Providers/BroadcastServiceProvider.php) |
| Endpoint de auth de Echo | [api.php](../api/routes/api.php) → `/broadcasting/mercure/auth` |
| Autorización | [channels.php](../api/routes/channels.php) |
| Hub (directiva `mercure`, Bolt, `issuer`, cookie) | [Caddyfile](../api/docker/Caddyfile), versión en [Dockerfile](../api/docker/Dockerfile) |
| Configuración | [broadcasting.php](../api/config/broadcasting.php), [cors.php](../api/config/cors.php) |
| Cliente (Echo) | [mercure.service.ts](../web/src/app/mercure.service.ts) |
