<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Verification;

use ZirkelDesign\CapCaptcha\Settings;

/**
 * Higher-level verifier used by the WordPress integrations. Pulls the Cap
 * token from `$_POST['cap-token']`, hands it to CapVerifier for the HTTP call,
 * and honours the global "fail-open" setting when configured.
 */
final class TokenVerifier
{
    /**
     * Per-request cache of verification results, keyed by token. Cap tokens are
     * single-use, so when more than one hook verifies the same submission (e.g.
     * WooCommerce login fires both `woocommerce_process_login_errors` and core's
     * `wp_authenticate_user`), we must not redeem the token twice — the second
     * redemption would fail. Memoizing keeps every check consistent.
     *
     * @var array<string, bool>
     */
    private array $memo = [];

    public function __construct(private readonly Settings $settings) {}

    public function verifyCurrentRequest(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Caller (login/register/comment/checkout/GF flow) enforces its own nonce on the surrounding form; value is unslashed here and sanitized via sanitize_text_field() on the next line.
        $raw = isset($_POST['cap-token']) ? wp_unslash($_POST['cap-token']) : '';
        $token = is_string($raw) ? sanitize_text_field($raw) : '';

        return $this->verifyToken($token);
    }

    public function verifyToken(string $token): bool
    {
        if ($token === '') {
            return $this->failedResult();
        }

        if (array_key_exists($token, $this->memo)) {
            return $this->memo[$token];
        }

        if (! $this->settings->isConfigured()) {
            return $this->failedResult();
        }

        $verifier = new CapVerifier(
            $this->settings->getEndpointBase(),
            $this->settings->getSiteKey(),
            $this->settings->getSecretKey(),
        );

        $verified = $verifier->verify($token);

        // CapVerifier already returns false on transport errors. When fail-open
        // is enabled, we still need to distinguish "Cap rejected the token"
        // from "couldn't reach Cap at all" — but CapVerifier collapses both to
        // bool. Until we surface the distinction, fail-open lets *any* false
        // through, which matches Oliweb's behavior.
        if (! $verified && $this->settings->isFailOpen()) {
            $verified = true;
        }

        return $this->memo[$token] = $verified;
    }

    private function failedResult(): bool
    {
        return $this->settings->isFailOpen();
    }
}
