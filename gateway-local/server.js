// Emulador minimo de una WebSocket API de AWS API Gateway, para correr el
// driver 'apigateway' en docker-compose sin cuenta de AWS.
//
// Imita lo que importa para Laravel, con la misma forma que la integracion
// HTTP de infra/template.yaml:
//
//   handshake        -> POST {BACKEND}/api/ws/gateway/connect?ticket=...
//                       un no-2xx rechaza el socket (como $connect)
//   mensaje entrante -> ruta por body.action ($request.body.action):
//                       subscribe -> POST .../subscribe con el mensaje crudo
//                       ping      -> nada (en AWS es una mock integration)
//   cierre           -> POST .../disconnect (best-effort, como en AWS)
//   POST /@connections/{id}  -> escribe al socket, o 410 GoneException
//
// Cada request a Laravel lleva X-Connection-Id y X-Gateway-Secret, igual que
// los RequestParameters de la plantilla SAM. No valida firmas SigV4.

import { randomBytes } from 'node:crypto';
import http from 'node:http';
import { WebSocketServer } from 'ws';

const PORT = Number(process.env.PORT ?? 3001);
const BACKEND = process.env.BACKEND ?? 'http://api:8000';
const SECRET = process.env.WS_GATEWAY_SECRET ?? '';
// Limites reales de API Gateway: 10 min sin trafico y 2 h de vida maxima.
// Se pueden achicar para ver la reconexion sin esperar.
const IDLE_TIMEOUT_MS = Number(process.env.IDLE_TIMEOUT_MS ?? 10 * 60 * 1000);
const MAX_LIFETIME_MS = Number(process.env.MAX_LIFETIME_MS ?? 2 * 60 * 60 * 1000);

/** @type {Map<string, import('ws').WebSocket>} */
const connections = new Map();

const log = (...args) => console.log(new Date().toISOString(), ...args);

// Formato parecido al de AWS ("L0SM9cOFvHcCIhw="), sin caracteres a escapar.
const newConnectionId = () => randomBytes(9).toString('base64url');

async function callBackend(route, connectionId, { query = '', body } = {}) {
  const response = await fetch(`${BACKEND}/api/ws/gateway/${route}${query}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-Connection-Id': connectionId,
      'X-Gateway-Secret': SECRET,
    },
    body: body ?? '{}',
  });

  log(`-> ${route} ${connectionId} ${response.status}`);

  return response;
}

// ---------------------------------------------------------------------------
// Endpoint de gestion: lo que el SDK de AWS llama como PostToConnection
// ---------------------------------------------------------------------------

const server = http.createServer((req, res) => {
  const match = req.url?.match(/^\/@connections\/([^/?]+)/);

  if (!match) {
    res.writeHead(404).end();
    return;
  }

  const connectionId = decodeURIComponent(match[1]);
  const socket = connections.get(connectionId);

  const gone = () => {
    res
      .writeHead(410, { 'Content-Type': 'application/json', 'x-amzn-ErrorType': 'GoneException' })
      .end(JSON.stringify({ message: 'Gone' }));
  };

  if (req.method === 'DELETE') {
    if (!socket) return gone();
    socket.close(1000, 'Deleted by backend');
    res.writeHead(204).end();
    return;
  }

  if (req.method !== 'POST') {
    res.writeHead(405).end();
    return;
  }

  const chunks = [];
  req.on('data', (chunk) => chunks.push(chunk));
  req.on('end', () => {
    if (!socket || socket.readyState !== socket.OPEN) return gone();

    socket.send(Buffer.concat(chunks).toString('utf8'));
    res.writeHead(200).end();
  });
});

// ---------------------------------------------------------------------------
// WebSocket: $connect / rutas / $disconnect
// ---------------------------------------------------------------------------

const wss = new WebSocketServer({ noServer: true });

server.on('upgrade', async (req, socket, head) => {
  const url = new URL(req.url ?? '/', 'http://localhost');
  const connectionId = newConnectionId();

  let response;
  try {
    response = await callBackend('connect', connectionId, { query: url.search });
  } catch (error) {
    log(`$connect ${connectionId} backend inalcanzable: ${error.message}`);
    socket.end('HTTP/1.1 502 Bad Gateway\r\n\r\n');
    return;
  }

  if (!response.ok) {
    // Igual que API Gateway: el navegador solo ve que el handshake fallo.
    socket.end(`HTTP/1.1 ${response.status} Rejected\r\n\r\n`);
    return;
  }

  wss.handleUpgrade(req, socket, head, (ws) => {
    connections.set(connectionId, ws);
    log(`open ${connectionId} (${connections.size} abiertas)`);
    track(ws, connectionId);
  });
});

function track(ws, connectionId) {
  let idleTimer;
  const resetIdle = () => {
    clearTimeout(idleTimer);
    idleTimer = setTimeout(() => ws.close(1001, 'Idle timeout'), IDLE_TIMEOUT_MS);
  };
  const lifetimeTimer = setTimeout(() => ws.close(1001, 'Going away'), MAX_LIFETIME_MS);
  resetIdle();

  ws.on('message', async (raw) => {
    resetIdle();

    const body = raw.toString('utf8');
    let action;
    try {
      action = JSON.parse(body).action;
    } catch {
      action = undefined;
    }

    if (action === 'ping') return;

    if (action === 'subscribe') {
      try {
        await callBackend('subscribe', connectionId, { body });
      } catch (error) {
        log(`subscribe ${connectionId} fallo: ${error.message}`);
        ws.send(JSON.stringify({ message: 'Internal server error', connectionId }));
      }
      return;
    }

    // Sin ruta $default, API Gateway responde asi.
    ws.send(JSON.stringify({ message: 'Forbidden', connectionId }));
  });

  ws.on('close', () => {
    clearTimeout(idleTimer);
    clearTimeout(lifetimeTimer);
    connections.delete(connectionId);
    log(`close ${connectionId} (${connections.size} abiertas)`);

    callBackend('disconnect', connectionId).catch((error) =>
      log(`$disconnect ${connectionId} fallo: ${error.message}`),
    );
  });
}

server.listen(PORT, () => {
  log(`gateway-local escuchando en :${PORT}, backend ${BACKEND}`);
});
