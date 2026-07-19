# License Server — Fatia 2: Ativação self-service (plano de implementação)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar ao servidor de licenças uma página de ativação self-service: o cliente informa código de ativação + e-mail da compra + domínio, e a chave é cunhada na hora, travada nesse domínio.

**Architecture:** Um CPT novo `gkl_code` guarda o "direito" (código hasheado, plano, duração, limites, jti ativo). `ActivationService` valida código+e-mail, cunha via o `LicenseIssuer` existente (cunha-antes-de-revoga) e atualiza o código. `ActivateController` expõe `POST /gkl/v1/activate` (público, rate-limited). Um shortcode renderiza o form. CLI/admin emitem códigos no interim (fatia 3 automatiza via webhook). **O cliente `guardkids-wp` não muda.**

**Tech Stack:** PHP 8.1+, WordPress (CPT + REST), Ed25519 via libsodium, autoloader PSR-ish self-contained, harness de teste standalone (`tests/run.php`, sem PHPUnit/Composer).

**Spec:** `guardkids-wp/docs/superpowers/specs/2026-07-19-license-server-fatia2-activation-design.md`

---

## Setup — como rodar os testes

O repo é `C:/Users/mysho/guardkids-license-server`. A suíte é um runner standalone que precisa da extensão **sodium** (Ed25519). Rode da raiz do repo:

```bash
PHP="C:/Users/mysho/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe"
EXT="C:/Users/mysho/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/ext"
"$PHP" -n -d extension_dir="$EXT" -d extension=sodium -d extension=mbstring tests/run.php
```

Saída esperada no fim: `== N passed, 0 failed ==` e exit 0. Cada task abaixo diz "rode a suíte (Setup)" — é este comando. Os testes são **append** no fim de `tests/run.php` (antes do bloco `echo "\n== {$pass}..."`), e todos rodam juntos num arquivo só.

> ⚠️ **Baseline atual está VERMELHO** por um bug pré-existente (não é do seu trabalho): o stub `WP_REST_Response` não tem `header()`, que o `RevokedController` passou a chamar na v1.0.2. A **Task 1** conserta isso antes de tudo.

## Convenções do repo (siga à risca)

- **Autoloader:** `GuardKids\LicenseServer\FooBar` → `includes/class-foo-bar.php`; `GuardKids\LicenseServer\Api\FooBar` → `includes/api/class-foo-bar.php`. Toda classe começa com `declare(strict_types=1);`, namespace, e `defined('ABSPATH') || exit;`.
- **Lookups de CPT:** SEMPRE use a lista explícita de `STATUSES` (nunca `'any'` — exclui status `exclude_from_search` e some com tudo; gotcha herdado do fork).
- **Classes `final`**, injeção por construtor com defaults (`private Foo $foo = new Foo()`), igual `RevokedController`/`LicenseIssuer`.
- **Segredos hasheados:** o código em claro nunca é persistido (só o `sha256`).

## Mapa de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `includes/class-code-cpt.php` (novo) | Registra o CPT `gkl_code` + status `gkl_code_open`/`gkl_code_used` |
| `includes/class-code-repository.php` (novo) | Acesso ao `gkl_code`: `persist`, `findByCodeHash`, `recordActivation` |
| `includes/class-activation-code-issuer.php` (novo) | Gera código, canonicaliza+hasheia, persiste. `canonicalHash()` estático compartilhado |
| `includes/class-activation-service.php` (novo) | Orquestra a ativação: valida, cunha (LicenseIssuer), revoga-depois, atualiza o código |
| `includes/api/class-activate-controller.php` (novo) | `POST /gkl/v1/activate` — rate-limit + parse + mapeia resultado pra HTTP |
| `includes/class-activation-form.php` (novo) | Shortcode `[gkl_activation_form]` — form HTML + JS fetch |
| `includes/class-cli-command.php` (modif.) | + comando `wp gkl issue-code` |
| `includes/class-admin.php` (modif.) | + página admin "Emitir código" |
| `includes/class-plugin.php` (modif.) | Fia `CodeCpt`, `ActivateController`, `ActivationForm` no boot |
| `tests/run.php` (modif.) | Upgrade do harness + testes novos |

---

## Task 1: Consertar o harness (baseline verde)

O stub `WP_REST_Response` não tem `header()`, e o stub `get_posts` ignora `post_type`/`meta` — o que impede testar dois CPTs (código + licença) na mesma chamada. Conserta os dois e ajusta as fixtures existentes pra setar `post_type`.

**Files:**
- Modify: `tests/run.php` (stubs `WP_REST_Response`, `get_posts`; fixtures existentes)

- [ ] **Step 1: Adicionar `header()` ao stub `WP_REST_Response`**

Em `tests/run.php`, na classe `WP_REST_Response`, adicione o método (no-op, o teste não inspeciona headers):

```php
class WP_REST_Response {
    public function __construct(private mixed $data = null, private int $status = 200) {}
    public function get_data(): mixed { return $this->data; }
    public function get_status(): int { return $this->status; }
    public function header(string $name, string $value): void {}
}
```

- [ ] **Step 2: Tornar o stub `get_posts` ciente de `post_type`/`meta`/`post_status`**

Substitua a função `get_posts` do stub por:

```php
function get_posts(array $a): array {
    $GLOBALS['last_get_posts'] = $a;
    $posts = $GLOBALS['posts'] ?? [];
    if (isset($a['post_type'])) {
        $posts = array_values(array_filter($posts, fn($p) => $p->post_type === $a['post_type']));
    }
    if (isset($a['post_status']) && is_array($a['post_status'])) {
        $posts = array_values(array_filter($posts, fn($p) => in_array($p->post_status, $a['post_status'], true)));
    }
    if (isset($a['meta_key'])) {
        $mk = $a['meta_key']; $mv = $a['meta_value'] ?? null;
        $posts = array_values(array_filter($posts, fn($p) => (($GLOBALS['meta'][$p->ID][$mk] ?? null) === $mv)));
    }
    $n = (int) ($a['numberposts'] ?? -1);
    return $n > 0 ? array_slice($posts, 0, $n) : $posts;
}
```

