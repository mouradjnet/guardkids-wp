import { describe, expect, it } from 'vitest';

import { recommend, type AcademyContext, type FamilyState } from './engine';

const emptyFamily: FamilyState = {
  childrenCount: 0,
  hasPairedDevice: false,
  hasSiteRules: false,
  hasTimeLimits: false,
};

function ctx(overrides: Partial<AcademyContext> = {}): AcademyContext {
  return {
    screen: overrides.screen ?? 'dashboard',
    family: { ...emptyFamily, ...(overrides.family ?? {}) },
    completedLessonIds: overrides.completedLessonIds ?? [],
    dismissed: overrides.dismissed ?? [],
  };
}

describe('academy/engine recommend', () => {
  it('sugere Primeiros Passos no dashboard sem filhos', () => {
    const rec = recommend(ctx({ screen: 'dashboard', family: emptyFamily }));
    expect(rec?.lessonId).toBe('primeiros-passos');
  });

  it('sugere proteger o aparelho em children com filho e sem device pareado', () => {
    const rec = recommend(
      ctx({ screen: 'children', family: { childrenCount: 1 } as FamilyState }),
    );
    expect(rec?.lessonId).toBe('dispositivo-filho');
  });

  it('REGRA DE OURO: a recomendação some quando o aparelho é pareado de verdade', () => {
    const antes = recommend(
      ctx({ screen: 'children', family: { childrenCount: 1 } as FamilyState }),
    );
    expect(antes?.lessonId).toBe('dispositivo-filho');

    const depois = recommend(
      ctx({
        screen: 'children',
        family: { childrenCount: 1, hasPairedDevice: true } as FamilyState,
      }),
    );
    expect(depois).toBeNull();
  });

  it('sugere permissões em sites-rules sem nenhuma regra', () => {
    const rec = recommend(ctx({ screen: 'sites-rules' }));
    expect(rec?.lessonId).toBe('gerenciamento-permissoes');
  });

  it('some quando já existe regra de site', () => {
    const rec = recommend(
      ctx({ screen: 'sites-rules', family: { hasSiteRules: true } as FamilyState }),
    );
    expect(rec).toBeNull();
  });

  it('sugere tempo de tela em time sem limites', () => {
    const rec = recommend(ctx({ screen: 'time' }));
    expect(rec?.lessonId).toBe('configuracao-preferencias');
  });

  it('sugere verificação de conexão no dashboard de uma família já configurada', () => {
    const rec = recommend(
      ctx({
        screen: 'dashboard',
        family: { childrenCount: 1, hasPairedDevice: true } as FamilyState,
      }),
    );
    expect(rec?.lessonId).toBe('verificacao-conexao');
  });

  it('não sugere uma aula já concluída', () => {
    const rec = recommend(
      ctx({ screen: 'dashboard', completedLessonIds: ['primeiros-passos'] }),
    );
    expect(rec).toBeNull();
  });

  it('não sugere uma recomendação dispensada', () => {
    const rec = recommend(
      ctx({ screen: 'dashboard', dismissed: ['primeiros-passos'] }),
    );
    expect(rec).toBeNull();
  });

  it('retorna null quando nenhuma regra se aplica (tela neutra)', () => {
    const rec = recommend(ctx({ screen: 'reports' }));
    expect(rec).toBeNull();
  });

  it('escolhe a de maior prioridade quando duas regras batem no mesmo contexto', () => {
    // dashboard + 0 filhos casa só com Primeiros Passos (100). Com device pareado
    // e ainda 0 filhos, Primeiros Passos (100) ganha de Verificação (30).
    const rec = recommend(
      ctx({ screen: 'dashboard', family: { hasPairedDevice: true } as FamilyState }),
    );
    expect(rec?.lessonId).toBe('primeiros-passos');
    expect(rec?.priority).toBe(100);
  });
});
