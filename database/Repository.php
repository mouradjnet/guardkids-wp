<?php

declare(strict_types=1);

namespace GuardKids\Database;

/**
 * Base de Repository — todas as queries via $wpdb->prepare.
 *
 * Subclasses devem definir {@see table()}. Convenções:
 *   - PK INT auto-increment chamada `id`
 *   - timestamps `created_at` / `updated_at` mantidos pelo repo
 *   - rows são `array<string, mixed>` (associativos)
 */
abstract class Repository
{
    protected readonly \wpdb $db;

    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
    }

    /**
     * Nome curto da tabela sem o prefixo `wp_guardkids_`.
     */
    abstract protected function tableSuffix(): string;

    final protected function table(): string
    {
        return $this->db->prefix . 'guardkids_' . $this->tableSuffix();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $sql = $this->db->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE id = %d LIMIT 1',
            $id,
        );
        $row = $this->db->get_row($sql, ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(string $orderBy = 'id', string $direction = 'ASC'): array
    {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $orderBy   = preg_match('/^[a-zA-Z_]+$/', $orderBy) ? $orderBy : 'id';

        $sql = 'SELECT * FROM ' . $this->table() . " ORDER BY {$orderBy} {$direction}";
        $rows = $this->db->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $where
     * @return array<int, array<string, mixed>>
     */
    protected function findWhere(array $where, string $orderBy = 'id', string $direction = 'ASC'): array
    {
        if ($where === []) {
            return $this->findAll($orderBy, $direction);
        }

        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $orderBy   = preg_match('/^[a-zA-Z_]+$/', $orderBy) ? $orderBy : 'id';

        $clauses = [];
        $values  = [];
        foreach ($where as $column => $value) {
            // Defesa em profundidade: callers internos só passam keys
            // hardcoded, mas se um dia algo vir de input, fail-fast aqui
            // bloqueia SQL injection via column name.
            if (preg_match('/^[a-zA-Z_]+$/', (string) $column) !== 1) {
                throw new \InvalidArgumentException(
                    'Invalid column name in findWhere: ' . (string) $column,
                );
            }
            $clauses[] = "{$column} = " . ($this->placeholderFor($value));
            $values[]  = $value;
        }
        $sql = 'SELECT * FROM ' . $this->table()
            . ' WHERE ' . implode(' AND ', $clauses)
            . " ORDER BY {$orderBy} {$direction}";
        $prepared = $this->db->prepare($sql, ...$values);
        $rows = $this->db->get_results($prepared, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        $now = current_time('mysql', true);
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        $ok = $this->db->insert($this->table(), $data);
        if ($ok === false) {
            return 0;
        }
        return (int) $this->db->insert_id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        $data['updated_at'] = current_time('mysql', true);
        $ok = $this->db->update($this->table(), $data, ['id' => $id]);
        return $ok !== false;
    }

    public function delete(int $id): bool
    {
        $ok = $this->db->delete($this->table(), ['id' => $id]);
        return $ok !== false;
    }

    private function placeholderFor(mixed $value): string
    {
        if (is_int($value)) {
            return '%d';
        }
        if (is_float($value)) {
            return '%f';
        }
        return '%s';
    }
}