- [ ] **Step 3: Setar `post_type` nas fixtures existentes**

Na seção `--- LicenseRepository ---`, a fixture do `revokedJtis` (a que cria `$p` com `ID = 7`) precisa de `post_type`:

```php
$p = new WP_Post(); $p->ID = 7; $p->post_status = 'gkl_revoked'; $p->post_type = 'gkl_license';
```

Na seção `--- RevokedController ---`, a fixture `$pRev`:

```php
$pRev = new WP_Post(); $pRev->ID = 3; $pRev->post_status = 'gkl_revoked'; $pRev->post_type = 'gkl_license';
```

- [ ] **Step 4: Rodar a suíte (Setup)**

Expected: `== N passed, 0 failed ==` e exit 0 (baseline volta ao verde; o `RevokedController` não fatala mais).

- [ ] **Step 5: Commit**

```bash
git add tests/run.php
git commit -m "test: conserta stub WP_REST_Response::header + get_posts ciente de post_type/meta"
```

---

## Task 2: CPT `gkl_code`

Espelha `LicenseCpt`, com POST_TYPE `gkl_code` e status `gkl_code_open`/`gkl_code_used`.

**Files:**
- Create: `includes/class-code-cpt.php`
- Test: `tests/run.php` (append)

- [ ] **Step 1: Escrever o teste que falha**

Append em `tests/run.php` (antes do `echo "\n== {$pass}...`):

```php
// --- CodeCpt ---
use GuardKids\LicenseServer\CodeCpt;
ok('code-cpt: POST_TYPE é gkl_code', CodeCpt::POST_TYPE === 'gkl_code');
ok('code-cpt: STATUSES são open/used', CodeCpt::STATUSES === ['gkl_code_open', 'gkl_code_used']);
$cpt = new CodeCpt();
ok('code-cpt: register não explode', (function () use ($cpt) { $cpt->register(); return true; })());
```

- [ ] **Step 2: Rodar a suíte (Setup) — deve falhar**

Expected: Error "Class ... CodeCpt not found" (a classe ainda não existe).

- [ ] **Step 3: Criar `includes/class-code-cpt.php`**

```php
<?php
declare(strict_types=1);

namespace GuardKids\LicenseServer;

defined('ABSPATH') || exit;

final class CodeCpt
{
    public const POST_TYPE = 'gkl_code';
    public const STATUSES  = ['gkl_code_open', 'gkl_code_used'];

    public function register(): void
    {
        add_action('init', [$this, 'registerPostType']);
        add_action('init', [$this, 'registerStatuses']);
    }

    public function registerPostType(): void
    {
        register_post_type(self::POST_TYPE, [
            'label'           => 'Códigos de ativação',
            'public'          => false,
            'show_ui'         => true,
            'show_in_menu'    => true,
            'menu_icon'       => 'dashicons-tickets-alt',
            'supports'        => ['title'],
            'capability_type' => 'post',
            'map_meta_cap'    => true,
            'has_archive'     => false,
            'rewrite'         => false,
            'show_in_rest'    => false,
        ]);
    }

    public function registerStatuses(): void
    {
        $labels = ['gkl_code_open' => 'Aberto', 'gkl_code_used' => 'Esgotado'];
        foreach (self::STATUSES as $status) {
            register_post_status($status, [
                'label'                     => $labels[$status],
                'public'                    => false,
                'internal'                  => true,
                'exclude_from_search'       => true,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
            ]);
        }
    }
}
```

- [ ] **Step 4: Rodar a suíte (Setup) — deve passar**

Expected: os 3 `ok('code-cpt: ...')` passam; total cresce; `0 failed`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-code-cpt.php tests/run.php
git commit -m "feat: CPT gkl_code (código de ativação) com status open/used"
```

---

## Task 3: `CodeRepository`

Acesso ao `gkl_code`: `persist` (cria aberto, contadores zerados), `findByCodeHash` (status explícito), `recordActivation` (atualiza meta + fecha se esgotou).

**Files:**
- Create: `includes/class-code-repository.php`
- Test: `tests/run.php` (append)

- [ ] **Step 1: Escrever os testes que falham**

Append:

```php
// --- CodeRepository ---
use GuardKids\LicenseServer\CodeRepository;

$GLOBALS['posts'] = []; $GLOBALS['meta'] = []; $GLOBALS['insert'] = null; $GLOBALS['updated'] = null;
$crepo = new CodeRepository();

$GLOBALS['next_id'] = 55;
$cid = $crepo->persist([
    'code_hash' => 'HASH123', 'email' => 'a@a.com', 'plan' => 'premium',
    'features' => null, 'duration_days' => 365, 'max_activations' => 3,
]);
ok('crepo: persist devolve id', $cid === 55);
ok('crepo: status gkl_code_open', ($GLOBALS['insert']['post_status'] ?? '') === 'gkl_code_open');
ok('crepo: grava code_hash', ($GLOBALS['insert']['meta_input']['code_hash'] ?? '') === 'HASH123');
ok('crepo: activations_used zera', ($GLOBALS['insert']['meta_input']['activations_used'] ?? null) === 0);
ok('crepo: current_jti vazio', ($GLOBALS['insert']['meta_input']['current_jti'] ?? null) === '');

$pc = new WP_Post(); $pc->ID = 55; $pc->post_status = 'gkl_code_open'; $pc->post_type = 'gkl_code';
$GLOBALS['posts'] = [$pc];
$GLOBALS['meta'][55] = ['code_hash' => 'HASH123'];
ok('crepo: findByCodeHash acha', $crepo->findByCodeHash('HASH123')?->ID === 55);
ok('crepo: findByCodeHash usa status explícito', ($GLOBALS['last_get_posts']['post_status'] ?? null) === CodeCpt::STATUSES);
ok('crepo: findByCodeHash não acha hash errado', $crepo->findByCodeHash('OUTRO') === null);

