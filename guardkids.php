<?php
/**
 * Plugin Name:       GuardKids WP
 * Description:       Controle parental web premium — painel dos pais, painel infantil e navegador seguro, com PWA instalável.
 * Version:           1.7.0
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

define('GUARDKIDS_VERSION', '1.7.0');
define('GUARDKIDS_DB_VERSION', 9);
define('GUARDKIDS_FILE', __FILE__);
define('GUARDKIDS_DIR', plugin_dir_path(__FILE__));
define('GUARDKIDS_URL', plugin_dir_url(__FILE__));

require_once GUARDKIDS_DIR . 'includes/Autoloader.php';

(new GuardKids\Autoloader())->register();

GuardKids\Plugin::instance()->boot();
