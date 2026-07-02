<?php

declare(strict_types=1);

namespace GuardKids\Database;

final class PushSubscriptionRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'push_subscriptions';
    }

    public function upsertByEndpoint(int $childId, string $endpoint, string $p256dh, string $auth): void
    {
        $existing = $this->findWhere(['endpoint' => $endpoint]);
        if ($existing !== []) {
            $this->db->update(
                $this->table(),
                ['child_id' => $childId, 'p256dh' => $p256dh, 'auth' => $auth],
                ['id' => (int) $existing[0]['id']],
            );
            return;
        }
        $this->db->insert($this->table(), [
            'child_id'   => $childId,
            'endpoint'   => $endpoint,
            'p256dh'     => $p256dh,
            'auth'       => $auth,
            'created_at' => current_time('mysql', true),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByChild(int $childId): array
    {
        return $this->findWhere(['child_id' => $childId]);
    }

    public function deleteByEndpoint(string $endpoint): void
    {
        $sql = $this->db->prepare(
            'DELETE FROM ' . $this->table() . ' WHERE endpoint = %s',
            $endpoint,
        );
        $this->db->query($sql);
    }
}
