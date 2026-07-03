# Sprint 3d — Recompensas (Gamificação) — Design

**Data:** 2026-07-03
**Projeto:** guardkids-wp
**Fatia:** 3d (Recompensas), continuação de 3a (economia), 3b (missões), 3c (medalhas)
**Status:** aprovado no brainstorming; pronto pra plano de implementação.

## Contexto

A gamificação já tem:
- **3a** (v1.29.0): `progression` (xp/coins/streak), `progression_awards`, `LevelCurve`, `awardForOpen`.
- **3b** (v1.30.0): missões diárias — `mission_completions`, `/child/missions`, bônus de coins.
- **3c** (v1.31.0, em prod, DB v20): medalhas permanentes — `medal_unlocks`, `/child/medals`, bônus de coins.

Até aqui os coins **só foram creditados** (opens, missões, medalhas). Esta fatia fecha o ciclo econômico: os coins acumulados viram algo **gastável** numa loja de recompensas definidas pelos pais.

**Estado relevante do código:**
- `ProgressionRepository` só tem `apply` (soma deltas) — **não há dedução atômica com checagem de saldo**. É a primeira vez que coins são deduzidos.
- Já existe o fluxo de aprovação dos pais: tabela `requests` + `RequestController` (filho cria `pending` → pai `decide(approved|denied)` via `get_current_user_id()`). É o **padrão a espelhar** (sem sobrecarregar a tabela `requests`, que é semântica de pedido de site).

## Decisões (do brainstorming)

1. **Catálogo criado pelos pais** (CRUD no app-pais). Não é auto-sistema como 3b/3c — recompensas são família-específicas.
2. **Fluxo: pedir → pai aprova → deduz.** Filho cria pedido pendente (nenhum coin sai); pai aprova (deduz atomicamente) ou nega (nada sai). Espelha o `RequestController`. Sem estorno.
3. **Recompensa enxuta:** título, custo em coins, ícone opcional, flag ativo. Bloqueia pedido duplicado pendente da mesma recompensa. Checagem leve de saldo no pedido, checagem dura (atômica) no approve. Sem limites/cooldowns/estoque.
4. **Arquitetura:** duas tabelas novas (`rewards` global + `reward_redemptions`) + `ProgressionRepository::spend` atômico. Sem cron.

## Modelo de dados

**Migração 021** (`database/migrations/021_rewards.php`), com **bump `GUARDKIDS_DB_VERSION` 20 → 21** em `guardkids.php` no mesmo commit (obrigatório).

O catálogo é **global** (não por-filho), igual a `sites`/`categories` — a conta do pai gerencia um catálogo único.

```sql
CREATE TABLE IF NOT EXISTS wp_guardkids_rewards (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title      VARCHAR(120) NOT NULL,
    cost_coins INT UNSIGNED NOT NULL,
    icon       VARCHAR(40) NULL,
    active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY active (active)
) {charsetCollate};

CREATE TABLE IF NOT EXISTS wp_guardkids_reward_redemptions (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    child_id   BIGINT UNSIGNED NOT NULL,
    reward_id  BIGINT UNSIGNED NOT NULL,
    cost_coins INT UNSIGNED NOT NULL,          -- snapshot no momento do pedido
    status     VARCHAR(16) NOT NULL DEFAULT 'pending',  -- pending|approved|denied
    decided_at DATETIME NULL,
    decided_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY child_id (child_id),
    KEY status (status)
) {charsetCollate};
```

- `cost_coins` na redemption é **snapshot** — editar/deletar a recompensa depois não muda o custo cobrado num pedido histórico.
- `uninstall.php` ganha o `DROP TABLE` das 2 tabelas.

## Dedução atômica

Novo método em `database/ProgressionRepository.php`:

```php
public function spend(int $childId, int $coins): bool
{
    $sql = $this->db->prepare(
        'UPDATE ' . $this->table() . ' SET coins = coins - %d, updated_at = %s '
        . 'WHERE child_id = %d AND coins >= %d',
        $coins,
        current_time('mysql', true),
        $childId,
        $coins,
    );
    return $this->db->query($sql) === 1;
}
```

