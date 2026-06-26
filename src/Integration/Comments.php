<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Integration;

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

final class Comments implements Integration
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Renderer $renderer,
        private readonly Enqueuer $enqueuer,
        private readonly TokenVerifier $verifier,
    ) {}

    public function id(): string
    {
        return 'comments';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public const WIDGET_ID = 'cap-captcha-comment';

    private bool $lastFailOpen = false;

    public function register(): void
    {
        add_action('comment_form_after_fields', [$this, 'renderWidget']);
        add_action('comment_form_logged_in_after', [$this, 'renderWidget']);
        add_filter('comment_form_submit_button', [$this, 'attachFloatingAttrsToSubmit']);
        add_filter('preprocess_comment', [$this, 'verifyComment']);
        add_action('comment_post', [$this, 'annotateFailOpen']);
    }

    public function renderWidget(): void
    {
        if (! $this->settings->isProtected('comments')) {
            return;
        }

        if (! $this->settings->isConfigured()) {
            echo wp_kses($this->renderer->renderAdminNotice(__('Comments', 'privacy-captcha-for-cap')), Renderer::ADMIN_NOTICE_KSES);

            return;
        }

        $mode = $this->settings->getDisplayMode();
        $this->enqueuer->enqueueForMode($mode);
        $this->enqueuer->printGlobals();

        // In floating mode we hook comment_form_submit_button (below) so the
        // existing submit button doubles as the trigger — no standalone UI.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer returns a <cap-widget> custom element with dynamic data-cap-i18n-* attributes wp_kses cannot allowlist; every interpolated value is escaped via esc_attr()/esc_html() inside Renderer.
        echo $this->renderer->renderForMode(self::WIDGET_ID, $mode, false);
    }

    public function attachFloatingAttrsToSubmit(string $button): string
    {
        if (! $this->settings->isProtected('comments') || ! $this->settings->isConfigured() || ! $this->settings->isFloating()) {
            return $button;
        }

        return Renderer::addFloatingAttrsToSubmit($button, self::WIDGET_ID);
    }

    /**
     * @param  array<string, mixed>  $commentData
     * @return array<string, mixed>
     */
    public function verifyComment(array $commentData): array
    {
        if (! $this->settings->isProtected('comments')) {
            return $commentData;
        }

        if (! $this->verifier->verifyCurrentRequest('comments')) {
            wp_die(
                esc_html__('CAPTCHA verification failed. Please go back and complete the challenge.', 'privacy-captcha-for-cap'),
                esc_html__('CAPTCHA verification failed', 'privacy-captcha-for-cap'),
                ['response' => 403, 'back_link' => true]
            );
        }

        $this->lastFailOpen = $this->verifier->wasLastFailOpen();

        return $commentData;
    }

    /**
     * Tag a comment that was accepted only because Cap was unreachable.
     *
     * @param  int|string  $commentId
     */
    public function annotateFailOpen($commentId): void
    {
        if (! $this->lastFailOpen) {
            return;
        }

        $this->lastFailOpen = false;
        add_comment_meta((int) $commentId, 'cap_captcha_fail_open', 1, true);
        do_action('cap_captcha_fail_open_pass', 'comments', ['comment_id' => (int) $commentId]);
    }
}
