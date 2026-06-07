import { beforeEach, describe, expect, it, vi } from 'vitest';

const { apiFetchMock } = vi.hoisted(() => ({ apiFetchMock: vi.fn() }));
vi.mock('./client', () => ({
  apiFetch: apiFetchMock,
  ApiError: class ApiError extends Error {},
}));

import { getReport } from './reports';

describe('api/reports', () => {
  beforeEach(() => {
    apiFetchMock.mockReset().mockResolvedValue(undefined);
  });

  it('getReport defaults to range=week', async () => {
    await getReport();
    expect(apiFetchMock).toHaveBeenCalledWith('/reports?range=week');
  });

  it('getReport passes range=month', async () => {
    await getReport('month');
    expect(apiFetchMock).toHaveBeenCalledWith('/reports?range=month');
  });

  it('getReport ignora child_id=0 (todos os filhos)', async () => {
    await getReport('week', 0);
    expect(apiFetchMock).toHaveBeenCalledWith('/reports?range=week');
  });

  it('getReport inclui child_id quando > 0', async () => {
    await getReport('month', 7);
    expect(apiFetchMock).toHaveBeenCalledWith('/reports?range=month&child_id=7');
  });
});
