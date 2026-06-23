<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\PrivacyController;
use GuardKids\Maintenance\Purger;
use GuardKids\Privacy\PrivacyEraser;
use GuardKids\Privacy\PrivacyExporter;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

final class PrivacyControllerTest extends TestCase
{
    private function fakeWpdb(): \wpdb
    {
        return new class () extends \wpdb {
            public string $prefix = 'wp_';

            public function __construct()
            {
            }

            public function prepare($query, ...$args)
            {
                return (string) $query;
            }

            public function get_results($query = null, $output = ARRAY_A)
            {
                if (str_contains((string) $query, 'guardkids_children')) {
                    return [['id' => 1, 'name' => 'Lucas']];
                }
                return [];
            }

            public function query($sql)
            {
                return 2;
            }
        };
    }

    private function controller(\wpdb $wpdb): PrivacyController
    {
        return new PrivacyController(
            new PrivacyExporter($wpdb),
            new PrivacyEraser($wpdb),
            new Purger($wpdb),
        );
    }

    public function testExportReturnsCollectedTables(): void
    {
        $res = $this->controller($this->fakeWpdb())->export();
        $data = $res->get_data();

        self::assertArrayHasKey('tables', $data);
        self::assertSame([['id' => 1, 'name' => 'Lucas']], $data['tables']['children']);
    }

    public function testClearHistoryReturnsCountsPerTable(): void
    {
        $res = $this->controller($this->fakeWpdb())->clearHistory();

        self::assertSame(
            ['usage_events' => 2, 'locations' => 2, 'requests' => 2],
            $res->get_data(),
        );
    }

    public function testDeleteAllRejectsWrongConfirm(): void
    {
        $req = new WP_REST_Request();
        $req->set_json_params(['confirm' => 'nope']);

        $res = $this->controller($this->fakeWpdb())->deleteAll($req);

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(400, $res->get_error_data()['status']);
    }

    public function testDeleteAllWipesWhenConfirmed(): void
    {
        $req = new WP_REST_Request();
        $req->set_json_params(['confirm' => 'EXCLUIR']);

        $res = $this->controller($this->fakeWpdb())->deleteAll($req);
        $data = $res->get_data();

        self::assertArrayHasKey('tables', $data);
        self::assertSame(2, $data['tables']['children']);
        self::assertArrayNotHasKey('guardians', $data['tables']);
    }
}
