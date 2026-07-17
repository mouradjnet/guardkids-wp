# License Server GuardKids — Núcleo (fatia 1) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Criar o servidor de licenças que emite (Ed25519) e revoga chaves do GuardKids, e fechar o loop no cliente (guardkids-wp) para que a revogação deixe de ser decorativa.

**Architecture:** Dois codebases. O **servidor** (`guardkids-license-server`, fork rebrandado do `fluxomestre-license-server`) cunha chaves assinadas replicando fielmente a mecânica do `scripts/issue-license.php`, registra cada uma num CPT, e publica a lista de `jti` revogados em `GET /gkl/v1/revoked`. O **cliente** (`guardkids-wp`) ganha um `RevocationCache` que faz phone-home diário a esse endpoint com falha aberta, e o `Gate::isRevoked()` passa a consultá-lo. A verificação da licença continua offline (Ed25519, pubkey embarcada) — o servidor só emite e lista revogadas.

**Tech Stack:** PHP 8.1+ (servidor) / 8.2+ (cliente), sodium (Ed25519), WordPress (CPT, REST, WP-CLI, cron, transients). Testes: harness standalone `php tests/run.php` no servidor (herdado do fork); PHPUnit no cliente.

**Referência:** spec em `docs/superpowers/specs/2026-07-17-license-server-design.md`. Contrato da chave definido por `includes/License/Verifier.php`. Mecânica de assinatura a replicar: `scripts/issue-license.php:152-166`.

---

## Convenções de nomes (servidor)

| Item | Valor |
|---|---|
| Repo/dir | `C:/Users/mysho/guardkids-license-server/` |
| Namespace | `GuardKids\LicenseServer` |
| Constantes | `GKL_VERSION`, `GKL_FILE`, `GKL_DIR`, `GKL_URL` |
| Privkey (wp-config) | `GKL_ISSUER_PRIVKEY_B64` |
| CPT | `gkl_license` |
| Post statuses | `gkl_active`, `gkl_revoked` |
| REST namespace | `gkl/v1` (rota `/revoked`) |
| WP-CLI | `wp gkl mint` / `wp gkl revoke` |

## File Structure

**Servidor (`guardkids-license-server/`):**
- `guardkids-license-server.php` — bootstrap do plugin (novo, adaptado do fork)
- `includes/class-autoloader.php` — reusa (rebrand)
- `includes/class-plugin.php` — boot (adapta: registra CPT + RevokedController + CLI)
- `includes/class-license-cpt.php` — adapta (CPT `gkl_license`, statuses `gkl_active`/`gkl_revoked`)
- `includes/class-rate-limiter.php` — reusa (rebrand)
- `includes/class-signer.php` — **novo** (Ed25519, replica issue-license.php)
- `includes/class-license-issuer.php` — **novo** (Service: assina + persiste CPT + email)
- `includes/class-license-repository.php` — **novo** (lookups por status, revoke, lista de jtis)
- `includes/class-cli-command.php` — adapta (`mint` + `revoke`)
- `includes/class-admin.php` — **novo** (row-action "Revogar" no admin do CPT)
- `includes/api/class-revoked-controller.php` — **novo** (`GET /gkl/v1/revoked`)
- `tests/run.php` — adapta (stubs reusados; testa Signer, Issuer, Repository, Controller)
- **Descartados do fork:** `class-hmac-verifier.php`, `class-hotmart-event-handler.php`, `api/class-{validate,status,webhook}-controller.php`

**Cliente (`guardkids-wp/`):**
- `includes/License/RevocationCache.php` — **novo** (phone-home + transient + falha aberta)
- `includes/License/Gate.php:151-155` — modifica `isRevoked()`
- `guardkids.php` — define `GK_LICENSE_SERVER_BASE` + registra o cron
- `tests/Unit/License/RevocationCacheTest.php` — **novo**
- `tests/Unit/License/GateTest.php` — estende (se existir) ou cria caso de revogação remota

---

# FASE A — Servidor

### Task A0: Scaffold do servidor via fork + rebrand

**Files:**
- Create: repo inteiro em `C:/Users/mysho/guardkids-license-server/`

- [ ] **Step 1: Copiar o fork base (sem .git) e inicializar repo novo**

```bash
cd /c/Users/mysho
cp -r fluxomestre-license-server guardkids-license-server
cd guardkids-license-server
rm -rf .git
# remover os arquivos do modelo online que não entram nesta fatia
rm -f includes/class-hmac-verifier.php includes/class-hotmart-event-handler.php
rm -f includes/api/class-validate-controller.php includes/api/class-status-controller.php includes/api/class-webhook-controller.php
mv fluxomestre-license-server.php guardkids-license-server.php
git init -q && git add -A && git commit -q -m "chore: import scaffold from fluxomestre-license-server (pre-rebrand)"
```

- [ ] **Step 2: Rebrand em massa (namespace, constantes, textos)**

```bash
cd /c/Users/mysho/guardkids-license-server
# namespace e constantes em todo o PHP
grep -rl 'FluxoMestre\\LicenseServer\|FML_\|fluxomestre-license\|FluxoMestre License' --include=*.php . \
  | xargs sed -i \
    -e 's/FluxoMestre\\\\LicenseServer/GuardKids\\LicenseServer/g' \
    -e 's/FML_/GKL_/g' \
    -e "s/fluxomestre-license/guardkids-license/g"
```

- [ ] **Step 3: Reescrever o bootstrap `guardkids-license-server.php`**

Substituir o conteúdo inteiro por (header + boot rebrandados):

