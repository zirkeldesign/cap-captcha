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

        // Per-form "Default / Always / Never" setting + global protect-all.
        add_filter('gform_form_settings_fields', [$this, 'addFormSetting'], 10, 2);

        // Auto-protected forms without the manual field get a synthetic
        // cap_captcha field at runtime, so rendering and validation reuse the
        // exact same field path. Runtime-only — never saved to the form.
        add_filter('gform_pre_render', [$this, 'injectAutoField']);
        add_filter('gform_pre_validation', [$this, 'injectAutoField']);
        add_filter('gform_pre_submission_filter', [$this, 'injectAutoField']);
    }

    /**
     * Per-form protection override in the Gravity Forms form settings.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public function addFormSetting(array $fields, array $form): array
    {
        $fields['cap_captcha'] = [
            'title' => esc_html__('Privacy CAPTCHA for Cap', 'privacy-captcha-for-cap'),
            'fields' => [
                [
                    'type' => 'select',
                    'name' => 'capCaptchaMode',
                    'label' => esc_html__('Protection', 'privacy-captcha-for-cap'),
                    'default_value' => 'default',
                    'choices' => [
                        ['label' => esc_html__('Default (use the global setting)', 'privacy-captcha-for-cap'), 'value' => 'default'],
                        ['label' => esc_html__('Always protect this form', 'privacy-captcha-for-cap'), 'value' => 'always'],
                        ['label' => esc_html__('Never protect this form', 'privacy-captcha-for-cap'), 'value' => 'never'],
                    ],
                    'tooltip' => esc_html__('Adding the "Privacy CAPTCHA for Cap" field to the form always protects it regardless of this setting.', 'privacy-captcha-for-cap'),
                ],
            ],
        ];

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public function injectAutoField(array $form): array
    {
        if (! Settings::get_instance()->isProtected('gravity_forms')) {
            return $form;
        }

        if (self::formHasCapField($form) || ! self::isFormAutoProtected($form)) {
            return $form;
        }

        $field = new Field([
            'id' => self::nextFieldId($form),
            'formId' => (int) ($form['id'] ?? 0),
            'type' => 'cap_captcha',
            'label' => esc_html__('Privacy CAPTCHA for Cap', 'privacy-captcha-for-cap'),
            'pageNumber' => self::lastPageNumber($form),
        ]);

        $form['fields'][] = $field;

        return $form;
    }

    /**
     * Resolve whether a form is auto-protected: the per-form override wins,
     * otherwise the global "protect all" setting decides.
     *
     * @param  array<string, mixed>  $form
     */
    public static function isFormAutoProtected(array $form): bool
    {
        $mode = (string) ($form['capCaptchaMode'] ?? 'default');

        if ($mode === 'always') {
            return true;
        }
        if ($mode === 'never') {
            return false;
        }

        return Settings::get_instance()->isGfProtectAll();
    }

    /**
     * @param  array<string, mixed>  $form
     */
    private static function nextFieldId(array $form): int
    {
        $max = 0;
        foreach (($form['fields'] ?? []) as $field) {
            if (is_object($field) && isset($field->id)) {
                $max = max($max, (int) $field->id);
            }
        }

        return $max + 1;
    }

    /**
     * @param  array<string, mixed>  $form
     */
    private static function lastPageNumber(array $form): int
    {
        $pages = 1;
        foreach (($form['fields'] ?? []) as $field) {
            if (is_object($field) && isset($field->type) && $field->type === 'page') {
                $pages++;
            }
        }

        return $pages;
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
        $hasField = self::formHasCapField($form);
        if (! $hasField && ! self::isFormAutoProtected($form)) {
            return;
        }

        // For an auto-protected form whose synthetic field isn't present in the
        // form passed here yet, fall back to the global display mode.
        $autoMode = $hasField ? '' : Settings::get_instance()->getDisplayMode();
        $hasFloating = self::formNeedsMode($form, Settings::MODE_FLOATING) || $autoMode === Settings::MODE_FLOATING;
        $hasProgrammatic = self::formNeedsMode($form, Settings::MODE_PROGRAMMATIC) || $autoMode === Settings::MODE_PROGRAMMATIC;

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
