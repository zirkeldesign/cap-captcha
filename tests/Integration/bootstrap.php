<?php

declare(strict_types=1);

/*
 * Boots the real WordPress + Gravity Forms install provisioned by
 * scripts/integration-env.sh. Unlike tests/bootstrap.php this defines no
 * stubs — the point of this suite is to run against GF's actual
 * GFFormDisplay::process_form(), including its paging logic, which is where
 * the multi-page CAPTCHA bug lived.
 */

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

$root = getenv('CAP_WP_ROOT') ?: dirname(__DIR__, 2).'/.wp-integration';

if (! is_file($root.'/wp-load.php')) {
    fwrite(STDERR, <<<TXT

        No WordPress install found at {$root}.

        Provision one first (needs wp-cli, pdo_sqlite and a Gravity Forms copy
        you have a licence for):

            CAP_GF_SOURCE=/path/to/gravityforms ./scripts/integration-env.sh


        TXT);
    exit(1);
}

// Front-end request shape: GF reads REQUEST_METHOD and the host when building
// form markup and resolving the submission.
define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST'] = 'localhost:8899';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SERVER_PORT'] = '8899';

require_once $root.'/wp-load.php';

// Pest only auto-loads the Pest.php at the root of the tests directory, and
// this suite lives one level down — pull the helpers in explicitly.
require_once __DIR__.'/Pest.php';

/*
 * Keep the suite offline and fast. Tests register their own pre_http_request
 * filter at the default priority to fake Cap; this backstop runs last and
 * fails anything else (Gravity Forms licence pings, update checks) instead of
 * letting it hit the network on every boot.
 */
add_filter('pre_http_request', static function ($preempt, $args, $url) {
    if ($preempt !== false) {
        return $preempt;
    }

    return new WP_Error('cap_integration_offline', 'Blocked outbound request to '.$url);
}, 999, 3);

if (! class_exists('GFAPI')) {
    fwrite(STDERR, <<<TXT

        Gravity Forms is not active in {$root}.

        Re-run the provisioning script with CAP_GF_SOURCE pointing at a
        gravityforms directory or zip:

            CAP_GF_SOURCE=/path/to/gravityforms ./scripts/integration-env.sh


        TXT);
    exit(1);
}
