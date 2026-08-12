<?php

/**
 * Plugin Name:       Privacy CAPTCHA for Cap
 * Plugin URI:        https://github.com/zirkeldesign/privacy-captcha-for-cap
 * Description:       Privacy-friendly spam protection for WordPress comments, login, registration, WooCommerce checkout, and Gravity Forms, powered by your own Cap (trycap.dev) server.
 * Version:           1.3.1
 * Author:            Zirkel Design
 * Author URI:        https://zirkel.design
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       privacy-captcha-for-cap
 * Domain Path:       /languages
 * Requires PHP:      8.3
 * Requires at least: 6.5
 */

declare(strict_types=1);
use ZirkelDesign\CapCaptcha\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

define('CAP_CAPTCHA_VERSION', '1.3.1');
define('CAP_CAPTCHA_FILE', __FILE__);
define('CAP_CAPTCHA_DIR', plugin_dir_path(__FILE__));
define('CAP_CAPTCHA_URL', plugin_dir_url(__FILE__));

// Composer's autoloader when it exists (dev checkouts), our own otherwise.
// The plugin has no runtime dependencies, so the shipped zip carries neither.
if (file_exists(CAP_CAPTCHA_DIR.'vendor/autoload.php')) {
    require_once CAP_CAPTCHA_DIR.'vendor/autoload.php';
} else {
    require_once CAP_CAPTCHA_DIR.'autoload.php';
}

// No form plugin is required to boot: Plugin::boot() asks each integration
// whether its host plugin is present and skips the ones that aren't, so
// comments, login, registration and WooCommerce work on their own.
add_action('plugins_loaded', static function (): void {
    Plugin::boot();
});
