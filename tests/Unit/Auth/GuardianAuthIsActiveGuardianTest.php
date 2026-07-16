<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Auth;

use GuardKids\Auth\GuardianAuth;
use GuardKids\Database\GuardianRepository;
use PHPUnit\Framework\TestCase;

/**
 * currentRole() dá 'admin' por manage_options SEM exigir linha em `guardians`,
 * mas GuardianRepository::findActive() só enxerga quem tem linha. Resolver
 * destinatários de push por findActive() deixaria o admin WP dono da
 * instalação sem push nenhum — daí este método existir.
 */
final class GuardianAuthIsActiveGuardianTest extends TestCase
{
    /**
     * @param array<int, array<string, mixed>> $guardianRows
     */
    private function bootWpdb(array $guardianRows): void
    {
        $wpdb = new class ($guardianRows) extends \wpdb {
            public string $prefix = 'wp_';

            /** @param array<int, array<string, mixed>> $rows */
            public function __construct(private array $rows)
            {
            }

            public function prepare($query, ...$args)
            {
                $flat = $args[0] ?? null;
                if (is_array($flat)) {
                    $args = $flat;
                }
                return vsprintf(str_replace(['%d', '%s'], ['%d', "'%s'"], (string) $query), $args);
            }

            public function get_results($query = null, $output = ARRAY_A)
            {
                if (preg_match('/wp_user_id = (\d+)/', (string) $query, $m) === 1) {
                    return array_values(array_filter(
                        $this->rows,
                        static fn (array $r): bool => (int) ($r['wp_user_id'] ?? 0) === (int) $m[1],
                    ));
                }
                return [];
            }
        };
        $GLOBALS['wpdb'] = $wpdb;
    }

    protected function setUp(): void
    {
        $GLOBALS['gk_caps_by_user'] = [];
    }

    /** O caso que quase escapou do design. */
    public function testWpAdminWithoutGuardianRowIsActive(): void
    {
        $this->bootWpdb([]);
        $GLOBALS['gk_caps_by_user'][1] = ['manage_options' => true];

        self::assertTrue(GuardianAuth::isActiveGuardian(1, new GuardianRepository()));
    }

    public function testActiveGuardianRowIsActive(): void
    {
        $this->bootWpdb([['wp_user_id' => 5, 'role' => 'collaborator', 'status' => 'active']]);

        self::assertTrue(GuardianAuth::isActiveGuardian(5, new GuardianRepository()));
    }

    public function testInactiveGuardianRowIsNotActive(): void
    {
        $this->bootWpdb([['wp_user_id' => 5, 'role' => 'collaborator', 'status' => 'pending']]);

        self::assertFalse(GuardianAuth::isActiveGuardian(5, new GuardianRepository()));
    }

    public function testUnknownUserIsNotActive(): void
    {
        $this->bootWpdb([]);

        self::assertFalse(GuardianAuth::isActiveGuardian(99, new GuardianRepository()));
    }

    public function testZeroIsNotActive(): void
    {
        $this->bootWpdb([]);

        self::assertFalse(GuardianAuth::isActiveGuardian(0, new GuardianRepository()));
    }
}
