<?php

declare(strict_types=1);

namespace GuardKids\Tests\Integration\Api;

use GuardKids\Api\Controllers\ChildController;
use GuardKids\License\Gate;
use GuardKids\Tests\Integration\ControllerIntegrationTestCase;
use GuardKids\Tests\Support\AlwaysAllowGate;

/**
 * Integration tests do ChildController contra MySQL real.
 *
 * Valida o ciclo completo REST → controller → repo → MySQL → response,
 * incluindo:
 *  - shape do toJson() (snake_case do row → camelCase do JSON)
 *  - gating do plan_limit (Free permite 1 filho)
 *  - validações de payload (name obrigatório, bedtime exige start+end)
 *  - 404 quando id inexistente
 *  - issueDeviceToken integrado com ChildAuth + SettingsRepository
 *
 * Premium é simulado com {@see AlwaysAllowGate}; Free usa Gate real (sem
 * licença persistida = plano Free natural).
 */
final class ChildControllerTest extends ControllerIntegrationTestCase
{
    private function freeController(): ChildController
    {
        return new ChildController(new Gate());
    }

    private function premiumController(): ChildController
    {
        return new ChildController(new AlwaysAllowGate());
    }

    public function test_index_returns_empty_when_no_children(): void
    {
        $resp = $this->freeController()->index();
        $this->assertResponseStatus(200, $resp);
        $this->assertSame([], $this->dataOf($resp));
    }

    public function test_index_returns_camel_case_shape(): void
    {
        $ctrl = $this->freeController();
        $ctrl->create($this->makeRequest('POST', '/children', [
            'name'          => 'Maria',
            'age'           => 8,
            'limit_minutes' => 90,
        ]));

        $resp = $ctrl->index();
        $data = $this->dataOf($resp);
        $this->assertCount(1, $data);

        $kid = $data[0];
        $this->assertSame('Maria', $kid['name']);
        $this->assertSame('maria', $kid['slug']);
        $this->assertSame(8, $kid['age']);
        $this->assertSame(90, $kid['limitMinutes']);
        $this->assertSame(0, $kid['usedMinutes']);
        $this->assertSame('offline', $kid['status']);
        $this->assertSame('YYYYYYY', $kid['allowedWeekdays']);
        $this->assertFalse($kid['bedtimeEnabled']);
    }

    public function test_show_returns_404_when_child_missing(): void
    {
        $req  = $this->makeRequest('GET', '/children/999', ['id' => 999]);
        $resp = $this->freeController()->show($req);
        $this->assertWpError('not_found', $resp);
        $this->assertResponseStatus(404, $resp);
    }

    public function test_show_returns_child_when_present(): void
    {
        $ctrl    = $this->freeController();
        $created = $ctrl->create($this->makeRequest('POST', '/children', ['name' => 'Pedro']));
        $id      = $this->dataOf($created)['id'];

        $resp = $ctrl->show($this->makeRequest('GET', "/children/{$id}", ['id' => $id]));
        $this->assertResponseStatus(200, $resp);
        $this->assertSame('Pedro', $this->dataOf($resp)['name']);
    }

    public function test_create_rejects_empty_name(): void
    {
        $resp = $this->freeController()->create($this->makeRequest('POST', '/children', ['name' => '']));
        $this->assertWpError('invalid_payload', $resp);
        $this->assertResponseStatus(422, $resp);
    }

    public function test_create_returns_201_with_camel_case_data(): void
    {
        $resp = $this->freeController()->create($this->makeRequest('POST', '/children', [
            'name' => 'Ana',
            'age'  => 10,
        ]));
        $this->assertResponseStatus(201, $resp);

        $data = $this->dataOf($resp);
        $this->assertGreaterThan(0, $data['id']);
        $this->assertSame('Ana', $data['name']);
        $this->assertSame('ana', $data['slug']);
        $this->assertSame(10, $data['age']);
        $this->assertSame(60, $data['limitMinutes']); // default
    }

    public function test_create_blocks_second_child_on_free_plan(): void
    {
        $ctrl = $this->freeController();
        $ctrl->create($this->makeRequest('POST', '/children', ['name' => 'Filho 1']));

        $resp = $ctrl->create($this->makeRequest('POST', '/children', ['name' => 'Filho 2']));
        $this->assertWpError('plan_limit', $resp);
        $this->assertResponseStatus(402, $resp);
    }

    public function test_create_allows_multiple_children_on_premium(): void
    {
        $ctrl = $this->premiumController();
        $first  = $ctrl->create($this->makeRequest('POST', '/children', ['name' => 'A']));
        $second = $ctrl->create($this->makeRequest('POST', '/children', ['name' => 'B']));

        $this->assertResponseStatus(201, $first);
        $this->assertResponseStatus(201, $second);
    }

    public function test_create_with_schedule_blocked_on_free(): void
    {
        $resp = $this->freeController()->create($this->makeRequest('POST', '/children', [
            'name'            => 'Júlia',
            'bedtime_enabled' => true,
            'bedtime_start'   => '21:00',
            'bedtime_end'     => '07:00',
        ]));
        $this->assertWpError('plan_limit', $resp);
        $this->assertResponseStatus(402, $resp);
    }

