<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Notifications\WebPush;

use GuardKids\Notifications\WebPush\PushSender;
use PHPUnit\Framework\TestCase;

/**
 * Cobre só a SELEÇÃO de destinatários — não o envio. O envio passa por
 * Payload::encrypt, que gera chave EC e estoura no openssl do Windows (é o
 * gotcha que já deixa 2 casos do PushSenderTest vermelhos localmente). Manter
 * este teste longe do crypto é o que o mantém verde nas duas pontas.
 */
final class PushSenderGuardiansTest extends TestCase
{
    /** @param array<int, array<string, mixed>> $subs */
    private function bootWpdb(array $subs): void
    {
        $wpdb = new class ($subs) extends \wpdb {
            public string $prefix = 'wp_';

            /** @param array<int, array<string, mixed>> $subs */
            public function __construct(private array $subs)
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

            public function get_results($query = null, $output = ARRAY_A)
            {
                // subscriptions do guardião: as semeadas.
                // guardians: vazio (os ativos deste teste são admins WP).
                if (str_contains((string) $query, 'guardian_push_subscriptions')) {
                    return $this->subs;
                }
                return [];
            }
        };
        $GLOBALS['wpdb'] = $wpdb;
    }

    protected function setUp(): void
    {
        $GLOBALS['gk_caps_by_user'] = [];
    }

    public function testKeepsSubscriptionsOfActiveGuardians(): void
    {
        $this->bootWpdb([
            ['id' => 1, 'wp_user_id' => 1, 'endpoint' => 'https://a', 'p256dh' => 'P', 'auth' => 'A'],
        ]);
        $GLOBALS['gk_caps_by_user'][1] = ['manage_options' => true];

        $subs = (new PushSender())->guardianSubscriptions();

        self::assertCount(1, $subs);
        self::assertSame('https://a', $subs[0]['endpoint']);
    }

    public function testSkipsSubscriptionsOfWhoIsNoLongerGuardian(): void
    {
        $this->bootWpdb([
            ['id' => 1, 'wp_user_id' => 1, 'endpoint' => 'https://a', 'p256dh' => 'P', 'auth' => 'A'],
            ['id' => 2, 'wp_user_id' => 42, 'endpoint' => 'https://b', 'p256dh' => 'P', 'auth' => 'A'],
        ]);
        // Só o user 1 é admin. O 42 saiu do time: sem cap, sem linha ativa.
        $GLOBALS['gk_caps_by_user'][1] = ['manage_options' => true];

        $subs = (new PushSender())->guardianSubscriptions();

        self::assertCount(1, $subs);
        self::assertSame(1, (int) $subs[0]['wp_user_id']);
    }

    public function testEmptyWhenNobodySubscribed(): void
    {
        $this->bootWpdb([]);

        self::assertSame([], (new PushSender())->guardianSubscriptions());
    }
}
