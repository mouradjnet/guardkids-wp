import { beforeEach, describe, expect, it, vi } from 'vitest';

const { apiFetchMock } = vi.hoisted(() => ({ apiFetchMock: vi.fn() }));
vi.mock('./client', () => ({
  apiFetch: apiFetchMock,
  ApiError: class ApiError extends Error {},
}));

import { listSettings, updateSettings } from './settings';

describe('api/settings', () => {
  beforeEach(() => {
    apiFetchMock.mockReset().mockResolvedValue(undefined);
  });

  it('listSettings GETs /settings', async () => {
    await listSettings();
    expect(apiFetchMock).toHaveBeenCalledWith('/settings');
  });

  it('updateSettings PATCHes /settings with bag', async () => {
    await updateSettings({ 'notifications.push': false, 'security.two_fa': true });
    expect(apiFetchMock).toHaveBeenCalledWith('/settings', {
      method: 'PATCH',
      body: JSON.stringify({ 'notifications.push': false, 'security.two_fa': true }),
    });
  });
});
