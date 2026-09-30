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

Tres contenedores: `api` (FrankenPHP + Laravel 13), `redis` y `web` (Angular dev
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

Entra con `ana@example.com`. La home abre el stream y queda en «en vivo». Después,
desde otra terminal:

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
