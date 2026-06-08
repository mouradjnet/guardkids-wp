import { vi } from 'vitest';
import type { LicenseSnapshot } from '../api/license';
import { PREMIUM_FEATURES } from '../hooks/useLicense';

/**
 * Mocks compartilhados pra `api/license` — usar em tests de páginas premium
 * onde a licença não é o foco (mas precisa estar "ativa" pra não bloquear).
 *
 * Pra teste de bloqueio explícito, monte o snapshot manualmente.
 */

export const ACTIVE_PREMIUM_SNAPSHOT: LicenseSnapshot = {
  plan: 'premium',
  status: 'active',
  features: [...PREMIUM_FEATURES],
  expiresAt: '2027-12-31T00:00:00Z',
  daysLeft: 365,
  email: 'djair@example.test',
  activatedAt: '2026-06-08 14:00:00',
  upgradeUrl: 'https://comprar.example.com',
};

export const FREE_NONE_SNAPSHOT: LicenseSnapshot = {
  plan: 'free',
  status: 'none',
  features: [],
  expiresAt: null,
  daysLeft: null,
  email: null,
  activatedAt: null,
  upgradeUrl: 'https://comprar.example.com',
};

/** Use em vi.mock junto com vi.hoisted, retornando o fn pra ser configurado. */
export function makeLicenseMock(defaultSnapshot: LicenseSnapshot = ACTIVE_PREMIUM_SNAPSHOT) {
  return vi.fn().mockResolvedValue(defaultSnapshot);
}
