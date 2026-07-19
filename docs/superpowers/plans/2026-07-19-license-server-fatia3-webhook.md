# Fatia 3 — Webhooks de venda multi-plataforma (Implementation Plan)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fechar o funil de venda: um webhook da plataforma (Hotmart nesta fatia) emite o código de ativação e o manda por e-mail automaticamente, e gere a assinatura recorrente por revogação.

**Architecture:** Fronteira de adapter — núcleo `SubscriptionService` agnóstico que consome eventos normalizados (`purchase/refund/chargeback/cancel/reactivate`), + adapters por plataforma (`verify()` + `parse()`) despachados por um `AdapterRegistry` numa rota `POST /gkl/v1/webhook/{plataforma}`. Só o adapter Hotmart nesta fatia; a assinatura é gerida por revogação (a chave tem exp-teto e o controle real é o `/revoked` que o cliente já consome).

**Tech Stack:** PHP 8.1, WordPress (CPT + REST + wp-cron), sodium (já em uso), harness standalone `tests/run.php` (sem PHPUnit).

---

## Setup — como rodar os testes

Da raiz do repo `guardkids-license-server`:

```bash
php tests/run.php
```

Se o sodium não estiver no PATH do PHP CLI, use o do LocalWP:
```bash
php -n -d extension_dir=<localwp ext 8.2.29> -d extension=sodium -d extension=mbstring tests/run.php
```
Esperado no fim: `== N passed, 0 failed ==`. Baseline atual (após fatia 2): **59 passed**.

## Convenções do repo (siga à risca)

- **Autoloader** (`includes/class-autoloader.php`): `GuardKids\LicenseServer\Foo` → `includes/class-foo.php`; `GuardKids\LicenseServer\Api\Foo` → `includes/api/class-foo.php`; `GuardKids\LicenseServer\Adapters\Foo` → `includes/adapters/class-foo.php`. CamelCase vira kebab.
- **Lookups de CPT** usam a lista explícita de status (`CodeCpt::STATUSES` / `LicenseCpt::STATUSES`), nunca `'any'`.
- **Um `git commit` por task** (ao fim dos steps).
- Testes no estilo do harness: `ok('nome', <bool>)`, estado no `$GLOBALS` (`posts`, `meta`, `insert`, `updated`, `mail`, `tr`, `next_id`).

## Mapa de arquivos

**Criar:**
- `includes/class-normalized-event.php` — struct do evento normalizado + constantes de tipo.
- `includes/class-platform-adapter.php` — interface `PlatformAdapter`.
- `includes/class-hmac-verifier.php` — helper HMAC-SHA256.
- `includes/class-subscription-service.php` — núcleo agnóstico (fluxos A/B/C + cron).
- `includes/class-adapter-registry.php` — slug → adapter + nome da constante do secret.
- `includes/adapters/class-hotmart-adapter.php` — adapter Hotmart (`verify` + `parse`).
- `includes/api/class-webhook-controller.php` — rota `POST /gkl/v1/webhook/{platform}`.

**Modificar:**
- `includes/class-license-repository.php` — `unrevoke()`.
- `includes/class-code-cpt.php` — status `gkl_code_disabled`.
- `includes/class-code-repository.php` — `findBySubscriptionId()`, `scheduleRevocation()`, `disable()`, `enable()`, `clearRevokeAt()`, `dueForRevocation()`, e `subscription_id`/`revoke_at` no `persist()`.
- `includes/class-activation-code-issuer.php` — param `$subscriptionId`.
- `includes/class-activation-service.php` — exigir status `gkl_code_open`.
- `includes/class-plugin.php` — registrar o `WebhookController` + o cron.
- `guardkids-license-server.php` — bump versão + hook do cron.
- `tests/run.php` — testes de cada task.

---

## Task 1: `LicenseRepository::unrevoke()`

**Files:**
- Modify: `includes/class-license-repository.php`
- Test: `tests/run.php`

- [ ] **Step 1: Escrever o teste que falha**

Adicione em `tests/run.php` logo após a linha `ok('repo: lookup usa status explícito ...`:

```php
// unrevoke: reativa a licença revogada
$pu = new WP_Post(); $pu->ID = 9; $pu->post_status = 'gkl_revoked'; $pu->post_type = 'gkl_license';
$GLOBALS['posts'] = [$pu]; $GLOBALS['meta'][9] = ['jti' => 'j-unrev']; $GLOBALS['updated'] = null;
ok('repo: unrevoke reativa (gkl_active)', $repo->unrevoke('j-unrev') === true && ($GLOBALS['updated']['post_status'] ?? '') === 'gkl_active');
ok('repo: unrevoke de jti inexistente = false', $repo->unrevoke('nope') === false);
```

- [ ] **Step 2: Rodar a suíte — deve falhar**

Run: `php tests/run.php`
Expected: FAIL `repo: unrevoke reativa (gkl_active)` (método não existe / erro fatal).

- [ ] **Step 3: Implementar `unrevoke`**

Em `includes/class-license-repository.php`, adicione após o método `revoke`:

```php
    public function unrevoke(string $jti): bool
    {
        $post = $this->findByJti($jti);
        if ($post === null) {
            return false;
        }
        wp_update_post(['ID' => $post->ID, 'post_status' => 'gkl_active']);
        return true;
    }
```

- [ ] **Step 4: Rodar a suíte — deve passar**

Run: `php tests/run.php` — Expected: as 2 novas linhas passam; `0 failed`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-license-repository.php tests/run.php
git commit -m "feat(license): LicenseRepository::unrevoke (gkl_revoked → gkl_active)"
```

---

## Task 2: Status `gkl_code_disabled` + `ActivationService` exige `open`

**Files:**
- Modify: `includes/class-code-cpt.php`, `includes/class-activation-service.php`
- Test: `tests/run.php`

- [ ] **Step 1: Escrever o teste que falha**

Adicione em `tests/run.php` logo após o bloco `activate: esgotado → 409 exhausted` (procure essa string):

```php
// código desativado (reembolso antes do resgate) não ativa
[$id, $svc] = $mkCode();
$GLOBALS['posts'][0]->post_status = 'gkl_code_disabled';
$dis = $svc->activate('aaaa-bbbb-cccc', 'buyer@x.com', 'https://cliente.com');
ok('activate: código disabled → 409 disabled', ($dis['status'] ?? 0) === 409 && ($dis['error'] ?? '') === 'disabled');
```

- [ ] **Step 2: Rodar a suíte — deve falhar**

Run: `php tests/run.php`
Expected: FAIL `activate: código disabled → 409 disabled` (hoje o service não trata `disabled` — o `findByCodeHash` nem acha o post porque `disabled` não está em `STATUSES`).

- [ ] **Step 3: Registrar o status novo**

Em `includes/class-code-cpt.php`, troque a constante e o array de labels:

```php
    public const STATUSES  = ['gkl_code_open', 'gkl_code_used', 'gkl_code_disabled'];
