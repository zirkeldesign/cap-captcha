# Changelog

All notable changes to this project will be documented in this file.

## [1.1.0] - Unreleased

### Added
- **Contact Form 7** integration: injects the widget via `wpcf7_form_elements` and rejects unverified submissions through the `wpcf7_spam` gate.
- **CF7 placement control**: a `[cap_captcha]` form-tag for manual placement and a mode setting — **Automatic** (all forms, with per-form opt-out via the `cap_captcha: off` Additional Setting) or **Manual** (only forms carrying the tag). Verification is scoped to protected forms, so legally required / accessibility-sensitive forms left without the CAPTCHA are never blocked.
- **GF placement control**: a global "protect all Gravity Forms" setting and a per-form **Default / Always / Never** override in the form settings. Auto-protected forms get a synthetic `cap_captcha` field injected at runtime (via `gform_pre_render` / `gform_pre_validation` / `gform_pre_submission_filter`) so render and validation reuse the existing field path.
- **WooCommerce My Account** surfaces: login (`woocommerce_login`), registration (`woocommerce_registration`), and lost password (`woocommerce_lost_password`), each an independent toggle alongside checkout (`woocommerce_checkout`). A WooCommerce **master toggle** (`woocommerce`) gates all four — surfaces are picked in the master card's options disclosure in settings. The legacy `woocommerce` value maps to master-on + `woocommerce_checkout`.
- **Dashboard widget** (`Admin\DashboardWidget`) reusing the extracted `Status\StatusPanel` to show cached Cap server stats with a link to settings.
- **Per-surface protection model**: `Settings::isProtected($context)` gates every render and verification, exposed via the `cap_captcha_protect` and `cap_captcha_protect_{context}` filters. Granular settings toggles for all nine surfaces.

### Changed
- Integrations now register unconditionally when available and gate each render/verify on `isProtected()`, so the per-surface toggles and filters control behaviour at request time.
- `TokenVerifier` memoises verification per token within a request, preventing a single-use token from being redeemed twice when multiple hooks fire (e.g. WooCommerce login).
- Extracted the status panel rendering out of `Settings` into `Status\StatusPanel`.

### Fixed
- The Login integration no longer runs on WooCommerce My Account logins (detected via the `woocommerce-login` nonce), which previously could block them; the `woocommerce_login` surface owns that form.

### Migration
- The legacy single `woocommerce` toggle maps to `woocommerce_checkout` automatically.

## [1.0.0] - Unreleased

### Added
- Initial release as **Privacy CAPTCHA for Cap** (renamed from "Cap CAPTCHA for Gravity Forms").
- Top-level **Settings → Privacy CAPTCHA for Cap** page with per-integration toggles.
- **Comments** integration (`comment_form_after_fields` + `preprocess_comment`).
- **Login** integration (`login_form` + `wp_authenticate_user`).
- **Registration** integration (`register_form` + `registration_errors`).
- **WooCommerce checkout** integration (auto-detected, only loads when WC is active).
- **Programmatic display mode** — auto-solves the challenge in the background, no user interaction.
- **WASM source** setting (bundled / your own Cap server) with bundled as the default — both served from your own infrastructure.
- **Fail-open** behavior toggle for resilience during Cap-server outages.
- **`CAP_CAPTCHA_SECRET_KEY`** constant override so the secret can live in `wp-config.php`.
- Fully self-hosted front-end assets: bundled `@cap.js/wasm@0.0.7` plus the `pako` decompression library and the cap-widget script, all served from the plugin. The jsdelivr fallback URLs baked into the upstream widget bundle are stripped at build time and the plugin sets `window.CAP_PAKO_URL` / `window.CAP_CUSTOM_WASM_URL` to local copies, so no third-party CDN is contacted at runtime.
- German (`de_DE`) translations for the new settings strings.
- Architecture refactor: shared `Asset\Renderer`, `Asset\Enqueuer`, `Verification\TokenVerifier`, and an `Integration\Integration` contract.
