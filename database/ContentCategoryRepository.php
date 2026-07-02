<?php

declare(strict_types=1);

namespace GuardKids\Database;

final class ContentCategoryRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'content_categories';
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->findAll('sort_order', 'ASC');
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
