import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const apiFetchMock = vi.hoisted(() => vi.fn());
vi.mock('../api/client', () => ({ apiFetch: apiFetchMock }));

import { isPushSupported, subscribe, unsubscribe } from './push';

const VALID_KEY =
  'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';

/**
 * `ready` é uma promise que NUNCA resolve. Se o código a usar, o teste estoura
 * por timeout — que é exatamente o bug que queremos impedir: o SW do painel tem
 * scope do dist/ e não controla /painel-pais/, então `serviceWorker.ready`
 * penduraria pra sempre, em silêncio.
 */
const NEVER = new Promise<never>(() => {});

function stubSubscribed() {
  const pushSubscribe = vi.fn().mockResolvedValue({
    toJSON: () => ({ endpoint: 'https://fcm/x', keys: { p256dh: 'P', auth: 'A' } }),
  });
  const register = vi.fn().mockResolvedValue({
    active: {},
    pushManager: { subscribe: pushSubscribe },
  });
  vi.stubGlobal('navigator', { serviceWorker: { register, ready: NEVER } });
  return { register, pushSubscribe };
}

describe('isPushSupported', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('é falso quando o browser não tem serviceWorker', () => {
    vi.stubGlobal('navigator', {});
    vi.stubGlobal('window', {});
    expect(isPushSupported()).toBe(false);
  });
});

describe('subscribe', () => {
  beforeEach(() => {
    apiFetchMock.mockReset();
    vi.stubGlobal('window', {
      PushManager: class {},
      guardkidsApi: { swUrl: 'https://site.test/plugins/dist/sw.js' },
    });
    vi.stubGlobal('Notification', {
      permission: 'granted',
      requestPermission: vi.fn().mockResolvedValue('granted'),
    });
  });

  afterEach(() => vi.unstubAllGlobals());

  it('registra o SW pela swUrl e não depende de serviceWorker.ready', async () => {
    const { register } = stubSubscribed();
    apiFetchMock.mockResolvedValueOnce({ publicKey: VALID_KEY }).mockResolvedValueOnce({ ok: true });

    await subscribe();

    expect(register).toHaveBeenCalledWith('https://site.test/plugins/dist/sw.js');
    expect(apiFetchMock.mock.calls[0][0]).toBe('/guardian/push/key');
    expect(apiFetchMock.mock.calls[1][0]).toBe('/guardian/push/subscribe');
    expect(JSON.parse(apiFetchMock.mock.calls[1][1].body)).toEqual({
      endpoint: 'https://fcm/x',
      keys: { p256dh: 'P', auth: 'A' },
    });
  });

  it('pede permissão quando ainda não foi concedida', async () => {
    const requestPermission = vi.fn().mockResolvedValue('granted');
    vi.stubGlobal('Notification', { permission: 'default', requestPermission });
    stubSubscribed();
    apiFetchMock.mockResolvedValueOnce({ publicKey: VALID_KEY }).mockResolvedValueOnce({ ok: true });

    await subscribe();

    expect(requestPermission).toHaveBeenCalled();
  });

  it('rejeita quando a permissão é negada, sem chamar o subscribe da API', async () => {
    vi.stubGlobal('Notification', {
      permission: 'default',
      requestPermission: vi.fn().mockResolvedValue('denied'),
    });
    stubSubscribed();
    apiFetchMock.mockResolvedValueOnce({ publicKey: VALID_KEY });

    await expect(subscribe()).rejects.toThrow(/permiss/i);
    expect(apiFetchMock).toHaveBeenCalledTimes(1);
  });

  it('rejeita quando a swUrl não foi injetada', async () => {
    vi.stubGlobal('window', { PushManager: class {}, guardkidsApi: {} });
    stubSubscribed();
    apiFetchMock.mockResolvedValueOnce({ publicKey: VALID_KEY });

    await expect(subscribe()).rejects.toThrow(/service worker/i);
  });

  it('rejeita (em vez de pendurar) quando o SW vira redundant', async () => {
    // SW que falha ao instalar: installing -> redundant, nunca 'activated'.
    // Sem tratar isso, o await da ativacao nunca assenta e o toggle fica preso
    // em "salvando" pra sempre — a mesma falha silenciosa do serviceWorker.ready.
    const listeners: Array<() => void> = [];
    const worker = {
      state: 'installing',
      addEventListener: (_: string, cb: () => void) => listeners.push(cb),
    };
    const register = vi.fn().mockResolvedValue({
      active: null,
      installing: worker,
      waiting: null,
      pushManager: { subscribe: vi.fn() },
    });
    vi.stubGlobal('navigator', { serviceWorker: { register, ready: NEVER } });
    apiFetchMock.mockResolvedValueOnce({ publicKey: VALID_KEY });

    const p = subscribe();
    // deixa o registerSw chegar no addEventListener antes de disparar
    await new Promise((r) => setTimeout(r, 0));
    worker.state = 'redundant';
    listeners.forEach((cb) => cb());

    await expect(p).rejects.toThrow(/falhou ao instalar/i);
  });
});

describe('unsubscribe', () => {
  beforeEach(() => {
    apiFetchMock.mockReset();
    vi.stubGlobal('window', {
      PushManager: class {},
      guardkidsApi: { swUrl: 'https://site.test/plugins/dist/sw.js' },
    });
  });

  afterEach(() => vi.unstubAllGlobals());

  it('cancela no browser e avisa a API', async () => {
    const subUnsubscribe = vi.fn().mockResolvedValue(true);
    vi.stubGlobal('navigator', {
      serviceWorker: {
        ready: NEVER,
        getRegistration: vi.fn().mockResolvedValue({
          pushManager: {
            getSubscription: vi.fn().mockResolvedValue({
              endpoint: 'https://fcm/x',
              unsubscribe: subUnsubscribe,
            }),
          },
        }),
      },
    });

    await unsubscribe();

    expect(subUnsubscribe).toHaveBeenCalled();
    expect(apiFetchMock).toHaveBeenCalledWith('/guardian/push/unsubscribe', {
      method: 'POST',
      body: JSON.stringify({ endpoint: 'https://fcm/x' }),
    });
  });

  it('não chama a API quando não havia subscription', async () => {
    vi.stubGlobal('navigator', {
      serviceWorker: {
        ready: NEVER,
        getRegistration: vi.fn().mockResolvedValue({
          pushManager: { getSubscription: vi.fn().mockResolvedValue(null) },
        }),
      },
    });

    await unsubscribe();

    expect(apiFetchMock).not.toHaveBeenCalled();
  });
});
