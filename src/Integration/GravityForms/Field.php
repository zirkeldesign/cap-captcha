<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Integration\GravityForms;

use GF_Field;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Settings;

final class Field extends GF_Field
{
    /** @var string */
    public $type = 'cap_captcha';

    public function get_form_editor_field_title(): string
    {
        return esc_attr__('Privacy CAPTCHA for Cap', 'privacy-captcha-for-cap');
    }

    public function get_form_editor_field_description(): string
    {
        return esc_attr__('Adds a Cap challenge that must be solved before the form can be submitted.', 'privacy-captcha-for-cap');
    }

    public function get_form_editor_field_icon(): string
    {
        return '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M396.463 293.184c28.79 13.58 102.92 45.57 113.25 66.14 7.77 15.46-45.34-21.13-110.2-18.2-134.12 6.07-151.22 80.93-278.05 21.89-17.24 4.81-34.08 10.84-51.3 15.73-12.93 3.67-37.68 7.34-48.12 12.85-4.22 2.23-7.95 8.67-12.96-.23-21.5-106.67 2.64-220.09 118.29-254.96 14.17-21.94 30.82-25.32 52.11-9.91 118.99-10.08 183.7 59.59 216.99 166.69z"/></svg>';
    }

    /**
     * @return array<string, string>
     */
    public function get_form_editor_button(): array
    {
        return [
            'group' => 'advanced_fields',
            'text' => $this->get_form_editor_field_title(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function get_form_editor_field_settings(): array
    {
        return [
            'label_setting',
            'description_setting',
            'css_class_setting',
            'admin_label_setting',
            'cap_display_mode_setting',
        ];
    }

    /**
     * Resolve the display mode for this field instance, falling back to the
     * global setting when the field doesn't override it.
     */
    public function resolveDisplayMode(): string
    {
        $override = isset($this->capDisplayMode) ? trim((string) $this->capDisplayMode) : '';
        $valid = [Settings::MODE_INLINE, Settings::MODE_FLOATING, Settings::MODE_PROGRAMMATIC];

        $mode = in_array($override, $valid, true)
            ? $override
            : Settings::get_instance()->getDisplayMode();

        $filtered = (string) apply_filters('cap_captcha_display_mode', $mode, $this);

        return in_array($filtered, $valid, true) ? $filtered : Settings::MODE_INLINE;
    }

    /**
     * Render the per-field "Display mode" select in the form editor.
     */
    public static function renderDisplayModeSetting(int $position, int $form_id): void
    {
        if ($position !== 50) {
            return;
        }

        ?>
        <li class="cap_display_mode_setting field_setting">
            <label for="cap_display_mode" class="section_label">
                <?php echo esc_html__('Display mode', 'privacy-captcha-for-cap'); ?>
            </label>
            <select id="cap_display_mode" onchange="SetFieldProperty('capDisplayMode', jQuery(this).val());">
                <option value=""><?php echo esc_html__('Use global setting', 'privacy-captcha-for-cap'); ?></option>
                <option value="<?php echo esc_attr(Settings::MODE_INLINE); ?>"><?php echo esc_html__('Inline (always visible)', 'privacy-captcha-for-cap'); ?></option>
                <option value="<?php echo esc_attr(Settings::MODE_FLOATING); ?>"><?php echo esc_html__('Floating (opens on click)', 'privacy-captcha-for-cap'); ?></option>
                <option value="<?php echo esc_attr(Settings::MODE_PROGRAMMATIC); ?>"><?php echo esc_html__('Programmatic (auto-solves silently)', 'privacy-captcha-for-cap'); ?></option>
            </select>
        </li>
        <?php
    }

    /**
     * Emit the editor JS that binds the custom setting to the field model.
     */
    public static function renderEditorJs(): void
    {
        $defaultLabel = __('Privacy CAPTCHA for Cap', 'privacy-captcha-for-cap');

        $js = <<<'JS'
            (function ($) {
                if (typeof fieldSettings === 'object' && fieldSettings.cap_captcha) {
                    fieldSettings.cap_captcha += ', .cap_display_mode_setting';
                }

                $(document).on('gform_load_field_settings', function (event, field) {
                    if (!field || field.type !== 'cap_captcha') {
                        return;
                    }
                    $('#cap_display_mode').val(field.capDisplayMode || '');
                });

                if (typeof window.SetDefaultValues_cap_captcha !== 'function') {
                    window.SetDefaultValues_cap_captcha = function (field) {
                        field.label = '__CAP_DEFAULT_LABEL__';
                        return field;
                    };
                }
            })(jQuery);
            JS;

        $js = str_replace('__CAP_DEFAULT_LABEL__', esc_js($defaultLabel), $js);

        wp_print_inline_script_tag($js);
    }

    public function is_conditional_logic_supported(): bool
    {
        return false;
    }

    public function is_value_submission_empty($form_id): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Gravity Forms verifies its own nonce before this hook fires.
        $token = isset($_POST['cap-token']) ? sanitize_text_field(wp_unslash($_POST['cap-token'])) : '';

        return $token === '' || trim($token) === '';
    }

    /**
     * Suppress the front-end `<label>` — the Cap widget provides its own label.
     * The admin label remains visible inside the form editor.
     *
     * Both parameters must stay optional: Gravity Forms 3.0 made them optional
     * on GF_Field, and a child declaring them as required is a fatal signature
     * error. The defaults are harmless on GF 2.x, which always passes both.
     *
     * @param  bool  $force_frontend_label
     * @param  int|string  $value
     */
    public function get_field_label($force_frontend_label = true, $value = ''): string
    {
        if ($this->is_form_editor()) {
            return (string) parent::get_field_label($force_frontend_label, $value);
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $form
     * @param  string|array<mixed>  $value
     * @param  array<string, mixed>|null  $entry
     */
    public function get_field_input($form, $value = '', $entry = null): string
    {
        $settings = Settings::get_instance();
        $formId = (int) ($form['id'] ?? 0);
        $fieldId = (int) $this->id;
        $inputId = "input_{$formId}_{$fieldId}";
        $cssClass = trim('ginput_container ginput_container_cap_captcha '.($this->cssClass ?? ''));

        $renderer = new Renderer($settings);

        if ($settings->getWidgetEndpoint() === '') {
            $notice = $this->is_form_editor()
                ? sprintf(
                    '<em>%s</em>',
                    esc_html__('Privacy CAPTCHA for Cap is not configured. Set the endpoint and keys in Settings → Privacy CAPTCHA for Cap.', 'privacy-captcha-for-cap')
                )
                : $renderer->renderAdminNotice(__('Gravity Forms', 'privacy-captcha-for-cap'));

            return sprintf('<div class="%s">%s</div>', esc_attr($cssClass), $notice);
        }

        // For floating mode, GF's gform_submit_button filter attaches the
        // floating attrs to the form's own submit button — no standalone UI
        // needed here, just the hidden widget element.
        $markup = $renderer->renderForMode($inputId, $this->resolveDisplayMode(), false);

        return sprintf('<div class="%s">%s</div>', esc_attr($cssClass), $markup);
    }
}
