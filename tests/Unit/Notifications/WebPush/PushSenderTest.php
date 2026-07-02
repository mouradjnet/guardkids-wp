<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Notifications\WebPush;

use GuardKids\Database\PushSubscriptionRepository;
use GuardKids\Notifications\WebPush\PushSender;
use PHPUnit\Framework\TestCase;

final class PushSenderTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $GLOBALS['gk_options'] = [];
        $GLOBALS['gk_http'] = [];
        $GLOBALS['gk_http_status'] = 201;

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

            public function get_results($sql, $output = OBJECT)
            {
                if (preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    return array_values(array_filter(
                        $this->rows,
                        static fn (array $r): bool => (int) $r['child_id'] === (int) $m[1],
                    ));
                }
                return [];
            }

            public function insert($table, $data, $format = null)
            {
                $this->insert_id = count($this->rows) + 1;
                $this->rows[$this->insert_id] = array_merge(['id' => $this->insert_id], $data);
                return 1;
            }

            public function query($sql)
            {
                if (preg_match("/DELETE.*endpoint = '([^']*)'/s", (string) $sql, $m) === 1) {
                    foreach ($this->rows as $id => $r) {
                        if ((string) $r['endpoint'] === $m[1]) {
                            unset($this->rows[$id]);
                        }
                    }
                }
                return 0;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;

        (new PushSubscriptionRepository())->upsertByEndpoint(
            1,
            'https://fcm.googleapis.com/fcm/send/abc',
            'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4',
            'BTBZMqHH6r4Tts7J_aSIgg',
        );
    }

    public function testSendPostsWithVapidAndAes128gcmHeaders(): void
    {
        (new PushSender())->sendToChild(1, 'Oi', 'Corpo');
        self::assertCount(1, $GLOBALS['gk_http']);
        $headers = $GLOBALS['gk_http'][0]['args']['headers'];
        self::assertStringStartsWith('vapid t=', $headers['Authorization']);
        self::assertSame('aes128gcm', $headers['Content-Encoding']);
        self::assertArrayHasKey('TTL', $headers);
    }

    public function testGoneResponseRemovesSubscription(): void
    {
        $GLOBALS['gk_http_status'] = 410;
        (new PushSender())->sendToChild(1, 'Oi', 'Corpo');
        self::assertCount(0, $this->wpdb->rows);
    }

    public function testSuccessKeepsSubscription(): void
    {
        $GLOBALS['gk_http_status'] = 201;
        (new PushSender())->sendToChild(1, 'Oi', 'Corpo');
        self::assertCount(1, $this->wpdb->rows);
    }
}
