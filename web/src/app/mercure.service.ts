import { Injectable, signal } from '@angular/core';
import Echo, { type ConnectionStatus } from 'laravel-echo';
import { API_BASE, MERCURE_HUB_URL } from './api.config';
import { ConnectionState, Notification, Transport, TransportLog } from './transport';

/**
 * Cliente del transporte Mercure: Laravel Echo con el broadcaster 'mercure',
 * contra el driver nativo de Laravel. A diferencia de SseService y WsService
 * aca no hay protocolo propio, todo lo hace Echo:
 *
 *  1. POST /broadcasting/mercure/auth con { channel_names } y el Bearer de
 *     sesion. Laravel corre routes/channels.php y responde con una cookie
 *     HttpOnly que lleva el JWT de suscriptor (JavaScript nunca lo ve).
 *  2. EventSource nativo contra el hub (/.well-known/mercure?match=...), con
 *     withCredentials para que el navegador mande esa cookie.
 *  3. Re-autentica solo antes de que venza la cookie y reconecta con backoff,
 *     mandando last_event_id.
 *
 * Lo que se pierde frente al driver propio anterior: Echo guarda el ultimo id
 * solo en memoria (tras un F5 no hay reposicion) y no expone el id del evento
 * a los listeners.
 */
@Injectable({ providedIn: 'root' })
export class MercureService implements Transport {
  readonly state = signal<ConnectionState>('idle');
  readonly events = signal<Notification[]>([]);
  readonly lastError = signal<string | null>(null);
  readonly resumedFrom = signal<string | null>(null);
  readonly log = new TransportLog();

  private echo?: Echo<'mercure'>;
  private stopStatus?: () => void;
  /**
   * Ultima vez que el stream quedo conectado: un evento con sentAt anterior
   * solo puede ser reposicion. Es el reloj del navegador; Echo no da una
   * respuesta de la que sacar el del servidor.
   */
  private openedAt = 0;

  connect(token: string, channel: string): void {
    this.disconnect();

    this.lastError.set(null);
    this.state.set('connecting');
    // Echo no expone el cursor; lo que si se sabe es donde vive.
    this.resumedFrom.set('(Echo, en memoria)');

    const echo = new Echo<'mercure'>({
      broadcaster: 'mercure',
      host: MERCURE_HUB_URL,
      authEndpoint: `${API_BASE}/broadcasting/mercure/auth`,
      auth: { headers: { Authorization: `Bearer ${token}` } },
      withoutInterceptors: true,
    });
    this.echo = echo;

    this.stopStatus = echo.connector.onConnectionChange((status) => this.handleStatus(status));

    this.log.info(`Echo: POST /broadcasting/mercure/auth { channel_names: [${channel}] } con Bearer <jwt>`);

    // Echo antepone "private-": el canal de la sesion ya lo trae.
    echo
      .private(channel.replace(/^private-/, ''))
      .subscribed(() => this.log.ok(`Suscripto a ${channel}: cookie de suscriptor recibida`))
      .listen('.notification', (payload: { message: string; sentAt: string }) => this.handleNotification(payload))
      .error((error: unknown) => {
        // Canal denegado (denied en la respuesta) o auth rechazado (401/403):
        // Echo saca el canal y no reintenta.
        const message = error instanceof Error ? error.message : String(error);
        this.state.set('error');
        this.lastError.set(message);
        this.log.error(message);
      });
  }

  disconnect(): void {
    if (this.echo) this.log.info('Desconectado por el cliente.');
    this.stopStatus?.();
    this.stopStatus = undefined;
    this.echo?.disconnect();
    this.echo = undefined;
    this.state.set('idle');
  }

  clear(): void {
    this.events.set([]);
  }

  private handleStatus(status: ConnectionStatus): void {
    switch (status) {
      case 'connecting':
        this.state.set('connecting');
        this.log.info('Echo: connecting');
        break;

      case 'connected':
        this.openedAt = Date.now();
        this.state.set('open');
        this.lastError.set(null);
        this.log.ok('Echo: connected, EventSource abierto contra el hub');
        break;

      case 'reconnecting':
        this.state.set('retrying');
        this.log.warn('Echo: reconnecting, con backoff y last_event_id');
        break;

      case 'failed':
        this.state.set('error');
        this.log.error('Echo: failed');
        break;

      case 'disconnected':
        // Tras un canal denegado Echo queda en disconnected: no pisar el error.
        if (this.state() !== 'error') this.state.set('idle');
        this.log.info('Echo: disconnected');
        break;
    }
  }

  private handleNotification(payload: { message: string; sentAt: string }): void {
    const notification: Notification = {
      // El id SSE del hub no llega al listener.
      id: crypto.randomUUID(),
      message: payload.message,
      sentAt: payload.sentAt,
      receivedAt: Date.now(),
      replayed: Date.parse(payload.sentAt) < this.openedAt,
    };

    this.events.update((list) => [notification, ...list].slice(0, 50));
    this.log.event(`notification${notification.replayed ? ' (repuesto)' : ''}`);
  }
}
