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
  // Cache-buster: o edge (LiteSpeed/hcdn) serve GET autenticado do cache privado
  // mesmo com `no-store`, devolvendo lista de sessões velha. A URL única força
  // uma resposta fresca a cada chamada.
  return apiFetch<{ sessions: SessionDto[] }>(`/security/sessions?_=${Date.now()}`);
}

export function destroyOtherSessions(): Promise<{ destroyed: number; sessions: SessionDto[] }> {
  return apiFetch<{ destroyed: number; sessions: SessionDto[] }>(
    '/security/sessions/destroy-others',
    { method: 'POST' },
  );
}
