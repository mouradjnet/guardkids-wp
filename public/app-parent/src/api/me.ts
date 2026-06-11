import { apiFetch } from './client';

export type CurrentRole = 'admin' | 'collaborator' | null;

export type Me = {
  role: CurrentRole;
  email: string;
  name: string;
};

export function getMe(): Promise<Me> {
  return apiFetch<Me>('/me');
}
