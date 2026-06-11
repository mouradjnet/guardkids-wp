<?php

declare(strict_types=1);

namespace GuardKids\Tests\Integration\Api;

use GuardKids\Api\Controllers\SiteController;
use GuardKids\License\Gate;
use GuardKids\Tests\Integration\ControllerIntegrationTestCase;
use GuardKids\Tests\Support\AlwaysAllowGate;

final class SiteControllerTest extends ControllerIntegrationTestCase
{
    private function freeController(): SiteController
    {
        return new SiteController(new Gate());
    }

    private function premiumController(): SiteController
    {
        return new SiteController(new AlwaysAllowGate());
    }

    public function test_index_returns_all_when_list_param_absent(): void
    {
        $ctrl = $this->premiumController();
        $ctrl->create($this->makeRequest('POST', '/sites', ['domain' => 'a.com', 'list_type' => 'whitelist']));
        $ctrl->create($this->makeRequest('POST', '/sites', ['domain' => 'b.com', 'list_type' => 'blacklist']));

        $data = $this->dataOf($ctrl->index($this->makeRequest('GET', '/sites')));
        $this->assertCount(2, $data);
    }

    public function test_index_filters_by_list_param(): void
    {
        $ctrl = $this->premiumController();
        $ctrl->create($this->makeRequest('POST', '/sites', ['domain' => 'a.com', 'list_type' => 'whitelist']));
        $ctrl->create($this->makeRequest('POST', '/sites', ['domain' => 'b.com', 'list_type' => 'whitelist']));
        $ctrl->create($this->makeRequest('POST', '/sites', ['domain' => 'c.com', 'list_type' => 'blacklist']));

        $whitelist = $this->dataOf($ctrl->index($this->makeRequest('GET', '/sites', ['list' => 'whitelist'])));
        $blacklist = $this->dataOf($ctrl->index($this->makeRequest('GET', '/sites', ['list' => 'blacklist'])));

        $this->assertCount(2, $whitelist);
        $this->assertCount(1, $blacklist);
    }

    public function test_create_rejects_empty_domain(): void
    {
        $resp = $this->premiumController()->create($this->makeRequest('POST', '/sites', ['domain' => '']));
        $this->assertWpError('invalid_payload', $resp);
        $this->assertResponseStatus(422, $resp);
    }

    public function test_create_whitelist_blocked_on_free_plan(): void
    {
        $resp = $this->freeController()->create($this->makeRequest('POST', '/sites', [
            'domain'    => 'kids-only.com',
            'list_type' => 'whitelist',
        ]));
        $this->assertWpError('plan_limit', $resp);
        $this->assertResponseStatus(402, $resp);
    }

    public function test_create_blacklist_allowed_on_free_plan(): void
    {
        $resp = $this->freeController()->create($this->makeRequest('POST', '/sites', [
            'domain'    => 'adultos.com',
            'list_type' => 'blacklist',
        ]));
        $this->assertResponseStatus(201, $resp);
        $this->assertSame('adultos.com', $this->dataOf($resp)['domain']);
        $this->assertSame('blacklist', $this->dataOf($resp)['listType']);
    }

    public function test_create_persists_applies_to_as_json(): void
    {
        $resp = $this->premiumController()->create($this->makeRequest('POST', '/sites', [
            'domain'     => 'youtube.com',
            'list_type'  => 'whitelist',
            'applies_to' => [1, 2, 3],
        ]));
        $this->assertResponseStatus(201, $resp);
        $this->assertSame([1, 2, 3], $this->dataOf($resp)['appliesTo']);
    }

    public function test_destroy_removes_site_and_returns_id(): void
    {
        $ctrl    = $this->premiumController();
        $created = $ctrl->create($this->makeRequest('POST', '/sites', [
            'domain'    => 'tiktok.com',
            'list_type' => 'blacklist',
        ]));
        $id = $this->dataOf($created)['id'];

        $resp = $ctrl->destroy($this->makeRequest('DELETE', "/sites/{$id}", ['id' => $id]));
        $this->assertResponseStatus(200, $resp);
        $this->assertSame(['deleted' => true, 'id' => $id], $this->dataOf($resp));

        $count = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM `{$this->db->prefix}guardkids_sites`"
        );
        $this->assertSame(0, $count);
    }

    public function test_index_returns_camel_case_shape(): void
    {
        $ctrl = $this->premiumController();
        $ctrl->create($this->makeRequest('POST', '/sites', [
            'domain'    => 'wiki.org',
            'category'  => 'education',
            'list_type' => 'whitelist',
        ]));

        $data = $this->dataOf($ctrl->index($this->makeRequest('GET', '/sites')));
        $this->assertSame('wiki.org', $data[0]['domain']);
        $this->assertSame('education', $data[0]['category']);
        $this->assertSame('whitelist', $data[0]['listType']);
        $this->assertSame([], $data[0]['appliesTo']);
    }
}
