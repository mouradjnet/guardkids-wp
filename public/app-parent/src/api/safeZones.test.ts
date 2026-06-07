import { beforeEach, describe, expect, it, vi } from 'vitest';

const { apiFetchMock } = vi.hoisted(() => ({ apiFetchMock: vi.fn() }));
vi.mock('./client', () => ({
  apiFetch: apiFetchMock,
  ApiError: class ApiError extends Error {},
}));

import { createSafeZone, deleteSafeZone, listSafeZones, updateSafeZone } from './safeZones';

describe('api/safeZones', () => {
  beforeEach(() => {
    apiFetchMock.mockReset().mockResolvedValue(undefined);
  });

  it('listSafeZones GETs /safe-zones', async () => {
    await listSafeZones();
    expect(apiFetchMock).toHaveBeenCalledWith('/safe-zones');
  });

  it('createSafeZone POSTs /safe-zones with JSON body', async () => {
    await createSafeZone({
      name: 'Casa',
      address: 'Rua X',
      latitude: -8.05,
      longitude: -34.88,
      radius_meters: 100,
    });
    expect(apiFetchMock).toHaveBeenCalledWith('/safe-zones', {
      method: 'POST',
      body: JSON.stringify({
        name: 'Casa',
        address: 'Rua X',
        latitude: -8.05,
        longitude: -34.88,
        radius_meters: 100,
      }),
    });
  });

  it('updateSafeZone PUTs /safe-zones/{id}', async () => {
    await updateSafeZone(5, {
      name: 'Escola',
      latitude: -8.06,
      longitude: -34.89,
      radius_meters: 200,
    });
    expect(apiFetchMock).toHaveBeenCalledWith('/safe-zones/5', {
      method: 'PUT',
      body: JSON.stringify({
        name: 'Escola',
        latitude: -8.06,
        longitude: -34.89,
        radius_meters: 200,
      }),
    });
  });

  it('deleteSafeZone DELETEs /safe-zones/{id}', async () => {
    await deleteSafeZone(9);
    expect(apiFetchMock).toHaveBeenCalledWith('/safe-zones/9', {
      method: 'DELETE',
    });
  });
});
