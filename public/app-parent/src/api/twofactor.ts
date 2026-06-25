import { apiFetch } from './client';

export type TwoFactorStatus = { enabled: boolean; recoveryRemaining: number };
export type TwoFactorSetup = { secret: string; otpauthUri: string };
export type TwoFactorActivated = { enabled: true; recoveryCodes: string[] };

export function getTwoFactorStatus(): Promise<TwoFactorStatus> {
  return apiFetch<TwoFactorStatus>('/security/2fa');
}

export function setupTwoFactor(): Promise<TwoFactorSetup> {
  return apiFetch<TwoFactorSetup>('/security/2fa/setup', { method: 'POST' });
}

export function activateTwoFactor(code: string): Promise<TwoFactorActivated> {
  return apiFetch<TwoFactorActivated>('/security/2fa/activate', {
    method: 'POST',
    body: JSON.stringify({ code }),
  });
}

export function regenerateRecoveryCodes(code: string): Promise<{ recoveryCodes: string[] }> {
  return apiFetch<{ recoveryCodes: string[] }>('/security/2fa/recovery-codes', {
    method: 'POST',
    body: JSON.stringify({ code }),
  });
}

export function disableTwoFactor(code: string): Promise<{ enabled: false }> {
  return apiFetch<{ enabled: false }>('/security/2fa', {
    method: 'DELETE',
    body: JSON.stringify({ code }),
  });
}
