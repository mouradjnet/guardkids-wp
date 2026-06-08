import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { LicenseSnapshot } from '../api/license';
import { PREMIUM_FEATURES, useLicense } from './useLicense';

const { getLicenseMock } = vi.hoisted(() => ({ getLicenseMock: vi.fn() }));
vi.mock('../api/license', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../api/license')>();
  return {
    ...actual,
    getLicense: getLicenseMock,
  };
});

function wrapper() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0 } },
  });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
}

const ACTIVE_PREMIUM: LicenseSnapshot = {
  plan: 'premium',
  status: 'active',
  features: [...PREMIUM_FEATURES],
  expiresAt: '2027-12-31T00:00:00Z',
  daysLeft: 365,
  email: 'djair@example.test',
  activatedAt: '2026-06-08 14:00:00',
  upgradeUrl: 'https://comprar.example.com',
};

describe('useLicense', () => {
  beforeEach(() => {
    getLicenseMock.mockReset();
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  it('expõe free fallback enquanto carrega', () => {
    getLicenseMock.mockImplementation(() => new Promise(() => {}));
    const { result } = renderHook(useLicense, { wrapper: wrapper() });
    expect(result.current.isLoading).toBe(true);
    expect(result.current.plan).toBe('free');
    expect(result.current.status).toBe('none');
    expect(result.current.snapshot).toBeNull();
  });

  it('quando carregando, can() retorna passthrough (assume premium pra não piscar)', () => {
    // Decisão de UX: enquanto carrega, NÃO bloqueia. Veja PremiumLock.tsx.
    getLicenseMock.mockImplementation(() => new Promise(() => {}));
    const { result } = renderHook(useLicense, { wrapper: wrapper() });
    // PremiumLock testa isLoading; useLicense.can() em si segue o snapshot atual.
    // Aqui, com FREE_FALLBACK, premium features são bloqueadas — esse é o
    // comportamento correto do hook isoladamente.
    expect(result.current.can('browser')).toBe(false);
  });

  it('com licença premium ativa, libera todas as PREMIUM_FEATURES', async () => {
    getLicenseMock.mockResolvedValueOnce(ACTIVE_PREMIUM);
    const { result } = renderHook(useLicense, { wrapper: wrapper() });
    await waitFor(() => expect(result.current.isLoading).toBe(false));
    expect(result.current.plan).toBe('premium');
    expect(result.current.status).toBe('active');
    for (const f of PREMIUM_FEATURES) {
      expect(result.current.can(f)).toBe(true);
    }
  });

  it('features fora da lista premium são sempre liberadas', async () => {
    getLicenseMock.mockResolvedValueOnce({
      ...ACTIVE_PREMIUM,
      status: 'none',
      plan: 'free',
      features: [],
    });
    const { result } = renderHook(useLicense, { wrapper: wrapper() });
    await waitFor(() => expect(result.current.isLoading).toBe(false));
    expect(result.current.can('blacklist_basic')).toBe(true);
    expect(result.current.can('time_basic')).toBe(true);
  });

  it('licença expirada bloqueia premium mas mantém snapshot pra UI', async () => {
    getLicenseMock.mockResolvedValueOnce({
      ...ACTIVE_PREMIUM,
      status: 'expired',
      plan: 'free',
      daysLeft: 0,
    });
    const { result } = renderHook(useLicense, { wrapper: wrapper() });
    await waitFor(() => expect(result.current.isLoading).toBe(false));
    expect(result.current.can('browser')).toBe(false);
    expect(result.current.snapshot).not.toBeNull();
    expect(result.current.daysLeft).toBe(0);
    expect(result.current.email).toBe('djair@example.test');
  });

  it('premium parcial libera apenas as features no payload', async () => {
    getLicenseMock.mockResolvedValueOnce({
      ...ACTIVE_PREMIUM,
      features: ['browser', 'categories'],
    });
    const { result } = renderHook(useLicense, { wrapper: wrapper() });
    await waitFor(() => expect(result.current.isLoading).toBe(false));
    expect(result.current.can('browser')).toBe(true);
    expect(result.current.can('categories')).toBe(true);
    expect(result.current.can('reports')).toBe(false);
    expect(result.current.can('location')).toBe(false);
  });

  it('marca isError quando a query falha', async () => {
    getLicenseMock.mockRejectedValueOnce(new Error('500'));
    const { result } = renderHook(useLicense, { wrapper: wrapper() });
    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(result.current.plan).toBe('free'); // degrada silenciosamente
  });
});