```php
<?php
/**
 * Plugin Name:       GuardKids License Server
 * Plugin URI:        https://github.com/mouradjnet/guardkids-license-server
 * Description:       Servidor de licenças do GuardKids — emite chaves Ed25519 e publica a lista de licenças revogadas. Expõe GET /wp-json/gkl/v1/revoked.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            Djair Falcão
 * License:           GPL-2.0-or-later
 * Text Domain:       guardkids-license-server
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('GKL_VERSION', '1.0.0');
define('GKL_FILE', __FILE__);
define('GKL_DIR', plugin_dir_path(__FILE__));
define('GKL_URL', plugin_dir_url(__FILE__));

require_once GKL_DIR . 'includes/class-autoloader.php';
\GuardKids\LicenseServer\Autoloader::register();

add_action('plugins_loaded', static function (): void {
    \GuardKids\LicenseServer\Plugin::instance()->boot();
});
```

- [ ] **Step 4: Reescrever `includes/class-plugin.php` (remove webhook/validate/status, adiciona RevokedController + Admin)**

```php
<?php
declare(strict_types=1);

namespace GuardKids\LicenseServer;

use GuardKids\LicenseServer\Api\RevokedController;

defined('ABSPATH') || exit;

final class Plugin
{
    private static ?self $instance = null;
    private bool $booted = false;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        (new LicenseCpt())->register();
        (new RevokedController())->register();
        (new Admin())->register();

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('gkl', CliCommand::class);
        }
    }
}
```

- [ ] **Step 5: Adaptar `includes/class-license-cpt.php` (statuses gkl_active/gkl_revoked)**

```php
<?php
declare(strict_types=1);

namespace GuardKids\LicenseServer;

defined('ABSPATH') || exit;

final class LicenseCpt
{
    public const POST_TYPE = 'gkl_license';
    public const STATUSES  = ['gkl_active', 'gkl_revoked'];

    public function register(): void
    {
        add_action('init', [$this, 'registerPostType']);
        add_action('init', [$this, 'registerStatuses']);
    }

    public function registerPostType(): void
    {
        register_post_type(self::POST_TYPE, [
            'label'           => 'Licenças',
            'public'          => false,
            'show_ui'         => true,
            'show_in_menu'    => true,
            'menu_icon'       => 'dashicons-shield',
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
        foreach (self::STATUSES as $status) {
            register_post_status($status, [
                'label'                     => $status === 'gkl_revoked' ? 'Revogada' : 'Ativa',
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

- [ ] **Step 6: Verificar php -l em todos os arquivos e commitar o rebrand**

```bash
cd /c/Users/mysho/guardkids-license-server
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l | grep -v 'No syntax errors' || echo "lint OK"
git add -A && git commit -q -m "chore: rebrand FluxoMestre→GuardKids license server scaffold"
```

Expected: `lint OK` (nenhuma linha de erro).

---

### Task A1: Signer Ed25519 (replica issue-license.php)

**Files:**
- Create: `includes/class-signer.php`
- Test: `tests/run.php` (adiciona bloco)

- [ ] **Step 1: Escrever o teste que falha (assina e verifica com um keypair de teste)**

No `tests/run.php`, no bootstrap de constantes adicione um keypair de teste e no bloco de asserts adicione:

```php
// --- Signer ---
use GuardKids\LicenseServer\Signer;

$kp     = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($kp);
$pub    = sodium_crypto_sign_publickey($kp);

$signer  = new Signer(base64_encode($secret));
$payload = [
    'iss' => 'guardkids', 'sub' => 'https://cliente.com', 'jti' => 'abc123',
    'iat' => 1000, 'exp' => 2000, 'plan' => 'premium', 'features' => ['browser'], 'email' => 'x@y.com',
];
$key = $signer->sign($payload);

// formato: <b64url(json)>.<b64url(sig)>
$parts = explode('.', $key, 3);
ok('signer: formato de 2 partes', count($parts) === 2);

// verificação cruzada: a assinatura cobre os BYTES do payload b64url encoded
[$pB64, $sB64] = $parts;
$sig = sodium_base642bin(strtr($pB64 === '' ? '' : $sB64, '-_', '+/') . str_repeat('=', (4 - strlen($sB64) % 4) % 4), SODIUM_BASE64_VARIANT_ORIGINAL);
ok('signer: assinatura verifica com a pubkey', sodium_crypto_sign_verify_detached($sig, $pB64, $pub));

// payload round-trips com JSON_UNESCAPED_SLASHES (barras não viram \/)
$json = sodium_base642bin($pB64 . str_repeat('=', (4 - strlen($pB64) % 4) % 4), SODIUM_BASE64_VARIANT_ORIGINAL_NO_PADDING);
ok('signer: sub sem escape de barra', str_contains($json, 'https://cliente.com'));
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `php -d extension=sodium tests/run.php`
Expected: FAIL — `Class "GuardKids\LicenseServer\Signer" not found`.

- [ ] **Step 3: Implementar `includes/class-signer.php`**

```php
<?php
declare(strict_types=1);

namespace GuardKids\LicenseServer;

defined('ABSPATH') || exit;

/**
 * Cunha chaves de licença assinadas com Ed25519.
 *
 * Réplica fiel de scripts/issue-license.php:152-166 do guardkids-wp — o cliente
 * (Verifier) só aceita chaves geradas com ESTE encoding. Qualquer divergência
 * (ordem de campos irrelevante; mas JSON_UNESCAPED_SLASHES e b64url são
 * obrigatórios) quebra a verificação.
 *
 * Formato: <base64url(payload_json)>.<base64url(assinatura)>
 */
final class Signer
{
    private string $secret;

    public function __construct(?string $privkeyB64 = null)
    {
        $b64 = $privkeyB64 ?? (defined('GKL_ISSUER_PRIVKEY_B64') ? (string) GKL_ISSUER_PRIVKEY_B64 : '');
        $raw = base64_decode($b64, true);
        $this->secret = $raw === false ? '' : $raw;
    }

    public function isReady(): bool
    {
        return \strlen($this->secret) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function sign(array $payload): string
    {
        if (!$this->isReady()) {
            throw new \RuntimeException('Privkey Ed25519 ausente ou corrompida (defina GKL_ISSUER_PRIVKEY_B64).');
        }
        $json      = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $b64       = self::b64url($json);
        $signature = sodium_crypto_sign_detached($b64, $this->secret);
        return $b64 . '.' . self::b64url($signature);
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
```

