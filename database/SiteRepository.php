<?php

declare(strict_types=1);

namespace GuardKids\Database;

final class SiteRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'sites';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByList(string $listType): array
    {
        return $this->findWhere(['list_type' => $listType], 'domain', 'ASC');
    }
}
