<?php

declare(strict_types=1);

namespace GuardKids\Database;

final class GuardianRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'guardians';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $rows = $this->findWhere(['email' => $email]);
        return $rows[0] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByWpUserId(int $wpUserId): ?array
    {
        $rows = $this->findWhere(['wp_user_id' => $wpUserId]);
        return $rows[0] ?? null;
    }

    public function countAdmins(): int
    {
        $rows = $this->findWhere(['role' => 'admin']);
        return count($rows);
    }

    /**
     * Lookup por hash do token de convite (plaintext nunca passa por aqui).
     *
     * @return array<string, mixed>|null
     */
    public function findByInviteTokenHash(string $hash): ?array
    {
        $rows = $this->findWhere(['invite_token' => $hash]);
        return $rows[0] ?? null;
    }

    /**
     * Marca convite consumido: status=active + zera token/expira + grava wp_user_id.
     */
    public function consumeInvite(int $id, int $wpUserId): bool
    {
        return $this->update($id, [
            'wp_user_id'        => $wpUserId,
            'status'            => 'active',
            'invite_token'      => null,
            'invite_expires_at' => null,
        ]);
    }
}
