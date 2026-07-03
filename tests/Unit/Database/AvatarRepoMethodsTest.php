<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\MedalUnlockRepository;
use GuardKids\Database\ProgressionRepository;
use PHPUnit\Framework\TestCase;

final class AvatarRepoMethodsTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<string, array<int, array<string, mixed>>> */
            public array $t = [];

            public function __construct()
            {
            }

            public function prepare($query, ...$args)
            {
                $flat = $args[0] ?? null;
                if (is_array($flat)) {
                    $args = $flat;
                }
                return vsprintf(str_replace(['%d', '%s', '%f'], ['%d', "'%s'", '%F'], (string) $query), $args);
            }

            private function nameOf(string $sql): string
            {
                preg_match_all('/guardkids_([a-z_]+)/', $sql, $m);
                return end($m[1]) ?: '';
            }

            public function insert($table, $data, $format = null)
            {
                $n = $this->nameOf((string) $table);
                $this->t[$n] ??= [];
                $id = count($this->t[$n]) + 1;
                $this->insert_id = $id;
                $this->t[$n][$id] = array_merge(['id' => $id], $data);
                return 1;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                $n = $this->nameOf((string) $table);
                $id = (int) ($where['id'] ?? 0);
                if (isset($this->t[$n][$id])) {
                    $this->t[$n][$id] = array_merge($this->t[$n][$id], $data);
                    return 1;
                }
                return 0;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $n = $this->nameOf((string) $sql);
                $rows = array_values($this->t[$n] ?? []);
                if (preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['child_id'] ?? 0) === (int) $m[1]));
                }
                return $rows;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testSetEquippedAvatarPersists(): void
    {
        $repo = new ProgressionRepository();
        $repo->setEquippedAvatar(1, 'rocket');
        $row = $repo->findByChild(1);
        self::assertSame('rocket', $row['equipped_avatar']);
    }

    public function testUnlockedKeys(): void
    {
        $this->wpdb->t['medal_unlocks'] = [
            1 => ['id' => 1, 'child_id' => 1, 'medal_key' => 'faithful_7'],
            2 => ['id' => 2, 'child_id' => 1, 'medal_key' => 'devourer_50'],
            3 => ['id' => 3, 'child_id' => 2, 'medal_key' => 'veteran_10'],
        ];
        $keys = (new MedalUnlockRepository())->unlockedKeys(1);
        sort($keys);
        self::assertSame(['devourer_50', 'faithful_7'], $keys);
        self::assertSame([], (new MedalUnlockRepository())->unlockedKeys(99));
    }
}
