<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Integration\GravityForms;

use GFFormDisplay;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

final class Validator
{
    private bool $lastFailOpen = false;

    public function __construct(private readonly TokenVerifier $verifier) {}

    /**
     * Whether the last validated submission only passed because Cap was
     * unreachable (fail-open).
     */
    public function wasLastFailOpen(): bool
    {
        return $this->lastFailOpen;
    }

    /**
     * Gravity Forms applies `gform_validation` on every page submission of a
     * multi-page form, not just the final one — and `cap-token` is a plain POST
     * field, not a form value, so it is only ever present on the page that
     * actually rendered the widget. Demand a proof exactly when that page was
     * just submitted, or on the final submit; otherwise carry the earlier
     * verification forward via VerifiedState.
     *
     * @param  array{is_valid: bool, form: array<string, mixed>, failed_validation_page?: int}  $result
     * @param  string  $context  GF submission context: form-submit / api-submit / api-validate.
     * @return array{is_valid: bool, form: array<string, mixed>, failed_validation_page?: int}
     */
    public function validate(array $result, string $context = 'form-submit'): array
    {
        $this->lastFailOpen = false;
        $form = $result['form'];

        // GFAPI::submit_form() and the validation-only REST route never post a
        // token — there is no browser to solve a challenge. Only real web form
        // submissions are gated.
        if ($context !== 'form-submit') {
            return $result;
        }

        if (empty($form['fields']) || ! is_array($form['fields'])) {
            return $result;
        }

        $formId = (int) ($form['id'] ?? 0);
        $sourcePage = $this->sourcePage($formId);
        $isFinalSubmit = $this->targetPage($form, $sourcePage) === 0;

        $field = $this->findFieldRequiringProof($form['fields'], $formId, $sourcePage, $isFinalSubmit);

        if ($field === null) {
            return $result;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- GF verifies its own nonce before this hook runs; the token is opaque text passed straight to Cap's /siteverify, sanitize_text_field would corrupt it.
        $raw = isset($_POST['cap-token']) ? wp_unslash($_POST['cap-token']) : '';
        $token = is_string($raw) ? trim($raw) : '';

        if ($token === '') {
            return $this->validateWithoutToken($result, $field, $formId);
        }

        if (! $this->verifier->verifyToken($token, 'gravity_forms')) {
            return $this->failResult(
                $result,
                $field,
                esc_html__('CAPTCHA verification failed. Please try again.', 'privacy-captcha-for-cap')
            );
        }

        $this->lastFailOpen = $this->verifier->wasLastFailOpen();
        VerifiedState::remember($formId, $this->lastFailOpen);

        return $result;
    }

    /**
     * No token in this request. Either an earlier page of the same submission
     * already proved it, or the user never solved the challenge.
     *
     * @param  array{is_valid: bool, form: array<string, mixed>, failed_validation_page?: int}  $result
     * @return array{is_valid: bool, form: array<string, mixed>, failed_validation_page?: int}
     */
    private function validateWithoutToken(array $result, object $field, int $formId): array
    {
        $carried = VerifiedState::recall($formId);

        if ($carried !== null) {
            // Restore the earlier page's fail-open verdict so annotateFailOpen()
            // still flags an entry that only got through during a Cap outage.
            $this->lastFailOpen = $carried;

            return $result;
        }

        // An empty token is never a fail-open case: fail-open means "Cap could
        // not be reached", not "nothing was submitted". TokenVerifier still owns
        // the not-configured short-circuit.
        if ($this->verifier->verifyToken('', 'gravity_forms')) {
            return $result;
        }

        return $this->failResult(
            $result,
            $field,
            esc_html__('Please complete the CAPTCHA before submitting.', 'privacy-captcha-for-cap')
        );
    }

    /**
     * The first cap field whose proof is due in this request, or null when none
     * is. Mirrors Gravity Forms' own `! $field_in_other_page || $is_last_page`
     * rule so we gate on exactly the pages GF itself validates.
     *
     * @param  array<int|string, mixed>  $fields
     */
    private function findFieldRequiringProof(array $fields, int $formId, int $sourcePage, bool $isFinalSubmit): ?object
    {
        foreach ($fields as $field) {
            if (! is_object($field) || ! isset($field->type) || $field->type !== 'cap_captcha') {
                continue;
            }

            // GF flags fields hidden by conditional logic before this filter
            // runs. Enforcing a widget the visitor was never shown would make
            // the form unsubmittable, so skip — but make it observable.
            if (! empty($field->is_field_hidden)) {
                do_action('cap_captcha_gf_skipped_hidden', $formId, (int) ($field->id ?? 0));

                continue;
            }

            $fieldPage = (int) ($field->pageNumber ?? 1);

            if ($isFinalSubmit || $fieldPage === $sourcePage) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The page that was just submitted. 1 for a single-page form.
     */
    private function sourcePage(int $formId): int
    {
        if (class_exists(GFFormDisplay::class)) {
            return max(1, (int) GFFormDisplay::get_source_page($formId));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reading GF's own paging input to decide which page is being validated; GF verified the submission before this hook.
        $raw = $_POST["gform_source_page_number_{$formId}"] ?? null;

        return is_numeric($raw) ? max(1, (int) $raw) : 1;
    }

    /**
     * The page GF is heading to; 0 means "submit the form". Resolved through GF
     * so pages hidden by conditional logic are skipped the same way GF skips
     * them.
     *
     * @param  array<string, mixed>  $form
     */
    private function targetPage(array $form, int $sourcePage): int
    {
        $formId = (int) ($form['id'] ?? 0);

        if (class_exists(GFFormDisplay::class)) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- GF's own dynamic-population payload, forwarded verbatim to GF's page resolver.
            $fieldValues = $_POST['gform_field_values'] ?? [];

            return (int) GFFormDisplay::get_target_page($form, $sourcePage, $fieldValues);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reading GF's own paging input; absent means a single-page submit.
        $raw = $_POST["gform_target_page_number_{$formId}"] ?? null;

        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * @param  array{is_valid: bool, form: array<string, mixed>, failed_validation_page?: int}  $result
     * @return array{is_valid: bool, form: array<string, mixed>, failed_validation_page?: int}
     */
    private function failResult(array $result, object $field, string $message): array
    {
        $result['is_valid'] = false;

        // Without this GF leaves the visitor on the page it was already showing
        // — on a multi-page form that is not the page holding the widget.
        $result['failed_validation_page'] = (int) ($field->pageNumber ?? 1);

        $field->failed_validation = true;
        $field->validation_message = $message;

        return $result;
    }
}
