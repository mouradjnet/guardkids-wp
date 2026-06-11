<?php

declare(strict_types=1);

namespace GuardKids\Tests\Integration\Api;

use GuardKids\Api\Controllers\LocationController;
use GuardKids\Database\LocationRepository;
use GuardKids\Tests\Integration\ControllerIntegrationTestCase;

/**
 * Integration tests do LocationController (leitura pelo parent).
 *
 * Foco: filtro obrigatorio por child_id, ordenacao DESC por recorded_at,
 * limit, shape camelCase com recordedAt convertido pra ISO UTC.
 */
final class LocationControllerTest extends ControllerIntegrationTestCase
{
    private function seedLocation(int $childId, string $recordedAt, float $lat = -23.5, float $lng = -46.6): int
    {
        return (new LocationRepository())->insert([
            'child_id'    => $childId,
            'latitude'    => $lat,
            'longitude'   => $lng,
            'recorded_at' => $recordedAt,
        ]);
    }

    public function test_index_returns_empty_when_child_id_absent(): void
    {
        $this->seedLocation(1, '2026-01-01 10:00:00');

        $resp = (new LocationController())->index($this->makeRequest('GET', '/locations'));
        $this->assertResponseStatus(200, $resp);
        $this->assertSame([], $this->dataOf($resp));
    }

    public function test_index_returns_empty_when_child_has_no_data(): void
    {
        $resp = (new LocationController())->index($this->makeRequest('GET', '/locations', ['child_id' => 99]));
        $this->assertSame([], $this->dataOf($resp));
    }

    public function test_index_orders_by_recorded_at_desc(): void
    {
        $this->seedLocation(1, '2026-01-01 10:00:00');
        $this->seedLocation(1, '2026-01-03 10:00:00');
        $this->seedLocation(1, '2026-01-02 10:00:00');

        $data = $this->dataOf((new LocationController())->index(
            $this->makeRequest('GET', '/locations', ['child_id' => 1, 'limit' => 10])
        ));
        $this->assertCount(3, $data);
        $this->assertSame('2026-01-03T10:00:00Z', $data[0]['recordedAt']);
        $this->assertSame('2026-01-02T10:00:00Z', $data[1]['recordedAt']);
        $this->assertSame('2026-01-01T10:00:00Z', $data[2]['recordedAt']);
    }

    public function test_index_respects_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->seedLocation(1, sprintf('2026-01-%02d 10:00:00', $i));
        }
        $data = $this->dataOf((new LocationController())->index(
            $this->makeRequest('GET', '/locations', ['child_id' => 1, 'limit' => 2])
        ));
        $this->assertCount(2, $data);
    }

    public function test_index_default_limit_is_1(): void
    {
        $this->seedLocation(1, '2026-01-01 10:00:00');
        $this->seedLocation(1, '2026-01-02 10:00:00');
        $this->seedLocation(1, '2026-01-03 10:00:00');

        // Sem param limit → default 1 (LocationController, não Repository's 50)
        $data = $this->dataOf((new LocationController())->index(
            $this->makeRequest('GET', '/locations', ['child_id' => 1])
        ));
        $this->assertCount(1, $data);
        $this->assertSame('2026-01-03T10:00:00Z', $data[0]['recordedAt']);
    }

    public function test_index_isolates_per_child(): void
    {
        $this->seedLocation(1, '2026-01-01 10:00:00');
        $this->seedLocation(2, '2026-01-01 10:00:00');
        $this->seedLocation(1, '2026-01-02 10:00:00');

        $child1 = $this->dataOf((new LocationController())->index(
            $this->makeRequest('GET', '/locations', ['child_id' => 1, 'limit' => 10])
        ));
        $child2 = $this->dataOf((new LocationController())->index(
            $this->makeRequest('GET', '/locations', ['child_id' => 2, 'limit' => 10])
        ));

        $this->assertCount(2, $child1);
        $this->assertCount(1, $child2);
        foreach ($child1 as $row) {
            $this->assertSame(1, $row['childId']);
        }
    }

    public function test_index_camel_case_shape_with_iso_utc(): void
    {
        (new LocationRepository())->insert([
            'child_id'    => 1,
            'latitude'    => -23.5489121,
            'longitude'   => -46.6388234,
            'accuracy'    => 18,
            'battery'     => 72,
            'recorded_at' => '2026-01-15 14:30:00',
        ]);

        $row = $this->dataOf((new LocationController())->index(
            $this->makeRequest('GET', '/locations', ['child_id' => 1])
        ))[0];

        $this->assertSame(1, $row['childId']);
        $this->assertEqualsWithDelta(-23.5489121, $row['latitude'], 0.0000001);
        $this->assertEqualsWithDelta(-46.6388234, $row['longitude'], 0.0000001);
        $this->assertSame(18, $row['accuracy']);
        $this->assertSame(72, $row['battery']);
        $this->assertSame('2026-01-15T14:30:00Z', $row['recordedAt']);
    }
}
