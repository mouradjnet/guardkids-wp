<?php

declare(strict_types=1);

/**
 * REQUER o banco de teste dockerizado (MySQL em 127.0.0.1:3307):
 *   docker compose -f docker-compose.test.yml up -d
 * Rode com a config de integração:
 *   vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ChildSelfControllerLocationPlaceTest
 * NÃO roda no gate unitário (tests/Unit apenas).
 */

namespace GuardKids\Tests\Integration\Api;

use GuardKids\Api\Controllers\ChildSelfController;
use GuardKids\Auth\ChildAuth;
use GuardKids\Database\ChildPlaceRepository;
use GuardKids\Database\ChildRepository;
use GuardKids\Database\SafeZoneRepository;
use GuardKids\Database\SettingsRepository;
use GuardKids\Tests\Integration\ControllerIntegrationTestCase;
use WP_REST_Request;

/**
 * Integration do geofencing acoplado ao POST /child/location contra MySQL real:
 *  - dois fixes dentro de uma zona confirmam e persistem child_place.current_zone_id
 *  - um fix válido retorna 201 mesmo sem nenhuma zona cadastrada (fail-open)
 */
final class ChildSelfControllerLocationPlaceTest extends ControllerIntegrationTestCase
{
    private function pairedChild(string $name = 'Maria'): array
    {
        $repo = new ChildRepository();
        $id   = $repo->insert([
            'slug'          => strtolower($name),
            'name'          => $name,
            'status'        => 'offline',
            'used_minutes'  => 0,
            'limit_minutes' => 60,
        ]);
        $issued = (new ChildAuth())->issueToken($id);
        return ['id' => $id, 'token' => $issued['token']];
    }

    private function locationRequest(string $token, float $lat, float $lng): WP_REST_Request
    {
        $req = $this->makeRequest('POST', '/child/location', [
            'latitude'  => $lat,
            'longitude' => $lng,
            'accuracy'  => 10,
        ]);
        $req->set_header('X-GuardKids-Token', $token);
        return $req;
    }

    public function test_two_fixes_inside_zone_persist_current_zone_id(): void
    {
        (new SettingsRepository())->set('location_enabled', true);
        $child = $this->pairedChild('Lucas');

        $lat = -23.5489121;
        $lng = -46.6388234;
        $zoneId = (new SafeZoneRepository())->insert([
            'name'          => 'Escola',
            'latitude'      => $lat,
            'longitude'     => $lng,
            'radius_meters' => 150,
        ]);

        $ctrl = new ChildSelfController();

        // Fix 1: candidato pendente (CONFIRM_FIXES = 2), ainda sem confirmar.
        $r1 = $ctrl->reportLocation($this->locationRequest($child['token'], $lat, $lng));
        $this->assertResponseStatus(201, $r1);

        // Fix 2: confirma a transição "entered" → current_zone_id gravado.
        $r2 = $ctrl->reportLocation($this->locationRequest($child['token'], $lat, $lng));
        $this->assertResponseStatus(201, $r2);

        $state = (new ChildPlaceRepository())->get($child['id']);
        $this->assertIsArray($state);
        $this->assertSame($zoneId, (int) $state['current_zone_id']);
    }

    public function test_fix_returns_201_with_no_zones(): void
    {
        (new SettingsRepository())->set('location_enabled', true);
        $child = $this->pairedChild('Ana');

        // Nenhuma safe_zone cadastrada: o geofencing roda mas não emite nada.
        $resp = $this->reportLocationAndAssertOk($child['token']);
        $this->assertResponseStatus(201, $resp);

        $row = $this->db->get_row(
            "SELECT * FROM `{$this->db->prefix}guardkids_locations` WHERE child_id = {$child['id']}",
            'ARRAY_A',
        );
        $this->assertIsArray($row);
        $this->assertSame('-23.5000000', (string) $row['latitude']);
    }

    private function reportLocationAndAssertOk(string $token)
    {
        return (new ChildSelfController())->reportLocation(
            $this->locationRequest($token, -23.5, -46.6)
        );
    }
}
