import { apiFetch } from './client';

/** Building block: o filho favorita um conteúdo (não wired na UI no Sprint 1). */
export function addFavorite(contentId: number): Promise<{ id: number }> {
  return apiFetch<{ id: number }>('/content/favorites', {
    method: 'POST',
    body: JSON.stringify({ content_id: contentId }),
  });
}
