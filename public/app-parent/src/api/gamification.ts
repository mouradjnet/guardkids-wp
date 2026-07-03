import { apiFetch } from './client';

export type ChildProgression = {
  xp: number;
  coins: number;
  level: number;
  streakDays: number;
  missionsCompleted: number;
  medalsUnlocked: number;
};

export function getChildProgression(childId: number): Promise<ChildProgression> {
  return apiFetch<ChildProgression>(`/progression?child_id=${childId}`);
}
