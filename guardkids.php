<?php
/**
 * Plugin Name:       GuardKids WP
 * Description:       Controle parental web premium — painel dos pais, painel infantil e navegador seguro, com PWA instalável.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            GuardKids
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       guardkids
 * Domain Path:       /languages
 *
 * @package GuardKids
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('GUARDKIDS_VERSION', '0.1.0');
define('GUARDKIDS_DB_VERSION', 1);
define('GUARDKIDS_FILE', __FILE__);
define('GUARDKIDS_DIR', plugin_dir_path(__FILE__));
define('GUARDKIDS_URL', plugin_dir_url(__FILE__));

$guardkids_autoload = GUARDKIDS_DIR . 'vendor/autoload.php';

if (! is_readable($guardkids_autoload)) {
    add_action(
        'admin_notices',
        static function (): void {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html__(
                    'GuardKids WP: dependências não instaladas. Execute "composer install" na pasta do plugin.',
                    'guardkids'
                )
            );
        }
    );

    return;
}

require_once $guardkids_autoload;

GuardKids\Plugin::instance()->boot();