```

```php
        $labels = ['gkl_code_open' => 'Aberto', 'gkl_code_used' => 'Esgotado', 'gkl_code_disabled' => 'Desativado'];
```

- [ ] **Step 4: Exigir `open` no `ActivationService`**

Em `includes/class-activation-service.php`, localize o bloco:

```php
        $used = (int) get_post_meta($post->ID, 'activations_used', true);
        $max  = (int) get_post_meta($post->ID, 'max_activations', true);
        if ($post->post_status === 'gkl_code_used' || $used >= $max) {
            return $this->err(409, 'exhausted', 'Este código já atingiu o limite de ativações.');
        }
```

e troque por:

```php
        $used = (int) get_post_meta($post->ID, 'activations_used', true);
        $max  = (int) get_post_meta($post->ID, 'max_activations', true);
        if ($post->post_status === 'gkl_code_disabled') {
            return $this->err(409, 'disabled', 'Este código foi desativado (compra cancelada ou reembolsada).');
        }
        // fail-safe: só status 'gkl_code_open' ativa (qualquer outro, incl. 'used', barra)
        if ($post->post_status !== 'gkl_code_open' || $used >= $max) {
            return $this->err(409, 'exhausted', 'Este código já atingiu o limite de ativações.');
        }
```

- [ ] **Step 5: Rodar a suíte — deve passar**

Run: `php tests/run.php` — Expected: nova linha passa; os testes existentes de `exhausted` continuam verdes; `0 failed`.

- [ ] **Step 6: Commit**

```bash
git add includes/class-code-cpt.php includes/class-activation-service.php tests/run.php
git commit -m "feat(code): status gkl_code_disabled + ActivationService exige status open"
```

---

## Task 3: `gkl_code` ganha `subscription_id`/`revoke_at` + métodos de mutação

**Files:**
- Modify: `includes/class-code-repository.php`, `includes/class-activation-code-issuer.php`
- Test: `tests/run.php`

- [ ] **Step 1: Escrever os testes que falham**

Adicione em `tests/run.php` logo após o bloco do `issuer-code` (procure `issuer-code: guarda max_activations`):

```php
// issuer aceita subscription_id e persiste
$GLOBALS['insert'] = null;
(new ActivationCodeIssuer())->issue('sub@x.com', 400, 3, 'premium', null, 'HTMART-SUB-1');
ok('issuer-code: persiste subscription_id', ($GLOBALS['insert']['meta_input']['subscription_id'] ?? null) === 'HTMART-SUB-1');
ok('issuer-code: revoke_at inicial = 0', ($GLOBALS['insert']['meta_input']['revoke_at'] ?? null) === 0);

// findBySubscriptionId + mutações
$cr = new CodeRepository();
$ps = new WP_Post(); $ps->ID = 300; $ps->post_status = 'gkl_code_open'; $ps->post_type = 'gkl_code';
$GLOBALS['posts'] = [$ps]; $GLOBALS['meta'][300] = ['subscription_id' => 'SUB-9', 'revoke_at' => 0];
ok('repo-code: acha por subscription_id', ($cr->findBySubscriptionId('SUB-9')->ID ?? 0) === 300);
ok('repo-code: subscription vazia = null', $cr->findBySubscriptionId('') === null);

$cr->scheduleRevocation(300, 1893456000);
ok('repo-code: scheduleRevocation grava revoke_at', ($GLOBALS['meta'][300]['revoke_at'] ?? 0) === 1893456000);
$cr->clearRevokeAt(300);
ok('repo-code: clearRevokeAt zera', ($GLOBALS['meta'][300]['revoke_at'] ?? -1) === 0);
$GLOBALS['updated'] = null; $cr->disable(300);
ok('repo-code: disable muda status', ($GLOBALS['updated']['post_status'] ?? '') === 'gkl_code_disabled');
$GLOBALS['updated'] = null; $cr->enable(300);
ok('repo-code: enable volta pra open', ($GLOBALS['updated']['post_status'] ?? '') === 'gkl_code_open');

// dueForRevocation filtra por revoke_at vencido
$pa = new WP_Post(); $pa->ID = 301; $pa->post_status = 'gkl_code_open'; $pa->post_type = 'gkl_code';
$pb = new WP_Post(); $pb->ID = 302; $pb->post_status = 'gkl_code_open'; $pb->post_type = 'gkl_code';
$GLOBALS['posts'] = [$pa, $pb];
$GLOBALS['meta'][301] = ['revoke_at' => 1000];   // vencido
$GLOBALS['meta'][302] = ['revoke_at' => 9999999999]; // futuro
$due = $cr->dueForRevocation(5000);
ok('repo-code: dueForRevocation pega só o vencido', count($due) === 1 && $due[0]->ID === 301);
```

- [ ] **Step 2: Rodar a suíte — deve falhar**

Run: `php tests/run.php`
Expected: FAIL nas novas linhas (métodos/params não existem).

- [ ] **Step 3: Estender `ActivationCodeIssuer::issue`**

Em `includes/class-activation-code-issuer.php`, troque a assinatura e o `persist`:

```php
    public function issue(
        string $email,
        int $durationDays = 365,
        int $maxActivations = 3,
        string $plan = 'premium',
        ?array $features = null,
        string $subscriptionId = '',
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
            'subscription_id' => $subscriptionId,
        ]);
        return ['code' => $display, 'code_id' => $id];
    }
```

- [ ] **Step 4: Estender `CodeRepository`**

Em `includes/class-code-repository.php`, no `persist`, adicione ao `meta_input` (depois de `current_jti`):

```php
                'subscription_id'  => $data['subscription_id'] ?? '',
                'revoke_at'        => 0,
