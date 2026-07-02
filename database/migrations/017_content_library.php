<?php

declare(strict_types=1);

/**
 * Migration 017 — Biblioteca Inteligente. Estende content_items (metadados),
 * content_recommendations (sort_order), content_history (duration) e seeda as
 * 12 categorias. ADD COLUMN não é idempotente → guard addColumnIfMissing.
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $p = $wpdb->prefix . 'guardkids_';

    $addColumnIfMissing = static function (string $table, string $col, string $def) use ($wpdb): void {
        $found = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $col));
        if ($found === null) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
        }
    };

    $addColumnIfMissing($p . 'content_items', 'age_min', 'TINYINT UNSIGNED NOT NULL DEFAULT 0');
    $addColumnIfMissing($p . 'content_items', 'age_max', 'TINYINT UNSIGNED NOT NULL DEFAULT 99');
    $addColumnIfMissing($p . 'content_items', 'estimated_minutes', 'SMALLINT UNSIGNED NULL');
    $addColumnIfMissing($p . 'content_items', 'level', 'VARCHAR(20) NULL');
    $addColumnIfMissing($p . 'content_items', 'tags', 'VARCHAR(255) NULL');
    $addColumnIfMissing($p . 'content_recommendations', 'sort_order', 'INT NOT NULL DEFAULT 0');
    $addColumnIfMissing($p . 'content_history', 'duration_seconds', 'INT NOT NULL DEFAULT 0');

    $now = current_time('mysql', true);
    $cats = [
        ['games', 'Jogos', 'sports_esports', 1],
        ['learn', 'Aprender', 'school', 2],
        ['create', 'Criar', 'palette', 3],
        ['science', 'Ciências', 'science', 4],
        ['portuguese', 'Português', 'menu_book', 5],
        ['math', 'Matemática', 'calculate', 6],
        ['english', 'Inglês', 'translate', 7],
        ['videos', 'Vídeos', 'smart_display', 8],
        ['reading', 'Leitura', 'auto_stories', 9],
        ['school', 'Escola', 'backpack', 10],
        ['coding', 'Programação', 'code', 11],
        ['creativity', 'Criatividade', 'brush', 12],
    ];
    foreach ($cats as [$slug, $name, $icon, $order]) {
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$p}content_categories (slug, name, icon, sort_order, created_at)
             VALUES (%s, %s, %s, %d, %s)
             ON DUPLICATE KEY UPDATE name = VALUES(name), icon = VALUES(icon), sort_order = VALUES(sort_order)",
            $slug,
            $name,
            $icon,
            $order,
            $now,
        ));
    }
};
