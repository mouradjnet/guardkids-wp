<?php

declare(strict_types=1);

namespace GuardKids\Database;

final class CategoryRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'categories';
    }

    /**
     * Seed inicial das categorias do briefing.
     * Roda apenas se a tabela estiver vazia.
     *
     * @param array<int, array<string, mixed>> $defaults
     */
    public function seed(array $defaults): void
    {
        $count = (int) $this->db->get_var('SELECT COUNT(*) FROM ' . $this->table());
        if ($count > 0) {
            return;
        }
        foreach ($defaults as $row) {
            $this->insert($row);
        }
    }
}
