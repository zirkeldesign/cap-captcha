<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Integration\GravityForms;

/**
 * Remembers that a multi-page Gravity Forms submission already proved itself.
 *
 * Cap tokens are single-use and `cap-token` is not a form value, so the proof
 * solved on the page holding the widget is gone by the time the visitor reaches
 * the final page. Gravity Forms does round-trip a stable `gform_unique_id`
 * across every page of one submission, which gives us a key to hang the
 * verdict on for the rest of that submission.
 *
 * The record is consumed when the entry is saved, so one solve buys exactly one
 * entry — a resubmit after some other field failed validation still works,
 * while replaying a captured unique id cannot mint extra entries.
 */
final class VerifiedState
{
    private const PREFIX = 'cap_gf_ok_';

    /**
     * Store the verdict for the submission currently being processed. No-op
     * when GF gave us no usable submission id (we then simply require a token
     * on every page — fail-closed).
     */
    public static function remember(int $formId, bool $failOpen): void
    {
        $key = self::key($formId);
        if ($key === '') {
            return;
        }

        set_transient($key, ['fail_open' => $failOpen], self::ttl());
    }

    /**
     * The stored fail-open verdict, or null when this submission has not been
     * verified on an earlier page.
     */
    public static function recall(int $formId): ?bool
    {
        $key = self::key($formId);
        if ($key === '') {
            return null;
        }

        $stored = get_transient($key);

        if (! is_array($stored) || ! array_key_exists('fail_open', $stored)) {
            return null;
        }

        return (bool) $stored['fail_open'];
    }

    public static function forget(int $formId): void
    {
        $key = self::key($formId);
        if ($key === '') {
            return;
        }

        delete_transient($key);
    }

    /**
     * GF's per-submission id. It is visitor-supplied, so validate its shape
     * before it reaches an option name — GF itself only ever emits alphanumeric
     * ids.
     */
    private static function submissionId(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reading GF's own submission id to key our per-submission record; GF verified the submission before this hook, and the value is shape-checked below.
        $raw = isset($_POST['gform_unique_id']) ? wp_unslash($_POST['gform_unique_id']) : '';

        if (! is_string($raw) || $raw === '' || strlen($raw) > 64 || ! ctype_alnum($raw)) {
            return '';
        }

        return $raw;
    }

    /**
     * Hashed so the visitor-supplied id never lands in an option name verbatim,
     * and so the key stays a fixed 42 characters.
     */
    private static function key(int $formId): string
    {
        $uid = self::submissionId();

        if ($uid === '' || $formId <= 0) {
            return '';
        }

        return self::PREFIX.substr(hash_hmac('sha256', $formId.'|'.$uid, wp_salt('cap_captcha')), 0, 32);
    }

    private static function ttl(): int
    {
        /**
         * How long a verification stays valid across the pages of one
         * multi-page submission.
         *
         * @param  int  $ttl  Seconds.
         */
        return max(60, (int) apply_filters('cap_captcha_gf_verified_ttl', HOUR_IN_SECONDS));
    }
}