- [ ] **Step 4: Rodar o teste e confirmar que passa**

Run: `php -d extension=sodium tests/run.php`
Expected: PASS — as 3 asserts do Signer verdes, contador total subiu +3.

- [ ] **Step 5: Commit**

```bash
git add includes/class-signer.php tests/run.php
git commit -q -m "feat: Signer Ed25519 replicando o encoding do issue-license"
```

---

### Task A2: LicenseRepository (persistência e lookups)

**Files:**
- Create: `includes/class-license-repository.php`
- Test: `tests/run.php`

- [ ] **Step 1: Escrever o teste que falha**

Adicionar no `tests/run.php` (usa os stubs `wp_insert_post`/`get_posts`/`get_post_meta` já presentes):

```php
// --- LicenseRepository ---
use GuardKids\LicenseServer\LicenseRepository;
use GuardKids\LicenseServer\LicenseCpt;

$GLOBALS['posts'] = [];
$GLOBALS['meta']  = [];
$repo = new LicenseRepository();

// persist
$GLOBALS['next_id'] = 42;
$id = $repo->persist(['jti' => 'j1', 'sub' => 'https://a.com', 'email' => 'a@a.com', 'plan' => 'premium', 'features' => ['browser'], 'iat' => 1, 'exp' => 2, 'key_b64' => 'KEY']);
ok('repo: persist devolve id', $id === 42);
ok('repo: grava status gkl_active', ($GLOBALS['insert']['post_status'] ?? '') === 'gkl_active');
ok('repo: grava jti no meta', ($GLOBALS['insert']['meta_input']['jti'] ?? '') === 'j1');

// revokedJtis lê só os gkl_revoked
$p = new WP_Post(); $p->ID = 7; $p->post_status = 'gkl_revoked';
$GLOBALS['posts'] = [$p];
$GLOBALS['meta'][7] = ['jti' => 'j-revoked'];
ok('repo: revokedJtis retorna o jti', $repo->revokedJtis() === ['j-revoked']);
ok('repo: lookup usa status explícito (não "any")', ($GLOBALS['last_get_posts']['post_status'] ?? null) === ['gkl_revoked']);
```

- [ ] **Step 2: Rodar e confirmar falha**

Run: `php -d extension=sodium tests/run.php`
Expected: FAIL — `Class "GuardKids\LicenseServer\LicenseRepository" not found`.

- [ ] **Step 3: Implementar `includes/class-license-repository.php`**

```php
<?php
declare(strict_types=1);

namespace GuardKids\LicenseServer;

defined('ABSPATH') || exit;

/**
 * Acesso ao CPT gkl_license. Todos os lookups usam a lista explícita de status
 * (LicenseCpt::STATUSES) — NUNCA 'any', que exclui status com
 * exclude_from_search => true e torna toda licença invisível (gotcha herdado do
 * fluxomestre-license-server v1.1.1).
 */
final class LicenseRepository
{
    /**
     * @param array{jti:string,sub:string,email:string,plan:string,features:list<string>,iat:int,exp:int,key_b64:string} $data
     */
    public function persist(array $data): int
    {
        return (int) wp_insert_post([
            'post_type'   => LicenseCpt::POST_TYPE,
            'post_status' => 'gkl_active',
            'post_title'  => 'Licença ' . substr($data['jti'], 0, 8) . ' — ' . $data['email'],
            'meta_input'  => [
                'jti'      => $data['jti'],
                'sub'      => $data['sub'],
                'email'    => $data['email'],
                'plan'     => $data['plan'],
                'features' => $data['features'],
                'iat'      => $data['iat'],
                'exp'      => $data['exp'],
                'key_b64'  => $data['key_b64'],
            ],
        ]);
    }

    public function findByJti(string $jti): ?\WP_Post
    {
        if ($jti === '') {
            return null;
        }
        $posts = get_posts([
            'post_type'   => LicenseCpt::POST_TYPE,
            'post_status' => LicenseCpt::STATUSES,
            'meta_key'    => 'jti',
            'meta_value'  => $jti,
            'numberposts' => 1,
        ]);
        return $posts[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function revokedJtis(): array
    {
        $posts = get_posts([
            'post_type'   => LicenseCpt::POST_TYPE,
            'post_status' => ['gkl_revoked'],
            'numberposts' => -1,
        ]);
        $jtis = [];
        foreach ($posts as $post) {
            $jti = (string) get_post_meta($post->ID, 'jti', true);
            if ($jti !== '') {
                $jtis[] = $jti;
            }
        }
        return $jtis;
    }

    public function revoke(string $jti): bool
    {
        $post = $this->findByJti($jti);
        if ($post === null) {
            return false;
        }
        wp_update_post(['ID' => $post->ID, 'post_status' => 'gkl_revoked']);
        return true;
    }
}
```

- [ ] **Step 4: Adicionar stub `wp_update_post` no tests/run.php (se ausente)**

No bloco de function stubs do `tests/run.php`:

```php
function wp_update_post(array $a): int { $GLOBALS['updated'] = $a; return (int) ($a['ID'] ?? 0); }
```

- [ ] **Step 5: Rodar e confirmar passa**

Run: `php -d extension=sodium tests/run.php`
Expected: PASS — 5 asserts do repositório verdes.

- [ ] **Step 6: Commit**

```bash
git add includes/class-license-repository.php tests/run.php
git commit -q -m "feat: LicenseRepository (persist/lookup/revoke) com status explícito"
```

---

