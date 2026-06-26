<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Integration\GravityForms;

use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

final class Validator
{
    public function __construct(private readonly TokenVerifier $verifier) {}

    /**
     * @param  array{is_valid: bool, form: array<string, mixed>}  $result
     * @return array{is_valid: bool, form: array<string, mixed>}
     */
    public function validate(array $result): array
    {
        $form = $result['form'];

        if (empty($form['fields']) || ! is_array($form['fields'])) {
            return $result;
        }

        $field = $this->findCapField($form['fields']);

        if ($field === null) {
            return $result;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- GF verifies its own nonce before this hook runs; the token is opaque text passed straight to Cap's /siteverify, sanitize_text_field would corrupt it.
        $raw = isset($_POST['cap-token']) ? wp_unslash($_POST['cap-token']) : '';
        $token = is_string($raw) ? trim($raw) : '';

        // An empty token defers to the surface's fail-open policy (verifyToken
        // returns the fail-open decision for an empty token), so a Cap outage
        // can let a form through when configured to.
        if (! $this->verifier->verifyToken($token, 'gravity_forms')) {
            $message = $token === ''
                ? esc_html__('Please complete the CAPTCHA before submitting.', 'privacy-captcha-for-cap')
                : esc_html__('CAPTCHA verification failed. Please try again.', 'privacy-captcha-for-cap');

            return $this->failResult($result, $field, $message);
        }

        return $result;
    }

    /**
     * @param  array<int|string, mixed>  $fields
     */
    private function findCapField(array $fields): ?object
    {
        foreach ($fields as $field) {
            if (! is_object($field)) {
                continue;
            }
            if (isset($field->type) && $field->type === 'cap_captcha') {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param  array{is_valid: bool, form: array<string, mixed>}  $result
     * @return array{is_valid: bool, form: array<string, mixed>}
     */
    private function failResult(array $result, object $field, string $message): array
    {
        $result['is_valid'] = false;

        $field->failed_validation = true;
        $field->validation_message = $message;

        return $result;
    }
}
