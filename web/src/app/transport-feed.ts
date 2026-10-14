import { Component, ElementRef, afterRenderEffect, computed, input, viewChild } from '@angular/core';
import { LogLevel, Notification, Transport } from './transport';

/**
 * Una columna de la home: estado y eventos de un transporte. El mismo
 * componente muestra SSE y API Gateway, para compararlos en igualdad.
 */
@Component({
  selector: 'app-transport-feed',
  template: `
    <section class="feed">
      <header>
        <div>
          <h2>{{ title() }}</h2>
          <p class="sub">{{ subtitle() }}</p>
        </div>
        <span class="badge" [attr.data-state]="transport().state()">{{ label() }}</span>
      </header>

      @if (transport().lastError()) {
        <p class="error">{{ transport().lastError() }}</p>
      }

      <div class="feed-head">
        <p class="count">
          {{ transport().events().length }} eventos
          @if (avgLatency() !== null) {
            · latencia media {{ avgLatency() }} ms
          }
        </p>
        @if (transport().events().length) {
          <button type="button" class="ghost" (click)="transport().clear()">Limpiar</button>
        }
      </div>

      @if (transport().events().length === 0) {
        <p class="empty">Nada todavia. La conexion esta abierta esperando.</p>
      } @else {
        <ul>
          @for (event of transport().events(); track event.id) {
            <li [class.replayed]="event.replayed">
              <p class="msg">{{ event.message }}</p>
              <p class="meta">
                @if (event.replayed) {
                  <span class="tag">repuesto</span>
                } @else {
                  <span class="latency" title="Incluye el desfase de reloj servidor/navegador">
                    {{ latency(event) }} ms
                  </span>
                }
                <time>{{ event.sentAt }}</time>
                <span class="id">id {{ event.id }}</span>
              </p>
            </li>
          }
        </ul>
      }

      <div class="feed-head">
        <p class="count">Log · {{ transport().log.entries().length }} entradas</p>
        @if (transport().log.entries().length) {
          <button type="button" class="ghost" (click)="transport().log.clear()">Limpiar</button>
        }
      </div>

      <ol #logBox class="log" (scroll)="onLogScroll()">
        @for (entry of transport().log.entries(); track $index) {
          <li [attr.data-level]="entry.level">
            <time>{{ time(entry.at) }}</time>
            <span class="icon">{{ icons[entry.level] }}</span>
            <span class="text">{{ entry.text }}</span>
          </li>
        } @empty {
          <li class="none">Sin actividad.</li>
        }
      </ol>
    </section>
  `,
  styles: `
    .feed {
      display: grid;
      align-content: start;
      gap: 12px;
      min-width: 0;
    }
    header {
      display: flex;
      gap: 12px;
      align-items: flex-start;
      justify-content: space-between;
    }
    h2 {
      margin: 0;
      font-size: 13px;
      text-transform: uppercase;
      letter-spacing: 0.08em;
    }
    .sub {
      margin: 4px 0 0;
      font-size: 12px;
      color: var(--dim);
    }
    .badge {
      flex: none;
      padding: 4px 10px;
      border-radius: 999px;
      border: 1px solid currentColor;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--dim);
    }
    .badge[data-state='open'] {
      color: var(--good);
    }
    .badge[data-state='connecting'],
    .badge[data-state='retrying'] {
      color: var(--warn);
    }
    .badge[data-state='error'] {
      color: var(--bad);
    }
    .feed-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      min-height: 26px;
    }
    .count {
      margin: 0;
      font-size: 12px;
      color: var(--dim);
    }
    ul {
      display: grid;
      gap: 8px;
      max-height: 360px;
      margin: 0;
      padding: 0;
      overflow-y: auto;
      list-style: none;
    }
    ul > li {
      padding: 12px 14px;
      border: 1px solid var(--line);
      border-left: 2px solid var(--good);
      border-radius: 6px;
      background: var(--panel);
    }
    ul > li.replayed {
      border-left-color: var(--warn);
    }
    .msg {
      margin: 0;
      font-size: 14px;
      overflow-wrap: anywhere;
    }
    .meta {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin: 6px 0 0;
      font-size: 11px;
      color: var(--dim);
    }
    .latency {
      color: var(--good);
      font-variant-numeric: tabular-nums;
    }
    .tag {
      color: var(--warn);
    }
    .id {
      font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
      overflow-wrap: anywhere;
    }
    .empty {
      margin: 0;
      font-size: 13px;
      color: var(--dim);
    }
    .error {
      margin: 0;
      padding: 8px 12px;
      border-radius: 6px;
      background: color-mix(in srgb, var(--warn) 15%, transparent);
      color: var(--warn);
      font-size: 12px;
    }
    .log {
      height: 240px;
      margin: 0;
      padding: 8px 10px;
      overflow-y: auto;
      border: 1px solid var(--line);
      border-radius: 6px;
      background: var(--panel);
      list-style: none;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: 11px;
      line-height: 1.6;
    }
    .log li {
      display: grid;
      grid-template-columns: auto 1ch 1fr;
      gap: 8px;
      color: var(--dim);
    }
    .log time {
      font-variant-numeric: tabular-nums;
      opacity: 0.7;
    }
    .log .text {
      overflow-wrap: anywhere;
    }
    .log li[data-level='ok'] .icon,
    .log li[data-level='ok'] .text {
      color: var(--good);
    }
    .log li[data-level='warn'] .icon,
    .log li[data-level='warn'] .text {
      color: var(--warn);
    }
    .log li[data-level='error'] .icon,
    .log li[data-level='error'] .text {
      color: var(--bad);
    }
    .log li[data-level='event'] .icon,
    .log li[data-level='event'] .text {
      color: var(--ink);
    }
    .log li.none {
      display: block;
    }
    .ghost {
      padding: 5px 10px;
      border: 1px solid var(--line);
      border-radius: 6px;
      background: transparent;
      color: var(--dim);
      font-size: 12px;
      cursor: pointer;
    }
    .ghost:hover {
      color: var(--ink);
      border-color: var(--dim);
    }
  `,
})
export class TransportFeed {
  readonly transport = input.required<Transport>();
  readonly title = input.required<string>();
  readonly subtitle = input('');

