<?php

declare(strict_types=1);

namespace GuardKids\Database;

/**
 * Ledger de desbloqueio de medalhas (permanente, UNIQUE por filho/medalha) e
 * leitura dos sinais acumulados que alimentam o MedalEvaluator. Só tem
 * created_at → insert próprio, sem o updated_at do base.
 */
final class MedalUnlockRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'medal_unlocks';
    }

    public function existsFor(int $childId, string $key): bool
    {
        return $this->findWhere([
            'child_id'  => $childId,
            'medal_key' => $key,
        ]) !== [];
    }

    public function record(int $childId, string $key, string $date, int $xp, int $coins): int
    {
        $ok = $this->db->insert($this->table(), [
            'child_id'      => $childId,
            'medal_key'     => $key,
            'unlocked_date' => $date,
            'xp'            => $xp,
            'coins'         => $coins,
            'created_at'    => current_time('mysql', true),
        ]);
        return $ok === false ? 0 : (int) $this->db->insert_id;
    }

    /**
     * @return array<int, string>
     */
    public function unlockedKeys(int $childId): array
    {
        $sql = $this->db->prepare(
            'SELECT medal_key FROM ' . $this->table() . ' WHERE child_id = %d',
            $childId,
        );
        $rows = $this->db->get_results($sql, ARRAY_A);
        return is_array($rows)
            ? array_map(static fn ($r) => (string) $r['medal_key'], $rows)
            : [];
    }

    public function countUnlocked(int $childId): int
    {
        $sql = $this->db->prepare(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE child_id = %d',
            $childId,
        );
        return (int) $this->db->get_var($sql);
    }

    /**
     * Sinais acumulados (all-time) derivados dos dados existentes.
     *
     * @return array{totalContentOpened:int, totalMissionsCompleted:int, distinctCategoriesAllTime:int}
     */
    public function signalsFor(int $childId): array
    {
        $awards   = $this->db->prefix . 'guardkids_progression_awards';
        $items    = $this->db->prefix . 'guardkids_content_items';
        $missions = $this->db->prefix . 'guardkids_mission_completions';

        $opened = (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$awards} WHERE child_id = %d",
            $childId,
        ));

        $missionsDone = (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$missions} WHERE child_id = %d",
            $childId,
        ));

        $categories = (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(DISTINCT c.category_id) FROM {$awards} a "
            . "JOIN {$items} c ON a.content_id = c.id "
            . "WHERE a.child_id = %d AND c.category_id IS NOT NULL",
            $childId,
        ));

        return [
            'totalContentOpened'        => $opened,
            'totalMissionsCompleted'    => $missionsDone,
            'distinctCategoriesAllTime' => $categories,
        ];
    }
}