### Task A3: LicenseIssuer (assina + persiste + email)

**Files:**
- Create: `includes/class-license-issuer.php`
- Test: `tests/run.php`

- [ ] **Step 1: Escrever o teste que falha**

```php
// --- LicenseIssuer ---
use GuardKids\LicenseServer\LicenseIssuer;

$GLOBALS['insert'] = null; $GLOBALS['mail'] = null; $GLOBALS['next_id'] = 99;
$issuer = new LicenseIssuer(new Signer(base64_encode($secret)), new LicenseRepository());
$res = $issuer->issue('cli@ente.com', 'https://cli.com/', strtotime('2030-01-01'), sendEmail: true);

ok('issuer: devolve license_key não vazia', is_string($res['license_key']) && $res['license_key'] !== '');
ok('issuer: sub sem barra final', $res['sub'] === 'https://cli.com');
ok('issuer: jti tem 24 hex', (bool) preg_match('/^[0-9a-f]{24}$/', $res['jti']));
ok('issuer: persistiu no CPT', ($GLOBALS['insert']['meta_input']['jti'] ?? '') === $res['jti']);
ok('issuer: enviou email pro cliente', ($GLOBALS['mail']['to'] ?? '') === 'cli@ente.com');
// a chave emitida verifica com a pubkey do keypair de teste
$kp2 = explode('.', $res['license_key'], 3);
$sig2 = sodium_base642bin(strtr($kp2[1], '-_', '+/') . str_repeat('=', (4 - strlen($kp2[1]) % 4) % 4), SODIUM_BASE64_VARIANT_ORIGINAL);
ok('issuer: chave verifica com a pubkey', sodium_crypto_sign_verify_detached($sig2, $kp2[0], $pub));
```

- [ ] **Step 2: Rodar e confirmar falha**

Run: `php -d extension=sodium tests/run.php`
Expected: FAIL — `Class "GuardKids\LicenseServer\LicenseIssuer" not found`.

- [ ] **Step 3: Implementar `includes/class-license-issuer.php`**

```php
<?php
declare(strict_types=1);

namespace GuardKids\LicenseServer;

defined('ABSPATH') || exit;

/**
 * Regra de emissão de licença — reusada pelo WP-CLI (mint) e, na fatia futura,
 * pela página self-service. Assina, persiste no CPT e (opcional) envia por email.
 */
final class LicenseIssuer
{
    /** As 7 features premium — espelha Gate::PREMIUM_FEATURES do guardkids-wp. */
    public const ALL_FEATURES = [
        'browser', 'categories', 'schedule', 'reports', 'location', 'unlimited_kids', 'full_history',
    ];

    public function __construct(
        private Signer $signer = new Signer(),
        private LicenseRepository $repo = new LicenseRepository(),
    ) {}

    /**
     * @param list<string>|null $features
     * @return array{license_id:int,license_key:string,jti:string,sub:string,exp:int}
     */
    public function issue(
        string $email,
        string $domain,
        int $exp,
        string $plan = 'premium',
        ?array $features = null,
        bool $sendEmail = true,
    ): array {
        $jti     = bin2hex(random_bytes(12));
        $sub     = rtrim($domain, '/');
        $payload = [
            'iss'      => 'guardkids',
            'sub'      => $sub,
            'jti'      => $jti,
            'iat'      => time(),
            'exp'      => $exp,
            'plan'     => $plan,
            'features' => $features ?? self::ALL_FEATURES,
            'email'    => $email,
        ];

        $key = $this->signer->sign($payload);

        $id = $this->repo->persist([
            'jti'      => $jti,
            'sub'      => $sub,
            'email'    => $email,
            'plan'     => $plan,
            'features' => $payload['features'],
            'iat'      => $payload['iat'],
            'exp'      => $exp,
            'key_b64'  => $key,
        ]);

        if ($sendEmail) {
            $this->sendKeyEmail($email, $key, $sub, $exp);
        }

        return ['license_id' => $id, 'license_key' => $key, 'jti' => $jti, 'sub' => $sub, 'exp' => $exp];
    }

    private function sendKeyEmail(string $email, string $key, string $sub, int $exp): void
    {
        if ($email === '') {
            return;
        }
        $subj = 'Sua licença GuardKids Premium';
        $body = "Olá!\n\nSua licença GuardKids Premium para {$sub} está pronta.\n"
              . "Válida até " . gmdate('d/m/Y', $exp) . ".\n\n"
              . "Chave:\n{$key}\n\n"
              . "Como ativar: no painel dos pais, vá em Configurações → Licença → cole a chave → Ativar.";
        wp_mail($email, $subj, $body);
    }
}
```

- [ ] **Step 4: Rodar e confirmar passa**

Run: `php -d extension=sodium tests/run.php`
Expected: PASS — 6 asserts do issuer verdes.

- [ ] **Step 5: Commit**

```bash
git add includes/class-license-issuer.php tests/run.php
git commit -q -m "feat: LicenseIssuer (assina + persiste CPT + email)"
```

---

### Task A4: WP-CLI `mint` e `revoke`

**Files:**
- Modify: `includes/class-cli-command.php` (substituição integral)

- [ ] **Step 1: Substituir `includes/class-cli-command.php`**

