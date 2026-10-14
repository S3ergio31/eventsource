# Web (Angular)

Cliente de la evaluación de drivers de broadcasting. Tras el login, la home abre
los tres transportes lado a lado y muestra cada evento con su latencia, para
compararlos sobre el mismo `UserNotification`. El propósito y la comparación
están en el [README raíz](../README.md).

| Qué | Dónde |
|---|---|
| Cliente del driver `sse` (`@microsoft/fetch-event-source`) | `src/app/sse.service.ts` |
| Cliente del driver `apigateway` (WebSocket + ticket) | `src/app/ws.service.ts` |
| Cliente del driver `mercure` (SSE al hub + JWT de suscriptor) | `src/app/mercure.service.ts` |
| Interfaz común de los tres | `src/app/transport.ts` |
| Columna de la home (estado, eventos, latencia) | `src/app/transport-feed.ts` |
| URLs del backend | `src/app/api.config.ts` |

Se levanta con `docker compose up` desde la raíz (servicio `web`, puerto 4200).
Fuera de docker: `npm install && npx ng serve`. Build: `npx ng build`.
