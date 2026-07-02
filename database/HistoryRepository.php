<?php

declare(strict_types=1);

namespace GuardKids\Database;

final class HistoryRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'content_history';
    }

    public function count(): int
    {
        return (int) $this->db->get_var('SELECT COUNT(*) FROM ' . $this->table());
    }

    public function add(int $childId, int $contentId, string $action): int
    {
        return $this->record($childId, $contentId, $action, 0);
    }

    public function record(int $childId, int $contentId, string $action, int $durationSeconds): int
    {
        $ok = $this->db->insert($this->table(), [
            'child_id'         => $childId,
            'content_id'       => $contentId,
            'action'           => $action,
            'duration_seconds' => $durationSeconds,
            'created_at'       => current_time('mysql', true),
        ]);
        return $ok === false ? 0 : (int) $this->db->insert_id;
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->findAll('id', 'DESC');
    }
}
