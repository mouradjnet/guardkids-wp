<?php

declare(strict_types=1);

namespace GuardKids\Database;

/**
 * Subscriptions de Web Push dos guardiões. Paralela à PushSubscriptionRepository
 * (que serve a criança): modelos de auth diferentes — token de dispositivo lá,
 * usuário WP aqui — então tabelas diferentes.
 *
 * Repo burro de propósito: quem PODE receber push é decisão de autorização e
 * mora em GuardianAuth::isActiveGuardian(), não aqui.
 */
final class GuardianPushSubscriptionRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'guardian_push_subscriptions';
    }

    public function upsertByEndpoint(int $wpUserId, string $endpoint, string $p256dh, string $auth): void
    {
        $existing = $this->findWhere(['endpoint' => $endpoint]);
        if ($existing !== []) {
            // db->update direto: Repository::update grava updated_at, coluna
            // que esta tabela não tem.
            $this->db->update(
                $this->table(),
                ['wp_user_id' => $wpUserId, 'p256dh' => $p256dh, 'auth' => $auth],
                ['id' => (int) $existing[0]['id']],
            );
            return;
        }

        // db->insert direto, mesmo motivo.
        $this->db->insert($this->table(), [
            'wp_user_id' => $wpUserId,
            'endpoint'   => $endpoint,
            'p256dh'     => $p256dh,
            'auth'       => $auth,
            'created_at' => current_time('mysql', true),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByUser(int $wpUserId): array
    {
        return $this->findWhere(['wp_user_id' => $wpUserId]);
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
