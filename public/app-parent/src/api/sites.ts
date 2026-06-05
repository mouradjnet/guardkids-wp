import { apiFetch } from './client';
import type { Site, SiteListType } from './types';

export type ListSitesFilter = SiteListType | 'all';

export type CreateSiteInput = {
  domain: string;
  list_type: SiteListType;
  category?: string | null;
  applies_to?: number[];
};

export function listSites(filter: ListSitesFilter = 'all'): Promise<Site[]> {
  return apiFetch<Site[]>(`/sites?list=${filter}`);
}

export function createSite(input: CreateSiteInput): Promise<Site> {
  return apiFetch<Site>('/sites', {
    method: 'POST',
    body: JSON.stringify(input),
  });
}

export function deleteSite(id: number): Promise<{ deleted: true; id: number }> {
  return apiFetch<{ deleted: true; id: number }>(`/sites/${id}`, { method: 'DELETE' });
}
