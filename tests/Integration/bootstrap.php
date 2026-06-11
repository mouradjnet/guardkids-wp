<?php

declare(strict_types=1);

/**
 * Bootstrap dos integration tests do GuardKids.
 *
 * Diferente do bootstrap unit (tests/bootstrap.php), aqui:
 *   1. wpdb é um adapter real-mysql (Support/MysqliWpdb.php)
 *   2. dbDelta executa o SQL direto contra MySQL real
 *   3. Migrations rodam uma vez na suite (drop & recreate)
 *   4. IntegrationTestCase::setUp faz TRUNCATE entre testes
 *
 * Config vem de env vars (defaults em phpunit-integration.xml.dist):
 *   GUARDKIDS_TEST_DB_HOST / PORT / USER / PASS / NAME
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/Autoloader.php';
require_once __DIR__ . '/Support/MysqliWpdb.php';

define('GUARDKIDS_DIR', dirname(__DIR__, 2) . '/');
define('GUARDKIDS_FILE', dirname(__DIR__, 2) . '/guardkids.php');

(new GuardKids\Autoloader())->register();

spl_autoload_register(static function (string $class): void {
    $prefix = 'GuardKids\\Tests\\Integration\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file     = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($file)) {
        require $file;
    }
});

// --- WP function stubs (devem ficar em sincronia com tests/bootstrap.php) ---

if (! function_exists('current_time')) {
    function current_time(string $type, $gmt = 0): string
    {
        if ($type === 'mysql') {
            return gmdate('Y-m-d H:i:s');
        }
        return (string) time();
    }
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode($value, int $options = 0, int $depth = 512): string|false
    {
        return json_encode($value, $options, $depth);
    }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string
    {
        return trim($str);
    }
}

if (! function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field(string $str): string
    {
        return trim($str);
    }
}

if (! function_exists('sanitize_title')) {
    function sanitize_title(string $str): string
    {
        return strtolower(preg_replace('/[^a-z0-9-]+/i', '-', $str) ?? '');
    }
}

if (! function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string
    {
        return $url;
    }
}

if (! function_exists('trailingslashit')) {
    function trailingslashit(string $string): string
    {
        return rtrim($string, '/\\') . '/';
    }
}

$GLOBALS['gk_options'] = [];

if (! function_exists('get_option')) {
    function get_option(string $key, $default = false)
    {
        return $GLOBALS['gk_options'][$key] ?? $default;
    }
}

if (! function_exists('update_option')) {
    function update_option(string $key, $value, $autoload = null): bool
    {
        $GLOBALS['gk_options'][$key] = $value;
        return true;
    }
}

if (! function_exists('delete_option')) {
    function delete_option(string $key): bool
    {
        if (! array_key_exists($key, $GLOBALS['gk_options'])) {
            return false;
        }
        unset($GLOBALS['gk_options'][$key]);
        return true;
    }
}

if (! defined('OBJECT')) define('OBJECT', 'OBJECT');
if (! defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
if (! defined('ARRAY_N')) define('ARRAY_N', 'ARRAY_N');

// ABSPATH + dbDelta stub. dbDelta delega ao $wpdb real, executando o SQL direto.
// MigrationRunner faz require_once ABSPATH . 'wp-admin/includes/upgrade.php'.
if (! defined('ABSPATH')) {
    $abspath = sys_get_temp_dir() . '/gk-wp-integration/';
    if (! is_dir($abspath . 'wp-admin/includes')) {
        mkdir($abspath . 'wp-admin/includes', 0777, true);
    }
    $upgradeStub = $abspath . 'wp-admin/includes/upgrade.php';
    file_put_contents(
        $upgradeStub,
        <<<'PHP'
<?php
if (!function_exists('dbDelta')) {
    function dbDelta($sql) {
        global $wpdb;
        $statements = is_array($sql) ? $sql : [$sql];
        foreach ($statements as $statement) {
            $wpdb->query($statement);
        }
        return [];
    }
}

PHP,
    );
    define('ABSPATH', $abspath);
}

// --- Conecta ao MySQL real ---

$host = getenv('GUARDKIDS_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('GUARDKIDS_TEST_DB_PORT') ?: 3307);
$user = getenv('GUARDKIDS_TEST_DB_USER') ?: 'root';
$pass = getenv('GUARDKIDS_TEST_DB_PASS') ?: 'root';
$name = getenv('GUARDKIDS_TEST_DB_NAME') ?: 'guardkids_test';

// MYSQLI_REPORT_OFF pra que queries com erro (ex.: UNIQUE violation)
// retornem false em vez de lançar — comportamento que o wpdb do WP simula.
mysqli_report(MYSQLI_REPORT_OFF);

$mysqli = @new \mysqli($host, $user, $pass, $name, $port);
if ($mysqli->connect_error) {
    fwrite(STDERR, "GuardKids integration: nao consegui conectar em mysql://{$user}@{$host}:{$port}/{$name}\n");
    fwrite(STDERR, "Detalhe: " . $mysqli->connect_error . "\n");
    fwrite(STDERR, "Sobe o MySQL com: docker compose -f docker-compose.test.yml up -d\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');

/** @var wpdb $wpdb */
$wpdb = new wpdb($mysqli);
$GLOBALS['wpdb'] = $wpdb;

// Drop tabelas existentes + roda migrations limpas
$tables = ['settings', 'categories', 'sites', 'requests', 'usage_events', 'locations', 'safe_zones', 'children'];
foreach ($tables as $t) {
    $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}guardkids_{$t}`");
}
$GLOBALS['gk_options'] = []; // reseta guardkids_db_version

(new GuardKids\Database\MigrationRunner(GUARDKIDS_DIR . 'database/migrations'))->run();
