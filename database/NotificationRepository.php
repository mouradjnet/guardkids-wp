<?php

declare(strict_types=1);

namespace GuardKids\Database;

final class NotificationRepository extends Repository
{
    private const MAX_LIMIT = 50;

    protected function tableSuffix(): string
    {
        return 'notifications';
    }

    /**
     * Insere direto (a tabela não tem updated_at; created_at em UTC).
     *
     * @param array{child_id:int,type:string,title:string,body?:?string,dedup_key?:?string} $data
     */
    public function create(array $data): int
    {
        $ok = $this->db->insert($this->table(), [
            'child_id'   => (int) $data['child_id'],
            'type'       => (string) $data['type'],
            'title'      => (string) $data['title'],
            'body'       => $data['body'] ?? null,
            'dedup_key'  => $data['dedup_key'] ?? null,
            'read_at'    => null,
            'created_at' => current_time('mysql', true),
        ]);
        return $ok === false ? 0 : (int) $this->db->insert_id;
    }

    /**
     * Cria só se não existir linha com (child_id, dedup_key). Idempotente.
     *
     * @param array{type:string,title:string,body?:?string} $data
     */
    public function createIfAbsent(int $childId, string $dedupKey, array $data): bool
    {
        if ($this->findWhere(['child_id' => $childId, 'dedup_key' => $dedupKey]) !== []) {
            return false;
        }
        return $this->create($data + ['child_id' => $childId, 'dedup_key' => $dedupKey]) > 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByChild(int $childId, int $limit = self::MAX_LIMIT): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $sql = $this->db->prepare(
            'SELECT * FROM ' . $this->table()
            . ' WHERE child_id = %d ORDER BY created_at DESC, id DESC LIMIT %d',
            $childId,
            $limit,
        );
        $rows = $this->db->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public function unreadCount(int $childId): int
    {
        $sql = $this->db->prepare(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE child_id = %d AND read_at IS NULL',
            $childId,
        );
        return (int) $this->db->get_var($sql);
    }

    public function markAllRead(int $childId): int
    {
        $sql = $this->db->prepare(
            'UPDATE ' . $this->table() . ' SET read_at = %s WHERE child_id = %d AND read_at IS NULL',
            current_time('mysql', true),
            $childId,
        );
        $affected = $this->db->query($sql);
        return is_numeric($affected) ? (int) $affected : 0;
    }
}
