<?php

declare(strict_types=1);

namespace GuardKids\Tests\Integration\Repository;

use GuardKids\Database\ChildRepository;
use GuardKids\Tests\Integration\IntegrationTestCase;

/**
 * Valida que ChildRepository (via base Repository) executa SQL contra MySQL real.
 *
 * Cobre o ciclo completo insert→findById→findAll→update→delete + a
 * UNIQUE constraint em `slug` (que stubs unit não conseguem testar).
 */
final class ChildRepositoryTest extends IntegrationTestCase
{
    public function test_insert_persists_and_findById_retrieves(): void
    {
        $repo = new ChildRepository();

        $id = $repo->insert([
            'slug'          => 'maria',
            'name'          => 'Maria',
            'age'           => 8,
            'status'        => 'offline',
            'used_minutes'  => 0,
            'limit_minutes' => 60,
        ]);

        $this->assertGreaterThan(0, $id);

        $row = $repo->findById($id);
        $this->assertIsArray($row);
        $this->assertSame('maria', $row['slug']);
        $this->assertSame('Maria', $row['name']);
        $this->assertSame(8, (int) $row['age']);
        $this->assertNotEmpty($row['created_at']);
        $this->assertNotEmpty($row['updated_at']);
    }

    public function test_update_changes_columns(): void
    {
        $repo = new ChildRepository();
        $id   = $repo->insert([
            'slug'          => 'joao',
            'name'          => 'João',
            'status'        => 'offline',
            'used_minutes'  => 0,
            'limit_minutes' => 60,
        ]);

        $ok = $repo->update($id, [
            'status'        => 'online',
            'used_minutes'  => 42,
            'limit_minutes' => 90,
        ]);

        $this->assertTrue($ok);

        $row = $repo->findById($id);
        $this->assertSame('online', $row['status']);
        $this->assertSame(42, (int) $row['used_minutes']);
        $this->assertSame(90, (int) $row['limit_minutes']);
    }

    public function test_delete_removes_row(): void
    {
        $repo = new ChildRepository();
        $id   = $repo->insert([
            'slug'          => 'temp',
            'name'          => 'Temp',
            'status'        => 'offline',
            'used_minutes'  => 0,
            'limit_minutes' => 60,
        ]);

        $this->assertTrue($repo->delete($id));
        $this->assertNull($repo->findById($id));
    }

    public function test_findAll_returns_rows_ordered_by_id_asc(): void
    {
        $repo = new ChildRepository();
        $repo->insert(['slug' => 'a', 'name' => 'A', 'status' => 'offline', 'used_minutes' => 0, 'limit_minutes' => 60]);
        $repo->insert(['slug' => 'b', 'name' => 'B', 'status' => 'offline', 'used_minutes' => 0, 'limit_minutes' => 60]);
        $repo->insert(['slug' => 'c', 'name' => 'C', 'status' => 'offline', 'used_minutes' => 0, 'limit_minutes' => 60]);

        $all = $repo->findAll();
        $this->assertCount(3, $all);
        $this->assertSame(['a', 'b', 'c'], array_column($all, 'slug'));
    }

    public function test_unique_constraint_on_slug_blocks_duplicate(): void
    {
        $repo = new ChildRepository();
        $first = $repo->insert([
            'slug'          => 'pedro',
            'name'          => 'Pedro 1',
            'status'        => 'offline',
            'used_minutes'  => 0,
            'limit_minutes' => 60,
        ]);
        $this->assertGreaterThan(0, $first);

        $second = $repo->insert([
            'slug'          => 'pedro',
            'name'          => 'Pedro 2',
            'status'        => 'offline',
            'used_minutes'  => 0,
            'limit_minutes' => 60,
        ]);
        // MySQL UNIQUE viola — mysqli em strict mode lança exception, capturada
        // por insert() que retorna false (e Repository::insert devolve 0).
        $this->assertSame(0, $second);
    }

    public function test_migration_003_schedule_columns_exist(): void
    {
        $repo = new ChildRepository();
        $id   = $repo->insert([
            'slug'             => 'sched',
            'name'             => 'Sched',
            'status'           => 'offline',
            'used_minutes'     => 0,
            'limit_minutes'    => 60,
            'bedtime_start'    => '21:00:00',
            'bedtime_end'      => '07:00:00',
            'bedtime_enabled'  => 1,
            'allowed_weekdays' => 'YYYYYNN',
        ]);

        $row = $repo->findById($id);
        $this->assertSame('21:00:00', $row['bedtime_start']);
        $this->assertSame('07:00:00', $row['bedtime_end']);
        $this->assertSame(1, (int) $row['bedtime_enabled']);
        $this->assertSame('YYYYYNN', $row['allowed_weekdays']);
    }
}
