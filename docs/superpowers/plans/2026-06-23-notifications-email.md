# Notificações por Email (resumo diário + relatório semanal) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enviar por cron um resumo diário (22h) e um relatório semanal (seg 8h) por email a todos os guardiões ativos, ligáveis por toggle (opt-in) na seção Notificações.

**Architecture:** `DigestData` agrega os números (queries `$wpdb` em janelas UTC via `gmdate`); `DigestMailer` checa o toggle, renderiza HTML branded e envia via `wp_mail` a cada guardião ativo; `Plugin.php` agenda 2 hooks de cron espelhando o padrão do purge. Frontend só habilita 2 toggles existentes.

**Tech Stack:** PHP 8.2 + `$wpdb` + `wp_mail` + WP-Cron; PHPUnit (fake wpdb + captura de wp_mail); React + Vitest.

**Spec:** `docs/superpowers/specs/2026-06-23-notifications-email-design.md`

**Comandos de teste:**
- PHPUnit: `PHP="/c/Users/mysho/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win32/php.exe"; EXT="C:/Users/mysho/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win32/ext"; "$PHP" -d extension_dir="$EXT" -d extension=mbstring -d extension=openssl -d extension=sodium vendor/bin/phpunit --testsuite unit --filter <Nome>`
- Vitest: `cd public/app-parent && npx vitest run <arquivo>`

---

## File Structure

**Backend (criar):**
- `includes/Notifications/DigestData.php` — `buildDaily()`/`buildWeekly()`.
- `includes/Notifications/DigestMailer.php` — `sendDaily()`/`sendWeekly()` + render HTML.

**Backend (modificar):**
- `database/GuardianRepository.php` — `findActive()`.
- `includes/Plugin.php` — 2 hooks de cron + agendamento + callbacks + cleanup.
- `tests/bootstrap.php` — stub capturador de `wp_mail`.

**Backend (testes):**
- `tests/Unit/Database/GuardianRepositoryFindActiveTest.php` (criar).
- `tests/Unit/Notifications/DigestDataTest.php` (criar).
- `tests/Unit/Notifications/DigestMailerTest.php` (criar).

**Frontend (modificar):**
- `public/app-parent/src/pages/Settings.tsx` — habilita 2 toggles, remove `comingSoon`.
- `public/app-parent/src/pages/Settings.test.tsx` — testes dos toggles + ajuste do badge.

---

## Task 1: GuardianRepository::findActive

**Files:**
- Modify: `database/GuardianRepository.php`
- Test: `tests/Unit/Database/GuardianRepositoryFindActiveTest.php`

- [ ] **Step 1: Write the failing test**

