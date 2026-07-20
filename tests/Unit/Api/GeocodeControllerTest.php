<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\GeocodeController;
use GuardKids\Geo\Geocoder;
use PHPUnit\Framework\TestCase;

final class GeocodeControllerTest extends TestCase
{
    public function testReturnsCoordinatesForQuery(): void
    {
        $geocoder = new class () extends Geocoder {
            public function geocode(string $query): ?array
            {
                return ['lat' => -8.05, 'lng' => -34.88, 'displayName' => 'Recife'];
            }
        };
        $req = new \WP_REST_Request();
        $req->set_param('q', 'Recife');

        $res = (new GeocodeController($geocoder))->index($req);
        self::assertSame(-8.05, $res->get_data()['lat']);
    }

    public function testNotFoundReturns404(): void
    {
        $geocoder = new class () extends Geocoder {
            public function geocode(string $query): ?array
            {
                return null;
            }
        };
        $req = new \WP_REST_Request();
        $req->set_param('q', 'zzz');

        $res = (new GeocodeController($geocoder))->index($req);
        self::assertInstanceOf(\WP_Error::class, $res);
        self::assertSame(404, $res->get_error_data()['status']);
    }
}
