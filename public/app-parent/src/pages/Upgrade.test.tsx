import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { LicenseSnapshot } from '../api/license';
import { PREMIUM_FEATURES } from '../hooks/useLicense';
import { Upgrade } from './Upgrade';

const { getLicenseMock } = vi.hoisted(() => ({ getLicenseMock: vi.fn() }));
vi.mock('../api/license', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../api/license')>();
  return {
    ...actual,
    getLicense: getLicenseMock,
  };
});

function renderInClient(ui: ReactNode) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0 } },
  });
  return render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

const ACTIVE: LicenseSnapshot = {
  plan: 'premium',
  status: 'active',
  features: [...PREMIUM_FEATURES],
  expiresAt: '2027-12-31T00:00:00Z',
  daysLeft: 365,
  email: 'djair@example.test',
  activatedAt: '2026-06-08 14:00:00',
  upgradeUrl: 'https://comprar.example.com',
};

const FREE_WITH_URL: LicenseSnapshot = {
  plan: 'free',
  status: 'none',
  features: [],
  expiresAt: null,
  daysLeft: null,
  email: null,
  activatedAt: null,
  upgradeUrl: 'https://comprar.example.com',
};

const FREE_WITHOUT_URL: LicenseSnapshot = { ...FREE_WITH_URL, upgradeUrl: null };

describe('Upgrade page', () => {
  beforeEach(() => {
    getLicenseMock.mockReset();
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  it('mostra hero de marketing quando user é Free', async () => {
    getLicenseMock.mockResolvedValueOnce(FREE_WITH_URL);
    renderInClient(<Upgrade />);

    expect(
      await screen.findByText(/proteção completa para todos os seus filhos/i),
    ).toBeInTheDocument();
    expect(screen.queryByTestId('upgrade-already-premium')).not.toBeInTheDocument();
  });

  it('mostra banner "Já é Premium" quando ativo, com daysLeft', async () => {
    getLicenseMock.mockResolvedValueOnce(ACTIVE);
    renderInClient(<Upgrade />);

    expect(await screen.findByTestId('upgrade-already-premium')).toBeInTheDocument();
    expect(screen.getByText(/sua licença é válida por mais 365 dias/i)).toBeInTheDocument();
    expect(
      screen.queryByText(/proteção completa para todos os seus filhos/i),
    ).not.toBeInTheDocument();
  });

  it('CTA premium aponta pra upgradeUrl em nova aba (Free + URL configurado)', async () => {
    getLicenseMock.mockResolvedValueOnce(FREE_WITH_URL);
    renderInClient(<Upgrade />);

    const link = await screen.findByRole('link', { name: /fazer upgrade agora/i });
    expect(link).toHaveAttribute('href', 'https://comprar.example.com');
    expect(link).toHaveAttribute('target', '_blank');
    expect(link).toHaveAttribute('rel', 'noopener noreferrer');
  });

  it('CTA premium fica desabilitado quando upgradeUrl é null', async () => {
    getLicenseMock.mockResolvedValueOnce(FREE_WITHOUT_URL);
    renderInClient(<Upgrade />);

    const button = await screen.findByRole('button', { name: /configure o link/i });
    expect(button).toBeDisabled();
  });

  it('CTA premium vira "Plano atual" quando user já é Premium', async () => {
    getLicenseMock.mockResolvedValueOnce(ACTIVE);
    renderInClient(<Upgrade />);

    await screen.findByTestId('upgrade-already-premium');
    const premiumCard = screen.getByTestId('plan-card-premium');
    const ctaButton = premiumCard.querySelector('button[disabled]');
    expect(ctaButton).not.toBeNull();
    expect(ctaButton).toHaveTextContent(/plano atual/i);
  });

  it('plano Free marca "Plano atual" quando user é Free', async () => {
    getLicenseMock.mockResolvedValueOnce(FREE_WITH_URL);
    renderInClient(<Upgrade />);

    // Esperar a query resolver (link no premium card aparece)
    await screen.findByRole('link', { name: /fazer upgrade agora/i });
    const freeCard = screen.getByTestId('plan-card-free');
    expect(freeCard.querySelector('button[disabled]')).toHaveTextContent(/plano atual/i);
  });

  it('plano Free mostra link textual quando user é Premium (sem fluxo de downgrade)', async () => {
    getLicenseMock.mockResolvedValueOnce(ACTIVE);
    renderInClient(<Upgrade />);

    await screen.findByTestId('upgrade-already-premium');
    const freeCard = screen.getByTestId('plan-card-free');
    expect(freeCard).toHaveTextContent(/desative a licença em/i);
    expect(freeCard.querySelector('button')).toBeNull();
  });

  it('comparativo renderiza as features do planCatalog', async () => {
    getLicenseMock.mockResolvedValueOnce(FREE_WITH_URL);
    renderInClient(<Upgrade />);

    await screen.findByText(/comparativo completo/i);
    // "Filhos cadastrados" aparece no PlanCard premium E no comparativo → 2 ocorrências
    expect(screen.getAllByText('Filhos cadastrados').length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText('Navegador infantil seguro').length).toBeGreaterThanOrEqual(1);
    // Localização é premium de verdade (Gate::PREMIUM_FEATURES) → tem que aparecer no upsell
    expect(screen.getAllByText('Localização e Zonas Seguras').length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText('Histórico completo').length).toBeGreaterThanOrEqual(1);
  });
});
