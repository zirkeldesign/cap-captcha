<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha;

use ZirkelDesign\CapCaptcha\Status\StatsClient;
use ZirkelDesign\CapCaptcha\Status\StatusPanel;

class Settings
{
    public const OPTION_KEY = 'cap_captcha_settings';

    public const SLUG = 'cap-captcha';

    public const CAPABILITY = 'manage_options';

    public const MODE_INLINE = 'inline';

    public const MODE_FLOATING = 'floating';

    public const MODE_PROGRAMMATIC = 'programmatic';

    public const WASM_BUNDLED = 'bundled';

    public const WASM_CAP_SERVER = 'cap_server';

    public const CF7_AUTOMATIC = 'automatic';

    public const CF7_MANUAL = 'manual';

    /**
     * Every protectable surface/context. Each is an independent on/off toggle
     * and the `$context` passed to the `cap_captcha_protect` filter.
     */
    public const SURFACES = [
        'gravity_forms',
        'contact_form_7',
        'comments',
        'login',
        'registration',
        'woocommerce_checkout',
        'woocommerce_login',
        'woocommerce_registration',
        'woocommerce_lost_password',
    ];

    private static ?Settings $instance = null;

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public static function get_instance(): Settings
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    public function registerHooks(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_action('wp_ajax_cap_captcha_test_connection', [$this, 'ajaxTestConnection']);
    }

