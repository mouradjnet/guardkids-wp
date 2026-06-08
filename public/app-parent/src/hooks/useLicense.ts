import { useQuery } from '@tanstack/react-query';
import {
  getLicense,
  type LicenseSnapshot,
  type LicenseStatus,
} from '../api/license';

/**
 * Source of truth do client de quais features exigem premium.
 * MANTER sincronizado com `GuardKids\License\Gate::PREMIUM_FEATURES` no PHP.
 */
export const PREMIUM_FEATURES = [
  'browser',
  'categories',
  'schedule',
  'reports',
  'location',
  'unlimited_kids',
  'full_history',
] as const;
export type PremiumFeature = (typeof PREMIUM_FEATURES)[number];

export type UseLicenseResult = {
  isLoading: boolean;
  isError: boolean;
  plan: 'free' | 'premium';
  status: LicenseStatus;
  features: string[];
  daysLeft: number | null;
  expiresAt: string | null;
  email: string | null;
  activatedAt: string | null;
  upgradeUrl: string | null;
  /** True se a feature é livre ou se o status atual desbloqueia. */
  can: (featureId: string) => boolean;
  /** Snapshot bruto, ou null enquanto carrega / em erro. */
  snapshot: LicenseSnapshot | null;
};

const FREE_FALLBACK: LicenseSnapshot = {
  plan: 'free',
  status: 'none',
  features: [],
  expiresAt: null,
  daysLeft: null,
  email: null,
  activatedAt: null,
  upgradeUrl: null,
};

export function useLicense(): UseLicenseResult {
  const query = useQuery({ queryKey: ['license'], queryFn: getLicense });
  const snapshot = query.data ?? null;
  const data = query.data ?? FREE_FALLBACK;

  return {
    isLoading: query.isLoading,
    isError: query.isError,
    plan: data.plan,
    status: data.status,
    features: data.features,
    daysLeft: data.daysLeft,
    expiresAt: data.expiresAt,
    email: data.email,
    activatedAt: data.activatedAt,
    upgradeUrl: data.upgradeUrl,
    can: (featureId) => canUse(data, featureId),
    snapshot,
  };
}

function canUse(snap: LicenseSnapshot, featureId: string): boolean {
  if (!isPremiumFeature(featureId)) {
    return true;
  }
  if (snap.status !== 'active') {
    return false;
  }
  return snap.features.includes(featureId);
}

function isPremiumFeature(id: string): id is PremiumFeature {
  return (PREMIUM_FEATURES as readonly string[]).includes(id);
}
