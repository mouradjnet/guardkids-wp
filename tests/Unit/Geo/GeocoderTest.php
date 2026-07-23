<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Geo;

use GuardKids\Geo\Geocoder;
use PHPUnit\Framework\TestCase;

final class GeocoderTest extends TestCase
{
    public function testParsesFirstResult(): void
    {
        $geocoder = new class () extends Geocoder {
            protected function fetch(string $query): ?string
            {
                return '[{"lat":"-8.0501","lon":"-34.8811","display_name":"Rua X, Recife"}]';
            }
            protected function cacheGet(string $key): mixed
            {
                return false;
            }
            protected function cacheSet(string $key, mixed $value): void
            {
            }
        };
        $r = $geocoder->geocode('Rua X, Recife');
        self::assertNotNull($r);
        self::assertEqualsWithDelta(-8.0501, $r['lat'], 0.0001);
        self::assertEqualsWithDelta(-34.8811, $r['lng'], 0.0001);
        self::assertSame('Rua X, Recife', $r['displayName']);
    }

    public function testReturnsNullOnEmptyResult(): void
    {
        $geocoder = new class () extends Geocoder {
            protected function fetch(string $query): ?string
            {
                return '[]';
            }
            protected function cacheGet(string $key): mixed
            {
                return false;
            }
            protected function cacheSet(string $key, mixed $value): void
            {
            }
        };
        self::assertNull($geocoder->geocode('inexistente'));
    }

    /**
     * O caso real que motivou a expansão: contra a API do Nominatim, "Av Gov
     * Agamenon Magalhaes 222, Jaboatao dos Guararapes" devolve VAZIO e a mesma
     * busca por extenso acha de primeira.
     */
    public function testExpandeAbreviacoesDeEndereco(): void
    {
        self::assertSame(
            'Avenida Governador Agamenon Magalhaes 222, Cavaleiro, Jaboatao dos Guararapes - PE',
            Geocoder::expandirAbreviacoes('Av Gov Agamenon Magalhaes 222, Cavaleiro, Jaboatao dos Guararapes - PE'),
        );
    }

    public function testExpandeComPontoEIgnoraCaixa(): void
    {
        self::assertSame('Rua Doutor Jose', Geocoder::expandirAbreviacoes('R. Dr. Jose'));
        self::assertSame('Praça Santa Rita', Geocoder::expandirAbreviacoes('PC STA Rita'));
    }

    /** Palavra que não é abreviação tem que sair intacta, acento e tudo. */
    public function testNaoMexeNoQueNaoEhAbreviacao(): void
    {
        $endereco = 'Estrada da Batalha, 1200, Jardim Paulista, São Paulo';
        self::assertSame($endereco, Geocoder::expandirAbreviacoes($endereco));
    }

    /** A query que vai pro HTTP é a expandida, não a que o pai digitou. */
    public function testConsultaUsaAQueryExpandida(): void
    {
        $geocoder = new class () extends Geocoder {
            public string $recebida = '';
            protected function fetch(string $query): ?string
            {
                $this->recebida = $query;
                return '[{"lat":"-8.08","lon":"-34.97","display_name":"ok"}]';
            }
            protected function cacheGet(string $key): mixed
            {
                return false;
            }
            protected function cacheSet(string $key, mixed $value): void
            {
            }
        };

        $geocoder->geocode('Av Gov Agamenon Magalhaes 222');

        self::assertSame('Avenida Governador Agamenon Magalhaes 222', $geocoder->recebida);
    }

    public function testReturnsNullOnHttpError(): void
    {
        $geocoder = new class () extends Geocoder {
            protected function fetch(string $query): ?string
            {
                return null;
            }
            protected function cacheGet(string $key): mixed
            {
                return false;
            }
            protected function cacheSet(string $key, mixed $value): void
            {
            }
        };
        self::assertNull($geocoder->geocode('qualquer'));
    }
}
