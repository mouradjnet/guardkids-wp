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

    /** Adiciona um domínio ao whitelist (idempotente). */
    public function allowDomain(string $domain): void
    {
        $domain = trim($domain);
        if ($domain === '') {
            return;
        }
        if ($this->findWhere(['domain' => $domain, 'list_type' => 'whitelist']) !== []) {
            return;
        }
        $this->insert(['domain' => $domain, 'list_type' => 'whitelist']);
    }
}
