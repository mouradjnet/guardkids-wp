<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\ChildAcademyController;
use GuardKids\Auth\ChildAuth;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * ChildAcademyController — Academy da criança (Onda 3).
 *
 * Pontos críticos: (1) concluir credita XP/coins UMA vez (idempotência),
 * (2) o valor do XP é do servidor — chave inválida não credita nada,
 * (3) isolamento por filho, (4) sem token → 401.
 */
final class ChildAcademyControllerTest extends TestCase
{
    private \wpdb $wpdb;
    private string $token = '';

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<string, string> */
            public array $settings = [];
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

            public function get_var($sql, $x = 0, $y = 0)
            {
                $sql = (string) $sql;
                if (preg_match("/setting_key = '([^']+)'/", $sql, $m) === 1) {
                    if (str_contains($sql, 'SELECT id')) {
                        return isset($this->settings[$m[1]]) ? '1' : null;
                    }
                    return $this->settings[$m[1]] ?? null;
                }
                return null;
            }

            public function insert($table, $data, $format = null)
            {
                if (str_contains((string) $table, 'guardkids_settings')) {
                    $this->settings[(string) $data['setting_key']] = (string) $data['value'];
                    return 1;
                }
                $n = $this->nameOf((string) $table);
                $this->t[$n] ??= [];
                $id = count($this->t[$n]) + 1;
                $this->insert_id = $id;
                $this->t[$n][$id] = array_merge(['id' => $id], $data);
                return 1;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                if (str_contains((string) $table, 'guardkids_settings')) {
                    $this->settings[(string) $where['setting_key']] = (string) $data['value'];
                    return 1;
                }
                $n = $this->nameOf((string) $table);
                $id = (int) ($where['id'] ?? 0);
                if (isset($this->t[$n][$id])) {
                    $this->t[$n][$id] = array_merge($this->t[$n][$id], $data);
                }
                return 1;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $sql = (string) $sql;
                $rows = array_values($this->t[$this->nameOf($sql)] ?? []);
                if (preg_match('/child_id = (\d+)/', $sql, $mm) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['child_id'] ?? 0) === (int) $mm[1]));
                }
                if (preg_match("/lesson_key = '([^']+)'/", $sql, $mm) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (string) ($r['lesson_key'] ?? '') === $mm[1]));
                }
                return $rows;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->token = (new ChildAuth())->issueToken(1, 'tablet')['token'];
    }

    private function getReq(): WP_REST_Request
    {
        $req = new WP_REST_Request('GET', '/child/academy');
        $req->set_header('X-GuardKids-Token', $this->token);
        return $req;
    }

    /**
     * @param list<int> $answers
     */
    private function quizReq(string $key, array $answers, ?string $token = null): WP_REST_Request
    {
        $req = new WP_REST_Request('POST', '/child/academy/quiz');
        $req->set_header('X-GuardKids-Token', $token ?? $this->token);
        $req->set_param('lesson_key', $key);
        $req->set_param('answers', $answers);
        return $req;
    }

    public function testIndexReturns401WithoutToken(): void
    {
        $res = (new ChildAcademyController())->index(new WP_REST_Request('GET', '/child/academy'));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(401, $res->get_error_data()['status']);
    }

    public function testEmptyProgressForNewChild(): void
    {
        $data = (new ChildAcademyController())->index($this->getReq())->get_data();
        self::assertSame([], $data['completedKeys']);
        self::assertSame(0, $data['progression']['xp']);
    }

    public function testQuizPassCreditsBonusOnceAndIsIdempotent(): void
    {
        $ctrl = new ChildAcademyController();

        // senha-secreta: gabarito [0,1,1]
        $first = $ctrl->quiz($this->quizReq('senha-secreta', [0, 1, 1]))->get_data();
        self::assertTrue($first['passed']);
        self::assertSame(3, $first['correct']);
        self::assertTrue($first['awarded']['justCompleted']);
        self::assertSame(25, $first['awarded']['xp']);
        self::assertSame(15, $first['awarded']['coins']);
        self::assertSame(['senha-secreta'], $first['completedKeys']);
        self::assertSame(25, $first['progression']['xp']);
        self::assertSame(15, $first['progression']['coins']);
        self::assertCount(1, $this->wpdb->t['academy_child_lessons'] ?? []);

        // reenviar o quiz da aula já concluída: passa, mas não credita de novo
        $second = $ctrl->quiz($this->quizReq('senha-secreta', [0, 1, 1]))->get_data();
        self::assertTrue($second['passed']);
        self::assertFalse($second['awarded']['justCompleted']);
        self::assertSame(0, $second['awarded']['xp']);
        self::assertSame(25, $second['progression']['xp']);
        self::assertCount(1, $this->wpdb->t['academy_child_lessons'] ?? []);
    }

    public function testQuizFailDoesNotCredit(): void
    {
        // senha-secreta correto é [0,1,1]; erra a 1ª → reprova
        $data = (new ChildAcademyController())->quiz($this->quizReq('senha-secreta', [2, 1, 1]))->get_data();
        self::assertFalse($data['passed']);
        self::assertSame(2, $data['correct']);
        self::assertSame([], $data['completedKeys']);
        self::assertSame(0, $data['progression']['xp']);
        self::assertArrayNotHasKey('academy_child_lessons', $this->wpdb->t);
    }

    public function testInvalidLessonKeyIsRejectedAndCreditsNothing(): void
    {
        $res = (new ChildAcademyController())->quiz($this->quizReq('xp-gratis-farm', [0, 0, 0]));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(400, $res->get_error_data()['status']);
        self::assertArrayNotHasKey('academy_child_lessons', $this->wpdb->t);
    }

    public function testStreakIsPreservedWhenCrediting(): void
    {
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 1, 'xp' => 0, 'coins' => 0, 'streak_days' => 4, 'last_activity_date' => '2026-08-01'],
        ];
        $ctrl = new ChildAcademyController();
        // tempo-intro: gabarito [0,1,1]
        $ctrl->quiz($this->quizReq('tempo-intro', [0, 1, 1]));

        $wallet = array_values($this->wpdb->t['progression'])[0];
        self::assertSame(25, (int) $wallet['xp']);
        self::assertSame(4, (int) $wallet['streak_days']);
        self::assertSame('2026-08-01', $wallet['last_activity_date']);
    }
}
