<?php

declare(strict_types=1);

namespace GuardKids\Security;

/**
 * Códigos de recuperação de uso único pra 2FA. Texto mostrado uma vez na
 * geração; persistido só como `password_hash` (mesmo padrão do ChildPin).
 */
final class RecoveryCodes
{
    private const COUNT = 10;

    /** @return array<int, string> */
    public function generate(int $n = self::COUNT): array
    {
        $codes = [];
        for ($i = 0; $i < $n; $i++) {
            $raw     = bin2hex(random_bytes(5));
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
        }
        return $codes;
    }

    /**
     * @param array<int, string> $codes
     * @return array<int, string>
     */
    public function hashAll(array $codes): array
    {
        return array_map(
            static fn (string $c): string => password_hash(self::normalize($c), PASSWORD_DEFAULT),
            $codes,
        );
    }

    /**
     * Devolve a lista de hashes sem o que casou (consumido), ou null se
     * nenhum casar. Fail-closed.
     *
     * @param array<int, string> $hashes
     * @return array<int, string>|null
     */
    public function verifyAndConsume(string $code, array $hashes): ?array
    {
        $norm = self::normalize($code);
        foreach ($hashes as $i => $hash) {
            if (password_verify($norm, $hash)) {
                unset($hashes[$i]);
                return array_values($hashes);
            }
        }
        return null;
    }

    private static function normalize(string $code): string
    {
        return strtolower(str_replace([' ', '-'], '', trim($code)));
    }
}
