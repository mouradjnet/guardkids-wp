# Sprint 3e — Avatar (Gamificação) — Design

**Data:** 2026-07-03
**Projeto:** guardkids-wp
**Fatia:** 3e (Avatar), última do roadmap 3. Continuação de 3a (economia), 3b (missões), 3c (medalhas), 3d (recompensas).
**Status:** aprovado no brainstorming; pronto pra plano de implementação.

## Contexto

A gamificação já tem, todas em prod:
- **3a** (v1.29.0): `progression` (xp/coins/streak), `LevelCurve` (nível via xp), `awardForOpen`.
- **3b** (v1.30.0): missões diárias.
- **3c** (v1.31.0): medalhas permanentes (`medal_unlocks`, `MedalUnlockRepository`).
- **3d** (v1.32.0, DB v21): recompensas (loja, `spend` atômico).

Esta fatia fecha o roadmap: deixa o filho **personalizar o próprio avatar**, com opções **desbloqueadas pela progressão** (nível de 3a + medalhas de 3c). O avatar vira o troféu visível da jornada. Cosmético/digital → **sem envolvimento dos pais** (diferente do fluxo de aprovação da 3d).

**Estado relevante:** `children.avatar_url` (TEXT, nullable) hoje guarda uma URL de imagem opcional setada pelo pai; o `ProfileSheet` do app-filho renderiza `child.avatarUrl` como `<img>` ou cai num ícone genérico `account_circle`. Não há picker.

## Decisões (do brainstorming)

1. **Desbloqueio por progressão** (não compra com coins, não grátis-total). Alguns avatares são starters grátis; outros desbloqueiam por nível ou por medalha.
2. **Catálogo:** 7 avatares emoji (sem pipeline de imagem), gated por nível **e** medalha — amarra jogar (nível) + conquistar (medalhas).
3. **Arquitetura:** desbloqueio **derivado** (calculado no read a partir de nível + medalhas desbloqueadas), sem tabela de ledger. Só o avatar **equipado** persiste (1 coluna nova). Sem envolvimento dos pais, sem cron.

## Catálogo de avatares (constantes no código)

| key | emoji | label | gate |
|---|---|---|---|
| `star` | ⭐ | Estrela | free |
| `heart` | ❤️ | Coração | free |
| `rocket` | 🚀 | Foguete | level 5 |
| `crown` | 👑 | Coroa | level 10 |
| `fire` | 🔥 | Chama | medal `faithful_7` |
| `book` | 📚 | Livro | medal `devourer_50` |
| `trophy` | 🏅 | Troféu | medal `veteran_10` |

Cada avatar carrega `key`, `emoji`, `label`, `gate` (`free`/`level`/`medal`), `threshold` (int, pro `level`), `medalKey` (string|null, pro `medal`). As medalKeys referenciam medalhas reais da 3c.

## Modelo de dados

Sem tabela nova. O desbloqueio é derivado; só o avatar equipado persiste.

**Migração 022** (`database/migrations/022_avatar.php`), com **bump `GUARDKIDS_DB_VERSION` 21 → 22** em `guardkids.php` no mesmo commit (obrigatório).

Um `ALTER` idempotente na `progression`, usando o helper `addColumnIfMissing` (mesmo padrão da migração 017):

```php
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $addColumnIfMissing = static function (string $table, string $column, string $definition) use ($wpdb): void {
        $exists = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $table,
            $column,
        ));
        if ((int) $exists === 0) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    };

    $addColumnIfMissing($wpdb->prefix . 'guardkids_progression', 'equipped_avatar', 'VARCHAR(40) NULL');
};
```

`equipped_avatar` guarda a key do avatar escolhido pelo filho (`null` = default `star`). Sem drop novo no `uninstall.php` (a coluna some com a tabela `progression`, que já é dropada).

**Racional:** diferente das medalhas (que têm bônus a creditar uma vez), aqui não há nada a creditar — o desbloqueio é só visual e recalculável a partir de nível+medalhas (que nunca regridem). Um ledger seria redundante.

## Backend

