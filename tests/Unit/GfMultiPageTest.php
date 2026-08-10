<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Integration\GravityForms\Validator;
use ZirkelDesign\CapCaptcha\Integration\GravityForms\VerifiedState;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

beforeEach(function (): void {
    $_POST = [];
    cap_reset_options();
    cap_reset_remote_stub();
    cap_reset_filters();
    cap_reset_transients();
});

/**
 * Solve the challenge on page 1 of a 3-page form, then return a validator that
 * still holds the resulting state. Mirrors a manually placed Cap field.
 */
function capSolveOnPageOne(string $uniqueId = 'abc123', ?WP_Error $remote = null): Validator
{
    $validator = new Validator(new TokenVerifier(capFakeSettings()));

    $_POST['gform_unique_id'] = $uniqueId;
    $_POST['cap-token'] = 'good-token';
    $GLOBALS['__cap_remote_response'] = $remote ?? ['body' => '{"success":true}'];
    capGfPaging(1, 2);

    $validator->validate(capForm(capCaptchaField(4, 1)));

    unset($_POST['cap-token']);
    cap_reset_remote_stub();

    return $validator;
}

it('carries a page 1 verification through to the final submit', function (): void {
    $validator = capSolveOnPageOne();

    capGfPaging(3, 0);
    $field = capCaptchaField(4, 1);

    $result = $validator->validate(capForm($field));

    expect($result['is_valid'])->toBeTrue();
    expect($field->failed_validation)->toBeFalse();
});

it('does not carry over to a different submission id', function (): void {
    $validator = capSolveOnPageOne('abc123');

    $_POST['gform_unique_id'] = 'def456';
    capGfPaging(3, 0);

    expect($validator->validate(capForm(capCaptchaField(4, 1)))['is_valid'])->toBeFalse();
});

it('does not carry over without a usable submission id', function (): void {
    $validator = capSolveOnPageOne('abc123');

    capGfPaging(3, 0);

    // Missing entirely.
    unset($_POST['gform_unique_id']);
    expect($validator->validate(capForm(capCaptchaField(4, 1)))['is_valid'])->toBeFalse();

    // Present but not the alphanumeric shape GF emits.
    $_POST['gform_unique_id'] = 'abc-123';
    expect($validator->validate(capForm(capCaptchaField(4, 1)))['is_valid'])->toBeFalse();
});

it('carries the fail-open verdict forward so the entry stays flagged', function (): void {
    $settings = capFailOpenSettings(true);
    $validator = new Validator(new TokenVerifier($settings));

    $_POST['gform_unique_id'] = 'abc123';
    $_POST['cap-token'] = 'tok';
    $GLOBALS['__cap_remote_response'] = new WP_Error('down', 'unreachable');
    capGfPaging(1, 2);

    expect($validator->validate(capForm(capCaptchaField(4, 1)))['is_valid'])->toBeTrue();
    expect($validator->wasLastFailOpen())->toBeTrue();

    unset($_POST['cap-token']);
    cap_reset_remote_stub();
    capGfPaging(3, 0);

    expect($validator->validate(capForm(capCaptchaField(4, 1)))['is_valid'])->toBeTrue();
    expect($validator->wasLastFailOpen())->toBeTrue();
});

it('survives a failed final submit and is consumed only when the entry is saved', function (): void {
    $validator = capSolveOnPageOne();
    capGfPaging(3, 0);

    // The visitor fixes some other field and resubmits — the single-use token
    // is long gone, so this must keep working.
    expect($validator->validate(capForm(capCaptchaField(4, 1)))['is_valid'])->toBeTrue();
    expect($validator->validate(capForm(capCaptchaField(4, 1)))['is_valid'])->toBeTrue();

    VerifiedState::forget(1);

    expect($validator->validate(capForm(capCaptchaField(4, 1)))['is_valid'])->toBeFalse();
});

it('does not demand a token when navigating backwards', function (): void {
    $validator = new Validator(new TokenVerifier(capFakeSettings()));

    // Page 3 back to page 2, widget on page 3. GF skips validation for backward
    // navigation; the predicate must not fire either.
    capGfPaging(2, 1);

    expect($validator->validate(capForm(capCaptchaField(4, 3)))['is_valid'])->toBeTrue();
});
