import { apiFetch, ApiError, authHeaders } from './client';
import type { Child } from './types';

type WpMediaResponse = {
  id: number;
  source_url: string;
};

/**
 * Upload pro Media Library do WP. Retorna o source_url pra salvar como
 * `avatar_url` do filho. Não usa o apiFetch (que prefixa guardkids/v1) —
 * Media Library vive em /wp/v2/media.
 */
export async function uploadAvatar(file: File): Promise<string> {
  const res = await fetch('/wp-json/wp/v2/media', {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      ...authHeaders(),
      'Content-Disposition': `attachment; filename="${file.name.replace(/"/g, '')}"`,
      'Content-Type': file.type,
    },
    body: file,
  });
  if (!res.ok) {
    let code = 'upload_failed';
    let message = res.statusText;
    try {
      const body = (await res.json()) as { code?: string; message?: string };
      if (body.code) code = body.code;
      if (body.message) message = body.message;
    } catch {
      // sem JSON; ignora
    }
    throw new ApiError(code, message, res.status);
  }
  const data = (await res.json()) as WpMediaResponse;
  return data.source_url;
}

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
  daily_limit_enabled: boolean;
  bedtime_enabled: boolean;
  bedtime_start: string;
  bedtime_end: string;
  allowed_weekdays: string;
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

export type DeviceTokenResponse = {
  token: string;
  childId: number;
  label: string | null;
  createdAt: string;
  notice: string;
};

export function pairChildDevice(id: number, label?: string): Promise<DeviceTokenResponse> {
  return apiFetch<DeviceTokenResponse>(`/children/${id}/pair`, {
    method: 'POST',
    body: JSON.stringify({ label: label ?? null }),
  });
}

export function pauseChild(id: number): Promise<Child> {
  return apiFetch<Child>(`/children/${id}/pause`, { method: 'POST' });
}

export function resumeChild(id: number): Promise<Child> {
  return apiFetch<Child>(`/children/${id}/resume`, { method: 'POST' });
}

export function deleteChild(id: number): Promise<{ deleted: true; id: number }> {
  return apiFetch<{ deleted: true; id: number }>(`/children/${id}`, {
    method: 'DELETE',
  });
}
