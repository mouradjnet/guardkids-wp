import { fireEvent, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithClient } from '../test/queryClient';
import { Recompensas } from './Recompensas';

const listRewards = vi.fn();
const listPendingRedemptions = vi.fn();
const deleteReward = vi.fn();
const createReward = vi.fn();
const updateReward = vi.fn();
vi.mock('../api/rewards', () => ({
  listRewards: () => listRewards(),
  createReward: (input: unknown) => createReward(input),
  updateReward: (id: number, patch: unknown) => updateReward(id, patch),
  deleteReward: (id: number) => deleteReward(id),
  listPendingRedemptions: () => listPendingRedemptions(),
  approveRedemption: vi.fn(),
  denyRedemption: vi.fn(),
}));

describe('Recompensas', () => {
  afterEach(() => {
    listRewards.mockReset();
    listPendingRedemptions.mockReset();
    deleteReward.mockReset();
    createReward.mockReset();
    updateReward.mockReset();
  });

  it('lista recompensas e resgates pendentes', async () => {
    listRewards.mockResolvedValue([
      { id: 1, title: 'Sorvete', costCoins: 100, icon: 'icecream', active: true },
    ]);
    listPendingRedemptions.mockResolvedValue([
      {
        id: 9,
        childId: 5,
        childName: 'Lucas',
        rewardId: 1,
        title: 'Sorvete',
        costCoins: 100,
        status: 'pending',
        createdAt: null,
      },
    ]);
    renderWithClient(<Recompensas />);
    expect(await screen.findByText('Sorvete')).toBeInTheDocument();
    expect(await screen.findByText('Lucas')).toBeInTheDocument();
    expect(await screen.findByRole('button', { name: /aprovar/i })).toBeInTheDocument();
  });

  it('mostra erro quando remover uma recompensa falha (não é silencioso)', async () => {
    listRewards.mockResolvedValue([
      { id: 1, title: 'Sorvete', costCoins: 100, icon: 'icecream', active: true },
    ]);
    listPendingRedemptions.mockResolvedValue([]);
    deleteReward.mockRejectedValue(new Error('Falha ao remover'));
    renderWithClient(<Recompensas />);

    fireEvent.click(await screen.findByRole('button', { name: /remover/i }));

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent(/falha ao remover/i);
  });

  it('mostra erro quando criar recompensa falha (não some mudo)', async () => {
    listRewards.mockResolvedValue([]);
    listPendingRedemptions.mockResolvedValue([]);
    createReward.mockRejectedValue(new Error('servidor fora'));
    renderWithClient(<Recompensas />);

    await screen.findByText(/nenhuma recompensa ainda/i);
    fireEvent.change(screen.getByLabelText(/t[íi]tulo/i), { target: { value: 'Cinema' } });
    fireEvent.change(screen.getByLabelText(/custo|moedas|pre[çc]o/i), { target: { value: '50' } });
    fireEvent.click(screen.getByRole('button', { name: /adicionar/i }));

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent(/falha na recompensa/i);
    expect(alert).toHaveTextContent(/servidor fora/i);
  });

  it('mostra erro quando ativar/desativar recompensa falha (não some mudo)', async () => {
    listRewards.mockResolvedValue([
      { id: 1, title: 'Sorvete', costCoins: 100, icon: 'icecream', active: true },
    ]);
    listPendingRedemptions.mockResolvedValue([]);
    updateReward.mockRejectedValue(new Error('servidor fora'));
    renderWithClient(<Recompensas />);

    fireEvent.click(await screen.findByRole('button', { name: /desativar/i }));

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent(/falha na recompensa/i);
    expect(alert).toHaveTextContent(/servidor fora/i);
  });
});
