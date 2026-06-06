import { beforeEach, describe, expect, it, vi } from 'vitest';

const { apiFetchMock } = vi.hoisted(() => ({ apiFetchMock: vi.fn() }));
vi.mock('./client', () => ({
  apiFetch: apiFetchMock,
  ApiError: class ApiError extends Error {},
}));

import { createChild, listChildren, pairChildDevice, updateChild } from './children';

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
});
