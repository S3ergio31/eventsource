import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { Router } from '@angular/router';
import { AuthService } from './auth.service';
import { MercureService } from './mercure.service';
import { SseService } from './sse.service';
import { TransportFeed } from './transport-feed';
import { WsService } from './ws.service';

/**
 * Los tres transportes lado a lado. El backend manda cada evento por los tres
 * drivers a la vez (ver UserNotification::broadcastConnections()), asi que
 * un mismo demo:notify aparece en todas las columnas con su latencia.
 */
@Component({
  selector: 'app-home',
  imports: [TransportFeed],
  template: `
    <main class="shell">
      <header>
        <div>
          <h1>{{ session()?.user.name }}</h1>
          <p class="lede">
            escuchando <code>{{ session()?.channel }}</code>
          </p>
        </div>
        <button type="button" class="ghost" (click)="logout()">Salir</button>
      </header>

      <section class="howto">
        <p>Despacha un evento desde otra terminal; llega por los tres transportes:</p>
        <pre>docker compose exec api php artisan demo:notify {{ session()?.user.id }} "hola"</pre>
      </section>

      <div class="columns">
        <app-transport-feed
          title="SSE"
          subtitle="Laravel sostiene el stream · Redis Streams"
          [transport]="sse"
        />
        <app-transport-feed
          title="API Gateway"
          subtitle="API Gateway sostiene el WebSocket · DynamoDB"
          [transport]="ws"
        />
        <app-transport-feed
          title="Mercure"
          subtitle="Driver nativo + Echo · hub Go de FrankenPHP · Bolt"
          [transport]="mercure"
        />
      </div>
    </main>
  `,
  styles: `
    .shell {
      display: grid;
      gap: 20px;
      max-width: 1200px;
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
    .lede {
      margin: 4px 0 0;
      font-size: 13px;
      color: var(--dim);
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
    .columns {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 24px;
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
  readonly ws = inject(WsService);
  readonly mercure = inject(MercureService);
  readonly session = this.auth.session;

  ngOnInit(): void {
    const session = this.session();

    if (session) {
      this.sse.connect(session.token, session.channel);
      this.ws.connect(session.token, session.channel);
      this.mercure.connect(session.token, session.channel);
    }
  }

  ngOnDestroy(): void {
    this.sse.disconnect();
    this.ws.disconnect();
    this.mercure.disconnect();
  }

  logout(): void {
    for (const transport of [this.sse, this.ws, this.mercure]) {
      transport.disconnect();
      transport.clear();
      transport.log.clear();
    }
    this.auth.logout();
    void this.router.navigate(['/login']);
  }
}
