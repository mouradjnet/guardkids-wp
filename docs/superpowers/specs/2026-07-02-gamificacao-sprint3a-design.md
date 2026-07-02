# Design — Gamificação 3a: Economia/Progressão (Mundo Guardião)

- **Data:** 2026-07-02
- **Status:** aprovado (aguardando review do spec)
- **Base:** guardkids-wp **v1.28.0 / DB v17** (Biblioteca já em prod).
- **Escopo:** primeira fatia da gamificação — a **fundação** (GuardCoins + XP + Níveis + engine de ganho + streak + os 2 painéis). Missões/Medalhas/Recompensas/Avatar ficam para fatias seguintes (3b–3e). **Não altera módulos existentes** (só um hook aditivo no registro de histórico da Biblioteca).

## 1. Objetivo

Dar à criança um sistema de evolução: cada conteúdo aberto na Biblioteca rende **XP** e **GuardCoins**, sobe **nível** (1→100) e mantém um **streak** de dias. Os pais veem o progresso; a criança vê "Minha Evolução".

## 2. Decisões (brainstorming)
1. **Decomposição:** construir a fatia **3a (economia/progressão)** primeiro; o resto depende dela.
2. **Ganho por conteúdo distinto/dia** (anti-farm): reabrir o mesmo conteúdo no mesmo dia não rende de novo. Streak por dias consecutivos com atividade, com bônus no primeiro ganho do dia.
3. Unidades pequenas e testáveis: `LevelCurve` (pura), `ProgressionRepository`, `AwardRepository`, serviço `Progression`.

## 3. Schema — migração 018 (`GUARDKIDS_DB_VERSION` 17 → 18)

`CREATE TABLE IF NOT EXISTS` via `$wpdb->query` (padrão das migrações 013-017).

**`wp_guardkids_progression`** — carteira/progressão por filho.
| coluna | tipo |
|---|---|
| id | BIGINT UNSIGNED PK AI |
| child_id | BIGINT UNSIGNED NOT NULL, `UNIQUE` |
| xp | INT NOT NULL DEFAULT 0 |
| coins | INT NOT NULL DEFAULT 0 |
| streak_days | INT NOT NULL DEFAULT 0 |
| last_activity_date | DATE NULL |
| created_at | DATETIME NOT NULL |
| updated_at | DATETIME NOT NULL |

**`wp_guardkids_progression_awards`** — ledger + anti-farm.
| id PK | child_id BIGINT | content_id BIGINT | award_date DATE | xp INT | coins INT | created_at DATETIME | `UNIQUE KEY once_per_day (child_id, content_id, award_date)`, `KEY child (child_id)` |

`uninstall.php` dropa as 2 tabelas.

## 4. Curva de nível (`includes/Progression/LevelCurve.php`, pura)

- XP para subir de nível L → L+1: `100 * L`.
- XP total acumulado para **atingir** o nível L: `50 * L * (L - 1)` (L1=0, L2=100, L3=300, L10=4500, L100=495000).
- `levelForXp(int $xp): int` — maior L com `50*L*(L-1) <= xp`, **cap 100**.
- `progressInLevel(int $xp): array{level:int, xpIntoLevel:int, xpForNextLevel:int}` — `xpIntoLevel = xp - totalToReach(level)`; `xpForNextLevel = 100 * level` (0 se level == 100).

Tudo estático/puro, sem `$wpdb` — 100% unit-testável.

## 5. Repositories

- **`ProgressionRepository`** (`progression`): `findByChild(childId)` (ou null), `ensure(childId)` (cria a carteira zerada se não existir, retorna a linha), `apply(childId, xpDelta, coinsDelta, streakDays, lastActivityDate)` (soma xp/coins e seta streak/data). Override de `insert`/`update` conforme colunas (tem `updated_at`, então o base serve; `created_at`/`updated_at` do base).
- **`AwardRepository`** (`progression_awards`): `existsFor(childId, contentId, date): bool`, `record(childId, contentId, date, xp, coins): int`.

## 6. Engine — `includes/Progression/Progression.php`

Constantes: `XP_PER_OPEN = 10`, `COINS_PER_OPEN = 5`, `DAILY_BONUS_COINS = 5`.

