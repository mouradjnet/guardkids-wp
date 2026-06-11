<?php

declare(strict_types=1);

namespace GuardKids\Tests\Integration\Repository;

use GuardKids\Database\RequestRepository;
use GuardKids\Tests\Integration\IntegrationTestCase;

/**
 * Valida o ciclo de status do RequestRepository contra MySQL real.
 *
 * Foco no que é específico ao Request (CRUD base já é testado em
 * ChildRepositoryTest): findByStatus, findByChild, decide() e o default
 * 'pending' no schema.
 */
final class RequestRepositoryTest extends IntegrationTestCase
{
    public function test_default_status_is_pending_when_omitted_on_insert(): void
    {
        $repo = new RequestRepository();
        $id   = $repo->insert([
            'child_id'    => 1,
            'kind'        => 'site',
            'description' => 'youtube.com',
        ]);

        $row = $repo->findById($id);
        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['decided_at']);
        $this->assertNull($row['decided_by']);
    }

    public function test_findByStatus_returns_only_matching_status(): void
    {
        $repo = new RequestRepository();
        $repo->insert(['child_id' => 1, 'kind' => 'site', 'status' => 'pending']);
        $repo->insert(['child_id' => 1, 'kind' => 'site', 'status' => 'approved']);
        $repo->insert(['child_id' => 2, 'kind' => 'time', 'status' => 'pending']);

        $pending  = $repo->findByStatus('pending');
        $approved = $repo->findByStatus('approved');

        $this->assertCount(2, $pending);
        $this->assertCount(1, $approved);
        $this->assertSame('approved', $approved[0]['status']);
    }

    public function test_findByChild_returns_only_matching_child(): void
    {
        $repo = new RequestRepository();
        $repo->insert(['child_id' => 1, 'kind' => 'site']);
        $repo->insert(['child_id' => 1, 'kind' => 'time']);
        $repo->insert(['child_id' => 2, 'kind' => 'site']);

        $child1 = $repo->findByChild(1);
        $child2 = $repo->findByChild(2);

        $this->assertCount(2, $child1);
        $this->assertCount(1, $child2);
        foreach ($child1 as $row) {
            $this->assertSame(1, (int) $row['child_id']);
        }
    }

    public function test_findByStatus_orders_by_created_at_desc(): void
    {
        $repo = new RequestRepository();
        // Forçando created_at distintos pra a ordenação ter o que ordenar
        $first  = $repo->insert(['child_id' => 1, 'kind' => 'site']);
        $this->db->query("UPDATE `{$this->db->prefix}guardkids_requests` SET created_at = '2026-01-01 10:00:00' WHERE id = {$first}");

        $second = $repo->insert(['child_id' => 1, 'kind' => 'site']);
        $this->db->query("UPDATE `{$this->db->prefix}guardkids_requests` SET created_at = '2026-01-02 10:00:00' WHERE id = {$second}");

        $third  = $repo->insert(['child_id' => 1, 'kind' => 'site']);
        $this->db->query("UPDATE `{$this->db->prefix}guardkids_requests` SET created_at = '2026-01-03 10:00:00' WHERE id = {$third}");

        $rows = $repo->findByStatus('pending');
        $this->assertCount(3, $rows);
        $this->assertSame($third, (int) $rows[0]['id']);
        $this->assertSame($second, (int) $rows[1]['id']);
        $this->assertSame($first, (int) $rows[2]['id']);
    }

    public function test_decide_sets_status_decided_at_and_decided_by(): void
    {
        $repo = new RequestRepository();
        $id   = $repo->insert(['child_id' => 1, 'kind' => 'site']);

        $ok = $repo->decide($id, 'approved', 42);
        $this->assertTrue($ok);

        $row = $repo->findById($id);
        $this->assertSame('approved', $row['status']);
        $this->assertSame(42, (int) $row['decided_by']);
        $this->assertNotNull($row['decided_at']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            (string) $row['decided_at'],
        );
    }

    public function test_decide_denied_persists_decision_too(): void
    {
        $repo = new RequestRepository();
        $id   = $repo->insert(['child_id' => 1, 'kind' => 'time']);

        $repo->decide($id, 'denied', 7);

        $row = $repo->findById($id);
        $this->assertSame('denied', $row['status']);
        $this->assertSame(7, (int) $row['decided_by']);
        $this->assertNotNull($row['decided_at']);
    }

    public function test_nullable_text_fields_round_trip(): void
    {
        $repo = new RequestRepository();
        $id   = $repo->insert([
            'child_id'    => 1,
            'kind'        => 'site',
            'description' => 'discord.com',
            'highlight'   => 'novo amigo',
            'reason'      => 'Quero conversar com a Ana que conheci na escola',
        ]);

        $row = $repo->findById($id);
        $this->assertSame('discord.com', $row['description']);
        $this->assertSame('novo amigo', $row['highlight']);
        $this->assertSame('Quero conversar com a Ana que conheci na escola', $row['reason']);
    }
}
