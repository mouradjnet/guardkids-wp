<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Avatars;

use GuardKids\Avatars\AvatarEvaluator;
use PHPUnit\Framework\TestCase;

final class AvatarEvaluatorTest extends TestCase
{
    /**
     * @param array{level:int, unlockedMedals:array<int,string>} $signals
     * @return array<string, mixed>
     */
    private function avatar(array $signals, string $key): array
    {
        foreach (AvatarEvaluator::evaluate($signals) as $a) {
            if ($a['key'] === $key) {
                return $a;
            }
        }
        self::fail("avatar {$key} not found");
    }

    public function testFreeAlwaysUnlocked(): void
    {
        $s = ['level' => 1, 'unlockedMedals' => []];
        self::assertTrue($this->avatar($s, 'star')['unlocked']);
        self::assertSame('Grátis', $this->avatar($s, 'star')['requirementLabel']);
    }

    public function testLevelGate(): void
    {
        self::assertFalse($this->avatar(['level' => 4, 'unlockedMedals' => []], 'rocket')['unlocked']);
        self::assertTrue($this->avatar(['level' => 5, 'unlockedMedals' => []], 'rocket')['unlocked']);
        self::assertSame('Nível 5', $this->avatar(['level' => 5, 'unlockedMedals' => []], 'rocket')['requirementLabel']);
    }

    public function testMedalGate(): void
    {
        $off = ['level' => 99, 'unlockedMedals' => []];
        $on  = ['level' => 1, 'unlockedMedals' => ['faithful_7']];
        self::assertFalse($this->avatar($off, 'fire')['unlocked']);
        self::assertTrue($this->avatar($on, 'fire')['unlocked']);
        self::assertStringContainsString('Fiel', $this->avatar($on, 'fire')['requirementLabel']);
    }

    public function testEmojiPresent(): void
    {
        self::assertSame('⭐', $this->avatar(['level' => 1, 'unlockedMedals' => []], 'star')['emoji']);
    }
}
