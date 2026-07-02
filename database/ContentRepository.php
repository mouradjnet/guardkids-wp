<?php

declare(strict_types=1);

namespace GuardKids\Database;

final class ContentRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'content_items';
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->findAll('id', 'DESC');
    }

    /** @return array<int, array<string, mixed>> */
    public function findByCategory(int $categoryId): array
    {
        return $this->findWhere(['category_id' => $categoryId], 'id', 'DESC');
    }

    public function count(): int
    {
        return (int) $this->db->get_var('SELECT COUNT(*) FROM ' . $this->table());
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $ok = $this->db->insert($this->table(), $data + ['created_at' => current_time('mysql', true)]);
        return $ok === false ? 0 : (int) $this->db->insert_id;
    }
}