Espelha 3c (peças puras) + métodos de repo + controller.

### `includes/Avatars/AvatarCatalog.php` (puro)

Os 7 avatares como constantes (`key`, `emoji`, `label`, `gate`, `threshold`, `medalKey`). `all(): array`. Sem estado, sem `$wpdb`.

### `includes/Avatars/AvatarEvaluator.php` (puro)

```
evaluate(signals): array   // por avatar: {key, emoji, label, gate, requirementLabel, unlocked}
```

onde `signals = { level:int, unlockedMedals: string[] }`. Regra por `gate`: `free`→sempre `true`; `level`→`level >= threshold`; `medal`→`in_array(medalKey, unlockedMedals, true)`. `requirementLabel` pra UI: `"Grátis"` / `"Nível {threshold}"` / `"Medalha {label da medalha}"` (o label da medalha vem do `MedalCatalog` pela medalKey, com fallback pra própria key). Puro → testável sem MySQL.

### `database/ProgressionRepository.php` (aditivo)

- `setEquippedAvatar(int $childId, string $avatarKey): void` — `ensure($childId)` + `update($id, ['equipped_avatar' => $avatarKey])`.
- `ensure()`/`findByChild()` já devolvem a linha inteira; após a migração 022 a linha inclui `equipped_avatar`.

### `database/MedalUnlockRepository.php` (aditivo)

- `unlockedKeys(int $childId): array` — `SELECT medal_key FROM ...medal_unlocks WHERE child_id = %d` (`get_col`/`get_results`), lista de strings das medalhas desbloqueadas.

### `api/Controllers/AvatarController.php`

- `GET /child/avatars` (token): resolve `childId` (401 se inválido); `wallet = findByChild`; `xp = (int) wallet['xp']` (0 sem carteira); `level = LevelCurve::levelForXp($xp)`; `equipped = wallet['equipped_avatar'] ?? 'star'`; `unlockedMedals = MedalUnlockRepository::unlockedKeys($childId)`; `signals = {level, unlockedMedals}`; `AvatarEvaluator::evaluate($signals)`; devolve `{ equipped, avatars: [ {key, emoji, label, requirementLabel, unlocked, isEquipped} ] }` (`isEquipped = key === equipped`).
- `POST /child/avatar` (token, body `{avatarKey}`): valida `avatarKey` no catálogo (404 `avatar_not_found` se inexistente); avalia o `unlocked` desse avatar (mesmos signals); se não desbloqueado → 409 `avatar_locked`; `setEquippedAvatar($childId, $avatarKey)`; devolve `{ equipped: avatarKey }`.

### `api/Controllers/ChildSelfController.php` (mudança em código existente)

O método `me` passa a incluir `avatarEmoji`: o emoji do avatar equipado (lê `progression.equipped_avatar` via `ProgressionRepository::findByChild`, resolve o emoji via `AvatarCatalog`), ou `null` se default/sem carteira. É o que Header/ProfileSheet renderizam.

### Rotas — `RestApi::registerAvatarRoutes()`

Novo método (só token do filho), chamado em `registerRoutes()`:
- `GET /child/avatars` + `POST /child/avatar`, ambos `permission_callback => (new ChildAuth())->requireToken()`.

**Acoplamento em código existente:** `setEquippedAvatar`/`unlockedKeys` (aditivos nos repos), `avatarEmoji` no `/child/me`, e a chamada de `registerAvatarRoutes`. Resto é arquivo novo. Sem cron; nada no caminho quente do `childHistory`.

## Frontend

### App-filho (`public/app-child/`)

