import { apiFetch } from './client';
import type { Guardian, GuardianRole } from './types';

export type CreateGuardianInput = {
  name: string;
  email: string;
  role: GuardianRole;
};

export function listGuardians(): Promise<Guardian[]> {
  return apiFetch<Guardian[]>('/guardians');
}

export function createGuardian(input: CreateGuardianInput): Promise<Guardian> {
  return apiFetch<Guardian>('/guardians', {
    method: 'POST',
    body: JSON.stringify(input),
  });
}

export function updateGuardianRole(id: number, role: GuardianRole): Promise<Guardian> {
  return apiFetch<Guardian>(`/guardians/${id}`, {
    method: 'PATCH',
    body: JSON.stringify({ role }),
  });
}

export function activateGuardian(id: number): Promise<Guardian> {
  return apiFetch<Guardian>(`/guardians/${id}/activate`, { method: 'POST' });
}

export function removeGuardian(id: number): Promise<{ deleted: true; id: number }> {
  return apiFetch<{ deleted: true; id: number }>(`/guardians/${id}`, {
    method: 'DELETE',
  });
}