    public function enqueueAdminAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'settings_page_'.self::SLUG) {
            return;
        }

        wp_enqueue_style(
            'cap-captcha-admin',
            CAP_CAPTCHA_URL.'assets/css/admin.css',
            [],
            CAP_CAPTCHA_VERSION
        );

        wp_enqueue_script(
            'cap-captcha-admin',
            CAP_CAPTCHA_URL.'assets/js/admin.js',
            [],
            CAP_CAPTCHA_VERSION,
            ['strategy' => 'defer', 'in_footer' => true]
        );

        wp_add_inline_script(
            'cap-captcha-admin',
            'window.capCaptchaAdmin = '.wp_json_encode([
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('cap_captcha_test_connection'),
                'i18n' => [
                    'testing' => __('Testing…', 'privacy-captcha-for-cap'),
                    'ok' => __('Connection OK', 'privacy-captcha-for-cap'),
                    'error' => __('Connection failed', 'privacy-captcha-for-cap'),
                ],
            ]).';',
            'before'
        );
    }

    public function ajaxTestConnection(): void
    {
        check_ajax_referer('cap_captcha_test_connection');

        if (! current_user_can(self::CAPABILITY)) {
            wp_send_json_error(['message' => __('You do not have permission to run this check.', 'privacy-captcha-for-cap')], 403);
        }

        if (! $this->isConfigured()) {
            wp_send_json_error(['message' => __('Endpoint / Site key / Secret key not yet configured.', 'privacy-captcha-for-cap')]);
        }

        if ($this->getAdminApiKey() === '') {
            wp_send_json_error(['message' => __('Admin API key is empty — connection test needs it.', 'privacy-captcha-for-cap')]);
        }

        $stats = (new StatsClient($this))->fetch(true);

        if ($stats === null) {
            wp_send_json_error(['message' => __('Cap server did not return a valid response. Verify endpoint and admin API key.', 'privacy-captcha-for-cap')]);
        }

        $name = (string) ($stats['key']['name'] ?? '');
        $siteKey = (string) ($stats['key']['siteKey'] ?? '');

        wp_send_json_success([
            'message' => sprintf(
                /* translators: 1: site name, 2: site key */
                __('Connected — site "%1$s" (%2$s)', 'privacy-captcha-for-cap'),
                $name !== '' ? $name : '—',
                $siteKey
            ),
        ]);
    }

    public function registerMenu(): void
    {
        add_options_page(
            __('Privacy CAPTCHA for Cap', 'privacy-captcha-for-cap'),
            __('Privacy CAPTCHA for Cap', 'privacy-captcha-for-cap'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'renderPage']
        );
    }

    public function registerSettings(): void
    {
        register_setting(self::SLUG, self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default' => $this->defaults(),
        ]);
    }

    /**
     * @param  mixed  $input
     * @return array<string, mixed>
     */
    public function sanitize($input): array
    {
        if (! is_array($input)) {
            $input = [];
        }

        $mode = (string) ($input['display_mode'] ?? self::MODE_INLINE);
        if (! in_array($mode, [self::MODE_INLINE, self::MODE_FLOATING, self::MODE_PROGRAMMATIC], true)) {
            $mode = self::MODE_INLINE;
        }

        $wasm = (string) ($input['wasm_source'] ?? self::WASM_BUNDLED);
        if (! in_array($wasm, [self::WASM_BUNDLED, self::WASM_CAP_SERVER], true)) {
            $wasm = self::WASM_BUNDLED;
        }

        $integrations = [];
        $rawIntegrations = is_array($input['integrations'] ?? null) ? $input['integrations'] : [];
        foreach (self::SURFACES as $id) {
            $integrations[$id] = ! empty($rawIntegrations[$id]);
        }
        // WooCommerce master toggle (gates the four woocommerce_* sub-surfaces).
        $integrations['woocommerce'] = ! empty($rawIntegrations['woocommerce']);

        $cf7Mode = (string) ($input['cf7_mode'] ?? self::CF7_AUTOMATIC);
        if (! in_array($cf7Mode, [self::CF7_AUTOMATIC, self::CF7_MANUAL], true)) {
            $cf7Mode = self::CF7_AUTOMATIC;
        }

        $failOpenOverrides = [];
        $rawOverrides = is_array($input['fail_open_overrides'] ?? null) ? $input['fail_open_overrides'] : [];
        foreach (self::SURFACES as $id) {
            $value = (string) ($rawOverrides[$id] ?? self::FAIL_OPEN_DEFAULT);
            if (in_array($value, [self::FAIL_OPEN_OPEN, self::FAIL_OPEN_CLOSED], true)) {
                $failOpenOverrides[$id] = $value;
            }
        }

        // Disabled inputs aren't submitted; keep the existing stored values so
        // toggling the wp-config constant on/off doesn't wipe whatever was saved
        // before. We don't trust the placeholder bullets that might leak through.
        $stored = $this->getAll();
        $endpoint = $this->isEndpointConstantSet()
            ? (string) ($stored['endpoint_base'] ?? '')
            : esc_url_raw((string) ($input['endpoint_base'] ?? ''));
        $siteKey = $this->isSiteKeyConstantSet()
            ? (string) ($stored['site_key'] ?? '')
            : $this->sanitizeKey($input['site_key'] ?? '');
        $secretKey = $this->isSecretKeyConstantSet()
            ? (string) ($stored['secret_key'] ?? '')
            : $this->sanitizeKey($input['secret_key'] ?? '');
        $adminApiKey = $this->isAdminApiKeyConstantSet()
            ? (string) ($stored['admin_api_key'] ?? '')
            : $this->sanitizeKey($input['admin_api_key'] ?? '');

        return [
            'endpoint_base' => $endpoint,
            'site_key' => $siteKey,
            'secret_key' => $secretKey,
            'admin_api_key' => $adminApiKey,
            'display_mode' => $mode,
            'wasm_source' => $wasm,
            'fail_open' => ! empty($input['fail_open']),
            'show_admin_notices' => ! empty($input['show_admin_notices']),
            'integrations' => $integrations,
            'cf7_mode' => $cf7Mode,
            'gf_protect_all' => ! empty($input['gf_protect_all']),
            'fail_open_overrides' => $failOpenOverrides,
        ];
    }

    /**
     * Minimal sanitizer for opaque API keys/secrets. sanitize_text_field()
     * would strip characters that may be valid in a token, so we only unslash
     * and trim surrounding whitespace plus any line breaks. Every read of these
     * values is escaped via esc_attr() before output.
     *
     * @param  mixed  $value
     */
    private function sanitizeKey($value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return trim(str_replace(["\r", "\n", "\t"], '', (string) wp_unslash($value)));
    }

    public function renderPage(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'privacy-captcha-for-cap'));
        }

        $v = $this->getAll();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Privacy CAPTCHA for Cap', 'privacy-captcha-for-cap'); ?></h1>
            <p>
                <?php
                printf(
                    /* translators: %s is a link to trycap.dev */
                    esc_html__('Connect WordPress to your self-hosted Cap server and choose which forms to protect. See %s for server setup.', 'privacy-captcha-for-cap'),
                    '<a href="https://trycap.dev/" target="_blank" rel="noopener noreferrer">trycap.dev</a>'
                );
        ?>
            </p>

            <form method="post" action="options.php">
                <?php settings_fields(self::SLUG); ?>

                <h2><?php echo esc_html__('Cap server', 'privacy-captcha-for-cap'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php $endpointLocked = $this->isEndpointConstantSet(); ?>
                    <tr>
                        <th scope="row"><label for="cap_endpoint_base"><?php echo esc_html__('Endpoint base URL', 'privacy-captcha-for-cap'); ?></label></th>
                        <td>
                            <input
                                type="url"
                                id="cap_endpoint_base"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[endpoint_base]"
                                value="<?php echo esc_attr($endpointLocked ? (string) \CAP_CAPTCHA_ENDPOINT : $v['endpoint_base']); ?>"
                                class="regular-text"
                                placeholder="https://cap.example.com/"
                                <?php echo $endpointLocked ? 'disabled' : ''; ?>
                            >
                            <p class="description">
                                <?php if ($endpointLocked) { ?>
                                    <?php echo esc_html__('Defined via CAP_CAPTCHA_ENDPOINT in wp-config.php — value here is read-only.', 'privacy-captcha-for-cap'); ?>
                                <?php } else { ?>
                                    <?php echo esc_html__('Public URL of your Cap server, e.g. https://cap.example.com/', 'privacy-captcha-for-cap'); ?>
                                <?php } ?>
                            </p>
                        </td>
                    </tr>
                    <?php $siteKeyLocked = $this->isSiteKeyConstantSet(); ?>
                    <tr>
                        <th scope="row"><label for="cap_site_key"><?php echo esc_html__('Site key', 'privacy-captcha-for-cap'); ?></label></th>
                        <td>
                            <input
                                type="text"
                                id="cap_site_key"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[site_key]"
                                value="<?php echo esc_attr($siteKeyLocked ? (string) \CAP_CAPTCHA_SITE_KEY : $v['site_key']); ?>"
                                class="regular-text"
                                autocomplete="off"
                                <?php echo $siteKeyLocked ? 'disabled' : ''; ?>
                            >
                            <p class="description">
                                <?php if ($siteKeyLocked) { ?>
                                    <?php echo esc_html__('Defined via CAP_CAPTCHA_SITE_KEY in wp-config.php — value here is read-only.', 'privacy-captcha-for-cap'); ?>
                                <?php } else { ?>
                                    <?php echo esc_html__('Public site key issued by your Cap server.', 'privacy-captcha-for-cap'); ?>
                                <?php } ?>
                            </p>
                        </td>
                    </tr>
                    <?php $secretLocked = $this->isSecretKeyConstantSet(); ?>
                    <tr>
                        <th scope="row"><label for="cap_secret_key"><?php echo esc_html__('Secret key', 'privacy-captcha-for-cap'); ?></label></th>
                        <td>
                            <input
                                type="password"
                                id="cap_secret_key"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[secret_key]"
                                value="<?php echo esc_attr($secretLocked ? '••••••••••••' : $v['secret_key']); ?>"
                                class="regular-text"
                                autocomplete="off"
                                <?php echo $secretLocked ? 'disabled' : ''; ?>
                            >
                            <p class="description">
                                <?php if ($secretLocked) { ?>
                                    <?php echo esc_html__('Defined via CAP_CAPTCHA_SECRET_KEY in wp-config.php — value here is read-only.', 'privacy-captcha-for-cap'); ?>
                                <?php } else { ?>
                                    <?php echo esc_html__('Secret key used to call /siteverify. Define CAP_CAPTCHA_SECRET_KEY in wp-config.php to override.', 'privacy-captcha-for-cap'); ?>
                                <?php } ?>
                            </p>
                        </td>
                    </tr>
                    <?php $apiLocked = $this->isAdminApiKeyConstantSet(); ?>
                    <tr>
                        <th scope="row"><label for="cap_admin_api_key"><?php echo esc_html__('Admin API key', 'privacy-captcha-for-cap'); ?></label></th>
                        <td>
                            <input
                                type="password"
                                id="cap_admin_api_key"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[admin_api_key]"
                                value="<?php echo esc_attr($apiLocked ? '••••••••••••' : $v['admin_api_key']); ?>"
                                class="regular-text"
                                autocomplete="off"
                                <?php echo $apiLocked ? 'disabled' : ''; ?>
                            >
                            <p class="description">
                                <?php if ($apiLocked) { ?>
                                    <?php echo esc_html__('Defined via CAP_CAPTCHA_ADMIN_API_KEY in wp-config.php — value here is read-only.', 'privacy-captcha-for-cap'); ?>
                                <?php } else { ?>
                                    <?php echo esc_html__('Optional. Issue a dedicated key under Settings → API Keys in your Cap server and paste it here. Used for the Status panel below. Define CAP_CAPTCHA_ADMIN_API_KEY in wp-config.php to override.', 'privacy-captcha-for-cap'); ?>
                                <?php } ?>
                            </p>
                            <p class="cap-captcha-test-connection">
                                <button type="button" class="button" data-cap-captcha-test>
                                    <?php echo esc_html__('Test connection', 'privacy-captcha-for-cap'); ?>
                                </button>
                                <span data-cap-captcha-test-result class="cap-captcha-test-connection__result" aria-live="polite"></span>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php $this->renderStatusPanel(); ?>

                <h2><?php echo esc_html__('Display', 'privacy-captcha-for-cap'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="cap_display_mode"><?php echo esc_html__('Default display mode', 'privacy-captcha-for-cap'); ?></label></th>
                        <td>
                            <select id="cap_display_mode" name="<?php echo esc_attr(self::OPTION_KEY); ?>[display_mode]">
                                <option value="<?php echo esc_attr(self::MODE_INLINE); ?>" <?php selected($v['display_mode'], self::MODE_INLINE); ?>><?php echo esc_html__('Inline — widget visible in the form', 'privacy-captcha-for-cap'); ?></option>
                                <option value="<?php echo esc_attr(self::MODE_FLOATING); ?>" <?php selected($v['display_mode'], self::MODE_FLOATING); ?>><?php echo esc_html__('Floating — opens on click of a trigger button', 'privacy-captcha-for-cap'); ?></option>
                                <option value="<?php echo esc_attr(self::MODE_PROGRAMMATIC); ?>" <?php selected($v['display_mode'], self::MODE_PROGRAMMATIC); ?>><?php echo esc_html__('Programmatic — solves silently in the background (no user interaction)', 'privacy-captcha-for-cap'); ?></option>
                            </select>
                            <p class="description"><?php echo esc_html__('Per-form integrations can override this default.', 'privacy-captcha-for-cap'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('Privacy', 'privacy-captcha-for-cap'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('WASM source', 'privacy-captcha-for-cap'); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[wasm_source]" value="<?php echo esc_attr(self::WASM_BUNDLED); ?>" <?php checked($v['wasm_source'], self::WASM_BUNDLED); ?>>
                                    <?php echo esc_html__('Bundled — load from this plugin (recommended, DSGVO-clean)', 'privacy-captcha-for-cap'); ?>
                                </label><br>
                                <label>
                                    <input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[wasm_source]" value="<?php echo esc_attr(self::WASM_CAP_SERVER); ?>" <?php checked($v['wasm_source'], self::WASM_CAP_SERVER); ?>>
                                    <?php echo esc_html__('Cap server — load from your endpoint at /assets/cap_wasm_bg.wasm', 'privacy-captcha-for-cap'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('Integrations', 'privacy-captcha-for-cap'); ?></h2>
                <?php foreach ($this->integrationGroups() as $group) { ?>
                    <h3 class="cap-captcha-integrations__heading"><?php echo esc_html($group['label']); ?></h3>
                    <p class="description cap-captcha-integrations__description"><?php echo esc_html($group['description']); ?></p>
                    <?php $this->renderIntegrationCards($group, $v); ?>
                <?php } ?>

                <h2><?php echo esc_html__('Behavior', 'privacy-captcha-for-cap'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Fail-open mode', 'privacy-captcha-for-cap'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[fail_open]" value="1" <?php checked($v['fail_open']); ?>>
                                <?php echo esc_html__('Let submissions through when the Cap server is unreachable', 'privacy-captcha-for-cap'); ?>
                            </label>
                            <p class="description"><?php echo esc_html__('Off by default. Enable only if temporary Cap outages must not block legitimate users (logins, checkouts).', 'privacy-captcha-for-cap'); ?></p>
                            <?php $this->renderFailOpenOverrides($v); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Admin notices', 'privacy-captcha-for-cap'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[show_admin_notices]" value="1" <?php checked($v['show_admin_notices']); ?>>
                                <?php echo esc_html__('Warn site admins inline when an enabled integration is not configured', 'privacy-captcha-for-cap'); ?>
                            </label>
                            <p class="description"><?php echo esc_html__('Only users with manage_options ever see these notices — anonymous visitors get nothing rendered.', 'privacy-captcha-for-cap'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Save settings', 'privacy-captcha-for-cap'), 'primary large'); ?>
            </form>
        </div>
        <?php
    }

    private function renderStatusPanel(): void
    {
        if (! $this->isConfigured() || $this->getAdminApiKey() === '') {
            return;
        }

        $bypass = isset($_GET['cap_captcha_refresh']) && check_admin_referer('cap_captcha_refresh');
        $stats = (new StatsClient($this))->fetch($bypass);

        echo '<h2>'.esc_html__('Status', 'privacy-captcha-for-cap').'</h2>';

        if ($stats === null) {
            echo '<div class="notice notice-warning inline"><p>'
                .esc_html__('Could not fetch status from the Cap server. Check the Endpoint, Site key, and Admin API key.', 'privacy-captcha-for-cap')
                .'</p></div>';

            return;
        }

        $refreshUrl = wp_nonce_url(
            add_query_arg('cap_captcha_refresh', '1'),
            'cap_captcha_refresh'
        );

        ?>
        <div class="cap-captcha-status">
            <?php (new StatusPanel)->render($stats); ?>
            <p class="cap-captcha-status__refresh">
                <a class="button" href="<?php echo esc_url($refreshUrl); ?>"><?php echo esc_html__('Refresh now', 'privacy-captcha-for-cap'); ?></a>
                <span class="description"><?php echo esc_html__('Status is cached for 5 minutes.', 'privacy-captcha-for-cap'); ?></span>
            </p>
        </div>
        <?php
    }

    /**
     * Integrations grouped ALTCHA-style: WordPress core vs. third-party plugins.
     *
     * @return array<int, array{label: string, description: string, collapsible?: bool, items: array<string, array{label: string, description: string, available: bool}>}>
     */
    private function integrationGroups(): array
    {
        $all = $this->integrationDescriptions();

        return [
            [
                'label' => __('WordPress', 'privacy-captcha-for-cap'),
                'description' => __('Protect WordPress core forms.', 'privacy-captcha-for-cap'),
                'items' => array_intersect_key($all, array_flip(['comments', 'login', 'registration'])),
            ],
            [
                'label' => __('Form plugins', 'privacy-captcha-for-cap'),
                'description' => __('Protect form-builder plugin submissions.', 'privacy-captcha-for-cap'),
                'items' => array_intersect_key($all, array_flip(['gravity_forms', 'contact_form_7'])),
            ],
            [
                'label' => __('WooCommerce', 'privacy-captcha-for-cap'),
                'description' => __('Protect WooCommerce checkout and My Account forms.', 'privacy-captcha-for-cap'),
                'items' => array_intersect_key($all, array_flip(['woocommerce'])),
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, description: string, available: bool}>
     */
    private function integrationDescriptions(): array
    {
        $wooAvailable = class_exists('WooCommerce');

        return [
            'gravity_forms' => [
                'label' => __('Gravity Forms', 'privacy-captcha-for-cap'),
                'description' => __('Adds a "Privacy CAPTCHA for Cap" field to the form editor under Advanced Fields.', 'privacy-captcha-for-cap'),
                'available' => class_exists('GFForms') || class_exists('GFAPI'),
            ],
            'contact_form_7' => [
                'label' => __('Contact Form 7', 'privacy-captcha-for-cap'),
                'description' => __('Protects every Contact Form 7 form submission.', 'privacy-captcha-for-cap'),
                'available' => defined('WPCF7_VERSION') || class_exists('WPCF7'),
            ],
            'comments' => [
                'label' => __('Comments', 'privacy-captcha-for-cap'),
                'description' => __('Protects the default WordPress comment form.', 'privacy-captcha-for-cap'),
                'available' => true,
            ],
            'login' => [
                'label' => __('Login', 'privacy-captcha-for-cap'),
                'description' => __('Protects wp-login.php and any front-end login form using login_form.', 'privacy-captcha-for-cap'),
                'available' => true,
            ],
            'registration' => [
                'label' => __('Registration', 'privacy-captcha-for-cap'),
                'description' => __('Protects the WordPress user registration form.', 'privacy-captcha-for-cap'),
                'available' => true,
            ],
            'woocommerce' => [
                'label' => __('WooCommerce', 'privacy-captcha-for-cap'),
                'description' => __('Enable WooCommerce protection, then choose which forms below.', 'privacy-captcha-for-cap'),
                'available' => $wooAvailable,
            ],
            'woocommerce_checkout' => [
                'label' => __('Checkout', 'privacy-captcha-for-cap'),
                'description' => __('Protects the WooCommerce checkout form.', 'privacy-captcha-for-cap'),
                'available' => $wooAvailable,
            ],
            'woocommerce_login' => [
                'label' => __('Account login', 'privacy-captcha-for-cap'),
                'description' => __('Protects the My Account login form.', 'privacy-captcha-for-cap'),
                'available' => $wooAvailable,
            ],
            'woocommerce_registration' => [
                'label' => __('Account registration', 'privacy-captcha-for-cap'),
                'description' => __('Protects the My Account registration form.', 'privacy-captcha-for-cap'),
                'available' => $wooAvailable,
            ],
            'woocommerce_lost_password' => [
                'label' => __('Lost password', 'privacy-captcha-for-cap'),
                'description' => __('Protects the My Account lost-password form.', 'privacy-captcha-for-cap'),
                'available' => $wooAvailable,
            ],
        ];
    }

    /**
     * Render a single integration toggle card.
     *
     * @param  array{label: string, description: string, available: bool}  $meta
     * @param  array<string, mixed>  $v
     */
    private function renderIntegrationCard(string $id, array $meta, array $v): void
    {
        ?>
        <label class="cap-captcha-integration <?php echo $meta['available'] ? '' : 'cap-captcha-integration--disabled'; ?>">
            <input
                type="checkbox"
                class="cap-captcha-integration__toggle"
                name="<?php echo esc_attr(self::OPTION_KEY); ?>[integrations][<?php echo esc_attr($id); ?>]"
                value="1"
                <?php checked(! empty($v['integrations'][$id])); ?>
                <?php disabled(! $meta['available']); ?>
            >
            <span class="cap-captcha-integration__body">
                <strong class="cap-captcha-integration__name"><?php echo esc_html($meta['label']); ?></strong>
                <?php if ($meta['description'] !== '') { ?>
                    <span class="cap-captcha-integration__hint"><?php echo esc_html($meta['description']); ?></span>
                <?php } ?>
                <?php if (! $meta['available']) { ?>
                    <span class="cap-captcha-integration__status"><?php echo esc_html__('Plugin not active', 'privacy-captcha-for-cap'); ?></span>
                <?php } ?>
                <?php if ($meta['available'] && $this->integrationHasOptions($id)) { ?>
                    <a class="cap-captcha-integration__options-link" href="#cap-captcha-options-<?php echo esc_attr($id); ?>"><?php echo esc_html__('Options', 'privacy-captcha-for-cap'); ?> <span aria-hidden="true">↓</span></a>
                <?php } ?>
            </span>
        </label>
        <?php
    }

    /**
     * Render a group's card grid plus the placement-options disclosure for any
     * integration that has one.
     *
     * @param  array{items: array<string, array{label: string, description: string, available: bool}>}  $group
     * @param  array<string, mixed>  $v
     */
    private function renderIntegrationCards(array $group, array $v): void
    {
        ?>
        <div class="cap-captcha-integrations__grid">
            <?php foreach ($group['items'] as $id => $meta) {
                $this->renderIntegrationCard($id, $meta, $v);
            } ?>
        </div>
        <?php foreach ($group['items'] as $id => $meta) {
            if (! $meta['available'] || ! $this->integrationHasOptions($id)) {
                continue;
            }
            $enabled = ! empty($v['integrations'][$id]); ?>
            <details id="cap-captcha-options-<?php echo esc_attr($id); ?>" class="cap-captcha-integration-options<?php echo $enabled ? '' : ' cap-captcha-integration-options--muted'; ?>"<?php echo $enabled ? ' open' : ''; ?>>
                <summary class="cap-captcha-integration-options__summary"><?php
                    /* translators: %s is the integration name, e.g. "Gravity Forms". */
                    echo esc_html(sprintf(__('%s options', 'privacy-captcha-for-cap'), $meta['label'])); ?></summary>
                <div class="cap-captcha-integration-options__body">
                    <?php $this->renderIntegrationOptions($id, $v); ?>
                </div>
            </details>
        <?php }
        }

    /**
     * Per-surface fail-open override controls, shown in a disclosure under the
     * global fail-open toggle. Only surfaces whose plugin is available appear.
     *
     * @param  array<string, mixed>  $v
     */
    private function renderFailOpenOverrides(array $v): void
    {
        $all = $this->integrationDescriptions();
        $overrides = is_array($v['fail_open_overrides'] ?? null) ? $v['fail_open_overrides'] : [];

        $rows = [];
        foreach (self::SURFACES as $surface) {
            if (empty($all[$surface]['available'])) {
                continue;
            }
            $label = (string) $all[$surface]['label'];
            if (str_starts_with($surface, 'woocommerce_')) {
                $label = 'WooCommerce — '.$label;
            }
            $rows[$surface] = $label;
        }

        if ($rows === []) {
            return;
        }
        ?>
        <details class="cap-captcha-failopen">
            <summary class="cap-captcha-failopen__summary"><?php echo esc_html__('Override fail-open per form', 'privacy-captcha-for-cap'); ?></summary>
            <table class="cap-captcha-failopen__table">
                <?php foreach ($rows as $surface => $label) {
                    $value = (string) ($overrides[$surface] ?? self::FAIL_OPEN_DEFAULT); ?>
                    <tr>
                        <td><?php echo esc_html($label); ?></td>
                        <td>
                            <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[fail_open_overrides][<?php echo esc_attr($surface); ?>]">
                                <option value="<?php echo esc_attr(self::FAIL_OPEN_DEFAULT); ?>" <?php selected($value, self::FAIL_OPEN_DEFAULT); ?>><?php echo esc_html__('Default (use the global setting)', 'privacy-captcha-for-cap'); ?></option>
                                <option value="<?php echo esc_attr(self::FAIL_OPEN_OPEN); ?>" <?php selected($value, self::FAIL_OPEN_OPEN); ?>><?php echo esc_html__('Fail-open (let through if Cap is unreachable)', 'privacy-captcha-for-cap'); ?></option>
                                <option value="<?php echo esc_attr(self::FAIL_OPEN_CLOSED); ?>" <?php selected($value, self::FAIL_OPEN_CLOSED); ?>><?php echo esc_html__('Fail-closed (always require a valid proof)', 'privacy-captcha-for-cap'); ?></option>
                            </select>
                        </td>
                    </tr>
                <?php } ?>
            </table>
            <p class="description"><?php echo esc_html__('For example, let logins through during a Cap outage but always require a valid proof on contact forms.', 'privacy-captcha-for-cap'); ?></p>
        </details>
        <?php
    }

    /**
     * Whether an integration has placement options shown in a disclosure under
     * its card.
     */
    private function integrationHasOptions(string $id): bool
    {
        return in_array($id, ['gravity_forms', 'contact_form_7', 'woocommerce'], true);
    }

    /**
     * Render the per-integration placement options (inside the <details> body).
     *
     * @param  array<string, mixed>  $v
     */
    private function renderIntegrationOptions(string $id, array $v): void
    {
        if ($id === 'contact_form_7') {
            $mode = (string) ($v['cf7_mode'] ?? self::CF7_AUTOMATIC);
            ?>
            <fieldset>
                <label>
                    <input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[cf7_mode]" value="<?php echo esc_attr(self::CF7_AUTOMATIC); ?>" <?php checked($mode, self::CF7_AUTOMATIC); ?>>
                    <?php echo esc_html__('Automatic — protect every form (add the [cap_captcha] tag to a form for custom placement, or “cap_captcha: off” in its Additional Settings to skip it)', 'privacy-captcha-for-cap'); ?>
                </label><br>
                <label>
                    <input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[cf7_mode]" value="<?php echo esc_attr(self::CF7_MANUAL); ?>" <?php checked($mode, self::CF7_MANUAL); ?>>
                    <?php echo esc_html__('Manual — only protect forms that contain the [cap_captcha] tag', 'privacy-captcha-for-cap'); ?>
                </label>
            </fieldset>
            <p class="description"><?php echo esc_html__('Use Manual to keep the CAPTCHA off legally required or accessibility-sensitive forms.', 'privacy-captcha-for-cap'); ?></p>
            <?php
            return;
        }

        if ($id === 'gravity_forms') {
            ?>
            <label>
                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[gf_protect_all]" value="1" <?php checked(! empty($v['gf_protect_all'])); ?>>
                <?php echo esc_html__('Automatically protect every Gravity Form', 'privacy-captcha-for-cap'); ?>
            </label>
            <p class="description"><?php echo esc_html__('Otherwise add the “Privacy CAPTCHA for Cap” field to a form. Each form can override this under its own settings (Default / Always / Never).', 'privacy-captcha-for-cap'); ?></p>
            <?php
            return;
        }

        if ($id === 'woocommerce') {
            $all = $this->integrationDescriptions();
            $surfaces = ['woocommerce_checkout', 'woocommerce_login', 'woocommerce_registration', 'woocommerce_lost_password'];
            ?>
            <div class="cap-captcha-integrations__grid">
                <?php foreach ($surfaces as $surface) {
                    $this->renderIntegrationCard($surface, $all[$surface], $v);
                } ?>
            </div>
            <?php
        }
    }

    public function getEndpointBase(): string
    {
        if ($this->isEndpointConstantSet()) {
            $base = (string) \CAP_CAPTCHA_ENDPOINT;
        } else {
            $base = $this->get('endpoint_base');
        }

        return $base === '' ? '' : rtrim($base, '/').'/';
    }

    public function getSiteKey(): string
    {
        if ($this->isSiteKeyConstantSet()) {
            return (string) \CAP_CAPTCHA_SITE_KEY;
        }

        return $this->get('site_key');
    }

    public function getSecretKey(): string
    {
        if ($this->isSecretKeyConstantSet()) {
            return (string) \CAP_CAPTCHA_SECRET_KEY;
        }

        return $this->get('secret_key');
    }

    public function getAdminApiKey(): string
    {
        if ($this->isAdminApiKeyConstantSet()) {
            return (string) \CAP_CAPTCHA_ADMIN_API_KEY;
        }

        return $this->get('admin_api_key');
    }

    public function isEndpointConstantSet(): bool
    {
        return defined('CAP_CAPTCHA_ENDPOINT') && is_string(\CAP_CAPTCHA_ENDPOINT) && \CAP_CAPTCHA_ENDPOINT !== '';
    }

    public function isSiteKeyConstantSet(): bool
    {
        return defined('CAP_CAPTCHA_SITE_KEY') && is_string(\CAP_CAPTCHA_SITE_KEY) && \CAP_CAPTCHA_SITE_KEY !== '';
    }

    public function isSecretKeyConstantSet(): bool
    {
        return defined('CAP_CAPTCHA_SECRET_KEY') && is_string(\CAP_CAPTCHA_SECRET_KEY) && \CAP_CAPTCHA_SECRET_KEY !== '';
    }

    public function isAdminApiKeyConstantSet(): bool
    {
        return defined('CAP_CAPTCHA_ADMIN_API_KEY') && is_string(\CAP_CAPTCHA_ADMIN_API_KEY) && \CAP_CAPTCHA_ADMIN_API_KEY !== '';
    }

    public function getDisplayMode(): string
    {
        $mode = $this->get('display_mode');

        return in_array($mode, [self::MODE_INLINE, self::MODE_FLOATING, self::MODE_PROGRAMMATIC], true)
            ? $mode
            : self::MODE_INLINE;
    }

    public function isFloating(): bool
    {
        return $this->getDisplayMode() === self::MODE_FLOATING;
    }

    public function isProgrammatic(): bool
    {
        return $this->getDisplayMode() === self::MODE_PROGRAMMATIC;
    }

    public function isConfigured(): bool
    {
        return $this->getEndpointBase() !== ''
            && $this->getSiteKey() !== ''
            && $this->getSecretKey() !== '';
    }

    public const FAIL_OPEN_DEFAULT = 'default';

    public const FAIL_OPEN_OPEN = 'open';

    public const FAIL_OPEN_CLOSED = 'closed';

    /**
     * Whether to let a submission through when Cap cannot be reached. The
     * per-surface override (Default / Fail-open / Fail-closed) wins over the
     * global default, then the `cap_captcha_fail_open` filter has the final say.
     *
     * @param  string  $context  Surface id (see self::SURFACES); '' uses the global default.
     */
    public function isFailOpen(string $context = ''): bool
    {
        $values = $this->getAll();
        $global = ! empty($values['fail_open']);

        $overrides = is_array($values['fail_open_overrides'] ?? null) ? $values['fail_open_overrides'] : [];
        $override = (string) ($overrides[$context] ?? self::FAIL_OPEN_DEFAULT);

        $open = match ($override) {
            self::FAIL_OPEN_OPEN => true,
            self::FAIL_OPEN_CLOSED => false,
            default => $global,
        };

        /**
         * Filters the resolved fail-open decision for a surface.
         *
         * @param  bool  $open
         * @param  string  $context
         */
        return (bool) apply_filters('cap_captcha_fail_open', $open, $context);
    }

    /**
     * Whether to emit inline "not configured" notices for users who can act on
     * them. False both when the setting is off AND when the current user lacks
     * the capability — so callers can rely on this alone, no double-checking.
     */
    public function shouldShowAdminNotices(): bool
    {
        $values = $this->getAll();
        if (empty($values['show_admin_notices'])) {
            return false;
        }

        return function_exists('current_user_can') && current_user_can(self::CAPABILITY);
    }

    public function isIntegrationEnabled(string $id): bool
    {
        return $this->isSurfaceEnabled($id);
    }

    /**
     * Whether the admin enabled protection for this surface in the settings.
     */
    public function isSurfaceEnabled(string $context): bool
    {
        $values = $this->getAll();

        return ! empty($values['integrations'][$context]);
    }

    /**
     * Whether Cap protection should apply to a given surface/context. This is
     * the single gate every integration passes through before rendering the
     * widget AND before verifying a submission, so the filters below give
     * developers full control over every situation.
     *
     * @param  string  $context  One of self::SURFACES, e.g. 'login' or
     *                           'woocommerce_checkout'.
     */
    public function isProtected(string $context): bool
    {
        $enabled = $this->isSurfaceEnabled($context);

        // WooCommerce sub-surfaces also require the WooCommerce master toggle.
        if ($enabled && str_starts_with($context, 'woocommerce_') && ! $this->isSurfaceEnabled('woocommerce')) {
            $enabled = false;
        }

        /**
         * Filters whether Cap protection applies to any surface.
         *
         * @param  bool  $enabled  Whether the admin enabled this surface.
         * @param  string  $context  The surface id (see Settings::SURFACES).
         */
        $enabled = (bool) apply_filters('cap_captcha_protect', $enabled, $context);

        /**
         * Filters whether Cap protection applies to one specific surface. The
         * dynamic portion is the context, e.g. `cap_captcha_protect_login`.
         *
         * @param  bool  $enabled
         */
        return (bool) apply_filters("cap_captcha_protect_{$context}", $enabled);
    }

    /**
     * Contact Form 7 placement mode: CF7_AUTOMATIC protects every form (minus
     * per-form opt-outs); CF7_MANUAL protects only forms carrying the
     * [cap_captcha] tag.
     */
    public function getCf7Mode(): string
    {
        $values = $this->getAll();
        $mode = (string) ($values['cf7_mode'] ?? self::CF7_AUTOMATIC);

        return $mode === self::CF7_MANUAL ? self::CF7_MANUAL : self::CF7_AUTOMATIC;
    }

    /**
     * Whether every Gravity Form is auto-protected by default (a form can still
     * opt out individually).
     */
    public function isGfProtectAll(): bool
    {
        $values = $this->getAll();

        return ! empty($values['gf_protect_all']);
    }

    public function getWidgetEndpoint(): string
    {
        $base = $this->getEndpointBase();
        $siteKey = $this->getSiteKey();

        if ($base === '' || $siteKey === '') {
            return '';
        }

        return $base.$siteKey.'/';
    }

    public function getSelfHostedWasmUrl(): string
    {
        $values = $this->getAll();
        $source = (string) ($values['wasm_source'] ?? self::WASM_BUNDLED);

        return match ($source) {
            self::WASM_BUNDLED => CAP_CAPTCHA_URL.'assets/wasm/cap_wasm_bg.wasm',
            self::WASM_CAP_SERVER => $this->getEndpointBase() === ''
                ? ''
                : $this->getEndpointBase().'assets/cap_wasm_bg.wasm',
            default => CAP_CAPTCHA_URL.'assets/wasm/cap_wasm_bg.wasm',
        };
    }

    private function get(string $key): string
    {
        $values = $this->getAll();

        return trim((string) ($values[$key] ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function getAll(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $stored = get_option(self::OPTION_KEY, []);
        if (! is_array($stored)) {
            $stored = [];
        }

        $this->cache = array_replace($this->defaults(), $stored);

        // Migrate the pre-1.1 single `woocommerce` toggle (checkout only) to the
        // granular `woocommerce_checkout` surface.
        $integrations = is_array($this->cache['integrations'] ?? null) ? $this->cache['integrations'] : [];
        if (array_key_exists('woocommerce', $integrations) && ! array_key_exists('woocommerce_checkout', $integrations)) {
            $integrations['woocommerce_checkout'] = $integrations['woocommerce'];
        }
        $this->cache['integrations'] = $integrations;

        return $this->cache;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'endpoint_base' => '',
            'site_key' => '',
            'secret_key' => '',
            'admin_api_key' => '',
            'display_mode' => self::MODE_INLINE,
            'wasm_source' => self::WASM_BUNDLED,
            'fail_open' => false,
            'show_admin_notices' => true,
            'integrations' => [
                'gravity_forms' => true,
                'contact_form_7' => false,
                'comments' => false,
                'login' => false,
                'registration' => false,
                'woocommerce' => false,
                'woocommerce_checkout' => false,
                'woocommerce_login' => false,
                'woocommerce_registration' => false,
                'woocommerce_lost_password' => false,
            ],
            'cf7_mode' => self::CF7_AUTOMATIC,
            'gf_protect_all' => false,
            'fail_open_overrides' => [],
        ];
    }
}
