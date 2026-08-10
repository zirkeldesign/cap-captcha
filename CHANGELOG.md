# Changelog

All notable changes to this project will be documented in this file.

## [1.3.0] - Unreleased

### Fixed
- **Multi-page Gravity Forms were unprotected or unsubmittable.** `gform_validation` fires on every page transition, but `cap-token` is a plain POST field written into the form by `<cap-widget>` — not a form value — and Gravity Forms re-renders the whole form (all pages, inactive ones hidden) on each transition, so the solved token never survives to the final submit. `Validator` demanded a proof on every page regardless. On forms with a preview step (e.g. the Gravity Wiz *GP Preview Submission* perk, which is a page break plus `{all_fields}` on the last page) the CAPTCHA could therefore never be satisfied: fail-closed made the form unsubmittable, fail-open made the CAPTCHA decorative. `Validator` is now page-aware — it requires the proof exactly when the page holding the field was submitted, or on the final submit — and `VerifiedState` carries an earlier page's verification across the rest of the submission via a short-lived transient keyed on GF's `gform_unique_id`.
- **Fail-open no longer waives a missing token.** `TokenVerifier::verifyToken('')` routed an empty token through `failOpen()`, so a site with fail-open enabled accepted *every* tokenless submission on *every* surface — the CAPTCHA became optional. Fail-open now means what `readme.txt` and the settings UI have always said it means: it applies only when the Cap server is genuinely unreachable. The not-configured short-circuit is unchanged.

### Added
- **Integration test suite** (`tests/Integration`, `composer test:integration`) running against a real WordPress + Gravity Forms via `GFFormDisplay::process_form()`, so the paging logic the validator mirrors is GF's own. Six of its twelve cases fail against the pre-fix code. The fixture is provisioned by `scripts/integration-env.sh` (SQLite, no MySQL, no Gravity Perks); Cap is faked through `pre_http_request` so the suite stays offline. Not wired into CI — Gravity Forms is commercial and would need its licence as a repository secret.
- `cap_captcha_require_token($required, $context)` filter — opt a surface out of the empty-token block for flows that legitimately submit without our widget.
- `cap_captcha_gf_field_page($page, $form)` filter — move the auto-injected Gravity Forms field off the last page (useful when that page can be hidden by conditional logic).
- `cap_captcha_gf_verified_ttl($seconds)` filter — how long a verification stays valid across the pages of one submission (default one hour).
- `cap_captcha_gf_skipped_hidden($formId, $fieldId)` action — fires when enforcement is skipped because conditional logic hid the CAPTCHA field.

### Changed
- The CAPTCHA now keeps a margin below itself on `wp-login.php` when something follows it, instead of sitting flush against the submit button.
- `gform_validation` is now registered with two arguments; `GFAPI::submit_form()` and validation-only API calls (`api-submit` / `api-validate`) are no longer gated, since no browser could have solved a challenge.
- Failing Gravity Forms validation now sets `failed_validation_page`, so the visitor lands on the page that actually holds the widget.
- The Login, Registration, and Comments integrations bail out on REST, XML-RPC, WP-CLI and cron requests, and on posts that lack the marker field of the form we render into (`wp-submit` / `comment_post_ID`). Without this the fail-closed change above would have broken XML-RPC logins, the REST comments endpoint, and third-party login forms.

## [1.2.2] - Unreleased

### Fixed
- An enabled surface no longer blocks submissions when the plugin is **not configured**. `TokenVerifier::verifyToken()` previously routed the not-configured case through `failOpen()` (fail-closed by default), so with e.g. the Login surface on but no endpoint/site key/secret set, logins failed with "CAPTCHA verification failed" while no widget was even rendered. It now returns early as a pass — protection stays off until configured.

## [1.2.1] - Unreleased

### Fixed
- WooCommerce checkout **account creation** no longer fails the CAPTCHA. `woocommerce_registration_errors` fires during checkout (via `wc_create_new_customer()`) as well as on the My Account register form, but the widget only renders on the latter; `verifyRegistration` now bails unless the register form's `woocommerce-register-nonce` is present (mirroring `verifyLostPassword`).
- The Login integration's WooCommerce-login skip guard checked a non-existent `woocommerce-login` field; corrected to `woocommerce-login-nonce`, so core Login verification no longer runs on WooCommerce My Account logins (which previously could block them when `woocommerce_login` was off but the Login surface was on).

## [1.2.0] - Unreleased

### Added
- **Per-surface fail-open.** Each surface can override the global fail-open with **Default / Fail-open / Fail-closed** (e.g. let logins through during a Cap outage but always require a valid proof on contact forms). New `cap_captcha_fail_open($open, $context)` filter.
- **`CapVerifier::check()`** returns `VerificationResult::{Verified,Rejected,Unreachable}`. Fail-open now applies **only** when Cap is unreachable (or there is no token) — an actively rejected token always blocks, even on a fail-open surface.
- **Fail-open annotations.** Submissions accepted during an outage are tagged `cap_captcha_fail_open = 1`: Gravity Forms entry meta, WooCommerce order meta + order note, comment meta, registration user meta. A `cap_captcha_fail_open_pass($context, $data)` action fires for custom handling.

### Changed
- `TokenVerifier` threads the surface `$context` through every integration and exposes `wasLastFailOpen()`.
- The Gravity Forms validator now defers an empty token to the surface's fail-open policy instead of always blocking.

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
