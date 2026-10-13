import { Signal } from '@angular/core';

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
 * Lo que la home necesita de un transporte. SseService y WsService lo
 * implementan igual, asi se muestran lado a lado con el mismo componente.
 */
export interface Transport {
  readonly state: Signal<ConnectionState>;
  readonly events: Signal<Notification[]>;
  readonly lastError: Signal<string | null>;
  readonly resumedFrom: Signal<string | null>;
  connect(token: string, channel: string): void;
  disconnect(): void;
  clear(): void;
}
