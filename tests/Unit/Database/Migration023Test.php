<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

/**
 * Migração 023 — adiciona status/approved_by/approved_at em content_items e
 * faz grandfather do conteúdo existente (status='approved'). Verifica o DDL/DML
 * emitido capturando as queries num FakeWpdb.
 */
final class Migration023Test extends TestCase
{
    /** @var array<int, string> */
    private array $queries = [];

    private function fakeWpdb(bool $statusColumnExists): \wpdb
    {
        $self = $this;
        return new class ($self, $statusColumnExists) extends \wpdb {
            public string $prefix = 'wp_';
            public function __construct(private object $t, private bool $hasStatus)
            {
            }
            public function prepare($query, ...$args)
            {
                $flat = $args[0] ?? null;
                if (is_array($flat)) {
                    $args = $flat;
                }
                return vsprintf(str_replace(['%d', '%s'], ['%d', "'%s'"], (string) $query), $args);
            }
            public function get_var($sql, $x = 0, $y = 0)
            {
                if (str_contains((string) $sql, "LIKE 'status'")) {
                    return $this->hasStatus ? 'status' : null;
                }
                return null;
            }
            public function query($sql)
            {
                ($this->t)->record((string) $sql);
                return 0;
            }
        };
    }

    public function record(string $sql): void
    {
        $this->queries[] = $sql;
    }

    public function testAddsStatusColumnAndGrandfathersExisting(): void
    {
        $this->queries = [];
        $GLOBALS['wpdb'] = $this->fakeWpdb(statusColumnExists: false);

        $factory = require dirname(__DIR__, 3) . '/database/migrations/023_content_moderation.php';
        $factory($GLOBALS['wpdb'], 'utf8mb4');

        $all = implode("\n", $this->queries);
        self::assertMatchesRegularExpression('/ALTER TABLE \S*content_items ADD COLUMN status/', $all);
        self::assertMatchesRegularExpression('/ADD COLUMN approved_by/', $all);
        self::assertMatchesRegularExpression('/ADD COLUMN approved_at/', $all);
        self::assertMatchesRegularExpression(
            "/UPDATE \S*content_items SET status = 'approved'.*WHERE status = 'pending'/s",
            $all,
        );
    }

    public function testIdempotentWhenStatusColumnAlreadyExists(): void
    {
        $this->queries = [];
        $GLOBALS['wpdb'] = $this->fakeWpdb(statusColumnExists: true);

        $factory = require dirname(__DIR__, 3) . '/database/migrations/023_content_moderation.php';
        $factory($GLOBALS['wpdb'], 'utf8mb4');

        $all = implode("\n", $this->queries);
        self::assertDoesNotMatchRegularExpression('/ADD COLUMN status /', $all);
    }
}
