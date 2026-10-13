# SSE como driver de broadcasting de Laravel

Maqueta mínima y **desechable** de un driver de transporte propio para la librería
de broadcasting de Laravel, que entrega eventos al navegador por
Server-Sent Events, con autenticación JWT, canales privados y Redis como bus.

```
Angular :4200 ──── POST /api/login ───────────► Laravel (FrankenPHP :8000)
    │                                                │
    │  fetchEventSource('/api/sse?channel=…')        │  middleware EnsureJwt
    │  Authorization: Bearer <jwt>                   │  SseBroadcaster::canAccess()
    └──◄── text/event-stream ─────────────────────── │  routes/channels.php
                                                     │
                            ┌────────── XREAD BLOCK ─┘
                            ▼
                         Redis  sse:stream:private-user.1
                            ▲
                            └── XADD ── SseBroadcaster::broadcast()
                                            ▲
                          php artisan demo:notify 1 "hola"
```

## Arrancar

```bash
docker compose up --build
```

Cinco contenedores: `api` (FrankenPHP + Laravel 13), `redis` y `web` (Angular dev
server) para SSE, más `dynamodb` (DynamoDB Local) y `gateway-local` (emulador de
API Gateway) para el [segundo driver](#segundo-driver-api-gateway-websocket--dynamodb).
La base es SQLite, un archivo en `api/database/database.sqlite`, sin contenedor
propio.

La primera vez tarda: el contenedor `web` instala sus `node_modules` dentro del
volumen y el `api` compila la extensión `redis`. Cuando veas
`API lista en http://localhost:8000` y el bundle de Angular listo, abre
**http://localhost:4200**.

Usuarios sembrados, ambos con contraseña `password`:

| email | id |
|---|---|
| `ana@example.com` | 1 |
| `beto@example.com` | 2 |

## Probar

Entra con `ana@example.com`. La home abre los dos transportes lado a lado (SSE y
API Gateway) y ambos quedan en «en vivo». Cada evento sale por los dos drivers a
la vez, así que aparece en las dos columnas con su latencia. Después, desde otra
terminal:

```bash
# 1. El evento aparece en la home al instante
docker compose exec api php artisan demo:notify 1 "hola Ana"

# 2. Aislamiento: esto NO debe aparecer en la sesión de Ana
docker compose exec api php artisan demo:notify 2 "hola Beto"

# 3. Los eventos quedan en el stream de Redis, con el id que usa SSE
docker compose exec redis redis-cli XRANGE sse:stream:private-user.1 - +
```

Para ver que un usuario no puede espiar el canal de otro, pide el canal ajeno con
tu propio token (devuelve **403**):

```bash
TOKEN=$(curl -s localhost:8000/api/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"ana@example.com","password":"password"}' | jq -r .token)

curl -i "localhost:8000/api/sse?channel=private-user.2" -H "Authorization: Bearer $TOKEN"
```

Y que el middleware realmente deja el user id en el request:

```bash
curl -s localhost:8000/api/me -H "Authorization: Bearer $TOKEN"
# {"user_id_from_request_attributes":1, ...}
```

### Reconexión

**Aguanta un corte**, en el navegador: con la home abierta, corta Redis y
vuelve a levantarlo. El badge pasa por «reconectando» y vuelve a «en vivo» solo;
después de eso los eventos siguen llegando.

```bash
docker compose stop redis && docker compose start redis
docker compose exec api php artisan demo:notify 1 "tras el corte"
```

**Reanuda sin perder eventos.** Esto se ve mejor con `curl`, porque permite
mandar el `Last-Event-ID` a mano. Abre un stream, quédate con el último `id:` que
recibiste, corta, despacha eventos mientras estás desconectado y vuelve pidiendo
desde ese id:

```bash
curl -N "localhost:8000/api/sse?channel=private-user.1" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Last-Event-ID: 1790727978609-0"
```

El servidor responde con los eventos que te perdiste antes de seguir en vivo.
`@microsoft/fetch-event-source` manda ese header por su cuenta al reconectar, así
que un corte de red no deja huecos.

Con una salvedad honesta: la librería guarda el último id **en memoria**, así que
un recargado completo de la página arranca de cero (`resumedFrom: "$"`, solo lo
nuevo). Si en producción quieres que sobreviva a un F5, hay que persistir el
último id en el cliente y pasarlo como `Last-Event-ID` en la primera conexión.

## Las piezas

| Qué | Dónde |
|---|---|
| El driver de broadcasting | `api/app/Broadcasting/SseBroadcaster.php` |
| Registro del driver en Laravel | `api/app/Providers/BroadcastServiceProvider.php` |
| Configuración del driver | `api/config/broadcasting.php` |
| Middleware que pone el user id en el request | `api/app/Http/Middleware/EnsureJwt.php` |
| Emisión y verificación del JWT | `api/app/Services/JwtService.php` |
| Autorización de canales privados | `api/routes/channels.php` |
| El endpoint que sostiene el stream | `api/app/Http/Controllers/SseController.php` |
| El evento de demo | `api/app/Events/UserNotification.php` |
| El disparador | `api/app/Console/Commands/DemoNotify.php` |
| Cliente SSE | `web/src/app/sse.service.ts` |

### Por qué Redis Streams y no Pub/Sub

Pub/Sub no tiene memoria: lo que se publica mientras el cliente está desconectado
se pierde. Con Streams, el id que devuelve `XADD` (`1727...-0`) sirve tal cual
como campo `id:` de SSE, así que cuando el navegador reconecta y envía
`Last-Event-ID`, el servidor reanuda exactamente desde ahí. Los streams se podan
con `MAXLEN ~ 100`: son historial para reconexiones, no un log.

### Por qué `@microsoft/fetch-event-source` y no `EventSource`

El `EventSource` nativo no permite mandar headers — el JWT tendría que viajar en
la query string, quedando en logs de acceso — y no expone el status de la
respuesta, así que no se puede distinguir un «403, no insistas» de un «se cayó la
red, reintentá». Esta librería usa `fetch()` y resuelve las dos cosas.

### Los tres detalles que hacen que un stream en PHP funcione

1. **Nada puede bufferizar.** `output_buffering=0` y `zlib.output_compression=Off`
   en `api/docker/php.ini`, y el Caddyfile a propósito **no** usa `encode`:
   comprimir un `text/event-stream` es la causa más común de «el navegador no
   recibe nada».
2. **Heartbeats.** El `XREAD` bloquea 15 s como máximo; si no hubo eventos se
   escribe un comentario `:heartbeat`. Eso mantiene viva la conexión y, al
   escribir, es como nos enteramos de que el cliente se fue
   (`connection_aborted()`).
3. **`read_timeout` en Redis.** La conexión `sse` de `config/database.php` usa
   `read_timeout = 0`. Con el valor por defecto, phpredis lanza una excepción en
   cuanto el `XREAD` bloquea más de lo permitido.

## Lo que NO transfiere a producción

**El endpoint SSE en PHP es la parte desechable de esta maqueta.**

En PHP una petición ocupa un proceso o hilo completo durante toda su vida, y una
conexión SSE no termina nunca. `api/docker/Caddyfile` fija `num_threads 64`: ese
número es literalmente el techo de streams simultáneos del contenedor. El costo
por conexión ronda los 30-60 MB porque cada hilo carga su intérprete, así que
unos pocos miles de usuarios concurrentes se vuelven inviables — no por lentitud,
sino por el modelo de ejecución.

Todo lo demás de esta maqueta sí transfiere tal cual: el driver de broadcasting,
el JWT, `routes/channels.php`, el layout de claves en Redis y el cliente Angular.

El camino a producción es mover **solo el borde** — lo que sostiene los sockets —
a un runtime con I/O asíncrona (Node, ~10-20k conexiones por proceso; Go, 100k+),
o a un hub ya hecho como Mercure. Laravel sigue publicando a Redis con este mismo
driver y vuelve a ser un servicio de peticiones cortas, fácil de escalar. Ese
borde debería además:

- **Multiplexar Redis.** Mantener una sola conexión en modo `SUBSCRIBE` por
  proceso y repartir a miles de clientes, en lugar de un `XREAD` por conexión.
  Es decir: `PUBLISH` para el fan-out en vivo y los Streams solo para reponer lo
  perdido en una reconexión.
- **Consultar la autorización a Laravel**, no reimplementarla: verificar el JWT
  para saber quién es, y llamar a `POST /api/broadcasting/auth` (ya expuesto aquí)
  al suscribirse, cacheando el resultado unos minutos.

Y una advertencia concreta: **AWS Lambda no sirve para sostener estas
conexiones.** Function URLs con `RESPONSE_STREAM` técnicamente hacen SSE, pero
Lambda factura GB-segundo por toda la duración de la invocación y una conexión SSE
está ociosa el 99.9% del tiempo; el tope duro de 15 minutos fuerza a todos los
clientes a reconectar a la vez; y cada ejecución aislada es una conexión más a
Redis, lo que destruye el multiplexado. Para esto, un contenedor en Fargate es
órdenes de magnitud más barato.

## Segundo driver: API Gateway WebSocket + DynamoDB

Corre **en paralelo** al de SSE, sin tocarlo. Es el camino a producción descrito
arriba, pero con un borde administrado: **API Gateway sostiene los sockets** y
Laravel vuelve a atender solo peticiones cortas. El estado vive en DynamoDB, sin
Redis.

```
Angular ─POST /api/ws/ticket (Bearer JWT)──────────► Laravel ── { ticket, 60 s }
   │
   └─ ws://localhost:3001?ticket=… ─► API Gateway WebSocket (gateway-local en docker)
                                        │ $connect · subscribe · ping · $disconnect
                                        ▼ POST + X-Connection-Id + X-Gateway-Secret
                                      Laravel /api/ws/gateway/*  ───►  DynamoDB
                                                                  ws_connections
demo:notify ─► UserNotification                                   ws_subscriptions
                ├─► driver sse        → XADD Redis                ws_events
                └─► driver apigateway → PutItem ws_events
                                        + PostToConnection a cada suscriptor
```

`UserNotification::broadcastConnections()` lo manda por los dos drivers
(`BROADCAST_FANOUT=sse,apigateway`). Laravel arma el payload una sola vez, así que
`sentAt` es idéntico en ambos y las latencias se comparan en igualdad.

### Cómo funciona

1. **Handshake con ticket.** El `WebSocket` del navegador no puede mandar
   `Authorization`. En lugar de poner el JWT de sesión en la URL, el front lo canjea
   por un ticket (`aud: ws`, 60 s) y abre `?ticket=`. Un ticket no sirve como Bearer
   y un JWT de sesión no sirve como ticket.
2. **`$connect`.** API Gateway llama a `POST /api/ws/gateway/connect`. Laravel valida
   el ticket y guarda `connectionId → userId`. Un no-2xx rechaza el socket.
3. **`subscribe`.** El cliente manda
   `{"action":"subscribe","channel":"private-user.1","lastId":"01J…"}`. Laravel
   autoriza con los **mismos callbacks de `routes/channels.php`**, primero guarda la
   suscripción y después repone lo posterior a `lastId`. Todo le llega al cliente
   por el socket, vía `PostToConnection`.
4. **Publicar.** El driver guarda el evento con un ULID como id (hace el papel del id
   de Redis Streams) y hace un `PostToConnection` en paralelo a cada suscriptor. Un
   `410 Gone` limpia esa conexión, porque `$disconnect` es best-effort.

### Reconexión sin perder eventos

Aquí no existe `Last-Event-ID`, así que se implementa a mano:

- El cliente guarda el último id por canal **en `localStorage`**, de modo que, a
  diferencia de SSE, sobrevive a un F5.
- Al reconectar pide un ticket nuevo y se resuscribe con ese `lastId`.
- La reposición consulta desde `lastId − 5 s`, para tolerar desfase de reloj entre
  instancias, y el cliente descarta los ids repetidos.
- Sin `lastId` previo, el ack de `subscribe` trae un `cursor`. Así, un corte antes
  del primer evento tampoco deja huecos.

Para verlo, con la home abierta:

```bash
docker compose stop gateway-local
docker compose exec api php artisan demo:notify 1 "durante el corte 1"
docker compose exec api php artisan demo:notify 1 "durante el corte 2"
docker compose start gateway-local
```

La columna API Gateway reconecta y muestra los dos eventos marcados como
«repuesto». SSE, que no dependía de ese contenedor, ya los había recibido en vivo.

Para ver los cortes de API Gateway (10 min sin tráfico, 2 h de vida) sin esperar,
se pueden acortar `IDLE_TIMEOUT_MS` / `MAX_LIFETIME_MS` en el servicio
`gateway-local` de `docker-compose.yml`.

Para inspeccionar el estado:

```bash
docker compose exec api php artisan tinker --execute \
  "dump(app(App\Broadcasting\ApiGateway\ConnectionStore::class)->subscribersOf('private-user.1'))"
```

### Desplegar en AWS

`infra/template.yaml` (SAM) crea la WebSocket API con sus rutas, las tres tablas con
TTL y la política IAM mínima para Laravel. Laravel tiene que ser alcanzable desde
internet; para la demo alcanza con un túnel:

```bash
cloudflared tunnel --url http://localhost:8000     # anota la URL https://….trycloudflare.com

cd infra
sam deploy --guided --capabilities CAPABILITY_IAM \
  --parameter-overrides BackendUrl=https://….trycloudflare.com GatewaySecret=<secreto>
```

Con los outputs:

- `.env` de Laravel: `WS_MANAGEMENT_ENDPOINT=<ManagementEndpoint>`,
  `DYNAMODB_ENDPOINT=` (vacío), el mismo `WS_GATEWAY_SECRET` y credenciales de un
  usuario o rol con `LaravelPolicyArn` adjunta. Quita también `DYNAMODB_ENDPOINT` y
  `WS_MANAGEMENT_ENDPOINT` del bloque `environment` del servicio `api` en
  `docker-compose.yml`, que tiene prioridad sobre el `.env`.
- `web/src/app/api.config.ts`: `WS_URL = <WebSocketUrl>`.

> Pendiente de verificar en un despliegue real: que el mapeo
> `context.connectionId → X-Connection-Id` funcione con integraciones `HTTP_PROXY`.
> El emulador local asume que sí.

### Qué cambia respecto de SSE

| | SSE (este repo) | API Gateway WebSocket |
|---|---|---|
| Quién sostiene la conexión | un hilo PHP por cliente (techo: `num_threads`) | API Gateway; Laravel solo recibe POST cortos |
| Auth del handshake | `Authorization: Bearer` | ticket de 60 s en la query |
| Reanudar | `Last-Event-ID` automático, solo en memoria | `lastId` a mano, en `localStorage` (sobrevive a F5) |
| Estado | Redis Streams | DynamoDB (3 tablas con TTL) |
| Límites | los que pongas | 2 h por conexión, 10 min idle, 128 KB por mensaje |
| Fan-out | un `XREAD` por conexión | un `PostToConnection` por suscriptor |
| Costo | infraestructura propia | ~US$0.25/millón de minutos-conexión + ~US$1/millón de mensajes + DynamoDB por request |

La latencia que muestra la home es reloj del navegador menos reloj del servidor.
Con Docker Desktop la VM puede ir unos ms desfasada (incluso da negativo), pero el
desfase es el mismo para las dos columnas: sirve para compararlas, no como valor
absoluto.

### Las piezas

| Qué | Dónde |
|---|---|
| El driver | `api/app/Broadcasting/ApiGatewayBroadcaster.php` |
| Estado en DynamoDB | `api/app/Broadcasting/ApiGateway/ConnectionStore.php` |
| Rutas que llama API Gateway | `api/app/Http/Controllers/WsGatewayController.php` |
| Ticket del handshake | `api/app/Http/Controllers/WsTicketController.php`, `JwtService::issueWsTicket()` |
| Protección de `/api/ws/gateway/*` | `api/app/Http/Middleware/EnsureGatewaySecret.php` |
| Tablas en DynamoDB Local | `php artisan ws:create-tables` (lo corre el entrypoint) |
| Emulador de API Gateway | `gateway-local/server.js` |
| Cliente WebSocket | `web/src/app/ws.service.ts` |
| Infraestructura AWS | `infra/template.yaml` |
