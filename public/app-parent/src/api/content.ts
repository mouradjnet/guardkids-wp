import { apiFetch } from './client';

export type ContentSummary = {
  contents: number;
  categories: number;
  favorites: number;
  recommendations: number;
  lastSync: string | null;
};

export function getContentSummary(): Promise<ContentSummary> {
  return apiFetch<ContentSummary>('/content/summary');
}
