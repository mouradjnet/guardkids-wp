<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\GuardianPushController;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

final class GuardianPushControllerTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        // Semeia a chave pública: VapidKeys::ensure() retorna cedo e NÃO gera
        // chave EC (que estouraria no openssl do Windows). É o que mantém este
        // teste verde onde o equivalente da criança é vermelho.
        $GLOBALS['gk_options'] = ['guardkids_vapid_public' => 'FAKE_PUBLIC_KEY'];
        $GLOBALS['gk_current_user_id'] = 7;

        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];
            /** @var array<int, string> */
            public array $queries = [];

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

            public function get_results($query = null, $output = ARRAY_A)
            {
                if (preg_match("/endpoint = '([^']*)'/", (string) $query, $m) === 1) {
                    return array_values(array_filter(
                        $this->rows,
                        static fn (array $r): bool => (string) $r['endpoint'] === $m[1],
                    ));
                }
                return array_values($this->rows);
            }

            public function insert($table, $data, $format = null)
            {
                $this->insert_id = count($this->rows) + 1;
                $this->rows[$this->insert_id] = array_merge(['id' => $this->insert_id], $data);
                return 1;
            }

            public function query($query)
            {
                $this->queries[] = (string) $query;
                if (preg_match("/DELETE.*endpoint = '([^']*)'/s", (string) $query, $m) === 1) {
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
    }

    public function testKeyReturnsSeededPublicKey(): void
    {
        $resp = (new GuardianPushController())->pushKey(new WP_REST_Request());

        self::assertSame(['publicKey' => 'FAKE_PUBLIC_KEY'], $resp->get_data());
    }

    public function testSubscribePersistsWithCurrentUserId(): void
    {
        // set_param, não set_json_params: no stub do bootstrap o get_param só
        // lê $params (é o padrão que ChildSelfControllerTest já usa).
        $req = new WP_REST_Request();
        $req->set_param('endpoint', 'https://fcm.example/xyz');
        $req->set_param('keys', ['p256dh' => 'P', 'auth' => 'A']);

        $resp = (new GuardianPushController())->pushSubscribe($req);

        self::assertSame(['ok' => true], $resp->get_data());
        self::assertCount(1, $this->wpdb->rows);
        self::assertSame(7, $this->wpdb->rows[1]['wp_user_id'], 'grava o guardiao logado');
        self::assertSame('https://fcm.example/xyz', $this->wpdb->rows[1]['endpoint']);
    }

    public function testSubscribeRejectsMissingEndpoint(): void
    {
        $req = new WP_REST_Request();
        $req->set_param('keys', ['p256dh' => 'P', 'auth' => 'A']);

        $resp = (new GuardianPushController())->pushSubscribe($req);

        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame('invalid_subscription', $resp->get_error_code());
    }

    public function testSubscribeRejectsMissingKeys(): void
    {
        $req = new WP_REST_Request();
        $req->set_param('endpoint', 'https://fcm.example/xyz');

        $resp = (new GuardianPushController())->pushSubscribe($req);

        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame('invalid_subscription', $resp->get_error_code());
    }

    public function testUnsubscribeDeletesByEndpoint(): void
    {
        $this->wpdb->rows[1] = [
            'id' => 1, 'wp_user_id' => 7, 'endpoint' => 'https://fcm.example/xyz',
            'p256dh' => 'P', 'auth' => 'A', 'created_at' => '2026-01-01 00:00:00',
        ];
        $req = new WP_REST_Request();
        $req->set_param('endpoint', 'https://fcm.example/xyz');

        $resp = (new GuardianPushController())->pushUnsubscribe($req);

        self::assertSame(['ok' => true], $resp->get_data());
        self::assertCount(0, $this->wpdb->rows);
    }

    public function testUnsubscribeRejectsMissingEndpoint(): void
    {
        $resp = (new GuardianPushController())->pushUnsubscribe(new WP_REST_Request());

        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame('invalid_subscription', $resp->get_error_code());
    }
}
