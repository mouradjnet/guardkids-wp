<?php

declare(strict_types=1);

namespace GuardKids\Tests\Integration\Api;

use GuardKids\Api\Controllers\RequestController;
use GuardKids\Database\RequestRepository;
use GuardKids\Tests\Integration\ControllerIntegrationTestCase;

/**
 * Integration tests do RequestController (parent decide pedidos do child).
 *
 * Foco: filtro por status no index, ciclo approve/deny escrito em
 * decided_by (= get_current_user_id), 409 quando ja decidido.
 */
final class RequestControllerTest extends ControllerIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Default: admin user id 42 (stub get_current_user_id() retorna isso)
        $GLOBALS['gk_current_user_id'] = 42;
    }

    private function seedRequest(int $childId, string $kind, string $status = 'pending'): int
    {
        return (new RequestRepository())->insert([
            'child_id' => $childId,
            'kind'     => $kind,
            'status'   => $status,
        ]);
    }

    public function test_index_defaults_to_pending_when_param_absent(): void
    {
        $this->seedRequest(1, 'extra_time', 'pending');
        $this->seedRequest(1, 'unblock_site', 'approved');

        $data = $this->dataOf((new RequestController())->index($this->makeRequest('GET', '/requests')));
        $this->assertCount(1, $data);
        $this->assertSame('pending', $data[0]['status']);
    }

    public function test_index_filters_by_status_param(): void
    {
        $this->seedRequest(1, 'a', 'pending');
        $this->seedRequest(1, 'b', 'approved');
        $this->seedRequest(1, 'c', 'denied');

        $ctrl = new RequestController();
        $this->assertCount(1, $this->dataOf($ctrl->index($this->makeRequest('GET', '/requests', ['status' => 'approved']))));
        $this->assertCount(1, $this->dataOf($ctrl->index($this->makeRequest('GET', '/requests', ['status' => 'denied']))));
    }

    public function test_index_all_returns_everything_ordered_by_created_at_desc(): void
    {
        $first  = $this->seedRequest(1, 'a', 'pending');
        $this->db->query("UPDATE `{$this->db->prefix}guardkids_requests` SET created_at = '2026-01-01 10:00:00' WHERE id = {$first}");
        $second = $this->seedRequest(1, 'b', 'approved');
        $this->db->query("UPDATE `{$this->db->prefix}guardkids_requests` SET created_at = '2026-01-03 10:00:00' WHERE id = {$second}");
        $third  = $this->seedRequest(1, 'c', 'denied');
        $this->db->query("UPDATE `{$this->db->prefix}guardkids_requests` SET created_at = '2026-01-02 10:00:00' WHERE id = {$third}");

        $data = $this->dataOf((new RequestController())->index($this->makeRequest('GET', '/requests', ['status' => 'all'])));
        $this->assertCount(3, $data);
        $this->assertSame($second, $data[0]['id']);
        $this->assertSame($third, $data[1]['id']);
        $this->assertSame($first, $data[2]['id']);
    }

    public function test_approve_returns_404_when_request_missing(): void
    {
        $resp = (new RequestController())->approve($this->makeRequest('POST', '/requests/999/approve', ['id' => 999]));
        $this->assertWpError('not_found', $resp);
        $this->assertResponseStatus(404, $resp);
    }

    public function test_approve_sets_status_and_decided_by_from_current_user(): void
    {
        $GLOBALS['gk_current_user_id'] = 77;
        $id = $this->seedRequest(1, 'extra_time');

        $resp = (new RequestController())->approve($this->makeRequest('POST', "/requests/{$id}/approve", ['id' => $id]));
        $this->assertResponseStatus(200, $resp);

        $data = $this->dataOf($resp);
        $this->assertSame('approved', $data['status']);
        $this->assertSame(77, $data['decidedBy']);
        $this->assertNotNull($data['decidedAt']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            (string) $data['decidedAt'],
        );
    }

    public function test_deny_sets_status_denied(): void
    {
        $id = $this->seedRequest(1, 'extra_time');

        $resp = (new RequestController())->deny($this->makeRequest('POST', "/requests/{$id}/deny", ['id' => $id]));
        $this->assertResponseStatus(200, $resp);

        $data = $this->dataOf($resp);
        $this->assertSame('denied', $data['status']);
        $this->assertSame(42, $data['decidedBy']); // default do setUp
    }

    public function test_approve_returns_409_when_already_decided(): void
    {
        $id = $this->seedRequest(1, 'extra_time', 'approved');

        $resp = (new RequestController())->approve($this->makeRequest('POST', "/requests/{$id}/approve", ['id' => $id]));
        $this->assertWpError('already_decided', $resp);
        $this->assertResponseStatus(409, $resp);
    }

    public function test_deny_returns_409_when_already_denied(): void
    {
        $id = $this->seedRequest(1, 'unblock_site', 'denied');

        $resp = (new RequestController())->deny($this->makeRequest('POST', "/requests/{$id}/deny", ['id' => $id]));
        $this->assertWpError('already_decided', $resp);
    }

    public function test_approve_then_deny_blocks_second_decision(): void
    {
        $id = $this->seedRequest(1, 'extra_time');

        $ctrl = new RequestController();
        $first = $ctrl->approve($this->makeRequest('POST', "/requests/{$id}/approve", ['id' => $id]));
        $this->assertResponseStatus(200, $first);

        $second = $ctrl->deny($this->makeRequest('POST', "/requests/{$id}/deny", ['id' => $id]));
        $this->assertWpError('already_decided', $second);
    }

    public function test_index_camel_case_shape(): void
    {
        $GLOBALS['gk_current_user_id'] = 5;
        $id = $this->seedRequest(1, 'extra_time');
        (new RequestController())->approve($this->makeRequest('POST', "/requests/{$id}/approve", ['id' => $id]));

        $data = $this->dataOf((new RequestController())->index($this->makeRequest('GET', '/requests', ['status' => 'approved'])));
        $this->assertSame($id, $data[0]['id']);
        $this->assertSame(1, $data[0]['childId']);
        $this->assertSame('extra_time', $data[0]['kind']);
        $this->assertSame('approved', $data[0]['status']);
        $this->assertSame(5, $data[0]['decidedBy']);
        $this->assertArrayHasKey('createdAt', $data[0]);
        $this->assertArrayHasKey('decidedAt', $data[0]);
    }
}
