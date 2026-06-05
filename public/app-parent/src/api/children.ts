import { apiFetch } from './client';
import type { Child } from './types';

export type CreateChildInput = {
  name: string;
  age?: number | null;
  device?: string | null;
  limit_minutes?: number;
};

export type UpdateChildInput = Partial<{
  name: string;
  age: number | null;
  avatar_url: string | null;
  device: string | null;
  limit_minutes: number;
}>;

export function listChildren(): Promise<Child[]> {
  return apiFetch<Child[]>('/children');
}

export function createChild(input: CreateChildInput): Promise<Child> {
  return apiFetch<Child>('/children', {
    method: 'POST',
    body: JSON.stringify(input),
  });
}

export function updateChild(id: number, input: UpdateChildInput): Promise<Child> {
  return apiFetch<Child>(`/children/${id}`, {
    method: 'PATCH',
    body: JSON.stringify(input),
  });
}
