<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Settings;

beforeEach(function (): void {
    cap_reset_options();
    cap_reset_filters();
    capResetSettingsSingleton();
});

it('defaults every integration to off', function (): void {
    // Gravity Forms used to default to on, a leftover from when the plugin was
    // Gravity Forms-only. On a site without it the card showed ticked.
    $settings = new Settings;

    foreach (Settings::SURFACES as $surface) {
        expect($settings->isIntegrationEnabled($surface))->toBeFalse();
    }
});

it('keeps an enabled integration whose plugin is inactive when settings are saved', function (): void {
    // Gravity Forms is not loaded in this environment, so its card renders
    // disabled — and disabled checkboxes are never submitted. Reading the POST
    // alone would switch the integration off behind the admin's back.
    update_option(Settings::OPTION_KEY, [
        'integrations' => ['gravity_forms' => true, 'comments' => true],
    ]);
    capResetSettingsSingleton();

    $saved = Settings::get_instance()->sanitize([
        'integrations' => ['comments' => '1'],
    ]);

    expect($saved['integrations']['gravity_forms'])->toBeTrue();
    expect($saved['integrations']['comments'])->toBeTrue();
});

it('still lets an available integration be switched off', function (): void {
    // Comments needs no host plugin, so its card is always enabled and an
    // absent checkbox genuinely means "the admin unticked it".
    update_option(Settings::OPTION_KEY, [
        'integrations' => ['comments' => true, 'login' => true],
    ]);
    capResetSettingsSingleton();

    $saved = Settings::get_instance()->sanitize([
        'integrations' => ['login' => '1'],
    ]);

    expect($saved['integrations']['comments'])->toBeFalse();
    expect($saved['integrations']['login'])->toBeTrue();
});

it('does not resurrect an unavailable integration that was already off', function (): void {
    update_option(Settings::OPTION_KEY, ['integrations' => ['gravity_forms' => false]]);
    capResetSettingsSingleton();

    $saved = Settings::get_instance()->sanitize(['integrations' => []]);

    expect($saved['integrations']['gravity_forms'])->toBeFalse();
});

it('preserves the WooCommerce master toggle too', function (): void {
    update_option(Settings::OPTION_KEY, [
        'integrations' => ['woocommerce' => true, 'woocommerce_checkout' => true],
    ]);
    capResetSettingsSingleton();

    $saved = Settings::get_instance()->sanitize(['integrations' => []]);

    expect($saved['integrations']['woocommerce'])->toBeTrue();
    expect($saved['integrations']['woocommerce_checkout'])->toBeTrue();
});
