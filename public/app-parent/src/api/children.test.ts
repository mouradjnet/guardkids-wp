import { beforeEach, describe, expect, it, vi } from 'vitest';

const { apiFetchMock, fetchOrExplainMock } = vi.hoisted(() => ({
  apiFetchMock: vi.fn(),
  fetchOrExplainMock: vi.fn(),
}));
vi.mock('./client', () => ({
  apiFetch: apiFetchMock,
  ApiError: class ApiError extends Error {},
  authHeaders: () => ({}),
  fetchOrExplain: fetchOrExplainMock,
}));

import { createChild, listChildren, pairChildDevice, updateChild, uploadAvatar } from './children';

describe('api/children', () => {
  beforeEach(() => {
    apiFetchMock.mockReset().mockResolvedValue(undefined);
  });

  it('listChildren GETs /children', async () => {
    await listChildren();
    expect(apiFetchMock).toHaveBeenCalledWith('/children');
  });

  it('createChild POSTs /children with JSON body', async () => {
    await createChild({ name: 'Lucas', age: 9, limit_minutes: 60 });
    expect(apiFetchMock).toHaveBeenCalledWith('/children', {
      method: 'POST',
      body: JSON.stringify({ name: 'Lucas', age: 9, limit_minutes: 60 }),
    });
  });

  it('updateChild PATCHes /children/{id} with JSON body', async () => {
    await updateChild(7, { limit_minutes: 120 });
    expect(apiFetchMock).toHaveBeenCalledWith('/children/7', {
      method: 'PATCH',
      body: JSON.stringify({ limit_minutes: 120 }),
    });
  });

  it('pairChildDevice POSTs /children/{id}/pair with label', async () => {
    await pairChildDevice(3, 'Tablet sala');
    expect(apiFetchMock).toHaveBeenCalledWith('/children/3/pair', {
      method: 'POST',
      body: JSON.stringify({ label: 'Tablet sala' }),
    });
  });

  it('pairChildDevice sends label: null when omitted', async () => {
    await pairChildDevice(3);
    expect(apiFetchMock).toHaveBeenCalledWith('/children/3/pair', {
      method: 'POST',
      body: JSON.stringify({ label: null }),
    });
  });

  // uploadAvatar não passa pelo apiFetch (Media Library vive em /wp/v2), então
  // precisa pedir o fetch explicado explicitamente — senão a falha de rede volta
  // a chegar na tela como "Failed to fetch".
  it('uploadAvatar sobe pelo fetch que explica falha de rede', async () => {
    fetchOrExplainMock.mockResolvedValue(
      new Response(JSON.stringify({ id: 1, source_url: 'http://wp.test/a.png' }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    const url = await uploadAvatar(new File(['x'], 'a.png', { type: 'image/png' }));

    expect(url).toBe('http://wp.test/a.png');
    expect(fetchOrExplainMock).toHaveBeenCalledWith('/wp-json/wp/v2/media', expect.any(Object));
  });
});
