<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Notifications;

use GuardKids\Database\GuardianPushDedupRepository;
use GuardKids\Database\ChildRepository;
use GuardKids\Notifications\GuardianNotifier;
use GuardKids\Notifications\WebPush\PushSender;
use PHPUnit\Framework\TestCase;

final class GuardianNotifierPlaceTest extends TestCase
{
    /** @var array<int, array{title:string, body:string}> */
    private array $sent = [];

    private function notifier(): GuardianNotifier
    {
        $test = $this;
        $dedup = new class () extends GuardianPushDedupRepository {
            /** @var array<string, bool> */
            public array $keys = [];
            public function __construct()
            {
            }
            public function createIfAbsent(string $key): bool
            {
                if (isset($this->keys[$key])) {
                    return false;
                }
                $this->keys[$key] = true;
                return true;
            }
        };
        $children = new class () extends ChildRepository {
            public function __construct()
            {
            }
            public function findById(int $id): ?array
            {
                return ['id' => $id, 'name' => 'João'];
            }
        };
        $sender = new class ($test) extends PushSender {
            public function __construct(private object $t)
            {
            }
            public function sendToGuardians(string $title, string $body): void
            {
                ($this->t)->record($title, $body);
            }
        };
        return new GuardianNotifier($dedup, $children, $sender);
    }

    public function record(string $title, string $body): void
    {
        $this->sent[] = ['title' => $title, 'body' => $body];
    }

    public function testEnteredSendsWithIcon(): void
    {
        $this->sent = [];
        $this->notifier()->notifyPlaceEntered(9, 'Escola', '🏫', '2026-07-20 14:00:00');
        self::assertCount(1, $this->sent);
        self::assertStringContainsString('João', $this->sent[0]['title']);
        self::assertStringContainsString('Escola', $this->sent[0]['title']);
        self::assertStringContainsString('🏫', $this->sent[0]['title']);
    }

    public function testLeftSends(): void
    {
        $this->sent = [];
        $this->notifier()->notifyPlaceLeft(9, 'Escola', '2026-07-20 15:00:00');
        self::assertCount(1, $this->sent);
        self::assertStringContainsString('saiu', mb_strtolower($this->sent[0]['title']));
    }

    public function testSameTokenDedupes(): void
    {
        $this->sent = [];
        $n = $this->notifier();
        $n->notifyPlaceEntered(9, 'Escola', '🏫', '2026-07-20 14:00:00');
        $n->notifyPlaceEntered(9, 'Escola', '🏫', '2026-07-20 14:00:00');
        self::assertCount(1, $this->sent);
    }
}
