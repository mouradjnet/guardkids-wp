import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { LicenseSnapshot } from '../api/license';
import { PREMIUM_FEATURES } from '../hooks/useLicense';
import { PremiumLock } from './PremiumLock';

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

const FREE_NONE: LicenseSnapshot = {
  plan: 'free',
  status: 'none',
  features: [],
  expiresAt: null,
  daysLeft: null,
  email: null,
  activatedAt: null,
  upgradeUrl: 'https://comprar.example.com',
};

describe('PremiumLock', () => {
  beforeEach(() => {
    getLicenseMock.mockReset();
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  it('passa children direto durante o loading (evita pisca de bloqueio)', () => {
    getLicenseMock.mockImplementation(() => new Promise(() => {}));
    renderInClient(
      <PremiumLock featureId="browser">
        <div>Conteúdo premium</div>
      </PremiumLock>,
    );
    expect(screen.getByText('Conteúdo premium')).toBeInTheDocument();
    expect(screen.queryByLabelText('Feature premium')).not.toBeInTheDocument();
  });

  it('passa children direto quando a feature não é premium', async () => {
    getLicenseMock.mockResolvedValueOnce(FREE_NONE);
    renderInClient(
      <PremiumLock featureId="blacklist_basic">
        <div>Sempre visível</div>
      </PremiumLock>,
    );
    expect(await screen.findByText('Sempre visível')).toBeInTheDocument();
    expect(screen.queryByLabelText('Feature premium')).not.toBeInTheDocument();
  });

  it('passa children direto quando premium está ativo', async () => {
    getLicenseMock.mockResolvedValueOnce(ACTIVE_PREMIUM);
    renderInClient(
      <PremiumLock featureId="browser">
        <div>Conteúdo do Browser seguro</div>
      </PremiumLock>,
    );
    expect(await screen.findByText('Conteúdo do Browser seguro')).toBeInTheDocument();
    expect(screen.queryByLabelText('Feature premium')).not.toBeInTheDocument();
  });

  it('renderiza overlay com CTA quando bloqueado e há upgradeUrl', async () => {
    getLicenseMock.mockResolvedValueOnce(FREE_NONE);
    renderInClient(
      <PremiumLock featureId="browser">
        <div>Conteúdo blurado</div>
      </PremiumLock>,
    );
    expect(await screen.findByLabelText('Feature premium')).toBeInTheDocument();
    const cta = screen.getByRole('link', { name: /fazer upgrade/i });
    expect(cta).toHaveAttribute('href', 'https://comprar.example.com');
    expect(cta).toHaveAttribute('target', '_blank');
    expect(cta).toHaveAttribute('rel', 'noopener noreferrer');
  });

  it('usa título e descrição customizados quando passados', async () => {
    getLicenseMock.mockResolvedValueOnce(FREE_NONE);
    renderInClient(
      <PremiumLock
        featureId="browser"
        title="Browser Seguro"
        description="Navegação infantil protegida está no Premium."
      >
        <div>x</div>
      </PremiumLock>,
    );
    expect(await screen.findByText('Browser Seguro')).toBeInTheDocument();
    expect(
      screen.getByText('Navegação infantil protegida está no Premium.'),
    ).toBeInTheDocument();
  });

  it('mostra fallback de configuração quando upgradeUrl é null', async () => {
    getLicenseMock.mockResolvedValueOnce({ ...FREE_NONE, upgradeUrl: null });
    renderInClient(
      <PremiumLock featureId="browser">
        <div>x</div>
      </PremiumLock>,
    );
    expect(await screen.findByLabelText('Feature premium')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /fazer upgrade/i })).not.toBeInTheDocument();
    expect(screen.getByText(/configure o link de upgrade/i)).toBeInTheDocument();
  });

  it('mostra overlay quando a licença existe mas expirou', async () => {
    getLicenseMock.mockResolvedValueOnce({
      ...ACTIVE_PREMIUM,
      status: 'expired',
      plan: 'free',
      daysLeft: 0,
    });
    renderInClient(
      <PremiumLock featureId="reports">
        <div>Reports antigos</div>
      </PremiumLock>,
    );
    expect(await screen.findByLabelText('Feature premium')).toBeInTheDocument();
  });

  it('renderiza overlay mesmo sem children passados', async () => {
    getLicenseMock.mockResolvedValueOnce(FREE_NONE);
    renderInClient(<PremiumLock featureId="browser" />);
    expect(await screen.findByLabelText('Feature premium')).toBeInTheDocument();
  });
});