Um único `UPDATE ... WHERE coins >= X` é **atômico sob o lock de linha do MySQL** — sem read-modify-write, impossível ficar negativo mesmo com requests concorrentes. Retorna `true` se deduziu (tinha saldo), `false` se insuficiente ou sem carteira.

## Backend

### Repositórios (estendem `Repository`)

`database/RewardRepository.php` (suffix `rewards`): CRUD reusa a base (`insert`/`update`/`delete`/`findById`/`findAll`) + `findActive(): array` (só `active=1`).

`database/RewardRedemptionRepository.php` (suffix `reward_redemptions`), espelha `RequestRepository`:
- `create(int $childId, int $rewardId, int $cost): int`
- `hasPendingFor(int $childId, int $rewardId): bool` — bloqueia duplicado pendente.
- `decide(int $id, string $status, int $userId): bool` — grava `status`/`decided_at`/`decided_by`.
- `findByChildWithReward(int $childId): array` — resgates do filho + título/ícone da recompensa (JOIN).
- `findPendingWithDetails(): array` — pendentes + título da recompensa + nome do filho (JOIN).
- `findById(int $id)` da base.

### Controllers

`api/Controllers/RewardController.php` — catálogo:
- `GET /rewards` (admin) → todas (ativas+inativas) pra gestão.
- `POST /rewards` (admin) → cria (`title` não-vazio, `costCoins ≥ 1`, `icon?`).
- `PUT /rewards/(?P<id>\d+)` (admin) → edita (title/costCoins/icon/active).
- `DELETE /rewards/(?P<id>\d+)` (admin) → remove.
- `GET /child/rewards` (token) → só **ativas** + o **saldo de coins** do filho: `{ balance:int, rewards:[...] }`.

`api/Controllers/RedemptionController.php` — resgates:
- `POST /child/redemptions` (token) → valida recompensa existe+ativa; `hasPendingFor` → 409 `already_pending`; checagem leve de saldo (`coins ≥ cost`) senão 409 `insufficient_funds`; cria `pending` com **snapshot** do custo.
- `GET /child/redemptions` (token) → resgates do filho (status).
- `GET /redemptions?status=pending` (admin) → pendentes com detalhes.
- `POST /redemptions/(?P<id>\d+)/approve` (admin) → **fluxo atômico**: carrega a redemption; se não-pendente → 409 `already_decided`; `spend(childId, cost_coins)` (o snapshot); se `false` → 409 `insufficient_funds` (status continua pending); se `true` → `decide(approved, userId)`.
- `POST /redemptions/(?P<id>\d+)/deny` (admin) → `decide(denied, userId)`, nenhum coin sai.

**Rotas**: novo `RestApi::registerRewardsRoutes()` (mix `requireAdmin` + `ChildAuth::requireToken`), chamado em `registerRoutes()`.

**Acoplamento em código existente**: só `spend` novo no `ProgressionRepository` (aditivo) + a chamada de `registerRewardsRoutes()`. Resto é arquivo novo. Nada no caminho quente do `childHistory`.

## Frontend

### App-filho (`public/app-child/`) — a Loja

- `api/rewards.ts`: tipos `Reward` + `Redemption` + `getStore(): Promise<{balance:number, rewards:Reward[]}>`, `getMyRedemptions()`, `redeem(rewardId)`.
- **`pages/Loja.tsx`** (`PageId 'store'`): topo com **saldo de coins**; lista de recompensas ativas (ícone, título, custo); botão **"Resgatar"** desabilitado se `custo > saldo` ou já há pedido pendente daquela recompensa; ao resgatar → POST → toast "Pedido enviado ao papai". Seção **"Meus resgates"** com status (pendente/aprovado/negado).
- **Entrada**: card na Home ("Loja de Recompensas · N coins") que navega pra `store` via `onNavigate` — **sem** 7º item na BottomNav (mantém o rodapé enxuto). O saldo do card **reusa a query `['child','progression']`** que o `ProgressCard` já dispara na Home (mesmo `queryKey`, TanStack deduplica) — **não abre rota nova na Home**, então o e2e `usageTracker.spec.ts` não precisa de novo stub (o `/child/progression` já é estubado desde a 3c).

