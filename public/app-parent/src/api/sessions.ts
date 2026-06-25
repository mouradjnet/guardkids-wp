import { apiFetch } from './client';

export type SessionDto = {
  device: string;
  browser: string;
  os: string;
  ip: string;
  lastAccess: number;
  current: boolean;
};

export function listSessions(): Promise<{ sessions: SessionDto[] }> {
  return apiFetch<{ sessions: SessionDto[] }>('/security/sessions');
}

export function destroyOtherSessions(): Promise<{ destroyed: number; sessions: SessionDto[] }> {
  return apiFetch<{ destroyed: number; sessions: SessionDto[] }>(
    '/security/sessions/destroy-others',
    { method: 'POST' },
  );
}
