import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { InsightsResponse } from '../api/insights';
import type { LicenseSnapshot } from '../api/license';
import { PREMIUM_FEATURES } from '../hooks/useLicense';
import { InsightsCard } from './InsightsCard';

const { getInsightsMock, refreshInsightsMock, getLicenseMock } = vi.hoisted(() => ({
  getInsightsMock: vi.fn(),
  refreshInsightsMock: vi.fn(),
  getLicenseMock: vi.fn(),
}));

vi.mock('../api/insights', () => ({
  getInsights: getInsightsMock,
  refreshInsights: refreshInsightsMock,
}));

vi.mock('../api/license', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../api/license')>();
  return { ...actual, getLicense: getLicenseMock };
});

function renderInClient(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  return render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

const PREMIUM: LicenseSnapshot = {
  plan: 'premium',
  status: 'active',
  features: [...PREMIUM_FEATURES],
  expiresAt: '2027-12-31T00:00:00Z',
  daysLeft: 365,
  email: 'djair@example.test',
  activatedAt: '2026-06-08 14:00:00',
  upgradeUrl: 'https://comprar.example.com',
};

const FREE: LicenseSnapshot = {
  plan: 'free',
  status: 'none',
  features: [],
  expiresAt: null,
  daysLeft: null,
  email: null,
  activatedAt: null,
  upgradeUrl: 'https://comprar.example.com',
};

function response(insights: InsightsResponse['insights'], extra: Partial<InsightsResponse> = {}): InsightsResponse {
  return { available: true, fromCache: false, generatedAt: '2026-08-09 12:00:00', model: 'claude-opus-4-8', insights, ...extra };
}

describe('InsightsCard', () => {
  beforeEach(() => {
    getInsightsMock.mockReset();
    refreshInsightsMock.mockReset();
    getLicenseMock.mockReset().mockResolvedValue(PREMIUM);
  });

  afterEach(() => vi.clearAllMocks());

  it('renderiza a lista de insights', async () => {
    getInsightsMock.mockResolvedValue(
      response([{ title: 'Tempo subiu 40%', body: 'Concentrado à noite.', severity: 'warning', cta: 'Definir limite noturno' }]),
    );
    renderInClient(<InsightsCard range="week" childId={0} />);

    expect(await screen.findByText('Tempo subiu 40%')).toBeInTheDocument();
    expect(screen.getByText('Concentrado à noite.')).toBeInTheDocument();
    expect(screen.getByText('Definir limite noturno')).toBeInTheDocument();
  });

  it('mostra estado vazio quando não há insights', async () => {
    getInsightsMock.mockResolvedValue(response([]));
    renderInClient(<InsightsCard range="week" childId={0} />);

    expect(await screen.findByText(/sem alertas no período/i)).toBeInTheDocument();
  });

  it('mostra "indisponível" quando o servidor degrada (available=false)', async () => {
    getInsightsMock.mockResolvedValue({ available: false, reason: 'no_key', fromCache: false, insights: [] });
    renderInClient(<InsightsCard range="week" childId={0} />);

    expect(await screen.findByText(/indispon[ií]veis no momento/i)).toBeInTheDocument();
  });

  it('o botão Atualizar regenera e troca os insights na tela', async () => {
    getInsightsMock.mockResolvedValue(response([{ title: 'Antigo', body: 'b', severity: 'info', cta: '' }]));
    refreshInsightsMock.mockResolvedValue(response([{ title: 'Recém-gerado', body: 'novo', severity: 'info', cta: '' }]));
    renderInClient(<InsightsCard range="week" childId={0} />);

    await screen.findByText('Antigo');
    fireEvent.click(screen.getByRole('button', { name: /atualizar insights/i }));

    expect(await screen.findByText('Recém-gerado')).toBeInTheDocument();
    expect(refreshInsightsMock).toHaveBeenCalledWith('week', 0);
  });

  it('no plano Free não busca insights (query desabilitada)', async () => {
    getLicenseMock.mockResolvedValue(FREE);
    renderInClient(<InsightsCard range="week" childId={0} />);

    // dá tempo da licença resolver
    await screen.findByRole('button', { name: /atualizar insights/i });
    expect(getInsightsMock).not.toHaveBeenCalled();
  });
});
