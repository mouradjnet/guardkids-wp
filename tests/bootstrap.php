<?php

declare(strict_types=1);

/**
 * Bootstrap dos testes unitários.
 *
 * Tests aqui são puros — não carregam o WordPress, não conectam no banco.
 * Funções do WP que o código sob teste usa ficam stubadas neste arquivo.
 *
 * Para testes de integração que precisem do WP real, criar tests/Integration
 * com bootstrap separado (ainda a fazer; depende de wp-env ou setup
 * cuidadoso contra LocalWP).
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Autoloader self-contained do plugin pra estar disponível nos testes
require_once __DIR__ . '/../includes/Autoloader.php';

define('GUARDKIDS_DIR', dirname(__DIR__) . '/');
define('GUARDKIDS_FILE', __DIR__ . '/../guardkids.php');

(new GuardKids\Autoloader())->register();

// Autoload de helpers de teste (GuardKids\Tests\Support\…)
spl_autoload_register(static function (string $class): void {
    $prefix = 'GuardKids\\Tests\\Support\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file     = __DIR__ . '/Support/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($file)) {
        require $file;
    }
});

// --- Stubs mínimos das funções do WP que o código sob teste chama ---

if (! function_exists('current_time')) {
    /**
     * @param string $type
     * @param bool|int $gmt
     */
    function current_time(string $type, $gmt = 0): string
    {
        if ($type === 'mysql') {
            return gmdate('Y-m-d H:i:s');
        }
        return (string) time();
    }
}

if (! function_exists('wp_json_encode')) {
    /**
     * @param mixed $value
     */
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

// Storage in-memory pra wp_options usado por testes do MigrationRunner.
$GLOBALS['gk_options'] = [];

if (! function_exists('get_option')) {
    /**
     * @param mixed $default
     * @return mixed
     */
    function get_option(string $key, $default = false)
    {
        return $GLOBALS['gk_options'][$key] ?? $default;
    }
}

if (! function_exists('update_option')) {
    /**
     * @param mixed $value
     * @param bool|null $autoload
     */
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

// Storage in-memory pra transients (TTL ignorado nos testes — RateLimiter
// não depende de tempo real; é resetado entre testes via setUp).
$GLOBALS['gk_transients'] = [];

if (! function_exists('get_transient')) {
    /**
     * @return mixed
     */
    function get_transient(string $key)
    {
        return $GLOBALS['gk_transients'][$key] ?? false;
    }
}

if (! function_exists('set_transient')) {
    /**
     * @param mixed $value
     */
    function set_transient(string $key, $value, int $ttl = 0): bool
    {
        $GLOBALS['gk_transients'][$key] = $value;
        return true;
    }
}

if (! function_exists('delete_transient')) {
    function delete_transient(string $key): bool
    {
        if (! array_key_exists($key, $GLOBALS['gk_transients'])) {
            return false;
        }
        unset($GLOBALS['gk_transients'][$key]);
        return true;
    }
}

// Storage in-memory pra WP-Cron events usado pelos testes do Plugin/Purger.
$GLOBALS['gk_cron_events'] = [];

if (! function_exists('wp_next_scheduled')) {
    /**
     * @return int|false timestamp do próximo run, ou false se não agendado.
     */
    function wp_next_scheduled(string $hook)
    {
        return $GLOBALS['gk_cron_events'][$hook] ?? false;
    }
}

if (! function_exists('wp_schedule_event')) {
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook): bool
    {
        $GLOBALS['gk_cron_events'][$hook] = $timestamp;
        return true;
    }
}

if (! function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook): int
    {
        if (! isset($GLOBALS['gk_cron_events'][$hook])) {
            return 0;
        }
        unset($GLOBALS['gk_cron_events'][$hook]);
        return 1;
    }
}

// Setup mínimo de ABSPATH + stub do wp-admin/includes/upgrade.php que o
// MigrationRunner faz require_once. dbDelta vira no-op nos testes.
if (! defined('ABSPATH')) {
    $abspath = sys_get_temp_dir() . '/gk-wp-tests/';
    if (! is_dir($abspath . 'wp-admin/includes')) {
        mkdir($abspath . 'wp-admin/includes', 0777, true);
    }
    $upgradeStub = $abspath . 'wp-admin/includes/upgrade.php';
    if (! file_exists($upgradeStub)) {
        file_put_contents(
            $upgradeStub,
            "<?php if (!function_exists('dbDelta')) { function dbDelta(\$sql) { return []; } }\n"
        );
    }
    define('ABSPATH', $abspath);
}