- `api/avatars.ts`: tipo `Avatar` (`{key, emoji, label, requirementLabel, unlocked, isEquipped}`) + `getAvatars(): Promise<{equipped:string, avatars:Avatar[]}>` + `equipAvatar(key): Promise<{equipped:string}>`.
- **`pages/Avatar.tsx`** (`PageId 'avatar'`): grid dos 7 avatares. Desbloqueado = emoji grande, tap equipa (realce no equipado); bloqueado = emoji acinzentado (grayscale/opacity) + `requirementLabel` + cadeado. Ao tocar num desbloqueado → `equipAvatar` → invalida `['child','avatars']` + `['child','me']` (Header atualiza na hora). Botão Voltar no topo (padrão da Loja/3d).
- **Entrada**: botão **"Trocar avatar"** dentro do `ProfileSheet` (que já mostra o avatar) que navega pra `avatar` via `onNavigate('avatar')` (fechando o sheet).
- **Render do emoji**: `Header` e `ProfileSheet` mostram o **emoji equipado** (`child.avatarEmoji` do `/child/me`) quando presente, caindo pra imagem `avatarUrl` e depois pro ícone `account_circle`.

### App-pais

**Fora do escopo** desta fatia. O avatar é a personalização pessoal do filho; o pai já vê nível/medalhas no dashboard. Exibir o emoji no painel dos pais fica como incremento futuro.

## Testes

Padrão das fatias: puros a fundo; repos/controllers com FakeWpdb; front com vitest.

- **PHP unit:**
  - `AvatarCatalogTest` — 7 avatares com keys/emoji/gates esperados; medalKeys referenciam medalhas reais.
  - `AvatarEvaluatorTest` — matriz: free sempre; level gate (abaixo/no/acima); medal gate (com/sem a medalha); `requirementLabel` por tipo.
  - `ProgressionAvatarTest` — `setEquippedAvatar` grava e `findByChild` devolve `equipped_avatar`; cria carteira se não existe.
  - `MedalUnlockRepositoryTest` — novo caso pra `unlockedKeys`.
  - `AvatarControllerTest` — 401 sem token; lista com `unlocked`/`isEquipped` + `equipped` default `star` sem carteira; equipar desbloqueado grava; equipar bloqueado → 409 `avatar_locked`; key inexistente → 404.
  - `ChildSelfControllerTest` — `/child/me` inclui `avatarEmoji` do equipado (e `null` no default).
- **vitest app-child:** `Avatar` — grid, equipado realçado, bloqueado com requirementLabel+cadeado, tap equipa. `ProfileSheet` — botão "Trocar avatar" navega + render do emoji quando `avatarEmoji` presente.
- **Integration** (MySQL real, CI): migração 022 adiciona a coluna; equipar persiste e volta no `/child/me`.

## Escopo

**Dentro do 3e:**
- Migração 022 (ALTER `progression` +`equipped_avatar`) + DB v22.
- `AvatarCatalog` + `AvatarEvaluator` (puros).
- `ProgressionRepository::setEquippedAvatar` + `MedalUnlockRepository::unlockedKeys`.
- `AvatarController` + rotas `GET /child/avatars` + `POST /child/avatar`.
- `avatarEmoji` no `/child/me`.
- App-filho: página `Avatar` + entrada no `ProfileSheet` + render do emoji no Header/ProfileSheet.

**Fora do 3e (futuro, YAGNI):**
- Cor de fundo / segunda dimensão de personalização.
- Avatar exibido no app-pais.
- Avatares comprados com coins (a 3d é o ralo de coins).
- Ledger de desbloqueio (derivado, não precisa persistir).

## Entregável

A fatia mais leve das cinco (sem tabela nova, sem cron, sem fluxo dos pais) — **fecha o roadmap 3 de gamificação**. O avatar vira o troféu visível de tudo que a criança conquistou (nível + medalhas). Só `setEquippedAvatar`/`unlockedKeys`/`avatarEmoji`/`registerAvatarRoutes` tocam código existente. Migração idempotente (`addColumnIfMissing`). Após merge: release v1.33.0 + deploy SSH (`wp plugin install --force`).

**Gotcha conhecido (fatias anteriores):** a tela `Avatar` é acessada via navegação (ProfileSheet → `onNavigate('avatar')`), NÃO adiciona fetch novo na Home — então o e2e `usageTracker.spec.ts` não precisa de novo stub. Se a página `Avatar` for montada dentro do fluxo testado pelo e2e (não é — ela não está na Home), estubar `/child/avatars`.
