<?php

declare(strict_types=1);

namespace GuardKids\Tests\Integration\Api;

use GuardKids\Api\Controllers\CompanionController;
use GuardKids\Database\ChildRepository;
use GuardKids\Database\CompanionDeviceRepository;
use GuardKids\Database\SettingsRepository;
use GuardKids\Tests\Integration\ControllerIntegrationTestCase;
use WP_REST_Request;

/**
 * companion/sync devolve o veredito de bloqueio por tempo contra MySQL real:
 * gate de modo (family vs maximum) e reason=bedtime via ScheduleEvaluator.
 */
final class CompanionSyncBlockTest extends ControllerIntegrationTestCase
{
    /**
     * Cria uma criança (com schedule) + um device pareado/ativo com session token.
     *
     * @param array<string, mixed> $childOverrides
     * @return array{childId:int, token:string}
     */
    private function pairedDevice(array $childOverrides = []): array
    {
        $childId = (new ChildRepository())->insert(array_merge([
            'slug'          => 'kid-' . uniqid(),
            'name'          => 'Kid',
            'status'        => 'offline',
            'used_minutes'  => 0,
            'limit_minutes' => 60,
        ], $childOverrides));

        $token = bin2hex(random_bytes(32));
        (new CompanionDeviceRepository())->insert([
            'child_id'           => $childId,
            'device_uuid'        => 'uuid-' . uniqid(),
            'session_token_hash' => hash('sha256', $token),
            'session_expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
            'status'             => 'active',
        ]);

        return ['childId' => $childId, 'token' => $token];
    }

    private function syncRequest(string $token): WP_REST_Request
    {
        $req = $this->makeRequest('POST', '/companion/sync');
        $req->set_header('X-GuardKids-Companion-Token', $token);
        return $req;
    }

    public function test_maximum_mode_blocks_bedtime(): void
    {
        $paired = $this->pairedDevice([
            'bedtime_enabled'  => 1,
            'bedtime_start'    => '00:00:00',
            'bedtime_end'      => '23:59:59',
            'allowed_weekdays' => 'YYYYYYY',
        ]);
        (new SettingsRepository())->set('protection_mode', 'maximum');

        $resp = (new CompanionController())->sync($this->syncRequest($paired['token']));
        $this->assertResponseStatus(200, $resp);

        $block = $this->dataOf($resp)['block'];
        $this->assertTrue($block['isBlocked']);
        $this->assertSame('bedtime', $block['reason']);
        $this->assertSame('maximum', $block['mode']);
        $this->assertNotNull($block['unlockAt']);
    }

    public function test_family_mode_does_not_block(): void
    {
        $paired = $this->pairedDevice([
            'bedtime_enabled' => 1,
            'bedtime_start'   => '00:00:00',
            'bedtime_end'     => '23:59:59',
        ]);
        (new SettingsRepository())->set('protection_mode', 'family');

        $block = $this->dataOf(
            (new CompanionController())->sync($this->syncRequest($paired['token']))
        )['block'];

        $this->assertFalse($block['isBlocked']);
        $this->assertSame('family', $block['mode']);
    }
}
