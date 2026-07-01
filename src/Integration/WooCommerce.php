<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Integration;

use WP_Error;
use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

final class WooCommerce implements Integration
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Renderer $renderer,
        private readonly Enqueuer $enqueuer,
        private readonly TokenVerifier $verifier,
    ) {}

    public function id(): string
    {
        return 'woocommerce';
    }

    public function isAvailable(): bool
    {
        return class_exists('WooCommerce');
    }

    public const WIDGET_ID = 'cap-captcha-checkout';

    public const LOGIN_WIDGET_ID = 'cap-captcha-wc-login';

    public const REGISTER_WIDGET_ID = 'cap-captcha-wc-register';

    public const LOST_PASSWORD_WIDGET_ID = 'cap-captcha-wc-lost-password';

    private bool $lastCheckoutFailOpen = false;

    public function register(): void
    {
        // Checkout.
        add_action('woocommerce_after_checkout_billing_form', [$this, 'renderWidget']);
        add_filter('woocommerce_order_button_html', [$this, 'attachFloatingAttrsToSubmit']);
        add_action('woocommerce_checkout_process', [$this, 'verifyCheckout']);
        add_action('woocommerce_checkout_order_processed', [$this, 'annotateCheckoutFailOpen']);

        // My Account — login.
        add_action('woocommerce_login_form', [$this, 'renderLoginWidget']);
        add_filter('woocommerce_process_login_errors', [$this, 'verifyLogin'], 10, 1);

        // My Account — registration.
        add_action('woocommerce_register_form', [$this, 'renderRegisterWidget']);
        add_filter('woocommerce_registration_errors', [$this, 'verifyRegistration'], 10, 1);

        // My Account — lost password.
        add_action('woocommerce_lostpassword_form', [$this, 'renderLostPasswordWidget']);
        add_action('lostpassword_post', [$this, 'verifyLostPassword'], 10, 1);
    }

    public function renderWidget(): void
    {
        if (! $this->settings->isProtected('woocommerce_checkout')) {
            return;
        }

        if (! $this->settings->isConfigured()) {
            echo wp_kses($this->renderer->renderAdminNotice(__('WooCommerce checkout', 'privacy-captcha-for-cap')), Renderer::ADMIN_NOTICE_KSES);

            return;
        }

        $mode = $this->settings->getDisplayMode();
        $this->enqueuer->enqueueForMode($mode);
        $this->enqueuer->printGlobals();

        // WC hooks woocommerce_order_button_html (below) so the existing
        // Place Order button becomes the floating trigger — no extra UI.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer returns a <cap-widget> custom element with dynamic data-cap-i18n-* attributes wp_kses cannot allowlist; every interpolated value is escaped via esc_attr()/esc_html() inside Renderer.
        echo $this->renderer->renderForMode(self::WIDGET_ID, $mode, false);
    }

    public function attachFloatingAttrsToSubmit(string $button): string
    {
        if (! $this->settings->isProtected('woocommerce_checkout') || ! $this->settings->isConfigured() || ! $this->settings->isFloating()) {
            return $button;
        }

        return Renderer::addFloatingAttrsToSubmit($button, self::WIDGET_ID);
    }

    public function verifyCheckout(): void
    {
        if (! $this->settings->isProtected('woocommerce_checkout')) {
            return;
        }

        if (! $this->verifier->verifyCurrentRequest('woocommerce_checkout')) {
            if (function_exists('wc_add_notice')) {
                wc_add_notice(
                    esc_html__('CAPTCHA verification failed. Please try again.', 'privacy-captcha-for-cap'),
                    'error'
                );
            }

            return;
        }

        $this->lastCheckoutFailOpen = $this->verifier->wasLastFailOpen();
    }

    /**
     * Tag an order placed during a Cap outage (fail-open) with order meta and a
     * note, so it can be reviewed later.
     *
     * @param  int|string  $orderId
     */
    public function annotateCheckoutFailOpen($orderId): void
    {
        if (! $this->lastCheckoutFailOpen) {
            return;
        }

        $this->lastCheckoutFailOpen = false;

        $order = function_exists('wc_get_order') ? wc_get_order($orderId) : null;
        if (is_object($order) && method_exists($order, 'update_meta_data')) {
            $order->update_meta_data('cap_captcha_fail_open', 1);
            $order->add_order_note(esc_html__('Placed during a Cap outage — CAPTCHA verification was skipped (fail-open).', 'privacy-captcha-for-cap'));
            $order->save();
        }

        do_action('cap_captcha_fail_open_pass', 'woocommerce_checkout', ['order_id' => (int) $orderId]);
    }

    public function renderLoginWidget(): void
    {
        $this->renderAccountWidget('woocommerce_login', self::LOGIN_WIDGET_ID, __('WooCommerce login', 'privacy-captcha-for-cap'));
    }

    public function renderRegisterWidget(): void
    {
        $this->renderAccountWidget('woocommerce_registration', self::REGISTER_WIDGET_ID, __('WooCommerce registration', 'privacy-captcha-for-cap'));
    }

    public function renderLostPasswordWidget(): void
    {
        $this->renderAccountWidget('woocommerce_lost_password', self::LOST_PASSWORD_WIDGET_ID, __('WooCommerce lost password', 'privacy-captcha-for-cap'));
    }

    public function verifyLogin(WP_Error $errors): WP_Error
    {
        return $this->verifyAccountForm('woocommerce_login', $errors);
    }

    public function verifyRegistration(WP_Error $errors): WP_Error
    {
        // woocommerce_registration_errors also fires during checkout account
        // creation and programmatic wc_create_new_customer(), where our widget
        // is not rendered. Only validate the actual My Account registration
        // form, identified by its own nonce field.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only detecting which form is posting; WooCommerce verifies this nonce itself before this runs.
        if (! isset($_POST['woocommerce-register-nonce'])) {
            return $errors;
        }

        return $this->verifyAccountForm('woocommerce_registration', $errors);
    }

    public function verifyLostPassword(WP_Error $errors): void
    {
        // lostpassword_post also fires on wp-login.php; only act on the
        // WooCommerce My Account form, identified by its own nonce field.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only detecting which form is posting; WooCommerce verifies this nonce itself before this runs.
        if (! isset($_POST['woocommerce-lost-password-nonce'])) {
            return;
        }

        $this->verifyAccountForm('woocommerce_lost_password', $errors);
    }

    private function renderAccountWidget(string $context, string $widgetId, string $surfaceLabel): void
    {
        if (! $this->settings->isProtected($context)) {
            return;
        }

        if (! $this->settings->isConfigured()) {
            echo wp_kses($this->renderer->renderAdminNotice($surfaceLabel), Renderer::ADMIN_NOTICE_KSES);

            return;
        }

        $mode = $this->settings->getDisplayMode();
        $this->enqueuer->enqueueForMode($mode);
        $this->enqueuer->printGlobals();

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer returns a <cap-widget> custom element with dynamic data-cap-i18n-* attributes wp_kses cannot allowlist; every interpolated value is escaped via esc_attr()/esc_html() inside Renderer.
        echo $this->renderer->renderForMode($widgetId, $mode);
    }

    private function verifyAccountForm(string $context, WP_Error $errors): WP_Error
    {
        if (! $this->settings->isProtected($context)) {
            return $errors;
        }

        if (! $this->verifier->verifyCurrentRequest($context)) {
            $errors->add(
                'cap_captcha_failed',
                esc_html__('CAPTCHA verification failed. Please try again.', 'privacy-captcha-for-cap')
            );
        }

        return $errors;
    }
}
