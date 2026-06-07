import { beforeEach, describe, expect, it, vi } from 'vitest';

const { apiFetchMock } = vi.hoisted(() => ({ apiFetchMock: vi.fn() }));
vi.mock('./client', () => ({
  apiFetch: apiFetchMock,
  ApiError: class ApiError extends Error {},
}));

import { listLocations } from './locations';

describe('api/locations', () => {
  beforeEach(() => {
    apiFetchMock.mockReset().mockResolvedValue([]);
  });

  it('listLocations GETs /locations with child_id and default limit=1', async () => {
    await listLocations(7);
    expect(apiFetchMock).toHaveBeenCalledWith('/locations?child_id=7&limit=1');
  });

  it('listLocations passes custom limit', async () => {
    await listLocations(7, 50);
    expect(apiFetchMock).toHaveBeenCalledWith('/locations?child_id=7&limit=50');
  });
});
