# Privacy CAPTCHA for Cap

WordPress plugin that integrates the self-hosted [Cap](https://trycap.dev/) proof-of-work CAPTCHA into WordPress comments, login, registration, WooCommerce checkout, and Gravity Forms — with server-side verification.

## Requirements

- WordPress 6.5+ (uses `wp_enqueue_script_module`)
- PHP 8.3+
- A reachable Cap server
- Gravity Forms 2.5+ (only if the Gravity Forms integration is enabled)
- WooCommerce (only if the WooCommerce integration is enabled)

## Installation (development)

```sh
composer install
bun install
bun run build   # copies cap-widget + WASM into assets/
```

The `assets/js/vendor/cap-widget.*` and `assets/wasm/cap_wasm_bg.wasm` files are committed so the plugin works straight from a checkout (and from a WordPress.org zip). Rerun `bun run build` after bumping the upstream deps. `bun run build:check` fails CI if the vendored files drift.

## Configuration

1. Activate the plugin.
2. Go to **Settings → Privacy CAPTCHA for Cap**.
3. Enter your Cap *endpoint base URL* (e.g. `https://cap.example.com/`), *site key*, and *secret key*. Optionally define `CAP_CAPTCHA_SECRET_KEY` in `wp-config.php` to keep the secret out of `wp_options`.
4. Pick a default *display mode*: inline, floating, or programmatic.
5. Pick a *WASM source*: bundled (default, DSGVO-clean) or your own Cap server. Either way the file is served from your own infrastructure — no third-party CDN.
6. Tick the integrations you want enabled.

## Display modes

| Mode | Behaviour |
| --- | --- |
| **Inline** | The widget is rendered visibly inside the form. |
| **Floating** | A trigger button is shown; clicking it opens the widget as a popover. |
| **Programmatic** | A hidden `cap-token` input is rendered. A background script solves the proof-of-work on page load and writes the token before submit. No user interaction needed. |

The Gravity Forms field has a *Display mode* setting that overrides the global default per field.

## Protected surfaces

Each surface is an independent on/off toggle in **Settings → Privacy CAPTCHA for Cap → Integrations**, and the id below is the `$context` passed to the protection filters:

| Context | Surface |
| --- | --- |
| `gravity_forms` | Gravity Forms field |
| `contact_form_7` | Contact Form 7 forms |
| `comments` | WordPress comment form |
| `login` | wp-login.php / `login_form` |
| `registration` | WordPress registration |
| `woocommerce_checkout` | WooCommerce checkout |
| `woocommerce_login` | WooCommerce My Account login |
| `woocommerce_registration` | WooCommerce My Account registration |
| `woocommerce_lost_password` | WooCommerce My Account lost password |

## Filter hooks

- `cap_captcha_protect` — master gate for **every** surface. `($enabled, $context)` → bool. Runs before the widget renders and before a submission is verified, so it controls all situations. Example: `add_filter('cap_captcha_protect', fn($on, $ctx) => is_user_logged_in() ? false : $on, 10, 2);`
- `cap_captcha_protect_{context}` — per-surface gate, e.g. `cap_captcha_protect_woocommerce_login`. `($enabled)` → bool. Runs after the master filter. (For `gravity_forms` this is evaluated at load time, so add the filter before the plugin boots.)
- `cap_captcha_widget_src` — override the URL the `cap-widget` ES module is loaded from. Default: `assets/js/vendor/cap-widget.js`.
- `cap_captcha_floating_src` — override the URL of `cap-widget.floating.js`.
- `cap_captcha_programmatic_src` — override the URL of `assets/js/programmatic.js`.
- `cap_captcha_style_src` — override the front-end stylesheet URL. Return `''` to disable the bundled styles entirely.
- `cap_captcha_wasm_url` — override the URL the widget loads its WASM bundle from (sets `window.CAP_CUSTOM_WASM_URL`). Default is whatever the *WASM source* setting resolves to.
- `cap_captcha_pako_url` — override the URL the widget loads the `pako` decompression library from (sets `window.CAP_PAKO_URL`). Only fetched by older browsers lacking the native `DecompressionStream` API. Default: `assets/js/vendor/pako_inflate.min.js`.
- `cap_captcha_i18n` — override the `data-cap-i18n-*` strings used on `<cap-widget>`. Accepts an `array<string,string>`.
- `cap_captcha_floating_button_classes` — override the CSS classes on the floating-mode trigger button.
- `cap_captcha_floating_position` — `top` (default) or `bottom`. Position of the floating popover relative to the trigger button.
- `cap_captcha_floating_autosubmit_src` — override the URL of the auto-submit helper (`assets/js/floating-autosubmit.js`). Return `''` to disable auto-submit after solve.
- `cap_captcha_display_mode` — override the resolved mode for a specific Gravity Forms field. Receives `($mode, $field)`.

## Scripts

| Command | What it does |
| --- | --- |
| `composer test` | Run Pest unit tests |
| `composer phpstan` | Static analysis (level 6) |
| `composer format` | Apply Pint formatting |
| `composer format:test` | Check Pint formatting |
| `bun run build` | Copy vendored cap-widget + WASM into `assets/` |
| `bun run build:check` | Fail if vendored assets are out of date |
| `bun run translate` | Regenerate POT + update PO files + compile MO |

## Architecture

```
src/
├── Plugin.php                          # boot, register integrations
├── Settings.php                        # WP Settings → Privacy CAPTCHA for Cap
├── Asset/
│   ├── Renderer.php                    # builds <cap-widget> markup
│   └── Enqueuer.php                    # wp_enqueue_script_module, CSS, globals
├── Verification/
│   ├── CapVerifier.php                 # raw HTTP call to /siteverify
│   └── TokenVerifier.php               # request-level wrapper, fail-open
└── Integration/
    ├── Integration.php                 # interface
    ├── Comments.php
    ├── Login.php
    ├── Registration.php
    ├── WooCommerce.php
    ├── GravityForms.php                # registers field + validator
    └── GravityForms/
        ├── Field.php                   # GF_Field subclass
        └── Validator.php               # gform_validation handler
```

## License

GPL-2.0-or-later
