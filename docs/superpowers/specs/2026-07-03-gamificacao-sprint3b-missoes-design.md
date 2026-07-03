# Sprint 3b — Missões (Gamificação) — Design

**Data:** 2026-07-03
**Projeto:** guardkids-wp
**Fatia:** 3b (Missões), continuação da fundação 3a (economia/progressão)
**Status:** aprovado no brainstorming; pronto pra plano de implementação.

## Contexto

A fatia 3a (v1.29.0, em prod, DB v18) entregou a fundação da gamificação:

- Tabela `progression` (carteira por filho: `xp`, `coins`, `streak_days`, `last_activity_date`).
- Tabela `progression_awards` (ledger anti-farm: uma linha por `(child_id, content_id, award_date)` via `UNIQUE once_per_day`).
- `includes/Progression/LevelCurve.php` — curva de nível 1–100, pura.
- `includes/Progression/Progression.php` — engine `awardForOpen(childId, contentId, now)`, hookado em `ContentController::childHistory` quando `action='open'`.
- `GET /child/progression` (token do filho) → carteira; `GET /progression?child_id=` (admin) → carteira **+ `missionsCompleted: 0`** (placeholder já reservado pra esta fatia).
- App-filho: `ProgressCard` no topo da Home. App-pais: `GamificationDashboard` (card por filho).

**Única fonte de ganho hoje:** abrir conteúdo distinto do "Mundo Guardião".

Esta fatia adiciona **Missões diárias automáticas** que dão metas à criança e um bônus ao completar.

## Decisões (do brainstorming)

1. **Fonte:** missões **automáticas do sistema** — definidas no código, derivadas de sinais que já existem. Sem missões criadas pelos pais nesta fatia.
2. **Recorrência:** **diárias que resetam** à meia-noite. Estado por `(filho, missão, dia)`.
3. **Recompensa:** **bônus de conclusão único por dia** (XP/coins), por cima do ganho por conteúdo. Ledger anti-duplo.
4. **Catálogo:** **3 missões curadas** (volume + variedade + hábito).
5. **Arquitetura:** **calcular no read + creditar preguiçoso e idempotente** (abordagem A). Catálogo/avaliador puros + 1 tabela de ledger. Sem cron. Sem tabela de definição de missão.

## Catálogo de missões (constantes no código)

| key            | título            | alvo | sinal                                   | bônus            |
|----------------|-------------------|------|-----------------------------------------|------------------|
| `explore_3`    | Explorador do dia | 3    | conteúdos distintos abertos hoje        | +15 XP / +10 coins |
| `categories_2` | Curioso           | 2    | categorias distintas exploradas hoje    | +15 XP / +10 coins |
| `streak_today` | Presença          | 1    | teve atividade hoje (mantém a sequência)| +10 XP / +5 coins  |

Valores de bônus são ajustáveis num único ponto (`MissionCatalog`).

## Modelo de dados

**Migração 019** (`database/migrations/019_daily_missions.php`), com **bump `GUARDKIDS_DB_VERSION` 18 → 19** em `guardkids.php` no mesmo commit (obrigatório, senão `maybeRunMigrations` skipa).

```sql
CREATE TABLE IF NOT EXISTS wp_guardkids_mission_completions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    child_id        BIGINT UNSIGNED NOT NULL,
    mission_key     VARCHAR(40) NOT NULL,      -- 'explore_3' | 'categories_2' | 'streak_today'
    completion_date DATE NOT NULL,
    xp              INT NOT NULL DEFAULT 0,     -- snapshot do bônus creditado (auditoria)
    coins           INT NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY once_per_day (child_id, mission_key, completion_date),
    KEY child (child_id)
) {charsetCollate};
```

- O `UNIQUE(child_id, mission_key, completion_date)` garante que o mesmo bônus nunca seja creditado duas vezes no dia, mesmo com requests concorrentes.
- Espelho exato do `progression_awards` (só `created_at`, sem `updated_at` → insert próprio no repo).
- `uninstall.php` ganha o `DROP TABLE` dessa tabela.
- As **definições** das 3 missões NÃO vão pro banco — ficam como constantes no `MissionCatalog`.

## Backend

Estrutura espelha `includes/Progression/` (peças puras) + repo + controller.

### `includes/Missions/MissionCatalog.php` (puro)

Define as 3 missões como constantes (`key`, `title`, `description`, `icon`, `target`, `xpReward`, `coinsReward`). `all(): array` devolve a lista. Sem estado, sem `$wpdb`.

### `includes/Missions/MissionEvaluator.php` (puro)

Recebe **sinais já computados** (não toca no banco) e devolve o estado de cada missão:

```
evaluate(signals): array   // por missão: {key, title, description, icon, target, progress, completed}
```

onde `signals = { contentOpenedToday:int, categoriesToday:int, streakActiveToday:bool }`.
Regra de conclusão: `progress >= target`. Função pura → cobre todas as regras em teste unitário sem MySQL.

### `database/MissionCompletionRepository.php` (estende `Repository`, padrão `AwardRepository`)

- `existsFor(childId, key, date): bool`
- `record(childId, key, date, xp, coins): int` — insert próprio (só `created_at`).
- `countCompleted(childId): int` — para o `missionsCompleted` do app-pais.
- **Leitura dos sinais** (queries diretas, mesmo estilo dos outros repos):
  - `contentOpenedToday` = `COUNT(*)` em `progression_awards` (`child_id` + `award_date = hoje`). Como o `progression_awards` já é uma linha por conteúdo/dia (UNIQUE), `COUNT(*)` já é o número de conteúdos distintos.
  - `categoriesToday` = `COUNT(DISTINCT c.category_id)` no join `progression_awards a JOIN content_items c ON a.content_id = c.id` (`child_id` + hoje + `category_id IS NOT NULL`).
  - `streakActiveToday` = `progression.last_activity_date == hoje`.

