<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Integration;

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

/**
 * Protects every Contact Form 7 form. The widget is injected into the form
 * markup via `wpcf7_form_elements`; submissions are rejected as spam when the
 * Cap token is missing or invalid (CF7 has no per-field captcha concept, so the
 * `wpcf7_spam` gate is the idiomatic place to block a bad submission).
 */
final class ContactForm7 implements Integration
{
    public const WIDGET_ID = 'cap-captcha-cf7';

    public function __construct(
        private readonly Settings $settings,
        private readonly Renderer $renderer,
        private readonly Enqueuer $enqueuer,
        private readonly TokenVerifier $verifier,
    ) {}

    public function id(): string
    {
        return 'contact_form_7';
    }

    public function isAvailable(): bool
    {
        return defined('WPCF7_VERSION') || class_exists('WPCF7');
    }

    public function register(): void
    {
        add_filter('wpcf7_form_elements', [$this, 'injectWidget']);
        add_filter('wpcf7_spam', [$this, 'flagSpam'], 10, 2);
    }

    public function injectWidget(string $html): string
    {
        if (! $this->settings->isProtected('contact_form_7') || ! $this->settings->isConfigured()) {
            return $html;
        }

        $mode = $this->settings->getDisplayMode();
        $this->enqueuer->enqueueForMode($mode);
        $this->enqueuer->printGlobals();

        $widget = $this->renderer->renderForMode(self::WIDGET_ID, $mode);
        if ($widget === '') {
            return $html;
        }

        // Place the widget just before the form's submit control; fall back to
        // appending when no submit element is found.
        $injected = preg_replace(
            '/(<(?:input|button)[^>]*type=(["\'])submit\2[^>]*>)/i',
            $widget.'$1',
            $html,
            1,
            $count
        );

        return ($injected !== null && $count > 0) ? $injected : $html.$widget;
    }

    /**
     * @param  mixed  $submission  CF7 WPCF7_Submission (unused; token read from POST).
     */
    public function flagSpam(bool $spam, $submission = null): bool
    {
        if ($spam) {
            return true;
        }

        if (! $this->settings->isProtected('contact_form_7')) {
            return false;
        }

        return ! $this->verifier->verifyCurrentRequest();
    }
}