$crepo->recordActivation(55, 'jti-novo', 1893456000, 3, true);
ok('crepo: recordActivation grava jti', ($GLOBALS['meta'][55]['current_jti'] ?? '') === 'jti-novo');
ok('crepo: recordActivation grava used', ($GLOBALS['meta'][55]['activations_used'] ?? null) === 3);
ok('crepo: esgotado vira gkl_code_used', ($GLOBALS['updated']['post_status'] ?? '') === 'gkl_code_used');
```

- [ ] **Step 2: Rodar a suíte (Setup) — deve falhar**

Expected: Error "Class ... CodeRepository not found".

- [ ] **Step 3: Criar `includes/class-code-repository.php`**

```php
<?php
declare(strict_types=1);

namespace GuardKids\LicenseServer;

defined('ABSPATH') || exit;

/**
 * Acesso ao CPT gkl_code. Lookups usam a lista explícita CodeCpt::STATUSES
 * (nunca 'any') — mesmo gotcha do LicenseRepository.
 */
final class CodeRepository
{
    /**
     * @param array{code_hash:string,email:string,plan:string,features:list<string>|null,duration_days:int,max_activations:int} $data
     */
    public function persist(array $data): int
    {
        return (int) wp_insert_post([
            'post_type'   => CodeCpt::POST_TYPE,
            'post_status' => 'gkl_code_open',
            'post_title'  => 'Código ' . substr($data['code_hash'], 0, 8) . ' — ' . $data['email'],
            'meta_input'  => [
                'code_hash'        => $data['code_hash'],
                'email'            => $data['email'],
                'plan'             => $data['plan'],
                'features'         => $data['features'],
                'duration_days'    => $data['duration_days'],
                'max_activations'  => $data['max_activations'],
                'activations_used' => 0,
                'activated_exp'    => 0,
                'current_jti'      => '',
            ],
        ]);
    }

    public function findByCodeHash(string $hash): ?\WP_Post
    {
        if ($hash === '') {
            return null;
        }
        $posts = get_posts([
            'post_type'   => CodeCpt::POST_TYPE,
            'post_status' => CodeCpt::STATUSES,
            'meta_key'    => 'code_hash',
            'meta_value'  => $hash,
            'numberposts' => 1,
        ]);
        return $posts[0] ?? null;
    }

    public function recordActivation(int $postId, string $jti, int $activatedExp, int $activationsUsed, bool $exhausted): void
    {
        update_post_meta($postId, 'current_jti', $jti);
        update_post_meta($postId, 'activated_exp', $activatedExp);
        update_post_meta($postId, 'activations_used', $activationsUsed);
        if ($exhausted) {
            wp_update_post(['ID' => $postId, 'post_status' => 'gkl_code_used']);
        }
    }
}
```

- [ ] **Step 4: Rodar a suíte (Setup) — deve passar**

Expected: os 11 `ok('crepo: ...')` passam; `0 failed`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-code-repository.php tests/run.php
git commit -m "feat: CodeRepository (persist/findByCodeHash/recordActivation)"
```

---

## Task 4: `ActivationCodeIssuer`

Gera o código legível, canonicaliza+hasheia, persiste via `CodeRepository`. `canonicalHash()` é estático e compartilhado com o `ActivationService`.

**Files:**
- Create: `includes/class-activation-code-issuer.php`
- Test: `tests/run.php` (append)

- [ ] **Step 1: Escrever os testes que falham**

Append:

```php
// --- ActivationCodeIssuer ---
use GuardKids\LicenseServer\ActivationCodeIssuer;

ok('issuer-code: canonicalHash ignora caixa e traços',
   ActivationCodeIssuer::canonicalHash('ab12-cd34-ef56') === ActivationCodeIssuer::canonicalHash('AB12CD34EF56'));

$GLOBALS['posts'] = []; $GLOBALS['meta'] = []; $GLOBALS['insert'] = null; $GLOBALS['next_id'] = 77;
$res = (new ActivationCodeIssuer())->issue('CLIENTE@X.com', 365, 3);
ok('issuer-code: devolve code_id', ($res['code_id'] ?? null) === 77);
ok('issuer-code: code no formato XXXX-XXXX-XXXX', (bool) preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $res['code'] ?? ''));
ok('issuer-code: persiste o hash do código emitido',
   ($GLOBALS['insert']['meta_input']['code_hash'] ?? '') === ActivationCodeIssuer::canonicalHash($res['code']));
ok('issuer-code: e-mail em minúsculas', ($GLOBALS['insert']['meta_input']['email'] ?? '') === 'cliente@x.com');
ok('issuer-code: guarda max_activations', ($GLOBALS['insert']['meta_input']['max_activations'] ?? null) === 3);
```

- [ ] **Step 2: Rodar a suíte (Setup) — deve falhar**

Expected: Error "Class ... ActivationCodeIssuer not found".

- [ ] **Step 3: Criar `includes/class-activation-code-issuer.php`**

```php
<?php
declare(strict_types=1);

namespace GuardKids\LicenseServer;

defined('ABSPATH') || exit;

/**
 * Emite códigos de ativação. Usado pela CLI/admin agora; pelo webhook da fatia 3
 * depois (mesma porta). O código em claro é devolvido uma vez; só o hash persiste.
 */
final class ActivationCodeIssuer
{
    public function __construct(private CodeRepository $repo = new CodeRepository()) {}

    /**
     * @param list<string>|null $features
     * @return array{code:string,code_id:int}
     */
    public function issue(
        string $email,
        int $durationDays = 365,
        int $maxActivations = 3,
        string $plan = 'premium',
        ?array $features = null,
    ): array {
        $plain   = strtoupper(bin2hex(random_bytes(6)));      // 12 hex chars
        $display = implode('-', str_split($plain, 4));         // XXXX-XXXX-XXXX
        $id = $this->repo->persist([
            'code_hash'       => self::canonicalHash($display),
            'email'           => strtolower(trim($email)),
            'plan'            => $plan,
            'features'        => $features,
            'duration_days'   => $durationDays,
            'max_activations' => $maxActivations,
        ]);
        return ['code' => $display, 'code_id' => $id];
    }

    /** Forma canônica (maiúsculas, só A-Z0-9) hasheada — igual na emissão e no resgate. */
    public static function canonicalHash(string $code): string
    {
        $canon = (string) preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($code)));
        return hash('sha256', $canon);
    }
}
```