### `api/Controllers/MissionController.php`

- **`GET /child/missions`** (token do filho):
  1. Resolve `childId` (401 se token inválido).
  2. Lê os sinais via `MissionCompletionRepository`.
  3. `MissionEvaluator::evaluate(signals)`.
  4. Para cada missão **completada e ainda não no ledger** (`existsFor` false): `record()` + credita o bônus na carteira via `ProgressionRepository::apply()` com o XP/coins do bônus, **mantendo o `streak_days` e o `last_activity_date` atuais** (o bônus não altera o streak).
  5. O crédito preguiçoso segue o modelo do `awardForOpen`: envolto em `try/catch`, falha nunca quebra a resposta.
  6. Devolve `[{ key, title, description, icon, target, progress, completed, justCompleted, xpReward, coinsReward }]`, onde `justCompleted` marca a transição nesta chamada.
- Idempotência: chamar o endpoint N vezes no mesmo dia credita o bônus **uma única vez** por missão (garantido pelo `existsFor` + `UNIQUE`).

### `GamificationController::progression` (mudança em código existente)

Troca o `missionsCompleted => 0` hardcoded por `MissionCompletionRepository::countCompleted($childId)`.

### Rotas — `RestApi::registerGamificationRoutes()`

Adiciona `/child/missions` (permission `ChildAuth::requireToken`) ao lado de `/child/progression`.

**Acoplamento:** a única linha que muda em código existente é o `missionsCompleted` no `GamificationController`. Todo o resto é aditivo (arquivos novos + 1 rota). **Nada no caminho quente do `childHistory`.**

## Frontend

### App-filho (`public/app-child/`)

- `api/gamification.ts`: `type Mission` + `getMissions(): Promise<Mission[]>`.
- **`components/MissionsCard.tsx`** (novo): lista as 3 missões do dia; cada uma com ícone, título, **barra de progresso** `progress/target` e selo ✓ quando `completed`. Visual do `ProgressCard` (mesmos tokens `rounded-2xl bg-surface-container`, componente `Icon`). Quando `justCompleted === true`, um destaque simples (badge "+XP"/cor) na missão recém-batida — sem animação pesada.
- Posição: **logo abaixo do `ProgressCard` na Home**. Sem tela nova nem item de nav. `useQuery(['child','missions'])` com refetch quando a Home ganha foco (dispara o crédito preguiçoso ao voltar à tela).

### App-pais (`public/app-parent/`)

- `api/gamification.ts`: o tipo do parent progression ganha `missionsCompleted: number`.
- `pages/GamificationDashboard.tsx`: o card de cada filho passa a mostrar **"Missões concluídas: N"** ao lado de nível/XP/coins/streak. Sem CRUD (missões são automáticas).

**Racional (card na Home vs tela dedicada):** 3 missões diárias cabem num card e ganham mais visibilidade ali do que atrás de um clique. Aba dedicada com histórico fica como incremento futuro se o catálogo crescer.

## Testes

Padrão da 3a: peças puras cobertas a fundo; repos/controllers com FakeWpdb/reflection; front com vitest.

- **PHP unit** (sem MySQL):
  - `MissionCatalogTest` — as 3 missões existem com keys/alvos/bônus esperados.
  - `MissionEvaluatorTest` — matriz de sinais: nada feito, parcial, exatamente no alvo, acima do alvo, os 3 completos; assere `progress`/`completed` por missão (coração da lógica, cobertura densa).
  - `MissionCompletionRepositoryTest` — `existsFor`/`record`/`countCompleted` via FakeWpdb.
  - `MissionControllerTest` — token inválido → 401; crédito idempotente (2 chamadas no mesmo dia = 1 linha no ledger, bônus creditado uma vez); missão não-completa não credita; `justCompleted` só na transição.
  - `GamificationControllerTest` — `missionsCompleted` reflete o ledger (não mais `0`).
- **vitest app-child**: `MissionsCard` — loading, 3 missões com barra, selo ✓ quando completa, estado `justCompleted`.
- **vitest app-parent**: `GamificationDashboard` — mostra "Missões concluídas: N".
- **Integration** (MySQL real, CI): migração 019 cria a tabela; fluxo mínimo de conclusão persiste.

## Escopo

**Dentro do 3b:**
- Migração 019 + bump DB v19; tabela `mission_completions`; drop no `uninstall.php`.
- `MissionCatalog` + `MissionEvaluator` (puros), `MissionCompletionRepository`, `MissionController` + rota `/child/missions`.
- `missionsCompleted` real no endpoint dos pais.
- `MissionsCard` no app-filho + número no `GamificationDashboard`.

**Fora do 3b (fatias futuras, YAGNI agora):**
- Missões criadas pelos pais / CRUD.
- Missões semanais, tela dedicada com histórico, animações de conclusão.
- Missões que dependem de novos sinais (tempo de tela, favoritos).

## Entregável

Vertical slice aditivo — só a linha do `missionsCompleted` toca código existente; nada no caminho quente do `childHistory`; sem cron. PR único, mesmo fluxo da 3a. Após merge: release + deploy SSH (`wp plugin install --force`), migração idempotente (`CREATE TABLE IF NOT EXISTS`).
