import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { listSettingsMock, updateSettingsMock } = vi.hoisted(() => ({
  listSettingsMock: vi.fn(),
  updateSettingsMock: vi.fn(),
}));
vi.mock('../api/settings', () => ({
  listSettings: listSettingsMock,
  updateSettings: updateSettingsMock,
}));

import { Settings } from './Settings';

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return render(<Settings />, { wrapper });
}

function toggleFor(label: string): HTMLElement {
  const heading = screen.getByText(label);
  let node: HTMLElement | null = heading.parentElement;
  while (node) {
    const sw = node.querySelector<HTMLElement>('[role="switch"]');
    if (sw) return sw;
    node = node.parentElement;
  }
  throw new Error(`Switch for "${label}" not found`);
}

describe('Settings page', () => {
  beforeEach(() => {
    listSettingsMock.mockReset();
    updateSettingsMock.mockReset();
  });

  it('renders all 6 sections', async () => {
    listSettingsMock.mockResolvedValue({});
    renderPage();
    expect(
      await screen.findByRole('heading', { name: /^notificações$/i, level: 3 }),
    ).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: /^segurança$/i, level: 3 })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: /família/i, level: 3 })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: /localização/i, level: 3 })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: /premium/i, level: 3 })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: /privacidade/i, level: 3 })).toBeInTheDocument();
  });

  it('upgrade URL: input vazio quando bag não tem guardkids_upgrade_url', async () => {
    listSettingsMock.mockResolvedValue({});
    renderPage();
    const input = await screen.findByLabelText(/link de upgrade/i);
    expect(input).toHaveValue('');
  });

  it('upgrade URL: pré-preenche com valor do servidor', async () => {
    listSettingsMock.mockResolvedValue({
      guardkids_upgrade_url: 'https://comprar.exemplo.com/premium',
    });
    renderPage();
    // O input remonta quando a query resolve (key force-reset) — re-busca dentro do waitFor
    await waitFor(() => {
      expect(screen.getByLabelText(/link de upgrade/i)).toHaveValue(
        'https://comprar.exemplo.com/premium',
      );
    });
  });

  it('upgrade URL: botão Salvar desabilitado quando valor não mudou', async () => {
    listSettingsMock.mockResolvedValue({
      guardkids_upgrade_url: 'https://x.com',
    });
    renderPage();
    await screen.findByLabelText(/link de upgrade/i);
    const button = await screen.findByRole('button', { name: /salvar/i });
    expect(button).toBeDisabled();
  });

  it('upgrade URL: submit chama updateSettings com valor trim()ado', async () => {
    listSettingsMock.mockResolvedValue({});
    updateSettingsMock.mockResolvedValue({
      guardkids_upgrade_url: 'https://novo.com',
    });
    const user = userEvent.setup();
    renderPage();
    // Espera query resolver pra evitar re-mount do form via `key`
    await waitFor(() => expect(listSettingsMock).toHaveBeenCalled());
    await screen.findByText(/notificações push/i);

    const input = screen.getByLabelText(/link de upgrade/i);
    await user.type(input, '  https://novo.com  ');
    await user.click(screen.getByRole('button', { name: /salvar/i }));

    await waitFor(() => {
      expect(updateSettingsMock).toHaveBeenCalledWith(
        { guardkids_upgrade_url: 'https://novo.com' },
        expect.anything(),
      );
    });
  });

  it('upgrade URL: submit com valor vazio limpa a setting (envia string vazia)', async () => {
    listSettingsMock.mockResolvedValue({
      guardkids_upgrade_url: 'https://antigo.com',
    });
    updateSettingsMock.mockResolvedValue({ guardkids_upgrade_url: '' });
    const user = userEvent.setup();
    renderPage();
    await waitFor(() =>
      expect(screen.getByLabelText(/link de upgrade/i)).toHaveValue(
        'https://antigo.com',
      ),
    );

    await user.clear(screen.getByLabelText(/link de upgrade/i));
    await user.click(screen.getByRole('button', { name: /salvar/i }));

    await waitFor(() => {
      expect(updateSettingsMock).toHaveBeenCalledWith(
        { guardkids_upgrade_url: '' },
        expect.anything(),
      );
    });
  });

  it('uses fallback values when settings bag is empty', async () => {
    listSettingsMock.mockResolvedValue({});
    renderPage();
    await screen.findByText('Notificações push');

    // fallbacks: push=true, email=true, realtime=false, weekly_report=true, two_fa=false, pin_child=true, auto_logout=false
    expect(toggleFor('Notificações push')).toHaveAttribute('aria-checked', 'true');
    expect(toggleFor('Alertas em tempo real')).toHaveAttribute('aria-checked', 'false');
    expect(toggleFor('Autenticação em 2 fatores (2FA)')).toHaveAttribute('aria-checked', 'false');
    expect(toggleFor('PIN no painel infantil')).toHaveAttribute('aria-checked', 'true');
  });

  it('overrides fallback with server value', async () => {
    listSettingsMock.mockResolvedValue({
      'notifications.push': false,
      'security.two_fa': true,
    });
    renderPage();
    await screen.findByText('Notificações push');

    await waitFor(() => {
      expect(toggleFor('Notificações push')).toHaveAttribute('aria-checked', 'false');
    });
    expect(toggleFor('Autenticação em 2 fatores (2FA)')).toHaveAttribute('aria-checked', 'true');
  });

  it('shows activeBadge when 2FA is on', async () => {
    listSettingsMock.mockResolvedValue({ 'security.two_fa': true });
    renderPage();
    expect(await screen.findByText('Ativo')).toBeInTheDocument();
  });

  it('calls updateSettings with toggled value when switch clicked', async () => {
    listSettingsMock.mockResolvedValue({});
    updateSettingsMock.mockResolvedValue({ 'notifications.push': false });
    const user = userEvent.setup();
    renderPage();
    await waitFor(() => expect(listSettingsMock).toHaveBeenCalled());

    await user.click(toggleFor('Notificações push'));

    await waitFor(() => {
      expect(updateSettingsMock).toHaveBeenCalled();
      expect(updateSettingsMock.mock.calls[0]?.[0]).toEqual({ 'notifications.push': false });
    });
  });

  it('shows error state when listSettings fails', async () => {
    listSettingsMock.mockRejectedValue(new Error('boom'));
    renderPage();
    expect(await screen.findByText(/falha ao carregar configurações/i)).toBeInTheDocument();
  });

  it('shows mutation error alert when updateSettings fails', async () => {
    listSettingsMock.mockResolvedValue({});
    updateSettingsMock.mockRejectedValue(new Error('save failed'));
    const user = userEvent.setup();
    renderPage();
    await waitFor(() => expect(listSettingsMock).toHaveBeenCalled());

    await user.click(toggleFor('Notificações push'));

    expect(await screen.findByRole('alert')).toHaveTextContent(/falha ao salvar/i);
  });

  it('renders ComingSoonBadge on Família and Privacidade headings', async () => {
    listSettingsMock.mockResolvedValue({});
    renderPage();

    const familia = await screen.findByRole('heading', { name: /família/i });
    expect(familia).toHaveTextContent(/em breve/i);
    const privacidade = screen.getByRole('heading', { name: /privacidade/i });
    expect(privacidade).toHaveTextContent(/em breve/i);
  });
});