    public function test_update_returns_404_when_missing(): void
    {
        $resp = $this->freeController()->update($this->makeRequest('PUT', '/children/999', ['id' => 999, 'name' => 'X']));
        $this->assertWpError('not_found', $resp);
    }

    public function test_update_patches_only_provided_fields(): void
    {
        $ctrl    = $this->freeController();
        $created = $ctrl->create($this->makeRequest('POST', '/children', ['name' => 'Lucas', 'age' => 7]));
        $id      = $this->dataOf($created)['id'];

        $resp = $ctrl->update($this->makeRequest('PUT', "/children/{$id}", [
            'id'            => $id,
            'limit_minutes' => 120,
        ]));
        $this->assertResponseStatus(200, $resp);

        $data = $this->dataOf($resp);
        $this->assertSame('Lucas', $data['name']);          // preservado
        $this->assertSame(7, $data['age']);                 // preservado
        $this->assertSame(120, $data['limitMinutes']);      // alterado
    }

    public function test_update_bedtime_enabled_requires_start_and_end(): void
    {
        $ctrl    = $this->premiumController();
        $created = $ctrl->create($this->makeRequest('POST', '/children', ['name' => 'Bia']));
        $id      = $this->dataOf($created)['id'];

        // Tenta ligar bedtime sem ter start/end nem na request nem no row.
        $resp = $ctrl->update($this->makeRequest('PUT', "/children/{$id}", [
            'id'              => $id,
            'bedtime_enabled' => true,
        ]));
        $this->assertWpError('invalid_payload', $resp);
        $this->assertResponseStatus(422, $resp);
    }

    public function test_update_coerces_time_format_to_HH_MM_SS(): void
    {
        $ctrl    = $this->premiumController();
        $created = $ctrl->create($this->makeRequest('POST', '/children', ['name' => 'Tom']));
        $id      = $this->dataOf($created)['id'];

        $resp = $ctrl->update($this->makeRequest('PUT', "/children/{$id}", [
            'id'              => $id,
            'bedtime_enabled' => true,
            'bedtime_start'   => '21:30',
            'bedtime_end'     => '06:45',
        ]));
        $this->assertResponseStatus(200, $resp);

        $data = $this->dataOf($resp);
        $this->assertTrue($data['bedtimeEnabled']);
        $this->assertSame('21:30', $data['bedtimeStart']); // toJson devolve HH:MM
        $this->assertSame('06:45', $data['bedtimeEnd']);

        // Mas no banco persistiu HH:MM:SS
        $row = $this->db->get_row(
            "SELECT bedtime_start, bedtime_end FROM `{$this->db->prefix}guardkids_children` WHERE id = {$id}",
            'ARRAY_A',
        );
        $this->assertSame('21:30:00', $row['bedtime_start']);
        $this->assertSame('06:45:00', $row['bedtime_end']);
    }

    public function test_destroy_removes_child_and_returns_id(): void
    {
        $ctrl    = $this->freeController();
        $created = $ctrl->create($this->makeRequest('POST', '/children', ['name' => 'Iza']));
        $id      = $this->dataOf($created)['id'];

        $resp = $ctrl->destroy($this->makeRequest('DELETE', "/children/{$id}", ['id' => $id]));
        $this->assertResponseStatus(200, $resp);
        $this->assertSame(['deleted' => true, 'id' => $id], $this->dataOf($resp));

        // Confirma persistencia: 0 rows na tabela
        $count = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM `{$this->db->prefix}guardkids_children`",
        );
        $this->assertSame(0, $count);
    }

    public function test_issueDeviceToken_returns_token_and_persists_hash(): void
    {
        $ctrl    = $this->freeController();
        $created = $ctrl->create($this->makeRequest('POST', '/children', ['name' => 'Davi']));
        $id      = $this->dataOf($created)['id'];

        $resp = $ctrl->issueDeviceToken($this->makeRequest('POST', "/children/{$id}/device-token", [
            'id'    => $id,
            'label' => 'tablet quarto',
        ]));
        $this->assertResponseStatus(201, $resp);

        $data = $this->dataOf($resp);
        $this->assertNotEmpty($data['token']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $data['token']); // 32 bytes hex
        $this->assertSame($id, $data['childId']);
        $this->assertSame('tablet quarto', $data['label']);
        $this->assertArrayHasKey('createdAt', $data);

        // ChildAuth persiste hash em wp_guardkids_settings — confirma que rolou
        $count = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM `{$this->db->prefix}guardkids_settings`",
        );
        $this->assertGreaterThan(0, $count);
    }

    public function test_issueDeviceToken_returns_404_for_missing_child(): void
    {
        $resp = $this->freeController()->issueDeviceToken($this->makeRequest('POST', '/children/999/device-token', [
            'id' => 999,
        ]));
        $this->assertWpError('not_found', $resp);
        $this->assertResponseStatus(404, $resp);
    }
}
