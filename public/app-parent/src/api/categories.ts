import { apiFetch } from './client';
import type { Category } from './types';

export function listCategories(): Promise<Category[]> {
  return apiFetch<Category[]>('/categories');
}

export function updateCategoryBlocked(id: number, blocked: boolean): Promise<Category> {
  return apiFetch<Category>(`/categories/${id}`, {
    method: 'PATCH',
    body: JSON.stringify({ blocked }),
  });
}
