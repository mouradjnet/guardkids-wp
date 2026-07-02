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
