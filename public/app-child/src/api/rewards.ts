import { apiFetch } from './client';

export type Reward = {
  id: number;
  title: string;
  costCoins: number;
  icon: string | null;
  active: boolean;
};

export type Redemption = {
  id: number;
  rewardId: number;
  title: string;
  icon: string | null;
  costCoins: number;
  status: 'pending' | 'approved' | 'denied';
  createdAt: string | null;
};

export function getStore(): Promise<{ balance: number; rewards: Reward[] }> {
  return apiFetch<{ balance: number; rewards: Reward[] }>('/child/rewards');
}

export function getMyRedemptions(): Promise<Redemption[]> {
  return apiFetch<Redemption[]>('/child/redemptions');
}

export function redeem(rewardId: number): Promise<Redemption> {
  return apiFetch<Redemption>('/child/redemptions', {
    method: 'POST',
    body: JSON.stringify({ rewardId }),
  });
}
