<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Auth;

use GuardKids\Auth\ChildAuth;
use GuardKids\Database\SettingsRepository;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * Testes do ChildAuth — usa SettingsRepository in-memory pra evitar DB.
 *
 * Substitui o $GLOBALS['wpdb'] com um fake que captura inserts e get_var
 * pra simular a tabela de settings.
 */
final class ChildAuthTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new class () extends \wpdb {
            public string $prefix = 'wp_';
            /** @var array<string, string> key → JSON */
            public array $store = [];

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

            public function get_var($sql, $x = 0, $y = 0)
            {
                // SettingsRepository::get chama get_var com WHERE setting_key = '...'
                if (preg_match("/setting_key = '([^']+)'/", (string) $sql, $m) === 1) {
                    return $this->store[$m[1]] ?? null;
                }
                return null;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $out = [];
                if (preg_match("/setting_key LIKE '([^%']+)%'/", (string) $sql, $m) === 1) {
                    foreach ($this->store as $key => $value) {
                        if (str_starts_with($key, $m[1])) {
                            $out[] = ['setting_key' => $key, 'value' => $value];
                        }
                    }
                }
                return $out;
            }

            public function esc_like($text)
            {
                return $text;
            }

            public function insert($table, $data, $format = null)
            {
                $this->store[$data['setting_key']] = (string) $data['value'];
                return 1;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                return 1;
            }
        };
    }

    public function testIssueTokenReturnsHexTokenAndStoresChildId(): void
    {
        $auth = new ChildAuth();
        $issued = $auth->issueToken(7, 'Tablet do Lucas');

        self::assertSame(ChildAuth::TOKEN_LENGTH, strlen($issued['token']));
        self::assertMatchesRegularExpression('/^[a-f0-9]+$/', $issued['token']);
        self::assertSame(hash('sha256', $issued['token']), $issued['hash']);

        // Token salvo com a chave correta
        $key = 'child_token:' . $issued['hash'];
        $stored = json_decode($GLOBALS['wpdb']->store[$key] ?? '', true);
        self::assertSame(7, $stored['childId']);
        self::assertSame('Tablet do Lucas', $stored['label']);
    }

    public function testResolveChildIdReturnsCorrectIdForValidToken(): void
    {
        $auth = new ChildAuth();
        $issued = $auth->issueToken(11);

        $req = new WP_REST_Request('GET', '/child/me');
        $req->set_header('X-GuardKids-Token', $issued['token']);

        self::assertSame(11, $auth->resolveChildId($req));
    }

    public function testResolveChildIdReturnsNullWhenTokenMissing(): void
    {
        $auth = new ChildAuth();
        $req = new WP_REST_Request('GET', '/child/me');

        self::assertNull($auth->resolveChildId($req));
    }

    public function testResolveChildIdReturnsNullForWrongLengthToken(): void
    {
        $auth = new ChildAuth();
        $req = new WP_REST_Request('GET', '/child/me');
        $req->set_header('X-GuardKids-Token', 'abc');

        self::assertNull($auth->resolveChildId($req));
    }

    public function testResolveChildIdReturnsNullForNonHexToken(): void
    {
        $auth = new ChildAuth();
        $req = new WP_REST_Request('GET', '/child/me');
        $req->set_header('X-GuardKids-Token', str_repeat('z', ChildAuth::TOKEN_LENGTH));

        self::assertNull($auth->resolveChildId($req));
    }

    public function testResolveChildIdReturnsNullForUnknownToken(): void
    {
        $auth = new ChildAuth();
        $req = new WP_REST_Request('GET', '/child/me');
        $req->set_header(
            'X-GuardKids-Token',
            str_repeat('f', ChildAuth::TOKEN_LENGTH)
        );

        self::assertNull($auth->resolveChildId($req));
    }

    public function testIssuedTokensAreUnique(): void
    {
        $auth = new ChildAuth();
        $a = $auth->issueToken(1);
        $b = $auth->issueToken(1);

        self::assertNotSame($a['token'], $b['token']);
        self::assertNotSame($a['hash'], $b['hash']);
    }

    public function test_pairedChildIds_returns_distinct_ids_from_tokens(): void
    {
        $auth = new ChildAuth();
        $auth->issueToken(1, 'tablet');
        $auth->issueToken(1, 'celular'); // mesmo filho, 2 tokens
        $auth->issueToken(2, null);

        $ids = $auth->pairedChildIds();
        sort($ids);

        self::assertSame([1, 2], $ids);
    }

    public function test_pairedChildIds_empty_without_tokens(): void
    {
        self::assertSame([], (new ChildAuth())->pairedChildIds());
    }
}
