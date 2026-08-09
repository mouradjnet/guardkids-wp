<?php

declare(strict_types=1);

namespace GuardKids\Database;

/**
 * Ledger de conclusão de aulas do Academy da criança (Onda 3). Anti-duplo por
 * UNIQUE (child_id, lesson_key): a aula é concluída de vez e o bônus creditado
 * uma única vez. Só tem created_at → insert próprio, sem o updated_at do base.
 */
final class ChildAcademyRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'academy_child_lessons';
    }

    public function existsFor(int $childId, string $lessonKey): bool
    {
        return $this->findWhere([
            'child_id'   => $childId,
            'lesson_key' => $lessonKey,
        ]) !== [];
    }

    public function record(int $childId, string $lessonKey, int $xp, int $coins): int
    {
        $ok = $this->db->insert($this->table(), [
            'child_id'   => $childId,
            'lesson_key' => $lessonKey,
            'xp'         => $xp,
            'coins'      => $coins,
            'created_at' => current_time('mysql', true),
        ]);
        return $ok === false ? 0 : (int) $this->db->insert_id;
    }

    /**
     * Chaves das aulas já concluídas por esta criança.
     *
     * @return list<string>
     */
    public function listCompleted(int $childId): array
    {
        $rows = $this->findWhere(['child_id' => $childId]);
        return array_values(array_map(
            static fn (array $r): string => (string) $r['lesson_key'],
            $rows,
        ));
    }
}
