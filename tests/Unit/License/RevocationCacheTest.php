<?php
declare(strict_types=1);

namespace GuardKids\Tests\Unit\License;

use GuardKids\License\RevocationCache;
use PHPUnit\Framework\TestCase;

final class RevocationCacheTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['gk_transients'] = [];
    }

    public function testApplyResponsePopulatesCacheAndIsRevoked(): void
    {
        $cache = new RevocationCache('https://server.test/wp-json/gkl/v1/');
        $cache->applyResponse(['revoked' => ['jti-a', 'jti-b'], 'generated_at' => 'x']);

        $this->assertTrue($cache->isRevoked('jti-a'));
        $this->assertFalse($cache->isRevoked('jti-c'));
    }

    public function testFailOpenKeepsPreviousCacheOnBadResponse(): void
    {
        $cache = new RevocationCache('https://server.test/wp-json/gkl/v1/');
        $cache->applyResponse(['revoked' => ['jti-a']]);   // cache prévio
        $cache->applyResponse(null);                       // servidor fora / lixo
        $this->assertTrue($cache->isRevoked('jti-a'), 'falha não pode limpar o cache');
    }

    public function testNoCacheMeansNobodyRevoked(): void
    {
        $cache = new RevocationCache('https://server.test/wp-json/gkl/v1/');
        $this->assertFalse($cache->isRevoked('jti-a'), 'sem cache = ninguém revogado (falha aberta)');
    }
}
