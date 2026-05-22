<?php

declare(strict_types=1);

namespace GuardKids;

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

        add_action('init', [$this, 'loadTextdomain']);
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
     * Executado na ativação do plugin.
     *
     * Corpo preenchido na Fase B (Passo 6): executar o MigrationRunner,
     * garantir o segredo JWT e agendar o cron de limpeza.
     */
    public function onActivate(): void
    {
    }

    /**
     * Executado na desativação do plugin.
     *
     * Corpo preenchido na Fase B (Passo 6): limpar os agendamentos de cron.
     */
    public function onDeactivate(): void
    {
    }
}
