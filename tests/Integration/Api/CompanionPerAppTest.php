<?php

declare(strict_types=1);

namespace GuardKids\Tests\Integration\Api;

use GuardKids\Api\Controllers\CompanionController;
use GuardKids\Database\ChildRepository;
use GuardKids\Database\CompanionDeviceRepository;
use GuardKids\Tests\Integration\ControllerIntegrationTestCase;

/**
 * Bloqueio por-app contra MySQL real: setBlockedApps persiste e o sync devolve
 * blockedApps; installed_apps faz round-trip (migration 013 / DB v13).
 */
final class CompanionPerAppTest extends ControllerIntegrationTestCase
{
    /** @return array{childId:int, token:string} */
    private function pairedDevice(): array
    {
        $childId = (new ChildRepository())->insert([
            'slug' => 'kid-' . uniqid(), 'name' => 'Kid', 'status' => 'offline',
            'used_minutes' => 0, 'limit_minutes' => 60,
        ]);
        $token = bin2hex(random_bytes(32));
        (new CompanionDeviceRepository())->insert([
            'child_id' => $childId, 'device_uuid' => 'uuid-' . uniqid(),
            'session_token_hash' => hash('sha256', $token),
            'session_expires_at' => gmdate('Y-m-d H:i:s', time() + 86400), 'status' => 'active',
        ]);
        return ['childId' => $childId, 'token' => $token];
    }

    public function test_set_blocked_apps_then_sync_returns_them(): void
    {
        $p = $this->pairedDevice();

        $set = $this->makeRequest('POST', '/companion/blocked-apps');
        $set->set_param('child_id', $p['childId']);
        $set->set_param('apps', ['com.zhiliaoapp.musically']);
        (new CompanionController())->setBlockedApps($set);

        $sync = $this->makeRequest('POST', '/companion/sync');
        $sync->set_header('X-GuardKids-Companion-Token', $p['token']);
        $sync->set_param('installed_apps', [['packageName' => 'com.whatsapp', 'label' => 'WhatsApp']]);
        $data = $this->dataOf((new CompanionController())->sync($sync));

        $this->assertSame(['com.zhiliaoapp.musically'], $data['blockedApps']);
        $this->assertSame('com.whatsapp', $data['installedApps'][0]['packageName']);
    }
}
