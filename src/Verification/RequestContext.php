<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Verification;

/**
 * Tells apart a submission from one of our rendered forms and a programmatic
 * one that reaches the same WordPress hook.
 *
 * `wp_authenticate_user`, `preprocess_comment` and `registration_errors` all
 * fire for REST, XML-RPC, WP-CLI, importers and third-party login forms as well
 * as for the wp-login.php / comment form our widget is attached to. Those
 * requests never carry a token — blocking them would break the site, not the
 * bots — so each integration bails out before verifying.
 */
final class RequestContext
{
    /**
     * A request with no browser behind it, so no widget could have been solved.
     */
    public static function isNonInteractive(): bool
    {
        if (defined('REST_REQUEST') && \REST_REQUEST) {
            return true;
        }

        if (defined('XMLRPC_REQUEST') && \XMLRPC_REQUEST) {
            return true;
        }

        if (defined('WP_CLI') && \WP_CLI) {
            return true;
        }

        return function_exists('wp_doing_cron') && wp_doing_cron();
    }

    /**
     * Whether the POST carries the marker field of the form we render into.
     * Absent means some other caller reached this hook programmatically.
     */
    public static function isPostFrom(string $markerField): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only detecting which form is posting so we don't gate programmatic callers; the surrounding WordPress flow verifies its own nonce.
        return isset($_POST[$markerField]);
    }

    /**
     * Both checks at once: a real submission of the form identified by
     * $markerField.
     */
    public static function isFormPost(string $markerField): bool
    {
        return ! self::isNonInteractive() && self::isPostFrom($markerField);
    }
}
