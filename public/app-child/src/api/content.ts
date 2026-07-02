import { apiFetch } from './client';
import type { Content } from './types';

export type LibraryCategory = { id: number; slug: string; name: string; icon: string | null; count: number };
export type ChildRecommendation = { id: number; note: string | null; content: Content };

export function browseLibrary(category = 0, search = ''): Promise<Content[]> {
  const params = new URLSearchParams();
  if (category > 0) params.set('category', String(category));
  if (search) params.set('search', search);
  const qs = params.toString();
  return apiFetch<Content[]>(`/child/library${qs ? `?${qs}` : ''}`);
}

export function listLibraryCategories(): Promise<LibraryCategory[]> {
  return apiFetch<LibraryCategory[]>('/child/library/categories');
}

export function listChildRecommendations(): Promise<ChildRecommendation[]> {
  return apiFetch<ChildRecommendation[]>('/child/library/recommendations');
}

export function listChildFavorites(): Promise<Content[]> {
  return apiFetch<Content[]>('/child/library/favorites');
}

export function addFavorite(contentId: number): Promise<{ ok: boolean }> {
  return apiFetch<{ ok: boolean }>('/child/library/favorites', {
    method: 'POST',
    body: JSON.stringify({ content_id: contentId }),
  });
}

export function removeFavorite(contentId: number): Promise<{ ok: boolean }> {
  return apiFetch<{ ok: boolean }>(`/child/library/favorites/${contentId}`, { method: 'DELETE' });
}

export function recordHistory(contentId: number, action: 'open' | 'close', durationSeconds = 0): Promise<{ ok: boolean }> {
  return apiFetch<{ ok: boolean }>('/child/library/history', {
    method: 'POST',
    body: JSON.stringify({ content_id: contentId, action, duration_seconds: durationSeconds }),
  });
}
