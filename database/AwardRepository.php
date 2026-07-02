<?php

declare(strict_types=1);

namespace GuardKids\Database;

/**
 * Ledger de ganhos (anti-farm). Só tem created_at → insert próprio, sem o
 * updated_at do base.
 */
final class AwardRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'progression_awards';
    }

    public function existsFor(int $childId, int $contentId, string $date): bool
    {
        return $this->findWhere([
            'child_id'   => $childId,
            'content_id' => $contentId,
            'award_date' => $date,
        ]) !== [];
    }

    public function record(int $childId, int $contentId, string $date, int $xp, int $coins): int
    {
        $ok = $this->db->insert($this->table(), [
            'child_id'   => $childId,
            'content_id' => $contentId,
            'award_date' => $date,
            'xp'         => $xp,
            'coins'      => $coins,
            'created_at' => current_time('mysql', true),
        ]);
        return $ok === false ? 0 : (int) $this->db->insert_id;
    }
}