- [ ] **Step 4: Rodar a suíte (Setup) — deve passar**

Expected: os 6 `ok('issuer-code: ...')` passam; `0 failed`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-activation-code-issuer.php tests/run.php
git commit -m "feat: ActivationCodeIssuer (gera/canonicaliza/hasheia/persiste código)"
```

---

## Task 5: `ActivationService` (núcleo)

Valida código+e-mail+domínio, cunha a chave (LicenseIssuer) **antes** de revogar a anterior, atualiza o código. Preserva `activated_exp` nas re-ativações.

**Files:**
- Create: `includes/class-activation-service.php`
- Test: `tests/run.php` (append)

- [ ] **Step 1: Escrever os testes que falham**

Append. (Reusa `$secret`/`$pub` já criados na seção `--- Signer ---` no topo do arquivo.)

```php
// --- ActivationService ---
use GuardKids\LicenseServer\ActivationService;
use GuardKids\LicenseServer\LicenseIssuer as LI;
use GuardKids\LicenseServer\LicenseRepository as LR;
use GuardKids\LicenseServer\Signer as SG;

// helper: monta um gkl_code no "banco" e devolve [id, service]
$mkCode = function (array $metaOverride = []) use ($secret): array {
    $GLOBALS['posts'] = []; $GLOBALS['meta'] = []; $GLOBALS['insert'] = null;
    $GLOBALS['updated'] = null; $GLOBALS['mail'] = null; $GLOBALS['next_id'] = 100;
    $codeHash = ActivationCodeIssuer::canonicalHash('AAAA-BBBB-CCCC');
    $meta = array_merge([
        'code_hash' => $codeHash, 'email' => 'buyer@x.com', 'plan' => 'premium',
        'features' => null, 'duration_days' => 365, 'max_activations' => 3,
        'activations_used' => 0, 'activated_exp' => 0, 'current_jti' => '',
    ], $metaOverride);
    $pc = new WP_Post(); $pc->ID = 100; $pc->post_status = 'gkl_code_open'; $pc->post_type = 'gkl_code';
    $GLOBALS['posts'] = [$pc]; $GLOBALS['meta'][100] = $meta;
    $svc = new ActivationService(new CodeRepository(), new LI(new SG(base64_encode($secret)), new LR()), new LR());
    return [100, $svc];
};

