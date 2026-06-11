<?php

declare(strict_types=1);

/**
 * Implementação real-mysql do `\wpdb` para os integration tests.
 *
 * Cobre apenas os métodos usados pelos Repositories do GuardKids:
 *   prepare, get_row, get_var, get_results, insert, update, delete, query,
 *   get_charset_collate; e as properties prefix + insert_id.
 *
 * Não é uma re-implementação fiel do `wpdb` do WordPress — só o suficiente
 * pra validar SQL contra MySQL real.
 */
class wpdb // phpcs:ignore PSR1.Classes.ClassDeclaration
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;

    private \mysqli $mysqli;

    public function __construct(\mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function mysqli(): \mysqli
    {
        return $this->mysqli;
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    /**
     * @param mixed ...$args
     */
    public function prepare(string $query, mixed ...$args): string
    {
        if ($args === []) {
            return $query;
        }
        // WP aceita array como primeiro arg.
        if (count($args) === 1 && is_array($args[0])) {
            $args = array_values($args[0]);
        }

        $i = 0;
        return (string) preg_replace_callback(
            '/%[dfsi]/',
            function (array $m) use (&$i, $args): string {
                $value = $args[$i] ?? null;
                $i++;
                return match ($m[0]) {
                    '%d' => (string) (int) $value,
                    '%f' => (string) (float) $value,
                    '%s' => "'" . $this->mysqli->real_escape_string((string) $value) . "'",
                    '%i' => '`' . str_replace('`', '``', (string) $value) . '`',
                    default => $m[0],
                };
            },
            $query,
        );
    }

    public function query(string $sql): int|bool
    {
        $result = $this->mysqli->query($sql);
        if ($result === false) {
            return false;
        }
        if ($result === true) {
            return (int) $this->mysqli->affected_rows;
        }
        $result->free();
        return (int) $this->mysqli->affected_rows;
    }

    /**
     * @return array<string, mixed>|object|null
     */
    public function get_row(string $sql, string $output = 'OBJECT', int $row = 0): array|object|null
    {
        $result = $this->mysqli->query($sql);
        if (! $result instanceof \mysqli_result) {
            return null;
        }
        $row = $output === 'ARRAY_A' ? $result->fetch_assoc() : $result->fetch_object();
        $result->free();
        return $row ?: null;
    }

    public function get_var(string $sql, int $col = 0, int $row = 0): string|null
    {
        $result = $this->mysqli->query($sql);
        if (! $result instanceof \mysqli_result) {
            return null;
        }
        $data = $result->fetch_array(MYSQLI_NUM);
        $result->free();
        if (! is_array($data) || ! array_key_exists($col, $data)) {
            return null;
        }
        return $data[$col] === null ? null : (string) $data[$col];
    }

    /**
     * @return array<int, array<string, mixed>|object>
     */
    public function get_results(string $sql, string $output = 'OBJECT'): array
    {
        $result = $this->mysqli->query($sql);
        if (! $result instanceof \mysqli_result) {
            return [];
        }
        $rows = [];
        if ($output === 'ARRAY_A') {
            while ($r = $result->fetch_assoc()) {
                $rows[] = $r;
            }
        } else {
            while ($r = $result->fetch_object()) {
                $rows[] = $r;
            }
        }
        $result->free();
        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string>|null $format
     */
    public function insert(string $table, array $data, ?array $format = null): int|false
    {
        $columns      = array_map(fn (string $c) => '`' . $c . '`', array_keys($data));
        $placeholders = array_map(fn (mixed $v) => $this->inferPlaceholder($v), array_values($data));
        $sql          = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders),
        );
        $prepared = $this->prepare($sql, ...array_values($data));
        $ok       = $this->mysqli->query($prepared);
        if ($ok === false) {
            return false;
        }
        $this->insert_id = (int) $this->mysqli->insert_id;
        return (int) $this->mysqli->affected_rows;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     * @param array<int, string>|null $format
     * @param array<int, string>|null $where_format
     */
    public function update(
        string $table,
        array $data,
        array $where,
        ?array $format = null,
        ?array $where_format = null,
    ): int|false {
        $setSegments = [];
        $values      = [];
        foreach ($data as $col => $value) {
            $setSegments[] = '`' . $col . '` = ' . $this->inferPlaceholder($value);
            $values[]      = $value;
        }
        $whereSegments = [];
        foreach ($where as $col => $value) {
            $whereSegments[] = '`' . $col . '` = ' . $this->inferPlaceholder($value);
            $values[]        = $value;
        }
        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $table,
            implode(', ', $setSegments),
            implode(' AND ', $whereSegments),
        );
        $prepared = $this->prepare($sql, ...$values);
        $ok       = $this->mysqli->query($prepared);
        if ($ok === false) {
            return false;
        }
        return (int) $this->mysqli->affected_rows;
    }

    /**
     * @param array<string, mixed> $where
     * @param array<int, string>|null $where_format
     */
    public function delete(string $table, array $where, ?array $where_format = null): int|false
    {
        $whereSegments = [];
        $values        = [];
        foreach ($where as $col => $value) {
            $whereSegments[] = '`' . $col . '` = ' . $this->inferPlaceholder($value);
            $values[]        = $value;
        }
        $sql      = sprintf(
            'DELETE FROM `%s` WHERE %s',
            $table,
            implode(' AND ', $whereSegments),
        );
        $prepared = $this->prepare($sql, ...$values);
        $ok       = $this->mysqli->query($prepared);
        if ($ok === false) {
            return false;
        }
        return (int) $this->mysqli->affected_rows;
    }

    private function inferPlaceholder(mixed $value): string
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
