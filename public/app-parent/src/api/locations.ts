import { apiFetch } from './client';
import type { LocationFix } from './types';

export function listLocations(childId: number, limit = 1): Promise<LocationFix[]> {
  const params = new URLSearchParams({ child_id: String(childId), limit: String(limit) });
  return apiFetch<LocationFix[]>(`/locations?${params.toString()}`);
}