// happy path
[$id, $svc] = $mkCode();
$out = $svc->activate('aaaa-bbbb-cccc', 'BUYER@x.com', 'https://cliente.com/');
ok('activate: ok true', ($out['ok'] ?? false) === true);
ok('activate: sub travado sem barra', ($out['sub'] ?? '') === 'https://cliente.com');
$kp = explode('.', $out['license_key'] ?? '.', 3);
$sig = @sodium_base642bin($kp[1] ?? '', SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
ok('activate: chave verifica com a pubkey do cliente',
   is_string($sig) && sodium_crypto_sign_verify_detached($sig, $kp[0], $pub));
ok('activate: incrementa activations_used', ($GLOBALS['meta'][100]['activations_used'] ?? 0) === 1);
ok('activate: fixa activated_exp', ((int) ($GLOBALS['meta'][100]['activated_exp'] ?? 0)) > time());
ok('activate: envia a chave por e-mail', ($GLOBALS['mail']['to'] ?? '') === 'buyer@x.com');

// e-mail errado → mesma msg genérica (anti-enumeração)
[$id, $svc] = $mkCode();
$bad = $svc->activate('aaaa-bbbb-cccc', 'errado@x.com', 'https://cliente.com');
ok('activate: e-mail errado → 422 invalid_code', ($bad['status'] ?? 0) === 422 && ($bad['error'] ?? '') === 'invalid_code');

// código inexistente → mesma msg
$GLOBALS['posts'] = []; $GLOBALS['meta'] = [];
$svc2 = new ActivationService(new CodeRepository(), new LI(new SG(base64_encode($secret)), new LR()), new LR());
$none = $svc2->activate('ZZZZ-ZZZZ-ZZZZ', 'buyer@x.com', 'https://cliente.com');
ok('activate: código inexistente → 422 invalid_code', ($none['status'] ?? 0) === 422 && ($none['error'] ?? '') === 'invalid_code');

// domínio malformado → 422 invalid_domain
[$id, $svc] = $mkCode();
$dom = $svc->activate('aaaa-bbbb-cccc', 'buyer@x.com', 'cliente.com');
ok('activate: domínio sem http → 422 invalid_domain', ($dom['status'] ?? 0) === 422 && ($dom['error'] ?? '') === 'invalid_domain');

// esgotado → 409
[$id, $svc] = $mkCode(['activations_used' => 3, 'max_activations' => 3]);
$ex = $svc->activate('aaaa-bbbb-cccc', 'buyer@x.com', 'https://cliente.com');
ok('activate: esgotado → 409 exhausted', ($ex['status'] ?? 0) === 409 && ($ex['error'] ?? '') === 'exhausted');

// re-ativação: revoga a jti anterior e preserva o exp
[$id, $svc] = $mkCode(['activations_used' => 1, 'current_jti' => 'jti-antigo', 'activated_exp' => 1893456000]);
$plic = new WP_Post(); $plic->ID = 200; $plic->post_status = 'gkl_active'; $plic->post_type = 'gkl_license';
$GLOBALS['posts'][] = $plic; $GLOBALS['meta'][200] = ['jti' => 'jti-antigo'];
$re = $svc->activate('aaaa-bbbb-cccc', 'buyer@x.com', 'https://novo-dominio.com');
ok('activate: re-ativação ok', ($re['ok'] ?? false) === true);
ok('activate: exp preservado', ((int) ($GLOBALS['meta'][100]['activated_exp'] ?? 0)) === 1893456000);
ok('activate: revogou a licença anterior', ($GLOBALS['updated']['ID'] ?? 0) === 200 && ($GLOBALS['updated']['post_status'] ?? '') === 'gkl_revoked');
ok('activate: used foi de 1 pra 2', ((int) ($GLOBALS['meta'][100]['activations_used'] ?? 0)) === 2);

// cunha-antes-de-revoga: se a cunhagem falha, nada muda e a anterior segue válida
[$id, $svcOk] = $mkCode(['activations_used' => 1, 'current_jti' => 'jti-antigo']);
$plic2 = new WP_Post(); $plic2->ID = 201; $plic2->post_status = 'gkl_active'; $plic2->post_type = 'gkl_license';
$GLOBALS['posts'][] = $plic2; $GLOBALS['meta'][201] = ['jti' => 'jti-antigo'];
$GLOBALS['updated'] = null;
$svcBroken = new ActivationService(new CodeRepository(), new LI(new SG('chave-quebrada'), new LR()), new LR());
$fail = $svcBroken->activate('aaaa-bbbb-cccc', 'buyer@x.com', 'https://novo.com');
ok('activate: cunhagem falha → 500', ($fail['status'] ?? 0) === 500 && ($fail['error'] ?? '') === 'mint_failed');
ok('activate: falha NÃO revogou a anterior', $GLOBALS['updated'] === null);
ok('activate: falha NÃO mexeu no código', ((int) ($GLOBALS['meta'][100]['activations_used'] ?? 0)) === 1 && ($GLOBALS['meta'][100]['current_jti'] ?? '') === 'jti-antigo');
```

- [ ] **Step 2: Rodar a suíte (Setup) — deve falhar**

Expected: Error "Class ... ActivationService not found".

- [ ] **Step 3: Criar `includes/class-activation-service.php`**

```php
<?php
declare(strict_types=1);

namespace GuardKids\LicenseServer;

defined('ABSPATH') || exit;

/**
 * Orquestra o resgate de um código de ativação: valida código+e-mail+domínio,
 * cunha a chave travada no domínio (LicenseIssuer) ANTES de revogar a anterior,
 * e atualiza o gkl_code. exp é fixado na 1ª ativação e preservado nas seguintes.
 */
final class ActivationService
{
    public function __construct(
        private CodeRepository $codes = new CodeRepository(),
        private LicenseIssuer $issuer = new LicenseIssuer(),
        private LicenseRepository $licenses = new LicenseRepository(),
    ) {}

    /**
     * @return array<string,mixed> ok:true + license_key/sub/exp, ou ok:false + status/error/message
     */
    public function activate(string $code, string $email, string $domain): array
    {
        $domain = rtrim(trim($domain), '/');
        if (!preg_match('#^https?://[^\s/]+#i', $domain)) {
            return $this->err(422, 'invalid_domain', 'Informe o domínio completo, ex.: https://seusite.com');
        }

        $post = $this->codes->findByCodeHash(ActivationCodeIssuer::canonicalHash($code));
        $emailNorm = strtolower(trim($email));
        if ($post === null
            || strtolower(trim((string) get_post_meta($post->ID, 'email', true))) !== $emailNorm) {
            // mesma resposta pra código inexistente E e-mail errado (anti-enumeração)
            return $this->err(422, 'invalid_code', 'Código ou e-mail inválido.');
        }

        $used = (int) get_post_meta($post->ID, 'activations_used', true);
        $max  = (int) get_post_meta($post->ID, 'max_activations', true);
        if ($post->post_status === 'gkl_code_used' || $used >= $max) {
            return $this->err(409, 'exhausted', 'Este código já atingiu o limite de ativações.');
        }

        $activatedExp = (int) get_post_meta($post->ID, 'activated_exp', true);
        $exp = $activatedExp > 0
            ? $activatedExp
            : time() + ((int) get_post_meta($post->ID, 'duration_days', true)) * DAY_IN_SECONDS;

        $plan     = (string) get_post_meta($post->ID, 'plan', true) ?: 'premium';
        $features = get_post_meta($post->ID, 'features', true);
        $features = is_array($features) ? $features : null;
        $codeEmail = (string) get_post_meta($post->ID, 'email', true);

        // Cunha PRIMEIRO — se falhar, nada abaixo roda e a chave anterior segue válida.
        try {
            $res = $this->issuer->issue($codeEmail, $domain, $exp, $plan, $features, true);
        } catch (\Throwable $e) {
            return $this->err(500, 'mint_failed', 'Falha ao gerar a licença. Tente de novo.');
        }

        // Revoga a anterior SÓ depois da nova existir.
        $prevJti = (string) get_post_meta($post->ID, 'current_jti', true);
        if ($prevJti !== '') {
            $this->licenses->revoke($prevJti);
        }

        $used++;
        $this->codes->recordActivation($post->ID, $res['jti'], $exp, $used, $used >= $max);

        return ['ok' => true, 'license_key' => $res['license_key'], 'sub' => $res['sub'], 'exp' => $res['exp']];
    }

    /** @return array<string,mixed> */
    private function err(int $status, string $error, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $error, 'message' => $message];
    }
}
```

- [ ] **Step 4: Rodar a suíte (Setup) — deve passar**

Expected: todos os `ok('activate: ...')` (16) passam; `0 failed`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-activation-service.php tests/run.php
git commit -m "feat: ActivationService — resgate com cunha-antes-de-revoga e exp preservado"
```

---

## Task 6: `ActivateController` — `POST /gkl/v1/activate`

Espelha o `RevokedController`: rate-limit por IP, parse dos params, mapeia o resultado do `ActivationService` pra HTTP.

**Files:**
- Create: `includes/api/class-activate-controller.php`
- Test: `tests/run.php` (append)

- [ ] **Step 1: Escrever os testes que falham**

Append. (Precisa de um `ActivationService` de verdade; reusa `$secret`.)