Crie `tests/Unit/Database/GuardianRepositoryFindActiveTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\GuardianRepository;
use PHPUnit\Framework\TestCase;

final class GuardianRepositoryFindActiveTest extends TestCase
{
    public function testFindActiveFiltersByActiveStatus(): void
    {
        $wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            /** @var array<int, string> */
            public array $queries = [];

            public function __construct()
            {
            }

            public function prepare($query, ...$args)
            {
                $this->queries[] = (string) $query;
                return (string) $query;
            }

            public function get_results($query = null, $output = ARRAY_A)
            {
                return [['id' => 1, 'email' => 'a@b.com', 'status' => 'active']];
            }
        };
        $GLOBALS['wpdb'] = $wpdb;

        $rows = (new GuardianRepository())->findActive();

        self::assertCount(1, $rows);
        self::assertSame('a@b.com', $rows[0]['email']);
        self::assertStringContainsString('status', $wpdb->queries[0]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `... --filter GuardianRepositoryFindActiveTest`
Expected: FAIL — `Call to undefined method ... findActive()`.

- [ ] **Step 3: Write minimal implementation**

Em `database/GuardianRepository.php`, adicione antes do último `}`:

```php
    /**
     * Todos os guardiões com status active (para envio de notificações).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findActive(): array
    {
        return $this->findWhere(['status' => 'active']);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `... --filter GuardianRepositoryFindActiveTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/GuardianRepository.php tests/Unit/Database/GuardianRepositoryFindActiveTest.php
git commit -m "feat(notifications): GuardianRepository.findActive"
```

---

## Task 2: DigestData

**Files:**
- Create: `includes/Notifications/DigestData.php`
- Test: `tests/Unit/Notifications/DigestDataTest.php`

- [ ] **Step 1: Write the failing test**

Crie `tests/Unit/Notifications/DigestDataTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Notifications;

use GuardKids\Notifications\DigestData;
use PHPUnit\Framework\TestCase;

final class DigestDataTest extends TestCase
{
    private function wpdbReturning(callable $results, callable $vars): \wpdb
    {
        return new class ($results, $vars) extends \wpdb {
            public string $prefix = 'wp_';

            /** @param callable $results @param callable $vars */
            public function __construct(private $results, private $vars)
            {
            }

            public function prepare($query, ...$args)
            {
                return (string) $query;
            }

            public function get_results($query = null, $output = ARRAY_A)
            {
                return ($this->results)((string) $query);
            }

            public function get_var($query = null, $x = 0, $y = 0)
            {
                return ($this->vars)((string) $query);
            }
        };
    }

    public function testBuildDailyShapesChildrenPendingAndBlocks(): void
    {
        $wpdb = $this->wpdbReturning(
            static fn (string $q) => str_contains($q, 'guardkids_children')
                ? [['name' => 'Lucas', 'used_minutes' => 30, 'limit_minutes' => 60]]
                : [],
            static fn (string $q) => str_contains($q, 'schedule_block') ? 2 : 5,
        );
        $GLOBALS['wpdb'] = $wpdb;

        $out = (new DigestData($wpdb))->buildDaily();

        self::assertSame('Lucas', $out['children'][0]['name']);
        self::assertSame(30, $out['children'][0]['usedMinutes']);
        self::assertSame(60, $out['children'][0]['limitMinutes']);
        self::assertSame(5, $out['pendingRequests']);
        self::assertSame(2, $out['blocksToday']);
    }

    public function testBuildWeeklyShapesMinutesBlocksAndDecisions(): void
    {
        $wpdb = $this->wpdbReturning(
            static fn (string $q) => [['name' => 'Lucas', 'secs' => 7200]],
            static function (string $q): int {
                if (str_contains($q, 'schedule_block')) {
                    return 4;
                }
                if (str_contains($q, "status = 'approved'")) {
                    return 3;
                }
                if (str_contains($q, "status = 'denied'")) {
                    return 1;
                }
                return 0;
            },
        );
        $GLOBALS['wpdb'] = $wpdb;

        $out = (new DigestData($wpdb))->buildWeekly();

        self::assertSame('Lucas', $out['children'][0]['name']);
        self::assertSame(120, $out['children'][0]['weekMinutes']);
        self::assertSame(4, $out['blocksWeek']);
        self::assertSame(3, $out['requestsApproved']);
        self::assertSame(1, $out['requestsDenied']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `... --filter DigestDataTest`
Expected: FAIL — classe `DigestData` não encontrada.

- [ ] **Step 3: Write minimal implementation**

Crie `includes/Notifications/DigestData.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Notifications;

/**
 * Agrega os números dos digests de notificação. Janelas em UTC via gmdate
 * (created_at é gravado em UTC), no estilo do Purger/UsageEventRepository.
 */
final class DigestData
{
    private readonly \wpdb $db;

    public function __construct(?\wpdb $db = null)
    {
        if ($db === null) {
            global $wpdb;
            $db = $wpdb;
        }
        $this->db = $db;
    }

    /**
     * @return array{children: array<int, array{name: string, usedMinutes: int, limitMinutes: int}>, pendingRequests: int, blocksToday: int}
     */
    public function buildDaily(): array
    {
        $p = $this->db->prefix;
        $children = $this->db->get_results(
            'SELECT name, used_minutes, limit_minutes FROM ' . $p . 'guardkids_children ORDER BY name ASC',
            ARRAY_A,
        );
        $children = is_array($children) ? $children : [];

        $pending = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$p}guardkids_requests WHERE status = 'pending'",
        );

        $todayStart = gmdate('Y-m-d 00:00:00');
        $blocks = (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$p}guardkids_usage_events WHERE type = 'schedule_block' AND created_at >= %s",
            $todayStart,
        ));

        return [
            'children' => array_map(static fn (array $c): array => [
                'name'         => (string) $c['name'],
                'usedMinutes'  => (int) $c['used_minutes'],
                'limitMinutes' => (int) $c['limit_minutes'],
            ], $children),
            'pendingRequests' => $pending,
            'blocksToday'     => $blocks,
        ];
    }

    /**
     * @return array{children: array<int, array{name: string, weekMinutes: int}>, blocksWeek: int, requestsApproved: int, requestsDenied: int}
     */
    public function buildWeekly(): array
    {
        $p = $this->db->prefix;
        $weekStart = gmdate('Y-m-d H:i:s', time() - 7 * 86400);

        $rows = $this->db->get_results($this->db->prepare(
            "SELECT c.name AS name, COALESCE(SUM(e.duration_seconds), 0) AS secs"
            . " FROM {$p}guardkids_children c"
            . " LEFT JOIN {$p}guardkids_usage_events e"
            . "   ON e.child_id = c.id AND e.created_at >= %s"
            . " GROUP BY c.id, c.name ORDER BY c.name ASC",
            $weekStart,
        ), ARRAY_A);
        $rows = is_array($rows) ? $rows : [];

        $blocks = (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$p}guardkids_usage_events WHERE type = 'schedule_block' AND created_at >= %s",
            $weekStart,
        ));
        $approved = (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$p}guardkids_requests WHERE status = 'approved' AND decided_at >= %s",
            $weekStart,
        ));
        $denied = (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$p}guardkids_requests WHERE status = 'denied' AND decided_at >= %s",
            $weekStart,
        ));

        return [
            'children' => array_map(static fn (array $r): array => [
                'name'        => (string) $r['name'],
                'weekMinutes' => (int) floor(((int) $r['secs']) / 60),
            ], $rows),
            'blocksWeek'       => $blocks,
            'requestsApproved' => $approved,
            'requestsDenied'   => $denied,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `... --filter DigestDataTest`
Expected: PASS (2 testes).

- [ ] **Step 5: Commit**

```bash
git add includes/Notifications/DigestData.php tests/Unit/Notifications/DigestDataTest.php
git commit -m "feat(notifications): DigestData agrega resumo diário e semanal"
```

---

## Task 3: Stub de wp_mail no bootstrap de Unit

**Files:**
- Modify: `tests/bootstrap.php`

- [ ] **Step 1: Adicionar o stub capturador**

Em `tests/bootstrap.php`, logo antes do bloco `// home_url` (que você adicionou na frente Privacidade) — ou junto aos outros stubs de função:

```php
// wp_mail — captura envios pros testes de notificação
if (! function_exists('wp_mail')) {
    function wp_mail(...$args): bool
    {
        $GLOBALS['gk_wp_mail_log'][] = $args;
        return true;
    }
}

// esc_html — usado pelo DigestMailer ao renderizar o HTML
if (! function_exists('esc_html')) {
    function esc_html($text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES);
    }
}
```

- [ ] **Step 2: Verificar que a suite ainda roda**

Run: `... --testsuite unit --filter DigestDataTest`
Expected: PASS (nada quebrou; o stub só é definido se ainda não existir).

- [ ] **Step 3: Commit**

```bash
git add tests/bootstrap.php
git commit -m "test(notifications): stub capturador de wp_mail no bootstrap unit"
```

---

## Task 4: DigestMailer

**Files:**
- Create: `includes/Notifications/DigestMailer.php`
- Test: `tests/Unit/Notifications/DigestMailerTest.php`

- [ ] **Step 1: Write the failing test**

Crie `tests/Unit/Notifications/DigestMailerTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Notifications;

use GuardKids\Database\GuardianRepository;
use GuardKids\Database\SettingsRepository;
use GuardKids\Notifications\DigestData;
use GuardKids\Notifications\DigestMailer;
use PHPUnit\Framework\TestCase;

final class DigestMailerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['gk_wp_mail_log'] = [];
    }

    /**
     * @param mixed $toggleValue valor cru do get_var de settings (string JSON ou null)
     * @param array<int, array<string, mixed>> $guardians
     */
    private function installWpdb($toggleValue, array $guardians): void
    {
        $GLOBALS['wpdb'] = new class ($toggleValue, $guardians) extends \wpdb {
            public string $prefix = 'wp_';

            public function __construct(private $toggleValue, private array $guardians)
            {
            }

            public function prepare($query, ...$args)
            {
                return (string) $query;
            }

            public function get_results($query = null, $output = ARRAY_A)
            {
                if (str_contains((string) $query, 'guardkids_guardians')) {
                    return $this->guardians;
                }
                if (str_contains((string) $query, 'guardkids_children')) {
                    return [['name' => 'Lucas', 'used_minutes' => 10, 'limit_minutes' => 60, 'secs' => 0, 'id' => 1]];
                }
                return [];
            }

            public function get_var($query = null, $x = 0, $y = 0)
            {
                if (str_contains((string) $query, 'guardkids_settings')) {
                    return $this->toggleValue;
                }
                return 0;
            }
        };
    }

    public function testSendDailySkipsWhenToggleOff(): void
    {
        $this->installWpdb(null, [['email' => 'a@b.com', 'status' => 'active']]);

        $sent = (new DigestMailer(new DigestData(), new GuardianRepository(), new SettingsRepository()))->sendDaily();

        self::assertSame(0, $sent);
        self::assertSame([], $GLOBALS['gk_wp_mail_log']);
    }

    public function testSendDailyMailsActiveGuardiansWhenOn(): void
    {
        $this->installWpdb('true', [
            ['email' => 'a@b.com', 'status' => 'active'],
            ['email' => 'c@d.com', 'status' => 'active'],
        ]);

        $sent = (new DigestMailer(new DigestData(), new GuardianRepository(), new SettingsRepository()))->sendDaily();

        self::assertSame(2, $sent);
        self::assertCount(2, $GLOBALS['gk_wp_mail_log']);
        // args: [to, subject, html, headers]
        self::assertSame('a@b.com', $GLOBALS['gk_wp_mail_log'][0][0]);
        self::assertStringContainsString('text/html', $GLOBALS['gk_wp_mail_log'][0][3][0]);
        self::assertNotSame('', $GLOBALS['gk_wp_mail_log'][0][2]);
    }

    public function testSendWeeklySkipsWhenToggleOff(): void
    {
        $this->installWpdb(null, [['email' => 'a@b.com', 'status' => 'active']]);

        $sent = (new DigestMailer(new DigestData(), new GuardianRepository(), new SettingsRepository()))->sendWeekly();

        self::assertSame(0, $sent);
        self::assertSame([], $GLOBALS['gk_wp_mail_log']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `... --filter DigestMailerTest`
Expected: FAIL — classe `DigestMailer` não encontrada.

- [ ] **Step 3: Write minimal implementation**

Crie `includes/Notifications/DigestMailer.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Notifications;

use GuardKids\Database\GuardianRepository;
use GuardKids\Database\SettingsRepository;

/**
 * Envia os digests por email aos guardiões ativos. Gated pelos toggles
 * `notifications.email` (diário) e `notifications.weekly_report` (semanal).
 */
final class DigestMailer
{
    private const BRAND = '#1E3A8A';

    private readonly DigestData $data;
    private readonly GuardianRepository $guardians;
    private readonly SettingsRepository $settings;

    public function __construct(
        ?DigestData $data = null,
        ?GuardianRepository $guardians = null,
        ?SettingsRepository $settings = null,
    ) {
        $this->data      = $data ?? new DigestData();
        $this->guardians = $guardians ?? new GuardianRepository();
        $this->settings  = $settings ?? new SettingsRepository();
    }

    public function sendDaily(): int
    {
        if (! (bool) $this->settings->get('notifications.email', false)) {
            return 0;
        }
        return $this->dispatch('GuardKids — Resumo de hoje', $this->renderDailyHtml($this->data->buildDaily()));
    }

    public function sendWeekly(): int
    {
        if (! (bool) $this->settings->get('notifications.weekly_report', false)) {
            return 0;
        }
        return $this->dispatch('GuardKids — Relatório da semana', $this->renderWeeklyHtml($this->data->buildWeekly()));
    }

    private function dispatch(string $subject, string $html): int
    {
        $sent = 0;
        foreach ($this->guardians->findActive() as $g) {
            $email = (string) ($g['email'] ?? '');
            if ($email === '') {
                continue;
            }
            if (\wp_mail($email, $subject, $html, ['Content-Type: text/html; charset=UTF-8'])) {
                $sent++;
            }
        }
        return $sent;
    }

    /**
     * @param array{children: array<int, array{name: string, usedMinutes: int, limitMinutes: int}>, pendingRequests: int, blocksToday: int} $d
     */
    private function renderDailyHtml(array $d): string
    {
        $rows = '';
        foreach ($d['children'] as $c) {
            $rows .= '<li>' . \esc_html($c['name']) . ': '
                . (int) $c['usedMinutes'] . ' / ' . (int) $c['limitMinutes'] . ' min</li>';
        }
        return $this->wrap('Resumo de hoje', '<p>Pedidos pendentes: <b>' . (int) $d['pendingRequests']
            . '</b><br>Bloqueios hoje: <b>' . (int) $d['blocksToday'] . '</b></p>'
            . '<h3>Tempo de tela hoje</h3><ul>' . $rows . '</ul>');
    }

    /**
     * @param array{children: array<int, array{name: string, weekMinutes: int}>, blocksWeek: int, requestsApproved: int, requestsDenied: int} $d
     */
    private function renderWeeklyHtml(array $d): string
    {
        $rows = '';
        foreach ($d['children'] as $c) {
            $rows .= '<li>' . \esc_html($c['name']) . ': ' . (int) $c['weekMinutes'] . ' min</li>';
        }
        return $this->wrap('Relatório da semana', '<p>Bloqueios na semana: <b>' . (int) $d['blocksWeek']
            . '</b><br>Pedidos aprovados: <b>' . (int) $d['requestsApproved']
            . '</b> / negados: <b>' . (int) $d['requestsDenied'] . '</b></p>'
            . '<h3>Tempo de tela na semana</h3><ul>' . $rows . '</ul>');
    }

    private function wrap(string $title, string $body): string
    {
        return '<div style="font-family:sans-serif;max-width:560px;margin:0 auto">'
            . '<div style="background:' . self::BRAND . ';color:#fff;padding:16px 20px;border-radius:12px 12px 0 0">'
            . '<strong>GuardKids</strong> — ' . \esc_html($title) . '</div>'
            . '<div style="border:1px solid #e5e7eb;border-top:0;padding:20px;border-radius:0 0 12px 12px">'
            . $body . '</div></div>';
    }
}
```

Nota: o stub de `esc_html` foi adicionado ao `tests/bootstrap.php` na Task 3 (junto ao `wp_mail`), então este render funciona nos testes Unit.

- [ ] **Step 4: Run test to verify it passes**

Run: `... --filter DigestMailerTest`
Expected: PASS (3 testes).

- [ ] **Step 5: Commit**

```bash
git add includes/Notifications/DigestMailer.php tests/Unit/Notifications/DigestMailerTest.php
git commit -m "feat(notifications): DigestMailer envia digests HTML aos guardiões"
```

---

## Task 5: Cron no Plugin.php

**Files:**
- Modify: `includes/Plugin.php`

- [ ] **Step 1: Importar o DigestMailer**

No bloco de `use` (junto aos outros, ex.: perto de `use GuardKids\Maintenance\Purger;`):

```php
use GuardKids\Notifications\DigestMailer;
```

- [ ] **Step 2: Adicionar as constantes dos hooks**

Logo após `public const PURGE_HOOK = 'guardkids_daily_purge';`:

```php
    public const DAILY_DIGEST_HOOK  = 'guardkids_daily_digest';
    public const WEEKLY_DIGEST_HOOK = 'guardkids_weekly_digest';
```

- [ ] **Step 3: Registrar os action hooks no boot()**

Logo após `add_action(self::PURGE_HOOK, [$this, 'runPurger']);`:

```php
        add_action(self::DAILY_DIGEST_HOOK, [$this, 'runDailyDigest']);
        add_action(self::WEEKLY_DIGEST_HOOK, [$this, 'runWeeklyDigest']);
```

- [ ] **Step 4: Agendar no maybeScheduleCron()**

Substitua o corpo de `maybeScheduleCron()` por:

```php
    public function maybeScheduleCron(): void
    {
        if (! function_exists('wp_next_scheduled') || ! function_exists('wp_schedule_event')) {
            return;
        }
        if (wp_next_scheduled(self::PURGE_HOOK) === false) {
            wp_schedule_event(time() + 3600, 'daily', self::PURGE_HOOK);
        }
        if (wp_next_scheduled(self::DAILY_DIGEST_HOOK) === false) {
            wp_schedule_event($this->nextDailyAt(22), 'daily', self::DAILY_DIGEST_HOOK);
        }
        if (wp_next_scheduled(self::WEEKLY_DIGEST_HOOK) === false) {
            wp_schedule_event($this->nextWeeklyAt(1, 8), 'weekly', self::WEEKLY_DIGEST_HOOK);
        }
    }

    /** Próximo timestamp para a hora `$hour` no fuso do site. */
    private function nextDailyAt(int $hour): int
    {
        $tz     = wp_timezone();
        $now    = new \DateTimeImmutable('now', $tz);
        $target = $now->setTime($hour, 0, 0);
        if ($target <= $now) {
            $target = $target->modify('+1 day');
        }
        return $target->getTimestamp();
    }

    /** Próximo timestamp para o dia da semana `$weekday` (1=seg) à hora `$hour`. */
    private function nextWeeklyAt(int $weekday, int $hour): int
    {
        $tz     = wp_timezone();
        $now    = new \DateTimeImmutable('now', $tz);
        $target = $now->setTime($hour, 0, 0);
        $diff   = ($weekday - (int) $target->format('N') + 7) % 7;
        $target = $target->modify('+' . $diff . ' day');
        if ($target <= $now) {
            $target = $target->modify('+7 day');
        }
        return $target->getTimestamp();
    }
```

- [ ] **Step 5: Adicionar os callbacks dos digests**

Logo após o método `runPurger()`:

```php
    public function runDailyDigest(): void
    {
        (new DigestMailer())->sendDaily();
    }

    public function runWeeklyDigest(): void
    {
        (new DigestMailer())->sendWeekly();
    }
```

- [ ] **Step 6: Limpar os hooks no onDeactivate()**

Substitua o corpo de `onDeactivate()` por:

```php
    public function onDeactivate(): void
    {
        wp_clear_scheduled_hook(self::PURGE_HOOK);
        wp_clear_scheduled_hook(self::DAILY_DIGEST_HOOK);
        wp_clear_scheduled_hook(self::WEEKLY_DIGEST_HOOK);
        flush_rewrite_rules();
    }
```

- [ ] **Step 7: Rodar a suite PHP completa (sem regressão)**

Run: `... --testsuite unit`
Expected: PASS — suite verde (inclui os novos testes das Tasks 1-4).

- [ ] **Step 8: Commit**

```bash
git add includes/Plugin.php
git commit -m "feat(notifications): agenda cron diário 22h e semanal seg 8h"
```

---

## Task 6: Frontend — habilitar toggles de email

**Files:**
- Modify: `public/app-parent/src/pages/Settings.tsx`
- Test: `public/app-parent/src/pages/Settings.test.tsx`

- [ ] **Step 1: Write the failing tests**

Em `Settings.test.tsx`, adicione antes do `}` final do `describe`:

```tsx
  it('Notificações: liga resumo diário por email', async () => {
    listSettingsMock.mockResolvedValue({});
    updateSettingsMock.mockResolvedValue({});
    const user = userEvent.setup();
    renderPage();
    await waitFor(() => expect(listSettingsMock).toHaveBeenCalled());

    await user.click(toggleFor('Resumo diário por email'));

    await waitFor(() =>
      expect(updateSettingsMock).toHaveBeenCalledWith({ 'notifications.email': true }),
    );
  });

  it('Notificações: liga relatório semanal', async () => {
    listSettingsMock.mockResolvedValue({});
    updateSettingsMock.mockResolvedValue({});
    const user = userEvent.setup();
    renderPage();
    await waitFor(() => expect(listSettingsMock).toHaveBeenCalled());

    await user.click(toggleFor('Relatório semanal'));

    await waitFor(() =>
      expect(updateSettingsMock).toHaveBeenCalledWith({ 'notifications.weekly_report': true }),
    );
  });

  it('Notificações: push segue desabilitado', async () => {
    listSettingsMock.mockResolvedValue({});
    renderPage();
    await waitFor(() => expect(listSettingsMock).toHaveBeenCalled());

    expect(toggleFor('Notificações push')).toBeDisabled();
  });
```

E ajuste o teste de badge existente (`renders ComingSoonBadge on Notificações/Segurança but not Privacidade/Família`) — Notificações deixa de ter o badge. Substitua o corpo por:

```tsx
  it('renders ComingSoonBadge on Segurança but not Notificações/Privacidade/Família', async () => {
    listSettingsMock.mockResolvedValue({});
    renderPage();

    const seguranca = await screen.findByRole('heading', { name: /^segurança/i, level: 3 });
    expect(seguranca).toHaveTextContent(/em breve/i);
    const notificacoes = screen.getByRole('heading', { name: /^notificações/i, level: 3 });
    expect(notificacoes).not.toHaveTextContent(/em breve/i);
    const privacidade = screen.getByRole('heading', { name: /^privacidade/i, level: 3 });
    expect(privacidade).not.toHaveTextContent(/em breve/i);
    const familia = screen.getByRole('heading', { name: /^família/i, level: 3 });
    expect(familia).not.toHaveTextContent(/em breve/i);
  });
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd public/app-parent && npx vitest run src/pages/Settings.test.tsx`
Expected: FAIL — os toggles email/weekly estão `locked` (disabled) e Notificações ainda tem "Em breve".

- [ ] **Step 3: Implement — habilitar toggles + remover comingSoon**

Em `Settings.tsx`, na `<Section title="Notificações" ... comingSoon>`, remova o `comingSoon`:

```tsx
      <Section
        icon="notifications"
        iconTone="primary"
        title="Notificações"
        subtitle="Como você quer ser avisado sobre o que acontece"
      >
```

No `SettingToggleRow` de `notifications.email`, remova a linha `locked` e troque `fallback={true}` por `fallback={false}`:

```tsx
        <SettingToggleRow
          settingsKey="notifications.email"
          title="Resumo diário por email"
          description="Email todo dia às 22h com o que aconteceu na família."
          fallback={false}
          loading={settingsQuery.isLoading}
          saving={mutation.isPending}
          get={get}
          set={set}
        />
```

No `SettingToggleRow` de `notifications.weekly_report`, idem (remove `locked`, `fallback={false}`):

```tsx
        <SettingToggleRow
          settingsKey="notifications.weekly_report"
          title="Relatório semanal"
          description="Toda segunda às 8h com gráficos da semana anterior."
          fallback={false}
          loading={settingsQuery.isLoading}
          saving={mutation.isPending}
          get={get}
          set={set}
        />
```

Os toggles `notifications.push` e `notifications.realtime` ficam **inalterados** (seguem com `locked`).

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd public/app-parent && npx vitest run src/pages/Settings.test.tsx`
Expected: PASS (testes antigos + 3 novos + badge ajustado).

- [ ] **Step 5: Typecheck**

Run: `cd public/app-parent && npx tsc --noEmit`
Expected: `0 erros`.

- [ ] **Step 6: Commit**

```bash
git add public/app-parent/src/pages/Settings.tsx public/app-parent/src/pages/Settings.test.tsx
git commit -m "feat(notifications): habilita toggles de email diário e semanal"
```

---

## Task 7: Verificação final + build

**Files:** nenhum novo.

- [ ] **Step 1: Suite PHP completa**

Run: `... --testsuite unit`
Expected: PASS — verde.

- [ ] **Step 2: Suite Vitest completa do app-parent**

Run: `cd public/app-parent && npx vitest run`
Expected: PASS — verde.

- [ ] **Step 3: Build de produção**

Run: `cd public/app-parent && npm run build`
Expected: build OK, sem erros tsc.

- [ ] **Step 4: Smoke manual (LocalWP)**

- Em `https://guardkids-wp.local/painel-pais/` → Configurações → Notificações: os toggles "Resumo diário por email" e "Relatório semanal" agora alternam e persistem após F5; push/tempo real seguem desabilitados; a seção não mostra mais "Em breve".
- Forçar um envio sem esperar o cron (no shell do site): `wp eval '(new GuardKids\Notifications\DigestMailer())->sendDaily();'` com o toggle ligado → checar a caixa de entrada do guardião (ou maillog). Com o toggle desligado → retorna 0, sem email.
- Conferir agendamento: `wp cron event list | grep guardkids_` deve listar `guardkids_daily_digest` e `guardkids_weekly_digest`.

- [ ] **Step 5: (no release) bump de versão**

Bump (`1.10.0`, feature minor) + tag + zip + deploy ficam para o release, fora deste plano. `DB_VERSION` **não muda** (sem migração).

---

## Self-Review (autor do plano)

- **Cobertura do spec:** escopo email-only (todas as tasks); destinatário guardiões ativos (T1 findActive + T4 dispatch loop); conteúdo essencial diário/semanal (T2); HTML branded + wp_mail header (T4 render/dispatch); opt-in default off (T6 `fallback={false}`); horários 22h/seg 8h (T5 nextDailyAt/nextWeeklyAt); cron espelhando purge + cleanup (T5); frontend habilita toggles + remove comingSoon + mantém push/realtime locked (T6); testes PHP+TS (T1-T4, T6). Sem gaps.
- **Placeholders:** nenhum — todo step com código mostra o código completo.
- **Consistência de tipos:** `DigestData::buildDaily/buildWeekly` retornam as chaves usadas por `DigestMailer::renderDailyHtml/renderWeeklyHtml` (`children[].name/usedMinutes/limitMinutes/weekMinutes`, `pendingRequests`, `blocksToday`, `blocksWeek`, `requestsApproved`, `requestsDenied`). `GuardianRepository::findActive()` usado em `dispatch()`. Hooks `DAILY_DIGEST_HOOK`/`WEEKLY_DIGEST_HOOK` consistentes entre boot/schedule/deactivate. Toggle keys `notifications.email`/`notifications.weekly_report` batem entre T4 (gating) e T6 (UI).
