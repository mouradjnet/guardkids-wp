<?php

declare(strict_types=1);

/**
 * Migration 022 — avatar (gamificação 3e). Adiciona equipped_avatar na
 * progression (a escolha do filho). ADD COLUMN não é idempotente → guard.
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $addColumnIfMissing = static function (string $table, string $col, string $def) use ($wpdb): void {
        $found = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $col));
        if ($found === null) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
        }
    };

    $addColumnIfMissing($wpdb->prefix . 'guardkids_progression', 'equipped_avatar', 'VARCHAR(40) NULL');
};
