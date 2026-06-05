import { apiFetch } from './client';

export type SettingsBag = Record<string, unknown>;

export function listSettings(): Promise<SettingsBag> {
  return apiFetch<SettingsBag>('/settings');
}

export function updateSettings(patch: SettingsBag): Promise<SettingsBag> {
  return apiFetch<SettingsBag>('/settings', {
    method: 'PATCH',
    body: JSON.stringify(patch),
  });
}
