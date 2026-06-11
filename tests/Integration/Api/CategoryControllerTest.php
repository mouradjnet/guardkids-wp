<?php

declare(strict_types=1);

namespace GuardKids\Tests\Integration\Api;

use GuardKids\Api\Controllers\CategoryController;
use GuardKids\Database\CategoryRepository;
use GuardKids\License\Gate;
use GuardKids\Tests\Integration\ControllerIntegrationTestCase;
use GuardKids\Tests\Support\AlwaysAllowGate;

final class CategoryControllerTest extends ControllerIntegrationTestCase
{
    private function freeController(): CategoryController
    {
        return new CategoryController(new Gate());
    }

    private function premiumController(): CategoryController
    {
        return new CategoryController(new AlwaysAllowGate());
    }

    private function seedSampleCategories(): void
    {
        (new CategoryRepository())->seed([
            ['slug' => 'adult', 'name' => 'Adulto', 'blocked' => 1],
            ['slug' => 'games', 'name' => 'Jogos', 'blocked' => 0],
        ]);
    }

    public function test_index_returns_empty_when_table_empty(): void
    {
        $resp = $this->freeController()->index();
        $this->assertResponseStatus(200, $resp);
        $this->assertSame([], $this->dataOf($resp));
    }

    public function test_index_returns_camel_case_shape_with_blocked_as_bool(): void
    {
        $this->seedSampleCategories();
        $data = $this->dataOf($this->freeController()->index());

        $this->assertCount(2, $data);
        $bySlug = array_column($data, null, 'slug');
        $this->assertTrue($bySlug['adult']['blocked']);
        $this->assertFalse($bySlug['games']['blocked']);
        $this->assertSame('Adulto', $bySlug['adult']['name']);
    }

    public function test_update_blocked_on_free_plan(): void
    {
        $this->seedSampleCategories();
        $cat = (new CategoryRepository())->findAll()[0];

        $resp = $this->freeController()->update($this->makeRequest('PUT', "/categories/{$cat['id']}", [
            'id'      => $cat['id'],
            'blocked' => false,
        ]));
        $this->assertWpError('plan_limit', $resp);
        $this->assertResponseStatus(402, $resp);
    }

    public function test_update_returns_404_when_id_missing(): void
    {
        $resp = $this->premiumController()->update($this->makeRequest('PUT', '/categories/999', [
            'id'      => 999,
            'blocked' => true,
        ]));
        $this->assertWpError('not_found', $resp);
    }

    public function test_update_requires_blocked_param(): void
    {
        $this->seedSampleCategories();
        $cat = (new CategoryRepository())->findAll()[0];

        $resp = $this->premiumController()->update($this->makeRequest('PUT', "/categories/{$cat['id']}", [
            'id' => $cat['id'],
        ]));
        $this->assertWpError('invalid_payload', $resp);
        $this->assertResponseStatus(422, $resp);
    }

    public function test_update_toggles_blocked_true_to_false(): void
    {
        $this->seedSampleCategories();
        $cat = (new CategoryRepository())->findAll()[0]; // 'adult' com blocked=1

        $resp = $this->premiumController()->update($this->makeRequest('PUT', "/categories/{$cat['id']}", [
            'id'      => $cat['id'],
            'blocked' => false,
        ]));
        $this->assertResponseStatus(200, $resp);
        $this->assertFalse($this->dataOf($resp)['blocked']);

        // Confirma persistência no banco
        $row = (new CategoryRepository())->findById($cat['id']);
        $this->assertSame(0, (int) $row['blocked']);
    }
}
