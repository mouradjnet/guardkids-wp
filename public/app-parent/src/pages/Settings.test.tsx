import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Guardian } from '../api/types';

const { listSettingsMock, updateSettingsMock } = vi.hoisted(() => ({
  listSettingsMock: vi.fn(),
  updateSettingsMock: vi.fn(),
}));
vi.mock('../api/settings', () => ({
  listSettings: listSettingsMock,
  updateSettings: updateSettingsMock,
}));

const {
  listGuardiansMock,
  createGuardianMock,
  updateGuardianRoleMock,
  activateGuardianMock,
  removeGuardianMock,
  resendInviteMock,
} = vi.hoisted(() => ({
  listGuardiansMock: vi.fn(),
  createGuardianMock: vi.fn(),
  updateGuardianRoleMock: vi.fn(),
  activateGuardianMock: vi.fn(),
  removeGuardianMock: vi.fn(),
  resendInviteMock: vi.fn(),
}));
vi.mock('../api/guardians', () => ({
  listGuardians: listGuardiansMock,
  createGuardian: createGuardianMock,
  updateGuardianRole: updateGuardianRoleMock,
  activateGuardian: activateGuardianMock,
  removeGuardian: removeGuardianMock,
  resendInvite: resendInviteMock,
}));

// jsdom não tem navigator.serviceWorker: sem este mock, isPushSupported() daria
// false e o toggle de push nasceria travado em todos os testes.
const { isPushSupportedMock, pushSubscribeMock, pushUnsubscribeMock } = vi.hoisted(() => ({
  isPushSupportedMock: vi.fn(() => true),
  pushSubscribeMock: vi.fn(),
  pushUnsubscribeMock: vi.fn(),
}));
vi.mock('../lib/push', () => ({
  isPushSupported: isPushSupportedMock,
  subscribe: pushSubscribeMock,
  unsubscribe: pushUnsubscribeMock,
  getPermission: vi.fn(() => 'granted'),
}));

const { exportDataMock, clearHistoryMock, deleteAllDataMock } = vi.hoisted(() => ({
  exportDataMock: vi.fn(),
  clearHistoryMock: vi.fn(),
  deleteAllDataMock: vi.fn(),
}));
vi.mock('../api/privacy', () => ({
  exportData: exportDataMock,
  clearHistory: clearHistoryMock,
  deleteAllData: deleteAllDataMock,
}));

const { getPinStatusMock, setPinMock, clearPinMock } = vi.hoisted(() => ({
  getPinStatusMock: vi.fn(),
  setPinMock: vi.fn(),
  clearPinMock: vi.fn(),
}));
vi.mock('../api/security', () => ({
  getPinStatus: getPinStatusMock,
  setPin: setPinMock,
  clearPin: clearPinMock,
}));

vi.mock('qrcode', () => ({
  default: { toDataURL: vi.fn().mockResolvedValue('data:image/png;base64,xxx') },
}));
vi.mock('../api/twofactor');
vi.mock('../api/sessions');

import { Settings } from './Settings';

