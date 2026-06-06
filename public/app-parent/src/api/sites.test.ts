import { beforeEach, describe, expect, it, vi } from 'vitest';

const { apiFetchMock } = vi.hoisted(() => ({ apiFetchMock: vi.fn() }));
vi.mock('./client', () => ({
  apiFetch: apiFetchMock,
  ApiError: class ApiError extends Error {},
}));

import { createSite, deleteSite, listSites } from './sites';

describe('api/sites', () => {
  beforeEach(() => {
    apiFetchMock.mockReset().mockResolvedValue(undefined);
  });

  it('listSites defaults to filter=all', async () => {
    await listSites();
    expect(apiFetchMock).toHaveBeenCalledWith('/sites?list=all');
  });

  it('listSites passes whitelist filter via query', async () => {
    await listSites('whitelist');
    expect(apiFetchMock).toHaveBeenCalledWith('/sites?list=whitelist');
  });

  it('listSites passes blacklist filter via query', async () => {
    await listSites('blacklist');
    expect(apiFetchMock).toHaveBeenCalledWith('/sites?list=blacklist');
  });

  it('createSite POSTs /sites with JSON body', async () => {
    await createSite({ domain: 'youtube.com', list_type: 'whitelist', applies_to: [1, 2] });
    expect(apiFetchMock).toHaveBeenCalledWith('/sites', {
      method: 'POST',
      body: JSON.stringify({ domain: 'youtube.com', list_type: 'whitelist', applies_to: [1, 2] }),
    });
  });

  it('deleteSite DELETEs /sites/{id}', async () => {
    await deleteSite(42);
    expect(apiFetchMock).toHaveBeenCalledWith('/sites/42', { method: 'DELETE' });
  });
});
