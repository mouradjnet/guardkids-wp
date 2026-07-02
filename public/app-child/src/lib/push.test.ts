import { afterEach, describe, expect, it, vi } from 'vitest';
import { isPushSupported, subscribe } from './push';

const apiFetch = vi.fn();
vi.mock('../api/client', () => ({
  apiFetch: (...args: unknown[]) => apiFetch(...args),
}));

describe('push', () => {
  afterEach(() => {
    apiFetch.mockReset();
    vi.unstubAllGlobals();
  });

  it('isPushSupported reflete as APIs do navegador', () => {
    vi.stubGlobal('navigator', {});
    expect(isPushSupported()).toBe(false);
  });

  it('subscribe pega a chave, inscreve e envia endpoint+keys', async () => {
    apiFetch.mockResolvedValueOnce({ publicKey: 'BExampleKeyBase64Url' });
    apiFetch.mockResolvedValueOnce({ ok: true });

    const sub = {
      toJSON: () => ({
        endpoint: 'https://push/abc',
        keys: { p256dh: 'p', auth: 'a' },
      }),
    };
    const registration = { pushManager: { subscribe: vi.fn().mockResolvedValue(sub) } };
    vi.stubGlobal('navigator', {
      serviceWorker: { ready: Promise.resolve(registration) },
    });
    vi.stubGlobal('atob', (s: string) => Buffer.from(s, 'base64').toString('binary'));

    await subscribe();

    expect(apiFetch).toHaveBeenNthCalledWith(1, '/child/push/key');
    expect(apiFetch).toHaveBeenNthCalledWith(2, '/child/push/subscribe', {
      method: 'POST',
      body: JSON.stringify({ endpoint: 'https://push/abc', keys: { p256dh: 'p', auth: 'a' } }),
    });
  });
});
