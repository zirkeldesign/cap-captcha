<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Integration\GravityForms\Validator;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

beforeEach(function (): void {
    $_POST = [];
    cap_reset_options();
    cap_reset_remote_stub();
    cap_reset_filters();
    cap_reset_transients();
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

function capCaptchaField(int $id = 4, int $pageNumber = 1): object
{
    return (object) [
        'id'                 => $id,
        'type'               => 'cap_captcha',
        'pageNumber'         => $pageNumber,
        'failed_validation'  => false,
        'validation_message' => '',
    ];
}

/**
 * Gravity Forms' own paging inputs, as posted by a multi-page form.
 * A target of 0 means "this is the final submit".
 */
function capGfPaging(int $source, int $target, int $formId = 1): void
{
    $_POST["gform_source_page_number_{$formId}"] = (string) $source;
    $_POST["gform_target_page_number_{$formId}"] = (string) $target;
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

it('does not demand a token on a page transition that skips the Cap field', function (): void {
    // 3-page form, widget on the preview page: clicking "Next" on page 1 must
    // not ask for a proof the visitor has had no chance to give.
    capGfPaging(1, 2);

    $field = capCaptchaField(4, 3);
    $validator = new Validator(new TokenVerifier(capFakeSettings()));

    $result = $validator->validate(capForm($field));

    expect($result['is_valid'])->toBeTrue();
    expect($field->failed_validation)->toBeFalse();
});

it('accepts the token on the preview page submit', function (): void {
    // The GP Preview Submission regression: page 3 is the preview page and
    // carries both the widget and the submit button.
    capGfPaging(3, 0);
    $_POST['cap-token'] = 'good-token';
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];

    $field = capCaptchaField(4, 3);
    $validator = new Validator(new TokenVerifier(capFakeSettings()));

    expect($validator->validate(capForm($field))['is_valid'])->toBeTrue();
});

it('blocks the preview page submit when the box was never ticked', function (): void {
    capGfPaging(3, 0);

    $field = capCaptchaField(4, 3);
    $validator = new Validator(new TokenVerifier(capFakeSettings()));

    $result = $validator->validate(capForm($field));

    expect($result['is_valid'])->toBeFalse();
    expect($result['failed_validation_page'])->toBe(3);
    expect($field->failed_validation)->toBeTrue();
});

it('skips a Cap field hidden by conditional logic', function (): void {
    capGfPaging(2, 0);

    $field = capCaptchaField(4, 2);
    $field->is_field_hidden = true;
    $validator = new Validator(new TokenVerifier(capFakeSettings()));

    expect($validator->validate(capForm($field))['is_valid'])->toBeTrue();
});

it('ignores submissions that did not come from a web form', function (): void {
    $field = capCaptchaField();
    $validator = new Validator(new TokenVerifier(capFakeSettings()));

    expect($validator->validate(capForm($field), 'api-submit')['is_valid'])->toBeTrue();
    expect($field->failed_validation)->toBeFalse();
});
