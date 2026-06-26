<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Integration;

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

/**
 * Protects Contact Form 7 forms. Placement is controlled by the CF7 mode
 * setting:
 *
 *  - Automatic: the widget is injected into every form, except forms that
 *    already carry a [cap_captcha] tag (rendered there instead) or opt out via
 *    the Additional Settings key `cap_captcha: off`.
 *  - Manual: only forms containing a [cap_captcha] tag are protected.
 *
 * Verification (the `wpcf7_spam` gate) is scoped to the same set of forms, so a
 * legally required or accessibility-sensitive form left without the CAPTCHA is
 * never blocked.
 */
final class ContactForm7 implements Integration
{
    public const WIDGET_ID = 'cap-captcha-cf7';

    public const TAG = 'cap_captcha';

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
        add_action('wpcf7_init', [$this, 'registerTag']);
        add_filter('wpcf7_form_elements', [$this, 'injectWidget']);
        add_filter('wpcf7_spam', [$this, 'flagSpam'], 10, 2);
    }

    public function registerTag(): void
    {
        if (function_exists('wpcf7_add_form_tag')) {
            wpcf7_add_form_tag(self::TAG, [$this, 'renderTag']);
        }
    }

    /**
     * Render handler for the [cap_captcha] form-tag — the widget appears exactly
     * where the tag is placed.
     *
     * @param  mixed  $tag
     */
    public function renderTag($tag = null): string
    {
        if (! $this->settings->isProtected('contact_form_7') || ! $this->settings->isConfigured()) {
            return '';
        }

        $mode = $this->settings->getDisplayMode();
        $this->enqueuer->enqueueForMode($mode);
        $this->enqueuer->printGlobals();

        return $this->renderer->renderForMode(self::WIDGET_ID, $mode);
    }

    public function injectWidget(string $html): string
    {
        if (! $this->settings->isProtected('contact_form_7') || ! $this->settings->isConfigured()) {
            return $html;
        }

        $form = $this->currentForm();

        // Manual mode, opted-out forms, and forms that place the tag themselves
        // are handled elsewhere (or not at all) — never auto-inject for them.
        if (! $this->isFormProtected($form) || $this->formHasTag($form)) {
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
     * @param  mixed  $submission  CF7 WPCF7_Submission (token read from POST).
     */
    public function flagSpam(bool $spam, $submission = null): bool
    {
        if ($spam) {
            return true;
        }

        if (! $this->settings->isProtected('contact_form_7')) {
            return false;
        }

        $form = is_object($submission) && method_exists($submission, 'get_contact_form')
            ? $submission->get_contact_form()
            : $this->currentForm();

        if (! $this->isFormProtected($form)) {
            return false;
        }

        return ! $this->verifier->verifyCurrentRequest('contact_form_7');
    }

    /**
     * Whether a given CF7 form should be protected, honouring the mode and the
     * per-form opt-out. A null form (mode/opt-out unknown) defaults to the
     * automatic-mode behaviour.
     *
     * @param  mixed  $form  WPCF7_ContactForm|null
     */
    private function isFormProtected($form): bool
    {
        if ($this->settings->getCf7Mode() === Settings::CF7_MANUAL) {
            return $this->formHasTag($form);
        }

        return ! $this->isOptedOut($form);
    }

    /**
     * @param  mixed  $form  WPCF7_ContactForm|null
     */
    private function formHasTag($form): bool
    {
        if (! is_object($form) || ! method_exists($form, 'scan_form_tags')) {
            return false;
        }

        return $form->scan_form_tags(['type' => [self::TAG]]) !== [];
    }

    /**
     * Reads the Additional Settings key `cap_captcha: off` (also accepts false/0/no).
     *
     * @param  mixed  $form  WPCF7_ContactForm|null
     */
    private function isOptedOut($form): bool
    {
        if (! is_object($form) || ! method_exists($form, 'additional_setting')) {
            return false;
        }

        $values = $form->additional_setting(self::TAG);
        if (! is_array($values) || $values === []) {
            return false;
        }

        $value = strtolower(trim((string) $values[0]));

        return in_array($value, ['off', 'false', '0', 'no'], true);
    }

    /**
     * @return mixed WPCF7_ContactForm|null
     */
    private function currentForm()
    {
        if (! class_exists('WPCF7_ContactForm')) {
            return null;
        }

        return \WPCF7_ContactForm::get_current();
    }
}
