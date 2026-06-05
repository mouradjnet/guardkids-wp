<?php

declare(strict_types=1);

namespace GuardKids\Database;

/**
 * Roda migrations idempotentes do GuardKids comparando wp_options('guardkids_db_version').
 *
 * Cada migration é um arquivo `database/migrations/NNN_*.php` que devolve um
 * `callable(\wpdb $wpdb, string $charsetCollate): void`. Migrations com
 * `version <= current` são puladas.
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

            $factory($wpdb, $charsetCollate);
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
