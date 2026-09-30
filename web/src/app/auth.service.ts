import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { API_BASE } from './api.config';

export interface Session {
  token: string;
  channel: string;
  user: { id: number; name: string; email: string };
}

const STORAGE_KEY = 'sse-demo-session';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);

  readonly session = signal<Session | null>(AuthService.restore());
  readonly isLoggedIn = computed(() => this.session() !== null);

  async login(email: string, password: string): Promise<void> {
    const response = await firstValueFrom(
      this.http.post<Session>(`${API_BASE}/login`, { email, password }),
    );

    // El backend nos dice cual es el canal privado de este usuario, asi el
    // front no arma nombres de canal a mano.
    const session: Session = {
      token: response.token,
      channel: response.channel,
      user: response.user,
    };

    localStorage.setItem(STORAGE_KEY, JSON.stringify(session));
    this.session.set(session);
  }

  logout(): void {
    localStorage.removeItem(STORAGE_KEY);
    this.session.set(null);
  }

  private static restore(): Session | null {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      return raw ? (JSON.parse(raw) as Session) : null;
    } catch {
      return null;
    }
  }
}
