<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Notifications;

use GuardKids\Notifications\GuardianNotifier;
use GuardKids\Notifications\WebPush\PushSender;
use PHPUnit\Framework\TestCase;

final class GuardianNotifierTest extends TestCase
{
    /** @var array<int, array{title:string, body:string}> */
    private array $sent = [];
    private PushSender $sender;

    protected function setUp(): void
    {
        $this->sent = [];
        $test = $this;

        // Os repos são final: fakeia o wpdb e usa os repos de verdade.
        // Filho 3 = "Lucas". O dedupe vive no array $dedupKeys.
        $GLOBALS['wpdb'] = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<string, bool> */
            public array $dedupKeys = [];

            public function __construct()
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

            /** ChildRepository::findById */
            public function get_row($query = null, $output = ARRAY_A, $y = 0)
            {
                if (preg_match('/guardkids_children WHERE id = (\d+)/', (string) $query, $m) === 1) {
                    return (int) $m[1] === 3 ? ['id' => 3, 'name' => 'Lucas'] : null;
                }
                return null;
            }

            /** GuardianPushDedupRepository::findWhere(['dedup_key' => ...]) */
            public function get_results($query = null, $output = ARRAY_A)
            {
                if (preg_match("/dedup_key = '([^']*)'/", (string) $query, $m) === 1) {
                    return isset($this->dedupKeys[$m[1]]) ? [['id' => 1, 'dedup_key' => $m[1]]] : [];
                }
                return [];
            }

            public function insert($table, $data, $format = null)
            {
                if (isset($data['dedup_key'])) {
                    $this->dedupKeys[(string) $data['dedup_key']] = true;
                }
                $this->insert_id = count($this->dedupKeys);
                return 1;
            }
        };

        // PushSender NÃO é final: dá pra gravar as chamadas sem tocar no crypto.
        $this->sender = new class ($test) extends PushSender {
            public function __construct(private object $t)
            {
            }

            public function sendToGuardians(string $title, string $body): void
            {
                $this->t->record($title, $body);
            }
        };
    }

    public function record(string $title, string $body): void
    {
        $this->sent[] = ['title' => $title, 'body' => $body];
    }

    /** Repos reais (final) sobre o wpdb fakeado; só o sender é injetado. */
    private function notifier(): GuardianNotifier
    {
        return new GuardianNotifier(null, null, $this->sender);
    }

    public function testRequestCreatedSendsWithChildName(): void
    {
        $this->notifier()->notifyRequestCreated(
            ['id' => 42, 'child_id' => 3, 'description' => 'YouTube Kids'],
        );

        self::assertCount(1, $this->sent);
        self::assertSame('Lucas pediu acesso', $this->sent[0]['title']);
        self::assertSame('YouTube Kids', $this->sent[0]['body']);
    }

    public function testSameRequestTwiceSendsOnce(): void
    {
        $n = $this->notifier();
        $n->notifyRequestCreated(['id' => 42, 'child_id' => 3, 'description' => 'X']);
        $n->notifyRequestCreated(['id' => 42, 'child_id' => 3, 'description' => 'X']);

        self::assertCount(1, $this->sent, 'dedupe por evento: req:42 so anuncia uma vez');
    }

    public function testUnknownChildFallsBackToGenericName(): void
    {
        $this->notifier()->notifyRequestCreated(['id' => 1, 'child_id' => 99, 'description' => 'X']);

        self::assertSame('Seu filho pediu acesso', $this->sent[0]['title']);
    }

    public function testRequestWithoutIdIsIgnored(): void
    {
        $this->notifier()->notifyRequestCreated(['child_id' => 3, 'description' => 'X']);

        self::assertSame([], $this->sent);
    }

    public function testLimitReachedSends(): void
    {
        $this->notifier()->notifyLimitReached(3);

        self::assertCount(1, $this->sent);
        self::assertSame('Lucas esgotou o tempo de tela', $this->sent[0]['title']);
    }

    public function testLimitReachedTwiceSameDaySendsOnce(): void
    {
        $n = $this->notifier();
        $n->notifyLimitReached(3);
        $n->notifyLimitReached(3);

        self::assertCount(1, $this->sent, 'no maximo 1 por filho por dia');
    }

    public function testBlockedAttemptDedupesPerDetail(): void
    {
        $n = $this->notifier();
        $n->notifyBlockedAttempt(3, 'bedtime');
        $n->notifyBlockedAttempt(3, 'weekday');
        $n->notifyBlockedAttempt(3, 'bedtime');

        self::assertCount(2, $this->sent, 'bedtime e weekday sao eventos distintos');
        self::assertStringContainsString('na hora de dormir', $this->sent[0]['title']);
        self::assertStringContainsString('em dia bloqueado', $this->sent[1]['title']);
    }

    public function testDevicePairedSends(): void
    {
        $this->notifier()->notifyDevicePaired(3);

        self::assertCount(1, $this->sent);
        self::assertSame('Novo dispositivo conectado', $this->sent[0]['title']);
        self::assertStringContainsString('Lucas', $this->sent[0]['body']);
    }

    public function testZeroChildIdIsIgnored(): void
    {
        $this->notifier()->notifyLimitReached(0);

        self::assertSame([], $this->sent);
    }
}
