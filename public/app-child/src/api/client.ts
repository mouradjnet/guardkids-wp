import { getStoredToken } from './token';

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

export async function apiFetch<T>(path: string, init?: RequestInit): Promise<T> {
  const token = getStoredToken();
  const res = await fetch(`${API_ROOT}${path}`, {
    credentials: 'omit',
    ...init,
    headers: {
      'Content-Type': 'application/json',
      ...(token ? { 'X-GuardKids-Token': token } : {}),
      ...init?.headers,
    },
  });
  return parseResponse<T>(res);
}

export async function apiFetchWithToken<T>(token: string, path: string, init?: RequestInit): Promise<T> {
  const res = await fetch(`${API_ROOT}${path}`, {
    credentials: 'omit',
    ...init,
    headers: {
      'Content-Type': 'application/json',
      'X-GuardKids-Token': token,
      ...init?.headers,
    },
  });
  return parseResponse<T>(res);
}

async function parseResponse<T>(res: Response): Promise<T> {
  if (!res.ok) {
    let code = 'request_failed';
    let message = res.statusText;
    try {
      const body = (await res.json()) as { code?: string; message?: string };
      if (body.code) code = body.code;
      if (body.message) message = body.message;
    } catch {
      // resposta sem JSON — usa statusText
    }
    throw new ApiError(code, message, res.status);
  }
  if (res.status === 204) {
    return undefined as T;
  }
  return (await res.json()) as T;
}
