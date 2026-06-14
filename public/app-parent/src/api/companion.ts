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
};

export function getCompanionStatus(childId: number): Promise<CompanionStatus> {
  return apiFetch<CompanionStatus>(`/companion/status?child_id=${childId}`);
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
