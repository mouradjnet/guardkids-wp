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

// Stub mínimo do WP_REST_Request pra ChildAuth
if (! class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @var array<string, string> */
        private array $headers = [];

        public function __construct(string $method = '', string $route = '')
        {
            // ignora — só pra interface
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
    }
}
