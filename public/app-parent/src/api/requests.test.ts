import { beforeEach, describe, expect, it, vi } from 'vitest';

const { apiFetchMock } = vi.hoisted(() => ({ apiFetchMock: vi.fn() }));
vi.mock('./client', () => ({
  apiFetch: apiFetchMock,
  ApiError: class ApiError extends Error {},
}));

import { approveRequest, denyRequest, listRequests } from './requests';

describe('api/requests', () => {
  beforeEach(() => {
    apiFetchMock.mockReset().mockResolvedValue(undefined);
  });

  it('listRequests defaults to status=pending', async () => {
    await listRequests();
    expect(apiFetchMock).toHaveBeenCalledWith('/requests?status=pending');
  });

  it('listRequests passes approved filter via query', async () => {
    await listRequests('approved');
    expect(apiFetchMock).toHaveBeenCalledWith('/requests?status=approved');
  });

  it('listRequests passes all filter via query', async () => {
    await listRequests('all');
    expect(apiFetchMock).toHaveBeenCalledWith('/requests?status=all');
  });

  it('approveRequest POSTs /requests/{id}/approve', async () => {
    await approveRequest(7);
    expect(apiFetchMock).toHaveBeenCalledWith('/requests/7/approve', { method: 'POST' });
  });

  it('denyRequest POSTs /requests/{id}/deny', async () => {
    await denyRequest(7);
    expect(apiFetchMock).toHaveBeenCalledWith('/requests/7/deny', { method: 'POST' });
  });
});
