// Academy Engine (Onda 1) — o coração contextual.
//
// Função PURA: recebe a tela ativa + o estado da família + o progresso e devolve
// a recomendação do próximo passo (ou null). Sem I/O, sem request — o contexto e
// o estado já vivem no app-parent (activePage + queries do TanStack). Isso mantém
// a integração "contextual" sem acoplar ao backend nem tocar no core.
//
// Regra de ouro: recomendações de configuração somem sozinhas quando o ESTADO REAL
// muda (ex.: passou a existir aparelho pareado) — não quando o pai só "leu o texto".

import type { PageId } from '../data/mockData';

export interface FamilyState {
  childrenCount: number;
  hasPairedDevice: boolean;
  hasSiteRules: boolean;
  hasTimeLimits: boolean;
}

export interface AcademyContext {
  screen: PageId;
  family: FamilyState;
  /** aulas que o responsável marcou como concluídas (não voltam a ser sugeridas) */
  completedLessonIds: string[];
  /** recomendações dispensadas nesta jornada */
  dismissed: string[];
}

export interface AcademyRecommendation {
  lessonId: string;
  title: string;
  reason: string;
  cta: string;
  /** maior = mais urgente */
  priority: number;
}

interface Rule {
  lessonId: string;
  title: string;
  reason: string;
  cta: string;
  priority: number;
  /** true quando esta regra se aplica ao contexto/estado atual */
  when: (ctx: AcademyContext) => boolean;
}

// Tabela de regras: contexto + estado -> aula. Ordem não importa; o engine escolhe
// a de maior prioridade entre as aplicáveis e ainda não resolvidas.
const RULES: Rule[] = [
  {
    lessonId: 'primeiros-passos',
    title: 'Primeiros Passos',
    reason: 'Comece por aqui: você ainda não cadastrou nenhum filho.',
    cta: 'Aprender agora',
    priority: 100,
    when: (ctx) => ctx.screen === 'dashboard' && ctx.family.childrenCount === 0,
  },
  {
    lessonId: 'dispositivo-filho',
    title: 'Proteja o primeiro aparelho',
    reason: 'Você cadastrou um filho, mas nenhum aparelho está protegido ainda.',
    cta: 'Aprender agora',
    priority: 90,
    when: (ctx) =>
      ctx.screen === 'children' &&
      ctx.family.childrenCount > 0 &&
      !ctx.family.hasPairedDevice,
  },
  {
    lessonId: 'gerenciamento-permissoes',
    title: 'Gerenciamento de Permissões',
    reason: 'Nenhuma regra de sites foi criada ainda.',
    cta: 'Aprender agora',
    priority: 80,
    when: (ctx) =>
      (ctx.screen === 'sites-rules' || ctx.screen === 'protection') &&
      !ctx.family.hasSiteRules,
  },
  {
    lessonId: 'configuracao-preferencias',
    title: 'Configure o tempo de tela',
    reason: 'Você ainda não definiu limites de tempo para a criança.',
    cta: 'Aprender agora',
    priority: 80,
    when: (ctx) => ctx.screen === 'time' && !ctx.family.hasTimeLimits,
  },
  {
    lessonId: 'configuracao-responsavel',
    title: 'Configuração do Responsável',
    reason: 'Revise sua conta e as preferências do responsável.',
    cta: 'Ver dicas',
    priority: 40,
    when: (ctx) => ctx.screen === 'settings',
  },
  {
    lessonId: 'verificacao-conexao',
    title: 'Verificação de Conexão',
    reason: 'Confirme que a proteção está funcionando corretamente.',
    cta: 'Ver dicas',
    priority: 30,
    when: (ctx) => ctx.screen === 'dashboard' && ctx.family.hasPairedDevice,
  },
];

/**
 * Decide a próxima aula recomendada para o contexto atual, ou `null` quando não há
 * o que sugerir. Ignora aulas já concluídas ou recomendações dispensadas.
 */
export function recommend(ctx: AcademyContext): AcademyRecommendation | null {
  const applicable = RULES.filter(
    (rule) =>
      rule.when(ctx) &&
      !ctx.completedLessonIds.includes(rule.lessonId) &&
      !ctx.dismissed.includes(rule.lessonId),
  );

  if (applicable.length === 0) {
    return null;
  }

  const best = applicable.reduce((top, rule) =>
    rule.priority > top.priority ? rule : top,
  );

  return {
    lessonId: best.lessonId,
    title: best.title,
    reason: best.reason,
    cta: best.cta,
    priority: best.priority,
  };
}