```php
<?php
declare(strict_types=1);

namespace GuardKids\LicenseServer;

defined('ABSPATH') || exit;

/**
 * Comandos WP-CLI do servidor de licenças GuardKids.
 * Registrado só sob WP-CLI (ver Plugin::boot). Substitui o issue-license.php
 * que rodava no notebook do dev.
 */
final class CliCommand
{
    /**
     * Emite uma licença premium assinada e imprime a chave.
     *
     * ## OPTIONS
     *
     * --email=<email>
     * : E-mail do cliente (vai no payload e recebe a chave).
     *
     * --domain=<url>
     * : URL do WP do cliente (https://exemplo.com) — trava a licença a esse domínio.
     *
     * --expires=<data>
     * : Data de expiração parsável por strtotime (ex: 2027-12-31, "+1 year").
     *
     * [--plan=<plan>]
     * : premium | free. Padrão: premium.
     *
     * [--no-email]
     * : Não envia o email (só imprime a chave).
     *
     * [--format=<format>]
     * : table | json. Padrão: table.
     *
     * ## EXAMPLES
     *
     *     wp gkl mint --email=cliente@x.com --domain=https://cliente.com --expires=2027-12-31
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc
     */
    public function mint(array $args, array $assoc): void
    {
        $email  = (string) ($assoc['email'] ?? '');
        $domain = (string) ($assoc['domain'] ?? '');
        $plan   = (string) ($assoc['plan'] ?? 'premium');
        $expRaw = (string) ($assoc['expires'] ?? '');

        if (!is_email($email)) {
            \WP_CLI::error('--email inválido.');
        }
        if (!preg_match('#^https?://#i', $domain)) {
            \WP_CLI::error('--domain precisa começar com http:// ou https://.');
        }
        if (!in_array($plan, ['premium', 'free'], true)) {
            \WP_CLI::error('--plan precisa ser premium ou free.');
        }
        $exp = strtotime($expRaw);
        if ($exp === false || $exp <= time()) {
            \WP_CLI::error('--expires precisa ser uma data futura parsável (ex: 2027-12-31).');
        }

        $sendEmail = !isset($assoc['no-email']);
        $res = (new LicenseIssuer())->issue($email, $domain, $exp, $plan, sendEmail: $sendEmail);

        if (($assoc['format'] ?? 'table') === 'json') {
            \WP_CLI::line((string) wp_json_encode($res));
            return;
        }

        \WP_CLI::success("Licença emitida (jti {$res['jti']}).");
        \WP_CLI\Utils\format_items('table', [[
            'jti'         => $res['jti'],
            'sub'         => $res['sub'],
            'expires'     => gmdate('Y-m-d', $res['exp']),
            'license_key' => $res['license_key'],
        ]], ['jti', 'sub', 'expires', 'license_key']);
    }

    /**
     * Revoga uma licença pelo jti (aparece em /gkl/v1/revoked no próximo poll do cliente).
     *
     * ## OPTIONS
     *
     * --jti=<jti>
     * : jti da licença a revogar.
     *
     * ## EXAMPLES
     *
     *     wp gkl revoke --jti=a1b2c3d4e5f6a1b2c3d4e5f6
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc
     */
    public function revoke(array $args, array $assoc): void
    {
        $jti = (string) ($assoc['jti'] ?? '');
        if ($jti === '') {
            \WP_CLI::error('--jti obrigatório.');
        }
        if ((new LicenseRepository())->revoke($jti)) {
            \WP_CLI::success("Licença {$jti} revogada.");
        } else {
            \WP_CLI::error("Nenhuma licença ativa com jti {$jti}.");
        }
    }
}
```

- [ ] **Step 2: Lint e commit**

```bash
php -l includes/class-cli-command.php
git add includes/class-cli-command.php
git commit -q -m "feat: WP-CLI gkl mint/revoke"
```

Expected: `No syntax errors detected`. (Sem teste automatizado: comandos WP-CLI exercitam via smoke E2E na Fase C; a lógica — Issuer/Repository — já está coberta.)

---

### Task A5: RevokedController — `GET /gkl/v1/revoked`

**Files:**
- Create: `includes/api/class-revoked-controller.php`
- Test: `tests/run.php`

- [ ] **Step 1: Escrever o teste que falha**

```php
// --- RevokedController ---
use GuardKids\LicenseServer\Api\RevokedController;

$GLOBALS['tr'] = []; // reset rate-limit
$pRev = new WP_Post(); $pRev->ID = 3; $pRev->post_status = 'gkl_revoked';
$GLOBALS['posts'] = [$pRev];
$GLOBALS['meta'][3] = ['jti' => 'revoked-jti'];

$ctrl = new RevokedController();
$resp = $ctrl->handle(new WP_REST_Request([], '', ['x-forwarded-for' => '1.2.3.4']));
ok('revoked: status 200', $resp->get_status() === 200);
$data = $resp->get_data();
ok('revoked: lista contém o jti revogado', ($data['revoked'] ?? []) === ['revoked-jti']);
ok('revoked: tem generated_at', isset($data['generated_at']));
```

- [ ] **Step 2: Rodar e confirmar falha**

Run: `php -d extension=sodium tests/run.php`
Expected: FAIL — `Class "GuardKids\LicenseServer\Api\RevokedController" not found`.

- [ ] **Step 3: Implementar `includes/api/class-revoked-controller.php`**

```php
<?php
declare(strict_types=1);

namespace GuardKids\LicenseServer\Api;

use GuardKids\LicenseServer\LicenseRepository;
use GuardKids\LicenseServer\RateLimiter;

defined('ABSPATH') || exit;

/**
 * GET /wp-json/gkl/v1/revoked — lista pública dos jti revogados.
 *
 * Público de propósito: um jti é hex opaco, a lista não vaza dado pessoal. O
 * cliente (guardkids-wp) cacheia a lista inteira e decide localmente, offline
 * entre os polls. Rate-limited contra abuso.
 */
final class RevokedController
{
    public function __construct(
        private LicenseRepository $repo = new LicenseRepository(),
        private RateLimiter $limiter = new RateLimiter(),
    ) {}

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('gkl/v1', '/revoked', [
                'methods'             => 'GET',
                'callback'            => [$this, 'handle'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public function handle(\WP_REST_Request $req): \WP_REST_Response
    {
        $ip = (string) ($req->get_header('x-forwarded-for') ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        if (!$this->limiter->check($ip)) {
            return new \WP_REST_Response(['error' => 'rate_limited'], 429);
        }

        return new \WP_REST_Response([
            'revoked'      => $this->repo->revokedJtis(),
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ], 200);
    }
}
```