```

e adicione estes métodos ao final da classe (antes do `}` que fecha a classe):

```php
    public function findBySubscriptionId(string $subId): ?\WP_Post
    {
        if ($subId === '') {
            return null;
        }
        $posts = get_posts([
            'post_type'   => CodeCpt::POST_TYPE,
            'post_status' => CodeCpt::STATUSES,
            'meta_key'    => 'subscription_id',
            'meta_value'  => $subId,
            'numberposts' => 1,
        ]);
        return $posts[0] ?? null;
    }

    public function scheduleRevocation(int $postId, int $revokeAt): void
    {
        update_post_meta($postId, 'revoke_at', $revokeAt);
    }

    public function clearRevokeAt(int $postId): void
    {
        update_post_meta($postId, 'revoke_at', 0);
    }

    public function disable(int $postId): void
    {
        wp_update_post(['ID' => $postId, 'post_status' => 'gkl_code_disabled']);
    }

    public function enable(int $postId): void
    {
        wp_update_post(['ID' => $postId, 'post_status' => 'gkl_code_open']);
    }

    /** @return list<\WP_Post> códigos com revoke_at agendado e já vencido */
    public function dueForRevocation(int $now): array
    {
        $posts = get_posts([
            'post_type'   => CodeCpt::POST_TYPE,
            'post_status' => CodeCpt::STATUSES,
            'numberposts' => -1,
        ]);
        $due = [];
        foreach ($posts as $p) {
            $ra = (int) get_post_meta($p->ID, 'revoke_at', true);
            if ($ra > 0 && $ra <= $now) {
                $due[] = $p;
            }
        }
        return $due;
    }
```

- [ ] **Step 5: Rodar a suíte — deve passar**

Run: `php tests/run.php` — Expected: novas linhas verdes; `0 failed`.

- [ ] **Step 6: Commit**

```bash
git add includes/class-code-repository.php includes/class-activation-code-issuer.php tests/run.php
git commit -m "feat(code): subscription_id/revoke_at + findBySubscriptionId e mutações no CodeRepository"
```

---

## Task 4: Contratos — `NormalizedEvent` + `PlatformAdapter`

**Files:**
- Create: `includes/class-normalized-event.php`, `includes/class-platform-adapter.php`
- Test: `tests/run.php`

- [ ] **Step 1: Escrever o teste que falha**

Adicione em `tests/run.php` (antes do resumo final `== ... passed ==`, ou seja, antes do `echo`):

```php
// --- NormalizedEvent ---
use GuardKids\LicenseServer\NormalizedEvent;
$ne = new NormalizedEvent(NormalizedEvent::CANCEL, 'a@b.com', 'premium', 'SUB-1', 'EV-1', 1893456000);
ok('event: type/campos', $ne->type === 'cancel' && $ne->email === 'a@b.com' && $ne->subscriptionId === 'SUB-1' && $ne->cycleEndAt === 1893456000);
ok('event: constantes', NormalizedEvent::PURCHASE === 'purchase' && NormalizedEvent::REFUND === 'refund' && NormalizedEvent::REACTIVATE === 'reactivate');
```

- [ ] **Step 2: Rodar a suíte — deve falhar**

Run: `php tests/run.php` — Expected: FAIL `event: type/campos` (classe não existe).

- [ ] **Step 3: Criar `NormalizedEvent`**

`includes/class-normalized-event.php`:

```php
<?php
declare(strict_types=1);

namespace GuardKids\LicenseServer;

defined('ABSPATH') || exit;

/**
 * Evento de venda normalizado — a linguagem comum entre os adapters de plataforma
 * e o SubscriptionService. Cada adapter traduz o payload da sua plataforma nisto.
 */
final class NormalizedEvent
{
    public const PURCHASE   = 'purchase';
    public const REFUND     = 'refund';
    public const CHARGEBACK = 'chargeback';
    public const CANCEL     = 'cancel';
    public const REACTIVATE = 'reactivate';

    public function __construct(
        public readonly string $type,
        public readonly string $email,
        public readonly string $planKey,
        public readonly string $subscriptionId,
        public readonly string $eventId,
        public readonly int $cycleEndAt = 0,
    ) {}
}
```

- [ ] **Step 4: Criar a interface `PlatformAdapter`**

`includes/class-platform-adapter.php`:

```php
<?php
declare(strict_types=1);

namespace GuardKids\LicenseServer;

defined('ABSPATH') || exit;

/**
 * Casca fina por plataforma de venda: autentica o webhook dela e traduz o
 * payload dela num NormalizedEvent. Toda a lógica de negócio fica no núcleo.
 */
interface PlatformAdapter
{
    public function verify(\WP_REST_Request $request, string $secret): bool;

    /** @param array<string,mixed> $body @return NormalizedEvent|null null = ignorar */
    public function parse(array $body): ?NormalizedEvent;
}
```

- [ ] **Step 5: Rodar a suíte — deve passar**

Run: `php tests/run.php` — Expected: novas linhas verdes; `0 failed`.

- [ ] **Step 6: Commit**

```bash
git add includes/class-normalized-event.php includes/class-platform-adapter.php tests/run.php
git commit -m "feat(webhook): contratos NormalizedEvent + PlatformAdapter"
```

---

## Task 5: `HmacVerifier`

**Files:**
- Create: `includes/class-hmac-verifier.php`
- Test: `tests/run.php`

- [ ] **Step 1: Escrever o teste que falha**

Adicione em `tests/run.php` (antes do resumo final):

```php
// --- HmacVerifier ---
use GuardKids\LicenseServer\HmacVerifier;
$hbody = '{"event":"X"}'; $hsecret = 'shhh';
$hsig = hash_hmac('sha256', $hbody, $hsecret);
ok('hmac: assinatura válida passa', HmacVerifier::verify($hbody, $hsig, $hsecret) === true);
ok('hmac: assinatura errada falha', HmacVerifier::verify($hbody, 'deadbeef', $hsecret) === false);
ok('hmac: secret vazio falha', HmacVerifier::verify($hbody, $hsig, '') === false);
```

- [ ] **Step 2: Rodar a suíte — deve falhar**

Run: `php tests/run.php` — Expected: FAIL `hmac: assinatura válida passa`.

- [ ] **Step 3: Criar `HmacVerifier`**

`includes/class-hmac-verifier.php`:

```php
<?php
declare(strict_types=1);

namespace GuardKids\LicenseServer;

defined('ABSPATH') || exit;

