<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Notifications;

use GuardKids\Notifications\Notifier;
use GuardKids\Notifications\WebPush\PushSender;
use PHPUnit\Framework\TestCase;

final class NotifierPushTest extends TestCase
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

    private function spySender(): PushSender
    {
        return new class () extends PushSender {
            public int $calls = 0;

            public function __construct()
            {
            }

            public function sendToChild(int $childId, string $title, string $body): void
            {
                $this->calls++;
            }
        };
    }

    public function testEmitsPushWhenNotificationIsCreated(): void
    {
        $sender = $this->spySender();
        $notifier = new Notifier(null, null, $sender);
        $notifier->notifyRequestDecided(['id' => 9, 'child_id' => 1], 'approved');
        self::assertSame(1, $sender->calls);
    }

    public function testDoesNotPushOnDedupHit(): void
    {
        $sender = $this->spySender();
        $notifier = new Notifier(null, null, $sender);
        $notifier->notifyRequestDecided(['id' => 9, 'child_id' => 1], 'approved');
        $notifier->notifyRequestDecided(['id' => 9, 'child_id' => 1], 'approved');
        self::assertSame(1, $sender->calls);
    }
}
