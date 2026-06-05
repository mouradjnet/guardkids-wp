import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, apiFetch } from './client';

const originalFetch = globalThis.fetch;

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

describe('apiFetch', () => {
  beforeEach(() => {
    delete (window as { guardkidsApi?: unknown }).guardkidsApi;
    vi.unstubAllEnvs();
  });

  afterEach(() => {
    globalThis.fetch = originalFetch;
    vi.restoreAllMocks();
  });

  it('prefixes /wp-json/guardkids/v1', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ ok: true }));
    globalThis.fetch = fetchMock;

    await apiFetch('/children');

    expect(fetchMock).toHaveBeenCalledWith(
      '/wp-json/guardkids/v1/children',
      expect.any(Object),
    );
  });

  it('sends X-WP-Nonce when window.guardkidsApi.nonce is present (prod)', async () => {
    (window as { guardkidsApi?: { nonce: string; root: string } }).guardkidsApi = {
      nonce: 'abc123',
      root: 'http://example.test/wp-json/guardkids/v1',
    };
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ ok: true }));
    globalThis.fetch = fetchMock;

    await apiFetch('/children');

    const headers = (fetchMock.mock.calls[0]?.[1] as RequestInit | undefined)?.headers as
      | Record<string, string>
      | undefined;
    expect(headers).toMatchObject({ 'X-WP-Nonce': 'abc123' });
    expect(headers).not.toHaveProperty('Authorization');
  });

  it('falls back to Basic Auth from import.meta.env in dev', async () => {
    vi.stubEnv('VITE_WP_USER', 'admin');
    vi.stubEnv('VITE_WP_APP_PASSWORD', 'pwd');
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ ok: true }));
    globalThis.fetch = fetchMock;

    await apiFetch('/children');

    const headers = (fetchMock.mock.calls[0]?.[1] as RequestInit | undefined)?.headers as
      | Record<string, string>
      | undefined;
    expect(headers?.Authorization).toBe(`Basic ${btoa('admin:pwd')}`);
    expect(headers).not.toHaveProperty('X-WP-Nonce');
  });

  it('parses WP_Error body and throws ApiError with code/status', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ code: 'rest_forbidden', message: 'Sem permissão.' }, 401),
    );
    globalThis.fetch = fetchMock;

    await expect(apiFetch('/children')).rejects.toMatchObject({
      code: 'rest_forbidden',
      message: 'Sem permissão.',
      status: 401,
    });
    await expect(apiFetch('/children')).rejects.toBeInstanceOf(ApiError);
  });

  it('falls back to statusText when body is not JSON', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(new Response('boom', { status: 500, statusText: 'Internal Server Error' }));
    globalThis.fetch = fetchMock;

    await expect(apiFetch('/children')).rejects.toMatchObject({
      code: 'request_failed',
      message: 'Internal Server Error',
      status: 500,
    });
  });

  it('returns undefined for 204 No Content', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));
    globalThis.fetch = fetchMock;

    const result = await apiFetch<undefined>('/something');
    expect(result).toBeUndefined();
  });

  it('returns parsed JSON body for 200', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse([{ id: 1, name: 'Lucas' }]));
    globalThis.fetch = fetchMock;

    const result = await apiFetch<Array<{ id: number; name: string }>>('/children');
    expect(result).toEqual([{ id: 1, name: 'Lucas' }]);
  });
});
