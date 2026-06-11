import { apiFetch } from './client';
import type { Guardian, GuardianRole, GuardianWithInvite } from './types';

export type CreateGuardianInput = {
  name: string;
  email: string;
  role: GuardianRole;
};

export function listGuardians(): Promise<Guardian[]> {
  return apiFetch<Guardian[]>('/guardians');
}

export function createGuardian(input: CreateGuardianInput): Promise<GuardianWithInvite> {
  return apiFetch<GuardianWithInvite>('/guardians', {
    method: 'POST',
    body: JSON.stringify(input),
  });
}

export function resendInvite(id: number): Promise<GuardianWithInvite> {
  return apiFetch<GuardianWithInvite>(`/guardians/${id}/resend`, { method: 'POST' });
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
