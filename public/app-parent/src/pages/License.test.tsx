import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../api/client';
import type { LicenseSnapshot } from '../api/license';
import { PREMIUM_FEATURES } from '../hooks/useLicense';
import { License } from './License';

const { getLicenseMock, activateLicenseMock, deactivateLicenseMock } = vi.hoisted(() => ({
  getLicenseMock: vi.fn(),
  activateLicenseMock: vi.fn(),
  deactivateLicenseMock: vi.fn(),
}));

vi.mock('../api/license', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../api/license')>();
  return {
    ...actual,
    getLicense: getLicenseMock,
    activateLicense: activateLicenseMock,
    deactivateLicense: deactivateLicenseMock,
  };
});

function renderInClient(ui: ReactNode) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0 }, mutations: { retry: false } },
  });
  return render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

const ACTIVE_SNAPSHOT: LicenseSnapshot = {
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
  upgradeUrl: null,
};

describe('License page', () => {
  let originalConfirm: typeof window.confirm;

  beforeEach(() => {
    getLicenseMock.mockReset();
    activateLicenseMock.mockReset();
    deactivateLicenseMock.mockReset();
    originalConfirm = window.confirm;
  });

  afterEach(() => {
    vi.clearAllMocks();
    window.confirm = originalConfirm;
  });

  it('mostra hero "Plano Free" quando sem licença', async () => {
    getLicenseMock.mockResolvedValueOnce(FREE_NONE);
    renderInClient(<License />);

    const hero = await screen.findByTestId('license-hero');
    expect(hero).toHaveAttribute('data-status', 'none');
    // "Plano Free" aparece 2x (badge + título do hero), bom o suficiente
    expect(screen.getAllByText(/plano free/i).length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText(/cole sua chave de licença abaixo/i)).toBeInTheDocument();
  });

  it('mostra hero "Premium" quando ativa, com detalhes e features', async () => {
    getLicenseMock.mockResolvedValueOnce(ACTIVE_SNAPSHOT);
    renderInClient(<License />);

    const hero = await screen.findByTestId('license-hero');
    expect(hero).toHaveAttribute('data-status', 'active');
    expect(screen.getByText(/guardkids premium/i)).toBeInTheDocument();
    expect(screen.getByText(/cliente: djair@example.test/i)).toBeInTheDocument();
    expect(screen.getByText(/365 dias/i)).toBeInTheDocument();

    // Detalhes
    expect(screen.getByText('djair@example.test')).toBeInTheDocument();
    expect(screen.getByText('2026-06-08 14:00:00')).toBeInTheDocument();

    // Chips de features
    for (const f of PREMIUM_FEATURES) {
      expect(screen.getByText(f)).toBeInTheDocument();
    }
  });

  it('mostra hero "Expirada" e mantém detalhes da licença antiga', async () => {
    getLicenseMock.mockResolvedValueOnce({
      ...ACTIVE_SNAPSHOT,
      status: 'expired',
      plan: 'free',
      daysLeft: 0,
    });
    renderInClient(<License />);

    const hero = await screen.findByTestId('license-hero');
    expect(hero).toHaveAttribute('data-status', 'expired');
    expect(screen.getByText(/sua licença premium expirou/i)).toBeInTheDocument();
    // Detalhes ainda renderizam (email + activatedAt) — decisão §9.3
    expect(screen.getByText('djair@example.test')).toBeInTheDocument();
  });

  it('mostra hero "Domínio diferente" pra domain_mismatch', async () => {
    getLicenseMock.mockResolvedValueOnce({ ...FREE_NONE, status: 'domain_mismatch' });
    renderInClient(<License />);

    const hero = await screen.findByTestId('license-hero');
    expect(hero).toHaveAttribute('data-status', 'domain_mismatch');
    expect(screen.getByText(/esta chave é de outro domínio/i)).toBeInTheDocument();
  });

  it('submit do form chama activateLicense com a chave trim()ada', async () => {
    getLicenseMock.mockResolvedValueOnce(FREE_NONE);
    activateLicenseMock.mockResolvedValueOnce(ACTIVE_SNAPSHOT);
    renderInClient(<License />);
    await screen.findByTestId('license-hero');

    const textarea = screen.getByLabelText(/chave de licença/i);
    fireEvent.change(textarea, { target: { value: '  abc.def  ' } });
    fireEvent.click(screen.getByRole('button', { name: /ativar licença/i }));

    await waitFor(() => {
      expect(activateLicenseMock).toHaveBeenCalledWith('abc.def');
    });
  });

  it('submit limpa whitespace interno (newlines/tabs) antes de ativar', async () => {
    // Bug do smoke 2026-06-09: paste de chave com quebras visuais quebrava o
    // base64url no backend porque sanitize_text_field convertia em espaço.
    getLicenseMock.mockResolvedValueOnce(FREE_NONE);
    activateLicenseMock.mockResolvedValueOnce(ACTIVE_SNAPSHOT);
    renderInClient(<License />);
    await screen.findByTestId('license-hero');

    fireEvent.change(screen.getByLabelText(/chave de licença/i), {
      target: { value: '  abc.\n\ndef\tghi  ' },
    });
    fireEvent.click(screen.getByRole('button', { name: /ativar licença/i }));

    await waitFor(() => {
      expect(activateLicenseMock).toHaveBeenCalledWith('abc.defghi');
    });
  });

  it('exibe erro do backend quando activate falha', async () => {
    getLicenseMock.mockResolvedValueOnce(FREE_NONE);
    activateLicenseMock.mockRejectedValueOnce(
      new ApiError('license_domain_mismatch', 'Outro domínio.', 422),
    );
    renderInClient(<License />);
    await screen.findByTestId('license-hero');

    fireEvent.change(screen.getByLabelText(/chave de licença/i), {
      target: { value: 'fake.key' },
    });
    fireEvent.click(screen.getByRole('button', { name: /ativar licença/i }));

    expect(await screen.findByRole('alert')).toHaveTextContent(/outro domínio/i);
  });

  it('botão desativar só aparece quando há licença (active ou expired)', async () => {
    getLicenseMock.mockResolvedValueOnce(FREE_NONE);
    renderInClient(<License />);
    await screen.findByTestId('license-hero');
    expect(screen.queryByRole('button', { name: /desativar/i })).not.toBeInTheDocument();
  });

  it('desativar pede confirmação e chama API quando confirmado', async () => {
    getLicenseMock.mockResolvedValueOnce(ACTIVE_SNAPSHOT);
    deactivateLicenseMock.mockResolvedValueOnce(FREE_NONE);
    window.confirm = vi.fn().mockReturnValue(true);
    renderInClient(<License />);
    await screen.findByTestId('license-hero');

    fireEvent.click(
      screen.getByRole('button', { name: /desativar nesta instalação/i }),
    );

    expect(window.confirm).toHaveBeenCalledOnce();
    await waitFor(() => expect(deactivateLicenseMock).toHaveBeenCalledOnce());
  });

  it('desativar NÃO chama API se usuário cancelar o confirm', async () => {
    getLicenseMock.mockResolvedValueOnce(ACTIVE_SNAPSHOT);
    window.confirm = vi.fn().mockReturnValue(false);
    renderInClient(<License />);
    await screen.findByTestId('license-hero');

    fireEvent.click(
      screen.getByRole('button', { name: /desativar nesta instalação/i }),
    );

    expect(window.confirm).toHaveBeenCalledOnce();
    expect(deactivateLicenseMock).not.toHaveBeenCalled();
  });

  it('mostra erro quando a desativação falha (não é silenciosa)', async () => {
    getLicenseMock.mockResolvedValueOnce(ACTIVE_SNAPSHOT);
    deactivateLicenseMock.mockRejectedValueOnce(
      new ApiError('deactivate_failed', 'Falha ao desativar', 500),
    );
    window.confirm = vi.fn().mockReturnValue(true);
    renderInClient(<License />);
    await screen.findByTestId('license-hero');

    fireEvent.click(
      screen.getByRole('button', { name: /desativar nesta instalação/i }),
    );

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent(/falha ao desativar/i);
  });

  it('botão ativar fica desabilitado quando textarea está vazio', async () => {
    getLicenseMock.mockResolvedValueOnce(FREE_NONE);
    renderInClient(<License />);
    await screen.findByTestId('license-hero');

    const button = screen.getByRole('button', { name: /ativar licença/i });
    expect(button).toBeDisabled();

    fireEvent.change(screen.getByLabelText(/chave de licença/i), {
      target: { value: 'x' },
    });
    expect(button).toBeEnabled();
  });
});