`awardForOpen(int $childId, int $contentId, \DateTimeImmutable $now): void`:
1. `date = $now->format('Y-m-d')`.
2. Se `AwardRepository::existsFor(childId, contentId, date)` → **return** (anti-farm).
3. Carteira = `ProgressionRepository::ensure(childId)`.
4. **Streak**: `last = wallet.last_activity_date`. Se `last === date` → streak inalterado, sem bônus. Se `last === ontem` → `streak+1`, bônus do dia. Senão (ou null) → `streak = 1`, bônus do dia.
5. `xpGain = XP_PER_OPEN`; `coinGain = COINS_PER_OPEN + (bônusDoDia ? DAILY_BONUS_COINS : 0)`.
6. `AwardRepository::record(childId, contentId, date, xpGain, COINS_PER_OPEN)` (registra o ganho base; o bônus do dia não entra no ledger por-conteúdo).
7. `ProgressionRepository::apply(childId, xpGain, coinGain, streak, date)`.

Tudo em torno de datas locais (`wp_timezone`). O serviço recebe `$now` injetável (testes).

## 7. REST — `GamificationController` (`api/Controllers/`)

- **`GET /child/progression`** (token `ChildAuth::requireToken`) → carteira do filho do token + níveis:
  `{ xp, coins, level, xpIntoLevel, xpForNextLevel, streakDays }`. Se não houver carteira, devolve zeros (level 1).
- **`GET /progression`** (admin, `?child_id=`) → `{ xp, coins, level, streakDays, missionsCompleted }` (`missionsCompleted = 0` na 3a).

Rotas em `RestApi::registerGamificationRoutes()`.

**Hook (única alteração fora do módulo novo):** em `ContentController::childHistory`, após gravar o history com `action='open'`, chamar `(new Progression())->awardForOpen($childId, $contentId, $now)`. Aditivo — o history continua sendo gravado igual; só credita a carteira. `$now` no fuso do WP.

## 8. app-filho — "Minha Evolução"

- `src/api/gamification.ts`: `getProgression(): Promise<Progression>` (`GET /child/progression`) + tipo `Progression = { xp, coins, level, xpIntoLevel, xpForNextLevel, streakDays }`.
- **`ProgressCard`** (novo componente) renderizado no **topo da Home** (sem nova aba): badge de **nível**, **barra de XP** (`xpIntoLevel/xpForNextLevel`), 🪙 **coins**, 🔥 **streak**. `useQuery(['child','progression'])`. Loading (skeleton curto) e erro tratados; sem atividade → mostra nível 1 / 0.

## 9. app-pais — "Gamificação"

- `PageId` ganha `'gamification'`; item de nav `{ id:'gamification', label:'Gamificação', icon:'stadia_controller' }` após `content`. `App.tsx` roteia → `GamificationDashboard`.
- `src/api/gamification.ts`: `getChildProgression(childId): Promise<ParentProgression>` (`GET /progression?child_id=`), tipo `{ xp, coins, level, streakDays, missionsCompleted }`.
- **`GamificationDashboard`**: `listChildren()` → um card por filho com **nível, XP, GuardCoins, Missões concluídas (0), Dias consecutivos**. Estados vazio (sem filhos) / loading.

## 10. UX
Loading/skeleton, estado vazio ("Ainda sem atividade" / "Nenhum filho ainda") e erro — nas duas telas.

## 11. Testes

**PHP (unit):**
- `LevelCurve`: `levelForXp` em 0/100/300/4500/495000 e além (cap 100); `progressInLevel` (xpIntoLevel/xpForNextLevel corretos; level 100 → next 0).
- `ProgressionRepository`: `ensure` cria zerado; `apply` soma.
- `AwardRepository`: `record` + `existsFor` (true após record, respeita data).
- `Progression::awardForOpen`: credita 1×; 2º open mesmo conteúdo/dia = no-op; conteúdo diferente credita; streak incrementa (ontem→+1), reseta (gap), mantém (mesmo dia); bônus do dia só no 1º.
- `GamificationController`: `/child/progression` (token, zeros sem carteira, 401 sem token); `/progression` (admin, child_id).
- `ContentController::childHistory` credita ao abrir (com Progression injetável/spy ou verificando a tabela).
- Migração 018 idempotente.

**vitest:**
- app-child: `ProgressCard` (nível/XP/coins/streak; loading).
- app-parent: `GamificationDashboard` (cards por filho); nav item presente.

## 12. Não-metas (3a)
Missões (3b); Medalhas (3c); Recompensas (3d); Avatar (3e); config de valores pelos pais; push de "subiu de nível"; leaderboard; gastar coins (resgate vem com Recompensas).

## 13. Riscos
- **Hook no childHistory** — mitigado por ser aditivo + `Progression` injetável e testado; falha do award não deve quebrar o history (envolver em try/catch, log).
- **Migração** — CREATE TABLE IF NOT EXISTS idempotente.
- **Nada existente alterado** além do hook aditivo.
