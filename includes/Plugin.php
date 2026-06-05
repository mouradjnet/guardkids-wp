<?php

declare(strict_types=1);

namespace GuardKids;

use GuardKids\Api\RestApi;
use GuardKids\Database\CategoryRepository;
use GuardKids\Database\MigrationRunner;
use GuardKids\Security\RestHeaders;
use GuardKids\Ui\ParentApp;

/**
 * Bootstrap central do plugin GuardKids WP.
 *
 * Responsável apenas por registrar os hooks de ciclo de vida do plugin.
 * As regras de negócio ficam nos serviços dedicados de `includes/`.
 */
final class Plugin
{
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
        add_action('init', [$this, 'loadTextdomain']);

        (new RestApi())->register();
        (new RestHeaders())->register();
        (new ParentApp())->register();
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
        flush_rewrite_rules();
    }

    /**
     * Executado na desativação do plugin (preserva dados; limpa rewrite).
     */
    public function onDeactivate(): void
    {
        flush_rewrite_rules();
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
