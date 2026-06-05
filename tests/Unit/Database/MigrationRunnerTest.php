<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * MigrationRunner — discovery por nome NNN_*.php, idempotência via
 * wp_options('guardkids_db_version'), aplicação só do que está pendente.
 *
 * Cria diretório temporário com migrations fake que registram sua execução
 * em $GLOBALS['gk_migration_runs'] pra inspeção.
 */
final class MigrationRunnerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $GLOBALS['gk_options'] = [];
        $GLOBALS['gk_migration_runs'] = [];
        $GLOBALS['wpdb'] = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public function __construct()
            {
            }
            public function get_charset_collate(): string
            {
                return 'utf8mb4';
            }
        };

        $this->dir = sys_get_temp_dir() . '/gk-migrations-' . uniqid('', true) . '/';
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (glob($this->dir . '*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->dir);
        }
    }

    public function testRunsAllMigrationsFromVersionZero(): void
    {
        $this->makeMigration(1, 'one');
        $this->makeMigration(2, 'two');
        $this->makeMigration(3, 'three');

        (new MigrationRunner($this->dir))->run();

        self::assertSame([1, 2, 3], $GLOBALS['gk_migration_runs']);
        self::assertSame(3, $GLOBALS['gk_options']['guardkids_db_version']);
    }

    public function testIdempotentWhenAlreadyAtLatest(): void
    {
        $this->makeMigration(1, 'one');
        $this->makeMigration(2, 'two');
        $GLOBALS['gk_options']['guardkids_db_version'] = 2;

        (new MigrationRunner($this->dir))->run();

        self::assertSame([], $GLOBALS['gk_migration_runs']);
        // versão não muda quando nada aplicou
        self::assertSame(2, $GLOBALS['gk_options']['guardkids_db_version']);
    }

    public function testRunsOnlyPendingMigrations(): void
    {
        $this->makeMigration(1, 'one');
        $this->makeMigration(2, 'two');
        $this->makeMigration(3, 'three');
        $GLOBALS['gk_options']['guardkids_db_version'] = 1;

        (new MigrationRunner($this->dir))->run();

        self::assertSame([2, 3], $GLOBALS['gk_migration_runs']);
        self::assertSame(3, $GLOBALS['gk_options']['guardkids_db_version']);
    }

    public function testIgnoresFilesWithoutVersionPrefix(): void
    {
        $this->makeMigration(1, 'one');
        file_put_contents($this->dir . 'readme.php', "<?php return null;");
        file_put_contents($this->dir . 'notes.txt', 'ignorar');

        (new MigrationRunner($this->dir))->run();

        self::assertSame([1], $GLOBALS['gk_migration_runs']);
    }

    public function testRunsInVersionOrder(): void
    {
        // Cria fora de ordem; o glob discovery + ksort deve aplicar 1,2,3
        $this->makeMigration(3, 'three');
        $this->makeMigration(1, 'one');
        $this->makeMigration(2, 'two');

        (new MigrationRunner($this->dir))->run();

        self::assertSame([1, 2, 3], $GLOBALS['gk_migration_runs']);
    }

    public function testEmptyDirectoryIsNoOp(): void
    {
        (new MigrationRunner($this->dir))->run();

        self::assertSame([], $GLOBALS['gk_migration_runs']);
        self::assertArrayNotHasKey('guardkids_db_version', $GLOBALS['gk_options']);
    }

    public function testSkipsFileThatDoesNotReturnCallable(): void
    {
        // 001 retorna null em vez de Closure — runner pula
        file_put_contents(
            $this->dir . '001_broken.php',
            "<?php return null;\n"
        );
        $this->makeMigration(2, 'two');

        (new MigrationRunner($this->dir))->run();

        self::assertSame([2], $GLOBALS['gk_migration_runs']);
        self::assertSame(2, $GLOBALS['gk_options']['guardkids_db_version']);
    }

    private function makeMigration(int $version, string $name): void
    {
        $padded = str_pad((string) $version, 3, '0', STR_PAD_LEFT);
        file_put_contents(
            $this->dir . $padded . '_' . $name . '.php',
            sprintf(
                "<?php return static function (\$wpdb, \$collate) { \$GLOBALS['gk_migration_runs'][] = %d; };\n",
                $version
            )
        );
    }
}