final class HmacVerifier
{
    public static function verify(string $rawBody, string $sentHmac, string $secret): bool
    {
        if ($secret === '' || $sentHmac === '') {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $sentHmac);
    }
}
```

- [ ] **Step 4: Rodar a suíte — deve passar**

Run: `php tests/run.php` — Expected: novas linhas verdes; `0 failed`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-hmac-verifier.php tests/run.php
git commit -m "feat(webhook): HmacVerifier (HMAC-SHA256, hash_equals)"
```

---

## Task 6: `SubscriptionService` (núcleo agnóstico)

**Files:**
- Create: `includes/class-subscription-service.php`
- Test: `tests/run.php`

- [ ] **Step 1: Escrever os testes que falham**

Adicione em `tests/run.php` (antes do resumo final):

```php
// --- SubscriptionService ---
use GuardKids\LicenseServer\SubscriptionService;
use GuardKids\LicenseServer\CodeRepository as CR;
use GuardKids\LicenseServer\LicenseRepository as LRepo;

// helper: zera o "banco" e devolve o service
$mkSvc = function (): SubscriptionService {
    $GLOBALS['posts'] = []; $GLOBALS['meta'] = []; $GLOBALS['insert'] = null;
    $GLOBALS['updated'] = null; $GLOBALS['mail'] = null; $GLOBALS['next_id'] = 500; $GLOBALS['tr'] = [];
    return new SubscriptionService();
};
$evt = fn(string $type, string $sub, int $cycle = 0) =>
    new NormalizedEvent($type, 'buyer@x.com', 'premium', $sub, 'EV-' . $sub, $cycle);

// purchase 1ª vez: emite código, guarda subscription_id, manda e-mail, NÃO cunha licença
$svcS = $mkSvc();
$r = $svcS->handle($evt(NormalizedEvent::PURCHASE, 'SUB-A'));
ok('sub: purchase emite código', ($r['issued'] ?? false) === true);
ok('sub: guarda subscription_id no código', ($GLOBALS['insert']['meta_input']['subscription_id'] ?? '') === 'SUB-A');
ok('sub: usa exp_days/max do plano', ($GLOBALS['insert']['meta_input']['duration_days'] ?? 0) === 400 && ($GLOBALS['insert']['meta_input']['max_activations'] ?? 0) === 3);
ok('sub: manda e-mail ao comprador', ($GLOBALS['mail']['to'] ?? '') === 'buyer@x.com' && str_contains((string) ($GLOBALS['mail']['subj'] ?? ''), 'código'));
ok('sub: NÃO cunha licença (post_type gkl_license)', ($GLOBALS['insert']['post_type'] ?? '') === 'gkl_code');

// purchase de subscription já conhecida → reativa (des-revoga), NÃO emite 2º código
$svcS = $mkSvc();
$pc = new WP_Post(); $pc->ID = 600; $pc->post_status = 'gkl_code_open'; $pc->post_type = 'gkl_code';
$pl = new WP_Post(); $pl->ID = 601; $pl->post_status = 'gkl_revoked'; $pl->post_type = 'gkl_license';
$GLOBALS['posts'] = [$pc, $pl];
$GLOBALS['meta'][600] = ['subscription_id' => 'SUB-B', 'current_jti' => 'jti-b', 'revoke_at' => 123];
$GLOBALS['meta'][601] = ['jti' => 'jti-b'];
$GLOBALS['insert'] = null; $GLOBALS['updated'] = null;
$r = $svcS->handle($evt(NormalizedEvent::PURCHASE, 'SUB-B'));
ok('sub: reativação não emite 2º código', $GLOBALS['insert'] === null && ($r['reactivated'] ?? false) === true);
ok('sub: reativação des-revoga a licença', ($GLOBALS['updated']['post_status'] ?? '') === 'gkl_active');
ok('sub: reativação limpa revoke_at', ($GLOBALS['meta'][600]['revoke_at'] ?? -1) === 0);

// refund com código já resgatado → revoga current_jti
$svcS = $mkSvc();
$pc = new WP_Post(); $pc->ID = 610; $pc->post_status = 'gkl_code_used'; $pc->post_type = 'gkl_code';
$pl = new WP_Post(); $pl->ID = 611; $pl->post_status = 'gkl_active'; $pl->post_type = 'gkl_license';
$GLOBALS['posts'] = [$pc, $pl];
$GLOBALS['meta'][610] = ['subscription_id' => 'SUB-C', 'current_jti' => 'jti-c'];
$GLOBALS['meta'][611] = ['jti' => 'jti-c'];
$GLOBALS['updated'] = null;
$svcS->handle($evt(NormalizedEvent::REFUND, 'SUB-C'));
ok('sub: refund revoga a licença resgatada', ($GLOBALS['updated']['ID'] ?? 0) === 611 && ($GLOBALS['updated']['post_status'] ?? '') === 'gkl_revoked');

// refund com código ainda NÃO resgatado → disable do código
$svcS = $mkSvc();
$pc = new WP_Post(); $pc->ID = 620; $pc->post_status = 'gkl_code_open'; $pc->post_type = 'gkl_code';
$GLOBALS['posts'] = [$pc];
$GLOBALS['meta'][620] = ['subscription_id' => 'SUB-D', 'current_jti' => ''];
$GLOBALS['updated'] = null;
$svcS->handle($evt(NormalizedEvent::CHARGEBACK, 'SUB-D'));
ok('sub: chargeback não-resgatado desabilita o código', ($GLOBALS['updated']['ID'] ?? 0) === 620 && ($GLOBALS['updated']['post_status'] ?? '') === 'gkl_code_disabled');

// cancel → agenda revoke_at (não revoga já)
$svcS = $mkSvc();
$pc = new WP_Post(); $pc->ID = 630; $pc->post_status = 'gkl_code_used'; $pc->post_type = 'gkl_code';
$pl = new WP_Post(); $pl->ID = 631; $pl->post_status = 'gkl_active'; $pl->post_type = 'gkl_license';
$GLOBALS['posts'] = [$pc, $pl];
$GLOBALS['meta'][630] = ['subscription_id' => 'SUB-E', 'current_jti' => 'jti-e', 'revoke_at' => 0];
$GLOBALS['meta'][631] = ['jti' => 'jti-e']; $GLOBALS['updated'] = null;
$svcS->handle($evt(NormalizedEvent::CANCEL, 'SUB-E', 1893456000));
ok('sub: cancel agenda revoke_at', ($GLOBALS['meta'][630]['revoke_at'] ?? 0) === 1893456000);
ok('sub: cancel NÃO revoga já', $GLOBALS['updated'] === null);

// subscription desconhecida → ignored
$svcS = $mkSvc();
$r = $svcS->handle($evt(NormalizedEvent::REFUND, 'SUB-NAO-EXISTE'));
ok('sub: subscription desconhecida = ignored', ($r['ignored'] ?? false) === true);

// cron: revoga vencidos, preserva futuros
$svcS = $mkSvc();
$pa = new WP_Post(); $pa->ID = 640; $pa->post_status = 'gkl_code_used'; $pa->post_type = 'gkl_code';
$la = new WP_Post(); $la->ID = 641; $la->post_status = 'gkl_active'; $la->post_type = 'gkl_license';
$GLOBALS['posts'] = [$pa, $la];
$GLOBALS['meta'][640] = ['current_jti' => 'jti-f', 'revoke_at' => 1000];
$GLOBALS['meta'][641] = ['jti' => 'jti-f']; $GLOBALS['updated'] = null;
$n = $svcS->processScheduledRevocations(5000);
ok('sub: cron revoga 1 vencido', $n === 1 && ($GLOBALS['updated']['post_status'] ?? '') === 'gkl_revoked');
ok('sub: cron limpa revoke_at do processado', ($GLOBALS['meta'][640]['revoke_at'] ?? -1) === 0);
```