```php
// --- ActivateController ---
use GuardKids\LicenseServer\Api\ActivateController;

$mkCtrl = function () use ($secret): ActivateController {
    $svc = new ActivationService(new CodeRepository(), new LI(new SG(base64_encode($secret)), new LR()), new LR());
    return new ActivateController($svc);
};

// happy path → 200
$GLOBALS['tr'] = []; $GLOBALS['posts'] = []; $GLOBALS['meta'] = []; $GLOBALS['next_id'] = 300;
$codeHash = ActivationCodeIssuer::canonicalHash('DDDD-EEEE-FFFF');
$pc = new WP_Post(); $pc->ID = 300; $pc->post_status = 'gkl_code_open'; $pc->post_type = 'gkl_code';
$GLOBALS['posts'] = [$pc];
$GLOBALS['meta'][300] = [
    'code_hash' => $codeHash, 'email' => 'buyer@x.com', 'plan' => 'premium', 'features' => null,
    'duration_days' => 365, 'max_activations' => 3, 'activations_used' => 0, 'activated_exp' => 0, 'current_jti' => '',
];
$req = new WP_REST_Request(
    ['code' => 'dddd-eeee-ffff', 'email' => 'buyer@x.com', 'domain' => 'https://cliente.com'],
    '', ['x-forwarded-for' => '9.9.9.9']
);
$resp = $mkCtrl()->handle($req);
ok('ctrl: 200 no happy path', $resp->get_status() === 200);
ok('ctrl: devolve license_key', is_string($resp->get_data()['license_key'] ?? null));

// erro do service vira o status certo (código inexistente → 422)
$GLOBALS['tr'] = []; $GLOBALS['posts'] = []; $GLOBALS['meta'] = [];
$req2 = new WP_REST_Request(['code' => 'X', 'email' => 'a@a.com', 'domain' => 'https://c.com'], '', ['x-forwarded-for' => '9.9.9.8']);
$resp2 = $mkCtrl()->handle($req2);
ok('ctrl: 422 em código inválido', $resp2->get_status() === 422 && ($resp2->get_data()['error'] ?? '') === 'invalid_code');

// rate limit → 429
$GLOBALS['tr'] = []; $GLOBALS['posts'] = []; $GLOBALS['meta'] = [];
$ctrl = $mkCtrl();
$last = null;
for ($i = 0; $i < \GuardKids\LicenseServer\RateLimiter::MAX_HITS + 1; $i++) {
    $last = $ctrl->handle(new WP_REST_Request(['code' => 'X', 'email' => 'a@a.com', 'domain' => 'https://c.com'], '', ['x-forwarded-for' => '7.7.7.7']));
}
ok('ctrl: 429 ao estourar o rate limit', $last->get_status() === 429);
```

- [ ] **Step 2: Rodar a suíte (Setup) — deve falhar**

Expected: Error "Class ... Api\\ActivateController not found".

- [ ] **Step 3: Criar `includes/api/class-activate-controller.php`**

```php
<?php
declare(strict_types=1);

namespace GuardKids\LicenseServer\Api;

use GuardKids\LicenseServer\ActivationService;
use GuardKids\LicenseServer\RateLimiter;

defined('ABSPATH') || exit;

/**
 * POST /wp-json/gkl/v1/activate — resgate público de um código de ativação.
 *
 * Público de propósito (o cliente não está logado no servidor de licenças). A
 * defesa é: código+e-mail (2º fator), resposta genérica anti-enumeração, e
 * rate-limit por IP. Só o RESGATE é público; a EMISSÃO é CLI/admin.
 */
final class ActivateController
{
    public function __construct(
        private ActivationService $service = new ActivationService(),
        private RateLimiter $limiter = new RateLimiter(),
    ) {}

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('gkl/v1', '/activate', [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public function handle(\WP_REST_Request $req): \WP_REST_Response
    {
        $ip = (string) ($req->get_header('x-forwarded-for') ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        if (!$this->limiter->check($ip)) {
            return new \WP_REST_Response(
                ['ok' => false, 'error' => 'rate_limited', 'message' => 'Muitas tentativas. Tente de novo em alguns minutos.'],
                429
            );
        }

        $result = $this->service->activate(
            (string) $req->get_param('code'),
            (string) $req->get_param('email'),
            (string) $req->get_param('domain'),
        );

        if (($result['ok'] ?? false) === true) {
            return new \WP_REST_Response($result, 200);
        }

        return new \WP_REST_Response(
            ['ok' => false, 'error' => $result['error'], 'message' => $result['message']],
            (int) $result['status'],
        );
    }
}
```

- [ ] **Step 4: Rodar a suíte (Setup) — deve passar**

Expected: os 5 `ok('ctrl: ...')` passam; `0 failed`.

- [ ] **Step 5: Commit**

```bash
git add includes/api/class-activate-controller.php tests/run.php
git commit -m "feat: ActivateController POST /gkl/v1/activate (rate-limited)"
```

---

## Task 7: Shortcode `[gkl_activation_form]`

Página pública com o form (código, e-mail, domínio) que faz `fetch` no endpoint e mostra a chave ou o erro. Sem teste unitário (HTML/JS); verificação manual na Task 10.

**Files:**
- Create: `includes/class-activation-form.php`

- [ ] **Step 1: Criar `includes/class-activation-form.php`**

