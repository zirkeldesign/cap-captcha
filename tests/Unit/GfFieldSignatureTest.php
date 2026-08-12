<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Integration\GravityForms\Field;

/**
 * Gravity Forms 3.0 made both parameters of GF_Field::get_field_label()
 * optional. An override that declares them as required is a fatal signature
 * error on GF 3.x, and there are no GF 3 stubs for PHPStan to catch it with —
 * so the contract is pinned here.
 */
it('keeps every get_field_label parameter optional for Gravity Forms 3.x', function (): void {
    $method = new ReflectionMethod(Field::class, 'get_field_label');

    foreach ($method->getParameters() as $parameter) {
        expect($parameter->isOptional())->toBeTrue(
            "Parameter \${$parameter->getName()} must stay optional — GF 3.0 made it optional on GF_Field."
        );
    }
});

it('matches the defaults Gravity Forms 3.0 declares on the parent', function (): void {
    $method = new ReflectionMethod(Field::class, 'get_field_label');
    $defaults = [];

    foreach ($method->getParameters() as $parameter) {
        $defaults[$parameter->getName()] = $parameter->getDefaultValue();
    }

    expect($defaults)->toBe([
        'force_frontend_label' => true,
        'value' => '',
    ]);
});

it('still accepts the two-argument call shape used by Gravity Forms 2.x', function (): void {
    $field = new Field(['label' => 'Privacy CAPTCHA for Cap']);

    expect($field->get_field_label(true, ''))->toBe('');
    expect($field->get_field_label())->toBe('');
});
