// Trilhas do Academy da criança (Onda 3).
//
// A trilha é a fonte da ORDEM: `lessonIds` é uma lista ordenada de ids de aula
// (de lessons.ts). O progresso é derivado das aulas concluídas (ledger no
// servidor). Concluir uma aula credita XP/coins na carteira que a gamificação
// já usa. Sem tabela nova no cliente — o servidor guarda o ledger.

export interface Track {
  id: string;
  title: string;
  description: string;
  /** material symbol */
  icon: string;
  /** ids de aula em ordem */
  lessonIds: string[];
}

export const TRACKS: Track[] = [
  {
    id: 'digital-seguro',
    title: 'Meu Mundo Digital Seguro',
    description: 'Aprenda a se cuidar e a pedir ajuda na internet.',
    icon: 'shield',
    lessonIds: [
      'seguranca-intro',
      'senha-secreta',
      'falar-com-adulto',
      'pistas-de-golpe',
    ],
  },
  {
    id: 'meu-tempo',
    title: 'Meu Tempo de Tela',
    description: 'Descubra como usar a tela com equilíbrio e se divertir mais.',
    icon: 'timer',
    lessonIds: [
      'tempo-intro',
      'fazer-pausas',
      'tela-e-sono',
      'brincar-sem-tela',
    ],
  },
];

export interface TrackProgress {
  total: number;
  done: number;
  /** 0..100, inteiro */
  pct: number;
  /** primeira aula ainda não concluída, ou null se trilha vazia/concluída */
  nextLessonId: string | null;
}

/**
 * Progresso de uma trilha a partir das aulas concluídas. Puro — sem I/O.
 */
export function trackProgress(track: Track, completedIds: string[]): TrackProgress {
  const total = track.lessonIds.length;
  const done = track.lessonIds.filter((id) => completedIds.includes(id)).length;
  const pct = total === 0 ? 0 : Math.round((done / total) * 100);
  const nextLessonId = track.lessonIds.find((id) => !completedIds.includes(id)) ?? null;

  return { total, done, pct, nextLessonId };
}

/** Busca uma trilha pelo id. */
export function findTrack(id: string): Track | undefined {
  return TRACKS.find((track) => track.id === id);
}
