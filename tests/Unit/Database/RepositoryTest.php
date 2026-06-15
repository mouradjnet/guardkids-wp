<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\Repository;
use PHPUnit\Framework\TestCase;

/**
 * Testes da classe base Repository — usa $wpdb mockado.
 *
 * Como Repository é abstract, definimos um Concrete dentro do arquivo
 * que devolve um suffix fixo.
 */
final class RepositoryTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        // wpdb fake — só os métodos que Repository chama
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<int, array{method:string, sql:string|array, params:array<int, mixed>}> */
            public array $log = [];
            /** @var array<string, mixed> */
            public array $stubResponses = [];

            // @phpstan-ignore-next-line — sobrecarrega o construtor do wpdb pra evitar conectar no DB
            public function __construct()
            {
            }

            public function prepare($query, ...$args)
            {
                $this->log[] = ['method' => 'prepare', 'sql' => (string) $query, 'params' => $args];
                $flat = $args[0] ?? null;
                if (is_array($flat)) {
                    $args = $flat;
                }
                return vsprintf(str_replace(['%d', '%s', '%f'], ['%d', "'%s'", '%F'], (string) $query), $args);
            }

            public function get_row($sql, $output = OBJECT, $y = 0)
            {
                $this->log[] = ['method' => 'get_row', 'sql' => $sql, 'params' => []];
                return $this->stubResponses['get_row'] ?? null;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $this->log[] = ['method' => 'get_results', 'sql' => $sql, 'params' => []];
                return $this->stubResponses['get_results'] ?? [];
            }

            public function insert($table, $data, $format = null)
            {
                $this->log[] = ['method' => 'insert', 'sql' => $table, 'params' => $data];
                $this->insert_id = 42;
                return 1;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                $this->log[] = ['method' => 'update', 'sql' => $table, 'params' => [$data, $where]];
                return 1;
            }

            public function delete($table, $where, $where_format = null)
            {
                $this->log[] = ['method' => 'delete', 'sql' => $table, 'params' => $where];
                return 1;
            }
        };

        // Repository.__construct lê $GLOBALS['wpdb']
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testTableNameUsesGuardkidsPrefix(): void
    {
        $repo = $this->makeConcrete('children');
        // Indireto: findById chama table() internamente
        $repo->findById(1);

        self::assertSame('wp_', $this->wpdb->prefix);
        self::assertStringContainsString('wp_guardkids_children', (string) $this->wpdb->log[0]['sql']);
    }

    public function testFindByIdRunsPreparedSelect(): void
    {
        $this->wpdb->stubResponses['get_row'] = ['id' => 7, 'name' => 'Lucas'];
        $repo = $this->makeConcrete('children');

        $row = $repo->findById(7);

        self::assertSame(['id' => 7, 'name' => 'Lucas'], $row);
        self::assertSame('prepare', $this->wpdb->log[0]['method']);
        self::assertSame([7], $this->wpdb->log[0]['params']);
    }

    public function testFindByIdReturnsNullWhenNoRow(): void
    {
        $this->wpdb->stubResponses['get_row'] = null;
        $repo = $this->makeConcrete('children');

        self::assertNull($repo->findById(999));
    }

    public function testInsertAddsTimestampsAndReturnsInsertId(): void
    {
        $repo = $this->makeConcrete('children');
        $id = $repo->insert(['name' => 'Lucas']);

        self::assertSame(42, $id);
        $insertLog = $this->wpdb->log[0];
        self::assertSame('insert', $insertLog['method']);
        self::assertArrayHasKey('created_at', $insertLog['params']);
        self::assertArrayHasKey('updated_at', $insertLog['params']);
        self::assertSame($insertLog['params']['created_at'], $insertLog['params']['updated_at']);
    }

    public function testUpdateAddsUpdatedAtAndReturnsTrue(): void
    {
        $repo = $this->makeConcrete('children');
        $ok = $repo->update(5, ['name' => 'Paloma']);

        self::assertTrue($ok);
        $log = $this->wpdb->log[0];
        self::assertSame('update', $log['method']);
        self::assertArrayHasKey('updated_at', $log['params'][0]);
        self::assertSame(['id' => 5], $log['params'][1]);
    }

    public function testDeleteReturnsTrue(): void
    {
        $repo = $this->makeConcrete('children');
        $ok = $repo->delete(9);

        self::assertTrue($ok);
        self::assertSame('delete', $this->wpdb->log[0]['method']);
        self::assertSame(['id' => 9], $this->wpdb->log[0]['params']);
    }

    public function testFindAllSanitizesOrderByAndDirection(): void
    {
        $this->wpdb->stubResponses['get_results'] = [['id' => 1], ['id' => 2]];
        $repo = $this->makeConcrete('children');

        $rows = $repo->findAll('"; DROP TABLE--', 'INVALID');

        self::assertCount(2, $rows);
        // Regex no Repository força orderBy = id quando há caracteres não-permitidos
        // e direction = ASC quando não é DESC
        self::assertStringContainsString('ORDER BY id ASC', (string) $this->wpdb->log[0]['sql']);
    }

    /**
     * Defesa em profundidade: keys de findWhere devem ser hardcoded por
     * callers internos, mas se alguma vier de input por bug futuro, a regex
     * deve fail-fast em vez de concatenar diretamente no SQL.
     */
    public function testFindWhereRejectsInvalidColumnName(): void
    {
        $repo = new class () extends Repository {
            protected function tableSuffix(): string
            {
                return 'children';
            }
            /**
             * @param array<string, mixed> $where
             * @return array<int, array<string, mixed>>
             */
            public function exposeFindWhere(array $where): array
            {
                return $this->findWhere($where);
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid column name/');
        $repo->exposeFindWhere(['id; DROP TABLE wp_users--' => 1]);
    }

    private function makeConcrete(string $suffix): Repository
    {
        return new class ($suffix) extends Repository {
            public function __construct(private readonly string $suffix)
            {
                parent::__construct();
            }
            protected function tableSuffix(): string
            {
                return $this->suffix;
            }
        };
    }
}
