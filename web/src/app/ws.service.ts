import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { API_BASE, WS_URL } from './api.config';
import { ConnectionState, Notification, Transport } from './transport';

/** Mensajes que manda Laravel por el socket (via PostToConnection). */
type ServerMessage =
  | { type: 'event'; channel: string; id: string; event: string; data: { message: string; sentAt: string } }
  | { type: 'subscribed'; channel: string; resumedFrom: string | null; replayed: number; cursor: string }
  | { type: 'error'; channel: string | null; status: number; message: string };

/** Error que NO debe reintentarse (ticket 401, canal 403). */
class FatalError extends Error {}

/** API Gateway corta a los 10 min sin trafico; un ping cada 5 lo evita. */
const PING_MS = 5 * 60 * 1000;
const MAX_BACKOFF_MS = 30_000;
const LAST_ID_KEY = 'ws-last-id:';

/**
 * Cliente del transporte API Gateway WebSocket.
 *
 * Lo que con SSE hacia la libreria (reconectar, mandar Last-Event-ID) aca se
 * hace a mano:
 *
 *  1. Canjea el JWT por un ticket corto (POST /ws/ticket): el WebSocket del
 *     navegador no puede mandar el header Authorization.
 *  2. Abre el socket con ?ticket= y se suscribe mandando el ultimo id visto.
 *  3. Si el socket se cierra (red, limite de 2 h, idle), reconecta con backoff
 *     y un ticket nuevo; el servidor repone lo que se perdio desde ese id.
 *
 * El ultimo id se guarda en localStorage, asi la reposicion sobrevive a un F5
 * (con SSE no, porque fetch-event-source lo tiene solo en memoria).
 */
@Injectable({ providedIn: 'root' })
export class WsService implements Transport {
  private readonly http = inject(HttpClient);

  readonly state = signal<ConnectionState>('idle');
  readonly events = signal<Notification[]>([]);
  readonly lastError = signal<string | null>(null);
  readonly resumedFrom = signal<string | null>(null);

  private socket?: WebSocket;
  private token = '';
  private channel = '';
  private active = false;
  private attempt = 0;
  private pingTimer?: ReturnType<typeof setInterval>;
  private retryTimer?: ReturnType<typeof setTimeout>;
  /** Ids ya mostrados: la reposicion puede repetir alguno a proposito. */
  private readonly seen = new Set<string>();

  connect(token: string, channel: string): void {
    this.disconnect();

    this.token = token;
    this.channel = channel;
    this.active = true;
    this.attempt = 0;
    this.lastError.set(null);

    void this.open();
  }

  disconnect(): void {
    this.active = false;
    clearTimeout(this.retryTimer);
    this.stopPing();
    this.socket?.close(1000, 'client disconnect');
    this.socket = undefined;
    this.state.set('idle');
  }

  clear(): void {
    this.events.set([]);
  }

  private async open(): Promise<void> {
    this.state.set(this.attempt === 0 ? 'connecting' : 'retrying');

    let ticket: string;
    try {
      ticket = await this.fetchTicket();
    } catch (error) {
      this.fail(error);
      return;
    }

    if (!this.active) return;

    const socket = new WebSocket(`${WS_URL}?ticket=${encodeURIComponent(ticket)}`);
    this.socket = socket;

    socket.onopen = () => {
      socket.send(
        JSON.stringify({ action: 'subscribe', channel: this.channel, lastId: this.loadLastId() }),
      );
      this.startPing(socket);
    };

    socket.onmessage = (message) => this.handleMessage(JSON.parse(message.data as string) as ServerMessage);

    socket.onclose = (event) => {
      if (socket !== this.socket) return; // un socket viejo, ya reemplazado

      this.stopPing();

      if (!this.active) return;

      // API Gateway no expone el status de un $connect rechazado: el
      // navegador solo ve un cierre 1006. Se reintenta con un ticket nuevo.
      this.lastError.set(`Socket cerrado (${event.code}${event.reason ? `: ${event.reason}` : ''}).`);
      this.scheduleReconnect();
    };
  }

  private async fetchTicket(): Promise<string> {
    try {
      const response = await firstValueFrom(
        this.http.post<{ ticket: string }>(
          `${API_BASE}/ws/ticket`,
          {},
          { headers: { Authorization: `Bearer ${this.token}` } },
        ),
      );
      return response.ticket;
    } catch (error) {
      if (error instanceof HttpErrorResponse && error.status === 401) {
        throw new FatalError('El servidor rechazo el JWT al pedir el ticket (401).');
      }
      throw error;
    }
  }

  private handleMessage(message: ServerMessage): void {
    switch (message.type) {
      case 'subscribed':
        this.attempt = 0;
        this.state.set('open');
        this.lastError.set(null);
        this.resumedFrom.set(message.resumedFrom ?? '(nuevo)');
        // Sin lastId previo, el cursor del servidor marca "desde aca": si el
        // socket se corta antes del primer evento, no se pierde lo del hueco.
        if (!this.loadLastId()) this.saveLastId(message.cursor);
        break;

      case 'error':
        this.fail(new FatalError(message.message || `Error ${message.status}`));
        break;

      case 'event': {
        if (this.seen.has(message.id)) return;
        this.seen.add(message.id);

        this.saveLastId(message.id);

        if (message.event !== 'notification') return;

        const notification: Notification = {
          id: message.id,
          message: message.data.message,
          sentAt: message.data.sentAt,
          receivedAt: Date.now(),
          // Llego antes del ack de la suscripcion: es reposicion, no vivo.
          replayed: this.state() !== 'open',
        };
        this.events.update((list) => [notification, ...list].slice(0, 50));
        break;
      }
    }
  }

  private fail(error: unknown): void {
    if (error instanceof FatalError) {
      this.active = false;
      clearTimeout(this.retryTimer);
      this.stopPing();
      this.socket?.close(1000, 'fatal');
      this.state.set('error');
      this.lastError.set(error.message);
      return;
    }

    this.lastError.set(error instanceof Error ? error.message : String(error));
    this.scheduleReconnect();
  }

  private scheduleReconnect(): void {
    if (!this.active) return;

    this.state.set('retrying');
    const delay = Math.min(1000 * 2 ** this.attempt, MAX_BACKOFF_MS) + Math.random() * 500;
    this.attempt++;

    clearTimeout(this.retryTimer);
    this.retryTimer = setTimeout(() => void this.open(), delay);
  }

  private startPing(socket: WebSocket): void {
    this.stopPing();
    this.pingTimer = setInterval(() => {
      if (socket.readyState === WebSocket.OPEN) {
        socket.send(JSON.stringify({ action: 'ping' }));
      }
    }, PING_MS);
  }

  private stopPing(): void {
    clearInterval(this.pingTimer);
    this.pingTimer = undefined;
  }

  private loadLastId(): string | null {
    try {
      return localStorage.getItem(LAST_ID_KEY + this.channel);
    } catch {
      return null;
    }
  }

  /** Los ids son ULID: el orden alfabetico es el orden temporal. */
  private saveLastId(id: string): void {
    const current = this.loadLastId();
    if (current && current >= id) return;

    try {
      localStorage.setItem(LAST_ID_KEY + this.channel, id);
    } catch {
      // Sin storage, la reposicion solo cubre cortes sin recargar la pagina.
    }
  }
}