```php
<?php
declare(strict_types=1);

namespace GuardKids\LicenseServer;

defined('ABSPATH') || exit;

/**
 * Shortcode [gkl_activation_form] — a página de ativação self-service.
 * Form vanilla que POSTa em /wp-json/gkl/v1/activate e mostra a chave ou o erro.
 */
final class ActivationForm
{
    public function register(): void
    {
        add_shortcode('gkl_activation_form', [$this, 'render']);
    }

    public function render(): string
    {
        $endpoint = esc_url(rest_url('gkl/v1/activate'));
        ob_start();
        ?>
        <form id="gkl-activate" style="max-width:520px;margin:2rem auto;font-family:system-ui,sans-serif">
            <h2>Ativar sua licença GuardKids</h2>
            <p>Informe o código que você recebeu, o e-mail da compra e o endereço do seu site.</p>
            <label style="display:block;margin:.75rem 0 .25rem">Código de ativação</label>
            <input name="code" required placeholder="XXXX-XXXX-XXXX" style="width:100%;padding:.5rem" />
            <label style="display:block;margin:.75rem 0 .25rem">E-mail da compra</label>
            <input name="email" type="email" required placeholder="voce@exemplo.com" style="width:100%;padding:.5rem" />
            <label style="display:block;margin:.75rem 0 .25rem">Domínio do seu site</label>
            <input name="domain" required placeholder="https://seusite.com" style="width:100%;padding:.5rem" />
            <button type="submit" style="margin-top:1rem;padding:.6rem 1.2rem;background:#1e3a8a;color:#fff;border:0;border-radius:6px;cursor:pointer">Ativar</button>
            <div id="gkl-result" style="margin-top:1rem"></div>
        </form>
        <script>
        (function () {
            var form = document.getElementById('gkl-activate');
            var box  = document.getElementById('gkl-result');
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                box.textContent = 'Ativando...';
                var body = { code: form.code.value, email: form.email.value, domain: form.domain.value };
                fetch('<?php echo $endpoint; ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                }).then(function (r) { return r.json().then(function (d) { return { status: r.status, d: d }; }); })
                  .then(function (res) {
                    if (res.status === 200 && res.d.license_key) {
                        box.innerHTML = '<p style="color:#166534">Licença ativada! Copie a chave abaixo e cole no GuardKids &rarr; Licença. Também enviamos por e-mail.</p>'
                            + '<textarea readonly style="width:100%;height:96px;font-family:monospace"></textarea>';
                        box.querySelector('textarea').value = res.d.license_key;
                    } else {
                        box.innerHTML = '<p style="color:#b91c1c"></p>';
                        box.querySelector('p').textContent = (res.d && res.d.message) ? res.d.message : 'Não foi possível ativar.';
                    }
                }).catch(function () { box.textContent = 'Erro de conexão. Tente de novo.'; });
            });
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/class-activation-form.php
git commit -m "feat: shortcode [gkl_activation_form] — página de ativação self-service"
```

---

## Task 8: CLI `wp gkl issue-code` + página admin de emissão

Emissão interina de códigos (até a fatia 3). CLI imprime o código; página admin faz o mesmo por um clique.

**Files:**
- Modify: `includes/class-cli-command.php` (novo método `issueCode`)
- Modify: `includes/class-admin.php` (submenu "Emitir código")

- [ ] **Step 1: Adicionar o método `issueCode` em `CliCommand`**

Adicione dentro da classe `CliCommand` (depois de `revoke`):

```php
    /**
     * Emite um código de ativação e imprime. O cliente resgata em /ativar.
     *
     * ## OPTIONS
     *
     * --email=<email>
     * : E-mail da compra (2º fator no resgate).
     *
     * [--days=<n>]
     * : Duração da licença em dias, contada a partir da ativação. Padrão: 365.
     *
     * [--max=<n>]
     * : Máximo de (re)ativações. Padrão: 3.
     *
     * ## EXAMPLES
     *
     *     wp gkl issue-code --email=cliente@x.com
     *     wp gkl issue-code --email=cliente@x.com --days=365 --max=3
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc
     */
    public function issue_code(array $args, array $assoc): void
    {
        $email = (string) ($assoc['email'] ?? '');
        $days  = (int) ($assoc['days'] ?? 365);
        $max   = (int) ($assoc['max'] ?? 3);

        if (!is_email($email)) {
            \WP_CLI::error('--email inválido.');
        }
        if ($days <= 0 || $max <= 0) {
            \WP_CLI::error('--days e --max precisam ser positivos.');
        }

        $res = (new ActivationCodeIssuer())->issue($email, $days, $max);
        \WP_CLI::success("Código emitido (id {$res['code_id']}).");
        \WP_CLI\Utils\format_items('table', [[
            'code'  => $res['code'],
            'email' => strtolower(trim($email)),
            'days'  => (string) $days,
            'max'   => (string) $max,
        ]], ['code', 'email', 'days', 'max']);
    }
```

> Nota WP-CLI: o método `issue_code` vira o subcomando `wp gkl issue-code` (underscore → hífen). Sem colisão com flags (não há `--issue`).

- [ ] **Step 2: Adicionar a página admin em `Admin`**

Em `includes/class-admin.php`, no `register()`, adicione os dois hooks e os dois métodos:

```php
    public function register(): void
    {
        add_filter('post_row_actions', [$this, 'rowAction'], 10, 2);
        add_action('admin_action_gkl_revoke', [$this, 'handleRevoke']);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_action_gkl_issue_code', [$this, 'handleIssueCode']);
    }

    public function menu(): void
    {
        add_submenu_page(
            'edit.php?post_type=' . CodeCpt::POST_TYPE,
            'Emitir código',
            'Emitir código',
            'edit_posts',
            'gkl-issue-code',
            [$this, 'renderIssuePage']
        );
    }

    public function renderIssuePage(): void
    {
        $code = isset($_GET['gkl_code']) ? sanitize_text_field((string) $_GET['gkl_code']) : '';
        echo '<div class="wrap"><h1>Emitir código de ativação</h1>';
        if ($code !== '') {
            echo '<div class="notice notice-success"><p>Código emitido: <code style="font-size:1.1em">'
                . esc_html($code) . '</code> — envie ao cliente.</p></div>';
        }
        $url = wp_nonce_url(admin_url('admin.php?action=gkl_issue_code'), 'gkl_issue_code');
        echo '<form method="post" action="' . esc_url($url) . '">';
        echo '<table class="form-table">';
        echo '<tr><th><label for="email">E-mail da compra</label></th><td><input name="email" id="email" type="email" required class="regular-text" /></td></tr>';
        echo '<tr><th><label for="days">Dias de validade</label></th><td><input name="days" id="days" type="number" value="365" min="1" /></td></tr>';
        echo '<tr><th><label for="max">Máx. de ativações</label></th><td><input name="max" id="max" type="number" value="3" min="1" /></td></tr>';
        echo '</table>';
        submit_button('Emitir código');
        echo '</form></div>';
    }

    public function handleIssueCode(): void
    {
        if (!current_user_can('edit_posts') || !wp_verify_nonce((string) ($_REQUEST['_wpnonce'] ?? ''), 'gkl_issue_code')) {
            wp_die('Ação não autorizada.');
        }
        $email = sanitize_email((string) ($_POST['email'] ?? ''));
        $days  = max(1, (int) ($_POST['days'] ?? 365));
        $max   = max(1, (int) ($_POST['max'] ?? 3));
        if (!is_email($email)) {
            wp_die('E-mail inválido.');
        }
        $res = (new ActivationCodeIssuer())->issue($email, $days, $max);
        wp_safe_redirect(admin_url('edit.php?post_type=' . CodeCpt::POST_TYPE . '&page=gkl-issue-code&gkl_code=' . rawurlencode($res['code'])));
        exit;
    }
```

