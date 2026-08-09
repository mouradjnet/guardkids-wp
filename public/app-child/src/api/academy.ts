import { apiFetch } from './client';
import type { Progression } from './gamification';

export type AcademyState = {
  completedKeys: string[];
  progression: Progression;
};

export type CompleteResult = AcademyState & {
  awarded: { justCompleted: boolean; xp: number; coins: number };
};

/** Resultado do quiz (Onda 4): correção feita no servidor. */
export type QuizResult = CompleteResult & {
  passed: boolean;
  correct: number;
  total: number;
};

export function getAcademy(): Promise<AcademyState> {
  return apiFetch<AcademyState>('/child/academy');
}

/**
 * Envia as respostas do quiz. O servidor corrige (gabarito nunca vem ao cliente)
 * e, se aprovar, conclui a aula e credita o XP.
 */
export function submitQuiz(lessonKey: string, answers: number[]): Promise<QuizResult> {
  return apiFetch<QuizResult>('/child/academy/quiz', {
    method: 'POST',
    body: JSON.stringify({ lesson_key: lessonKey, answers }),
  });
}
