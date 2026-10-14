# Evaluación de drivers de broadcasting para Laravel

Este repo construye y compara **tres drivers custom para la librería de
broadcasting de Laravel**, con el objetivo de decidir cuál adopta la empresa para
entregar eventos en tiempo real al navegador. Los tres comparten la misma base
(autenticación JWT, canales privados autorizados en `routes/channels.php`, el
mismo evento y el mismo cliente Angular) y difieren solo en **quién sostiene la
conexión con el navegador** y dónde vive el estado para reanudar.

| Driver | Quién sostiene la conexión | Protocolo al navegador | Estado / historial |
|---|---|---|---|
| [`sse`](#driver-sse-sse-desde-php--redis-streams) | un hilo PHP por cliente (FrankenPHP) | SSE | Redis Streams |
| [`apigateway`](#driver-apigateway-api-gateway-websocket--dynamodb) | AWS API Gateway WebSocket | WebSocket | DynamoDB |
| [`mercure`](#driver-mercure-hub-mercure-dentro-de-frankenphp) | hub Mercure (Go) dentro de FrankenPHP | SSE | Bolt (archivo local del hub) |

Los tres corren **a la vez**: un mismo `UserNotification` sale por todos los
drivers de `BROADCAST_FANOUT` (por defecto `sse,apigateway,mercure`). Laravel arma
el payload una sola vez, así que `sentAt` es idéntico en los tres y la home los
muestra lado a lado con su latencia. La comparación y la recomendación están en
[Evaluación y decisión](#evaluación-y-decisión); los diagramas de flujo y de
secuencia de cada driver, en [`docs/`](docs/README.md).

```
                         php artisan demo:notify 1 "hola"
                                       │
                                UserNotification
                                       │ broadcastConnections() = BROADCAST_FANOUT
              ┌────────────────────────┼─────────────────────────┐
              ▼                        ▼                         ▼
       driver sse              driver apigateway           driver mercure
   XADD Redis Stream     PutItem DynamoDB + N PostTo…     1 POST privado al hub
              │                        │                         │
   SseController (hilo PHP)     API Gateway WebSocket      hub Mercure (Go)
              │                        │                         │
              └──────────────► Angular :4200 (tres columnas) ◄───┘
```

## Arrancar

```bash
docker compose up --build
```

Cinco contenedores: `api` (FrankenPHP + Laravel 13, que además aloja el hub
Mercure), `redis` (driver `sse`), `dynamodb` (DynamoDB Local) y `gateway-local`
(emulador de API Gateway) para el driver `apigateway`, y `web` (Angular dev
server). La base es SQLite, un archivo en `api/database/database.sqlite`, sin
contenedor propio.

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

Entra con `ana@example.com`. La home abre los tres transportes lado a lado (SSE,
API Gateway y Mercure) y todos quedan en «en vivo». Después, desde otra terminal:

```bash
# 1. El evento aparece en las tres columnas al instante
docker compose exec api php artisan demo:notify 1 "hola Ana"

# 2. Aislamiento: esto NO debe aparecer en la sesión de Ana
docker compose exec api php artisan demo:notify 2 "hola Beto"
```

Para los comandos con `curl` de cada driver hace falta un JWT de sesión:

```bash
TOKEN=$(curl -s localhost:8000/api/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"ana@example.com","password":"password"}' | jq -r .token)

# El middleware deja el user id en el request
curl -s localhost:8000/api/me -H "Authorization: Bearer $TOKEN"
# {"user_id_from_request_attributes":1, ...}
```

Cada driver tiene además sus propias pruebas de autorización y de reconexión, en
su sección.

**Sobre la latencia.** La que muestra la home es reloj del navegador menos reloj
del servidor. Con Docker Desktop la VM puede ir desfasada: unos ms, o varios
segundos después de suspender el equipo o reiniciar Docker. Por eso incluso puede
dar negativo. El desfase es el mismo para todas las columnas: sirve para
compararlas, no como valor absoluto. Si molesta, reiniciar Docker Desktop
resincroniza el reloj.

## Piezas comunes

Lo que comparten los tres drivers, y que se lleva tal cual a producción sea cual
sea el elegido:

| Qué | Dónde |
|---|---|
| Registro de los tres drivers en Laravel | `api/app/Providers/BroadcastServiceProvider.php` |
| Configuración de los drivers y del fan-out | `api/config/broadcasting.php` |
| Middleware que pone el user id en el request | `api/app/Http/Middleware/EnsureJwt.php` |
| Emisión y verificación de JWT | `api/app/Services/JwtService.php` |
| Autorización de canales privados (única fuente de verdad) | `api/routes/channels.php` |
| Autorización compartida por `apigateway` y `mercure` | `api/app/Broadcasting/AuthorizesChannelAccess.php` |
| El evento de prueba | `api/app/Events/UserNotification.php` |
| El disparador | `api/app/Console/Commands/DemoNotify.php` |
| Interfaz común de los clientes y columna de la home | `web/src/app/transport.ts`, `web/src/app/transport-feed.ts` |

`routes/channels.php` se registra solo en el driver por defecto; los otros dos
copian esos callbacks al construirse, así que la autorización es la misma en los
tres.

## Driver `sse`: SSE desde PHP + Redis Streams

Laravel escribe cada evento en un Redis Stream por canal y un endpoint PHP sostiene
el stream hacia el navegador. Es la **línea base** de la evaluación: la opción con
menos piezas externas y la que expone los límites de sostener conexiones en PHP.

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

### Cómo funciona

- **Publicar.** `SseBroadcaster::broadcast()` hace un `XADD` por canal. Los streams
  se podan con `MAXLEN ~ 100`: son historial para reconexiones, no un log.
- **Entregar.** `GET /api/sse?channel=…` autoriza con `routes/channels.php` y queda
  bloqueado en `XREAD BLOCK`, escribiendo cada evento como SSE.
- **Por qué Redis Streams y no Pub/Sub.** Pub/Sub no tiene memoria: lo que se
  publica mientras el cliente está desconectado se pierde. Con Streams, el id que
  devuelve `XADD` (`1727...-0`) sirve tal cual como campo `id:` de SSE, así que
  cuando el navegador reconecta y envía `Last-Event-ID`, el servidor reanuda
  exactamente desde ahí.
- **Por qué `@microsoft/fetch-event-source` y no `EventSource`.** El `EventSource`
  nativo no permite mandar headers — el JWT tendría que viajar en la query string,
  quedando en logs de acceso — y no expone el status de la respuesta, así que no se
  puede distinguir un «403, no insistas» de un «se cayó la red, reintentá». Esta
  librería usa `fetch()` y resuelve las dos cosas.

Los tres detalles que hacen que un stream en PHP funcione:

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

### Reconexión

`@microsoft/fetch-event-source` manda `Last-Event-ID` por su cuenta al reconectar,
así que un corte de red no deja huecos. La salvedad: la librería guarda el último
id **en memoria**, así que un recargado completo de la página arranca de cero
(`resumedFrom: "$"`, solo lo nuevo). Para que sobreviva a un F5 habría que
persistir el último id en el cliente, como hacen los otros dos drivers.

### Probar

```bash
# Los eventos quedan en el stream de Redis, con el id que usa SSE
docker compose exec redis redis-cli XRANGE sse:stream:private-user.1 - +

# Pedir el canal de otro usuario con tu token devuelve 403
curl -i "localhost:8000/api/sse?channel=private-user.2" -H "Authorization: Bearer $TOKEN"
```

**Aguanta un corte**, en el navegador: con la home abierta, corta Redis y vuelve a
levantarlo. La columna SSE pasa por «reconectando» y vuelve a «en vivo» sola;
después de eso los eventos siguen llegando.

```bash
docker compose stop redis && docker compose start redis
docker compose exec api php artisan demo:notify 1 "tras el corte"
```

**Reanuda sin perder eventos.** Se ve mejor con `curl`, porque permite mandar el
`Last-Event-ID` a mano. Abre un stream, quédate con el último `id:` que recibiste,
corta, despacha eventos mientras estás desconectado y vuelve pidiendo desde ese id:

```bash
curl -N "localhost:8000/api/sse?channel=private-user.1" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Last-Event-ID: 1790727978609-0"
```

El servidor responde con los eventos que te perdiste antes de seguir en vivo.

### Límites

**Sostener la conexión en PHP no escala.** En PHP una petición ocupa un proceso o
hilo completo durante toda su vida, y una conexión SSE no termina nunca.
`api/docker/Caddyfile` fija `num_threads 64`: ese número es literalmente el techo
de streams simultáneos del contenedor. El costo por conexión ronda los 30-60 MB
porque cada hilo carga su intérprete, así que unos pocos miles de usuarios
concurrentes se vuelven inviables — no por lentitud, sino por el modelo de
ejecución.

Para llevar este driver a producción habría que mover **solo el borde** — lo que
sostiene los sockets — a un runtime con I/O asíncrona (Node, ~10-20k conexiones
por proceso; Go, 100k+). Laravel seguiría publicando a Redis con este mismo driver
y volvería a ser un servicio de peticiones cortas. Ese borde debería además:

- **Multiplexar Redis.** Mantener una sola conexión en modo `SUBSCRIBE` por proceso
  y repartir a miles de clientes, en lugar de un `XREAD` por conexión. Es decir:
  `PUBLISH` para el fan-out en vivo y los Streams solo para reponer lo perdido en
  una reconexión.
- **Consultar la autorización a Laravel**, no reimplementarla: verificar el JWT
  para saber quién es, y llamar a `POST /api/broadcasting/auth` (ya expuesto aquí)
  al suscribirse, cacheando el resultado unos minutos.

Ese borde es justamente lo que aportan ya hechos los otros dos candidatos: API
Gateway (administrado) y Mercure (hub en Go).

Y una advertencia concreta: **AWS Lambda no sirve para sostener estas
conexiones.** Function URLs con `RESPONSE_STREAM` técnicamente hacen SSE, pero
Lambda factura GB-segundo por toda la duración de la invocación y una conexión SSE
está ociosa el 99.9% del tiempo; el tope duro de 15 minutos fuerza a todos los
clientes a reconectar a la vez; y cada ejecución aislada es una conexión más a
Redis, lo que destruye el multiplexado. Para esto, un contenedor en Fargate es
órdenes de magnitud más barato.

### Las piezas

| Qué | Dónde |
|---|---|
| El driver | `api/app/Broadcasting/SseBroadcaster.php` |
| El endpoint que sostiene el stream | `api/app/Http/Controllers/SseController.php` |
| Configuración del endpoint | `api/config/sse.php` |
| Cliente | `web/src/app/sse.service.ts` |

## Driver `apigateway`: API Gateway WebSocket + DynamoDB

Un borde **administrado**: API Gateway sostiene los sockets y Laravel atiende solo
peticiones cortas. El estado vive en DynamoDB, sin Redis.

```
Angular ─POST /api/ws/ticket (Bearer JWT)──────────► Laravel ── { ticket, 60 s }
   │
   └─ ws://localhost:3001?ticket=… ─► API Gateway WebSocket (gateway-local en docker)
                                        │ $connect · subscribe · ping · $disconnect
                                        ▼ POST + X-Connection-Id + X-Gateway-Secret
                                      Laravel /api/ws/gateway/*  ───►  DynamoDB
                                                                  ws_connections
demo:notify ─► UserNotification                                   ws_subscriptions
                └─► driver apigateway → PutItem ws_events         ws_events
                                        + PostToConnection a cada suscriptor
```

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
  diferencia del driver `sse`, sobrevive a un F5.
- Al reconectar pide un ticket nuevo y se resuscribe con ese `lastId`.
- La reposición consulta desde `lastId − 5 s`, para tolerar desfase de reloj entre
  instancias, y el cliente descarta los ids repetidos.
- Sin `lastId` previo, el ack de `subscribe` trae un `cursor`. Así, un corte antes
  del primer evento tampoco deja huecos.

### Probar

Con la home abierta:

```bash
docker compose stop gateway-local
docker compose exec api php artisan demo:notify 1 "durante el corte 1"
docker compose exec api php artisan demo:notify 1 "durante el corte 2"
docker compose start gateway-local
```

La columna API Gateway reconecta y muestra los dos eventos marcados como
«repuesto». Las columnas SSE y Mercure, que no dependían de ese contenedor, ya los
habían recibido en vivo.

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
internet; para probar alcanza con un túnel:

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

### Límites

- **Límites duros de AWS:** 2 h por conexión, 10 min sin tráfico, 128 KB por
  mensaje. El cliente manda `ping` y reconecta solo.
- **Fan-out desde Laravel:** un `PostToConnection` por suscriptor. Con canales de
  muchos suscriptores, publicar cuesta N llamadas HTTP desde PHP.
- **Lock-in:** el protocolo de `$connect`/`subscribe`/`PostToConnection` es propio de
  AWS; el emulador `gateway-local` solo sirve para desarrollo.

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
| Cliente | `web/src/app/ws.service.ts` |
| Infraestructura AWS | `infra/template.yaml` |

## Driver `mercure`: hub Mercure dentro de FrankenPHP

Un borde **ya hecho y autoalojado**. FrankenPHP trae **Mercure integrado**, así que
el hub corre en el mismo binario y contenedor que Laravel, sin servicios nuevos. El
navegador recibe SSE, como en el driver `sse`, pero la conexión la sostiene el hub,
**escrito en Go**: cada suscriptor es una goroutine, no uno de los `num_threads`
de PHP.

```
Angular ─POST /api/mercure/token (Bearer JWT)─► Laravel: routes/channels.php
   │                                             └► JWT de suscriptor {mercure.subscribe: [canal]}
   │
   └─ fetchEventSource(/.well-known/mercure?topic=private-user.1)
      Authorization: Bearer <JWT de suscriptor>
                  │
                  ▼
   FrankenPHP :8000 ─┬─ /.well-known/mercure → hub Mercure (Go) ── historial Bolt
                     └─ todo lo demás        → Laravel (PHP)
                                                  │
   demo:notify ─► driver mercure ─ 1 POST privado al hub, sin importar cuántos suscriptores
```

### Cómo funciona

- **Hub.** Es la directiva `mercure` de `api/docker/Caddyfile`, dentro de un `route`
  para que atienda `/.well-known/mercure` antes de llegar a PHP. Las claves JWT
  vienen del `environment` de `docker-compose.yml`: Caddy las lee del entorno del
  proceso, no del `.env`.
- **Publicar.** `MercureBroadcaster` usa `symfony/mercure` (sin el framework
  Symfony). Cada canal de Laravel es un topic con el mismo nombre, y las
  publicaciones son **privadas**.
- **Autorizar.** El hub no le pregunta nada a Laravel. Solo entrega un update
  privado a quien presente un JWT cuyo claim `mercure.subscribe` incluya el topic.
  Ese JWT lo firma `POST /api/mercure/token` **después** de pasar por
  `routes/channels.php`. Pedir el topic de otro usuario con tu token no da error:
  el hub simplemente no te entrega sus eventos.
- **Reanudar.** Es `Last-Event-ID` nativo: el hub asigna los ids (`urn:uuid:…`) y
  repone desde su historial (transporte Bolt, últimos 1000 updates). El cliente,
  además, guarda el último id en `localStorage`, así que también sobrevive a un F5.
- **Token vencido.** Si el hub responde 401 (el JWT duró
  `MERCURE_SUBSCRIBER_TTL`), el cliente pide otro token y sigue desde el último id.

### Probar

```bash
# Sin JWT, el hub rechaza (401)
curl -i "localhost:8000/.well-known/mercure?topic=private-user.1"

# Con token de suscriptor
MERCURE=$(curl -s localhost:8000/api/mercure/token -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"channel":"private-user.1"}' | jq -r .token)
curl -N "localhost:8000/.well-known/mercure?topic=private-user.1" -H "Authorization: Bearer $MERCURE"
```

Lo que se verificó al montarlo:

- **Capacidad.** Con **150 suscripciones abiertas al hub**, más del doble de
  `num_threads 64`, Laravel siguió respondiendo con normalidad y un solo
  `demo:notify` llegó a los 150 suscriptores. Con el driver `sse`, la conexión 65
  ya no tendría hilo.
- **Reposición.** Una reconexión con `Last-Event-ID` recupera exactamente los
  eventos perdidos.

### Límites

- **Un solo nodo.** Con Bolt, el historial es un archivo local: dos réplicas de
  FrankenPHP no comparten suscriptores ni historial. Escalar horizontalmente
  requiere la versión High Availability de Mercure (de pago) o un hub aparte con
  transporte compartido. El driver no cambia: solo cambia `MERCURE_URL`.
- **Revocación.** Como el hub no reconsulta, un acceso quitado sigue valiendo hasta
  que vence el JWT de suscriptor. `MERCURE_SUBSCRIBER_TTL` es esa ventana.
- **Mismo proceso.** Reiniciar FrankenPHP para desplegar Laravel corta también las
  conexiones del hub. Los clientes reconectan con `Last-Event-ID` y no pierden
  eventos, pero sí reconectan todos a la vez. Separar el hub en su propio
  contenedor lo evita, sin cambiar el driver.

### Las piezas

| Qué | Dónde |
|---|---|
| El driver | `api/app/Broadcasting/MercureBroadcaster.php` |
| JWT de suscriptor | `api/app/Http/Controllers/MercureTokenController.php` |
| El hub | `api/docker/Caddyfile` (directiva `mercure`) |
| Claves del hub | `docker-compose.yml` → `api.environment` |
| Cliente | `web/src/app/mercure.service.ts` |

## Evaluación y decisión

### Matriz comparativa

| Criterio | `sse` | `apigateway` | `mercure` |
|---|---|---|---|
| Quién sostiene la conexión | un hilo PHP por cliente | AWS | una goroutine en el hub |
| Capacidad por nodo | techo duro: `num_threads` (64 aquí), ~30-60 MB por conexión | administrada por AWS | miles de conexiones; 150 verificadas sin afectar a Laravel |
| Escala horizontal | Redis compartido, pero sigue atada a hilos PHP | administrada por AWS | requiere Mercure HA (de pago) o hub con transporte compartido |
| Protocolo al navegador | SSE | WebSocket | SSE |
| Auth del handshake | `Authorization: Bearer` | ticket de 60 s en la query | JWT de suscriptor en `Authorization` |
| Autorización | en cada conexión (`channels.php`) | en cada `subscribe` (`channels.php`) | al emitir el JWT; el hub no reconsulta |
| Revocación de acceso | inmediata en la próxima conexión | inmediata en el próximo `subscribe` | tarda hasta `MERCURE_SUBSCRIBER_TTL` |
| Reanudar sin pérdida | `Last-Event-ID` nativo, en memoria (no sobrevive a F5) | `lastId` a mano + `localStorage` | `Last-Event-ID` nativo + `localStorage` |
| Fan-out desde Laravel | 1 `XADD` | N `PostToConnection` | 1 POST al hub |
| Estado externo | Redis | DynamoDB (3 tablas con TTL) | ninguno (archivo Bolt local) |
| Límites duros | los que pongas | 2 h por conexión, 10 min idle, 128 KB por mensaje | los que pongas |
| Servicios a operar | Redis | API Gateway + DynamoDB (administrados) | ninguno extra (va en FrankenPHP) |
| Costo | infraestructura propia, crece con hilos PHP | ~US$0.25/millón de minutos-conexión + ~US$1/millón de mensajes + DynamoDB por request | infraestructura propia; licencia si se necesita HA |
| Lock-in | bajo | alto (AWS) | bajo (protocolo abierto, hub reemplazable) |
| Complejidad del cliente | baja | media (ticket, `subscribe`, `ping`, dedupe) | baja (más renovar el token en 401) |

### Recomendación preliminar

> **Propuesta a validar** con los datos de la empresa (usuarios concurrentes,
> proveedor de nube, presupuesto). No es una decisión cerrada.

- **`sse` no va a producción.** El modelo de un hilo PHP por conexión pone un techo
  que no se resuelve con más hardware. Queda como línea base de la evaluación y
  como prueba de que las piezas comunes (JWT, `channels.php`, cliente) funcionan.
- **`mercure` es la opción por defecto.** Saca las conexiones de PHP sin agregar
  servicios, mantiene SSE con `Last-Event-ID` nativo, el cliente es el más simple y
  el protocolo es abierto. Alcanza mientras un nodo (o un hub dedicado) soporte la
  carga.
- **`apigateway` si la empresa ya opera en AWS y necesita escala horizontal
  administrada** desde el día uno, o si prefiere pagar por uso antes que la
  licencia de Mercure HA. Acepta a cambio lock-in, límites duros y un cliente más
  complejo.

Lo que cambiaría la recomendación:

- Necesidad de **revocar accesos al instante** → pesa contra `mercure` (salvo TTL
  muy cortos) y a favor de `apigateway`.
- **Volumen de mensajes muy alto** con muchos suscriptores por canal → el fan-out N
  de `apigateway` encarece y carga a PHP; favorece `mercure`.
- **Comunicación bidireccional** (el cliente también envía) → favorece WebSocket,
  es decir `apigateway`.
- **Sin AWS** o restricción de proveedor → descarta `apigateway`.

### Pendiente para cerrar la decisión

1. **Prueba de carga** con la concurrencia esperada de la empresa sobre `mercure`
   (un nodo) y `apigateway` (AWS real): conexiones sostenidas, latencia p95 y
   consumo de CPU/memoria.
2. **Despliegue real de `apigateway`** en AWS para verificar el mapeo
   `context.connectionId → X-Connection-Id` con `HTTP_PROXY`.
3. **Costo mensual estimado** con el volumen real: minutos-conexión y mensajes de
   API Gateway + DynamoDB frente a infraestructura propia (y licencia HA) para
   Mercure.
4. **Requisito de revocación** de accesos: definir la ventana aceptable para fijar
   `MERCURE_SUBSCRIBER_TTL`.
