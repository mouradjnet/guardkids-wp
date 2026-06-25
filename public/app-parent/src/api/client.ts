const API_ROOT = '/wp-json/guardkids/v1';

export class ApiError extends Error {
  constructor(
    public readonly code: string,
    message: string,
    public readonly status: number,
  ) {
    super(message);
  }
}

export function authHeaders(): Record<string, string> {
  const wpNonce = window.guardkidsApi?.nonce;
  if (wpNonce) {
    return { 'X-WP-Nonce': wpNonce };
  }

  const user = import.meta.env.VITE_WP_USER;
  const pass = import.meta.env.VITE_WP_APP_PASSWORD;
  if (user && pass) {
    return { Authorization: `Basic ${btoa(`${user}:${pass}`)}` };
  }

  return {};
}

export async function apiFetch<T>(path: string, init?: RequestInit): Promise<T> {
  const method = (init?.method ?? 'GET').toUpperCase();
  let url = `${API_ROOT}${path}`;
  if (method === 'GET') {
    // Cache-buster: o edge (LiteSpeed/hcdn) serve GET autenticado do cache
    // privado mesmo com `no-store`, devolvendo dados velhos (visto no PIN e nas
    // sessões). A URL única garante resposta fresca a cada leitura.
    url += (path.includes('?') ? '&' : '?') + '_=' + Date.now();
  }

  const res = await fetch(url, {
    credentials: 'same-origin',
    ...init,
    headers: {
      'Content-Type': 'application/json',
      ...authHeaders(),
      ...init?.headers,
    },
  });

  if (!res.ok) {
    let code = 'request_failed';
    let message = res.statusText;
    try {
      const body = (await res.json()) as { code?: string; message?: string };
      if (body.code) code = body.code;
      if (body.message) message = body.message;
    } catch {
      // resposta sem JSON; ignora
    }
    throw new ApiError(code, message, res.status);
  }

  if (res.status === 204) {
    return undefined as T;
  }
  return (await res.json()) as T;
}
