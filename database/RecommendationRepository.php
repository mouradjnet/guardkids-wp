<?php

declare(strict_types=1);

namespace GuardKids\Database;

final class RecommendationRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'content_recommendations';
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->findAll('id', 'DESC');
    }

    public function count(): int
    {
        return (int) $this->db->get_var('SELECT COUNT(*) FROM ' . $this->table());
    }

    public function add(int $childId, int $contentId, ?int $guardianId, ?string $note): int
    {
        $ok = $this->db->insert($this->table(), [
            'child_id'    => $childId,
            'content_id'  => $contentId,
            'guardian_id' => $guardianId,
            'note'        => $note,
            'created_at'  => current_time('mysql', true),
        ]);
        return $ok === false ? 0 : (int) $this->db->insert_id;
    }
}
