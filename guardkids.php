<?php
/**
 * Plugin Name:       GuardKids WP
 * Description:       Controle parental web premium — painel dos pais, painel infantil e navegador seguro, com PWA instalável.
 * Version:           1.36.2
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Djair Falcão
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       guardkids
 * Domain Path:       /languages
 *
 * @package GuardKids
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('GUARDKIDS_VERSION', '1.35.0');
define('GUARDKIDS_DB_VERSION', 25);
define('GUARDKIDS_FILE', __FILE__);
define('GUARDKIDS_DIR', plugin_dir_path(__FILE__));
define('GUARDKIDS_URL', plugin_dir_url(__FILE__));

// Base REST do license server (phone-home de revogação). Trocar pelo domínio real
// do servidor no deploy; enquanto for placeholder, o refresh falha aberto (no-op).
if (! defined('GK_LICENSE_SERVER_BASE')) {
    define('GK_LICENSE_SERVER_BASE', 'https://SEU-LICENSE-SERVER/wp-json/gkl/v1/');
}

require_once GUARDKIDS_DIR . 'includes/Autoloader.php';

(new GuardKids\Autoloader())->register();

GuardKids\Plugin::instance()->boot();
