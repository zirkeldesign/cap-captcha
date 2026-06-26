<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha;

use ZirkelDesign\CapCaptcha\Admin\DashboardWidget;
use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Integration\Comments;
use ZirkelDesign\CapCaptcha\Integration\ContactForm7;
use ZirkelDesign\CapCaptcha\Integration\GravityForms;
use ZirkelDesign\CapCaptcha\Integration\Integration;
use ZirkelDesign\CapCaptcha\Integration\Login;
use ZirkelDesign\CapCaptcha\Integration\Registration;
use ZirkelDesign\CapCaptcha\Integration\WooCommerce;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

/**
 * Boot the plugin. Wires the central Settings page and conditionally registers
 * each integration that the admin has opted into.
 */
final class Plugin
{
    public static function boot(): void
    {
        // load_plugin_textdomain() is intentionally NOT called — WordPress 4.6+
        // auto-loads any `{textdomain}-{locale}.mo` files found in the plugin's
        // `/languages/` directory, which matches our naming convention.

        $settings = Settings::get_instance();
        $settings->registerHooks();

        $renderer = new Renderer($settings);
        $enqueuer = new Enqueuer($settings);
        $verifier = new TokenVerifier($settings);

        // Each available integration registers its hooks unconditionally and
        // gates rendering + verification at request time through
        // Settings::isProtected(), so the per-surface toggles and the
        // cap_captcha_protect filters control every situation consistently.
        foreach (self::integrations($settings, $renderer, $enqueuer, $verifier) as $integration) {
            if (! $integration->isAvailable()) {
                continue;
            }

            $integration->register();
        }

        if (is_admin()) {
            (new DashboardWidget($settings))->register();
        }
    }

    /**
     * @return iterable<Integration>
     */
    private static function integrations(
        Settings $settings,
        Renderer $renderer,
        Enqueuer $enqueuer,
        TokenVerifier $verifier,
    ): iterable {
        yield new GravityForms($settings, $renderer, $enqueuer, $verifier);
        yield new ContactForm7($settings, $renderer, $enqueuer, $verifier);
        yield new Comments($settings, $renderer, $enqueuer, $verifier);
        yield new Login($settings, $renderer, $enqueuer, $verifier);
        yield new Registration($settings, $renderer, $enqueuer, $verifier);
        yield new WooCommerce($settings, $renderer, $enqueuer, $verifier);
    }
}
