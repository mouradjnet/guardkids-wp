<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Security;

use GuardKids\Security\RecoveryCodes;
use PHPUnit\Framework\TestCase;

final class RecoveryCodesTest extends TestCase
{
    public function testGenerateProducesTenFormattedCodes(): void
    {
        $codes = (new RecoveryCodes())->generate();
        self::assertCount(10, $codes);
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{5}-[0-9a-f]{5}$/', $code);
        }
    }

    public function testHashAllNeverStoresPlaintext(): void
    {
        $rc     = new RecoveryCodes();
        $codes  = $rc->generate();
        $hashes = $rc->hashAll($codes);
        self::assertCount(10, $hashes);
        self::assertStringNotContainsString($codes[0], implode('', $hashes));
    }

    public function testVerifyAndConsumeRemovesMatchedCode(): void
    {
        $rc     = new RecoveryCodes();
        $codes  = $rc->generate();
        $hashes = $rc->hashAll($codes);

        $remaining = $rc->verifyAndConsume($codes[0], $hashes);
        self::assertIsArray($remaining);
        self::assertCount(9, $remaining);
        // Reuso do mesmo código falha contra a lista já reduzida.
        self::assertNull($rc->verifyAndConsume($codes[0], $remaining));
    }

    public function testVerifyAndConsumeIgnoresCaseAndDashes(): void
    {
        $rc     = new RecoveryCodes();
        $codes  = $rc->generate();
        $hashes = $rc->hashAll($codes);
        $messy  = strtoupper(str_replace('-', ' ', $codes[0]));
        self::assertIsArray($rc->verifyAndConsume($messy, $hashes));
    }

    public function testVerifyAndConsumeReturnsNullForUnknownCode(): void
    {
        $rc     = new RecoveryCodes();
        $hashes = $rc->hashAll($rc->generate());
        self::assertNull($rc->verifyAndConsume('00000-00000', $hashes));
    }
}
