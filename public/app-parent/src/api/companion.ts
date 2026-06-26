import { apiFetch } from './client';

export type ProtectionMode = 'family' | 'maximum';

export function getProtectionMode(): Promise<{ mode: ProtectionMode }> {
  return apiFetch<{ mode: ProtectionMode }>('/protection-mode');
}

export function setProtectionMode(mode: ProtectionMode): Promise<{ mode: ProtectionMode }> {
  return apiFetch<{ mode: ProtectionMode }>('/protection-mode', {
    method: 'POST',
    body: JSON.stringify({ mode }),
  });
}

export type CompanionStatus = {
  paired: boolean;
  status: 'unpaired' | 'pending' | 'active' | string;
  deviceUuid: string | null;
  deviceName: string | null;
  androidVersion: string | null;
  companionVersion: string | null;
  deviceOwnerEnabled: boolean;
  accessibilityEnabled: boolean;
  deviceAdminEnabled: boolean;
  playStoreEnabled: boolean;
  lastSync: string | null;
  installedApps: { packageName: string; label: string }[];
  blockedApps: string[];
};

export function getCompanionStatus(childId: number): Promise<CompanionStatus> {
  return apiFetch<CompanionStatus>(`/companion/status?child_id=${childId}`);
}

export function setBlockedApps(
  childId: number,
  apps: string[],
): Promise<{ blockedApps: string[] }> {
  return apiFetch<{ blockedApps: string[] }>('/companion/blocked-apps', {
    method: 'POST',
    body: JSON.stringify({ child_id: childId, apps }),
  });
}

export type CompanionPairResponse = {
  token: string;
  deviceUuid: string;
  endpoint: string;
  expiresAt: string;
  qrPayload: string;
  notice: string;
};

export function pairCompanion(childId: number): Promise<CompanionPairResponse> {
  return apiFetch<CompanionPairResponse>('/companion/pair', {
    method: 'POST',
    body: JSON.stringify({ child_id: childId }),
  });
}

export function revokeCompanion(childId: number): Promise<{ revoked: boolean }> {
  return apiFetch<{ revoked: boolean }>('/companion/revoke', {
    method: 'POST',
    body: JSON.stringify({ child_id: childId }),
  });
}
