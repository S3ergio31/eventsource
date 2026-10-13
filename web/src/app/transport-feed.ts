import { Component, computed, input } from '@angular/core';
import { Notification, Transport } from './transport';

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
      margin: 0;
      padding: 0;
      list-style: none;
    }
    li {
      padding: 12px 14px;
      border: 1px solid var(--line);
      border-left: 2px solid var(--good);
      border-radius: 6px;
      background: var(--panel);
    }
    li.replayed {
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

  /**
   * Reloj del navegador menos reloj del servidor: incluye el desfase entre
   * ambos (con Docker Desktop, la VM puede ir decenas de ms corrida), asi que
   * puede dar negativo. Sirve para comparar transportes, no como valor absoluto.
   */
  latency(event: Notification): number {
    return event.receivedAt - Date.parse(event.sentAt);
  }
}
