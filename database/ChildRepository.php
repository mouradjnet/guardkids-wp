<?php

declare(strict_types=1);

namespace GuardKids\Database;

final class ChildRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'children';
    }

    /**
     * O slug é UNIQUE global; usado pra gerar slugs únicos no cadastro.
     *
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $sql = $this->db->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE slug = %s LIMIT 1',
            $slug,
        );
        $row = $this->db->get_row($sql, ARRAY_A);
        return is_array($row) ? $row : null;
    }
}
