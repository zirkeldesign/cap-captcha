<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Integration;

use WP_Error;
use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

final class Registration implements Integration
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Renderer $renderer,
        private readonly Enqueuer $enqueuer,
        private readonly TokenVerifier $verifier,
    ) {}

    public function id(): string
    {
        return 'registration';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    private bool $lastFailOpen = false;

    public function register(): void
    {
        add_action('register_form', [$this, 'renderWidget']);
        add_action('login_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('login_footer', [Login::class, 'printScriptModulesOnLogin']);
        add_filter('registration_errors', [$this, 'verifyRegistration']);
        add_action('user_register', [$this, 'annotateFailOpen']);
    }

    public function enqueueAssets(): void
    {
        if (! $this->settings->isProtected('registration') || ! $this->settings->isConfigured()) {
            return;
        }
        $this->enqueuer->enqueueForMode($this->settings->getDisplayMode());
    }

    public function renderWidget(): void
    {
        if (! $this->settings->isProtected('registration')) {
            return;
        }

        if (! $this->settings->isConfigured()) {
            echo wp_kses($this->renderer->renderAdminNotice(__('Registration', 'privacy-captcha-for-cap')), Renderer::ADMIN_NOTICE_KSES);

            return;
        }

        $mode = $this->settings->getDisplayMode();
        $this->enqueuer->printGlobals();

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer returns a <cap-widget> custom element with dynamic data-cap-i18n-* attributes wp_kses cannot allowlist; every interpolated value is escaped via esc_attr()/esc_html() inside Renderer.
        echo $this->renderer->renderForMode('cap-captcha-register', $mode);
    }

    public function verifyRegistration(WP_Error $errors): WP_Error
    {
        if (! $this->settings->isProtected('registration')) {
            return $errors;
        }

        if (! $this->verifier->verifyCurrentRequest('registration')) {
            $errors->add(
                'cap_captcha_failed',
                esc_html__('CAPTCHA verification failed. Please try again.', 'privacy-captcha-for-cap')
            );

            return $errors;
        }

        $this->lastFailOpen = $this->verifier->wasLastFailOpen();

        return $errors;
    }

    /**
     * Tag a user created through a registration that only passed because Cap
     * was unreachable.
     *
     * @param  int|string  $userId
     */
    public function annotateFailOpen($userId): void
    {
        if (! $this->lastFailOpen) {
            return;
        }

        $this->lastFailOpen = false;
        add_user_meta((int) $userId, 'cap_captcha_fail_open', 1, true);
        do_action('cap_captcha_fail_open_pass', 'registration', ['user_id' => (int) $userId]);
    }
}