- [ ] **Step 3: Verificação manual (sem teste unitário — CLI/admin usam WP_CLI/HTTP)**

Depois da Task 9 (boot) e de instalar no servidor, confirme na Task 10. Aqui só garanta que a suíte segue verde (as classes carregam):

Rode a suíte (Setup). Expected: `0 failed` (nada quebrou; `ActivationCodeIssuer` já é testado na Task 4).

- [ ] **Step 4: Commit**

```bash
git add includes/class-cli-command.php includes/class-admin.php
git commit -m "feat: wp gkl issue-code + página admin de emissão de códigos"
```

---

## Task 9: Fiar tudo no `Plugin::boot`

**Files:**
- Modify: `includes/class-plugin.php`

- [ ] **Step 1: Registrar os componentes novos no `boot()`**

Em `includes/class-plugin.php`, adicione o `use` do controller e registre os 3 componentes novos:

```php
use GuardKids\LicenseServer\Api\RevokedController;
use GuardKids\LicenseServer\Api\ActivateController;
```

E dentro de `boot()`, junto dos registros existentes:

```php
        (new LicenseCpt())->register();
        (new CodeCpt())->register();
        (new RevokedController())->register();
        (new ActivateController())->register();
        (new ActivationForm())->register();
        (new Admin())->register();

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('gkl', CliCommand::class);
        }
```

- [ ] **Step 2: Rodar a suíte (Setup)**

Expected: `0 failed` (o boot não é exercitado pelo harness, mas garante que nada de sintaxe quebrou no autoload).

- [ ] **Step 3: Commit**

```bash
git add includes/class-plugin.php
git commit -m "feat: fia CodeCpt + ActivateController + ActivationForm no boot"
```

---

## Task 10: Smoke E2E manual (Fase C)

Prova o loop ponta-a-ponta em produção (`licencas.guardiaokids.site`), como no núcleo. **Não commita nada** — é validação.

**Pré-requisitos:** deploy do plugin atualizado no servidor de licenças (scp + `wp plugin ...` como no núcleo — ver `tools/deploy` do repo do servidor / README). SSH: `u217136411@82.25.73.253 -p 65002`, docroot do subdomínio `~/domains/guardiaokids.site/public_html/licencas`.

- [ ] **Step 1: Criar a página `/ativar`**

No wp-admin do servidor de licenças: Páginas → Adicionar, título "Ativar", conteúdo = shortcode `[gkl_activation_form]`, publicar. Confirme que `https://licencas.guardiaokids.site/ativar/` mostra o form.

- [ ] **Step 2: Emitir um código de teste (CLI)**

No SSH do servidor:

```bash
wp gkl issue-code --email=smoke@teste.com --days=365 --max=3
```

Expected: imprime uma linha com `code` no formato `XXXX-XXXX-XXXX`. Anote o código.

- [ ] **Step 3: Ativar via a página**

Em `https://licencas.guardiaokids.site/ativar/`, informe o código, `smoke@teste.com` e um domínio de teste (ex.: `https://smoke-fatia2.com`). Submeta.

Expected: mostra "Licença ativada!" + a chave numa textarea. O e-mail com a chave é disparado.

- [ ] **Step 4: Verificar que a chave é válida e travada no domínio**

Cole a chave num verificador (ou confirme via `wp gkl` que a `gkl_license` foi criada com `sub=https://smoke-fatia2.com`). O `gkl_code` deve mostrar `activations_used=1` e `current_jti` preenchido.

- [ ] **Step 5: Testar re-ativação + revogação**

Ative o **mesmo** código com **outro** domínio (`https://smoke-fatia2-b.com`). Expected: nova chave cunhada; a `jti` anterior aparece em `GET https://licencas.guardiaokids.site/wp-json/gkl/v1/revoked`. `activations_used=2`.

- [ ] **Step 6: Testar os erros**

- E-mail errado no mesmo código → "Código ou e-mail inválido."
- Domínio sem `https://` → "Informe o domínio completo...".
- Esgotar o código (3 ativações) → "Este código já atingiu o limite de ativações."

- [ ] **Step 7: Limpar os dados de teste**

Apague o `gkl_code` de smoke e as `gkl_license` de smoke pelo admin do servidor. Confirme que a lista de `/revoked` não guarda lixo de teste relevante.

---

## Self-review (feito ao escrever o plano)

- **Cobertura do spec:** CPT `gkl_code` (T2), `ActivationCodeIssuer` (T4), `ActivationService` com cunha-antes-de-revoga + exp preservado + 2º fator e-mail + anti-enumeração (T5), `ActivateController` + `/activate` + rate-limit (T6), shortcode/página (T7, T10.1), CLI `issue-code` + admin (T8), boot (T9), smoke E2E (T10). Ponte pra fatia 3 = `ActivationCodeIssuer::issue()` (T4), reusado sem mudança pelo futuro webhook. **Cliente não muda** — nenhuma task toca `guardkids-wp`. ✔
- **Sem placeholders:** todo passo de código tem o código completo; comandos e saídas esperadas explícitos. ✔
- **Consistência de tipos:** `canonicalHash()` (estático) usado igual na emissão (T4) e no resgate (T5); `CodeRepository::recordActivation(postId, jti, activatedExp, activationsUsed, exhausted)` chamado com a mesma assinatura em T5; `ActivationService::activate(code, email, domain)` consumido igual pelo controller (T6). `CodeCpt::STATUSES`/`POST_TYPE` referenciados de forma consistente. ✔
- **Escopo:** um plano, um subsistema (o servidor). Fatia 3 explicitamente fora. ✔
