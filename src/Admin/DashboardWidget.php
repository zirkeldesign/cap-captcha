<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Admin;

use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Status\StatsClient;
use ZirkelDesign\CapCaptcha\Status\StatusPanel;

/**
 * "At a glance" Cap server stats on the WordPress dashboard, reusing the same
 * StatusPanel as the settings page. Reads the 5-minute cached payload so the
 * dashboard never blocks on the Cap API.
 */
final class DashboardWidget
{
    public const WIDGET_ID = 'cap_captcha_overview';

    public function __construct(private readonly Settings $settings) {}

    public function register(): void
    {
        add_action('wp_dashboard_setup', [$this, 'addWidget']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addWidget(): void
    {
        if (! current_user_can(Settings::CAPABILITY)) {
            return;
        }

        wp_add_dashboard_widget(
            self::WIDGET_ID,
            __('Privacy CAPTCHA for Cap', 'privacy-captcha-for-cap'),
            [$this, 'render']
        );
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'index.php' || ! current_user_can(Settings::CAPABILITY)) {
            return;
        }

        wp_enqueue_style(
            'cap-captcha-admin',
            CAP_CAPTCHA_URL.'assets/css/admin.css',
            [],
            CAP_CAPTCHA_VERSION
        );
    }

    public function render(): void
    {
        $settingsUrl = admin_url('options-general.php?page='.Settings::SLUG);

        if (! $this->settings->isConfigured() || $this->settings->getAdminApiKey() === '') {
            printf(
                '<p>%s</p><p><a class="button button-primary" href="%s">%s</a></p>',
                esc_html__('Finish setup to see your Cap server stats here: add your endpoint, site key, and admin API key.', 'privacy-captcha-for-cap'),
                esc_url($settingsUrl),
                esc_html__('Open settings', 'privacy-captcha-for-cap')
            );

            return;
        }

        $stats = (new StatsClient($this->settings))->fetch();

        if ($stats === null) {
            echo '<p>'.esc_html__('Could not fetch status from the Cap server. Check the Endpoint, Site key, and Admin API key.', 'privacy-captcha-for-cap').'</p>';
        } else {
            echo '<div class="cap-captcha-status cap-captcha-status--dashboard">';
            (new StatusPanel)->render($stats);
            echo '</div>';
        }

        printf(
            '<p class="cap-captcha-status__settings-link"><a href="%s">%s</a></p>',
            esc_url($settingsUrl),
            esc_html__('Settings & full status →', 'privacy-captcha-for-cap')
        );
    }
}