- [ ] **Step 2: Rodar a suíte — deve falhar**

Run: `php tests/run.php` — Expected: FAIL `sub: purchase emite código` (classe não existe).

- [ ] **Step 3: Criar `SubscriptionService`**

`includes/class-subscription-service.php`:

```php
<?php
declare(strict_types=1);

namespace GuardKids\LicenseServer;

defined('ABSPATH') || exit;

/**
 * Núcleo agnóstico de plataforma. Consome NormalizedEvent e materializa a regra
 * de assinatura via revogação (a chave tem exp-teto; o controle real é o /revoked).
 */
final class SubscriptionService
{
    /** @var array<string,array{exp_days:int,max:int}> */
    public const PLANS = ['premium' => ['exp_days' => 400, 'max' => 3]];

    public function __construct(
        private ActivationCodeIssuer $issuer = new ActivationCodeIssuer(),
        private CodeRepository $codes = new CodeRepository(),
        private LicenseRepository $licenses = new LicenseRepository(),
    ) {}

    /** @return array<string,mixed> */
    public function handle(NormalizedEvent $e): array
    {
        return match ($e->type) {
            NormalizedEvent::PURCHASE   => $this->onPurchase($e),
            NormalizedEvent::REACTIVATE => $this->onReactivate($e->subscriptionId),
            NormalizedEvent::REFUND,
            NormalizedEvent::CHARGEBACK => $this->onRefund($e->subscriptionId),
            NormalizedEvent::CANCEL     => $this->onCancel($e->subscriptionId, $e->cycleEndAt),
            default                     => ['ignored' => true],
        };
    }

    /** @return array<string,mixed> */
    private function onPurchase(NormalizedEvent $e): array
    {
        if ($this->codes->findBySubscriptionId($e->subscriptionId) !== null) {
            return $this->onReactivate($e->subscriptionId);
        }
        $plan = self::PLANS[$e->planKey] ?? self::PLANS['premium'];
        $res = $this->issuer->issue($e->email, $plan['exp_days'], $plan['max'], 'premium', null, $e->subscriptionId);
        $this->sendCodeEmail($e->email, $res['code']);
        return ['issued' => true, 'code_id' => $res['code_id']];
    }

    /** @return array<string,mixed> */
    private function onReactivate(string $subId): array
    {
        $code = $this->codes->findBySubscriptionId($subId);
        if ($code === null) {
            return ['ignored' => true, 'reason' => 'not_found'];
        }
        $jti = (string) get_post_meta($code->ID, 'current_jti', true);
        if ($jti !== '') {
            $this->licenses->unrevoke($jti);
        }
        $this->codes->clearRevokeAt($code->ID);
        if ($code->post_status === 'gkl_code_disabled') {
            $this->codes->enable($code->ID);
        }
        return ['reactivated' => true, 'code_id' => $code->ID];
    }

    /** @return array<string,mixed> */
    private function onRefund(string $subId): array
    {
        $code = $this->codes->findBySubscriptionId($subId);
        if ($code === null) {
            return ['ignored' => true, 'reason' => 'not_found'];
        }
        $jti = (string) get_post_meta($code->ID, 'current_jti', true);
        if ($jti !== '') {
            $this->licenses->revoke($jti);
        } else {
            $this->codes->disable($code->ID);
        }
        return ['revoked' => true, 'code_id' => $code->ID];
    }

    /** @return array<string,mixed> */
    private function onCancel(string $subId, int $cycleEndAt): array
    {
        $code = $this->codes->findBySubscriptionId($subId);
        if ($code === null) {
            return ['ignored' => true, 'reason' => 'not_found'];
        }
        $this->codes->scheduleRevocation($code->ID, $cycleEndAt > 0 ? $cycleEndAt : time());
        return ['scheduled' => true, 'code_id' => $code->ID];
    }

    /** Revoga as licenças cujo ciclo pago venceu. Retorna quantas revogou. */
    public function processScheduledRevocations(int $now): int
    {
        $count = 0;
        foreach ($this->codes->dueForRevocation($now) as $code) {
            $jti = (string) get_post_meta($code->ID, 'current_jti', true);
            if ($jti !== '') {
                $this->licenses->revoke($jti);
            }
            $this->codes->clearRevokeAt($code->ID);
            $count++;
        }
        return $count;
    }

    private function sendCodeEmail(string $email, string $code): void
    {
        if ($email === '') {
            return;
        }
        $subj = 'Seu código de ativação do GuardKids Premium';
        $body = "Seu código: {$code}\n\n"
            . "Ative em https://licencas.guardiaokids.site/ativar/ informando este código, "
            . "o e-mail da compra e o endereço do seu site.";
        wp_mail($email, $subj, $body);
    }
}
```

- [ ] **Step 4: Rodar a suíte — deve passar**

