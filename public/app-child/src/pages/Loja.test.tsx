import { screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithClient } from '../test/queryClient';
import { Loja } from './Loja';

const getStore = vi.fn();
const getMyRedemptions = vi.fn();
const redeem = vi.fn();
vi.mock('../api/rewards', () => ({
  getStore: () => getStore(),
  getMyRedemptions: () => getMyRedemptions(),
  redeem: (id: number) => redeem(id),
}));

const store = {
  balance: 120,
  rewards: [
    { id: 1, title: 'Sorvete', costCoins: 100, icon: 'icecream', active: true },
    { id: 2, title: 'Cinema', costCoins: 300, icon: 'movie', active: true },
  ],
};

describe('Loja', () => {
  afterEach(() => {
    getStore.mockReset();
    getMyRedemptions.mockReset();
    redeem.mockReset();
  });

  it('mostra saldo e recompensas, desabilitando a que não dá pra pagar', async () => {
    getStore.mockResolvedValueOnce(store);
    getMyRedemptions.mockResolvedValueOnce([]);
    renderWithClient(<Loja onNavigate={() => {}} />);
    expect(await screen.findByText('Sorvete')).toBeInTheDocument();
    expect(screen.getByText(/120/)).toBeInTheDocument();
    const botoes = screen.getAllByRole('button', { name: /resgatar/i });
    expect(botoes[0]).toBeEnabled();
    expect(botoes[1]).toBeDisabled();
  });

  it('lista os resgates do filho com status', async () => {
    getStore.mockResolvedValueOnce(store);
    getMyRedemptions.mockResolvedValueOnce([
      { id: 9, rewardId: 1, title: 'Sorvete', icon: 'icecream', costCoins: 100, status: 'pending', createdAt: null },
    ]);
    renderWithClient(<Loja onNavigate={() => {}} />);
    expect(await screen.findByText(/pendente/i)).toBeInTheDocument();
  });
});
