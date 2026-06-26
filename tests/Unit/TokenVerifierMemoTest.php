<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

beforeEach(function (): void {
    $_POST = [];
    cap_reset_options();
    cap_reset_remote_stub();
    cap_reset_filters();
});

function capConfiguredSettings(): Settings
{
    update_option(Settings::OPTION_KEY, [
        'endpoint_base' => 'https://cap.example.com/',
        'site_key' => 'sitekey',
        'secret_key' => 'secret',
        'fail_open' => false,
        'integrations' => ['contact_form_7' => true],
    ]);

    return new Settings;
}

it('redeems a token once and reuses the result for the rest of the request', function (): void {
    $verifier = new TokenVerifier(capConfiguredSettings());
    $_POST['cap-token'] = 'tok-123';

    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];
    expect($verifier->verifyCurrentRequest())->toBeTrue();

    // The token is now spent server-side: a second redemption would be rejected.
    // Memoization must keep returning the first (successful) result.
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    expect($verifier->verifyCurrentRequest())->toBeTrue();
});

it('does not memoize across different tokens', function (): void {
    $verifier = new TokenVerifier(capConfiguredSettings());

    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];
    $_POST['cap-token'] = 'good';
    expect($verifier->verifyCurrentRequest())->toBeTrue();

    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    $_POST['cap-token'] = 'bad';
    expect($verifier->verifyCurrentRequest())->toBeFalse();
});
