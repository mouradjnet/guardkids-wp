import { beforeEach, describe, expect, it, vi } from 'vitest';

const { apiFetchMock } = vi.hoisted(() => ({ apiFetchMock: vi.fn() }));
vi.mock('./client', () => ({
  apiFetch: apiFetchMock,
  ApiError: class ApiError extends Error {},
}));

import { listCategories, updateCategoryBlocked } from './categories';

describe('api/categories', () => {
  beforeEach(() => {
    apiFetchMock.mockReset().mockResolvedValue(undefined);
  });

  it('listCategories GETs /categories', async () => {
    await listCategories();
    expect(apiFetchMock).toHaveBeenCalledWith('/categories');
  });

  it('updateCategoryBlocked PATCHes /categories/{id} with blocked flag', async () => {
    await updateCategoryBlocked(5, true);
    expect(apiFetchMock).toHaveBeenCalledWith('/categories/5', {
      method: 'PATCH',
      body: JSON.stringify({ blocked: true }),
    });
  });

  it('updateCategoryBlocked sends blocked: false', async () => {
    await updateCategoryBlocked(5, false);
    expect(apiFetchMock).toHaveBeenCalledWith('/categories/5', {
      method: 'PATCH',
      body: JSON.stringify({ blocked: false }),
    });
  });
});
