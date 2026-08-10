<?php

declare(strict_types=1);

/**
 * PSR-4 autoloader for this plugin's own classes.
 *
 * The plugin has no runtime dependencies — composer.json requires nothing but
 * PHP itself — so Composer's autoloader would exist purely to map this one
 * namespace onto src/. Doing that here instead means the plugin runs from a
 * plain copy of the repository, and the release zip ships no vendor/ at all.
 *
 * Kept out of src/ on purpose: it is the thing that makes src/ loadable, so it
 * cannot be autoloaded itself.
 */
if (! defined('ABSPATH')) {
    exit;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'ZirkelDesign\\CapCaptcha\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__.'/src/'.str_replace('\\', '/', $relative).'.php';

    if (is_file($path)) {
        require_once $path;
    }
});
