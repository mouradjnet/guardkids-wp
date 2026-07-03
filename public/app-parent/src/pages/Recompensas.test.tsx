import { screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithClient } from '../test/queryClient';
import { Recompensas } from './Recompensas';

const listRewards = vi.fn();
const listPendingRedemptions = vi.fn();
vi.mock('../api/rewards', () => ({
  listRewards: () => listRewards(),
  createReward: vi.fn(),
  updateReward: vi.fn(),
  deleteReward: vi.fn(),
  listPendingRedemptions: () => listPendingRedemptions(),
  approveRedemption: vi.fn(),
  denyRedemption: vi.fn(),
}));

describe('Recompensas', () => {
  afterEach(() => {
    listRewards.mockReset();
    listPendingRedemptions.mockReset();
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
});
