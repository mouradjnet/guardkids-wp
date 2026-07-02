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
            'sort_order'  => $this->nextSortOrder($childId),
            'created_at'  => current_time('mysql', true),
        ]);
        return $ok === false ? 0 : (int) $this->db->insert_id;
    }

    public function nextSortOrder(int $childId): int
    {
        $max = $this->db->get_var($this->db->prepare(
            'SELECT MAX(sort_order) FROM ' . $this->table() . ' WHERE child_id = %d',
            $childId,
        ));
        return (int) $max + 1;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByChildOrdered(int $childId): array
    {
        $sql = $this->db->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE child_id = %d ORDER BY sort_order ASC, id ASC',
            $childId,
        );
        $rows = $this->db->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Override: sem updated_at.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        return $this->db->update($this->table(), $data, ['id' => $id]) !== false;
    }

    /** @param array<int> $ids na ordem desejada */
    public function reorder(array $ids): void
    {
        foreach (array_values($ids) as $pos => $id) {
            $this->db->update($this->table(), ['sort_order' => $pos], ['id' => (int) $id]);
        }
    }
}
