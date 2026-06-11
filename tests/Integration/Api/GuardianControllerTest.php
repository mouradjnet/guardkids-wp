<?php

declare(strict_types=1);

namespace GuardKids\Tests\Integration\Api;

use GuardKids\Api\Controllers\GuardianController;
use GuardKids\Database\GuardianRepository;
use GuardKids\Tests\Integration\ControllerIntegrationTestCase;

/**
 * Integration tests do GuardianController.
 *
 * Cobre lazy-seed do current user no index/create, validação de email,
 * proteção de último admin, anti-self-delete, ciclo activate.
 */
final class GuardianControllerTest extends ControllerIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['gk_current_user_id'] = 1;
        $GLOBALS['gk_users'] = [
            1 => [
                'ID'           => 1,
                'user_login'   => 'djair',
                'user_email'   => 'djair@familia.com',
                'display_name' => 'Djair',
            ],
            2 => [
                'ID'           => 2,
                'user_login'   => 'marina',
                'user_email'   => 'marina@familia.com',
                'display_name' => 'Marina',
            ],
        ];
    }

    private function seed(string $email, string $role = 'collaborator', string $status = 'pending', ?int $wpUserId = null): int
    {
        return (new GuardianRepository())->insert([
            'wp_user_id' => $wpUserId,
            'name'       => 'Seed ' . $email,
            'email'      => $email,
            'role'       => $role,
            'status'     => $status,
        ]);
    }

    public function test_index_lazy_seeds_current_user_when_table_empty(): void
    {
        $data = $this->dataOf((new GuardianController())->index($this->makeRequest('GET', '/guardians')));

        $this->assertCount(1, $data);
        $this->assertSame('djair@familia.com', $data[0]['email']);
        $this->assertSame('admin', $data[0]['role']);
        $this->assertSame('active', $data[0]['status']);
        $this->assertSame(1, $data[0]['wpUserId']);
    }

    public function test_index_skips_lazy_seed_when_self_already_present(): void
    {
        $this->seed('djair@familia.com', 'admin', 'active', 1);
        $data = $this->dataOf((new GuardianController())->index($this->makeRequest('GET', '/guardians')));
        $this->assertCount(1, $data);
    }

    public function test_create_persists_guardian_as_pending_with_role(): void
    {
        $resp = (new GuardianController())->create($this->makeRequest('POST', '/guardians', [
            'name'  => 'Marina',
            'email' => 'marina@familia.com',
            'role'  => 'collaborator',
        ]));

        $this->assertResponseStatus(201, $resp);
        $data = $this->dataOf($resp);
        $this->assertSame('Marina', $data['name']);
        $this->assertSame('marina@familia.com', $data['email']);
        $this->assertSame('collaborator', $data['role']);
        $this->assertSame('pending', $data['status']);
        $this->assertNull($data['wpUserId']);
    }

    public function test_create_rejects_invalid_email(): void
    {
        $resp = (new GuardianController())->create($this->makeRequest('POST', '/guardians', [
            'name'  => 'X',
            'email' => 'not-an-email',
        ]));
        $this->assertWpError('invalid_payload', $resp);
        $this->assertResponseStatus(422, $resp);
    }

    public function test_create_rejects_duplicate_email(): void
    {
        $this->seed('marina@familia.com');

        $resp = (new GuardianController())->create($this->makeRequest('POST', '/guardians', [
            'name'  => 'Marina dupla',
            'email' => 'marina@familia.com',
        ]));
        $this->assertWpError('email_exists', $resp);
        $this->assertResponseStatus(409, $resp);
    }

    public function test_update_role_promotes_collaborator_to_admin(): void
    {
        $this->seed('djair@familia.com', 'admin', 'active', 1);
        $id = $this->seed('marina@familia.com', 'collaborator', 'active', 2);

        $resp = (new GuardianController())->updateRole(
            $this->makeRequest('PATCH', "/guardians/{$id}", ['id' => $id, 'role' => 'admin']),
        );
        $this->assertResponseStatus(200, $resp);
        $this->assertSame('admin', $this->dataOf($resp)['role']);
    }

    public function test_update_role_blocks_demoting_last_admin(): void
    {
        $id = $this->seed('djair@familia.com', 'admin', 'active', 1);

        $resp = (new GuardianController())->updateRole(
            $this->makeRequest('PATCH', "/guardians/{$id}", ['id' => $id, 'role' => 'collaborator']),
        );
        $this->assertWpError('last_admin', $resp);
        $this->assertResponseStatus(422, $resp);
    }

    public function test_activate_changes_status_from_pending_to_active(): void
    {
        $id = $this->seed('marina@familia.com', 'collaborator', 'pending');

        $resp = (new GuardianController())->activate(
            $this->makeRequest('POST', "/guardians/{$id}/activate", ['id' => $id]),
        );
        $this->assertResponseStatus(200, $resp);
        $this->assertSame('active', $this->dataOf($resp)['status']);
    }

    public function test_destroy_blocks_self_delete(): void
    {
        $id = $this->seed('djair@familia.com', 'admin', 'active', 1);

        $resp = (new GuardianController())->destroy(
            $this->makeRequest('DELETE', "/guardians/{$id}", ['id' => $id]),
        );
        $this->assertWpError('self_delete', $resp);
        $this->assertResponseStatus(422, $resp);
    }

    public function test_destroy_blocks_last_admin(): void
    {
        // admin sem wp_user_id ligado ao current, pra escapar do self_delete
        $id = $this->seed('outro@familia.com', 'admin', 'active', 999);

        $resp = (new GuardianController())->destroy(
            $this->makeRequest('DELETE', "/guardians/{$id}", ['id' => $id]),
        );
        $this->assertWpError('last_admin', $resp);
        $this->assertResponseStatus(422, $resp);
    }

    public function test_destroy_removes_guardian_when_safe(): void
    {
        $this->seed('djair@familia.com', 'admin', 'active', 1);
        $id = $this->seed('marina@familia.com', 'collaborator', 'active', 2);

        $resp = (new GuardianController())->destroy(
            $this->makeRequest('DELETE', "/guardians/{$id}", ['id' => $id]),
        );
        $this->assertResponseStatus(200, $resp);
        $data = $this->dataOf($resp);
        $this->assertTrue($data['deleted']);
        $this->assertSame($id, $data['id']);

        $remaining = (new GuardianRepository())->findById($id);
        $this->assertNull($remaining);
    }

    public function test_index_returns_camel_case_shape(): void
    {
        $this->seed('djair@familia.com', 'admin', 'active', 1);

        $data = $this->dataOf((new GuardianController())->index($this->makeRequest('GET', '/guardians')));
        $this->assertArrayHasKey('id', $data[0]);
        $this->assertArrayHasKey('wpUserId', $data[0]);
        $this->assertArrayHasKey('createdAt', $data[0]);
        $this->assertArrayHasKey('updatedAt', $data[0]);
    }
}