// Constantes do wpdb que aparecem em get_row/get_results
if (! defined('OBJECT')) define('OBJECT', 'OBJECT');
if (! defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
if (! defined('ARRAY_N')) define('ARRAY_N', 'ARRAY_N');

// Stub mínimo do \wpdb pra subclasses dos testes
if (! class_exists('wpdb')) {
    class wpdb
    {
        public string $prefix = 'wp_';
        public int $insert_id = 0;
        public string $last_error = '';

        public function prepare($query, ...$args)
        {
            return $query;
        }
        public function get_row($sql, $output = OBJECT, $y = 0)
        {
            return null;
        }
        public function get_var($sql, $x = 0, $y = 0)
        {
            return null;
        }
        public function get_results($sql, $output = OBJECT)
        {
            return [];
        }
        public function insert($table, $data, $format = null)
        {
            return 1;
        }
        public function update($table, $data, $where, $format = null, $where_format = null)
        {
            return 1;
        }
        public function delete($table, $where, $where_format = null)
        {
            return 1;
        }
    }
}

// Stub mínimo do WP_REST_Request pra ChildAuth + Controllers
if (! class_exists('WP_REST_Request')) {
    class WP_REST_Request implements ArrayAccess
    {
        /** @var array<string, string> */
        private array $headers = [];

        /** @var array<string, mixed> */
        private array $params = [];

        /** @var mixed */
        private $jsonBody = null;

        public function __construct(private string $method = '', private string $route = '')
        {
        }

        public function set_header(string $key, string $value): void
        {
            $this->headers[strtolower(str_replace('-', '_', $key))] = $value;
        }

        public function get_header(string $key): string
        {
            $normalized = strtolower(str_replace('-', '_', $key));
            return $this->headers[$normalized] ?? '';
        }

        public function get_route(): string
        {
            return $this->route;
        }

        public function get_method(): string
        {
            return $this->method;
        }

        /**
         * @param mixed $value
         */
        public function set_param(string $key, $value): void
        {
            $this->params[$key] = $value;
        }

        /**
         * @return mixed
         */
        public function get_param(string $key)
        {
            return $this->params[$key] ?? null;
        }

        /**
         * @param array<string, mixed> $body
         */
        public function set_json_params(array $body): void
        {
            $this->jsonBody = $body;
        }

        /**
         * @return mixed
         */
        public function get_json_params()
        {
            return $this->jsonBody;
        }

        // ArrayAccess pra (string) $req['id'] funcionar — controllers usam isso
        public function offsetExists(mixed $offset): bool
        {
            return isset($this->params[$offset]);
        }

        public function offsetGet(mixed $offset): mixed
        {
            return $this->params[$offset] ?? null;
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            $this->params[(string) $offset] = $value;
        }

        public function offsetUnset(mixed $offset): void
        {
            unset($this->params[$offset]);
        }
    }
}

// Stub do WP_Error retornado pelos controllers em casos de erro
if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public string $code;
        public string $message;
        /** @var array<string, mixed> */
        public array $data;

        public function __construct(string $code = '', string $message = '', array $data = [])
        {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_data(): array
        {
            return $this->data;
        }
    }
}

// home_url — usado pelo PrivacyExporter
if (! function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return 'http://test.local' . $path;
    }
}

// rest_ensure_response — usado pelos controllers
if (! function_exists('rest_ensure_response')) {
    function rest_ensure_response($value)
    {
        if ($value instanceof WP_REST_Response) {
            return $value;
        }
        if ($value instanceof WP_Error) {
            return $value;
        }
        return new WP_REST_Response($value);
    }
}

// get_current_user_id — usado por RequestController::decide
if (! function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return (int) ($GLOBALS['gk_current_user_id'] ?? 0);
    }
}

if (! function_exists('current_user_can')) {
    function current_user_can(string $cap): bool
    {
        return (bool) ($GLOBALS['gk_user_caps'][$cap] ?? false);
    }
}

if (! function_exists('get_userdata')) {
    function get_userdata(int $userId): object|false
    {
        $user = $GLOBALS['gk_users'][$userId] ?? null;
        return $user === null ? false : (object) $user;
    }
}

if (! function_exists('wp_get_current_user')) {
    function wp_get_current_user(): object
    {
        $id = (int) ($GLOBALS['gk_current_user_id'] ?? 0);
        $user = $GLOBALS['gk_users'][$id] ?? null;
        if ($user === null) {
            return (object) ['ID' => 0, 'user_email' => '', 'display_name' => '', 'user_login' => ''];
        }
        return (object) $user;
    }
}

// Stub mínimo do WP_REST_Response e WP_REST_Server pra RestHeaders/Controllers
if (! class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public mixed $data;
        public int $status;
        /** @var array<string, string> */
        public array $headers = [];

        public function __construct(mixed $data = null, int $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }

        public function header(string $name, string $value): void
        {
            $this->headers[$name] = $value;
        }

        public function get_status(): int
        {
            return $this->status;
        }

        public function get_data(): mixed
        {
            return $this->data;
        }
    }
}

if (! class_exists('WP_REST_Server')) {
    class WP_REST_Server
    {
        public const READABLE  = 'GET';
        public const CREATABLE = 'POST';
        public const EDITABLE  = 'POST, PUT, PATCH';
        public const DELETABLE = 'DELETE';
    }
}
