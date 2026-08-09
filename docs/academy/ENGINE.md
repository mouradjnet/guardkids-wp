# Academy Engine — referência

Como o Academy contextual decide o que ensinar, onde o progresso mora e como
estender. Vale para a Onda 1; a Onda 2 (trilhas/aulas com quiz) constrói por cima
disto sem reescrever.

## Ideia em uma frase

O app sabe **em que tela você está** e **qual o estado da família**, e sugere a
**próxima aula** — reusando o estado que o painel já carrega, sem backend novo
para decidir.

## O fluxo

```
activePage (App.tsx)
        │
        ▼
useAcademyContext(screen)  ── lê ['children'] e ['sites','all'] (cache compartilhado)
        │                     + GET /academy/progress
        ▼
AcademyContext  { screen, family, completedLessonIds, dismissed }
        │
        ▼
recommend(ctx)  ── função PURA (academy/engine.ts)
        │
        ▼
AcademyRecommendation | null
        │
        ▼
AcademyButton  ── pílula flutuante só quando != null
        │
        ▼
AcademyPanel   ── aula em markdown + "Concluí" / "Agora não"
        │
        ▼
completeLesson/dismissLesson → POST /academy/progress → invalida a query
        │
        ▼
recommend() recalcula → a recomendação some ou dá lugar à próxima
```

## O contrato (`academy/engine.ts`)

```ts
interface FamilyState {
  childrenCount: number;
  hasPairedDevice: boolean;   // algum filho com Child.paired
  hasSiteRules: boolean;      // existe ao menos uma regra de site
  hasTimeLimits: boolean;     // limite diário OU hora de dormir ativos
}

interface AcademyContext {
  screen: PageId;
  family: FamilyState;
  completedLessonIds: string[];
  dismissed: string[];
}

function recommend(ctx: AcademyContext): AcademyRecommendation | null;
```

`recommend` é **pura** — sem I/O, sem `Date.now()`, sem `fetch`. Isso a torna
trivial de testar e impossível de "quebrar em produção" por causa de rede.

## Tabela de regras (Onda 1)

Cada regra é `contexto + estado → aula`, com uma prioridade. O engine escolhe a de
**maior prioridade** entre as aplicáveis e ainda **não** concluídas/dispensadas.

| Prio | Tela                     | Condição                          | Aula                        |
| ---- | ------------------------ | --------------------------------- | --------------------------- |
| 100  | dashboard                | 0 filhos                          | `primeiros-passos`          |
| 90   | children                 | tem filho, sem aparelho pareado   | `dispositivo-filho`         |
| 80   | sites-rules / protection | sem regra de site                 | `gerenciamento-permissoes`  |
| 80   | time                     | sem limite de tempo               | `configuracao-preferencias` |
| 40   | settings                 | (sempre)                          | `configuracao-responsavel`  |
| 30   | dashboard                | aparelho pareado                  | `verificacao-conexao`       |

### Regra de ouro

Recomendações de **configuração** somem quando o **estado real muda**, não quando
o pai "leu o texto". Ex.: `dispositivo-filho` deixa de aparecer no instante em que
`hasPairedDevice` vira `true` — a condição da regra simplesmente para de casar.
`completedLessonIds`/`dismissed` servem para silenciar as aulas **informativas**
(settings, verificação), que não têm um estado que as resolva sozinhas.

## Onde o progresso mora

- **Backend:** `AcademyController` grava em `wp_usermeta`, chave
  `guardkids_academy_progress`, valor `{ completed: string[], dismissed: string[] }`.
- **Isolamento:** a meta vive na linha do próprio usuário (`get_current_user_id`),
  então um guardião **nunca** lê o progresso de outro. Coberto por
  `AcademyControllerTest::testProgressIsIsolatedBetweenGuardians`.
- **Auth:** `RestApi::requireCollaboratorOrAbove` — reusa `GuardianAuth`, sem login
  novo. Anônimo recebe 401 na escrita.
- **Sem migration** na Onda 1. A Onda 2 troca o usermeta por tabela dedicada
  quando houver trilhas/aulas com ordenação.

## Como estender

### Adicionar uma AULA

1. Em `academy/lessons.ts`, acrescente um objeto ao array `LESSONS` com um `id`
   **estável** (kebab-case), `title`, `summary`, `category` e `body` (markdown).
2. O `body` aceita: `#`/`##` títulos, listas `-` e `1.`, `**negrito**`, parágrafos
   (ver `academy/markdown.tsx`). **Não** use HTML cru.
3. Nada mais — a aula já pode ser referenciada por uma regra.

### Adicionar uma REGRA (contexto → aula)

1. Em `academy/engine.ts`, adicione uma entrada ao array `RULES`:
   `{ lessonId, title, reason, cta, priority, when: (ctx) => ... }`.
2. `when` deve ler só `ctx` (puro). Escolha a `priority` relativa à tabela acima.
3. Se a regra depende de um sinal novo (ex.: "tem alerta não lido"), acrescente o
   campo em `FamilyState` **e** derive-o em `academy/useAcademyContext.ts` a partir
   de uma query que o app já faça (ou uma nova, com `queryKey` compartilhável).
4. Escreva o teste em `academy/engine.test.ts` — inclusive o caso em que a regra
   **some** quando o estado muda.

### Colocar o botão em outra tela

O `AcademyButton` já é global (montado no `App.tsx`, recebe `activePage`). Basta
existir uma regra para aquela `PageId` que ele aparece — não há nada por tela a
configurar.

## Trilhas (Onda 2)

A **Academia** (`pages/Academy.tsx`) agrupa as aulas em **trilhas** ordenadas
(`academy/tracks.ts`). A trilha é a fonte da ordem (`Track.lessonIds`); o progresso
(`trackProgress`) é derivado do **mesmo `completed`** do usermeta — concluir uma aula
pela pílula contextual OU pela Academia conta igual. Sem tabela nova.

### Adicionar uma aula a uma trilha
1. Crie a aula em `lessons.ts` (id estável).
2. Coloque o id na posição desejada de `Track.lessonIds` em `tracks.ts`.
3. O teste de integridade (`tracks.test.ts`) garante que todo id referenciado existe.

### Adicionar / liberar uma trilha
- Nova trilha: item em `TRACKS` com `status: 'available'` e a lista ordenada de
  `lessonIds`. `coming-soon` = `lessonIds: []` (aparece bloqueada com selo "Em breve").

## Arquivos

| Papel                    | Arquivo                                            |
| ------------------------ | -------------------------------------------------- |
| Regras + decisão (puro)  | `public/app-parent/src/academy/engine.ts`          |
| Catálogo de aulas        | `public/app-parent/src/academy/lessons.ts`         |
| Monta o contexto real    | `public/app-parent/src/academy/useAcademyContext.ts` |
| Markdown seguro          | `public/app-parent/src/academy/markdown.tsx`       |
| Cliente da API           | `public/app-parent/src/api/academy.ts`             |
| UI — botão contextual    | `public/app-parent/src/components/AcademyButton.tsx` |
| UI — painel da aula      | `public/app-parent/src/components/AcademyPanel.tsx` |
| Progresso (backend)      | `api/Controllers/AcademyController.php`             |
| Rota REST                | `api/RestApi.php` (`registerAcademyRoutes`)         |
