<?php

declare(strict_types=1);

namespace GuardKids\Database;

/**
 * Catálogo global de recompensas (editável pelos pais). CRUD reusa a base;
 * findActive alimenta a loja do filho.
 */
final class RewardRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'rewards';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findActive(): array
    {
        return $this->findWhere(['active' => 1], 'id', 'ASC');
    }
}
