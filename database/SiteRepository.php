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
        $domain = self::normalizeDomain($domain);
        if ($domain === '') {
            return;
        }
        if ($this->findWhere(['domain' => $domain, 'list_type' => 'whitelist']) !== []) {
            return;
        }
        $this->insert(['domain' => $domain, 'list_type' => 'whitelist']);
    }

    /**
     * Reduz o que o usuário digita a um hostname limpo (sem protocolo, www ou
     * caminho). Mantém a whitelist consistente e casa com o bloqueio por host
     * do Companion Android. Espelha `normalizeHost` do app-child.
     */
    public static function normalizeDomain(string $domain): string
    {
        $d = strtolower(trim($domain));
        $d = (string) preg_replace('#^https?://#', '', $d);
        $d = (string) preg_replace('#^www\.#', '', $d);
        $d = (string) preg_replace('#/.*$#', '', $d);
        return trim($d);
    }
}
