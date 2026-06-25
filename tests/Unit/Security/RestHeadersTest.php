<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Security;

use GuardKids\Security\RestHeaders;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestHeaders — escopo restrito ao namespace guardkids/v1.
 */
final class RestHeadersTest extends TestCase
{
    private RestHeaders $headers;
    private WP_REST_Server $server;

    protected function setUp(): void
    {
        $this->headers = new RestHeaders();
        $this->server = new WP_REST_Server();
    }

    public function testAddsAllSecurityHeadersForGuardkidsRoute(): void
    {
        $response = new WP_REST_Response(['ok' => true]);
        $request = new WP_REST_Request('GET', '/guardkids/v1/children');

        $result = $this->headers->addHeaders($response, $this->server, $request);

        self::assertSame($response, $result);
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);
        self::assertSame('strict-origin-when-cross-origin', $response->headers['Referrer-Policy']);
        self::assertSame('DENY', $response->headers['X-Frame-Options']);
        self::assertSame('noindex, nofollow', $response->headers['X-Robots-Tag']);
        self::assertSame('no-store, no-cache, must-revalidate, max-age=0', $response->headers['Cache-Control']);
        self::assertSame('no-cache', $response->headers['Pragma']);
    }

    public function testSetsNoStoreOnPinStatusRoute(): void
    {
        // Bug v1.11.0: GET /security/pin servia {"pinSet":false} cacheado no edge.
        $response = new WP_REST_Response(['pinSet' => true]);
        $request = new WP_REST_Request('GET', '/guardkids/v1/security/pin');

        $this->headers->addHeaders($response, $this->server, $request);

        self::assertStringContainsString('no-store', $response->headers['Cache-Control']);
    }

    public function testIgnoresCoreNamespaceRoutes(): void
    {
        $response = new WP_REST_Response();
        $request = new WP_REST_Request('GET', '/wp/v2/posts');

        $this->headers->addHeaders($response, $this->server, $request);

        self::assertSame([], $response->headers);
    }

    public function testIgnoresOtherNamespacesEvenIfSubstringMatch(): void
    {
        $response = new WP_REST_Response();
        // "guardkids" sem o /v1/ não deve casar — startsWith é '/guardkids/v1/'
        $request = new WP_REST_Request('GET', '/guardkidsfake/v1/foo');

        $this->headers->addHeaders($response, $this->server, $request);

        self::assertSame([], $response->headers);
    }

    public function testReturnsResultUnchangedWhenNotWPRESTResponse(): void
    {
        $request = new WP_REST_Request('GET', '/guardkids/v1/children');
        $raw = ['not' => 'a-response'];

        $result = $this->headers->addHeaders($raw, $this->server, $request);

        self::assertSame($raw, $result);
    }

    public function testReturnsResultUnchangedWhenNull(): void
    {
        $request = new WP_REST_Request('GET', '/guardkids/v1/children');
        $result = $this->headers->addHeaders(null, $this->server, $request);

        self::assertNull($result);
    }

    public function testAllChildEndpointsGetHeaders(): void
    {
        foreach (['/guardkids/v1/child/me', '/guardkids/v1/child/requests'] as $route) {
            $response = new WP_REST_Response();
            $request = new WP_REST_Request('GET', $route);
            $this->headers->addHeaders($response, $this->server, $request);
            self::assertArrayHasKey('X-Content-Type-Options', $response->headers, "rota $route deveria ter headers");
        }
    }
}