- [ ] **Step 4: Rodar e confirmar passa**

Run: `php -d extension=sodium tests/run.php`
Expected: PASS — 3 asserts do controller verdes.

- [ ] **Step 5: Commit**

```bash
git add includes/api/class-revoked-controller.php tests/run.php
git commit -q -m "feat: GET /gkl/v1/revoked (rate-limited, público)"
```

---

### Task A6: Admin — ação "Revogar" no CPT

**Files:**
- Create: `includes/class-admin.php`

- [ ] **Step 1: Implementar `includes/class-admin.php`**

```php
<?php
declare(strict_types=1);

namespace GuardKids\LicenseServer;

defined('ABSPATH') || exit;

/**
 * Ação de linha "Revogar" na listagem do CPT gkl_license — o caminho de um
 * clique que a spec pede, complementando o WP-CLI revoke.
 */
final class Admin
{
    public function register(): void
    {
        add_filter('post_row_actions', [$this, 'rowAction'], 10, 2);
        add_action('admin_action_gkl_revoke', [$this, 'handleRevoke']);
    }

    /**
     * @param array<string,string> $actions
     * @return array<string,string>
     */
    public function rowAction(array $actions, \WP_Post $post): array
    {
        if ($post->post_type !== LicenseCpt::POST_TYPE || $post->post_status === 'gkl_revoked') {
            return $actions;
        }
        $url = wp_nonce_url(
            admin_url('admin.php?action=gkl_revoke&post=' . $post->ID),
            'gkl_revoke_' . $post->ID
        );
        $actions['gkl_revoke'] = '<a href="' . esc_url($url) . '" style="color:#b32d2e">Revogar</a>';
        return $actions;
    }

    public function handleRevoke(): void
    {
        $postId = (int) ($_GET['post'] ?? 0);
        if (!current_user_can('edit_posts') || !wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'gkl_revoke_' . $postId)) {
            wp_die('Ação não autorizada.');
        }
        $jti = (string) get_post_meta($postId, 'jti', true);
        if ($jti !== '') {
            (new LicenseRepository())->revoke($jti);
        }
        wp_safe_redirect(admin_url('edit.php?post_type=' . LicenseCpt::POST_TYPE));
        exit;
    }
}
```

- [ ] **Step 2: Lint e commit**

```bash
php -l includes/class-admin.php
git add includes/class-admin.php
git commit -q -m "feat: ação Revogar na listagem do CPT"
```

Expected: `No syntax errors detected`. (UI de admin: validada no smoke, não em unit.)

---

### Task A7: `.gitignore` da privkey + README de deploy

**Files:**
- Modify: `.gitignore`
- Modify: `README.md`

- [ ] **Step 1: Garantir que nenhuma chave vaze**

Adicionar ao `.gitignore`:

```
*.key
wp-config.php
```

- [ ] **Step 2: README com os passos de deploy (a privkey é do issue-license existente)**

Substituir o README por instruções GuardKids, incluindo:

```markdown
## Deploy

1. Copie a pasta para `wp-content/plugins/` num WP na Hostinger e ative.
2. No `wp-config.php` do servidor, defina a privkey Ed25519 — é o MESMO par cuja
   pubkey já está embarcada em `Verifier::DEFAULT_ISSUER_PUBKEY_B64` do guardkids-wp:

       define('GKL_ISSUER_PRIVKEY_B64', '<conteúdo de ~/.guardkids/issuer.key>');

   (O arquivo já contém a privkey em base64 — é só colar o conteúdo.)
3. Emita: `wp gkl mint --email=... --domain=https://cliente.com --expires=2027-12-31`
4. Confirme o endpoint: `curl https://<servidor>/wp-json/gkl/v1/revoked`
```

- [ ] **Step 3: Commit**

```bash
git add .gitignore README.md
git commit -q -m "docs: gitignore da privkey + README de deploy"
```

---

# FASE B — Cliente (guardkids-wp)

> **Working dir:** `C:/Users/mysho/guardkids-wp`. Criar branch: `git checkout -b feat/revocation-cache`.
> Testes PHP rodam com o wrapper do LocalWP (ver CLAUDE.md): PHP 8.2 + `-d extension=sodium`.

### Task B1: RevocationCache (phone-home + falha aberta)

**Files:**
- Create: `includes/License/RevocationCache.php`
- Test: `tests/Unit/License/RevocationCacheTest.php`

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php
declare(strict_types=1);

namespace GuardKids\Tests\Unit\License;

use GuardKids\License\RevocationCache;
use PHPUnit\Framework\TestCase;

final class RevocationCacheTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['gk_transients'] = [];
    }

    public function testApplyResponsePopulatesCacheAndIsRevoked(): void
    {
        $cache = new RevocationCache('https://server.test/wp-json/gkl/v1/');
        $cache->applyResponse(['revoked' => ['jti-a', 'jti-b'], 'generated_at' => 'x']);

        $this->assertTrue($cache->isRevoked('jti-a'));
        $this->assertFalse($cache->isRevoked('jti-c'));
    }

    public function testFailOpenKeepsPreviousCacheOnBadResponse(): void
    {
        $cache = new RevocationCache('https://server.test/wp-json/gkl/v1/');
        $cache->applyResponse(['revoked' => ['jti-a']]);   // cache prévio
        $cache->applyResponse(null);                       // servidor fora / lixo
        $this->assertTrue($cache->isRevoked('jti-a'), 'falha não pode limpar o cache');
    }

    public function testNoCacheMeansNobodyRevoked(): void
    {
        $cache = new RevocationCache('https://server.test/wp-json/gkl/v1/');
        $this->assertFalse($cache->isRevoked('jti-a'), 'sem cache = ninguém revogado (falha aberta)');
    }
}
```

