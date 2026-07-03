import { apiFetch } from './client';

export type Progression = {
  xp: number;
  coins: number;
  level: number;
  xpIntoLevel: number;
  xpForNextLevel: number;
  streakDays: number;
};

export function getProgression(): Promise<Progression> {
  return apiFetch<Progression>('/child/progression');
}

export type Mission = {
  key: string;
  title: string;
  description: string;
  icon: string;
  target: number;
  progress: number;
  completed: boolean;
  justCompleted: boolean;
  xpReward: number;
  coinsReward: number;
};

export function getMissions(): Promise<Mission[]> {
  return apiFetch<Mission[]>('/child/missions');
}

export type Medal = {
  key: string;
  title: string;
  description: string;
  icon: string;
  target: number;
  progress: number;
  unlocked: boolean;
  justUnlocked: boolean;
  xpReward: number;
  coinsReward: number;
};

export function getMedals(): Promise<Medal[]> {
  return apiFetch<Medal[]>('/child/medals');
}
