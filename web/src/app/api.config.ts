export const API_BASE = 'http://localhost:8000/api';

// Emulador local de API Gateway (gateway-local/). Contra AWS real, el output
// WebSocketUrl de infra/template.yaml: wss://{api-id}.execute-api.{region}.amazonaws.com/prod
export const WS_URL = 'ws://localhost:3001';
