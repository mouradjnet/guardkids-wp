import { apiFetch } from './client';

export type LicenseStatus =
  | 'none'
  | 'active'
  | 'expired'
  | 'domain_mismatch'
  | 'revoked';

export type LicenseSnapshot = {
  plan: 'free' | 'premium';
  status: LicenseStatus;
  features: string[];
  expiresAt: string | null;
  daysLeft: number | null;
  email: string | null;
  activatedAt: string | null;
  upgradeUrl: string | null;
};

export function getLicense(): Promise<LicenseSnapshot> {
  return apiFetch<LicenseSnapshot>('/license');
}

export function activateLicense(key: string): Promise<LicenseSnapshot> {
  return apiFetch<LicenseSnapshot>('/license', {
    method: 'POST',
    body: JSON.stringify({ key }),
  });
}

export function deactivateLicense(): Promise<LicenseSnapshot> {
  return apiFetch<LicenseSnapshot>('/license', { method: 'DELETE' });
}
