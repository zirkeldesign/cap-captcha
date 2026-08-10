<?php

declare(strict_types=1);

/*
 * The plugin ships its own PSR-4 autoloader (autoload.php) so it runs without
 * a vendor/ directory. That autoloader assumes one thing: every class under
 * ZirkelDesign\CapCaptcha\ lives at src/<the rest of the name>.php. If a file
 * and its namespace ever drift apart, Composer's classmap would paper over it
 * in development and the shipped plugin would fatal instead.
 */

/**
 * @return array<int, array{class: class-string|string, file: string}>
 */
function capSrcClasses(): array
{
    $src = dirname(__DIR__, 2).'/src';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src));

    $out = [];
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($src) + 1, -4);
        $out[] = [
            'class' => 'ZirkelDesign\\CapCaptcha\\'.str_replace('/', '\\', $relative),
            'file' => 'src/'.$relative.'.php',
        ];
    }

    sort($out);

    return $out;
}

it('finds the source tree', function (): void {
    expect(capSrcClasses())->not->toBeEmpty();
});

it('declares in every file exactly the class its path implies', function (): void {
    // Read the declaration rather than loading the class: some classes extend
    // types from Gravity Forms or WooCommerce that are absent here, and loading
    // them would fatal without proving anything about the path mapping.
    $wrong = [];

    foreach (capSrcClasses() as $entry) {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.$entry['file']);

        preg_match('/^namespace\s+([^;]+);/m', $source, $ns);
        preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $source, $name);

        $declared = trim($ns[1] ?? '').'\\'.($name[1] ?? '');

        if ($declared !== $entry['class']) {
            $wrong[] = $entry['file'].' declares '.$declared.', autoloader expects '.$entry['class'];
        }
    }

    expect($wrong)->toBe([]);
});

it('has no runtime Composer dependencies', function (): void {
    // The whole point of the bundled autoloader: if a real dependency ever
    // lands here, vendor/ has to ship again and .distignore must stop
    // excluding it.
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

    expect(array_keys($composer['require']))->toBe(['php']);
});
