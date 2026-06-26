<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Integration\GravityForms;
use ZirkelDesign\CapCaptcha\Settings;

function capResetSettingsSingleton(): void
{
    $ref = new ReflectionClass(Settings::class);
    $prop = $ref->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
}

beforeEach(function (): void {
    cap_reset_options();
    cap_reset_filters();
    capResetSettingsSingleton();
});

it('always-protects a form set to "always"', function (): void {
    expect(GravityForms::isFormAutoProtected(['capCaptchaMode' => 'always']))->toBeTrue();
});

it('never-protects a form set to "never" even when global protect-all is on', function (): void {
    update_option(Settings::OPTION_KEY, ['gf_protect_all' => true]);

    expect(GravityForms::isFormAutoProtected(['capCaptchaMode' => 'never']))->toBeFalse();
});

it('follows the global protect-all setting when the form uses the default', function (): void {
    update_option(Settings::OPTION_KEY, ['gf_protect_all' => true]);
    expect(GravityForms::isFormAutoProtected(['capCaptchaMode' => 'default']))->toBeTrue();

    capResetSettingsSingleton();
    update_option(Settings::OPTION_KEY, ['gf_protect_all' => false]);
    expect(GravityForms::isFormAutoProtected([]))->toBeFalse();
});
