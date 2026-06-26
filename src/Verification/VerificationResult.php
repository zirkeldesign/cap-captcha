<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Verification;

/**
 * Outcome of asking the Cap server to verify a token. Distinguishing a genuine
 * rejection from an unreachable server lets fail-open apply only when Cap could
 * not be contacted — never to a token the server actively rejected.
 */
enum VerificationResult
{
    case Verified;
    case Rejected;
    case Unreachable;
}
