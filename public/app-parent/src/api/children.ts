import { apiFetch } from './client';
import type { Child } from './types';

export function listChildren(): Promise<Child[]> {
  return apiFetch<Child[]>('/children');
}
