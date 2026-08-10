#!/usr/bin/env bash
#
# Provisions the WordPress install the Integration suite runs against.
#
# SQLite-backed, so it needs no MySQL — only wp-cli, PHP with pdo_sqlite, and
# network access for the WordPress core download. The install is cached in
# .wp-integration so repeat runs are instant; pass --fresh to rebuild it.
#
# Gravity Forms is commercial and is NOT downloaded. Point CAP_GF_SOURCE at a
# gravityforms plugin directory or zip you already have a licence for, e.g.
#
#   CAP_GF_SOURCE=/path/to/wp-content/plugins/gravityforms \
#     ./scripts/integration-env.sh
#
# The preview-step form is a plain two-page Gravity Form (page break + an HTML
# field holding {all_fields}) — exactly the shape the Gravity Wiz "GP Preview
# Submission" perk produces, so we reproduce the reported bug without needing
# the commercial perk.

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_DIR="${CAP_WP_ROOT:-$PLUGIN_DIR/.wp-integration}"
WP_VERSION="${CAP_WP_VERSION:-latest}"
SLUG="privacy-captcha-for-cap"

if [[ "${1:-}" == "--fresh" ]]; then
    echo "› Removing $WP_DIR"
    rm -rf "$WP_DIR"
fi

if [[ -f "$WP_DIR/wp-load.php" ]]; then
    echo "✅ WordPress already provisioned at $WP_DIR (use --fresh to rebuild)"
else
    echo "› Downloading WordPress $WP_VERSION"
    wp core download --path="$WP_DIR" --version="$WP_VERSION" --force --quiet

    echo "› Installing the SQLite drop-in"
    curl -sL https://downloads.wordpress.org/plugin/sqlite-database-integration.zip -o "$WP_DIR/sqlite.zip"
    unzip -q -o "$WP_DIR/sqlite.zip" -d "$WP_DIR/wp-content/plugins/"
    rm "$WP_DIR/sqlite.zip"
    cp "$WP_DIR/wp-content/plugins/sqlite-database-integration/db.copy" "$WP_DIR/wp-content/db.php"
    php -r '
        [$f, $d] = [$argv[1], $argv[2]];
        $c = file_get_contents($f);
        $c = str_replace("{SQLITE_IMPLEMENTATION_FOLDER_PATH}", $d."/wp-content/plugins/sqlite-database-integration", $c);
        $c = str_replace("{SQLITE_PLUGIN}", "sqlite-database-integration/load.php", $c);
        file_put_contents($f, $c);
    ' "$WP_DIR/wp-content/db.php" "$WP_DIR"

    echo "› Configuring and installing"
    wp config create --path="$WP_DIR" --dbname=wp --dbuser=root --dbpass='' --skip-check --force --quiet
    wp core install --path="$WP_DIR" \
        --url=http://localhost:8899 --title="Cap CAPTCHA Integration" \
        --admin_user=admin --admin_password=admin --admin_email=admin@example.test \
        --skip-email --quiet
fi

echo "› Linking $SLUG"
rm -rf "$WP_DIR/wp-content/plugins/$SLUG"
ln -s "$PLUGIN_DIR" "$WP_DIR/wp-content/plugins/$SLUG"

if [[ -n "${CAP_GF_SOURCE:-}" ]]; then
    echo "› Installing Gravity Forms from $CAP_GF_SOURCE"
    rm -rf "$WP_DIR/wp-content/plugins/gravityforms"
    if [[ -d "$CAP_GF_SOURCE" ]]; then
        cp -R "$CAP_GF_SOURCE" "$WP_DIR/wp-content/plugins/gravityforms"
    else
        unzip -q -o "$CAP_GF_SOURCE" -d "$WP_DIR/wp-content/plugins/"
    fi
fi

if [[ -d "$WP_DIR/wp-content/plugins/gravityforms" ]]; then
    wp plugin activate gravityforms --path="$WP_DIR" --quiet
    echo "✅ Gravity Forms $(wp plugin get gravityforms --field=version --path="$WP_DIR") active"
else
    echo "⚠️  Gravity Forms not installed — GF integration tests will be skipped."
    echo "    Set CAP_GF_SOURCE to a gravityforms directory or zip and re-run."
fi

wp plugin activate "$SLUG" --path="$WP_DIR" --quiet

# The Gravity Forms integration decides whether to register its hooks at load
# time, so protection has to be on in the database before the test process
# boots WordPress. Everything a test needs to vary (fail-open, Cap responses,
# token requirement) is filterable at validation time instead.
echo "› Configuring the plugin"
wp option update cap_captcha_settings --path="$WP_DIR" --format=json --quiet <<'JSON'
{
    "endpoint_base": "http://127.0.0.1:8899/cap/",
    "site_key": "integration-site-key",
    "secret_key": "integration-secret-key",
    "display_mode": "inline",
    "wasm_source": "bundled",
    "fail_open": false,
    "show_admin_notices": false,
    "integrations": { "gravity_forms": true },
    "gf_protect_all": false,
    "fail_open_overrides": []
}
JSON

echo "✅ Ready. Run: CAP_WP_ROOT=$WP_DIR composer test:integration"
