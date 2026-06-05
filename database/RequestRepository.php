<?php

declare(strict_types=1);

namespace GuardKids\Database;

final class RequestRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'requests';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByStatus(string $status): array
    {
        return $this->findWhere(['status' => $status], 'created_at', 'DESC');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByChild(int $childId): array
    {
        return $this->findWhere(['child_id' => $childId], 'created_at', 'DESC');
    }

    public function decide(int $id, string $decision, int $userId): bool
    {
        return $this->update($id, [
            'status'     => $decision,
            'decided_at' => current_time('mysql', true),
            'decided_by' => $userId,
        ]);
    }
}
