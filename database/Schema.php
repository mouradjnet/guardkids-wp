<?php

declare(strict_types=1);

namespace GuardKids\Database;

/**
 * Fonte única das tabelas do plugin (sufixo depois de `{prefix}guardkids_`).
 *
 * Usada por uninstall, PrivacyEraser, PrivacyExporter e ChildDataEraser. Antes
 * cada um tinha a própria lista e elas divergiram: o "Excluir conta" deixava
 * dados de criança em ~16 tabelas. O SchemaTest compara TABLES com as
 * migrations — migration com tabela nova quebra o teste até entrar aqui.
 *
 * Sem dependências: o uninstall.php faz require direto, sem autoloader.
 */
final class Schema
{
    /** Todas as tabelas criadas pelas migrations. */
    public const TABLES = [
        'children', 'requests', 'sites', 'categories', 'settings',
        'usage_events', 'locations', 'safe_zones', 'guardians',
        'companion_devices', 'notifications', 'push_subscriptions',
        'content_categories', 'content_items', 'content_favorites',
        'content_recommendations', 'content_history',
        'progression', 'progression_awards', 'mission_completions',
        'medal_unlocks', 'rewards', 'reward_redemptions',
        'guardian_push_subscriptions', 'guardian_push_dedup',
        'child_place', 'academy_child_lessons',
    ];

    /** Tabelas com coluna `child_id` (dados de uma criança específica). */
    public const CHILD_TABLES = [
        'requests', 'usage_events', 'locations', 'companion_devices',
        'notifications', 'push_subscriptions', 'content_favorites',
        'content_recommendations', 'content_history', 'progression',
        'progression_awards', 'mission_completions', 'medal_unlocks',
        'reward_redemptions', 'child_place', 'academy_child_lessons',
    ];

    /**
     * Preservadas no "Excluir conta": acesso dos guardiões e o catálogo de
     * conteúdo semeado pela migration 017 (que não roda de novo).
     */
    public const KEPT_ON_ERASE = [
        'guardians', 'guardian_push_subscriptions',
        'content_categories', 'content_items',
    ];

    /** @return list<string> */
    public static function erasableTables(): array
    {
        return array_values(array_diff(self::TABLES, self::KEPT_ON_ERASE));
    }
}
