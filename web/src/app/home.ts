import { Component, OnDestroy, OnInit, computed, inject } from '@angular/core';
import { Router } from '@angular/router';
import { AuthService } from './auth.service';
import { SseService } from './sse.service';

@Component({
  selector: 'app-home',
  template: `
    <main class="shell">
      <header>
        <div>
          <h1>{{ session()?.user.name }}</h1>
          <p class="lede">
            escuchando <code>{{ session()?.channel }}</code>
          </p>
        </div>
        <div class="right">
          <span class="badge" [attr.data-state]="sse.state()">{{ label() }}</span>
          <button type="button" class="ghost" (click)="logout()">Salir</button>
        </div>
      </header>

      @if (sse.lastError()) {
        <p class="error">{{ sse.lastError() }}</p>
      }

      <section class="howto">
        <p>Despacha un evento desde otra terminal:</p>
        <pre>docker compose exec api php artisan demo:notify {{ session()?.user.id }} "hola"</pre>
      </section>

      <section class="feed">
        <div class="feed-head">
          <h2>Eventos recibidos ({{ sse.events().length }})</h2>
          @if (sse.events().length) {
            <button type="button" class="ghost" (click)="sse.clear()">Limpiar</button>
          }
        </div>

        @if (sse.events().length === 0) {
          <p class="empty">Nada todavia. La conexion esta abierta esperando.</p>
        } @else {
          <ul>
            @for (event of sse.events(); track event.id) {
              <li>
                <p class="msg">{{ event.message }}</p>
                <p class="meta">
                  <time>{{ event.sentAt }}</time>
                  <span class="id">id {{ event.id }}</span>
                </p>
              </li>
            }
          </ul>
        }
      </section>
    </main>
  `,
  styles: `
    .shell {
      display: grid;
      gap: 20px;
      max-width: 680px;
      margin: 0 auto;
      padding: 32px 20px 64px;
    }
    header {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      align-items: center;
      justify-content: space-between;
      padding-bottom: 16px;
      border-bottom: 1px solid var(--line);
    }
    h1 {
      margin: 0;
      font-size: 20px;
      letter-spacing: -0.01em;
    }
    h2 {
      margin: 0;
      font-size: 13px;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--dim);
    }
    .lede {
      margin: 4px 0 0;
      font-size: 13px;
      color: var(--dim);
    }
    .right {
      display: flex;
      gap: 10px;
      align-items: center;
    }
    .badge {
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
    .howto {
      padding: 14px 16px;
      border: 1px solid var(--line);
      border-radius: 8px;
      background: var(--panel);
    }
    .howto p {
      margin: 0 0 8px;
      font-size: 12px;
      color: var(--dim);
    }
    pre {
      margin: 0;
      overflow-x: auto;
      font-size: 12px;
      color: var(--ink);
    }
    .feed {
      display: grid;
      gap: 12px;
    }
    .feed-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
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
    .msg {
      margin: 0;
      font-size: 14px;
    }
    .meta {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin: 6px 0 0;
      font-size: 11px;
      color: var(--dim);
    }
    .id {
      font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
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
export class HomePage implements OnInit, OnDestroy {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  readonly sse = inject(SseService);
  readonly session = this.auth.session;

  readonly label = computed(
    () =>
      ({
        idle: 'desconectado',
        connecting: 'conectando',
        open: 'en vivo',
        retrying: 'reconectando',
        error: 'error',
      })[this.sse.state()],
  );

  ngOnInit(): void {
    const session = this.session();

    if (session) {
      this.sse.connect(session.token, session.channel);
    }
  }

  ngOnDestroy(): void {
    this.sse.disconnect();
  }

  logout(): void {
    this.sse.disconnect();
    this.sse.clear();
    this.auth.logout();
    void this.router.navigate(['/login']);
  }
}
