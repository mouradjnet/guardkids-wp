import { describe, expect, it } from 'vitest';

import { LESSONS } from './lessons';
import { findQuiz, QUIZZES } from './quizzes';

// Contrato espelhado do servidor (includes/Academy/AcademyQuiz.php):
// 8 aulas, cada uma com 3 questões de 3 alternativas. Se o cliente e o servidor
// saírem de sincronia (nº de questões/opções/aulas), um dos lados quebra — lá o
// AcademyQuizTest, aqui este teste.
const QUESTIONS_PER_LESSON = 3;
const OPTIONS_PER_QUESTION = 3;

describe('academy/quizzes cobertura e formato', () => {
  it('toda aula do catálogo tem um quiz', () => {
    const semQuiz = LESSONS.map((l) => l.id).filter((id) => !findQuiz(id));
    expect(semQuiz).toEqual([]);
  });

  it('não há quiz órfão (chave sem aula correspondente)', () => {
    const lessonIds = new Set(LESSONS.map((l) => l.id));
    const orfaos = Object.keys(QUIZZES).filter((k) => !lessonIds.has(k));
    expect(orfaos).toEqual([]);
  });

  it('as chaves dos quizzes são exatamente as das aulas', () => {
    expect(Object.keys(QUIZZES).sort()).toEqual(LESSONS.map((l) => l.id).sort());
  });

  it(`cada quiz tem ${QUESTIONS_PER_LESSON} questões de ${OPTIONS_PER_QUESTION} alternativas`, () => {
    for (const [key, questions] of Object.entries(QUIZZES)) {
      expect(questions, key).toHaveLength(QUESTIONS_PER_LESSON);
      for (const q of questions) {
        expect(q.options, `${key}: "${q.prompt}"`).toHaveLength(OPTIONS_PER_QUESTION);
        expect(q.prompt.trim().length, key).toBeGreaterThan(0);
        // alternativas não-vazias e distintas
        expect(new Set(q.options).size, `${key}: opções repetidas`).toBe(OPTIONS_PER_QUESTION);
        for (const opt of q.options) {
          expect(opt.trim().length).toBeGreaterThan(0);
        }
      }
    }
  });
});