Run: `php tests/run.php` — Expected: todos os `sub:` verdes; `0 failed`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-subscription-service.php tests/run.php
git commit -m "feat(webhook): SubscriptionService — fluxos purchase/refund/cancel/reactivate + cron"
```

---

## Task 7: `HotmartAdapter`

**Files:**
- Create: `includes/adapters/class-hotmart-adapter.php`
- Test: `tests/run.php`

- [ ] **Step 1: Escrever os testes que falham**

Adicione em `tests/run.php` (antes do resumo final):

```php
// --- HotmartAdapter ---
use GuardKids\LicenseServer\Adapters\HotmartAdapter;
$ha = new HotmartAdapter();

// verify: HMAC no header x-hotmart-signature
$hbody2 = '{"event":"PURCHASE_COMPLETE"}';
$req = new WP_REST_Request([], $hbody2, ['x-hotmart-signature' => hash_hmac('sha256', $hbody2, 'sec')]);
ok('hotmart: verify HMAC válido', $ha->verify($req, 'sec') === true);
$reqBad = new WP_REST_Request([], $hbody2, ['x-hotmart-signature' => 'nope']);
ok('hotmart: verify HMAC inválido', $ha->verify($reqBad, 'sec') === false);

// parse: cancel → cycleEndAt do date_next_charge
$cancelBody = ['id' => 'EV9', 'event' => 'SUBSCRIPTION_CANCELLATION', 'data' => ['subscription' => ['id' => 'S1', 'date_next_charge' => 1893456000], 'buyer' => ['email' => 'b@x.com']]];
$pe = $ha->parse($cancelBody);
ok('hotmart: parse cancel', $pe !== null && $pe->type === 'cancel' && $pe->subscriptionId === 'S1' && $pe->cycleEndAt === 1893456000 && $pe->eventId === 'EV9');

// parse: refund
$refundBody = ['id' => 'EV10', 'event' => 'PURCHASE_REFUNDED', 'data' => ['subscription' => ['id' => 'S2'], 'buyer' => ['email' => 'b@x.com']]];
ok('hotmart: parse refund', ($ha->parse($refundBody)->type ?? '') === 'refund');

// parse: purchase de produto FORA do allowlist → null (ignora)
$buyBody = ['id' => 'EV11', 'event' => 'PURCHASE_COMPLETE', 'data' => ['product' => ['id' => 'PROD-DESCONHECIDO'], 'subscription' => ['id' => 'S3'], 'buyer' => ['email' => 'b@x.com']]];
ok('hotmart: purchase de produto fora do allowlist = null', $ha->parse($buyBody) === null);

// evento desconhecido → null
ok('hotmart: evento desconhecido = null', $ha->parse(['event' => 'FOO', 'data' => []]) === null);
```

- [ ] **Step 2: Rodar a suíte — deve falhar**

Run: `php tests/run.php` — Expected: FAIL `hotmart: verify HMAC válido` (classe não existe).

- [ ] **Step 3: Criar `HotmartAdapter`**

`includes/adapters/class-hotmart-adapter.php`:

```php
<?php
declare(strict_types=1);

namespace GuardKids\LicenseServer\Adapters;

use GuardKids\LicenseServer\HmacVerifier;
use GuardKids\LicenseServer\NormalizedEvent;
use GuardKids\LicenseServer\PlatformAdapter;

defined('ABSPATH') || exit;

/**
 * Adapter da Hotmart (webhook 2.0). Autentica via HMAC-SHA256 no header
 * x-hotmart-signature e traduz o payload em NormalizedEvent.
 */
final class HotmartAdapter implements PlatformAdapter
{
    /**
     * Mapa product.id (Hotmart) → plan_key interno. Preencher com os IDs reais
     * quando os produtos mensal/anual forem criados na Hotmart.
     * @var array<string,string>
     */
    public const PRODUCT_PLAN_MAP = [
        // 'ID-DO-PRODUTO-MENSAL' => 'premium',
        // 'ID-DO-PRODUTO-ANUAL'  => 'premium',
    ];

    public function verify(\WP_REST_Request $request, string $secret): bool
    {
        return HmacVerifier::verify(
            $request->get_body(),
            (string) ($request->get_header('x-hotmart-signature') ?? ''),
            $secret,
        );
    }

    public function parse(array $body): ?NormalizedEvent
    {
        $event = (string) ($body['event'] ?? '');
        $data  = (array) ($body['data'] ?? []);

        $type = match ($event) {
            'PURCHASE_COMPLETE', 'PURCHASE_APPROVED' => NormalizedEvent::PURCHASE,
            'PURCHASE_REFUNDED'         => NormalizedEvent::REFUND,
            'CHARGEBACK'                => NormalizedEvent::CHARGEBACK,
            'SUBSCRIPTION_CANCELLATION' => NormalizedEvent::CANCEL,
            default                     => null,
        };
        if ($type === null) {
            return null;
        }

        $email     = (string) ($data['buyer']['email'] ?? '');
        $subId     = (string) ($data['subscription']['id'] ?? '');
        $eventId   = (string) ($body['id'] ?? $body['event_id'] ?? '');
        $productId = (string) ($data['product']['id'] ?? '');
        $planKey   = self::PRODUCT_PLAN_MAP[$productId] ?? '';

        // Compra de produto fora do allowlist não gera código.
        if ($type === NormalizedEvent::PURCHASE && $planKey === '') {
            return null;
        }

        $cycleEnd = $type === NormalizedEvent::CANCEL
            ? (int) ($data['subscription']['date_next_charge'] ?? 0)
            : 0;

        return new NormalizedEvent($type, $email, $planKey, $subId, $eventId, $cycleEnd);
    }
}
```

- [ ] **Step 4: Rodar a suíte — deve passar**

Run: `php tests/run.php` — Expected: novas linhas verdes; `0 failed`.

- [ ] **Step 5: Commit**

```bash
git add includes/adapters/class-hotmart-adapter.php tests/run.php
git commit -m "feat(webhook): HotmartAdapter (verify HMAC + parse → NormalizedEvent)"
```

---

## Task 8: `AdapterRegistry`

**Files:**
- Create: `includes/class-adapter-registry.php`
- Test: `tests/run.php`

- [ ] **Step 1: Escrever o teste que falha**

Adicione em `tests/run.php` (antes do resumo final):

```php
// --- AdapterRegistry ---
use GuardKids\LicenseServer\AdapterRegistry;
use GuardKids\LicenseServer\PlatformAdapter;
$reg = new AdapterRegistry();
$res = $reg->resolve('hotmart');
ok('registry: hotmart → adapter + const do secret', is_array($res) && $res[0] instanceof PlatformAdapter && $res[1] === 'GKL_HOTMART_WEBHOOK_SECRET');
ok('registry: slug desconhecido = null', $reg->resolve('naoexiste') === null);
```

- [ ] **Step 2: Rodar a suíte — deve falhar**

Run: `php tests/run.php` — Expected: FAIL `registry: hotmart → adapter + const do secret`.

- [ ] **Step 3: Criar `AdapterRegistry`**

`includes/class-adapter-registry.php`:

```php
<?php
declare(strict_types=1);

