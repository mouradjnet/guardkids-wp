<?php

declare(strict_types=1);

namespace GuardKids\Database;

/**
 * Ledger de conclusão de missões (anti-duplo, UNIQUE por filho/missão/dia) e
 * leitura dos sinais que alimentam o MissionEvaluator. Só tem created_at →
 * insert próprio, sem o updated_at do base.
 */
final class MissionCompletionRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'mission_completions';
    }

    public function existsFor(int $childId, string $key, string $date): bool
    {
        return $this->findWhere([
            'child_id'        => $childId,
            'mission_key'     => $key,
            'completion_date' => $date,
        ]) !== [];
    }

    public function record(int $childId, string $key, string $date, int $xp, int $coins): int
    {
        $ok = $this->db->insert($this->table(), [
            'child_id'        => $childId,
            'mission_key'     => $key,
            'completion_date' => $date,
            'xp'              => $xp,
            'coins'           => $coins,
            'created_at'      => current_time('mysql', true),
        ]);
        return $ok === false ? 0 : (int) $this->db->insert_id;
    }

    public function countCompleted(int $childId): int
    {
        $sql = $this->db->prepare(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE child_id = %d',
            $childId,
        );
        return (int) $this->db->get_var($sql);
    }

    /**
     * Sinais do dia derivados dos dados existentes (progression_awards,
     * content_items, progression). Barato: awards já é 1 linha/conteúdo/dia.
     *
     * @return array{contentOpenedToday:int, categoriesToday:int, streakActiveToday:bool}
     */
    public function signalsFor(int $childId, string $date): array
    {
        $awards = $this->db->prefix . 'guardkids_progression_awards';
        $items  = $this->db->prefix . 'guardkids_content_items';
        $prog   = $this->db->prefix . 'guardkids_progression';

        $opened = (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$awards} WHERE child_id = %d AND award_date = %s",
            $childId,
            $date,
        ));

        $categories = (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(DISTINCT c.category_id) FROM {$awards} a "
            . "JOIN {$items} c ON a.content_id = c.id "
            . "WHERE a.child_id = %d AND a.award_date = %s AND c.category_id IS NOT NULL",
            $childId,
            $date,
        ));

        $lastDate = $this->db->get_var($this->db->prepare(
            "SELECT last_activity_date FROM {$prog} WHERE child_id = %d LIMIT 1",
            $childId,
        ));

        return [
            'contentOpenedToday' => $opened,
            'categoriesToday'    => $categories,
            'streakActiveToday'  => $lastDate === $date,
        ];
    }
}
