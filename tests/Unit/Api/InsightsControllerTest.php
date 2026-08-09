<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\AI\AnthropicClient;
use GuardKids\AI\InsightsService;
use GuardKids\Api\Controllers\InsightsController;
use GuardKids\License\Gate;
use GuardKids\Security\RateLimiter;
use GuardKids\Tests\Support\AlwaysAllowGate;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * InsightsController — gating, cache (custo controlado), degradação e refresh.
 */
final class InsightsControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['gk_transients']      = [];
        $GLOBALS['gk_current_user_id'] = 42;
    }

    /** Client fake: controla presença de chave e a resposta, conta chamadas. */
    private function fakeClient(bool $keyPresent, ?string $canned): AnthropicClient
    {
        return new class ($keyPresent, $canned) extends AnthropicClient {
            public int $calls = 0;
            public function __construct(private bool $keyPresent, private ?string $canned)
            {
            }
            public function hasKey(): bool
            {
                return $this->keyPresent;
            }
            public function complete(string $system, string $user): ?string
            {
                $this->calls++;
                return $this->canned;
            }
        };
    }

    /** Controller com o seam de dados sobrescrito pra não tocar em $wpdb. */
    private function controller(Gate $gate, AnthropicClient $client, ?RateLimiter $limiter = null): InsightsController
    {
        $service = new InsightsService($client);
        $ctrl = new class ($gate, $service, $client, $limiter) extends InsightsController {
            protected function reportData(WP_REST_Request $req): array
            {
                return [
                    'range' => 'week',
                    'kpis'  => ['totalMinutes' => 840, 'avgMinutesPerDay' => 120, 'percentOfLimit' => 0.75, 'deltaPctVsPrevious' => 0.4],
                    'perChild' => [['childId' => 7, 'name' => 'Lucas', 'totalMinutes' => 840, 'avgMinutesPerDay' => 120]],
                    'topSites' => [['domain' => 'youtube.com', 'opens' => 120, 'topChildId' => 7]],
                ];
            }
        };
        return $ctrl;
    }

    private function req(): WP_REST_Request
    {
        return new WP_REST_Request('GET', '/insights');
    }

    private function denyGate(): Gate
    {
        return new class () extends Gate {
            public function __construct()
            {
            }
            public function can(string $featureId): bool
            {
                return false;
            }
        };
    }

    public function testFreeIsLockedAndNeverCallsAi(): void
    {
        $client = $this->fakeClient(true, '{"insights":[{"title":"x","body":"y"}]}');
        $res = $this->controller($this->denyGate(), $client)->index($this->req());

        $data = $res->get_data();
        self::assertFalse($data['available']);
        self::assertSame('locked', $data['reason']);
        self::assertSame([], $data['insights']);
        self::assertSame(0, $client->calls, 'Free não pode chamar a IA');
    }

    public function testNoKeyDegradesAndSkipsAi(): void
    {
        $client = $this->fakeClient(false, '{"insights":[{"title":"x","body":"y"}]}');
        $res = $this->controller(new AlwaysAllowGate(), $client)->index($this->req());

        $data = $res->get_data();
        self::assertFalse($data['available']);
        self::assertSame('no_key', $data['reason']);
        self::assertSame(0, $client->calls);
    }

    public function testHappyPathGeneratesAndCaches(): void
    {
        $client = $this->fakeClient(true, '{"insights":[{"title":"Tempo subiu","body":"Subiu 40%.","severity":"warning","cta":"Limite"}]}');
        $ctrl = $this->controller(new AlwaysAllowGate(), $client);

        $data = $ctrl->index($this->req())->get_data();

        self::assertTrue($data['available']);
        self::assertFalse($data['fromCache']);
        self::assertCount(1, $data['insights']);
        self::assertSame('Tempo subiu', $data['insights'][0]['title']);
        self::assertArrayHasKey('generatedAt', $data);
        self::assertSame(1, $client->calls);
    }

    public function testSecondCallHitsCacheWithoutCallingAi(): void
    {
        $client = $this->fakeClient(true, '{"insights":[{"title":"T","body":"B"}]}');
        $ctrl = $this->controller(new AlwaysAllowGate(), $client);

        $ctrl->index($this->req());                  // gera + cacheia
        $data = $ctrl->index($this->req())->get_data(); // deve ler do cache

        self::assertTrue($data['fromCache']);
        self::assertCount(1, $data['insights']);
        self::assertSame(1, $client->calls, 'cache hit não pode re-chamar a IA');
    }

    public function testEmptyInsightsAreNotCached(): void
    {
        $client = $this->fakeClient(true, '{"insights":[]}');
        $ctrl = $this->controller(new AlwaysAllowGate(), $client);

        $first  = $ctrl->index($this->req())->get_data();
        $second = $ctrl->index($this->req())->get_data();

        self::assertSame([], $first['insights']);
        self::assertFalse($second['fromCache'], 'vazio não deve virar cache');
        self::assertSame(2, $client->calls, 'sem cache, a segunda tenta de novo');
    }

    public function testRefreshIsRateLimited(): void
    {
        $client = $this->fakeClient(true, '{"insights":[{"title":"T","body":"B"}]}');
        $ctrl = $this->controller(new AlwaysAllowGate(), $client, new RateLimiter(1, 300));

        $ok = $ctrl->refresh($this->req());
        self::assertInstanceOf(\WP_REST_Response::class, $ok);

        $blocked = $ctrl->refresh($this->req());
        self::assertInstanceOf(WP_Error::class, $blocked);
        self::assertSame(429, $blocked->get_error_data()['status']);
    }

    public function testRefreshRegeneratesBustingCache(): void
    {
        $client = $this->fakeClient(true, '{"insights":[{"title":"T","body":"B"}]}');
        $ctrl = $this->controller(new AlwaysAllowGate(), $client, new RateLimiter(5, 300));

        $ctrl->index($this->req());                        // calls=1, cacheia
        $data = $ctrl->refresh($this->req())->get_data();  // calls=2, ignora cache

        self::assertFalse($data['fromCache']);
        self::assertSame(2, $client->calls);
    }
}
