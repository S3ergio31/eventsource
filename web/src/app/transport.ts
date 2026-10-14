import { Signal, signal } from '@angular/core';

export interface Notification {
  id: string;
  message: string;
  sentAt: string;
  /** Date.now() al llegar al navegador, para medir la latencia. */
  receivedAt: number;
  /** true si llego como reposicion tras una reconexion, no en vivo. */
  replayed?: boolean;
}

export type ConnectionState = 'idle' | 'connecting' | 'open' | 'retrying' | 'error';

/**
 * Lo que la home necesita de un transporte. SseService, WsService y
 * MercureService (uno por driver candidato) lo implementan igual, asi se
 * muestran lado a lado con el mismo componente.
 */
export interface Transport {
  readonly state: Signal<ConnectionState>;
  readonly events: Signal<Notification[]>;
  readonly lastError: Signal<string | null>;
  readonly resumedFrom: Signal<string | null>;
  readonly log: TransportLog;
  connect(token: string, channel: string): void;
  disconnect(): void;
  clear(): void;
}

export type LogLevel = 'info' | 'ok' | 'warn' | 'error' | 'event';

export interface LogEntry {
  at: number;
  level: LogLevel;
  text: string;
}

const MAX_LOG_ENTRIES = 200;

/**
 * Historial de lo que hace un transporte por debajo (pedir ticket/token,
 * abrir, suscribirse, reintentar...), para ver la secuencia en la home.
 */
export class TransportLog {
  readonly entries = signal<LogEntry[]>([]);

  info(text: string): void {
    this.add('info', text);
  }

  ok(text: string): void {
    this.add('ok', text);
  }

  warn(text: string): void {
    this.add('warn', text);
  }

  error(text: string): void {
    this.add('error', text);
  }

  event(text: string): void {
    this.add('event', text);
  }

  clear(): void {
    this.entries.set([]);
  }

  private add(level: LogLevel, text: string): void {
    this.entries.update((list) => [...list, { at: Date.now(), level, text }].slice(-MAX_LOG_ENTRIES));
  }
}
