<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Verification;

use ZirkelDesign\CapCaptcha\Settings;

/**
 * Higher-level verifier used by the WordPress integrations. Pulls the Cap
 * token from `$_POST['cap-token']`, hands it to CapVerifier, and applies the
 * per-surface "fail-open" policy — but only when Cap could not be reached (or
 * there was nothing to verify), never when Cap actively rejected the token.
 */
final class TokenVerifier
{
    /**
     * Per-request cache of the raw Cap result, keyed by token. Cap tokens are
     * single-use, so a token must not be redeemed twice when more than one hook
     * verifies the same submission. The fail-open decision is applied per call
     * on top of this cached result, since it can differ per surface.
     *
     * @var array<string, VerificationResult>
     */
    private array $memo = [];

    private bool $lastFailOpen = false;

    public function __construct(private readonly Settings $settings) {}

    public function verifyCurrentRequest(string $context = ''): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Caller (login/register/comment/checkout/GF/CF7 flow) enforces its own nonce on the surrounding form; value is unslashed here and sanitized via sanitize_text_field() on the next line.
        $raw = isset($_POST['cap-token']) ? wp_unslash($_POST['cap-token']) : '';
        $token = is_string($raw) ? sanitize_text_field($raw) : '';

        return $this->verifyToken($token, $context);
    }

    public function verifyToken(string $token, string $context = ''): bool
    {
        $this->lastFailOpen = false;

        if ($token === '' || ! $this->settings->isConfigured()) {
            return $this->failOpen($context);
        }

        $result = $this->memo[$token] ??= (new CapVerifier(
            $this->settings->getEndpointBase(),
            $this->settings->getSiteKey(),
            $this->settings->getSecretKey(),
        ))->check($token);

        return match ($result) {
            VerificationResult::Verified => true,
            VerificationResult::Rejected => false,
            VerificationResult::Unreachable => $this->failOpen($context),
        };
    }

    /**
     * Whether the most recent verify*() call passed only because the surface's
     * fail-open policy let it through (Cap was unreachable / nothing to verify).
     * Integrations use this to annotate the resulting entry for later review.
     */
    public function wasLastFailOpen(): bool
    {
        return $this->lastFailOpen;
    }

    private function failOpen(string $context): bool
    {
        $open = $this->settings->isFailOpen($context);
        $this->lastFailOpen = $open;

        return $open;
    }
}