namespace GuardKids\LicenseServer;

use GuardKids\LicenseServer\Adapters\HotmartAdapter;

defined('ABSPATH') || exit;

/**
 * Mapeia o slug da plataforma (na URL do webhook) → [adapter, nome da constante
 * do secret no wp-config]. Adicionar uma plataforma = mais um braço no match.
 */
final class AdapterRegistry
{
    /** @return array{0: PlatformAdapter, 1: string}|null */
    public function resolve(string $slug): ?array
    {
        return match ($slug) {
            'hotmart' => [new HotmartAdapter(), 'GKL_HOTMART_WEBHOOK_SECRET'],
            default   => null,
        };
    }
}
```

- [ ] **Step 4: Rodar a suíte — deve passar**

Run: `php tests/run.php` — Expected: novas linhas verdes; `0 failed`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-adapter-registry.php tests/run.php
git commit -m "feat(webhook): AdapterRegistry (slug → adapter + secret const)"
```

---

## Task 9: `Api\WebhookController` — `POST /gkl/v1/webhook/{platform}`

**Files:**
- Create: `includes/api/class-webhook-controller.php`
- Test: `tests/run.php`

- [ ] **Step 1: Escrever os testes que falham**

No **topo** de `tests/run.php`, logo após os `define(...)` existentes (ex.: após `define('DAY_IN_SECONDS', 86400);`), adicione a constante do secret:

```php
define('GKL_HOTMART_WEBHOOK_SECRET', 'test-secret');
```

Depois adicione o bloco de teste (antes do resumo final):

```php
// --- WebhookController ---
use GuardKids\LicenseServer\Api\WebhookController;

$mkReq = function (array $payload, ?string $sig = null, string $platform = 'hotmart'): WP_REST_Request {
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $sig ??= hash_hmac('sha256', $body, 'test-secret');
    return new WP_REST_Request(['platform' => $platform], $body, ['x-hotmart-signature' => $sig]);
};
$reset = function (): void {
    $GLOBALS['posts'] = []; $GLOBALS['meta'] = []; $GLOBALS['insert'] = null;
    $GLOBALS['updated'] = null; $GLOBALS['mail'] = null; $GLOBALS['next_id'] = 700; $GLOBALS['tr'] = [];
};
$wc = new WebhookController();
$buy = fn(string $sub) => ['id' => 'EV-' . $sub, 'event' => 'PURCHASE_COMPLETE', 'data' => ['product' => ['id' => 'PROD-X'], 'subscription' => ['id' => $sub], 'buyer' => ['email' => 'b@x.com']]];

// slug desconhecido → 404
$reset();
ok('wh: slug desconhecido → 404', $wc->handle($mkReq($buy('S1'), null, 'naoexiste'))->get_status() === 404);

// assinatura inválida → 401
$reset();
ok('wh: assinatura inválida → 401', $wc->handle($mkReq($buy('S1'), 'assinatura-errada'))->get_status() === 401);

// body não-JSON → 400
$reset();
$reqBadBody = new WP_REST_Request(['platform' => 'hotmart'], 'nao-e-json', ['x-hotmart-signature' => hash_hmac('sha256', 'nao-e-json', 'test-secret')]);
ok('wh: body não-JSON → 400', $wc->handle($reqBadBody)->get_status() === 400);

// evento válido mas produto fora do allowlist → 200 ignored (parse devolve null)
$reset();
$resp = $wc->handle($mkReq($buy('S1')));
ok('wh: produto fora do allowlist → 200 ignored', $resp->get_status() === 200 && (($resp->get_data()['ignored'] ?? false) === true));

// idempotência: mesmo event id 2x com um evento processável (cancel de subscription conhecida)
$reset();
$pc = new WP_Post(); $pc->ID = 710; $pc->post_status = 'gkl_code_used'; $pc->post_type = 'gkl_code';
$GLOBALS['posts'] = [$pc]; $GLOBALS['meta'][710] = ['subscription_id' => 'S2', 'current_jti' => '', 'revoke_at' => 0];
$cancel = ['id' => 'EV-DUP', 'event' => 'SUBSCRIPTION_CANCELLATION', 'data' => ['subscription' => ['id' => 'S2', 'date_next_charge' => 1893456000], 'buyer' => ['email' => 'b@x.com']]];
$r1 = $wc->handle($mkReq($cancel));
ok('wh: 1º processa (scheduled)', $r1->get_status() === 200 && (($r1->get_data()['scheduled'] ?? false) === true));
$GLOBALS['meta'][710]['revoke_at'] = 0; // "desfaz" pra provar que o 2º NÃO reprocessa
$r2 = $wc->handle($mkReq($cancel));
ok('wh: 2º é dedup (não reprocessa)', (($r2->get_data()['dedup'] ?? false) === true) && ($GLOBALS['meta'][710]['revoke_at'] ?? 0) === 0);
```

- [ ] **Step 2: Rodar a suíte — deve falhar**

Run: `php tests/run.php` — Expected: FAIL `wh: slug desconhecido → 404` (classe não existe).

- [ ] **Step 3: Criar `WebhookController`**

`includes/api/class-webhook-controller.php`:

