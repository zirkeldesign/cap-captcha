<?php

/**
 * Plugin Name:       Privacy CAPTCHA for Cap
 * Plugin URI:        https://github.com/zirkeldesign/privacy-captcha-for-cap
 * Description:       Privacy-friendly spam protection for WordPress comments, login, registration, WooCommerce checkout, and Gravity Forms, powered by your own Cap (trycap.dev) server.
 * Version:           1.2.3
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

define('CAP_CAPTCHA_VERSION', '1.2.3');
define('CAP_CAPTCHA_FILE', __FILE__);
define('CAP_CAPTCHA_DIR', plugin_dir_path(__FILE__));
define('CAP_CAPTCHA_URL', plugin_dir_url(__FILE__));

if (file_exists(CAP_CAPTCHA_DIR.'vendor/autoload.php')) {
    require_once CAP_CAPTCHA_DIR.'vendor/autoload.php';
}

// No form plugin is required to boot: Plugin::boot() asks each integration
// whether its host plugin is present and skips the ones that aren't, so
// comments, login, registration and WooCommerce work on their own.
add_action('plugins_loaded', static function (): void {
    // A copy taken straight from the Git repository has no vendor/ directory,
    // so nothing is autoloadable. Say so instead of dying with a class-not-
    // found fatal — the release zips from WordPress.org and the GitHub
    // Releases page do include it.
    if (! class_exists(Plugin::class)) {
        add_action('admin_notices', static function (): void {
            if (! current_user_can('activate_plugins')) {
                return;
            }

            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html__(
                    'Privacy CAPTCHA for Cap is missing its autoloader. Install the release zip from WordPress.org or the GitHub Releases page rather than a copy of the source repository — or run "composer install" inside the plugin folder.',
                    'privacy-captcha-for-cap'
                )
            );
        });

        return;
    }

    Plugin::boot();
});
