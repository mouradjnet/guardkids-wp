import { apiFetch } from './client';

export type Reward = {
  id: number;
  title: string;
  costCoins: number;
  icon: string | null;
  active: boolean;
};

export type PendingRedemption = {
  id: number;
  childId: number;
  childName: string;
  rewardId: number;
  title: string;
  costCoins: number;
  status: string;
  createdAt: string | null;
};

export function listRewards(): Promise<Reward[]> {
  return apiFetch<Reward[]>('/rewards');
}

export function createReward(input: {
  title: string;
  costCoins: number;
  icon?: string;
}): Promise<Reward> {
  return apiFetch<Reward>('/rewards', { method: 'POST', body: JSON.stringify(input) });
}

export function updateReward(
  id: number,
  input: Partial<{ title: string; costCoins: number; icon: string; active: boolean }>,
): Promise<Reward> {
  return apiFetch<Reward>(`/rewards/${id}`, { method: 'PUT', body: JSON.stringify(input) });
}

export function deleteReward(id: number): Promise<{ deleted: boolean }> {
  return apiFetch<{ deleted: boolean }>(`/rewards/${id}`, { method: 'DELETE' });
}

export function listPendingRedemptions(): Promise<PendingRedemption[]> {
  return apiFetch<PendingRedemption[]>('/redemptions?status=pending');
}

export function approveRedemption(id: number): Promise<PendingRedemption> {
  return apiFetch<PendingRedemption>(`/redemptions/${id}/approve`, { method: 'POST' });
}

export function denyRedemption(id: number): Promise<PendingRedemption> {
  return apiFetch<PendingRedemption>(`/redemptions/${id}/deny`, { method: 'POST' });
}
