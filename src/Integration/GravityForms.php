<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Integration;

use GF_Fields;
use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Integration\GravityForms\Field;
use ZirkelDesign\CapCaptcha\Integration\GravityForms\Validator;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

final class GravityForms implements Integration
{
    public function __construct(
        Settings $settings,
        Renderer $renderer,
        private readonly Enqueuer $enqueuer,
        private readonly TokenVerifier $verifier,
    ) {
        // Settings + Renderer are consumed by Field::get_field_input() and
        // Field::resolveDisplayMode() directly via Settings::get_instance(),
        // so we don't keep references here. Kept in the constructor signature
        // for symmetry with other integrations.
        unset($settings, $renderer);
    }

    public function id(): string
    {
        return 'gravity_forms';
    }

    public function isAvailable(): bool
    {
        return class_exists('GFForms') || class_exists('GFAPI');
    }

    public function register(): void
    {
        // Evaluated at load time because this registers the GF field type. The
        // cap_captcha_protect / cap_captcha_protect_gravity_forms filters must
        // therefore be added before the plugin boots (e.g. from an mu-plugin).
        if (! Settings::get_instance()->isProtected('gravity_forms')) {
            return;
        }

        if (did_action('gform_loaded')) {
            $this->onGravityFormsLoaded();
        } else {
            add_action('gform_loaded', [$this, 'onGravityFormsLoaded'], 5);
        }

        $validator = new Validator($this->verifier);
        add_filter('gform_validation', [$validator, 'validate']);

        add_action('gform_enqueue_scripts', [$this, 'enqueueWidget'], 10, 2);
        add_action('wp_head', [$this->enqueuer, 'printGlobals'], 1);
        add_filter('gform_submit_button', [$this, 'attachFloatingAttrsToSubmit'], 10, 2);
    }

    /**
     * Server-side rendering of the floating attrs onto Gravity Forms' own
     * submit button. Matches the Cap demo's pattern: one button, no extra
     * "Verify" trigger. Without this filter the data-cap-floating attribute
     * would need to be injected client-side after cap-widget.floating.js had
     * already run its initial DOM scan — too late for cap-floating to wire
     * up the capture-phase click listener.
     *
     * @param  array<string, mixed>  $form
     */
    public function attachFloatingAttrsToSubmit(string $button, array $form): string
    {
        $field = self::findFloatingField($form);
        if ($field === null) {
            return $button;
        }

        $formId = (int) ($form['id'] ?? 0);
        $fieldId = (int) $field->id;
        $widgetId = "input_{$formId}_{$fieldId}";

        return Renderer::addFloatingAttrsToSubmit($button, $widgetId);
    }

    /**
     * @param  array<string, mixed>  $form
     */
    private static function findFloatingField(array $form): ?Field
    {
        if (empty($form['fields']) || ! is_array($form['fields'])) {
            return null;
        }

        foreach ($form['fields'] as $field) {
            if ($field instanceof Field && $field->resolveDisplayMode() === Settings::MODE_FLOATING) {
                return $field;
            }
        }

        return null;
    }

    public function onGravityFormsLoaded(): void
    {
        if (class_exists(GF_Fields::class)) {
            GF_Fields::register(new Field);
        }

        add_action('gform_field_standard_settings', [Field::class, 'renderDisplayModeSetting'], 10, 2);
        add_action('gform_editor_js', [Field::class, 'renderEditorJs']);
    }

    /**
     * @param  array<string, mixed>  $form
     */
    public function enqueueWidget(array $form, bool $isAjax): void
    {
        if (! self::formHasCapField($form)) {
            return;
        }

        $hasFloating = self::formNeedsMode($form, Settings::MODE_FLOATING);
        $hasProgrammatic = self::formNeedsMode($form, Settings::MODE_PROGRAMMATIC);

        $this->enqueuer->enqueueCore();
        if ($hasFloating) {
            $this->enqueuer->enqueueFloating();
        }
        if ($hasProgrammatic) {
            $this->enqueuer->enqueueProgrammatic();
        }
    }

    /**
     * @param  array<string, mixed>  $form
     */
    public static function formHasCapField(array $form): bool
    {
        if (empty($form['fields']) || ! is_array($form['fields'])) {
            return false;
        }

        foreach ($form['fields'] as $field) {
            if (is_object($field) && isset($field->type) && $field->type === 'cap_captcha') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $form
     */
    public static function formNeedsMode(array $form, string $mode): bool
    {
        if (empty($form['fields']) || ! is_array($form['fields'])) {
            return false;
        }

        foreach ($form['fields'] as $field) {
            if ($field instanceof Field && $field->resolveDisplayMode() === $mode) {
                return true;
            }
        }

        return false;
    }
}
