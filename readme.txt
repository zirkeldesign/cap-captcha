=== Privacy CAPTCHA for Cap ===
Contributors: dsturm
Tags: captcha, spam, proof-of-work, comments, woocommerce
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Privacy-friendly spam protection for comments, login, registration, WooCommerce, and Gravity Forms, powered by your own Cap server.

== Description ==

**Privacy CAPTCHA for Cap** integrates [Cap](https://trycap.dev/) — a self-hosted, privacy-friendly, proof-of-work CAPTCHA — into the parts of WordPress that attract the most spam: comments, login, user registration, WooCommerce checkout, and Gravity Forms.

Unlike third-party CAPTCHAs (reCAPTCHA, hCaptcha, Turnstile), Cap runs the proof-of-work entirely in the visitor's browser and verifies the token against your own Cap server. No data leaves your infrastructure.

> **Unofficial integration.** Cap is an independent open-source project by tiagozip (https://trycap.dev/, Apache-2.0). This plugin is a third-party integration and is **not affiliated with, endorsed by, or sponsored by** the Cap project. "Cap" refers to that project solely to describe what this plugin works with.

= Features =

* **First-class Gravity Forms field** — drag a "Privacy CAPTCHA for Cap" into any form from the Advanced Fields group. Per-field display-mode override.
* **Contact Form 7** integration — protects every CF7 form automatically.
* **WordPress comments, wp-login, registration** integrations — togglable from one settings page.
* **WooCommerce** integration — checkout plus the My Account login, registration, and lost-password forms, each its own toggle. Only loads when WooCommerce is active.
* **Dashboard widget** — at-a-glance Cap server stats (challenges, verified, failed, hourly chart) right on the WordPress dashboard.
* **Granular per-surface toggles** and **developer filters** (`cap_captcha_protect`) to enable/disable protection on any form, even conditionally.
* **Three display modes**: inline widget, floating popover, or fully programmatic (auto-solves silently).
* **Fully self-hosted assets** — the proof-of-work WebAssembly module, the cap-widget script, and the pako decompression library all ship inside the plugin and are served locally, so no jsdelivr or other third-party CDN is contacted at runtime. DSGVO/GDPR-clean by default.
* **WP 6.5+ native script-module API** for proper ES-module loading.
* **i18n-ready** with German translations bundled.
* **Theme-friendly CSS** built on Gravity Forms Orbital tokens with `--cap-captcha-*` overrides.
* **Filter hooks** for protection gating, asset URLs, button classes, i18n strings, and the display mode.

= Requirements =

* WordPress 6.5 or later
* PHP 8.3 or later
* A reachable Cap server (see https://trycap.dev/)
* Gravity Forms 2.5+ (only if you enable the Gravity Forms integration)
* WooCommerce (only if you enable the WooCommerce integration)

== Installation ==

1. Upload the `privacy-captcha-for-cap` folder to `/wp-content/plugins/`.
2. Activate the plugin via the *Plugins* menu in WordPress.
3. Go to **Settings → Privacy CAPTCHA for Cap** and enter your Cap endpoint URL, site key, and secret key.
4. Tick the integrations you want to enable.

== Frequently Asked Questions ==

= Is this an official Cap plugin? =

No. Cap is an independent open-source project by tiagozip (https://trycap.dev/), licensed under Apache-2.0. This plugin is an unofficial third-party integration and is not affiliated with or endorsed by the Cap project. We reference the "Cap" name only to indicate which software this plugin works with.

= Where do I get a Cap endpoint, site key and secret? =

You provision those in your self-hosted Cap server. See the Cap documentation at https://trycap.dev/.

= Where is the WASM loaded from? =

By default: from the copy bundled inside this plugin at `wp-content/plugins/privacy-captcha-for-cap/assets/wasm/cap_wasm_bg.wasm`. You can optionally switch to your own Cap server's `/assets/cap_wasm_bg.wasm` endpoint under **Settings → Privacy CAPTCHA for Cap → Privacy**. Either way the file is served from your own infrastructure — no third-party CDN is contacted.

= Why is a `.wasm` file bundled, and where does it come from? =

The bundled `assets/wasm/cap_wasm_bg.wasm` is the WebAssembly module the Cap widget uses to run the proof-of-work challenge in the visitor's browser. Bundling it locally is the privacy-friendly default: it means no third-party CDN (jsdelivr) is contacted at page load, so visitor IPs are never shared (DSGVO/GDPR-clean).

It is the unmodified upstream file from the `@cap.js/wasm` npm package (Apache-2.0), part of the open-source Cap project. The plugin does not alter it. Its source and build live in the Cap repository at https://github.com/tiagozip/cap, and the vendoring step is reproducible via `scripts/build-assets.mjs` (`bun run build`), which copies the file verbatim and `bun run build:check` verifies it matches upstream.

= Can I store the secret outside the database? =

Yes. Define `CAP_CAPTCHA_SECRET_KEY` in `wp-config.php` and the plugin will use it instead of the value saved in `wp_options`.

= What is "fail-open" mode? =

When enabled, the plugin lets submissions through if the Cap server is unreachable. Off by default — only turn it on if temporary outages must not block legitimate users (logins, checkouts).

= Does the bundled `cap-widget` script make any external requests? =

No third-party CDN requests. All widget assets are bundled and served from this plugin, including the `pako` decompression library (`assets/js/vendor/pako_inflate.min.js`), which older browsers without the native `DecompressionStream` API load locally instead of from jsdelivr. The only network requests the widget makes are to your own Cap endpoint to fetch and verify challenges.

= Can I turn protection on or off per form in code? =

Yes. Every surface passes through the `cap_captcha_protect` filter — `($enabled, $context)` returning a boolean — before the widget renders and before a submission is verified. For example, to skip the CAPTCHA for logged-in users everywhere: `add_filter('cap_captcha_protect', fn($on, $ctx) => is_user_logged_in() ? false : $on, 10, 2);`. There is also a per-surface filter, e.g. `cap_captcha_protect_woocommerce_login`. Context ids: gravity_forms, contact_form_7, comments, login, registration, woocommerce_checkout, woocommerce_login, woocommerce_registration, woocommerce_lost_password.

= Can I keep the CAPTCHA off a specific Contact Form 7 or Gravity Forms form? =

Yes — useful for legally required or accessibility-sensitive forms. For **Contact Form 7**, set the mode to *Manual* (Settings → Form placement) and add the `[cap_captcha]` tag only to the forms you want protected; or, in *Automatic* mode, add `cap_captcha: off` to a form's Additional Settings to skip just that one. For **Gravity Forms**, each form has a *Privacy CAPTCHA* setting (Default / Always / Never) so you can exclude individual forms even when "protect all" is on.

== Changelog ==

= 1.1.0 =
* Added: Contact Form 7 integration with placement control — automatic on all forms, or manual via the [cap_captcha] tag (so you can keep the CAPTCHA off legally required or accessibility-sensitive forms).
* Added: Gravity Forms automatic protection — global "protect all" plus a per-form Default / Always / Never override, alongside the existing field.
* Added: WooCommerce My Account login, registration, and lost-password forms (each its own toggle, alongside checkout).
* Added: dashboard widget showing your Cap server stats at a glance.
* Added: granular per-surface toggles and a `cap_captcha_protect` developer filter to control protection on any form, even conditionally.
* Fixed: the Login integration no longer interferes with WooCommerce My Account logins.

= 1.0.0 =
* Initial release.
* Gravity Forms field (inline / floating / programmatic display).
* Comments, login, registration, WooCommerce integrations.
* Fully self-hosted assets — WASM module, cap-widget script, and pako library all bundled and served locally; no third-party CDN is contacted at runtime. DSGVO-clean by default.
* WP 6.5 native script-module loading.
* Top-level Settings → Privacy CAPTCHA for Cap page with integration toggles, WASM source choice (this plugin or your own Cap server), fail-open switch.
* German translations.
