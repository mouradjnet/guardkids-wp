import { afterEach, describe, expect, it, vi } from 'vitest';
import { listNotifications, markNotificationsRead } from './child';

const apiFetch = vi.fn();
vi.mock('./client', () => ({
  apiFetch: (...args: unknown[]) => apiFetch(...args),
}));

describe('notifications api', () => {
  afterEach(() => apiFetch.mockReset());

  it('listNotifications faz GET /child/notifications', async () => {
    apiFetch.mockResolvedValueOnce([]);
    await listNotifications();
    expect(apiFetch).toHaveBeenCalledWith('/child/notifications');
  });

  it('markNotificationsRead faz POST /child/notifications/read', async () => {
    apiFetch.mockResolvedValueOnce({ updated: 2 });
    await markNotificationsRead();
    expect(apiFetch).toHaveBeenCalledWith('/child/notifications/read', { method: 'POST' });
  });
});
