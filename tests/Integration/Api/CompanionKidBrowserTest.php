<?php

declare(strict_types=1);

namespace GuardKids\Tests\Integration\Api;

use GuardKids\Api\Controllers\CompanionController;
use GuardKids\Api\Controllers\RequestController;
use GuardKids\Database\ChildRepository;
use GuardKids\Database\CompanionDeviceRepository;
use GuardKids\Tests\Integration\ControllerIntegrationTestCase;

/**
 * Loop pedir→aprovar→navegar contra MySQL real: request-site cria pedido;
 * aprovar adiciona ao whitelist; sync devolve em allowedSites.
 */
final class CompanionKidBrowserTest extends ControllerIntegrationTestCase
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

    public function test_request_approve_then_sync_returns_site(): void
    {
        $p = $this->pairedDevice();

        $reqSite = $this->makeRequest('POST', '/companion/request-site');
        $reqSite->set_header('X-GuardKids-Companion-Token', $p['token']);
        $reqSite->set_param('domain', 'canva.com');
        (new CompanionController())->requestSite($reqSite);

        global $wpdb;
        $reqId = (int) $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}guardkids_requests WHERE child_id = {$p['childId']} ORDER BY id DESC LIMIT 1",
        );
        $approve = $this->makeRequest('POST', "/requests/{$reqId}/approve");
        $approve->set_param('id', $reqId);
        (new RequestController())->approve($approve);

        $sync = $this->makeRequest('POST', '/companion/sync');
        $sync->set_header('X-GuardKids-Companion-Token', $p['token']);
        $allowed = $this->dataOf((new CompanionController())->sync($sync))['allowedSites'];

        $this->assertContains('canva.com', $allowed);
    }
}
