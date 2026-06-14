<?php

declare(strict_types=1);

namespace GuardKids\Database;

/**
 * Roda migrations idempotentes do GuardKids comparando wp_options('guardkids_db_version').
 *
 * Cada migration é um arquivo `database/migrations/NNN_*.php` que devolve um
 * `callable(\wpdb $wpdb, string $charsetCollate): void`. Migrations com
 * `version <= current` são puladas.
 *
 * Se uma migration deixar `$wpdb->last_error` populado (ALTER TABLE falhou,
 * dbDelta encontrou syntax error, etc), o loop aborta — `db_version` sobe só
 * pras versões que rodaram limpo. Isso evita o cenário que pegou a migration
 * 003 em prod (2026-06-12): o option subia pra 6 mesmo com ALTER silent fail,
 * mascarando colunas faltantes até o usuário tentar usar a feature.
 */
final class MigrationRunner
{
    private const OPTION = 'guardkids_db_version';

    public function __construct(private readonly string $migrationsDir)
    {
    }

    public function run(): void
    {
        global $wpdb;

        $current  = (int) get_option(self::OPTION, 0);
        $applied  = $current;
        $migrations = $this->discoverMigrations();

        if ($migrations === []) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charsetCollate = $wpdb->get_charset_collate();

        foreach ($migrations as $version => $file) {
            if ($version <= $current) {
                continue;
            }

            $factory = require $file;
            if (! is_callable($factory)) {
                continue;
            }

            $wpdb->last_error = '';
            $factory($wpdb, $charsetCollate);

            if ($wpdb->last_error !== '') {
                error_log(sprintf(
                    '[GuardKids] migration %d falhou (db_version segue em %d): %s',
                    $version,
                    $applied,
                    $wpdb->last_error,
                ));
                break;
            }

            $applied = $version;
        }

        if ($applied !== $current) {
            update_option(self::OPTION, $applied, false);
        }
    }

    /**
     * Lista os arquivos de migration em ordem crescente.
     *
     * @return array<int, string>
     */
    private function discoverMigrations(): array
    {
        $found = [];
        $pattern = trailingslashit($this->migrationsDir) . '*.php';
        foreach (glob($pattern) ?: [] as $path) {
            $basename = basename($path);
            if (! preg_match('/^(\d{3})_/', $basename, $matches)) {
                continue;
            }
            $found[(int) $matches[1]] = $path;
        }
        ksort($found);
        return $found;
    }
}