- [ ] **Step 2: Adicionar stubs de transient no bootstrap de teste (se ausentes)**

Verificar `tests/bootstrap.php`; se `get_transient`/`set_transient` não existem, adicionar:

```php
if (!function_exists('get_transient')) {
    function get_transient(string $k): mixed { return $GLOBALS['gk_transients'][$k] ?? false; }
    function set_transient(string $k, mixed $v, int $ttl = 0): bool { $GLOBALS['gk_transients'][$k] = $v; return true; }
}
```

- [ ] **Step 3: Rodar e confirmar falha**

Run: `"<php-localwp>" -d extension=sodium vendor/bin/phpunit --testsuite unit --filter RevocationCache`
Expected: FAIL — `Class "GuardKids\License\RevocationCache" not found`.

- [ ] **Step 4: Implementar `includes/License/RevocationCache.php`**

```php
<?php
declare(strict_types=1);

namespace GuardKids\License;

/**
 * Cache local da lista de jti revogados, populado por phone-home diário ao
 * license server. FALHA ABERTA: se o servidor estiver fora, mantém o último
 * cache e nunca revoga por indisponibilidade — derrubar premium legítimo por
 * causa de um servidor offline seria pior que o atraso de até ~24h na revogação.
 */
final class RevocationCache
{
    private const TRANSIENT = 'gk_revoked_jti';
    private const TTL        = 90000; // ~25h — sobrevive a um poll perdido

    private string $base;

    public function __construct(string $serverBase = '')
    {
        if ($serverBase === '') {
            $serverBase = defined('GK_LICENSE_SERVER_BASE') ? (string) GK_LICENSE_SERVER_BASE : '';
        }
        $this->base = $serverBase;
    }

    public function isRevoked(string $jti): bool
    {
        return $jti !== '' && \in_array($jti, $this->list(), true);
    }

    /**
     * @return list<string>
     */
    public function list(): array
    {
        $cached = get_transient(self::TRANSIENT);
        return \is_array($cached) ? array_values(array_filter($cached, 'is_string')) : [];
    }

    /**
     * Aplica o corpo decodificado do /revoked. Corpo inválido/null = no-op
     * (falha aberta — preserva o cache anterior).
     */
    public function applyResponse(mixed $body): void
    {
        if (!\is_array($body) || !isset($body['revoked']) || !\is_array($body['revoked'])) {
            return;
        }
        $jtis = array_values(array_filter($body['revoked'], 'is_string'));
        set_transient(self::TRANSIENT, $jtis, self::TTL);
    }

    /**
     * Phone-home. Glue fina sobre applyResponse — exercitada no smoke E2E.
     */
    public function refresh(): void
    {
        if ($this->base === '') {
            return;
        }
        $res = wp_remote_get(trailingslashit($this->base) . 'revoked', ['timeout' => 10]);
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            return; // falha aberta
        }
        $this->applyResponse(json_decode((string) wp_remote_retrieve_body($res), true));
    }
}
```

- [ ] **Step 5: Rodar e confirmar passa**

Run: `"<php-localwp>" -d extension=sodium vendor/bin/phpunit --testsuite unit --filter RevocationCache`
Expected: PASS — 3 testes verdes.

- [ ] **Step 6: Commit**

```bash
git add includes/License/RevocationCache.php tests/Unit/License/RevocationCacheTest.php tests/bootstrap.php
git commit -m "feat(license): RevocationCache com phone-home e falha aberta"
```

---

### Task B2: `Gate::isRevoked()` consulta o cache remoto

**Files:**
- Modify: `includes/License/Gate.php` (construtor + `isRevoked`)
- Test: `tests/Unit/License/GateTest.php` (ou novo arquivo se não existir)

- [ ] **Step 1: Escrever o teste que falha (revogação via cache remoto)**

Adicionar a um teste do Gate. Requer injetar o cache — usar transient direto (o Gate cria seu próprio `RevocationCache`, que lê o transient stubado):

```php
public function testStatusRevokedWhenJtiInRemoteCache(): void
{
    // instala licença válida (payload com jti conhecido) — reusar helper de fixture
    // do GateTest existente que grava guardkids_license com uma chave assinada de teste.
    $jti = $this->installValidLicenseReturningJti(); // helper existente/novo

    $GLOBALS['gk_transients']['gk_revoked_jti'] = [$jti];

    $gate = new Gate($this->testVerifier());
    $this->assertSame('revoked', $gate->status());
}
```

> Se o `GateTest` atual não tiver fixture de licença assinada, criar o helper com um keypair
> de teste + `Verifier($pubTest)` injetado, assinando um payload com `jti` conhecido, e gravando
> `update_option('guardkids_license', ['key_b64' => $key])`.

- [ ] **Step 2: Rodar e confirmar falha**

Run: `"<php-localwp>" -d extension=sodium vendor/bin/phpunit --testsuite unit --filter GateTest`
Expected: FAIL — status volta `active` (o cache remoto ainda não é consultado).

- [ ] **Step 3: Modificar `Gate.php` — construtor aceita RevocationCache; isRevoked consulta os dois**

Construtor (linha 47-50), adicionar o parâmetro opcional:

```php
    private readonly Verifier $verifier;
    private readonly RevocationCache $revocations;
    private ?Payload $cachedPayload = null;
    private bool $payloadResolved = false;

    public function __construct(?Verifier $verifier = null, ?RevocationCache $revocations = null)
    {
        $this->verifier    = $verifier ?? new Verifier();
        $this->revocations = $revocations ?? new RevocationCache();
    }
```

`isRevoked` (linha 151-155), substituir por:

