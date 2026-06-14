<?php

declare(strict_types=1);

namespace GuardKids\Tests\Integration\Api;

use GuardKids\Api\Controllers\ChildSelfController;
use GuardKids\Auth\ChildAuth;
use GuardKids\Database\ChildRepository;
use GuardKids\Database\SettingsRepository;
use GuardKids\Tests\Integration\ControllerIntegrationTestCase;
use WP_REST_Request;

/**
 * Integration tests do ChildSelfController contra MySQL real.
 *
 * Valida o ciclo completo do PWA infantil:
 *  - Auth por X-GuardKids-Token (resolve childId via hash em settings)
 *  - /me retorna child + schedule
 *  - /requests CRUD pela perspectiva da criança (isolation por child)
 *  - /events ingest com validação de type/duration/domain
 *  - /location 403 quando feature está desligada nas settings
 */
final class ChildSelfControllerTest extends ControllerIntegrationTestCase
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

    private function authedRequest(string $method, string $route, string $token, array $params = []): WP_REST_Request
    {
        $req = $this->makeRequest($method, $route, $params);
        $req->set_header('X-GuardKids-Token', $token);
        return $req;
    }

    public function test_me_returns_401_without_token(): void
    {
        $resp = (new ChildSelfController())->me($this->makeRequest('GET', '/child/me'));
        $this->assertWpError('child_auth_required', $resp);
        $this->assertResponseStatus(401, $resp);
    }

    public function test_me_returns_401_with_malformed_token(): void
    {
        $resp = (new ChildSelfController())->me($this->authedRequest('GET', '/child/me', 'too-short'));
        $this->assertWpError('child_auth_required', $resp);
    }

    public function test_me_returns_401_with_valid_format_but_unknown_token(): void
    {
        $bogus = str_repeat('a', 64);
        $resp  = (new ChildSelfController())->me($this->authedRequest('GET', '/child/me', $bogus));
        $this->assertWpError('child_auth_required', $resp);
    }

    public function test_me_returns_child_plus_schedule_when_authed(): void
    {
        $child = $this->pairedChild('Lucas');
        $resp  = (new ChildSelfController())->me($this->authedRequest('GET', '/child/me', $child['token']));
        $this->assertResponseStatus(200, $resp);

        $data = $this->dataOf($resp);
        $this->assertSame($child['id'], $data['id']);
        $this->assertSame('Lucas', $data['name']);
        $this->assertArrayHasKey('schedule', $data);
        $this->assertIsArray($data['schedule']);
    }

    public function test_me_returns_404_when_child_deleted_but_token_persists(): void
    {
        $child = $this->pairedChild();
        (new ChildRepository())->delete($child['id']);

        $resp = (new ChildSelfController())->me($this->authedRequest('GET', '/child/me', $child['token']));
        $this->assertWpError('not_found', $resp);
        $this->assertResponseStatus(404, $resp);
    }

    public function test_requestsCreate_returns_401_without_token(): void
    {
        $resp = (new ChildSelfController())->requestsCreate($this->makeRequest('POST', '/child/requests', [
            'kind' => 'extra_time',
        ]));
        $this->assertWpError('child_auth_required', $resp);
    }

    public function test_requestsCreate_rejects_empty_kind(): void
    {
        $child = $this->pairedChild();
        $resp  = (new ChildSelfController())->requestsCreate(
            $this->authedRequest('POST', '/child/requests', $child['token'], ['kind' => ''])
        );
        $this->assertWpError('invalid_payload', $resp);
        $this->assertResponseStatus(422, $resp);
    }

    public function test_requestsCreate_persists_with_pending_status_and_child_id_from_token(): void
    {
        $child = $this->pairedChild();
        $resp  = (new ChildSelfController())->requestsCreate(
            $this->authedRequest('POST', '/child/requests', $child['token'], [
                'kind'        => 'extra_time',
                'description' => '+30 min',
                'reason'      => 'Quero terminar o jogo',
            ])
        );
        $this->assertResponseStatus(201, $resp);

        $data = $this->dataOf($resp);
        $this->assertSame($child['id'], $data['childId']);
        $this->assertSame('extra_time', $data['kind']);
        $this->assertSame('pending', $data['status']);
        $this->assertSame('+30 min', $data['description']);
        $this->assertSame('Quero terminar o jogo', $data['reason']);
    }

    public function test_requestsIndex_isolates_per_child(): void
    {
        $alice = $this->pairedChild('Alice');
        $bob   = $this->pairedChild('Bob');

        $ctrl = new ChildSelfController();
        $ctrl->requestsCreate($this->authedRequest('POST', '/child/requests', $alice['token'], ['kind' => 'extra_time']));
        $ctrl->requestsCreate($this->authedRequest('POST', '/child/requests', $alice['token'], ['kind' => 'unblock_site']));
        $ctrl->requestsCreate($this->authedRequest('POST', '/child/requests', $bob['token'], ['kind' => 'other']));

        $aliceList = $this->dataOf($ctrl->requestsIndex($this->authedRequest('GET', '/child/requests', $alice['token'])));
        $bobList   = $this->dataOf($ctrl->requestsIndex($this->authedRequest('GET', '/child/requests', $bob['token'])));

        $this->assertCount(2, $aliceList);
        $this->assertCount(1, $bobList);
        foreach ($aliceList as $r) {
            $this->assertSame($alice['id'], $r['childId']);
        }
        $this->assertSame($bob['id'], $bobList[0]['childId']);
        $this->assertSame('other', $bobList[0]['kind']);
    }

    public function test_eventsCreate_rejects_invalid_type(): void
    {
        $child = $this->pairedChild();
        $resp  = (new ChildSelfController())->eventsCreate(
            $this->authedRequest('POST', '/child/events', $child['token'], [
                'type' => 'unknown_event',
            ])
        );
        $this->assertWpError('invalid_payload', $resp);
    }

    public function test_eventsCreate_rejects_duration_out_of_range(): void
    {
        $child = $this->pairedChild();
        $resp  = (new ChildSelfController())->eventsCreate(
            $this->authedRequest('POST', '/child/events', $child['token'], [
                'type'             => 'heartbeat',
                'duration_seconds' => 99999,
            ])
        );
        $this->assertWpError('invalid_payload', $resp);
    }

    public function test_eventsCreate_site_open_requires_domain(): void
    {
        $child = $this->pairedChild();
        $resp  = (new ChildSelfController())->eventsCreate(
            $this->authedRequest('POST', '/child/events', $child['token'], [
                'type'             => 'site_open',
                'duration_seconds' => 0,
            ])
        );
        $this->assertWpError('invalid_payload', $resp);
    }

    public function test_eventsCreate_heartbeat_persists_and_returns_201(): void
    {
        $child = $this->pairedChild();
        $resp  = (new ChildSelfController())->eventsCreate(
            $this->authedRequest('POST', '/child/events', $child['token'], [
                'type'             => 'heartbeat',
                'duration_seconds' => 60,
            ])
        );
        $this->assertResponseStatus(201, $resp);
        $data = $this->dataOf($resp);
        $this->assertGreaterThan(0, $data['id']);

        $count = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM `{$this->db->prefix}guardkids_usage_events` WHERE child_id = {$child['id']} AND type = 'heartbeat'"
        );
        $this->assertSame(1, $count);
    }

    public function test_eventsCreate_site_open_lowercases_domain(): void
    {
        $child = $this->pairedChild();
        $resp  = (new ChildSelfController())->eventsCreate(
            $this->authedRequest('POST', '/child/events', $child['token'], [
                'type'             => 'site_open',
                'domain'           => 'YouTube.COM',
                'duration_seconds' => 0,
            ])
        );
        $this->assertResponseStatus(201, $resp);

        $stored = (string) $this->db->get_var(
            "SELECT domain FROM `{$this->db->prefix}guardkids_usage_events` WHERE child_id = {$child['id']} AND type = 'site_open'"
        );
        $this->assertSame('youtube.com', $stored);
    }

    public function test_eventsCreate_schedule_block_requires_valid_detail(): void
    {
        $child = $this->pairedChild();
        $resp  = (new ChildSelfController())->eventsCreate(
            $this->authedRequest('POST', '/child/events', $child['token'], [
                'type'   => 'schedule_block',
                'detail' => 'qualquer_coisa',
            ])
        );
        $this->assertWpError('invalid_payload', $resp);
    }

    public function test_eventsCreate_schedule_block_persists_detail(): void
    {
        $child = $this->pairedChild();
        $resp  = (new ChildSelfController())->eventsCreate(
            $this->authedRequest('POST', '/child/events', $child['token'], [
                'type'   => 'schedule_block',
                'detail' => 'bedtime',
            ])
        );
        $this->assertResponseStatus(201, $resp);

        $stored = (string) $this->db->get_var(
            "SELECT detail FROM `{$this->db->prefix}guardkids_usage_events` WHERE child_id = {$child['id']} AND type = 'schedule_block'"
        );
        $this->assertSame('bedtime', $stored);
    }

    public function test_reportLocation_returns_403_when_feature_disabled(): void
    {
        $child = $this->pairedChild();
        // settings.location_enabled não setado = fail-closed

        $resp = (new ChildSelfController())->reportLocation(
            $this->authedRequest('POST', '/child/location', $child['token'], [
                'latitude'  => -23.5,
                'longitude' => -46.6,
            ])
        );
        $this->assertWpError('location_disabled', $resp);
        $this->assertResponseStatus(403, $resp);
    }

    public function test_reportLocation_persists_when_feature_enabled(): void
    {
        (new SettingsRepository())->set('location_enabled', true);
        $child = $this->pairedChild();

        $resp = (new ChildSelfController())->reportLocation(
            $this->authedRequest('POST', '/child/location', $child['token'], [
                'latitude'  => -23.5489121,
                'longitude' => -46.6388234,
                'accuracy'  => 18,
                'battery'   => 72,
            ])
        );
        $this->assertResponseStatus(201, $resp);

        $row = $this->db->get_row(
            "SELECT * FROM `{$this->db->prefix}guardkids_locations` WHERE child_id = {$child['id']}",
            'ARRAY_A',
        );
        $this->assertSame('-23.5489121', (string) $row['latitude']);
        $this->assertSame('-46.6388234', (string) $row['longitude']);
        $this->assertSame(18, (int) $row['accuracy']);
        $this->assertSame(72, (int) $row['battery']);
    }

    public function test_reportLocation_returns_401_when_feature_on_but_token_missing(): void
    {
        (new SettingsRepository())->set('location_enabled', true);

        // Sem header de token — controller checa feature gate primeiro, depois token.
        $resp = (new ChildSelfController())->reportLocation($this->makeRequest('POST', '/child/location', [
            'latitude'  => -23.5,
            'longitude' => -46.6,
        ]));
        $this->assertWpError('child_auth_required', $resp);
    }
}
