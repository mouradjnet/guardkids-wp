import { beforeEach, describe, expect, it, vi } from 'vitest';

const { apiFetchMock } = vi.hoisted(() => ({ apiFetchMock: vi.fn() }));
vi.mock('./client', () => ({
  apiFetch: apiFetchMock,
  ApiError: class ApiError extends Error {},
}));

import { geocodeAddress } from './geocode';

describe('api/geocode', () => {
  beforeEach(() => {
    apiFetchMock.mockReset();
  });

  it('GETs /geocode with the encoded query and returns lat/lng/displayName', async () => {
    apiFetchMock.mockResolvedValue({
      lat: -8.05,
      lng: -34.88,
      displayName: 'Recife, PE',
    });

    const result = await geocodeAddress('Rua da Aurora, Recife');

    expect(apiFetchMock).toHaveBeenCalledWith(
      '/geocode?q=Rua%20da%20Aurora%2C%20Recife',
    );
    expect(result).toEqual({ lat: -8.05, lng: -34.88, displayName: 'Recife, PE' });
  });
});
