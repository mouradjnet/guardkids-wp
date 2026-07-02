<?php

declare(strict_types=1);

namespace GuardKids\Database;

final class FavoriteRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'content_favorites';
    }

    /** @return array<int, array<string, mixed>> */
    public function findByChild(int $childId): array
    {
        return $this->findWhere(['child_id' => $childId], 'id', 'DESC');
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

    public function add(int $childId, int $contentId): int
    {
        $ok = $this->db->insert($this->table(), [
            'child_id'   => $childId,
            'content_id' => $contentId,
            'created_at' => current_time('mysql', true),
        ]);
        return $ok === false ? 0 : (int) $this->db->insert_id;
    }
}
