import { apiFetch } from './client';
import type { Progression } from './gamification';

export type AcademyState = {
  completedKeys: string[];
  progression: Progression;
};

export type CompleteResult = AcademyState & {
  awarded: { justCompleted: boolean; xp: number; coins: number };
};

export function getAcademy(): Promise<AcademyState> {
  return apiFetch<AcademyState>('/child/academy');
}

export function completeLesson(lessonKey: string): Promise<CompleteResult> {
  return apiFetch<CompleteResult>('/child/academy/complete', {
    method: 'POST',
    body: JSON.stringify({ lesson_key: lessonKey }),
  });
}
