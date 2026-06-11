<?php

declare(strict_types=1);

namespace GuardKids\Tests\Integration\Repository;

use GuardKids\Database\CategoryRepository;
use GuardKids\Tests\Integration\IntegrationTestCase;

/**
 * Valida CategoryRepository contra MySQL real.
 *
 * Foco: idempotência do seed (só roda se tabela vazia), UNIQUE em slug,
 * default de blocked = 0.
 */
final class CategoryRepositoryTest extends IntegrationTestCase
{
    public function test_seed_populates_when_table_empty(): void
    {
        $repo = new CategoryRepository();
        $repo->seed([
            ['slug' => 'adult', 'name' => 'Adulto', 'blocked' => 1],
            ['slug' => 'games', 'name' => 'Jogos', 'blocked' => 0],
        ]);

        $all = $repo->findAll();
        $this->assertCount(2, $all);
    }

    public function test_seed_is_skipped_when_table_not_empty(): void
    {
        $repo = new CategoryRepository();
        $repo->insert(['slug' => 'existing', 'name' => 'Existing', 'blocked' => 0]);

        $repo->seed([
            ['slug' => 'new1', 'name' => 'New 1', 'blocked' => 0],
            ['slug' => 'new2', 'name' => 'New 2', 'blocked' => 0],
        ]);

        $all = $repo->findAll();
        $this->assertCount(1, $all);
        $this->assertSame('existing', $all[0]['slug']);
    }

    public function test_default_blocked_is_zero_when_omitted(): void
    {
        $repo = new CategoryRepository();
        $id   = $repo->insert(['slug' => 'neutral', 'name' => 'Neutro']);

        $row = $repo->findById($id);
        $this->assertSame(0, (int) $row['blocked']);
    }

    public function test_unique_constraint_on_slug_blocks_duplicate(): void
    {
        $repo = new CategoryRepository();
        $first  = $repo->insert(['slug' => 'unique-cat', 'name' => 'A', 'blocked' => 0]);
        $second = $repo->insert(['slug' => 'unique-cat', 'name' => 'B', 'blocked' => 0]);

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second);
    }

    public function test_nullable_description_and_icon_round_trip(): void
    {
        $repo = new CategoryRepository();
        $withMeta = $repo->insert([
            'slug'        => 'social',
            'name'        => 'Social',
            'description' => 'Redes sociais',
            'icon'        => 'group',
            'blocked'     => 1,
        ]);
        $withoutMeta = $repo->insert(['slug' => 'bare', 'name' => 'Bare', 'blocked' => 0]);

        $a = $repo->findById($withMeta);
        $this->assertSame('Redes sociais', $a['description']);
        $this->assertSame('group', $a['icon']);

        $b = $repo->findById($withoutMeta);
        $this->assertNull($b['description']);
        $this->assertNull($b['icon']);
    }
}
