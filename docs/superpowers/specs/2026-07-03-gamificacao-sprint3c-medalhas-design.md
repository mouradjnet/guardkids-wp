# Sprint 3c — Medalhas (Gamificação) — Design

**Data:** 2026-07-03
**Projeto:** guardkids-wp
**Fatia:** 3c (Medalhas), continuação de 3a (economia/progressão) e 3b (missões diárias)
**Status:** aprovado no brainstorming; pronto pra plano de implementação.

## Contexto

- **3a** (v1.29.0): fundação — `progression` (xp/coins/streak_days/last_activity_date), `progression_awards` (ledger de opens), `LevelCurve` (níveis 1–100, puro), `Progression::awardForOpen`.
- **3b** (v1.30.0, em prod, DB v19): missões diárias — `MissionCatalog`/`MissionEvaluator` (puros), `mission_completions` (ledger anti-duplo), `MissionCompletionRepository`, `MissionController` + `GET /child/missions` (crédito preguiçoso idempotente), `MissionsCard` no app-filho, `missionsCompleted` no endpoint dos pais.

Esta fatia adiciona **Medalhas**: conquistas permanentes automáticas desbloqueadas ao atingir marcos acumulados. É o loop de longo prazo, complementar às missões diárias (curto prazo).

**Sinais disponíveis (já existem):** `level` (via `LevelCurve::levelForXp(xp)`), `streak_days` (streak atual da carteira), total de conteúdos abertos (`progression_awards`), total de missões (`mission_completions`), categorias distintas exploradas (join `progression_awards × content_items`).

## Decisões (do brainstorming)

1. **Natureza:** medalhas **permanentes automáticas** do sistema (flat, sem tiers formais). Desbloqueadas de uma vez ao atingir o marco; ficam pra sempre. Sem medalhas criadas pelos pais.
2. **Catálogo:** **6 medalhas curadas** em 4 eixos (conteúdo/missões/sequência/nível/categoria).
3. **Recompensa:** **bônus único de XP/coins** por desbloqueio (maiores que os das missões), creditado uma vez (ledger anti-duplo).
4. **Arquitetura:** **calcular no read + creditar preguiçoso e idempotente** (abordagem A), via endpoint dedicado `GET /child/medals`. Espelha exatamente a 3b (que já está em prod). Sem cron.

## Catálogo de medalhas (constantes no código)

| key | título | signal (eixo) | target | xpReward | coinsReward |
|---|---|---|---|---|---|
| `explorer_10` | Explorador | `totalContentOpened` | 10 | 30 | 20 |
| `devourer_50` | Devorador | `totalContentOpened` | 50 | 60 | 40 |
| `achiever_10` | Cumpridor | `totalMissionsCompleted` | 10 | 40 | 25 |
| `faithful_7` | Fiel | `streakDays` | 7 | 40 | 25 |
| `veteran_10` | Veterano | `level` | 10 | 50 | 30 |
| `curious_master_5` | Curioso Master | `distinctCategoriesAllTime` | 5 | 40 | 25 |

Cada medalha carrega o campo `signal` (qual sinal ela lê). Valores ajustáveis num único ponto (`MedalCatalog`). Ícones sugeridos (material symbols): `explore`, `auto_stories`, `task_alt`, `local_fire_department`, `military_tech`, `category`.

## Modelo de dados

**Migração 020** (`database/migrations/020_medal_unlocks.php`), com **bump `GUARDKIDS_DB_VERSION` 19 → 20** em `guardkids.php` no mesmo commit (obrigatório, senão `maybeRunMigrations` skipa).

```sql
CREATE TABLE IF NOT EXISTS wp_guardkids_medal_unlocks (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    child_id      BIGINT UNSIGNED NOT NULL,
    medal_key     VARCHAR(40) NOT NULL,
    unlocked_date DATE NOT NULL,          -- informativo (quando desbloqueou)
    xp            INT NOT NULL DEFAULT 0,  -- snapshot do bônus creditado (auditoria)
    coins         INT NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY once_per_medal (child_id, medal_key),
    KEY child (child_id)
) {charsetCollate};
```

