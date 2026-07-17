<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

/**
 * A migração 025 normaliza domínios legados. O teste existe sobretudo pra
 * travar o que ela NÃO pode fazer: apagar linha.
 *
 * A primeira versão dela deduplicava por (domain, list_type) e destruía regra
 * legítima — `khanacademy.org` pra todos e `khanacademy.org` só pro Lucas são
 * regras diferentes, e a chave ignorava o applies_to.
 */
final class Migration025Test extends TestCase
{
    /** @param array<int, array<string, mixed>> $linhas */
    private function rodar(array $linhas): object
    {
        $wpdb = new class ($linhas) extends \wpdb {
            public string $prefix = 'wp_';
            /** @var array<int, array<string, mixed>> */
            public array $rows;
            /** @var array<int, array<string, mixed>> */
            public array $updates = [];
            /** @var array<int, array<string, mixed>> */
            public array $deletes = [];

            /** @param array<int, array<string, mixed>> $linhas */
            public function __construct(array $linhas)
            {
                $this->rows = $linhas;
            }

            public function prepare($query, ...$args)
            {
                return (string) $query;
            }

            public function get_results($query = null, $output = ARRAY_A)
            {
                return $this->rows;
            }

            public function update($table, $data, $where, $f = null, $wf = null)
            {
                $this->updates[] = ['data' => $data, 'where' => $where];
                return 1;
            }

            public function delete($table, $where, $format = null)
            {
                $this->deletes[] = $where;
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $wpdb;

        $migration = require __DIR__ . '/../../../database/migrations/025_normalize_site_domains.php';
        $migration($wpdb, '');

        return $wpdb;
    }

    public function testNormalizaDominioComProtocolo(): void
    {
        $wpdb = $this->rodar([
            ['id' => 6, 'domain' => 'https://youtube.com'],
        ]);

        self::assertCount(1, $wpdb->updates);
        self::assertSame(['domain' => 'youtube.com'], $wpdb->updates[0]['data']);
        self::assertSame(['id' => 6], $wpdb->updates[0]['where']);
    }

    public function testNaoTocaEmDominioQueJaEstaLimpo(): void
    {
        $wpdb = $this->rodar([
            ['id' => 1, 'domain' => 'khanacademy.org'],
        ]);

        self::assertSame([], $wpdb->updates, 'update desnecessario e ruido no banco');
    }

    /** O erro que esta migração já cometeu uma vez. */
    public function testNUNCAApagaLinha(): void
    {
        $wpdb = $this->rodar([
            // mesmo domínio, mesma lista, ALVOS diferentes: sao regras distintas
            ['id' => 1, 'domain' => 'https://khanacademy.org', 'list_type' => 'whitelist', 'applies_to' => '[]'],
            ['id' => 2, 'domain' => 'khanacademy.org', 'list_type' => 'whitelist', 'applies_to' => '[1]'],
            // e uma linha que normaliza pra vazio
            ['id' => 9, 'domain' => 'https://', 'list_type' => 'whitelist', 'applies_to' => '[]'],
        ]);

        self::assertSame([], $wpdb->deletes, 'migracao nao pode apagar dado do cliente');
        // só a id=1 muda; a 2 já estava limpa e a 9 normaliza pra vazio (fica como está)
        self::assertCount(1, $wpdb->updates);
        self::assertSame(['id' => 1], $wpdb->updates[0]['where']);
    }

    public function testNormalizaWwwEPath(): void
    {
        $wpdb = $this->rodar([
            ['id' => 3, 'domain' => 'https://www.Canva.com/design/play'],
        ]);

        self::assertSame(['domain' => 'canva.com'], $wpdb->updates[0]['data']);
    }
}