### App-pais (`public/app-parent/`) — Recompensas

- `api/rewards.ts`: `listRewards`/`createReward`/`updateReward`/`deleteReward` + `listPendingRedemptions`/`approveRedemption`/`denyRedemption`.
- **`pages/Recompensas.tsx`** com duas seções:
  1. **Gerir recompensas** — lista (título/custo/ativo) + form add/editar (título, custo, ícone, toggle ativo) + remover.
  2. **Resgates pendentes** — nome do filho + recompensa + custo + **Aprovar**/**Negar** (reusa visual do `PendingRequests`); Aprovar mostra erro amigável em `insufficient_funds`.
- `PageId 'rewards'` + item **"Recompensas"** na SideNav (ícone `card_giftcard`), na seção de gamificação.

## Testes

Padrão das fatias: repos/controllers com FakeWpdb; front com vitest.

- **PHP unit:**
  - `ProgressionRepositoryTest` (ou `ProgressionSpendTest`) — `spend`: saldo suficiente deduz + `true`; saldo exato zera; insuficiente não mexe + `false`; sem carteira `false`.
  - `RewardRepositoryTest` — `findActive` (só ativas), CRUD.
  - `RewardRedemptionRepositoryTest` — `create`, `hasPendingFor`, `decide`, JOINs de detalhes.
  - `RewardControllerTest` — CRUD admin (valida title/costCoins), `/child/rewards` só ativas + saldo, 401 sem token.
  - `RedemptionControllerTest` — pedir: 409 duplicado pendente, 409 saldo insuficiente, cria snapshot; approve: deduz snapshot + approved, 409 `insufficient_funds` (saldo caiu, fica pending), 409 `already_decided`; deny não mexe em coins.
- **vitest app-child:** `Loja` — lista, saldo, botão desabilitado (caro/pendente), "Meus resgates" com status.
- **vitest app-parent:** `Recompensas` — gestão (add/editar/toggle/remover) + fila de pendentes (Aprovar/Negar) + erro de saldo.
- **Integration** (MySQL real, CI): migração 021 cria as 2 tabelas; fluxo redeem→approve deduz coins de verdade (valida o `spend` atômico).

## Escopo

**Dentro do 3d:**
- Migração 021 (`rewards` + `reward_redemptions`) + DB v21 + drop no uninstall.
- `ProgressionRepository::spend` (atômico).
- `RewardRepository` + `RewardRedemptionRepository`.
- `RewardController` + `RedemptionController` + `registerRewardsRoutes`.
- App-filho: página `Loja` + card de entrada na Home.
- App-pais: página `Recompensas` (gestão + fila de aprovação) + item na SideNav.

**Fora do 3d (futuro, YAGNI):**
- Limites/cooldowns/estoque; categorias/descrição rica/imagem.
- Estorno (coins só saem no approve).
- Recompensas por-filho (catálogo é global).
- Push de resgate decidido (o `Notifier` existe; incremento futuro).

## Entregável

Vertical slice maior que 3b/3c, porém coeso — fecha o ciclo econômico (ganhar em opens/missões/medalhas → gastar em recompensas dos pais). Só o `spend` novo + a chamada de rota tocam código existente; sem cron. PR único; migração idempotente (`CREATE TABLE IF NOT EXISTS`). Após merge: release v1.32.0 + deploy SSH (`wp plugin install --force`).

**Gotcha conhecido (das fatias anteriores):** adicionar um fetch novo na Home do app-filho quebra o e2e `usageTracker.spec.ts` (rotas sem stub ficam pendentes no harness). O card de entrada da Loja **reusa a query `/child/progression` existente** (não abre rota nova na Home), então NÃO precisa mexer no e2e. Se por algum motivo o card passar a disparar `/child/rewards` direto na Home, estubar essa rota no `beforeEach` do e2e (padrão do commit `4ed8188`).
