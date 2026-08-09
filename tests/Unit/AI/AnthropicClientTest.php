<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\AI;

use GuardKids\AI\AnthropicClient;
use PHPUnit\Framework\TestCase;

/**
 * AnthropicClient — transporte da Messages API, degradação leniente.
 *
 * A constante GUARDKIDS_ANTHROPIC_KEY não é definida aqui de propósito (constante
 * é global e vazaria entre testes): exercitamos a chave só pela option.
 */
final class AnthropicClientTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['gk_options'] = [];
    }

    /** Client que devolve um corpo decodificado fixo e conta as chamadas ao HTTP. */
    private function clientReturning(?array $decoded): AnthropicClient
    {
        return new class ($decoded) extends AnthropicClient {
            public int $fetchCalls = 0;
            public function __construct(private ?array $decoded)
            {
            }
            protected function fetch(array $payload): ?array
            {
                $this->fetchCalls++;
                return $this->decoded;
            }
        };
    }

    public function testReturnsTextFromGoodResponse(): void
    {
        $GLOBALS['gk_options']['guardkids_anthropic_key'] = 'sk-test';
        $client = $this->clientReturning([
            'content' => [['type' => 'text', 'text' => '{"insights":[]}']],
        ]);

        self::assertSame('{"insights":[]}', $client->complete('sys', 'user'));
    }

    public function testReturnsNullWithoutKeyAndSkipsHttp(): void
    {
        // option vazia + constante indefinida → sem chave.
        $client = $this->clientReturning([
            'content' => [['type' => 'text', 'text' => 'nunca deveria chegar aqui']],
        ]);

        self::assertNull($client->complete('sys', 'user'));
        self::assertSame(0, $client->fetchCalls, 'sem chave, o HTTP não pode ser chamado');
    }

    public function testReturnsNullOnHttpError(): void
    {
        $GLOBALS['gk_options']['guardkids_anthropic_key'] = 'sk-test';
        $client = $this->clientReturning(null); // fetch devolve null = erro HTTP

        self::assertNull($client->complete('sys', 'user'));
    }

    public function testReturnsNullWhenContentMissing(): void
    {
        $GLOBALS['gk_options']['guardkids_anthropic_key'] = 'sk-test';
        $client = $this->clientReturning(['error' => ['message' => 'overloaded']]);

        self::assertNull($client->complete('sys', 'user'));
    }

    public function testHasKeyReflectsOption(): void
    {
        $client = $this->clientReturning(null);
        self::assertFalse($client->hasKey());

        $GLOBALS['gk_options']['guardkids_anthropic_key'] = '  sk-live  ';
        self::assertTrue($client->hasKey());
    }
}
