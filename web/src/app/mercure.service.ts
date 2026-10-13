import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { EventSourceMessage, fetchEventSource } from '@microsoft/fetch-event-source';
import { firstValueFrom } from 'rxjs';
import { API_BASE } from './api.config';
import { ConnectionState, Notification, Transport } from './transport';

interface MercureToken {
  token: string;
  hub_url: string;
  topic: string;
  server_time: number;
}

/** Error que NO debe reintentarse (Laravel nego el canal o la sesion). */
class FatalError extends Error {}

/** El hub rechazo el JWT de suscriptor (vencido): hay que pedir otro. */
class TokenRejected extends Error {}

const LAST_ID_KEY = 'mercure-last-id:';
const RENEW_DELAY_MS = 1000;

/**
 * Cliente del transporte Mercure. Es SSE, igual que SseService, y usa la misma
 * libreria; lo que cambia es contra quien habla:
 *
 *  1. Canjea el JWT de sesion por un JWT de suscriptor del hub
 *     (POST /mercure/token). Ahi Laravel corre routes/channels.php.
 *  2. Se suscribe al hub (/.well-known/mercure?topic=...) con ese JWT. El hub
 *     es Go dentro de FrankenPHP: Laravel ya no sostiene la conexion.
 *  3. Las reconexiones las maneja la libreria con Last-Event-ID, y el hub
 *     repone desde su historial. Si el JWT vence (401 del hub), se pide uno
 *     nuevo y se sigue desde el ultimo id.
 *
 * El ultimo id se guarda en localStorage, asi la reposicion sobrevive a un F5.
 */
@Injectable({ providedIn: 'root' })
export class MercureService implements Transport {
  private readonly http = inject(HttpClient);

  readonly state = signal<ConnectionState>('idle');
  readonly events = signal<Notification[]>([]);
  readonly lastError = signal<string | null>(null);
  readonly resumedFrom = signal<string | null>(null);

  private controller?: AbortController;
  private renewTimer?: ReturnType<typeof setTimeout>;
  private token = '';
  private channel = '';
  /**
   * Inicio de esta conexion, en el reloj del servidor: un evento con sentAt
   * anterior solo puede ser reposicion. Se compara en el reloj del servidor
   * porque el de la VM de Docker puede ir segundos desfasado del navegador.
   */
  private openedAtServer = 0;
  /** Reloj del servidor menos reloj del navegador, estimado al pedir el token. */
  private clockOffset = 0;

  connect(token: string, channel: string): void {
    this.disconnect();

    this.token = token;
    this.channel = channel;
    this.lastError.set(null);
    this.state.set('connecting');

    void this.open();
  }

  disconnect(): void {
    clearTimeout(this.renewTimer);
    this.controller?.abort();
    this.controller = undefined;
    this.state.set('idle');
  }

  clear(): void {
    this.events.set([]);
  }

  private async open(): Promise<void> {
    const controller = new AbortController();
    this.controller = controller;

    let subscription: MercureToken;
    try {
      subscription = await this.fetchToken();
    } catch (error) {
      this.fail(error);
      return;
    }

    if (controller.signal.aborted) return;

    const lastId = this.loadLastId();
    const url = `${subscription.hub_url}?topic=${encodeURIComponent(subscription.topic)}`;

    void fetchEventSource(url, {
      signal: controller.signal,
      headers: {
        Authorization: `Bearer ${subscription.token}`,
        // En las reconexiones internas la libreria lo actualiza sola; este es
        // el de arranque (F5 o token renovado).
        ...(lastId ? { 'Last-Event-ID': lastId } : {}),
      },
      openWhenHidden: true,

      onopen: async (response) => {
        const contentType = response.headers.get('content-type') ?? '';

        if (response.ok && contentType.includes('text/event-stream')) {
          this.openedAtServer = Date.now() + this.clockOffset;
          this.state.set('open');
          this.lastError.set(null);
          this.resumedFrom.set(this.loadLastId() ?? '(nuevo)');
          return;
        }

        if (response.status === 401) {
          throw new TokenRejected('El hub rechazo el JWT de suscriptor (401).');
        }

        throw new Error(`Respuesta inesperada del hub (${response.status}).`);
      },

      onmessage: (event) => this.handleMessage(event),

      onclose: () => {
        throw new Error('El hub cerro el stream.');
      },

      onerror: (error) => {
        if (error instanceof TokenRejected) {
          throw error; // corta la libreria; open() pide un token nuevo
        }

        this.state.set('retrying');
        this.lastError.set(error instanceof Error ? error.message : String(error));
        return undefined;
      },
    }).catch((error: unknown) => {
      if (controller.signal.aborted) return;
      this.fail(error);
    });
  }

  private async fetchToken(): Promise<MercureToken> {
    try {
      const requestedAt = Date.now();
      const response = await firstValueFrom(
        this.http.post<MercureToken>(
          `${API_BASE}/mercure/token`,
          { channel: this.channel },
          { headers: { Authorization: `Bearer ${this.token}` } },
        ),
      );
      // El servidor leyo su reloj a mitad del viaje (aprox.).
      this.clockOffset = response.server_time - (requestedAt + Date.now()) / 2;
      return response;
    } catch (error) {
      if (error instanceof HttpErrorResponse && [400, 401, 403].includes(error.status)) {
        throw new FatalError(`Laravel rechazo la suscripcion (${error.status}).`);
      }
      throw error;
    }
  }

  private handleMessage(event: EventSourceMessage): void {
    // Los heartbeats del hub son comentarios SSE y no llegan como mensajes.
    if (!event.data || event.event !== 'notification') {
      return;
    }

    if (event.id) this.saveLastId(event.id);

    const payload = JSON.parse(event.data) as { message: string; sentAt: string };
    const notification: Notification = {
      id: event.id || crypto.randomUUID(),
      message: payload.message,
      sentAt: payload.sentAt,
      receivedAt: Date.now(),
      replayed: Date.parse(payload.sentAt) < this.openedAtServer,
    };
    this.events.update((list) =>
      list.some((item) => item.id === notification.id)
        ? list
        : [notification, ...list].slice(0, 50),
    );
  }

  private fail(error: unknown): void {
    if (error instanceof FatalError) {
      this.state.set('error');
      this.lastError.set(error.message);
      return;
    }

    // Token vencido o error de red al pedirlo: se reintenta con uno nuevo.
    this.state.set('retrying');
    this.lastError.set(error instanceof Error ? error.message : String(error));
    clearTimeout(this.renewTimer);
    this.renewTimer = setTimeout(() => void this.open(), RENEW_DELAY_MS);
  }

  private loadLastId(): string | null {
    try {
      return localStorage.getItem(LAST_ID_KEY + this.channel);
    } catch {
      return null;
    }
  }

  /** Los ids del hub (urn:uuid) no son ordenables: vale el ultimo recibido. */
  private saveLastId(id: string): void {
    try {
      localStorage.setItem(LAST_ID_KEY + this.channel, id);
    } catch {
      // Sin storage, la reposicion solo cubre cortes sin recargar la pagina.
    }
  }
}