```php
    private function isRevoked(Payload $payload): bool
    {
        // Fonte de verdade: lista remota do license server (cron diário, falha aberta).
        if ($this->revocations->isRevoked($payload->jti)) {
            return true;
        }
        // Override manual de emergência (revogar na unha sem depender do servidor).
        $list = get_option('guardkids_license_revoked', []);
        return \is_array($list) && \in_array($payload->jti, $list, true);
    }
```

Adicionar o import no topo do arquivo (após `namespace GuardKids\License;` já é o mesmo namespace — `RevocationCache` está em `GuardKids\License`, então **não precisa `use`**).

- [ ] **Step 4: Rodar e confirmar passa (e nada regrediu)**

Run: `"<php-localwp>" -d extension=sodium vendor/bin/phpunit --testsuite unit --filter GateTest`
Expected: PASS — o novo teste + todos os testes de Gate existentes verdes (o override local mantém o comportamento antigo).

- [ ] **Step 5: Commit**

```bash
git add includes/License/Gate.php tests/Unit/License/GateTest.php
git commit -m "feat(license): Gate consulta a lista remota de revogação (mantém override local)"
```

---

### Task B3: Constante do servidor + cron diário

**Files:**
- Modify: `guardkids.php`

- [ ] **Step 1: Definir a base do servidor e o cron**

No `guardkids.php`, junto das outras constantes (após a linha `define('GUARDKIDS_DB_VERSION', 25);`):

```php
// Base REST do license server (phone-home de revogação). Ajustar ao domínio real do servidor.
if (!defined('GK_LICENSE_SERVER_BASE')) {
    define('GK_LICENSE_SERVER_BASE', 'https://SEU-LICENSE-SERVER/wp-json/gkl/v1/');
}
```

E o agendamento do cron (junto do bootstrap de hooks do plugin — onde os outros `add_action` de init vivem):

```php
add_action('gk_refresh_revocations', static function (): void {
    (new \GuardKids\License\RevocationCache())->refresh();
});
add_action('init', static function (): void {
    if (!wp_next_scheduled('gk_refresh_revocations')) {
        wp_schedule_event(time(), 'daily', 'gk_refresh_revocations');
    }
});
```

E limpar o agendamento no desligamento — em `uninstall.php` (ou no `register_deactivation_hook` existente):

```php
wp_clear_scheduled_hook('gk_refresh_revocations');
```

- [ ] **Step 2: Verificar o plugin carrega sem fatal (lint + suíte completa)**

Run: `"<php-localwp>" -d extension=sodium vendor/bin/phpunit --testsuite unit`
Expected: PASS — suíte PHP completa verde (nenhuma regressão).

- [ ] **Step 3: Commit**

```bash
git add guardkids.php uninstall.php
git commit -m "feat(license): constante do license server + cron diário de revogação"
```

---

# FASE C — Smoke E2E (manual)

> Requer o servidor deployado num WP (LocalWP serve pra E2E) com `GKL_ISSUER_PRIVKEY_B64`
> definido = a privkey cujo par é a pubkey embarcada no `Verifier` do guardkids-wp. E o
> `GK_LICENSE_SERVER_BASE` do cliente apontando pra esse servidor.

- [ ] **1. Emitir no servidor** — `wp gkl mint --email=eu@teste.com --domain=<siteurl do cliente> --expires="+1 year"`. Copiar a `license_key`.
- [ ] **2. Ativar no cliente** — painel dos pais → Configurações → Licença → colar a chave → Ativar. Confirmar `status: active`, premium destravado (Localização/Zonas/Relatórios sem `PremiumLock`).
      *Prova o teste cruzado real: a chave cunhada pelo servidor é aceita pela pubkey embarcada.*
- [ ] **3. Confirmar o endpoint** — `curl <servidor>/wp-json/gkl/v1/revoked` → `{"revoked":[], "generated_at":...}` (ainda vazio).
- [ ] **4. Revogar** — `wp gkl revoke --jti=<jti da etapa 1>`. Confirmar que o `curl` do /revoked agora lista o jti.
- [ ] **5. Forçar o poll no cliente** — `wp cron event run gk_refresh_revocations` (ou esperar o daily). Recarregar o painel → **premium caiu**, `status: revoked`, `PremiumLock` de volta.
      *Prova o loop inteiro: revogar no servidor derruba o premium no cliente.*
- [ ] **6. Provar a falha aberta** — apontar `GK_LICENSE_SERVER_BASE` pra uma URL morta, rodar o cron, confirmar que o premium **não** cai (cache anterior preservado).
- [ ] **7. Limpeza** — deletar a licença de teste do CPT; `wp option delete guardkids_license` no cliente; limpar o transient `gk_revoked_jti`.

---

## Self-Review (feita)

- **Cobertura da spec:** Signer (A1), CPT+persistência (A2), emissão+email (A3), mint/revoke CLI (A4), `/revoked` (A5), admin one-click (A6), segurança da privkey (A7); cliente: RevocationCache+falha aberta (B1), Gate consumindo (B2), cron (B3); smoke E2E cobre os critérios de sucesso da spec (teste cruzado real, loop de revogação, falha aberta). Fatias 2/3 (self-service, checkout) permanecem fora, como na spec.
- **Consistência de tipos:** `LicenseIssuer::issue()` devolve `{license_id,license_key,jti,sub,exp}` — consumido igual no CLI (A4) e no teste (A3). `LicenseRepository` expõe `persist/findByJti/revokedJtis/revoke`, usados consistentemente. `RevocationCache` expõe `isRevoked/list/applyResponse/refresh`, usados no Gate (B2) e testes (B1). CPT status `gkl_active`/`gkl_revoked` idênticos em CPT, Repository e Admin.
- **Sem placeholders:** todo passo de código traz o código; fork+rebrand traz comandos `sed` concretos; passos sem unit (A4/A6, WP-CLI/admin) são exercitados no smoke E2E e apoiados na lógica já testada (Issuer/Repository).