```php
<?php
declare(strict_types=1);

namespace GuardKids\LicenseServer\Api;

use GuardKids\LicenseServer\AdapterRegistry;
use GuardKids\LicenseServer\RateLimiter;
use GuardKids\LicenseServer\SubscriptionService;

defined('ABSPATH') || exit;

/**
 * POST /wp-json/gkl/v1/webhook/{platform} — recebe os webhooks de venda.
 * Público por design (a plataforma não se autentica com sessão WP); a defesa é a
 * assinatura do adapter + rate-limit + idempotência por event id.
 */
final class WebhookController
{
    public function __construct(
        private AdapterRegistry $registry = new AdapterRegistry(),
        private SubscriptionService $service = new SubscriptionService(),
        private RateLimiter $limiter = new RateLimiter(),
    ) {}

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('gkl/v1', '/webhook/(?P<platform>[a-z0-9-]+)', [
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
            return new \WP_REST_Response(['ok' => false, 'error' => 'rate_limited'], 429);
        }

        $resolved = $this->registry->resolve((string) $req->get_param('platform'));
        if ($resolved === null) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'unknown_platform'], 404);
        }
        [$adapter, $secretConst] = $resolved;
        $secret = defined($secretConst) ? (string) constant($secretConst) : '';

        if (!$adapter->verify($req, $secret)) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'invalid_signature'], 401);
        }

        $body = json_decode($req->get_body(), true);
        if (!is_array($body)) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'invalid_body'], 400);
        }

        $event = $adapter->parse($body);
        if ($event === null) {
            return new \WP_REST_Response(['ok' => true, 'ignored' => true], 200);
        }

        $dedupKey = 'gkl_wh_' . md5($event->eventId !== '' ? $event->eventId : $req->get_body());
        if (get_transient($dedupKey)) {
            return new \WP_REST_Response(['ok' => true, 'dedup' => true], 200);
        }
        set_transient($dedupKey, 1, DAY_IN_SECONDS);

        $result = $this->service->handle($event);
        return new \WP_REST_Response(['ok' => true] + $result, 200);
    }
}
```

- [ ] **Step 4: Rodar a suíte — deve passar**

Run: `php tests/run.php` — Expected: todas as linhas `wh:` verdes; `0 failed`.

- [ ] **Step 5: Commit**

```bash
git add includes/api/class-webhook-controller.php tests/run.php
git commit -m "feat(webhook): WebhookController POST /gkl/v1/webhook/{platform}"
```

---

## Task 10: Fiar no `Plugin::boot` + cron

**Files:**
- Modify: `includes/class-plugin.php`

Sem teste unitário (o `boot()` não é exercitado pelo harness — igual à Task 9 da fatia 2). Verificação = suíte continua verde.

- [ ] **Step 1: Registrar o controller e o cron**

Em `includes/class-plugin.php`, no método `boot()`, adicione o registro do controller junto aos outros `->register()`:

```php
        (new \GuardKids\LicenseServer\Api\WebhookController())->register();
```

e, ainda no `boot()`, antes do bloco `if (defined('WP_CLI') ...`, adicione o cron:

```php
        add_action('gkl_process_scheduled_revocations', static function (): void {
            (new SubscriptionService())->processScheduledRevocations(time());
        });
        if (!wp_next_scheduled('gkl_process_scheduled_revocations')) {
            wp_schedule_event(time(), 'daily', 'gkl_process_scheduled_revocations');
        }
```

Se o arquivo ainda não importa a classe, adicione no topo (junto aos outros `use`, se houver) ou use o FQN `\GuardKids\LicenseServer\SubscriptionService` no closure acima (o namespace do arquivo já é `GuardKids\LicenseServer`, então `SubscriptionService` resolve direto — sem `use` necessário).

- [ ] **Step 2: Rodar a suíte — deve continuar verde**

Run: `php tests/run.php` — Expected: `0 failed` (nada novo quebrou).

- [ ] **Step 3: Lint dos arquivos novos/alterados**

Run: `php -l includes/class-plugin.php && php -l includes/class-subscription-service.php && php -l includes/api/class-webhook-controller.php`
Expected: `No syntax errors detected` em cada.

- [ ] **Step 4: Commit**

```bash
git add includes/class-plugin.php
git commit -m "feat(webhook): fia WebhookController + cron de revogação agendada no boot"
```

---

## Task 11: Bump de versão + suíte final

**Files:**
- Modify: `guardkids-license-server.php`

- [ ] **Step 1: Bump da versão**

Em `guardkids-license-server.php`, troque **os dois** pontos:
- Header: ` * Version:           1.1.0` → ` * Version:           1.2.0`
- Constante: `define('GKL_VERSION', '1.1.0');` → `define('GKL_VERSION', '1.2.0');`

- [ ] **Step 2: Rodar a suíte completa**

Run: `php tests/run.php`
Expected: `== N passed, 0 failed ==` (N = 59 baseline + as ~30 asserções novas desta fatia).

- [ ] **Step 3: Commit**

```bash
git add guardkids-license-server.php
git commit -m "chore(release): v1.2.0 — webhooks de venda (fatia 3, adapter Hotmart)"
```

---

## Fase C — Deploy + configuração real (manual, fora do TDD)

Após o merge em `master`:

1. **Deploy** (mesmo padrão da fatia 2): `scp -P 65002 -r includes guardkids-license-server.php` pro docroot `~/domains/guardiaokids.site/public_html/licencas/wp-content/plugins/guardkids-license-server/`; `wp plugin list` (confirmar v1.2.0); `wp cache flush`.
2. **Criar os produtos na Hotmart** (mensal R$ 29,90 / anual R$ 299,00) e anotar os `product.id` de cada.
3. **Preencher `HotmartAdapter::PRODUCT_PLAN_MAP`** com os IDs reais → `'premium'` (commit + redeploy).
4. **Configurar o webhook na Hotmart**: URL `https://licencas.guardiaokids.site/wp-json/gkl/v1/webhook/hotmart`, eventos `PURCHASE_COMPLETE`/`PURCHASE_APPROVED`/`PURCHASE_REFUNDED`/`CHARGEBACK`/`SUBSCRIPTION_CANCELLATION`.
5. **Definir o secret** no `wp-config.php`: `define('GKL_HOTMART_WEBHOOK_SECRET', '<secret da Hotmart>');` (fora do git).
6. **Smoke E2E**: usar "Send test" da Hotmart (ou um `curl` assinado) → confirmar que um `gkl_code` é criado e o e-mail sai; resgatar na `/ativar`; simular refund → confirmar que a licença cai no `/revoked`.

## Fora desta fatia (próximas)

- Adapters **Eduzz, Cakto, Kiwify** — mais um braço no `AdapterRegistry::resolve` + um `class-<x>-adapter.php` + a constante `GKL_<X>_WEBHOOK_SECRET`, cada um validado com webhook real.
- Depois: Monetizze, Ticto, Spark/Hero, PerfectPay, Lastlink, Braip, Pepper.
