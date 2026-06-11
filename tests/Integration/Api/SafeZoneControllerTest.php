<?php

declare(strict_types=1);

namespace GuardKids\Tests\Integration\Api;

use GuardKids\Api\Controllers\SafeZoneController;
use GuardKids\Database\SafeZoneRepository;
use GuardKids\License\Gate;
use GuardKids\Tests\Integration\ControllerIntegrationTestCase;
use GuardKids\Tests\Support\AlwaysAllowGate;

/**
 * Integration tests do SafeZoneController (parent CRUD).
 *
 * Foco: gating de 'location' premium, ordenacao por name ASC no index,
 * 404 em update/destroy de id inexistente, shape camelCase com
 * radiusMeters.
 */
final class SafeZoneControllerTest extends ControllerIntegrationTestCase
{
    private function freeController(): SafeZoneController
    {
        return new SafeZoneController(new Gate());
    }

    private function premiumController(): SafeZoneController
    {
        return new SafeZoneController(new AlwaysAllowGate());
    }

    private function seedZone(string $name, float $lat = -23.5, float $lng = -46.6, int $radius = 100): int
    {
        return (new SafeZoneRepository())->insert([
            'name'          => $name,
            'latitude'      => $lat,
            'longitude'     => $lng,
            'radius_meters' => $radius,
        ]);
    }

    public function test_index_returns_empty_when_no_zones(): void
    {
        $resp = $this->premiumController()->index();
        $this->assertResponseStatus(200, $resp);
        $this->assertSame([], $this->dataOf($resp));
    }

    public function test_index_orders_by_name_asc(): void
    {
        $this->seedZone('Casa');
        $this->seedZone('Avo');
        $this->seedZone('Escola');

        $data = $this->dataOf($this->premiumController()->index());
        $this->assertSame(['Avo', 'Casa', 'Escola'], array_column($data, 'name'));
    }

    public function test_index_returns_camel_case_with_radiusMeters(): void
    {
        $this->seedZone('Casa', -23.5489121, -46.6388234, 250);

        $row = $this->dataOf($this->premiumController()->index())[0];
        $this->assertSame('Casa', $row['name']);
        $this->assertEqualsWithDelta(-23.5489121, $row['latitude'], 0.0000001);
        $this->assertEqualsWithDelta(-46.6388234, $row['longitude'], 0.0000001);
        $this->assertSame(250, $row['radiusMeters']);
        $this->assertArrayHasKey('createdAt', $row);
        $this->assertArrayHasKey('updatedAt', $row);
    }

    public function test_create_blocked_on_free_plan(): void
    {
        $resp = $this->freeController()->create($this->makeRequest('POST', '/safe-zones', [
            'name'          => 'Casa',
            'latitude'      => -23.5,
            'longitude'     => -46.6,
            'radius_meters' => 100,
        ]));
        $this->assertWpError('plan_limit', $resp);
        $this->assertResponseStatus(402, $resp);
    }

    public function test_create_succeeds_on_premium(): void
    {
        $resp = $this->premiumController()->create($this->makeRequest('POST', '/safe-zones', [
            'name'          => 'Casa',
            'address'       => 'Rua Augusta, 100',
            'latitude'      => -23.5489121,
            'longitude'     => -46.6388234,
            'radius_meters' => 150,
        ]));
        $this->assertResponseStatus(201, $resp);

        $data = $this->dataOf($resp);
        $this->assertGreaterThan(0, $data['id']);
        $this->assertSame('Casa', $data['name']);
        $this->assertSame('Rua Augusta, 100', $data['address']);
        $this->assertSame(150, $data['radiusMeters']);
    }

    public function test_create_null_address_when_empty_string(): void
    {
        $resp = $this->premiumController()->create($this->makeRequest('POST', '/safe-zones', [
            'name'          => 'Padaria',
            'address'       => '',
            'latitude'      => -23.5,
            'longitude'     => -46.6,
            'radius_meters' => 50,
        ]));
        $this->assertNull($this->dataOf($resp)['address']);
    }

    public function test_update_blocked_on_free_plan(): void
    {
        $id = $this->seedZone('Casa');
        $resp = $this->freeController()->update($this->makeRequest('PUT', "/safe-zones/{$id}", [
            'id'            => $id,
            'name'          => 'Casa nova',
            'latitude'      => -23.5,
            'longitude'     => -46.6,
            'radius_meters' => 200,
        ]));
        $this->assertWpError('plan_limit', $resp);
    }

    public function test_update_returns_404_when_zone_missing(): void
    {
        $resp = $this->premiumController()->update($this->makeRequest('PUT', '/safe-zones/999', [
            'id'            => 999,
            'name'          => 'X',
            'latitude'      => -23.5,
            'longitude'     => -46.6,
            'radius_meters' => 100,
        ]));
        $this->assertWpError('not_found', $resp);
        $this->assertResponseStatus(404, $resp);
    }

    public function test_update_persists_changes(): void
    {
        $id = $this->seedZone('Casa', radius: 100);

        $resp = $this->premiumController()->update($this->makeRequest('PUT', "/safe-zones/{$id}", [
            'id'            => $id,
            'name'          => 'Casa nova',
            'latitude'      => -23.6,
            'longitude'     => -46.7,
            'radius_meters' => 300,
        ]));
        $this->assertResponseStatus(200, $resp);

        $data = $this->dataOf($resp);
        $this->assertSame('Casa nova', $data['name']);
        $this->assertSame(300, $data['radiusMeters']);
    }

    public function test_destroy_returns_404_when_zone_missing(): void
    {
        $resp = $this->premiumController()->destroy($this->makeRequest('DELETE', '/safe-zones/999', ['id' => 999]));
        $this->assertWpError('not_found', $resp);
    }

    public function test_destroy_returns_204_with_no_content_and_removes_row(): void
    {
        $id = $this->seedZone('Temp');

        $resp = $this->premiumController()->destroy($this->makeRequest('DELETE', "/safe-zones/{$id}", ['id' => $id]));
        $this->assertResponseStatus(204, $resp);
        $this->assertNull($resp->get_data());

        $count = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM `{$this->db->prefix}guardkids_safe_zones`"
        );
        $this->assertSame(0, $count);
    }
}