  readonly label = computed(
    () =>
      ({
        idle: 'desconectado',
        connecting: 'conectando',
        open: 'en vivo',
        retrying: 'reconectando',
        error: 'error',
      })[this.transport().state()],
  );

  /** Media de los eventos en vivo; los repuestos distorsionarian la cifra. */
  readonly avgLatency = computed(() => {
    const live = this.transport()
      .events()
      .filter((event) => !event.replayed);

    if (live.length === 0) return null;

    return Math.round(live.reduce((sum, event) => sum + this.latency(event), 0) / live.length);
  });

  readonly icons: Record<LogLevel, string> = {
    info: '·',
    ok: '✓',
    warn: '!',
    error: '✗',
    event: '↓',
  };

  private readonly logBox = viewChild.required<ElementRef<HTMLElement>>('logBox');
  /** Solo se sigue el final si el usuario no subio a leer algo anterior. */
  private stickToBottom = true;

  constructor() {
    // Despues del render, para que el scrollHeight incluya la entrada nueva.
    afterRenderEffect(() => {
      this.transport().log.entries();
      if (!this.stickToBottom) return;

      const box = this.logBox().nativeElement;
      box.scrollTop = box.scrollHeight;
    });
  }

  onLogScroll(): void {
    const box = this.logBox().nativeElement;
    this.stickToBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 8;
  }

  time(at: number): string {
    const date = new Date(at);
    const pad = (value: number, size = 2) => String(value).padStart(size, '0');
    return `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}.${pad(date.getMilliseconds(), 3)}`;
  }

  /**
   * Reloj del navegador menos reloj del servidor: incluye el desfase entre
   * ambos (con Docker Desktop, la VM puede ir decenas de ms corrida), asi que
   * puede dar negativo. Sirve para comparar transportes, no como valor absoluto.
   */
  latency(event: Notification): number {
    return event.receivedAt - Date.parse(event.sentAt);
  }
}
