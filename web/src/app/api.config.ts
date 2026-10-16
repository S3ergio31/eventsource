export const API_BASE = 'http://localhost:8000/api';

// Emulador local de API Gateway (gateway-local/). Contra AWS real, el output
// WebSocketUrl de infra/template.yaml: wss://{api-id}.execute-api.{region}.amazonaws.com/prod
export const WS_URL = 'ws://localhost:3001';

// Hub Mercure dentro de FrankenPHP (mismo host que la API). Echo se suscribe
// aca; la cookie de suscriptor tiene Path=/.well-known/mercure.
export const MERCURE_HUB_URL = 'http://localhost:8000/.well-known/mercure';
