<?php

declare(strict_types=1);

namespace GuardKids\Tests\Integration\Repository;

use GuardKids\Database\SafeZoneRepository;
use GuardKids\Tests\Integration\IntegrationTestCase;

/**
 * Valida SafeZoneRepository contra MySQL real.
 *
 * Repo só herda CRUD da base — foco aqui é o schema: default de
 * radius_meters, precisão DECIMAL(10,7) das coords, address nullable.
 */
final class SafeZoneRepositoryTest extends IntegrationTestCase
{
    public function test_default_radius_meters_is_100_when_omitted(): void
    {
        $repo = new SafeZoneRepository();
        $id   = $repo->insert([
            'name'      => 'Casa',
            'latitude'  => -23.5505200,
            'longitude' => -46.6333090,
        ]);

        $row = $repo->findById($id);
        $this->assertSame(100, (int) $row['radius_meters']);
    }

    public function test_explicit_radius_meters_is_persisted(): void
    {
        $repo = new SafeZoneRepository();
        $id   = $repo->insert([
            'name'          => 'Escola',
            'latitude'      => -23.5,
            'longitude'     => -46.6,
            'radius_meters' => 250,
        ]);

        $row = $repo->findById($id);
        $this->assertSame(250, (int) $row['radius_meters']);
    }

    public function test_decimal_10_7_precision_round_trip(): void
    {
        $repo = new SafeZoneRepository();
        $id   = $repo->insert([
            'name'      => 'Pediatra',
            'latitude'  => -23.5489121,
            'longitude' => -46.6388234,
        ]);

        $row = $repo->findById($id);
        $this->assertSame('-23.5489121', (string) $row['latitude']);
        $this->assertSame('-46.6388234', (string) $row['longitude']);
    }

    public function test_nullable_address_round_trip(): void
    {
        $repo = new SafeZoneRepository();
        $withAddress = $repo->insert([
            'name'      => 'Casa',
            'address'   => 'Rua Augusta, 100',
            'latitude'  => -23.5,
            'longitude' => -46.6,
        ]);
        $withoutAddress = $repo->insert([
            'name'      => 'Padaria',
            'latitude'  => -23.5,
            'longitude' => -46.6,
        ]);

        $this->assertSame('Rua Augusta, 100', $repo->findById($withAddress)['address']);
        $this->assertNull($repo->findById($withoutAddress)['address']);
    }

    public function test_full_crud_round_trip(): void
    {
        $repo = new SafeZoneRepository();
        $id   = $repo->insert([
            'name'          => 'Casa',
            'latitude'      => -23.5,
            'longitude'     => -46.6,
            'radius_meters' => 150,
        ]);
        $this->assertGreaterThan(0, $id);

        $ok = $repo->update($id, [
            'name'          => 'Casa nova',
            'radius_meters' => 200,
        ]);
        $this->assertTrue($ok);

        $row = $repo->findById($id);
        $this->assertSame('Casa nova', $row['name']);
        $this->assertSame(200, (int) $row['radius_meters']);
        $this->assertNotEmpty($row['updated_at']);

        $this->assertTrue($repo->delete($id));
        $this->assertNull($repo->findById($id));
    }

    public function test_findAll_orderBy_name_uses_indexed_column(): void
    {
        $repo = new SafeZoneRepository();
        $repo->insert(['name' => 'Casa', 'latitude' => -23.5, 'longitude' => -46.6]);
        $repo->insert(['name' => 'Avo', 'latitude' => -23.5, 'longitude' => -46.6]);
        $repo->insert(['name' => 'Escola', 'latitude' => -23.5, 'longitude' => -46.6]);

        $rows = $repo->findAll('name', 'ASC');
        $this->assertSame(['Avo', 'Casa', 'Escola'], array_column($rows, 'name'));
    }
}