- Diferença-chave vs `mission_completions`: o UNIQUE é `(child_id, medal_key)` **sem data** — medalha desbloqueia uma vez pra sempre, não por dia.
- `unlocked_date` é só informativo. Só tem `created_at` → insert próprio no repo.
- `uninstall.php` ganha o `DROP TABLE` dessa tabela.
- As definições das 6 medalhas NÃO vão pro banco — ficam no `MedalCatalog`.

## Backend

Estrutura espelha `includes/Missions/` (peças puras) + repo + controller.

### `includes/Medals/MedalCatalog.php` (puro)

Define as 6 medalhas como constantes (`key`, `title`, `description`, `icon`, `signal`, `target`, `xpReward`, `coinsReward`). `all(): array`. Sem estado, sem `$wpdb`.

### `includes/Medals/MedalEvaluator.php` (puro)

Recebe os **sinais já computados** e devolve o estado de cada medalha:

```
evaluate(signals): array   // por medalha: {key, title, description, icon, target, progress, unlocked}
```

onde `signals = { level:int, streakDays:int, totalContentOpened:int, totalMissionsCompleted:int, distinctCategoriesAllTime:int }`.
Para cada medalha do catálogo: `progress = min(signals[medal.signal], target)`, `unlocked = progress >= target`. Mapeia pelo campo `signal` (não `match` por key). Função pura → testável sem MySQL.

### `database/MedalUnlockRepository.php` (estende `Repository`, padrão `MissionCompletionRepository`)

- `existsFor(childId, key): bool` — `findWhere(['child_id'=>childId, 'medal_key'=>key])` (sem data).
- `record(childId, key, date, xp, coins): int` — insert próprio (só `created_at`).
- `countUnlocked(childId): int` — `COUNT(*)` por filho (pro `medalsUnlocked`).
- `signalsFor(childId): array{totalContentOpened:int, totalMissionsCompleted:int, distinctCategoriesAllTime:int}`:
  - `totalContentOpened` = `COUNT(*)` em `progression_awards` por `child_id` (all-time).
  - `totalMissionsCompleted` = `COUNT(*)` em `mission_completions` por `child_id`.
  - `distinctCategoriesAllTime` = `COUNT(DISTINCT c.category_id)` no join `progression_awards a JOIN content_items c ON a.content_id = c.id` por `a.child_id`, `c.category_id IS NOT NULL` (sem filtro de data).

### `api/Controllers/MedalController.php`

- **`GET /child/medals`** (token do filho):
  1. Resolve `childId` (401 se inválido).
  2. Lê a carteira via `ProgressionRepository::findByChild(childId)` → `xp`/`streak_days` (default 0 se sem carteira); `level = LevelCurve::levelForXp(xp)`.
  3. `counts = MedalUnlockRepository::signalsFor(childId)`.
  4. Monta `signals = { level, streakDays, ...counts }` e chama `MedalEvaluator::evaluate(signals)`.
  5. Para cada medalha **desbloqueada e ainda não no ledger** (`existsFor` false): `record()` + credita o bônus via `ProgressionRepository::apply()` com o XP/coins, **mantendo `streak_days` e `last_activity_date` atuais** (o bônus não altera o streak). `justUnlocked=true`. Envolto em `try/catch` (falha nunca quebra a resposta), igual ao `awardForOpen`/`MissionController`.
  6. Devolve `[{ key, title, description, icon, target, progress, unlocked, justUnlocked, xpReward, coinsReward }]`.
- **Consistência deliberada:** a avaliação é feita **uma vez** sobre os sinais pré-crédito. Um bônus que empurre o `level` até um marco não desbloqueia a medalha de nível na mesma request — só no próximo fetch. Determinístico, sem loop, eventualmente consistente. Aceitável para marcos de longo prazo.
- **Idempotência/permanência:** chamar N vezes credita o bônus **uma única vez** por medalha (via `existsFor` + `UNIQUE(child_id, medal_key)`). Uma vez desbloqueada, permanece mesmo que o sinal caia depois (ex.: streak zera).

### `GamificationController::progression` (mudança em código existente)

Injeta `MedalUnlockRepository` e adiciona ao payload `'medalsUnlocked' => $this->medals->countUnlocked($childId)`. Campo **novo** (não havia placeholder reservado).

