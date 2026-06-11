<?php

declare(strict_types=1);

namespace GuardKids\Tests\Integration\Api;

use GuardKids\Api\Controllers\SettingsController;
use GuardKids\Database\SettingsRepository;
use GuardKids\Tests\Integration\ControllerIntegrationTestCase;

final class SettingsControllerTest extends ControllerIntegrationTestCase
{
    public function test_index_returns_empty_object_when_no_settings(): void
    {
        $resp = (new SettingsController())->index();
        $this->assertResponseStatus(200, $resp);
        $this->assertSame([], $resp->get_data());
    }

    public function test_index_returns_decoded_assoc_of_existing_settings(): void
    {
        $repo = new SettingsRepository();
        $repo->set('upgrade_url', 'https://example.com/upgrade');
        $repo->set('feature_flags', ['premium' => true]);

        $data = $this->dataOf((new SettingsController())->index());

        $this->assertSame('https://example.com/upgrade', $data['upgrade_url']);
        $this->assertSame(['premium' => true], $data['feature_flags']);
    }

    public function test_update_rejects_missing_body(): void
    {
        $resp = (new SettingsController())->update($this->makeRequest('PUT', '/settings'));
        $this->assertWpError('invalid_payload', $resp);
        $this->assertResponseStatus(422, $resp);
    }

    public function test_update_rejects_empty_body(): void
    {
        $req = $this->makeRequest('PUT', '/settings');
        $req->set_json_params([]);
        $resp = (new SettingsController())->update($req);
        $this->assertWpError('invalid_payload', $resp);
    }

    public function test_update_upserts_multiple_keys_in_one_request(): void
    {
        $req = $this->makeRequest('PUT', '/settings');
        $req->set_json_params([
            'upgrade_url'      => 'https://example.com/x',
            'location_enabled' => true,
            'feature_flags'    => ['premium' => false, 'beta' => true],
        ]);

        $resp = (new SettingsController())->update($req);
        $this->assertResponseStatus(200, $resp);

        $data = $this->dataOf($resp);
        $this->assertSame('https://example.com/x', $data['upgrade_url']);
        $this->assertTrue($data['location_enabled']);
        $this->assertSame(['premium' => false, 'beta' => true], $data['feature_flags']);
    }

    public function test_update_preserves_existing_keys_not_in_body(): void
    {
        (new SettingsRepository())->set('upgrade_url', 'https://existing.com');

        $req = $this->makeRequest('PUT', '/settings');
        $req->set_json_params(['location_enabled' => true]);

        $resp = (new SettingsController())->update($req);
        $data = $this->dataOf($resp);

        $this->assertSame('https://existing.com', $data['upgrade_url']);
        $this->assertTrue($data['location_enabled']);
    }

    public function test_update_ignores_non_string_keys(): void
    {
        $req = $this->makeRequest('PUT', '/settings');
        $req->set_json_params([
            0          => 'should-be-ignored',
            ''         => 'should-be-ignored-too',
            'valid_key' => 'kept',
        ]);

        $resp = (new SettingsController())->update($req);
        $data = $this->dataOf($resp);

        $this->assertCount(1, $data);
        $this->assertSame('kept', $data['valid_key']);
    }
}
