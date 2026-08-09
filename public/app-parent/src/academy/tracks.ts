// Trilhas do Academy (Onda 2).
//
// A trilha é a fonte da ORDEM: `lessonIds` é uma lista ordenada de ids de aula
// (de lessons.ts). O progresso é derivado do mesmo `completed` (usermeta) da
// Onda 1 — concluir uma aula pela pílula contextual OU pela Academia conta igual.
// Sem tabela nova.

export interface Track {
  id: string;
  title: string;
  description: string;
  /** material symbol */
  icon: string;
  status: 'available' | 'coming-soon';
  /** ids de aula em ordem; vazio quando coming-soon */
  lessonIds: string[];
}

export const TRACKS: Track[] = [
  {
    id: 'primeiros-passos',
    title: 'Primeiros Passos',
    description: 'Do zero à proteção: cadastre, conecte e configure o essencial.',
    icon: 'flag',
    status: 'available',
    lessonIds: [
      'primeiros-passos',
      'instalacao-inicial',
      'configuracao-responsavel',
      'cadastrar-crianca',
      'dispositivo-filho',
      'gerenciamento-permissoes',
      'primeira-regra',
      'verificacao-conexao',
    ],
  },
  {
    id: 'tempo-de-tela',
    title: 'Tempo de Tela',
    description: 'Crie uma rotina digital saudável e sem brigas.',
    icon: 'timer',
    status: 'available',
    lessonIds: [
      'tempo-o-que-e',
      'tempo-rotina',
      'tempo-escolar',
      'tempo-descanso',
      'tempo-livre',
      'tempo-por-app',
      'tempo-conversa',
      'tempo-avaliacao',
    ],
  },
  {
    id: 'seguranca-digital',
    title: 'Segurança Digital',
    description: 'Como orientar a criança sobre golpes, senhas e privacidade.',
    icon: 'security',
    status: 'coming-soon',
    lessonIds: [],
  },
  {
    id: 'educacao-familiar',
    title: 'Educação Familiar',
    description: 'Combinar regras, criar confiança e acompanhar sem invadir.',
    icon: 'diversity_3',
    status: 'coming-soon',
    lessonIds: [],
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
