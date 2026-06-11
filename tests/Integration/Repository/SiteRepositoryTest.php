<?php

declare(strict_types=1);

namespace GuardKids\Tests\Integration\Repository;

use GuardKids\Database\SiteRepository;
use GuardKids\Tests\Integration\IntegrationTestCase;

/**
 * Valida SiteRepository contra MySQL real.
 *
 * Foco: findByList (filtro por whitelist/blocklist + ordenação por domain),
 * default de list_type, nullable category/applies_to.
 */
final class SiteRepositoryTest extends IntegrationTestCase
{
    public function test_default_list_type_is_whitelist_when_omitted(): void
    {
        $repo = new SiteRepository();
        $id   = $repo->insert(['domain' => 'youtube.com']);

        $row = $repo->findById($id);
        $this->assertSame('whitelist', $row['list_type']);
    }

    public function test_findByList_filters_whitelist_vs_blocklist(): void
    {
        $repo = new SiteRepository();
        $repo->insert(['domain' => 'youtube.com', 'list_type' => 'whitelist']);
        $repo->insert(['domain' => 'roblox.com', 'list_type' => 'whitelist']);
        $repo->insert(['domain' => 'tiktok.com', 'list_type' => 'blocklist']);

        $this->assertCount(2, $repo->findByList('whitelist'));
        $this->assertCount(1, $repo->findByList('blocklist'));
    }

    public function test_findByList_orders_by_domain_asc(): void
    {
        $repo = new SiteRepository();
        $repo->insert(['domain' => 'zebra.com', 'list_type' => 'whitelist']);
        $repo->insert(['domain' => 'apple.com', 'list_type' => 'whitelist']);
        $repo->insert(['domain' => 'mango.com', 'list_type' => 'whitelist']);

        $rows = $repo->findByList('whitelist');
        $this->assertSame(['apple.com', 'mango.com', 'zebra.com'], array_column($rows, 'domain'));
    }

    public function test_nullable_category_and_applies_to_round_trip(): void
    {
        $repo = new SiteRepository();
        $withMeta = $repo->insert([
            'domain'     => 'youtube.com',
            'category'   => 'videos',
            'applies_to' => '[1,2,3]',
        ]);
        $withoutMeta = $repo->insert(['domain' => 'wiki.org']);

        $a = $repo->findById($withMeta);
        $this->assertSame('videos', $a['category']);
        $this->assertSame('[1,2,3]', $a['applies_to']);

        $b = $repo->findById($withoutMeta);
        $this->assertNull($b['category']);
        $this->assertNull($b['applies_to']);
    }
}
