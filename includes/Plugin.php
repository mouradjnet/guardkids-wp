<?php

declare(strict_types=1);

namespace GuardKids;

use GuardKids\Api\RestApi;
use GuardKids\Database\CategoryRepository;
use GuardKids\Database\MigrationRunner;
use GuardKids\License\RevocationCache;
use GuardKids\Maintenance\Purger;
use GuardKids\Notifications\DigestMailer;
use GuardKids\Security\RestHeaders;
use GuardKids\Security\SecurityHeaders;
use GuardKids\Security\TwoFactorLogin;
use GuardKids\Ui\AcceptInviteApp;
use GuardKids\Ui\ChildApp;
use GuardKids\Ui\ParentApp;

/**
 * Bootstrap central do plugin GuardKids WP.
 *
 * Responsável apenas por registrar os hooks de ciclo de vida do plugin.
 * As regras de negócio ficam nos serviços dedicados de `includes/`.
 */
final class Plugin
{
    public const PURGE_HOOK = 'guardkids_daily_purge';
    public const DAILY_DIGEST_HOOK  = 'guardkids_daily_digest';
    public const WEEKLY_DIGEST_HOOK = 'guardkids_weekly_digest';
    public const REVOCATION_REFRESH_HOOK = 'guardkids_revocation_refresh';

    private static ?Plugin $instance = null;

    /**
     * Retorna a instância única do plugin.
     */
    public static function instance(): Plugin
    {
        if (! self::$instance instanceof Plugin) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
    }

    /**
     * Registra os hooks do WordPress. Idempotente.
     */
    public function boot(): void
    {
        register_activation_hook(GUARDKIDS_FILE, [$this, 'onActivate']);
        register_deactivation_hook(GUARDKIDS_FILE, [$this, 'onDeactivate']);

        add_action('plugins_loaded', [$this, 'maybeRunMigrations']);
        add_action('plugins_loaded', [$this, 'maybeScheduleCron']);
        add_action('init', [$this, 'loadTextdomain']);
        add_action(self::PURGE_HOOK, [$this, 'runPurger']);
        add_action(self::DAILY_DIGEST_HOOK, [$this, 'runDailyDigest']);
        add_action(self::WEEKLY_DIGEST_HOOK, [$this, 'runWeeklyDigest']);
        add_action(self::REVOCATION_REFRESH_HOOK, [$this, 'runRevocationRefresh']);

        (new RestApi())->register();
        (new RestHeaders())->register();
        (new SecurityHeaders())->register();
        (new TwoFactorLogin())->register();
        (new ParentApp())->register();
        (new ChildApp())->register();
        (new AcceptInviteApp())->register();
    }

    /**
     * Carrega as traduções do plugin (text domain "guardkids").
     */
    public function loadTextdomain(): void
    {
        load_plugin_textdomain(
            'guardkids',
            false,
            dirname(plugin_basename(GUARDKIDS_FILE)) . '/languages'
        );
    }

    /**
     * Roda migrations no boot caso o plugin tenha sido atualizado sem reativar.
     */
    public function maybeRunMigrations(): void
    {
        $current  = (int) get_option('guardkids_db_version', 0);
        $expected = (int) GUARDKIDS_DB_VERSION;
        if ($current >= $expected) {
            return;
        }
        (new MigrationRunner(GUARDKIDS_DIR . 'database/migrations'))->run();
    }

    /**
     * Executado na ativação do plugin: schema + seed inicial + flush das
     * rewrite rules pra `/painel-pais` valer.
     */
    public function onActivate(): void
    {
        (new MigrationRunner(GUARDKIDS_DIR . 'database/migrations'))->run();
        (new CategoryRepository())->seed($this->defaultCategories());
        (new ParentApp())->addRewriteRule();
        (new ChildApp())->addRewriteRule();
        (new AcceptInviteApp())->addRewriteRule();
        $this->maybeScheduleCron();
        flush_rewrite_rules();
    }

    /**
     * Executado na desativação do plugin (preserva dados; limpa rewrite + cron).
     */
    public function onDeactivate(): void
    {
        wp_clear_scheduled_hook(self::PURGE_HOOK);
        wp_clear_scheduled_hook(self::DAILY_DIGEST_HOOK);
        wp_clear_scheduled_hook(self::WEEKLY_DIGEST_HOOK);
        wp_clear_scheduled_hook(self::REVOCATION_REFRESH_HOOK);
        flush_rewrite_rules();
    }

    /**
     * Agenda o cron diário se ainda não tiver. Idempotente — chamado tanto
     * no activation hook quanto no boot (cobre o cenário "substituir plugin"
     * via WP Admin, que não dispara register_activation_hook —
     * ver [[feedback-wp-plugin-lifecycle-install-fallback]]).
     */
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
        if (wp_next_scheduled(self::REVOCATION_REFRESH_HOOK) === false) {
            wp_schedule_event(time() + 3600, 'daily', self::REVOCATION_REFRESH_HOOK);
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

    /**
     * Callback do hook `guardkids_daily_purge` — descarta usage_events > 90d
     * e locations > 30d. Isolado em método pra ser fácil mockar nos testes.
     */
    public function runPurger(): void
    {
        (new Purger())->run();
    }

    public function runDailyDigest(): void
    {
        (new DigestMailer())->sendDaily();
    }

    public function runWeeklyDigest(): void
    {
        (new DigestMailer())->sendWeekly();
    }

    /**
     * Cron diário: atualiza a lista de licenças revogadas a partir do license
     * server. Falha aberta — ver RevocationCache::refresh().
     */
    public function runRevocationRefresh(): void
    {
        (new RevocationCache())->refresh();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function defaultCategories(): array
    {
        return [
            [
                'slug'        => 'adult-content',
                'name'        => __('Conteúdo adulto', 'guardkids'),
                'description' => __('Bloqueia todo conteúdo +18, sempre.', 'guardkids'),
                'icon'        => 'no_adult_content',
                'blocked'     => 1,
            ],
            [
                'slug'        => 'gambling',
                'name'        => __('Apostas e cassino', 'guardkids'),
                'description' => __('Sites de jogos de azar e cassinos online.', 'guardkids'),
                'icon'        => 'casino',
                'blocked'     => 1,
            ],
            [
                'slug'        => 'extreme-violence',
                'name'        => __('Violência extrema', 'guardkids'),
                'description' => __('Conteúdo gráfico, gore e similares.', 'guardkids'),
                'icon'        => 'gpp_bad',
                'blocked'     => 1,
            ],
            [
                'slug'        => 'social-networks',
                'name'        => __('Redes sociais', 'guardkids'),
                'description' => __('TikTok, Instagram, Twitter, Snapchat.', 'guardkids'),
                'icon'        => 'group',
                'blocked'     => 1,
            ],
            [
                'slug'        => 'videos',
                'name'        => __('Vídeos (geral)', 'guardkids'),
                'description' => __('YouTube e plataformas similares sem filtro.', 'guardkids'),
                'icon'        => 'smart_display',
                'blocked'     => 0,
            ],
            [
                'slug'        => 'online-games',
                'name'        => __('Jogos online', 'guardkids'),
                'description' => __('Plataformas multiplayer com chat aberto.', 'guardkids'),
                'icon'        => 'sports_esports',
                'blocked'     => 0,
            ],
        ];
    }
}
