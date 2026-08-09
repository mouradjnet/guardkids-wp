<?php

declare(strict_types=1);

/**
 * Migration 027 — Academy da criança (Onda 3).
 *
 * `academy_child_lessons` = ledger anti-duplo de conclusão de aula pela criança.
 * Uma linha por (filho, aula) via UNIQUE — garante que o XP/coins da aula é
 * creditado UMA vez só, por mais que o POST /child/academy/complete repita.
 * Sem completion_date: aula é concluída de vez (diferente da missão diária).
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $table = $wpdb->prefix . 'guardkids_academy_child_lessons';

    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS {$table} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id    BIGINT UNSIGNED NOT NULL,
            lesson_key  VARCHAR(60) NOT NULL,
            xp          INT NOT NULL DEFAULT 0,
            coins       INT NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY once_per_lesson (child_id, lesson_key),
            KEY child (child_id)
        ) {$charsetCollate};"
    );
};
