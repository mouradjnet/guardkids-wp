import { describe, expect, it } from 'vitest';

import { findLesson, LESSONS } from './lessons';
import { findTrack, TRACKS, trackProgress, type Track } from './tracks';

const primeiros = findTrack('primeiros-passos') as Track;
const tempo = findTrack('tempo-de-tela') as Track;
const comingSoon = findTrack('seguranca-digital') as Track;

describe('academy/tracks integridade', () => {
  it('toda aula referenciada por uma trilha existe em LESSONS', () => {
    const orphans: string[] = [];
    for (const track of TRACKS) {
      for (const id of track.lessonIds) {
        if (!findLesson(id)) {
          orphans.push(`${track.id} -> ${id}`);
        }
      }
    }
    expect(orphans).toEqual([]);
  });

  it('as 2 trilhas disponíveis têm 8 aulas cada', () => {
    expect(primeiros.lessonIds).toHaveLength(8);
    expect(tempo.lessonIds).toHaveLength(8);
  });

  it('trilhas coming-soon não têm aulas', () => {
    expect(comingSoon.status).toBe('coming-soon');
    expect(comingSoon.lessonIds).toHaveLength(0);
  });

  it('não há id de aula repetido dentro de uma trilha', () => {
    for (const track of TRACKS) {
      expect(new Set(track.lessonIds).size).toBe(track.lessonIds.length);
    }
  });

  it('há mais aulas no catálogo do que as reusadas (as 7 da Onda 1 continuam)', () => {
    expect(LESSONS.length).toBeGreaterThanOrEqual(16);
    // ids estáveis da Onda 1 não podem sumir
    for (const id of ['primeiros-passos', 'dispositivo-filho', 'verificacao-conexao']) {
      expect(findLesson(id)).toBeDefined();
    }
  });
});

describe('academy/tracks trackProgress', () => {
  it('0% quando nada foi concluído', () => {
    const p = trackProgress(primeiros, []);
    expect(p).toEqual({ total: 8, done: 0, pct: 0, nextLessonId: 'primeiros-passos' });
  });

  it('conta só as aulas concluídas DAQUELA trilha', () => {
    const p = trackProgress(tempo, ['primeiros-passos', 'tempo-o-que-e']);
    expect(p.done).toBe(1); // primeiros-passos não é da trilha Tempo de Tela
    expect(p.nextLessonId).toBe('tempo-rotina');
  });

  it('parcial calcula o percentual arredondado', () => {
    const p = trackProgress(primeiros, ['primeiros-passos', 'instalacao-inicial']);
    expect(p.done).toBe(2);
    expect(p.pct).toBe(25); // 2/8
    expect(p.nextLessonId).toBe('configuracao-responsavel');
  });

  it('100% e sem próxima quando tudo concluído', () => {
    const p = trackProgress(tempo, [...tempo.lessonIds]);
    expect(p.pct).toBe(100);
    expect(p.done).toBe(8);
    expect(p.nextLessonId).toBeNull();
  });

  it('trilha coming-soon: 0% e sem próxima', () => {
    const p = trackProgress(comingSoon, ['tempo-o-que-e']);
    expect(p).toEqual({ total: 0, done: 0, pct: 0, nextLessonId: null });
  });

  it('nextLessonId respeita a ordem (pula as já concluídas do meio)', () => {
    const p = trackProgress(primeiros, ['primeiros-passos', 'configuracao-responsavel']);
    // instalacao-inicial (2ª) está sem concluir → é a próxima
    expect(p.nextLessonId).toBe('instalacao-inicial');
  });
});
