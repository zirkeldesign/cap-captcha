<?php

declare(strict_types=1);

/*
 * The regression suite for the bug reported in the field: a Gravity Form with
 * a preview step could be submitted without solving the CAPTCHA.
 *
 * These run against the real GFFormDisplay::process_form(), so the paging
 * logic our validator mirrors is GF's own, not our reading of it.
 */

$formId = null;

beforeEach(function (): void {
    capResetSubmission();
});

afterEach(function (): void {
    capResetSubmission($this->formId ?? 0);

    if (! empty($this->formId)) {
        GFAPI::delete_form($this->formId);
    }
});

it('boots WordPress with Gravity Forms and our field type registered', function (): void {
    expect(class_exists('GFAPI'))->toBeTrue();
    expect(GF_Fields::get('cap_captcha'))->not->toBeNull();
});

it('advances to the preview page without asking for a CAPTCHA', function (): void {
    // The widget lives on the preview page — GF must not demand a proof the
    // visitor has had no chance to give. This is the half of the bug that made
    // preview forms unsubmittable when fail-open was off.
    $form = capPreviewForm('last');
    $this->formId = (int) $form['id'];

    capSubmitPage($this->formId, source: 1, target: 2);
    $result = capProcess($this->formId);

    expect($result['is_valid'])->toBeTrue();
    expect($result['page'])->toBe(2);
    expect($result['entry'])->toBeNull();
});

it('rejects the final submit when the CAPTCHA was never solved', function (): void {
    // The reported symptom: this used to save an entry.
    $form = capPreviewForm('last');
    $this->formId = (int) $form['id'];

    capSubmitPage($this->formId, source: 1, target: 2);
    capProcess($this->formId);

    capSubmitPage($this->formId, source: 2, target: 0);
    $result = capProcess($this->formId);

    expect($result['is_valid'])->toBeFalse();
    expect($result['entry'])->toBeNull();
    expect($result['message'])->toContain('complete the CAPTCHA');
    expect($result['page'])->toBe(2);
});

it('saves the entry when the CAPTCHA is solved on the preview page', function (): void {
    $form = capPreviewForm('last');
    $this->formId = (int) $form['id'];

    capSubmitPage($this->formId, source: 1, target: 2);
    capProcess($this->formId);

    capFakeCapServer('verified');
    capSubmitPage($this->formId, source: 2, target: 0, extra: ['cap-token' => 'solved-on-preview']);
    $result = capProcess($this->formId);

    expect($result['is_valid'])->toBeTrue();
    expect($result['entry'])->not->toBeNull();
    expect($result['entry']['1'])->toBe('Ada Lovelace');
});

it('carries a CAPTCHA solved on page one through to the final submit', function (): void {
    // Manual field placement: the widget is on page 1, so no token can exist on
    // the final submit. VerifiedState has to bridge it.
    $form = capPreviewForm('first');
    $this->formId = (int) $form['id'];

    capFakeCapServer('verified');
    capSubmitPage($this->formId, source: 1, target: 2, extra: ['cap-token' => 'solved-on-page-one']);
    expect(capProcess($this->formId)['is_valid'])->toBeTrue();

    capResetSubmission();
    capSubmitPage($this->formId, source: 2, target: 0);
    $result = capProcess($this->formId);

    expect($result['is_valid'])->toBeTrue();
    expect($result['entry'])->not->toBeNull();
});

it('does not let a different submission reuse that verification', function (): void {
    $form = capPreviewForm('first');
    $this->formId = (int) $form['id'];

    capFakeCapServer('verified');
    capSubmitPage($this->formId, source: 1, target: 2, extra: ['cap-token' => 'solved-on-page-one']);
    capProcess($this->formId);

    capResetSubmission();
    capSubmitPage($this->formId, source: 2, target: 0, extra: ['gform_unique_id' => 'someoneelse']);
    $result = capProcess($this->formId);

    expect($result['is_valid'])->toBeFalse();
    expect($result['entry'])->toBeNull();
});

it('blocks a token the Cap server rejects', function (): void {
    $form = capPreviewForm('last');
    $this->formId = (int) $form['id'];

    capFakeCapServer('rejected');
    capSubmitPage($this->formId, source: 2, target: 0, extra: ['cap-token' => 'forged']);
    $result = capProcess($this->formId);

    expect($result['is_valid'])->toBeFalse();
    expect($result['message'])->toContain('verification failed');
});

it('still blocks an unsolved CAPTCHA when fail-open is on', function (): void {
    // The second half of the bug: fail-open used to waive a missing token, which
    // is what made the CAPTCHA optional on these two sites.
    $form = capPreviewForm('last');
    $this->formId = (int) $form['id'];

    add_filter('cap_captcha_fail_open', '__return_true');
    capSubmitPage($this->formId, source: 2, target: 0);
    $result = capProcess($this->formId);

    expect($result['is_valid'])->toBeFalse();
    expect($result['entry'])->toBeNull();
});

it('lets a submission through on fail-open when Cap is unreachable', function (): void {
    $form = capPreviewForm('last');
    $this->formId = (int) $form['id'];

    add_filter('cap_captcha_fail_open', '__return_true');
    capFakeCapServer('unreachable');
    capSubmitPage($this->formId, source: 2, target: 0, extra: ['cap-token' => 'solved-but-cap-is-down']);
    $result = capProcess($this->formId);

    expect($result['is_valid'])->toBeTrue();
    expect($result['entry'])->not->toBeNull();
    expect((int) gform_get_meta((int) $result['entry']['id'], 'cap_captcha_fail_open'))->toBe(1);
});

it('lets cap_captcha_require_token waive a missing token', function (): void {
    $form = capPreviewForm('last');
    $this->formId = (int) $form['id'];

    add_filter('cap_captcha_require_token', '__return_false');
    capSubmitPage($this->formId, source: 2, target: 0);

    expect(capProcess($this->formId)['is_valid'])->toBeTrue();
});

it('ignores forms with no CAPTCHA field', function (): void {
    $form = capPreviewForm('none');
    $this->formId = (int) $form['id'];

    capSubmitPage($this->formId, source: 2, target: 0);
    $result = capProcess($this->formId);

    expect($result['is_valid'])->toBeTrue();
    expect($result['entry'])->not->toBeNull();
});

it('does not gate programmatic API submissions', function (): void {
    $form = capPreviewForm('last');
    $this->formId = (int) $form['id'];

    $result = GFAPI::submit_form($this->formId, ['input_1' => 'Ada Lovelace']);

    expect(is_wp_error($result))->toBeFalse();
    expect($result['is_valid'])->toBeTrue();
});
