import { Injectable, signal } from '@angular/core';
import { EventSourceMessage, fetchEventSource } from '@microsoft/fetch-event-source';
import { API_BASE } from './api.config';
import { ConnectionState, Notification, Transport, TransportLog } from './transport';

/**
 * Error que NO debe reintentarse (401, 403, 400). Si lo relanzamos desde
 * onerror, fetch-event-source deja de reconectar.
 */
class FatalError extends Error {}

/**
 * Por que @microsoft/fetch-event-source y no el EventSource nativo:
 *
 *  - EventSource no permite mandar headers, asi que el JWT tendria que viajar
 *    en la query string (queda en logs de acceso e historial).
 *  - EventSource no expone el status de la respuesta, asi que no se puede
 *    distinguir "403, no insistas" de "se cayo la red, reintenta".
 *
 * Esta libreria usa fetch() por debajo y resuelve las dos cosas. La reconexion
 * y el reenvio del header Last-Event-ID los maneja ella.
 */
@Injectable({ providedIn: 'root' })
export class SseService implements Transport {
  readonly state = signal<ConnectionState>('idle');
  readonly events = signal<Notification[]>([]);
  readonly lastError = signal<string | null>(null);
  readonly resumedFrom = signal<string | null>(null);
  readonly log = new TransportLog();

  private controller?: AbortController;

  connect(token: string, channel: string): void {
    this.disconnect();

    const controller = new AbortController();
    this.controller = controller;

    this.state.set('connecting');
    this.lastError.set(null);

    const url = `${API_BASE}/sse?channel=${encodeURIComponent(channel)}`;
    this.log.info(`GET /sse?channel=${channel} con Authorization: Bearer <jwt>`);

    void fetchEventSource(url, {
      signal: controller.signal,
      // El JWT va en el header, que es el motivo de usar esta libreria.
      headers: { Authorization: `Bearer ${token}` },
      // Por defecto la libreria cierra el stream cuando la pestania pasa a
      // segundo plano; para la demo preferimos mantenerlo abierto.
      openWhenHidden: true,

      onopen: async (response) => {
        const contentType = response.headers.get('content-type') ?? '';

        if (response.ok && contentType.includes('text/event-stream')) {
          this.state.set('open');
          this.log.ok(`${response.status} text/event-stream: stream abierto`);
          return;
        }

        if (response.status === 401 || response.status === 403 || response.status === 400) {
          throw new FatalError(`El servidor rechazo la suscripcion (${response.status}).`);
        }

        throw new Error(`Respuesta inesperada del servidor (${response.status}).`);
      },

      onmessage: (event) => this.handleMessage(event),

      onclose: () => {
        // El servidor cerro limpio al llegar a SSE_MAX_LIFETIME. Lanzar hace
        // que la libreria pase por onerror y reconecte con Last-Event-ID.
        throw new Error('El servidor cerro el stream.');
      },

      onerror: (error) => {
        if (error instanceof FatalError) {
          this.state.set('error');
          this.lastError.set(error.message);
          this.log.error(`${error.message} Sin reintentos.`);
          throw error; // corta los reintentos
        }

        // Cualquier otra cosa se reintenta. Devolver undefined deja que la
        // libreria espere el intervalo del campo "retry:" que mando el server.
        const message = error instanceof Error ? error.message : String(error);
        this.state.set('retrying');
        this.lastError.set(message);
        this.log.warn(`${message} La libreria reconecta con Last-Event-ID tras el "retry:" del server.`);
        return undefined;
      },
    }).catch(() => {
      // Abortado por disconnect(), o fatal: el estado ya quedo seteado arriba.
    });
  }

  disconnect(): void {
    if (this.controller) this.log.info('Desconectado por el cliente.');
    this.controller?.abort();
    this.controller = undefined;
    this.state.set('idle');
  }

  clear(): void {
    this.events.set([]);
  }

  private handleMessage(event: EventSourceMessage): void {
    // Los heartbeats son comentarios SSE (":heartbeat") y la libreria no los
    // entrega como mensajes, asi que aca no llegan.
    if (!event.data) {
      return;
    }

    switch (event.event) {
      case 'sse:connected': {
        const payload = JSON.parse(event.data) as { resumedFrom: string };
        this.state.set('open');
        this.resumedFrom.set(payload.resumedFrom);
        this.log.ok(`sse:connected, leyendo el Redis Stream desde ${payload.resumedFrom}`);
        break;
      }

      case 'sse:reconnect':
        this.state.set('retrying');
        this.log.warn('sse:reconnect: el servidor pide reconectar.');
        break;

      case 'notification': {
        const payload = JSON.parse(event.data) as { message: string; sentAt: string };
        const notification: Notification = {
          id: event.id || crypto.randomUUID(),
          message: payload.message,
          sentAt: payload.sentAt,
          receivedAt: Date.now(),
        };
        this.events.update((list) => [notification, ...list].slice(0, 50));
        this.log.event(`notification id ${notification.id}`);
        break;
      }
    }
  }
}
