<?php

declare(strict_types=1);

namespace GuardKids\Database;

/**
 * Pedidos de resgate de recompensa (espelha RequestRepository). O
 * enriquecimento com título da recompensa / nome do filho é feito no
 * controller (evita JOIN, mantém o repo simples e testável).
 */
final class RewardRedemptionRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'reward_redemptions';
    }

    public function create(int $childId, int $rewardId, int $cost): int
    {
        return $this->insert([
            'child_id'   => $childId,
            'reward_id'  => $rewardId,
            'cost_coins' => $cost,
            'status'     => 'pending',
        ]);
    }

    public function hasPendingFor(int $childId, int $rewardId): bool
    {
        return $this->findWhere([
            'child_id'  => $childId,
            'reward_id' => $rewardId,
            'status'    => 'pending',
        ]) !== [];
    }

    public function decide(int $id, string $status, int $userId): bool
    {
        return $this->update($id, [
            'status'     => $status,
            'decided_at' => current_time('mysql', true),
            'decided_by' => $userId,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByChild(int $childId): array
    {
        return $this->findWhere(['child_id' => $childId], 'created_at', 'DESC');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByStatus(string $status): array
    {
        return $this->findWhere(['status' => $status], 'created_at', 'DESC');
    }
}
