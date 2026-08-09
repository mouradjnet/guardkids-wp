import { apiFetch } from './client';

/** Progresso do Academy do responsável (espelha o payload do AcademyController). */
export interface AcademyProgress {
  completed: string[];
  dismissed: string[];
}

export type ProgressKind = 'completed' | 'dismissed';

export function getAcademyProgress(): Promise<AcademyProgress> {
  return apiFetch<AcademyProgress>('/academy/progress');
}

export function markLesson(lessonId: string, kind: ProgressKind): Promise<AcademyProgress> {
  return apiFetch<AcademyProgress>('/academy/progress', {
    method: 'POST',
    body: JSON.stringify({ lessonId, kind }),
  });
}

export function completeLesson(lessonId: string): Promise<AcademyProgress> {
  return markLesson(lessonId, 'completed');
}

export function dismissLesson(lessonId: string): Promise<AcademyProgress> {
  return markLesson(lessonId, 'dismissed');
}
