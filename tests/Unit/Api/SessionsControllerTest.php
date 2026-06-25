<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\SessionsController;
use GuardKids\Security\SessionManager;
use PHPUnit\Framework\TestCase;
use WP_REST_Response;

final class SessionsControllerTest extends TestCase
{
    private function fakeManager(): SessionManager
    {
        return new class () extends SessionManager {
            /** @var array<int, array<string, mixed>> */
            public array $sessions = [
                ['device' => 'Chrome · Windows', 'browser' => 'Chrome', 'os' => 'Windows', 'ip' => '1.1.1.1', 'lastAccess' => 300, 'current' => true],
                ['device' => 'Firefox · Linux', 'browser' => 'Firefox', 'os' => 'Linux', 'ip' => '2.2.2.2', 'lastAccess' => 100, 'current' => false],
            ];

            public function list(): array
            {
                return $this->sessions;
            }

            public function destroyOthers(): int
            {
                $this->sessions = [$this->sessions[0]];
                return 1;
            }
        };
    }

    public function testIndexReturnsSessions(): void
    {
        $res = (new SessionsController($this->fakeManager()))->index();
        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertCount(2, $res->get_data()['sessions']);
        self::assertTrue($res->get_data()['sessions'][0]['current']);
    }

    public function testDestroyOthersReturnsCountAndUpdatedList(): void
    {
        $ctrl = new SessionsController($this->fakeManager());
        $res = $ctrl->destroyOthers();
        $data = $res->get_data();
        self::assertSame(1, $data['destroyed']);
        self::assertCount(1, $data['sessions']);
        self::assertTrue($data['sessions'][0]['current']);
    }
}
