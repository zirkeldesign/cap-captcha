<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Integration\GravityForms\VerifiedState;

/**
 * Build the form shape a preview step produces: an input page, a page break,
 * then a page holding only the {all_fields} summary and the submit button.
 * That is exactly what the Gravity Wiz "GP Preview Submission" perk generates,
 * so we reproduce the reported bug without the commercial perk.
 *
 * @param  'last'|'first'|'none'  $capFieldPage  Where the CAPTCHA field goes.
 * @return array<string, mixed> The saved form.
 */
function capPreviewForm(string $capFieldPage = 'last'): array
{
    // Gravity Forms derives pageNumber from a field's position relative to the
    // page breaks and overwrites whatever we pass, so the order of this array —
    // not the pageNumber key — is what decides which page the CAPTCHA lands on.
    $cap = [
        'id' => 4,
        'type' => 'cap_captcha',
        'label' => 'Privacy CAPTCHA for Cap',
    ];

    $fields = array_merge(
        [['id' => 1, 'type' => 'text', 'label' => 'Name', 'isRequired' => false]],
        $capFieldPage === 'first' ? [$cap] : [],
        [
            ['id' => 2, 'type' => 'page', 'label' => 'Page Break'],
            ['id' => 3, 'type' => 'html', 'label' => 'Summary', 'content' => '{all_fields}'],
        ],
        $capFieldPage === 'last' ? [$cap] : [],
    );

    $formId = GFAPI::add_form([
        'title' => 'Preview form',
        'labelPlacement' => 'top_label',
        'button' => ['type' => 'text', 'text' => 'Submit'],
        'fields' => $fields,
    ]);

    if (is_wp_error($formId)) {
        throw new RuntimeException('Could not create the test form: '.$formId->get_error_message());
    }

    return GFAPI::get_form($formId);
}

/**
 * Populate $_POST the way a browser posts one page of a multi-page Gravity
 * Form. A $target of 0 means "this is the final submit".
 *
 * @param  array<string, string>  $extra
 */
function capSubmitPage(int $formId, int $source, int $target, array $extra = []): void
{
    $_POST = array_merge([
        'gform_submit' => (string) $formId,
        'is_submit_'.$formId => '1',
        'gform_unique_id' => 'integration01',
        'gform_source_page_number_'.$formId => (string) $source,
        'gform_target_page_number_'.$formId => (string) $target,
        'input_1' => 'Ada Lovelace',
    ], $extra);
}

/**
 * Run GF's real submission pipeline and return its validation verdict plus the
 * entry it saved (if any).
 *
 * @return array{is_valid: bool, page: int, entry: array<string, mixed>|null, message: string}
 */
function capProcess(int $formId): array
{
    GFFormDisplay::$submission = [];
    GFFormsModel::$uploaded_files = [];

    GFFormDisplay::process_form($formId, GFFormDisplay::SUBMISSION_INITIATED_BY_WEBFORM);

    $submission = GFFormDisplay::$submission[$formId] ?? [];
    $form = $submission['form'] ?? GFAPI::get_form($formId);

    $message = '';
    foreach (($form['fields'] ?? []) as $field) {
        if (! empty($field->failed_validation)) {
            $message = (string) $field->validation_message;
            break;
        }
    }

    // GF leaves 'lead' as an empty array when nothing was saved.
    $entry = $submission['lead'] ?? null;

    return [
        'is_valid' => (bool) ($submission['is_valid'] ?? false),
        'page' => (int) ($submission['page_number'] ?? 0),
        'entry' => empty($entry) ? null : $entry,
        'message' => $message,
    ];
}

/**
 * Make every Cap /siteverify call resolve the same way for the rest of the
 * test, without touching the network.
 *
 * @param  'verified'|'rejected'|'unreachable'  $outcome
 */
function capFakeCapServer(string $outcome): void
{
    add_filter('pre_http_request', function ($preempt, $args, $url) use ($outcome) {
        if (! str_contains((string) $url, '/siteverify')) {
            return $preempt;
        }

        if ($outcome === 'unreachable') {
            return new WP_Error('http_request_failed', 'Cap is down');
        }

        return [
            'headers' => [],
            'body' => wp_json_encode(['success' => $outcome === 'verified']),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }, 10, 3);
}

/**
 * Reset everything that leaks between submissions in one PHP process.
 */
function capResetSubmission(int $formId = 0): void
{
    $_POST = [];
    $_GET = [];
    GFFormDisplay::$submission = [];

    remove_all_filters('pre_http_request');
    remove_all_filters('cap_captcha_fail_open');
    remove_all_filters('cap_captcha_require_token');

    if ($formId > 0) {
        VerifiedState::forget($formId);
    }
}
