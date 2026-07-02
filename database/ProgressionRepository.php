<?php

declare(strict_types=1);

namespace GuardKids\Database;

/**
 * Carteira/progressão por filho (xp, coins, streak). Tem created_at/updated_at,
 * então o insert/update da base servem.
 */
final class ProgressionRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'progression';
    }

    /** @return array<string, mixed>|null */
    public function findByChild(int $childId): ?array
    {
        $rows = $this->findWhere(['child_id' => $childId]);
        return $rows[0] ?? null;
    }

    /**
     * Garante a carteira do filho (cria zerada se não existir).
     *
     * @return array<string, mixed>
     */
    public function ensure(int $childId): array
    {
        $row = $this->findByChild($childId);
        if ($row !== null) {
            return $row;
        }
        $this->insert([
            'child_id'           => $childId,
            'xp'                 => 0,
            'coins'              => 0,
            'streak_days'        => 0,
            'last_activity_date' => null,
        ]);
        return $this->findByChild($childId) ?? [
            'id'                 => 0,
            'child_id'           => $childId,
            'xp'                 => 0,
            'coins'              => 0,
            'streak_days'        => 0,
            'last_activity_date' => null,
        ];
    }

    /**
     * Soma xp/coins e grava streak + data da última atividade.
     */
    public function apply(int $childId, int $xpDelta, int $coinsDelta, int $streakDays, string $lastActivityDate): void
    {
        $row = $this->ensure($childId);
        $this->update((int) $row['id'], [
            'xp'                 => (int) $row['xp'] + $xpDelta,
            'coins'              => (int) $row['coins'] + $coinsDelta,
            'streak_days'        => $streakDays,
            'last_activity_date' => $lastActivityDate,
        ]);
    }
}
