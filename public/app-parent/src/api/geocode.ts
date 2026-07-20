import { apiFetch } from './client';

export type GeocodeResult = { lat: number; lng: number; displayName: string };

export function geocodeAddress(query: string): Promise<GeocodeResult> {
  return apiFetch<GeocodeResult>(`/geocode?q=${encodeURIComponent(query)}`);
}
