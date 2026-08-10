<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Plugin;
use ZirkelDesign\CapCaptcha\Settings;

/*
 * This plugin protects comments, login, registration and WooCommerce on their
 * own — Gravity Forms and Contact Form 7 are optional. Neither GFForms/GFAPI
 * nor WPCF7 exists in the unit environment, so booting here is exactly the
 * "no form plugin installed" case a site like the one in issue #11 has.
 */

beforeEach(function (): void {
    cap_reset_options();
    cap_reset_filters();
    capResetSettingsSingleton();
});

it('has no form plugin available', function (): void {
    expect(class_exists('GFForms'))->toBeFalse();
    expect(class_exists('GFAPI'))->toBeFalse();
    expect(class_exists('WPCF7'))->toBeFalse();
});

it('registers the settings page when no form plugin is installed', function (): void {
    Plugin::boot();

    expect($GLOBALS['__cap_filters']['admin_menu'] ?? [])->not->toBeEmpty();
});

it('registers the surfaces that do not need a form plugin', function (): void {
    Plugin::boot();

    // Comments, login and registration are core WordPress surfaces and must
    // hook up regardless of which form plugins are around.
    expect($GLOBALS['__cap_filters']['preprocess_comment'] ?? [])->not->toBeEmpty();
    expect($GLOBALS['__cap_filters']['wp_authenticate_user'] ?? [])->not->toBeEmpty();
    expect($GLOBALS['__cap_filters']['registration_errors'] ?? [])->not->toBeEmpty();
});

it('skips the Gravity Forms hooks when Gravity Forms is absent', function (): void {
    update_option(Settings::OPTION_KEY, ['integrations' => ['gravity_forms' => true]]);

    Plugin::boot();

    expect($GLOBALS['__cap_filters']['gform_validation'] ?? [])->toBeEmpty();
});
