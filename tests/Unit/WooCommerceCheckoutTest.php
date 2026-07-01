<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Integration\Login;
use ZirkelDesign\CapCaptcha\Integration\WooCommerce;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

beforeEach(function (): void {
    $_POST = [];
    cap_reset_options();
    cap_reset_remote_stub();
    cap_reset_filters();
});

/**
 * @param  array<string, bool>  $integrations
 */
function capConfigure(array $integrations): Settings
{
    update_option(Settings::OPTION_KEY, [
        'endpoint_base' => 'https://cap.example.com/',
        'site_key' => 'sitekey',
        'secret_key' => 'secret',
        'fail_open' => false,
        'integrations' => $integrations,
    ]);

    return new Settings;
}

function capWoo(): WooCommerce
{
    $settings = capConfigure(['woocommerce' => true, 'woocommerce_registration' => true]);

    return new WooCommerce($settings, new Renderer($settings), new Enqueuer($settings), new TokenVerifier($settings));
}

function capLogin(): Login
{
    $settings = capConfigure(['login' => true]);

    return new Login($settings, new Renderer($settings), new Enqueuer($settings), new TokenVerifier($settings));
}

it('does not validate registration during checkout account creation', function (): void {
    // No woocommerce-register-nonce → this is checkout / programmatic, not the
    // My Account register form, so the CAPTCHA must not be required here.
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    $errors = new WP_Error;

    $result = capWoo()->verifyRegistration($errors);

    expect($result->has_errors())->toBeFalse();
});

it('validates the My Account registration form', function (): void {
    $_POST['woocommerce-register-nonce'] = 'x';
    $errors = new WP_Error;

    $result = capWoo()->verifyRegistration($errors);

    expect($result->get_error_code())->toBe('cap_captcha_failed');
});

it('skips core Login verification for WooCommerce My Account logins', function (): void {
    $_POST['woocommerce-login-nonce'] = 'x';
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    $user = new WP_User;

    expect(capLogin()->verifyLogin($user, 'pw'))->toBe($user);
});

it('verifies a normal wp-login submission', function (): void {
    $user = new WP_User;

    expect(capLogin()->verifyLogin($user, 'pw'))->toBeInstanceOf(WP_Error::class);
});