### Rotas — `RestApi::registerGamificationRoutes()`

Adiciona `/child/medals` (permission `ChildAuth::requireToken`) ao lado de `/child/missions`.

**Acoplamento em código existente:** só a linha nova do `medalsUnlocked` no `GamificationController`. Todo o resto é aditivo (arquivos novos + 1 rota). Nada no caminho quente do `childHistory`.

## Frontend

### App-filho (`public/app-child/`)

- `api/gamification.ts`: `type Medal` + `getMedals(): Promise<Medal[]>`.
- **`components/MedalsCard.tsx`** (novo): galeria em **grid** das 6 medalhas. Desbloqueada = ícone vibrante (cor + `filled`) + título; bloqueada = ícone acinzentado (grayscale/opacity) + mini barra `progress/target`. Cabeçalho "Minhas Medalhas" com contador `X/6`. Tokens do `MissionsCard`/`ProgressCard`. Quando `justUnlocked === true`, destaque simples (cor/"+XP") na medalha recém-ganha.
- Posição: **na Home, abaixo do `MissionsCard`**. `useQuery(['child','medals'])` com refetch ao focar a Home (dispara o crédito preguiçoso quando a criança volta).

### App-pais (`public/app-parent/`)

- `api/gamification.ts`: o tipo `ChildProgression` ganha `medalsUnlocked: number`.
- `pages/GamificationDashboard.tsx`: o card de cada filho ganha a métrica **"Medalhas: N"** no grid (ao lado de Nível/XP/GuardCoins/Missões/Dias). Mudança real no front dos pais (tipo + métrica), pois o campo é novo.

## Testes

Padrão da 3b: peças puras cobertas a fundo; repos/controllers com FakeWpdb; front com vitest.

- **PHP unit:**
  - `MedalCatalogTest` — as 6 medalhas com keys/sinais/alvos/bônus esperados.
  - `MedalEvaluatorTest` — matriz de sinais (nada/parcial/no alvo/acima); mapeamento de cada `signal`→progresso; `unlocked` por medalha; as duas de `totalContentOpened` (10/50) desbloqueando em pontos diferentes; progress clampado ao target.
  - `MedalUnlockRepositoryTest` — `existsFor`/`record`/`countUnlocked`/`signalsFor` (incl. JOIN de categorias all-time) via FakeWpdb.
  - `MedalControllerTest` — token inválido→401; desbloqueio idempotente (2 chamadas = 1 linha, bônus 1x); medalha não-atingida não credita; `justUnlocked` só na transição; permanência (medalha desbloqueada não recredita mesmo se o sinal cair).
  - `GamificationControllerTest` — `medalsUnlocked` reflete o ledger.
- **vitest app-child:** `MedalsCard` — loading, grid das 6, desbloqueada vs bloqueada+barra, contador `X/6`, `justUnlocked`.
- **vitest app-parent:** `GamificationDashboard` — mostra "Medalhas: N".
- **Integration** (MySQL real, CI): migração 020 cria a tabela; fluxo mínimo de desbloqueio persiste.

## Escopo

**Dentro do 3c:**
- Migração 020 + bump DB v20; tabela `medal_unlocks`; drop no `uninstall.php`.
- `MedalCatalog` + `MedalEvaluator` (puros), `MedalUnlockRepository`, `MedalController` + rota `/child/medals`.
- `medalsUnlocked` novo no endpoint dos pais + tipo/métrica no `GamificationDashboard`.
- `MedalsCard` (galeria) no app-filho.

**Fora do 3c (futuro, YAGNI):**
- Tiers formais (bronze/prata/ouro), medalhas criadas pelos pais.
- Tela dedicada de medalhas com histórico, animações de desbloqueio.
- Novos eixos de sinal (tempo de tela, favoritos, coins acumulados).

## Entregável

Vertical slice aditivo — só a linha do `medalsUnlocked` toca backend existente + a métrica no dashboard dos pais; nada no caminho quente; sem cron. PR único, mesmo fluxo da 3a/3b. Após merge: release + deploy SSH (`wp plugin install --force`), migração idempotente (`CREATE TABLE IF NOT EXISTS`).
