import { apiFetch } from './client';

export type ContentSummary = {
  contents: number;
  categories: number;
  favorites: number;
  recommendations: number;
  pendingCount: number;
  lastSync: string | null;
};

export function getContentSummary(): Promise<ContentSummary> {
  return apiFetch<ContentSummary>('/content/summary');
}

export type Content = {
  id: number;
  categoryId: number | null;
  title: string;
  description: string | null;
  url: string | null;
  thumbnail: string | null;
  type: string;
  ageMin: number;
  ageMax: number;
  estimatedMinutes: number | null;
  level: string | null;
  tags: string | null;
  status: 'pending' | 'approved';
};

export type ContentCategory = { id: number; slug: string; name: string; icon: string | null; description: string | null };

export type ContentAnalytics = {
  mostAccessed: { contentId: number; title: string; opens: number }[];
  favoriteCategories: { category: string; opens: number }[];
  timePerCategory: { category: string; minutes: number }[];
};

export type Recommendation = { id: number; childId: number; contentId: number; note: string | null; createdAt: string | null };

export type ContentInput = {
  title: string;
  description?: string;
  categoryId?: number;
  ageMin: number;
  ageMax: number;
  url?: string;
  thumbnail?: string;
  estimatedMinutes?: number;
  level?: string;
  tags?: string;
};

export function listContents(
  category = 0,
  search = '',
  status: '' | 'pending' | 'approved' = '',
): Promise<Content[]> {
  const params = new URLSearchParams();
  if (category > 0) params.set('category', String(category));
  if (search) params.set('search', search);
  if (status) params.set('status', status);
  const qs = params.toString();
  return apiFetch<Content[]>(`/content${qs ? `?${qs}` : ''}`);
}

export function listContentCategories(): Promise<ContentCategory[]> {
  return apiFetch<ContentCategory[]>('/content/categories');
}

export function createContent(input: ContentInput): Promise<Content> {
  return apiFetch<Content>('/content', { method: 'POST', body: JSON.stringify(input) });
}

export function updateContent(id: number, input: ContentInput): Promise<Content> {
  return apiFetch<Content>(`/content/${id}`, { method: 'PUT', body: JSON.stringify(input) });
}

export function deleteContent(id: number): Promise<{ deleted: boolean }> {
  return apiFetch<{ deleted: boolean }>(`/content/${id}`, { method: 'DELETE' });
}

export function approveContent(id: number): Promise<Content> {
  return apiFetch<Content>(`/content/${id}/approve`, { method: 'POST' });
}

export function revokeContent(id: number): Promise<Content> {
  return apiFetch<Content>(`/content/${id}/revoke`, { method: 'POST' });
}

export function getAnalytics(): Promise<ContentAnalytics> {
  return apiFetch<ContentAnalytics>('/content/analytics');
}

export function listRecommendations(childId: number): Promise<Recommendation[]> {
  return apiFetch<Recommendation[]>(`/content/recommendations?child_id=${childId}`);
}

export function createRecommendation(childId: number, contentId: number, note = ''): Promise<{ id: number }> {
  return apiFetch<{ id: number }>('/content/recommendations', {
    method: 'POST',
    body: JSON.stringify({ child_id: childId, content_id: contentId, note }),
  });
}

export function deleteRecommendation(id: number): Promise<{ deleted: boolean }> {
  return apiFetch<{ deleted: boolean }>(`/content/recommendations/${id}`, { method: 'DELETE' });
}

export function reorderRecommendations(childId: number, ids: number[]): Promise<{ ok: boolean }> {
  return apiFetch<{ ok: boolean }>('/content/recommendations/reorder', {
    method: 'POST',
    body: JSON.stringify({ child_id: childId, ids }),
  });
}
