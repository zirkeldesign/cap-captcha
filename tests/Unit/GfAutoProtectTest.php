<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Integration\GravityForms;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

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

function capGfIntegration(): GravityForms
{
    update_option(Settings::OPTION_KEY, [
        'endpoint_base' => 'https://cap.example.com/',
        'site_key' => 'sitekey',
        'secret_key' => 'secret',
        'gf_protect_all' => true,
        'integrations' => ['gravity_forms' => true],
    ]);
    capResetSettingsSingleton();
    $settings = Settings::get_instance();

    return new GravityForms($settings, new Renderer($settings), new Enqueuer($settings), new TokenVerifier($settings));
}

/**
 * A two-page-break form, i.e. three pages — the shape a preview step produces.
 */
function capThreePageForm(): array
{
    return [
        'id' => 1,
        'fields' => [
            (object) ['id' => 1, 'type' => 'text', 'pageNumber' => 1],
            (object) ['id' => 2, 'type' => 'page', 'pageNumber' => 2],
            (object) ['id' => 3, 'type' => 'page', 'pageNumber' => 3],
        ],
    ];
}

it('places the auto-injected field on the last page', function (): void {
    $form = capGfIntegration()->injectAutoField(capThreePageForm());
    $injected = end($form['fields']);

    expect($injected->type)->toBe('cap_captcha');
    expect((int) $injected->pageNumber)->toBe(3);
    expect((int) $injected->id)->toBe(4);
});

it('lets cap_captcha_gf_field_page move the auto-injected field', function (): void {
    $integration = capGfIntegration();
    add_filter('cap_captcha_gf_field_page', fn (int $page): int => 1);

    $form = $integration->injectAutoField(capThreePageForm());

    expect((int) end($form['fields'])->pageNumber)->toBe(1);
});
