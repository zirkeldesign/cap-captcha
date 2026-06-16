<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Asset;

use ZirkelDesign\CapCaptcha\Settings;

/**
 * Enqueues the cap-widget script module, the optional floating script, the
 * programmatic auto-solve script, and the front-end stylesheet. Tracks state
 * so multiple integrations on the same page only enqueue assets once.
 */
final class Enqueuer
{
    public const WIDGET_MODULE_ID = 'cap-captcha/widget';

    public const FLOATING_MODULE_ID = 'cap-captcha/floating';

    public const FLOATING_AUTOSUBMIT_MODULE_ID = 'cap-captcha/floating-autosubmit';

    public const PROGRAMMATIC_MODULE_ID = 'cap-captcha/programmatic';

    public const STYLE_HANDLE = 'cap-captcha';

    private bool $coreEnqueued = false;

    private bool $floatingEnqueued = false;

    private bool $programmaticEnqueued = false;

    private bool $globalsPrinted = false;

    public function __construct(private readonly Settings $settings) {}

    public function enqueueForMode(string $mode): void
    {
        $this->enqueueCore();

        if ($mode === Settings::MODE_FLOATING) {
            $this->enqueueFloating();
        }

        if ($mode === Settings::MODE_PROGRAMMATIC) {
            $this->enqueueProgrammatic();
        }
    }

    public function enqueueCore(): void
    {
        if ($this->coreEnqueued) {
            return;
        }
        $this->coreEnqueued = true;

        $src = (string) apply_filters(
            'cap_captcha_widget_src',
            CAP_CAPTCHA_URL.'assets/js/vendor/cap-widget.js'
        );

        wp_enqueue_script_module(self::WIDGET_MODULE_ID, $src, [], CAP_CAPTCHA_VERSION);

        $styleSrc = (string) apply_filters(
            'cap_captcha_style_src',
            CAP_CAPTCHA_URL.'assets/css/cap-captcha.css'
        );

        if ($styleSrc !== '') {
            wp_enqueue_style(self::STYLE_HANDLE, $styleSrc, [], CAP_CAPTCHA_VERSION);
        }
    }

    public function enqueueFloating(): void
    {
        if ($this->floatingEnqueued) {
            return;
        }
        $this->floatingEnqueued = true;

        $src = (string) apply_filters(
            'cap_captcha_floating_src',
            CAP_CAPTCHA_URL.'assets/js/vendor/cap-widget.floating.js'
        );

        wp_enqueue_script_module(self::FLOATING_MODULE_ID, $src, [], CAP_CAPTCHA_VERSION);

        $autosubmitSrc = (string) apply_filters(
            'cap_captcha_floating_autosubmit_src',
            CAP_CAPTCHA_URL.'assets/js/floating-autosubmit.js'
        );

        wp_enqueue_script_module(self::FLOATING_AUTOSUBMIT_MODULE_ID, $autosubmitSrc, [], CAP_CAPTCHA_VERSION);
    }

    public function enqueueProgrammatic(): void
    {
        if ($this->programmaticEnqueued) {
            return;
        }
        $this->programmaticEnqueued = true;

        $src = (string) apply_filters(
            'cap_captcha_programmatic_src',
            CAP_CAPTCHA_URL.'assets/js/programmatic.js'
        );

        wp_enqueue_script_module(self::PROGRAMMATIC_MODULE_ID, $src, [], CAP_CAPTCHA_VERSION);
    }

    /**
     * Emit window globals required by the widget and the programmatic script.
     * Idempotent — safe to call from multiple hook points.
     */
    public function printGlobals(): void
    {
        if ($this->globalsPrinted) {
            return;
        }
        $this->globalsPrinted = true;

        $wasmDefault = $this->settings->getSelfHostedWasmUrl();
        $wasmUrl = (string) apply_filters('cap_captcha_wasm_url', $wasmDefault);

        $globals = [];
        if ($wasmUrl !== '') {
            $globals['CAP_CUSTOM_WASM_URL'] = $wasmUrl;
        }

        $pakoDefault = CAP_CAPTCHA_URL.'assets/js/vendor/pako_inflate.min.js';
        $pakoUrl = (string) apply_filters('cap_captcha_pako_url', $pakoDefault);
        if ($pakoUrl !== '') {
            $globals['CAP_PAKO_URL'] = $pakoUrl;
        }
        if ($this->settings->isProgrammatic()) {
            $endpoint = $this->settings->getWidgetEndpoint();
            if ($endpoint !== '') {
                $globals['CAP_CAPTCHA_PROGRAMMATIC'] = [
                    'apiEndpoint' => $endpoint,
                    'tokenField' => 'cap-token',
                ];
            }
        }

        if ($globals === []) {
            return;
        }

        $lines = [];
        foreach ($globals as $name => $value) {
            $lines[] = sprintf('window.%s=%s;', $name, wp_json_encode($value));
        }

        // wp_print_inline_script_tag is the WordPress 5.7+ helper for safely
        // emitting an inline <script>, including CSP nonces when configured.
        wp_print_inline_script_tag(implode('', $lines));
    }
}