const djair: Guardian = {
  id: 1,
  wpUserId: 1,
  name: 'Djair',
  email: 'djair@familia.com',
  role: 'admin',
  status: 'active',
  invitePending: false,
  inviteExpiresAt: null,
  createdAt: null,
  updatedAt: null,
};
const marinaPending: Guardian = {
  id: 2,
  wpUserId: null,
  name: 'Marina',
  email: 'marina@familia.com',
  role: 'collaborator',
  status: 'pending',
  invitePending: true,
  inviteExpiresAt: '2099-01-01T00:00:00Z',
  createdAt: null,
  updatedAt: null,
};

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
    listGuardiansMock.mockReset().mockResolvedValue([]);
    createGuardianMock.mockReset();
    updateGuardianRoleMock.mockReset();
    activateGuardianMock.mockReset();
    removeGuardianMock.mockReset();
    resendInviteMock.mockReset();
    exportDataMock.mockReset().mockResolvedValue({
      exported_at: 'x',
      site_url: 'x',
      version: '1',
      tables: {},
    });
    clearHistoryMock.mockReset().mockResolvedValue({ usage_events: 1, locations: 2, requests: 3 });
    deleteAllDataMock.mockReset().mockResolvedValue({ tables: { children: 1 } });
    getPinStatusMock.mockReset().mockResolvedValue({ pinSet: false });
    setPinMock.mockReset().mockResolvedValue({ pinSet: true });
    clearPinMock.mockReset().mockResolvedValue({ pinSet: false });
    // O afterEach faz restoreAllMocks, que zera o `() => true` do vi.fn — sem
    // reafirmar aqui, isPushSupported devolveria undefined e o toggle nasceria
    // travado em todos os testes.
    isPushSupportedMock.mockReset().mockReturnValue(true);
    pushSubscribeMock.mockReset().mockResolvedValue(undefined);
    pushUnsubscribeMock.mockReset().mockResolvedValue(undefined);
  });
  afterEach(() => vi.restoreAllMocks());

  it('renders all 6 sections', async () => {
    listSettingsMock.mockResolvedValue({});
    renderPage();
    expect(
      await screen.findByRole('heading', { name: /^notificações/i, level: 3 }),
    ).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: /^segurança/i, level: 3 })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: /família/i, level: 3 })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: /localização/i, level: 3 })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: /premium/i, level: 3 })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: /privacidade/i, level: 3 })).toBeInTheDocument();
  });

  it('upgrade URL: input vazio quando bag não tem upgrade_url', async () => {
    listSettingsMock.mockResolvedValue({});
    renderPage();
    const input = await screen.findByLabelText(/link de upgrade/i);
    expect(input).toHaveValue('');
  });

  it('upgrade URL: pré-preenche com valor do servidor', async () => {
    listSettingsMock.mockResolvedValue({
      upgrade_url: 'https://comprar.exemplo.com/premium',
    });
    renderPage();
    await waitFor(() => {
      expect(screen.getByLabelText(/link de upgrade/i)).toHaveValue(
        'https://comprar.exemplo.com/premium',
      );
    });
  });

  it('upgrade URL: botão Salvar desabilitado quando valor não mudou', async () => {
    listSettingsMock.mockResolvedValue({
      upgrade_url: 'https://x.com',
    });
    renderPage();
    await screen.findByLabelText(/link de upgrade/i);
    const button = await screen.findByRole('button', { name: /salvar/i });
    expect(button).toBeDisabled();
  });

  it('upgrade URL: submit chama updateSettings com valor trim()ado', async () => {
    listSettingsMock.mockResolvedValue({});
    updateSettingsMock.mockResolvedValue({
      upgrade_url: 'https://novo.com',
    });
    const user = userEvent.setup();
    renderPage();
    await waitFor(() => expect(listSettingsMock).toHaveBeenCalled());
    await screen.findByText(/notificações push/i);

    const input = screen.getByLabelText(/link de upgrade/i);
    await user.type(input, '  https://novo.com  ');
    await user.click(screen.getByRole('button', { name: /salvar/i }));

    await waitFor(() => {
      expect(updateSettingsMock).toHaveBeenCalledWith(
        { upgrade_url: 'https://novo.com' },
        expect.anything(),
      );
    });
  });

  it('upgrade URL: submit com valor vazio limpa a setting (envia string vazia)', async () => {
    listSettingsMock.mockResolvedValue({
      upgrade_url: 'https://antigo.com',
    });
    updateSettingsMock.mockResolvedValue({ upgrade_url: '' });
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
        { upgrade_url: '' },
        expect.anything(),
      );
    });
  });

  it('uses fallback values when settings bag is empty', async () => {
    listSettingsMock.mockResolvedValue({});
    renderPage();
    await screen.findByText('Notificações push');

    // push nasce DESLIGADO: o toggle agora é funcional, e "ligado por padrão"
    // sem subscription seria mentira (era cosmético enquanto ficava locked).
    expect(toggleFor('Notificações push')).toHaveAttribute('aria-checked', 'false');
    expect(toggleFor('Alertas em tempo real')).toHaveAttribute('aria-checked', 'false');
    expect(toggleFor('Logout automático por inatividade')).toHaveAttribute('aria-checked', 'false');
    expect(toggleFor('PIN no painel infantil')).toHaveAttribute('aria-checked', 'true');
  });

  it('overrides fallback with server value', async () => {
    listSettingsMock.mockResolvedValue({
      'notifications.push': false,
      'security.auto_logout': true,
    });
    renderPage();
    await screen.findByText('Notificações push');

    await waitFor(() => {
      expect(toggleFor('Notificações push')).toHaveAttribute('aria-checked', 'false');
    });
    expect(toggleFor('Logout automático por inatividade')).toHaveAttribute('aria-checked', 'true');
  });

  it('calls updateSettings with toggled value when switch clicked', async () => {
    listSettingsMock.mockResolvedValue({});
    updateSettingsMock.mockResolvedValue({ location_enabled: true });
    const user = userEvent.setup();
    renderPage();
    await waitFor(() => expect(listSettingsMock).toHaveBeenCalled());

    await user.click(toggleFor('Permitir compartilhamento de localização'));

    await waitFor(() => {
      expect(updateSettingsMock).toHaveBeenCalled();
      expect(updateSettingsMock.mock.calls[0]?.[0]).toEqual({ location_enabled: true });
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

    await user.click(toggleFor('Permitir compartilhamento de localização'));

    expect(await screen.findByRole('alert')).toHaveTextContent(/falha ao salvar/i);
  });

  it('Segurança não mostra mais "em breve" no header (PIN é real)', async () => {
    listSettingsMock.mockResolvedValue({});
    renderPage();

    const seguranca = await screen.findByRole('heading', { name: /^segurança/i, level: 3 });
    expect(seguranca).not.toHaveTextContent(/em breve/i);
    const notificacoes = screen.getByRole('heading', { name: /^notificações/i, level: 3 });
    expect(notificacoes).not.toHaveTextContent(/em breve/i);
    const privacidade = screen.getByRole('heading', { name: /^privacidade/i, level: 3 });
    expect(privacidade).not.toHaveTextContent(/em breve/i);
  });

  it('Segurança: PIN toggle destravado (não disabled)', async () => {
    listSettingsMock.mockResolvedValue({});
    renderPage();
    await screen.findByText('PIN no painel infantil');

    await waitFor(() => expect(toggleFor('PIN no painel infantil')).not.toBeDisabled());
  });

  it('Segurança: define PIN pelo diálogo e chama setPin', async () => {
    listSettingsMock.mockResolvedValue({});
    getPinStatusMock.mockResolvedValue({ pinSet: false });
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByRole('button', { name: /definir pin/i }));

    const dialog = await screen.findByRole('dialog');
    await user.type(within(dialog).getByLabelText(/novo pin/i), '4321');
    await user.type(within(dialog).getByLabelText(/confirmar pin/i), '4321');
    await user.click(within(dialog).getByRole('button', { name: /salvar pin/i }));

    await waitFor(() => expect(setPinMock).toHaveBeenCalled());
    expect(setPinMock.mock.calls[0]?.[0]).toBe('4321');
  });

  it('Segurança: botão Remover aparece quando há PIN e chama clearPin após confirm', async () => {
    listSettingsMock.mockResolvedValue({});
    getPinStatusMock.mockResolvedValue({ pinSet: true });
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByRole('button', { name: /^remover$/i }));

    expect(confirmSpy).toHaveBeenCalled();
    await waitFor(() => expect(clearPinMock).toHaveBeenCalled());
    confirmSpy.mockRestore();
  });

  it('Notificações: liga resumo diário por email', async () => {
    listSettingsMock.mockResolvedValue({});
    updateSettingsMock.mockResolvedValue({});
    const user = userEvent.setup();
    renderPage();
    await waitFor(() => expect(listSettingsMock).toHaveBeenCalled());

    await user.click(toggleFor('Resumo diário por email'));

    await waitFor(() => expect(updateSettingsMock).toHaveBeenCalled());
    expect(updateSettingsMock.mock.calls[0]?.[0]).toEqual({ 'notifications.email': true });
  });

  it('Notificações: liga relatório semanal', async () => {
    listSettingsMock.mockResolvedValue({});
    updateSettingsMock.mockResolvedValue({});
    const user = userEvent.setup();
    renderPage();
    await waitFor(() => expect(listSettingsMock).toHaveBeenCalled());

    await user.click(toggleFor('Relatório semanal'));

    await waitFor(() => expect(updateSettingsMock).toHaveBeenCalled());
    expect(updateSettingsMock.mock.calls[0]?.[0]).toEqual({ 'notifications.weekly_report': true });
  });

  it('Notificações: push segue desabilitado', async () => {
    listSettingsMock.mockResolvedValue({});
    renderPage();
    await waitFor(() => expect(listSettingsMock).toHaveBeenCalled());

    expect(toggleFor('Notificações push')).toBeDisabled();
  });

  it('Família: shows empty state when no guardians', async () => {
    listSettingsMock.mockResolvedValue({});
    listGuardiansMock.mockResolvedValue([]);
    renderPage();

    expect(await screen.findByText(/sem guardiões cadastrados/i)).toBeInTheDocument();
  });

  it('Família: renders rows with name/email/role badge', async () => {
    listSettingsMock.mockResolvedValue({});
    listGuardiansMock.mockResolvedValue([djair, marinaPending]);
    renderPage();

    expect(await screen.findByText('Djair')).toBeInTheDocument();
    expect(screen.getByText('Marina')).toBeInTheDocument();
    expect(screen.getByText('djair@familia.com')).toBeInTheDocument();
    expect(screen.getByText('marina@familia.com')).toBeInTheDocument();
    // role badges
    expect(screen.getByText(/^admin$/i)).toBeInTheDocument();
    expect(screen.getByText(/^colaborador$/i)).toBeInTheDocument();
    // pending badge
    expect(screen.getByText(/^pendente$/i)).toBeInTheDocument();
  });

  it('Família: opens invite dialog when Convidar clicked', async () => {
    listSettingsMock.mockResolvedValue({});
    listGuardiansMock.mockResolvedValue([djair]);
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Djair');
    await user.click(screen.getByRole('button', { name: /^convidar$/i }));

    expect(screen.getByRole('dialog', { name: /convidar guardião/i })).toBeInTheDocument();
  });

  it('Família: submits new guardian through dialog and shows invite link', async () => {
    listSettingsMock.mockResolvedValue({});
    listGuardiansMock.mockResolvedValue([djair]);
    createGuardianMock.mockResolvedValue({
      ...marinaPending,
      inviteUrl: 'http://wp.local/aceitar-convite/abc',
      inviteToken: 'abc',
    });
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Djair');
    await user.click(screen.getByRole('button', { name: /^convidar$/i }));

    await user.type(screen.getByLabelText(/nome/i), 'Marina');
    await user.type(screen.getByLabelText(/e-mail/i), 'marina@familia.com');
    await user.click(screen.getByRole('button', { name: /enviar convite/i }));

    await waitFor(() => {
      expect(createGuardianMock).toHaveBeenCalled();
      expect(createGuardianMock.mock.calls[0]?.[0]).toEqual({
        name: 'Marina',
        email: 'marina@familia.com',
        role: 'collaborator',
      });
    });
    // Dialog troca pra success view com link de convite
    expect(await screen.findByText(/convite enviado/i)).toBeInTheDocument();
    expect(screen.getByText('http://wp.local/aceitar-convite/abc')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /copiar link/i })).toBeInTheDocument();
  });

  it('Família: pending row has Reenviar button which calls resendInvite + shows new link', async () => {
    listSettingsMock.mockResolvedValue({});
    listGuardiansMock.mockResolvedValue([djair, marinaPending]);
    resendInviteMock.mockResolvedValue({
      ...marinaPending,
      inviteUrl: 'http://wp.local/aceitar-convite/xyz',
      inviteToken: 'xyz',
    });
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Marina');
    await user.click(screen.getByRole('button', { name: /reenviar convite para marina/i }));

    await waitFor(() => {
      expect(resendInviteMock).toHaveBeenCalled();
      expect(resendInviteMock.mock.calls[0]?.[0]).toBe(2);
    });
    expect(await screen.findByText('http://wp.local/aceitar-convite/xyz')).toBeInTheDocument();
  });

  it('Família: clicking activate triggers activateGuardian for pending row', async () => {
    listSettingsMock.mockResolvedValue({});
    listGuardiansMock.mockResolvedValue([djair, marinaPending]);
    activateGuardianMock.mockResolvedValue({ ...marinaPending, status: 'active' });
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Marina');
    await user.click(screen.getByRole('button', { name: /ativar marina/i }));

    await waitFor(() => {
      expect(activateGuardianMock).toHaveBeenCalled();
      expect(activateGuardianMock.mock.calls[0]?.[0]).toBe(2);
    });
  });

  it('Família: promote confirms and calls updateGuardianRole(admin)', async () => {
    listSettingsMock.mockResolvedValue({});
    listGuardiansMock.mockResolvedValue([djair, marinaPending]);
    updateGuardianRoleMock.mockResolvedValue({ ...marinaPending, role: 'admin' });
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Marina');
    await user.click(screen.getByRole('button', { name: /promover marina/i }));

    expect(confirmSpy).toHaveBeenCalled();
    await waitFor(() => {
      expect(updateGuardianRoleMock).toHaveBeenCalled();
      expect(updateGuardianRoleMock.mock.calls[0]?.[0]).toBe(2);
      expect(updateGuardianRoleMock.mock.calls[0]?.[1]).toBe('admin');
    });
    confirmSpy.mockRestore();
  });

  it('Família: remove confirms and calls removeGuardian', async () => {
    listSettingsMock.mockResolvedValue({});
    listGuardiansMock.mockResolvedValue([djair, marinaPending]);
    removeGuardianMock.mockResolvedValue({ deleted: true, id: 2 });
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Marina');
    await user.click(screen.getByRole('button', { name: /remover marina/i }));

    expect(confirmSpy).toHaveBeenCalled();
    await waitFor(() => {
      expect(removeGuardianMock).toHaveBeenCalled();
      expect(removeGuardianMock.mock.calls[0]?.[0]).toBe(2);
    });
    confirmSpy.mockRestore();
  });

  it('Família: cancelar o confirm não chama removeGuardian', async () => {
    listSettingsMock.mockResolvedValue({});
    listGuardiansMock.mockResolvedValue([djair, marinaPending]);
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Marina');
    await user.click(screen.getByRole('button', { name: /remover marina/i }));

    expect(confirmSpy).toHaveBeenCalled();
    expect(removeGuardianMock).not.toHaveBeenCalled();
    confirmSpy.mockRestore();
  });

  it('Privacidade: exports data when "Solicitar" is clicked', async () => {
    listSettingsMock.mockResolvedValue({});
    const createUrl = vi.fn(() => 'blob:x');
    const revokeUrl = vi.fn();
    vi.stubGlobal('URL', { ...URL, createObjectURL: createUrl, revokeObjectURL: revokeUrl });
    const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByRole('button', { name: /solicitar/i }));

    await waitFor(() => expect(exportDataMock).toHaveBeenCalled());
    expect(clickSpy).toHaveBeenCalled();
    clickSpy.mockRestore();
    vi.unstubAllGlobals();
  });

  it('Privacidade: clears history after confirm', async () => {
    listSettingsMock.mockResolvedValue({});
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByRole('button', { name: /^limpar$/i }));

    expect(confirmSpy).toHaveBeenCalled();
    await waitFor(() => expect(clearHistoryMock).toHaveBeenCalled());
    confirmSpy.mockRestore();
  });

  it('Privacidade: deletes account through the confirm dialog', async () => {
    listSettingsMock.mockResolvedValue({});
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByRole('button', { name: /^excluir$/i }));
    await user.type(screen.getByLabelText(/digite/i), 'EXCLUIR');
    await user.click(screen.getByRole('button', { name: /excluir tudo/i }));

    await waitFor(() => expect(deleteAllDataMock).toHaveBeenCalledWith('EXCLUIR'));
  });

  describe('notificações push', () => {
    it('o toggle não está mais bloqueado', async () => {
      listSettingsMock.mockResolvedValue({});
      renderPage();
      await screen.findByText('Notificações push');

      // waitFor: o switch fica disabled enquanto settingsQuery.isLoading, e o
      // findByText acima resolve antes da query assentar.
      await waitFor(() => expect(toggleFor('Notificações push')).not.toBeDisabled());
    });

    it('assina no browser e persiste o setting ao ligar', async () => {
      listSettingsMock.mockResolvedValue({});
      pushSubscribeMock.mockResolvedValue(undefined);
      const user = userEvent.setup();
      renderPage();
      await screen.findByText('Notificações push');
      await waitFor(() => expect(toggleFor('Notificações push')).not.toBeDisabled());

      await user.click(toggleFor('Notificações push'));

      await waitFor(() => expect(pushSubscribeMock).toHaveBeenCalled());
      // v5: mutationFn recebe (variables, context) — daí o expect.anything().
      await waitFor(() =>
        expect(updateSettingsMock).toHaveBeenCalledWith(
          { 'notifications.push': true },
          expect.anything(),
        ),
      );
    });

    it('cancela a assinatura ao desligar', async () => {
      listSettingsMock.mockResolvedValue({ 'notifications.push': true });
      pushUnsubscribeMock.mockResolvedValue(undefined);
      const user = userEvent.setup();
      renderPage();
      await screen.findByText('Notificações push');
      await waitFor(() =>
        expect(toggleFor('Notificações push')).toHaveAttribute('aria-checked', 'true'),
      );

      await user.click(toggleFor('Notificações push'));

      await waitFor(() => expect(pushUnsubscribeMock).toHaveBeenCalled());
    });

    it('mostra o erro e NÃO persiste quando a permissão é negada', async () => {
      listSettingsMock.mockResolvedValue({});
      pushSubscribeMock.mockRejectedValue(new Error('Permissão de notificação negada no navegador.'));
      const user = userEvent.setup();
      renderPage();
      await screen.findByText('Notificações push');
      await waitFor(() => expect(toggleFor('Notificações push')).not.toBeDisabled());

      await user.click(toggleFor('Notificações push'));

      expect(await screen.findByRole('alert')).toHaveTextContent(/permiss/i);
      // O toggle não pode mentir que está ligado.
      expect(toggleFor('Notificações push')).toHaveAttribute('aria-checked', 'false');
      expect(updateSettingsMock).not.toHaveBeenCalled();
    });

    it('fica travado quando o browser não suporta push', async () => {
      isPushSupportedMock.mockReturnValue(false);
      listSettingsMock.mockResolvedValue({});
      renderPage();
      await screen.findByText('Notificações push');

      expect(toggleFor('Notificações push')).toBeDisabled();
      expect(screen.getByText(/não suporta notificações push/i)).toBeInTheDocument();
    });
  });
});
