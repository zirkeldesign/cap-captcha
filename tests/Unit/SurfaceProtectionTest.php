<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Settings;

beforeEach(function (): void {
    cap_reset_options();
    cap_reset_filters();
});

/**
 * @param  array<string, bool>  $integrations
 */
function capStoreSurfaces(array $integrations): Settings
{
    update_option(Settings::OPTION_KEY, ['integrations' => $integrations]);

    return new Settings;
}

it('reads the per-surface toggle', function (): void {
    $settings = capStoreSurfaces(['login' => true, 'comments' => false]);

    expect($settings->isSurfaceEnabled('login'))->toBeTrue();
    expect($settings->isSurfaceEnabled('comments'))->toBeFalse();
    expect($settings->isSurfaceEnabled('registration'))->toBeFalse();
});

it('protects an enabled surface and skips a disabled one', function (): void {
    $settings = capStoreSurfaces(['login' => true, 'comments' => false]);

    expect($settings->isProtected('login'))->toBeTrue();
    expect($settings->isProtected('comments'))->toBeFalse();
});

it('lets the global cap_captcha_protect filter force-enable a disabled surface', function (): void {
    $settings = capStoreSurfaces(['comments' => false]);

    add_filter('cap_captcha_protect', fn (bool $on, string $ctx): bool => $ctx === 'comments' ? true : $on, 10, 2);

    expect($settings->isProtected('comments'))->toBeTrue();
});

it('lets the per-context filter force-disable an enabled surface', function (): void {
    $settings = capStoreSurfaces(['login' => true]);

    add_filter('cap_captcha_protect_login', fn (bool $on): bool => false);

    expect($settings->isProtected('login'))->toBeFalse();
});

it('migrates the legacy woocommerce toggle to woocommerce_checkout', function (): void {
    $settings = capStoreSurfaces(['woocommerce' => true]);

    expect($settings->isSurfaceEnabled('woocommerce_checkout'))->toBeTrue();
    expect($settings->isSurfaceEnabled('woocommerce_login'))->toBeFalse();
});

it('gates woocommerce sub-surfaces behind the woocommerce master toggle', function (): void {
    $on = capStoreSurfaces(['woocommerce' => true, 'woocommerce_checkout' => true]);
    expect($on->isProtected('woocommerce_checkout'))->toBeTrue();

    $off = capStoreSurfaces(['woocommerce' => false, 'woocommerce_checkout' => true]);
    expect($off->isProtected('woocommerce_checkout'))->toBeFalse();
});
