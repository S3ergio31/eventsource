import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AuthService } from './auth.service';

@Component({
  selector: 'app-login',
  imports: [FormsModule],
  template: `
    <main class="shell">
      <form class="card" (submit)="submit($event)">
        <h1>Entrar</h1>
        <p class="lede">Evaluación de drivers de Laravel Broadcasting: SSE, API Gateway y Mercure.</p>

        <label>
          Email
          <input name="email" type="email" autocomplete="username" [(ngModel)]="email" required />
        </label>

        <label>
          Contrasenia
          <input
            name="password"
            type="password"
            autocomplete="current-password"
            [(ngModel)]="password"
            required
          />
        </label>

        @if (error()) {
          <p class="error">{{ error() }}</p>
        }

        <button type="submit" [disabled]="busy()">
          {{ busy() ? 'Entrando...' : 'Entrar' }}
        </button>

        <p class="hint">
          Usuarios sembrados: <code>ana&#64;example.com</code> y <code>beto&#64;example.com</code>,
          contrasenia <code>password</code>.
        </p>
      </form>
    </main>
  `,
  styles: `
    .shell {
      display: grid;
      place-items: center;
      min-height: 100dvh;
      padding: 24px;
    }
    .card {
      display: grid;
      gap: 16px;
      width: 100%;
      max-width: 360px;
      padding: 28px;
      border: 1px solid var(--line);
      border-radius: 10px;
      background: var(--panel);
    }
    h1 {
      margin: 0;
      font-size: 22px;
      letter-spacing: -0.01em;
    }
    .lede {
      margin: -8px 0 0;
      color: var(--dim);
      font-size: 13px;
    }
    label {
      display: grid;
      gap: 6px;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: var(--dim);
    }
    .hint {
      margin: 0;
      font-size: 12px;
      line-height: 1.6;
      color: var(--dim);
    }
    .error {
      margin: 0;
      padding: 8px 10px;
      border-radius: 6px;
      background: color-mix(in srgb, var(--bad) 15%, transparent);
      color: var(--bad);
      font-size: 13px;
    }
  `,
})
export class LoginPage {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  email = 'ana@example.com';
  password = 'password';

  readonly busy = signal(false);
  readonly error = signal<string | null>(null);

  async submit(event: Event): Promise<void> {
    event.preventDefault();

    this.busy.set(true);
    this.error.set(null);

    try {
      await this.auth.login(this.email, this.password);
      await this.router.navigate(['/']);
    } catch {
      this.error.set('No pudimos entrar. Revisa las credenciales y que la API este arriba.');
    } finally {
      this.busy.set(false);
    }
  }
}
