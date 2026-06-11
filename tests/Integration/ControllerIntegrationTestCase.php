<?php

declare(strict_types=1);

namespace GuardKids\Tests\Integration;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Base para integration tests de controllers REST.
 *
 * Estende {@see IntegrationTestCase} (que faz TRUNCATE entre testes) e
 * adiciona helpers de fábrica de WP_REST_Request + assertions de response.
 */
abstract class ControllerIntegrationTestCase extends IntegrationTestCase
{
    /**
     * @param array<string, mixed> $params
     */
    protected function makeRequest(string $method, string $route, array $params = []): WP_REST_Request
    {
        $req = new WP_REST_Request($method, $route);
        foreach ($params as $key => $value) {
            $req->set_param($key, $value);
        }
        return $req;
    }

    protected function assertResponseStatus(int $expected, WP_REST_Response|WP_Error $resp): void
    {
        if ($resp instanceof WP_Error) {
            $actual = (int) ($resp->get_error_data()['status'] ?? 0);
            $this->assertSame(
                $expected,
                $actual,
                sprintf('Expected status %d, got WP_Error %d (%s)', $expected, $actual, $resp->get_error_code()),
            );
            return;
        }
        $this->assertSame($expected, $resp->get_status());
    }

    protected function assertWpError(string $expectedCode, WP_REST_Response|WP_Error $resp): void
    {
        $this->assertInstanceOf(WP_Error::class, $resp, 'Expected WP_Error, got WP_REST_Response');
        $this->assertSame($expectedCode, $resp->get_error_code());
    }

    /**
     * @return array<string, mixed>
     */
    protected function dataOf(WP_REST_Response $resp): array
    {
        $data = $resp->get_data();
        $this->assertIsArray($data);
        return $data;
    }
}
