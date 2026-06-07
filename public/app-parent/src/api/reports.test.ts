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
});
