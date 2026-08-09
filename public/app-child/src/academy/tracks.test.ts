import { describe, expect, it } from 'vitest';

import { findLesson, LESSONS } from './lessons';
import { findTrack, TRACKS, trackProgress, type Track } from './tracks';

const seguro = findTrack('digital-seguro') as Track;
const tempo = findTrack('meu-tempo') as Track;

// Espelha VALID_KEYS em api/Controllers/ChildAcademyController.php — se o
// servidor e o cliente saírem de sincronia, a aula vira "desconhecida" no POST.
const SERVER_VALID_KEYS = [
  'seguranca-intro',
  'senha-secreta',
  'falar-com-adulto',
  'pistas-de-golpe',
  'tempo-intro',
  'fazer-pausas',
  'tela-e-sono',
  'brincar-sem-tela',
];

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

  it('as 2 trilhas têm 4 aulas cada', () => {
    expect(seguro.lessonIds).toHaveLength(4);
    expect(tempo.lessonIds).toHaveLength(4);
  });

  it('não há id de aula repetido dentro de uma trilha', () => {
    for (const track of TRACKS) {
      expect(new Set(track.lessonIds).size).toBe(track.lessonIds.length);
    }
  });

  it('todo id de aula do catálogo está no allowlist do servidor (e vice-versa)', () => {
    const catalogIds = LESSONS.map((l) => l.id).sort();
    const trackIds = TRACKS.flatMap((t) => t.lessonIds).sort();
    expect(catalogIds).toEqual([...SERVER_VALID_KEYS].sort());
    // toda aula do catálogo pertence a exatamente uma trilha
    expect(trackIds).toEqual(catalogIds);
  });
});

describe('academy/tracks trackProgress', () => {
  it('0% quando nada foi concluído', () => {
    const p = trackProgress(seguro, []);
    expect(p).toEqual({ total: 4, done: 0, pct: 0, nextLessonId: 'seguranca-intro' });
  });

  it('conta só as aulas concluídas DAQUELA trilha', () => {
    const p = trackProgress(tempo, ['seguranca-intro', 'tempo-intro']);
    expect(p.done).toBe(1); // seguranca-intro não é da trilha Meu Tempo
    expect(p.nextLessonId).toBe('fazer-pausas');
  });

  it('parcial calcula o percentual arredondado', () => {
    const p = trackProgress(seguro, ['seguranca-intro']);
    expect(p.done).toBe(1);
    expect(p.pct).toBe(25); // 1/4
    expect(p.nextLessonId).toBe('senha-secreta');
  });

  it('100% e sem próxima quando tudo concluído', () => {
    const p = trackProgress(tempo, [...tempo.lessonIds]);
    expect(p.pct).toBe(100);
    expect(p.done).toBe(4);
    expect(p.nextLessonId).toBeNull();
  });

  it('nextLessonId respeita a ordem (pula as já concluídas do meio)', () => {
    const p = trackProgress(seguro, ['seguranca-intro', 'falar-com-adulto']);
    // senha-secreta (2ª) está sem concluir → é a próxima
    expect(p.nextLessonId).toBe('senha-secreta');
  });
});
