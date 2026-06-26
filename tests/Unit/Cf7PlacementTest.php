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

/**
 * Minimal stand-in for WPCF7_ContactForm + WPCF7_Submission.
 */
function capFakeCf7Submission(bool $hasTag, bool $optedOut): object
{
    $form = new class($hasTag, $optedOut)
    {
        public function __construct(private bool $hasTag, private bool $optedOut) {}

        /** @return array<int, mixed> */
        public function scan_form_tags(array $cond = []): array
        {
            return $this->hasTag ? ['tag'] : [];
        }

        /** @return array<int, string> */
        public function additional_setting(string $name): array
        {
            return $this->optedOut ? ['off'] : [];
        }
    };

    return new class($form)
    {
        public function __construct(private object $form) {}

        public function get_contact_form(): object
        {
            return $this->form;
        }
    };
}

function capCf7Placement(string $mode): ContactForm7
{
    update_option(Settings::OPTION_KEY, [
        'endpoint_base' => 'https://cap.example.com/',
        'site_key' => 'sitekey',
        'secret_key' => 'secret',
        'fail_open' => false,
        'integrations' => ['contact_form_7' => true],
        'cf7_mode' => $mode,
    ]);

    $settings = new Settings;

    return new ContactForm7(
        $settings,
        new Renderer($settings),
        new Enqueuer($settings),
        new TokenVerifier($settings),
    );
}

it('automatic mode protects a normal form', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    $_POST['cap-token'] = 'tok';
    $submission = capFakeCf7Submission(hasTag: false, optedOut: false);

    expect(capCf7Placement(Settings::CF7_AUTOMATIC)->flagSpam(false, $submission))->toBeTrue();
});

it('automatic mode skips a form that opted out via additional settings', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    $_POST['cap-token'] = 'tok';
    $submission = capFakeCf7Submission(hasTag: false, optedOut: true);

    expect(capCf7Placement(Settings::CF7_AUTOMATIC)->flagSpam(false, $submission))->toBeFalse();
});

it('manual mode skips a form without the tag', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    $_POST['cap-token'] = 'tok';
    $submission = capFakeCf7Submission(hasTag: false, optedOut: false);

    expect(capCf7Placement(Settings::CF7_MANUAL)->flagSpam(false, $submission))->toBeFalse();
});

it('manual mode protects a form that carries the tag', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    $_POST['cap-token'] = 'tok';
    $submission = capFakeCf7Submission(hasTag: true, optedOut: false);

    expect(capCf7Placement(Settings::CF7_MANUAL)->flagSpam(false, $submission))->toBeTrue();
});
