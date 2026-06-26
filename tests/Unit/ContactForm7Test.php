<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Integration\ContactForm7;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

beforeEach(function (): void {
    $_POST = [];
    cap_reset_options();
    cap_reset_remote_stub();
    cap_reset_filters();
});

function capCf7(bool $enabled = true): ContactForm7
{
    update_option(Settings::OPTION_KEY, [
        'endpoint_base' => 'https://cap.example.com/',
        'site_key' => 'sitekey',
        'secret_key' => 'secret',
        'fail_open' => false,
        'integrations' => ['contact_form_7' => $enabled],
    ]);

    $settings = new Settings;

    return new ContactForm7(
        $settings,
        new Renderer($settings),
        new Enqueuer($settings),
        new TokenVerifier($settings),
    );
}

it('does not flag a submission with a valid token as spam', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];
    $_POST['cap-token'] = 'tok';

    expect(capCf7()->flagSpam(false))->toBeFalse();
});

it('flags a submission with an invalid token as spam', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    $_POST['cap-token'] = 'tok';

    expect(capCf7()->flagSpam(false))->toBeTrue();
});

it('keeps an already-flagged submission as spam', function (): void {
    expect(capCf7()->flagSpam(true))->toBeTrue();
});

it('passes through when the Contact Form 7 surface is disabled', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    $_POST['cap-token'] = 'tok';

    expect(capCf7(false)->flagSpam(false))->toBeFalse();
});
