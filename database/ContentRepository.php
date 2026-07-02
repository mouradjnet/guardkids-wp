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

    /**
     * Busca com filtros opcionais: categoria, termo (title/tags LIKE), idade.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(?int $categoryId, ?string $term, ?int $childAge): array
    {
        $where = [];
        $params = [];
        if ($categoryId !== null && $categoryId > 0) {
            $where[] = 'category_id = %d';
            $params[] = $categoryId;
        }
        if ($term !== null && $term !== '') {
            $like = '%' . $this->db->esc_like($term) . '%';
            $where[] = '(title LIKE %s OR tags LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }
        if ($childAge !== null) {
            $where[] = 'age_min <= %d AND age_max >= %d';
            $params[] = $childAge;
            $params[] = $childAge;
        }
        $sql = 'SELECT * FROM ' . $this->table();
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC';
        if ($params !== []) {
            $sql = $this->db->prepare($sql, ...$params);
        }
        $rows = $this->db->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Override: content_items não tem updated_at (base seta e quebraria).
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        return $this->db->update($this->table(), $data, ['id' => $id]) !== false;
    }
}
