<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/vendor/autoload.php';

if (! defined('CAP_CAPTCHA_VERSION')) {
    define('CAP_CAPTCHA_VERSION', '1.0.0-test');
}
if (! defined('CAP_CAPTCHA_FILE')) {
    define('CAP_CAPTCHA_FILE', __FILE__);
}
if (! defined('CAP_CAPTCHA_DIR')) {
    define('CAP_CAPTCHA_DIR', dirname(__DIR__).'/');
}
if (! defined('CAP_CAPTCHA_URL')) {
    define('CAP_CAPTCHA_URL', 'http://localhost/wp-content/plugins/cap-captcha/');
}

if (! function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim(preg_replace('/[\r\n\t]+|\s+/u', ' ', $value) ?? $value);
    }
}

if (! function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return $GLOBALS['__cap_options'][$name] ?? $default;
    }
}

if (! function_exists('update_option')) {
    function update_option(string $name, mixed $value): bool
    {
        $GLOBALS['__cap_options'][$name] = $value;

        return true;
    }
}

/**
 * Reset the in-memory wp_options store between tests.
 */
function cap_reset_options(): void
{
    $GLOBALS['__cap_options'] = [];
}

if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (! function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (! function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (! function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('add_filter')) {
    function add_filter(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['__cap_filters'][$hook_name][] = ['cb' => $callback, 'args' => $accepted_args];

        return true;
    }
}

if (! function_exists('apply_filters')) {
    function apply_filters(string $hook_name, mixed $value, mixed ...$args): mixed
    {
        foreach ($GLOBALS['__cap_filters'][$hook_name] ?? [] as $entry) {
            $passArgs = array_slice($args, 0, max(0, $entry['args'] - 1));
            $value = ($entry['cb'])($value, ...$passArgs);
        }

        return $value;
    }
}

/**
 * Reset the in-memory filter registry between tests.
 */
function cap_reset_filters(): void
{
    $GLOBALS['__cap_filters'] = [];
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $options = 0, int $depth = 512): string|false
    {
        return json_encode($data, $options, $depth);
    }
}

if (! function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof \WP_Error;
    }
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(public string $code = '', public string $message = '') {}
    }
}

if (! function_exists('wp_remote_post')) {
    /**
     * Test stub. Reads from $GLOBALS['__cap_remote_response'] and records the last request in
     * $GLOBALS['__cap_remote_last_request'].
     *
     * @param  array<string, mixed>  $args
     */
    function wp_remote_post(string $url, array $args = []): array|\WP_Error
    {
        $GLOBALS['__cap_remote_last_request'] = ['url' => $url, 'args' => $args];

        $response = $GLOBALS['__cap_remote_response'] ?? ['body' => '{"success":true}'];

        if ($response instanceof \WP_Error) {
            return $response;
        }

        return $response;
    }
}

if (! function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body(mixed $response): string
    {
        if (is_array($response) && isset($response['body'])) {
            return (string) $response['body'];
        }

        return '';
    }
}

/**
 * Reset the wp_remote_post stub between tests.
 */
function cap_reset_remote_stub(): void
{
    unset($GLOBALS['__cap_remote_response'], $GLOBALS['__cap_remote_last_request']);
}
