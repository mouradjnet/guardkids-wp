import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { EnableAlertsCard } from './EnableAlertsCard';

const isPushSupported = vi.fn();
const getPermission = vi.fn();
const subscribe = vi.fn();
const hasDeviceSubscription = vi.fn();
vi.mock('../lib/push', () => ({
  isPushSupported: () => isPushSupported(),
  getPermission: () => getPermission(),
  subscribe: () => subscribe(),
  hasDeviceSubscription: () => hasDeviceSubscription(),
}));

describe('EnableAlertsCard', () => {
  afterEach(() => {
    isPushSupported.mockReset();
    getPermission.mockReset();
    subscribe.mockReset();
    hasDeviceSubscription.mockReset();
  });

  it('não renderiza nada sem suporte a push', () => {
    isPushSupported.mockReturnValue(false);
    getPermission.mockReturnValue('default');
    hasDeviceSubscription.mockResolvedValue(false);
    const { container } = render(<EnableAlertsCard />);
    expect(container).toBeEmptyDOMElement();
  });

  it('não renderiza quando a permissão foi negada', () => {
    isPushSupported.mockReturnValue(true);
    getPermission.mockReturnValue('denied');
    hasDeviceSubscription.mockResolvedValue(false);
    const { container } = render(<EnableAlertsCard />);
    expect(container).toBeEmptyDOMElement();
  });

  it('some quando este aparelho já tem assinatura', async () => {
    isPushSupported.mockReturnValue(true);
    getPermission.mockReturnValue('granted');
    hasDeviceSubscription.mockResolvedValue(true);
    render(<EnableAlertsCard />);
    await waitFor(() =>
      expect(screen.queryByRole('button', { name: /ativar avisos/i })).not.toBeInTheDocument(),
    );
  });

  /**
   * O caso real de produção: permissão concedida em algum momento, mas nenhuma
   * subscription neste aparelho (a tabela estava vazia). O card sumia e a
   * criança ficava sem caminho nenhum para ativar os avisos.
   */
  it('CONTINUA aparecendo com permissão concedida mas sem assinatura neste aparelho', async () => {
    isPushSupported.mockReturnValue(true);
    getPermission.mockReturnValue('granted');
    hasDeviceSubscription.mockResolvedValue(false);
    subscribe.mockResolvedValueOnce(undefined);
    render(<EnableAlertsCard />);

    const botao = await screen.findByRole('button', { name: /ativar avisos/i });
    fireEvent.click(botao);

    await waitFor(() => expect(subscribe).toHaveBeenCalledTimes(1));
  });

  it('mostra o card e chama subscribe ao ativar', async () => {
    isPushSupported.mockReturnValue(true);
    getPermission.mockReturnValue('default');
    hasDeviceSubscription.mockResolvedValue(false);
    subscribe.mockResolvedValueOnce(undefined);
    render(<EnableAlertsCard />);
    fireEvent.click(screen.getByRole('button', { name: /ativar avisos/i }));
    await waitFor(() => expect(subscribe).toHaveBeenCalledTimes(1));
  });
});
