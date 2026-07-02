<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Notifications;

use GuardKids\Notifications\Notifier;
use PHPUnit\Framework\TestCase;

final class NotifierTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];

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

            public function insert($table, $data, $format = null)
            {
                $this->insert_id = count($this->rows) + 1;
                $this->rows[$this->insert_id] = array_merge(['id' => $this->insert_id], $data);
                return 1;
            }

            public function get_results($sql, $output = OBJECT)
            {
                // dedup lookup (createIfAbsent → findWhere)
                if (preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    $out = array_values(array_filter(
                        $this->rows,
                        static fn (array $r): bool => (int) ($r['child_id'] ?? 0) === (int) $m[1],
                    ));
                    if (preg_match("/dedup_key = '([^']*)'/", (string) $sql, $d) === 1) {
                        $out = array_values(array_filter(
                            $out,
                            static fn (array $r): bool => (string) ($r['dedup_key'] ?? '') === $d[1],
                        ));
                    }
                    return $out;
                }
                return [];
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testNotifyRequestDecidedApproved(): void
    {
        (new Notifier())->notifyRequestDecided(
            ['id' => 9, 'child_id' => 1, 'description' => 'Liberar site', 'highlight' => 'canva.com'],
            'approved',
        );
        self::assertCount(1, $this->wpdb->rows);
        $row = $this->wpdb->rows[1];
        self::assertSame('request_approved', $row['type']);
        self::assertSame('Seu pedido foi aprovado! 🎉', $row['title']);
        self::assertSame('Liberar site canva.com', $row['body']);
        self::assertSame('req:9', $row['dedup_key']);
    }

    public function testNotifyRequestDecidedDenied(): void
    {
        (new Notifier())->notifyRequestDecided(['id' => 3, 'child_id' => 1], 'denied');
        self::assertSame('request_denied', $this->wpdb->rows[1]['type']);
        self::assertSame('Seu pedido não foi aprovado', $this->wpdb->rows[1]['title']);
    }

    public function testNotifyRequestDecidedIsIdempotent(): void
    {
        $notifier = new Notifier();
        $req = ['id' => 9, 'child_id' => 1];
        $notifier->notifyRequestDecided($req, 'approved');
        $notifier->notifyRequestDecided($req, 'approved');
        self::assertCount(1, $this->wpdb->rows);
    }

    public function testNotifyBlockedUsesDetailTitle(): void
    {
        (new Notifier())->notifyBlocked(1, 'bedtime');
        self::assertSame('blocked', $this->wpdb->rows[1]['type']);
        self::assertSame('Hora de dormir', $this->wpdb->rows[1]['title']);
    }

    public function testApproachingWarningsLimit(): void
    {
        $now = new \DateTimeImmutable('2026-07-02 15:00:00');
        $warnings = Notifier::approachingWarnings(
            ['daily_limit_enabled' => 1, 'limit_minutes' => 60],
            52,
            $now,
        );
        self::assertCount(1, $warnings);
        self::assertSame('time_warning', $warnings[0]['type']);
        self::assertSame('Faltam 8 min de tela hoje.', $warnings[0]['body']);
        self::assertSame('limit:2026-07-02', $warnings[0]['dedup_key']);
    }

    public function testApproachingWarningsNoLimitWhenFarFromCap(): void
    {
        $now = new \DateTimeImmutable('2026-07-02 15:00:00');
        $warnings = Notifier::approachingWarnings(
            ['daily_limit_enabled' => 1, 'limit_minutes' => 60],
            30,
            $now,
        );
        self::assertSame([], $warnings);
    }

    public function testApproachingWarningsBedtime(): void
    {
        $now = new \DateTimeImmutable('2026-07-02 20:53:00');
        $warnings = Notifier::approachingWarnings(
            ['bedtime_enabled' => 1, 'bedtime_start' => '21:00:00'],
            0,
            $now,
        );
        self::assertCount(1, $warnings);
        self::assertSame('bedtime_warning', $warnings[0]['type']);
        self::assertSame('A hora de dormir começa em 7 min.', $warnings[0]['body']);
        self::assertSame('bedtime:2026-07-02', $warnings[0]['dedup_key']);
    }
}
