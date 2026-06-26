<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Verification;

final class CapVerifier
{
    public function __construct(
        private readonly string $endpointBase,
        private readonly string $siteKey,
        private readonly string $secretKey,
        private readonly int $timeout = 5,
    ) {}

    public function verify(string $token): bool
    {
        return $this->check($token) === VerificationResult::Verified;
    }

    /**
     * Verify a token, distinguishing an active rejection from an unreachable
     * server. An empty token or missing configuration is reported as
     * Unreachable (we cannot verify), so the caller's fail-open policy decides.
     */
    public function check(string $token): VerificationResult
    {
        $token = trim($token);

        if ($token === '' || $this->endpointBase === '' || $this->siteKey === '' || $this->secretKey === '') {
            return VerificationResult::Unreachable;
        }

        $url = rtrim($this->endpointBase, '/').'/'.$this->siteKey.'/siteverify';

        $response = wp_remote_post(
            $url,
            [
                'timeout' => $this->timeout,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode(['secret' => $this->secretKey, 'response' => $token]),
            ]
        );

        if (is_wp_error($response)) {
            return VerificationResult::Unreachable;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if (! is_array($body)) {
            return VerificationResult::Unreachable;
        }

        return ($body['success'] ?? false) === true
            ? VerificationResult::Verified
            : VerificationResult::Rejected;
    }
}
