<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Integration;

use WP_Error;
use WP_User;
use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

final class Login implements Integration
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Renderer $renderer,
        private readonly Enqueuer $enqueuer,
        private readonly TokenVerifier $verifier,
    ) {}

    public function id(): string
    {
        return 'login';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function register(): void
    {
        add_action('login_form', [$this, 'renderWidget']);
        add_action('login_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('login_footer', [self::class, 'printScriptModulesOnLogin']);
        add_filter('wp_authenticate_user', [$this, 'verifyLogin'], 10, 2);
    }

    /**
     * Manually flush enqueued script modules on wp-login.php. Core's
     * WP_Script_Modules printer only hooks into wp_head / wp_footer /
     * admin_print_footer_scripts — none of which fire on the login screen,
     * so without this our `wp_enqueue_script_module()` calls would silently
     * vanish.
     */
    public static function printScriptModulesOnLogin(): void
    {
        if (! function_exists('wp_script_modules')) {
            return;
        }

        $modules = wp_script_modules();
        $modules->print_import_map();
        $modules->print_enqueued_script_modules();
        $modules->print_script_module_preloads();
    }

    public function enqueueAssets(): void
    {
        if (! $this->settings->isProtected('login') || ! $this->settings->isConfigured()) {
            return;
        }

        $mode = $this->settings->getDisplayMode();
        $this->enqueuer->enqueueForMode($mode);
    }

    public function renderWidget(): void
    {
        if (! $this->settings->isProtected('login')) {
            return;
        }

        if (! $this->settings->isConfigured()) {
            echo wp_kses($this->renderer->renderAdminNotice(__('Login', 'privacy-captcha-for-cap')), Renderer::ADMIN_NOTICE_KSES);

            return;
        }

        $mode = $this->settings->getDisplayMode();
        $this->enqueuer->printGlobals();

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer returns a <cap-widget> custom element with dynamic data-cap-i18n-* attributes wp_kses cannot allowlist; every interpolated value is escaped via esc_attr()/esc_html() inside Renderer.
        echo $this->renderer->renderForMode('cap-captcha-login', $mode);
    }

    public function verifyLogin(WP_User|WP_Error $user, string $password): WP_User|WP_Error
    {
        if ($user instanceof WP_Error) {
            return $user;
        }

        if (! $this->settings->isProtected('login')) {
            return $user;
        }

        // WooCommerce My Account logins also flow through wp_authenticate_user.
        // Let the WooCommerce integration's woocommerce_login surface own those
        // so we don't double-handle them (and so they obey their own toggle).
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only detecting which form is posting; WooCommerce verifies its own login nonce before this runs.
        if (isset($_POST['woocommerce-login'])) {
            return $user;
        }

        if (! $this->verifier->verifyCurrentRequest()) {
            return new WP_Error(
                'cap_captcha_failed',
                esc_html__('CAPTCHA verification failed. Please try again.', 'privacy-captcha-for-cap')
            );
        }

        return $user;
    }
}
