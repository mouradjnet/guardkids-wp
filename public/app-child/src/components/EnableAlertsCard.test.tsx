import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { EnableAlertsCard } from './EnableAlertsCard';

const isPushSupported = vi.fn();
const getPermission = vi.fn();
const subscribe = vi.fn();
vi.mock('../lib/push', () => ({
  isPushSupported: () => isPushSupported(),
  getPermission: () => getPermission(),
  subscribe: () => subscribe(),
}));

describe('EnableAlertsCard', () => {
  afterEach(() => {
    isPushSupported.mockReset();
    getPermission.mockReset();
    subscribe.mockReset();
  });

  it('não renderiza nada sem suporte a push', () => {
    isPushSupported.mockReturnValue(false);
    getPermission.mockReturnValue('default');
    const { container } = render(<EnableAlertsCard />);
    expect(container).toBeEmptyDOMElement();
  });

  it('não renderiza se permissão já concedida', () => {
    isPushSupported.mockReturnValue(true);
    getPermission.mockReturnValue('granted');
    const { container } = render(<EnableAlertsCard />);
    expect(container).toBeEmptyDOMElement();
  });

  it('mostra o card e chama subscribe ao ativar', async () => {
    isPushSupported.mockReturnValue(true);
    getPermission.mockReturnValue('default');
    subscribe.mockResolvedValueOnce(undefined);
    render(<EnableAlertsCard />);
    fireEvent.click(screen.getByRole('button', { name: /ativar avisos/i }));
    await waitFor(() => expect(subscribe).toHaveBeenCalledTimes(1));
  });
});
