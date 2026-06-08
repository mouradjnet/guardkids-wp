import { beforeEach, describe, expect, it, vi } from 'vitest';

const { apiFetchMock } = vi.hoisted(() => ({ apiFetchMock: vi.fn() }));
vi.mock('./client', () => ({
  apiFetch: apiFetchMock,
  ApiError: class ApiError extends Error {},
}));

import { activateLicense, deactivateLicense, getLicense } from './license';

describe('api/license', () => {
  beforeEach(() => {
    apiFetchMock.mockReset().mockResolvedValue(undefined);
  });

  it('getLicense faz GET /license sem body', async () => {
    await getLicense();
    expect(apiFetchMock).toHaveBeenCalledWith('/license');
  });

  it('activateLicense POST com {key} no body', async () => {
    await activateLicense('chave-fake-123');
    expect(apiFetchMock).toHaveBeenCalledWith('/license', {
      method: 'POST',
      body: JSON.stringify({ key: 'chave-fake-123' }),
    });
  });

  it('deactivateLicense faz DELETE sem body', async () => {
    await deactivateLicense();
    expect(apiFetchMock).toHaveBeenCalledWith('/license', { method: 'DELETE' });
  });
});
