import { apiFetch } from './client';
import type { SafeZone } from './types';

export type SafeZoneInput = {
  name: string;
  address?: string | null;
  latitude: number;
  longitude: number;
  radius_meters: number;
};

export function listSafeZones(): Promise<SafeZone[]> {
  return apiFetch<SafeZone[]>('/safe-zones');
}

export function createSafeZone(input: SafeZoneInput): Promise<SafeZone> {
  return apiFetch<SafeZone>('/safe-zones', {
    method: 'POST',
    body: JSON.stringify(input),
  });
}

export function updateSafeZone(id: number, input: SafeZoneInput): Promise<SafeZone> {
  return apiFetch<SafeZone>(`/safe-zones/${id}`, {
    method: 'PUT',
    body: JSON.stringify(input),
  });
}

export function deleteSafeZone(id: number): Promise<void> {
  return apiFetch<void>(`/safe-zones/${id}`, {
    method: 'DELETE',
  });
}
