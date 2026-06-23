import { beforeEach, describe, expect, it, vi } from 'vitest';

const { apiFetchMock } = vi.hoisted(() => ({ apiFetchMock: vi.fn() }));
vi.mock('./client', () => ({
  apiFetch: apiFetchMock,
  ApiError: class ApiError extends Error {},
}));

import { clearHistory, deleteAllData, exportData } from './privacy';

describe('api/privacy', () => {
  beforeEach(() => {
    apiFetchMock.mockReset().mockResolvedValue(undefined);
  });

  it('exportData GETs /privacy/export', async () => {
    await exportData();
    expect(apiFetchMock).toHaveBeenCalledWith('/privacy/export');
  });

  it('clearHistory POSTs /privacy/clear-history', async () => {
    await clearHistory();
    expect(apiFetchMock).toHaveBeenCalledWith('/privacy/clear-history', { method: 'POST' });
  });

  it('deleteAllData POSTs /privacy/delete-all with confirm body', async () => {
    await deleteAllData('EXCLUIR');
    expect(apiFetchMock).toHaveBeenCalledWith('/privacy/delete-all', {
      method: 'POST',
      body: JSON.stringify({ confirm: 'EXCLUIR' }),
    });
  });
});
