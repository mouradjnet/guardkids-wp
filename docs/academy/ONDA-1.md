# PLANO — ONDA 1

## Academy Contextual: Engine + AcademyButton

**Alvo:** `guardkids-wp` v1.36.17 · app-parent · branch `feat/academy-onda-1` (aditivo, sem tocar no que existe)

---

## Objetivo

Entregar o **diferencial** do Academy — o app sabe em que tela você está e qual seu
estado, e sugere o próximo passo, ensinando ali — reusando os 7 tutoriais que já
existem em `E:\GuardiaoKids-Academy\04-CONTEUDOS\Tutoriais`. No ar, testável, **sem
banco novo de conteúdo, sem gamificação nova, sem IA.**

## O que a Onda 1 **NÃO** inclui (fica para depois)

- Trilhas/aulas com quiz (Onda 2/4)
- XP novo (reusa o existente só na Onda 2)
- Academy da criança (Onda 3)
- IA (Onda 5)
- Nenhuma migration de conteúdo

---

## Decisões de arquitetura (à luz do código real auditado)

1. **Engine é client-side (TypeScript).** O contexto = `activePage` (já existe no
   `App.tsx`, navegação por estado, não react-router); o estado da família = queries
   que o app **já carrega** (`listChildren`, companion, sites, limits). O Engine é uma
   **função pura** que não faz request para decidir. Integração "contextual" sem
   acoplar ao backend e sem tocar no core.
2. **Conteúdo estático e versionado.** Os 7 tutoriais viram um catálogo `lessons.ts`
   (título + markdown + a qual contexto pertencem), empacotado no bundle. Zero tabela
   nova.
3. **Progresso do responsável = mínimo.** "Aula concluída / recomendação dispensada"
   persiste em `wp_usermeta` via um controller enxuto — **sem migration** na Onda 1
   (tabela dedicada só na Onda 2, quando houver trilhas).
4. **Regra de ouro:** a recomendação de configuração só se marca como "feita" quando o
   **estado real muda** (ex.: passou a existir dispositivo pareado) — não quando o pai
   "leu o texto".

---

## O `AcademyEngine` — contrato

```ts
// public/app-parent/src/academy/engine.ts
export type AcademyContext = {
  screen: PageId;                 // = activePage do App.tsx
  family: {
    childrenCount: number;
    hasPairedDevice: boolean;     // de companion/children
    hasSiteRules: boolean;
    hasTimeLimits: boolean;
  };
  completedLessonIds: string[];   // do usermeta
  dismissed: string[];            // recomendacoes dispensadas
};

export type AcademyRecommendation = {
  lessonId: string;
  title: string;
  reason: string;   // "Voce cadastrou um filho, mas nenhum aparelho esta protegido."
  cta: string;      // "Aprender agora"
  priority: number;
} | null;

// funcao PURA, sem I/O — testavel isolada
export function recommend(ctx: AcademyContext): AcademyRecommendation;
```

A lógica é uma **tabela de regras** `contexto + estado → aula`, priorizada:

| Tela ativa  | Estado                                | Recomenda                        |
| ----------- | ------------------------------------- | -------------------------------- |
| dashboard   | 0 filhos                              | Primeiros Passos                 |
| children    | filho existe, sem aparelho pareado    | Configuração do Dispositivo Filho |
| sites-rules | sem regra                             | Gerenciamento de Permissões      |
| time        | sem limite                            | Configuração de Preferências     |
| dashboard   | aparelho pareado                      | Verificação de Conexão           |

## Mapa dos 7 tutoriais → contexto

| Tutorial                          | Contexto            |
| --------------------------------- | ------------------- |
| 01 Primeiros Passos               | dashboard (vazio)   |
| 02 Instalação Inicial             | dashboard           |
| 03 Configuração do Responsável    | settings            |
| 04 Configuração do Dispositivo Filho | children         |
| 05 Gerenciamento de Permissões    | sites-rules / protection |
| 06 Configuração de Preferências   | time / settings     |
| 07 Verificação de Conexão         | dashboard (pareado) |

---

## Arquivos a **criar**

**Frontend (`public/app-parent/src/`):**

- `academy/engine.ts` — a função `recommend` (pura)
- `academy/engine.test.ts` — testes das regras (vitest)
- `academy/lessons.ts` — catálogo dos 7 tutoriais (título + markdown + contexto)
- `academy/useAcademyContext.ts` — hook que monta o `AcademyContext` a partir das queries já existentes
- `api/academy.ts` — `getAcademyProgress()` / `completeLesson(id)` / `dismiss(id)` (usando o `apiFetch` já existente)
- `components/AcademyButton.tsx` — botão flutuante global (design system atual)
- `components/AcademyPanel.tsx` — painel que renderiza a recomendação + a aula em markdown
- `.test.tsx` de cada componente

**Backend:**

- `api/Controllers/AcademyController.php` — `GET/POST guardkids/v1/academy/progress` lendo/gravando em `wp_usermeta` (namespaced por guardião). Reusa `GuardianAuth` (sem login novo).
- `tests/` PHPUnit do controller (isolamento por guardião)

**Docs:** `docs/academy/ONDA-1.md` (este plano) + `docs/academy/ENGINE.md` (as regras)

## Arquivos a **modificar** (aditivo, não invasivo)

- `App.tsx` — montar `<AcademyButton />` global (1 linha no layout; não mexe no `PageRenderer`). Passa `activePage` como contexto.
- `api/RestApi.php` — registrar o `AcademyController` (segue o padrão dos 28 controllers existentes)
- **Sem** bump de `GUARDKIDS_DB_VERSION` (não há migration na Onda 1)

---

## Testes (definição de "pronto" ≠ "a tela abriu")

- **Engine (vitest):** cada regra da tabela — inclusive precedência e "não recomendar o que já foi concluído"
- **Isolamento (PHPUnit):** guardião A nunca lê progresso do guardião B (usermeta namespaced)
- **Componente:** AcademyButton aparece nas telas certas; painel renderiza a aula; "dispensar" persiste
- **Falsificação:** cada teste novo tem que falhar antes da implementação

## Critérios de aceite da Onda 1

1. Em `dashboard` sem filhos → aparece "Primeiros Passos"
2. Cadastrou filho, sem aparelho → em `children` aparece "Proteja o primeiro aparelho"
3. Abriu a aula, leu, e **pareou o aparelho de verdade** → a recomendação some (estado real mudou)
4. Progresso persiste entre sessões e é isolado por família
5. Visual integrado (design system atual), funcionando em mobile e desktop
6. `pnpm test` (vitest) e PHPUnit verdes

## Sequência de trabalho (incremental, 1 commit por passo)

1. `lessons.ts` + `engine.ts` + testes do engine → commit
2. `AcademyController` + testes de isolamento → commit
3. `useAcademyContext` + `api/academy.ts` → commit
4. `AcademyButton` + `AcademyPanel` + testes → commit
5. Montagem no `App.tsx` + registro no `RestApi.php` → commit
6. `docs/academy/` → commit
7. Gate final: `pnpm test` + `vendor/bin/phpunit` verdes → PR

**Esforço:** pequeno — dias, não semanas. Nenhuma dependência nova, nenhuma migration,
nenhum risco ao que está em produção (branch isolada + tudo aditivo).
