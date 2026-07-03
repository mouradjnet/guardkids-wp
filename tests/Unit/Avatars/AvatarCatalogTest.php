<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Avatars;

use GuardKids\Avatars\AvatarCatalog;
use PHPUnit\Framework\TestCase;

final class AvatarCatalogTest extends TestCase
{
    public function testHasSevenAvatars(): void
    {
        $keys = array_column(AvatarCatalog::all(), 'key');
        self::assertSame(
            ['star', 'heart', 'rocket', 'crown', 'fire', 'book', 'trophy'],
            $keys,
        );
    }

    public function testGatesAndFields(): void
    {
        $byKey = [];
        foreach (AvatarCatalog::all() as $a) {
            self::assertArrayHasKey('emoji', $a);
            self::assertArrayHasKey('label', $a);
            self::assertContains($a['gate'], ['free', 'level', 'medal']);
            $byKey[$a['key']] = $a;
        }
        self::assertSame('free', $byKey['star']['gate']);
        self::assertSame('level', $byKey['rocket']['gate']);
        self::assertSame(5, $byKey['rocket']['threshold']);
        self::assertSame('level', $byKey['crown']['gate']);
        self::assertSame(10, $byKey['crown']['threshold']);
        self::assertSame('medal', $byKey['fire']['gate']);
        self::assertSame('faithful_7', $byKey['fire']['medalKey']);
        self::assertSame('devourer_50', $byKey['book']['medalKey']);
        self::assertSame('veteran_10', $byKey['trophy']['medalKey']);
    }
}
