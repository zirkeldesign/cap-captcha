<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Integration\GravityForms\Validator;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

beforeEach(function (): void {
    $_POST = [];
    cap_reset_options();
    cap_reset_remote_stub();
});

function capFakeSettings(bool $failOpen = false): Settings
{
    update_option(Settings::OPTION_KEY, [
        'endpoint_base' => 'https://cap.example.com/',
        'site_key' => 'sitekey',
        'secret_key' => 'secret',
        'display_mode' => Settings::MODE_INLINE,
        'wasm_source' => Settings::WASM_BUNDLED,
        'fail_open' => $failOpen,
        'integrations' => [
            'gravity_forms' => true,
            'comments' => false,
            'login' => false,
            'registration' => false,
            'woocommerce' => false,
        ],
    ]);

    return new class extends Settings
    {
        public function __construct() {}

        public function isConfigured(): bool
        {
            return true;
        }

        public function getEndpointBase(): string
        {
            return 'https://cap.example.com/';
        }

        public function getSiteKey(): string
        {
            return 'sitekey';
        }

        public function getSecretKey(): string
        {
            return 'secret';
        }

        public function isFailOpen(string $context = ''): bool
        {
            return false;
        }
    };
}

function capCaptchaField(int $id = 4): object
{
    return (object) [
        'id'                 => $id,
        'type'               => 'cap_captcha',
        'failed_validation'  => false,
        'validation_message' => '',
    ];
}

function capForm(object ...$fields): array
{
    return [
        'is_valid' => true,
        'form'     => [
            'id'     => 1,
            'fields' => $fields,
        ],
    ];
}

it('passes through forms without a Cap field', function (): void {
    $result = capForm((object) ['id' => 1, 'type' => 'text']);

    $settings = capFakeSettings();
    $validator = new Validator(new TokenVerifier($settings));

    expect($validator->validate($result))->toBe($result);
});

it('fails when the cap-token is missing', function (): void {
    $field = capCaptchaField();
    $settings = capFakeSettings();
    $validator = new Validator(new TokenVerifier($settings));

    $result = $validator->validate(capForm($field));

    expect($result['is_valid'])->toBeFalse();
    expect($field->failed_validation)->toBeTrue();
    expect($field->validation_message)->not->toBe('');
});

it('fails when the verifier rejects the token', function (): void {
    $_POST['cap-token'] = 'bad-token';
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];

    $field = capCaptchaField();
    $settings = capFakeSettings();
    $validator = new Validator(new TokenVerifier($settings));

    $result = $validator->validate(capForm($field));

    expect($result['is_valid'])->toBeFalse();
    expect($field->failed_validation)->toBeTrue();
});

it('passes when the verifier accepts the token', function (): void {
    $_POST['cap-token'] = 'good-token';
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];

    $field = capCaptchaField();
    $settings = capFakeSettings();
    $validator = new Validator(new TokenVerifier($settings));

    $result = $validator->validate(capForm($field));

    expect($result['is_valid'])->toBeTrue();
    expect($field->failed_validation)->toBeFalse();
});
