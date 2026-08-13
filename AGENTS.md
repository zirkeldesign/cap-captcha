# Agent Guidelines

Commit conventions (gitmoji subject, English, no trailers) come from
`~/.claude/CLAUDE.md`. Stack conventions come from the `wp-plugin-dev` skill.
WordPress core release passes are the `wp-core-release` skill.

## Quality gate

```sh
composer format:test && composer phpstan && composer test
bun run build:check                  # vendored cap-widget/WASM up to date
composer pcp                         # Plugin Check on the BUILT zip, throwaway WP
WP_VERSION=7.1-RC3 composer pcp      # ... against a specific core
```

`composer test` must print a test count. Silence plus exit 0 means the suite
never ran.

The integration suite needs a Gravity Forms copy you hold a licence for and is
not wired into CI:

```sh
CAP_GF_SOURCE=/path/to/gravityforms composer test:integration:setup
composer test:integration
```

Point `CAP_WP_ROOT` and `CAP_WP_VERSION` elsewhere to provision a second fixture
on a different core without disturbing the cached `.wp-integration` one.

## Surfaces to smoke test

Static checks never execute the plugin. After any change to asset loading, and on
every WordPress core release, confirm the widget still renders on:

| Surface | Where | Note |
| --- | --- | --- |
| Login | `/wp-login.php` | **The fragile one.** `WP_Script_Modules::add_hooks()` does not run on wp-login, so `Asset/Enqueuer` prints the modules from `login_footer`. Grep the response for `type="module"` — a missing widget here is silent. |
| Registration | `/wp-login.php?action=register` | needs `users_can_register` |
| Comments | any single post | |
| WooCommerce | checkout, login, registration, lost password | needs WooCommerce active |
| Gravity Forms | field settings panel in the form editor | the integration suite covers submission flow |
| Contact Form 7 | a form with the automatic mode on | |
| Settings | Settings → Privacy CAPTCHA, incl. "Test connection" | reads the running config, not the form fields |

Minimum config that makes the widget render — `isConfigured()` requires all three:

```sh
wp option update cap_captcha_settings --format=json '{
  "endpoint_base":"https://cap.example.test","site_key":"abc123","secret_key":"deadbeef",
  "display_mode":"inline","wasm_source":"bundled",
  "integrations":{"login":true,"comments":true,"registration":true}}'
```

Seeding the wrong keys (`endpoint` rather than `endpoint_base`) yields a clean,
meaningless "no widget rendered".
