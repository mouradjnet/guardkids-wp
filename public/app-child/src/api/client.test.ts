import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError, apiFetch, apiFetchWithToken } from './client';

const originalFetch = globalThis.fetch;

vi.mock('./token', () => ({ getStoredToken: () => 'tok' }));

describe('falha de rede', () => {
  afterEach(() => {
    globalThis.fetch = originalFetch;
    vi.restoreAllMocks();
  });

  it('apiFetch explica em português em vez de vazar "Failed to fetch"', async () => {
    // fetch só rejeita por rede/CORS — nunca por status HTTP.
    globalThis.fetch = vi.fn().mockRejectedValue(new TypeError('Failed to fetch'));

    await expect(apiFetch('/child/me')).rejects.toThrow(/Sem internet/);
    await expect(apiFetch('/child/me')).rejects.not.toThrow(/Failed to fetch/);
  });

  it('apiFetchWithToken também explica (pareamento acontece offline)', async () => {
    globalThis.fetch = vi.fn().mockRejectedValue(new TypeError('Failed to fetch'));

    await expect(apiFetchWithToken('tok', '/child/me')).rejects.toThrow(/Sem internet/);
  });

  it('não marca falha de rede com status HTTP falso', async () => {
    // ApiError vira "mensagem (status)" na tela; não existe status 0.
    globalThis.fetch = vi.fn().mockRejectedValue(new TypeError('Failed to fetch'));

    await expect(apiFetch('/child/me')).rejects.not.toBeInstanceOf(ApiError);
  });
});
