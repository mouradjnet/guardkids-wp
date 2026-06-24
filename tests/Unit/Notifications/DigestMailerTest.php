<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Notifications;

use GuardKids\Database\GuardianRepository;
use GuardKids\Database\SettingsRepository;
use GuardKids\Notifications\DigestData;
use GuardKids\Notifications\DigestMailer;
use PHPUnit\Framework\TestCase;

final class DigestMailerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['gk_wp_mail_log'] = [];
    }

    /**
     * @param mixed $toggleValue valor cru do get_var de settings (string JSON ou null)
     * @param array<int, array<string, mixed>> $guardians
     */
    private function installWpdb($toggleValue, array $guardians): void
    {
        $GLOBALS['wpdb'] = new class ($toggleValue, $guardians) extends \wpdb {
            public string $prefix = 'wp_';

            /** @param mixed $toggleValue @param array<int, array<string, mixed>> $guardians */
            public function __construct(private $toggleValue, private array $guardians)
            {
            }

            public function prepare($query, ...$args)
            {
                return (string) $query;
            }

            public function get_results($query = null, $output = ARRAY_A)
            {
                if (str_contains((string) $query, 'guardkids_guardians')) {
                    return $this->guardians;
                }
                if (str_contains((string) $query, 'guardkids_children')) {
                    return [['name' => 'Lucas', 'used_minutes' => 10, 'limit_minutes' => 60, 'secs' => 0, 'id' => 1]];
                }
                return [];
            }

            public function get_var($query = null, $x = 0, $y = 0)
            {
                if (str_contains((string) $query, 'guardkids_settings')) {
                    return $this->toggleValue;
                }
                return 0;
            }
        };
    }

    public function testSendDailySkipsWhenToggleOff(): void
    {
        $this->installWpdb(null, [['email' => 'a@b.com', 'status' => 'active']]);

        $sent = (new DigestMailer(new DigestData(), new GuardianRepository(), new SettingsRepository()))->sendDaily();

        self::assertSame(0, $sent);
        self::assertSame([], $GLOBALS['gk_wp_mail_log']);
    }

    public function testSendDailyMailsActiveGuardiansWhenOn(): void
    {
        $this->installWpdb('true', [
            ['email' => 'a@b.com', 'status' => 'active'],
            ['email' => 'c@d.com', 'status' => 'active'],
        ]);

        $sent = (new DigestMailer(new DigestData(), new GuardianRepository(), new SettingsRepository()))->sendDaily();

        self::assertSame(2, $sent);
        self::assertCount(2, $GLOBALS['gk_wp_mail_log']);
        self::assertSame('a@b.com', $GLOBALS['gk_wp_mail_log'][0][0]);
        self::assertStringContainsString('text/html', $GLOBALS['gk_wp_mail_log'][0][3][0]);
        self::assertNotSame('', $GLOBALS['gk_wp_mail_log'][0][2]);
    }

    public function testSendWeeklySkipsWhenToggleOff(): void
    {
        $this->installWpdb(null, [['email' => 'a@b.com', 'status' => 'active']]);

        $sent = (new DigestMailer(new DigestData(), new GuardianRepository(), new SettingsRepository()))->sendWeekly();

        self::assertSame(0, $sent);
        self::assertSame([], $GLOBALS['gk_wp_mail_log']);
    }
}
