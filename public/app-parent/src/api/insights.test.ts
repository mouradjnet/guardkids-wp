import { beforeEach, describe, expect, it, vi } from 'vitest';

const { apiFetchMock } = vi.hoisted(() => ({ apiFetchMock: vi.fn() }));
vi.mock('./client', () => ({
  apiFetch: apiFetchMock,
  ApiError: class ApiError extends Error {},
}));

import { getInsights, refreshInsights } from './insights';

describe('api/insights', () => {
  beforeEach(() => {
    apiFetchMock.mockReset().mockResolvedValue(undefined);
  });

  it('getInsights defaults to range=week sem child_id', async () => {
    await getInsights();
    expect(apiFetchMock).toHaveBeenCalledWith('/insights?range=week');
  });

  it('getInsights inclui child_id quando > 0', async () => {
    await getInsights('month', 7);
    expect(apiFetchMock).toHaveBeenCalledWith('/insights?range=month&child_id=7');
  });

  it('refreshInsights faz POST só com range quando child_id=0', async () => {
    await refreshInsights('week');
    expect(apiFetchMock).toHaveBeenCalledWith('/insights/refresh', {
      method: 'POST',
      body: JSON.stringify({ range: 'week' }),
    });
  });

  it('refreshInsights inclui child_id no corpo quando > 0', async () => {
    await refreshInsights('month', 9);
    expect(apiFetchMock).toHaveBeenCalledWith('/insights/refresh', {
      method: 'POST',
      body: JSON.stringify({ range: 'month', child_id: 9 }),
    });
  });
});
