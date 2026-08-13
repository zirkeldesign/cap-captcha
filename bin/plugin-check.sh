#!/usr/bin/env bash
#
# Run the real WordPress.org Plugin Check against the BUILT zip, using a
# throwaway WordPress install backed by SQLite (no MySQL, no existing site).
#
# Usage: composer pcp                      # builds the zip, then checks it
#        bin/plugin-check.sh               # same, run directly
#        WP_VERSION=7.1-RC3 composer pcp   # check against a specific core
#
# Requires: wp-cli, php with pdo_sqlite, curl, unzip, network access.

set -euo pipefail

SLUG="privacy-captcha-for-cap"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PLUGIN_DIR"

VERSION="$(php get-version.php)"
ZIP="$PLUGIN_DIR/dist/${SLUG}-${VERSION}.zip"

# WP version to test against. Override with WP_VERSION to try a release
# candidate before readme.txt claims compatibility with it.
if [ -z "${WP_VERSION:-}" ]; then
  WP_VERSION="$(grep -i '^Tested up to:' readme.txt | sed 's/.*: *//' | tr -d '[:space:]' || true)"
fi
[ -z "${WP_VERSION:-}" ] && WP_VERSION="latest"

echo "🔨 Building distribution archive..."
rm -f "$ZIP"   # wp dist-archive refuses to overwrite an existing file
composer dist >/dev/null

echo "🌐 Spinning up throwaway WordPress ${WP_VERSION} (SQLite) ..."
WPDIR="$(mktemp -d /tmp/pcp-wp.XXXXXX)"
cleanup() { rm -rf "$WPDIR" /tmp/pcp-sqlite.$$.zip /tmp/pcp-pc.$$.zip; }
trap cleanup EXIT

wp core download --path="$WPDIR" --version="$WP_VERSION" --force >/dev/null 2>&1

# SQLite drop-in (no MySQL needed)
curl -sL https://downloads.wordpress.org/plugin/sqlite-database-integration.zip -o "/tmp/pcp-sqlite.$$.zip"
unzip -q -o "/tmp/pcp-sqlite.$$.zip" -d "$WPDIR/wp-content/plugins/"
cp "$WPDIR/wp-content/plugins/sqlite-database-integration/db.copy" "$WPDIR/wp-content/db.php"
php -r '$f=$argv[1];$d=$argv[2];$c=file_get_contents($f);
  $c=str_replace("{SQLITE_IMPLEMENTATION_FOLDER_PATH}",$d."/wp-content/plugins/sqlite-database-integration",$c);
  $c=str_replace("{SQLITE_PLUGIN}","sqlite-database-integration/load.php",$c);
  file_put_contents($f,$c);' "$WPDIR/wp-content/db.php" "$WPDIR"

wp config create --path="$WPDIR" --dbname=wp --dbuser=root --dbpass='' --skip-check --force >/dev/null
wp core install --path="$WPDIR" --url=http://localhost --title=PCP \
  --admin_user=admin --admin_password=admin --admin_email=a@b.test --skip-email >/dev/null

# Plugin-under-test from the BUILT zip + the Plugin Check plugin
unzip -q -o "$ZIP" -d "$WPDIR/wp-content/plugins/"
curl -sL https://downloads.wordpress.org/plugin/plugin-check.zip -o "/tmp/pcp-pc.$$.zip"
unzip -q -o "/tmp/pcp-pc.$$.zip" -d "$WPDIR/wp-content/plugins/"
wp plugin activate plugin-check --path="$WPDIR" >/dev/null 2>&1

echo "🔎 Running Plugin Check on ${SLUG} ${VERSION} against WordPress ${WP_VERSION} ..."
RESULT="$(wp plugin check "$SLUG" --path="$WPDIR" --format=json 2>/dev/null || true)"

# A clean result has no matches; grep returning non-zero must not trip `set -e`.
set +e
ERRORS="$(grep -o '"type":"ERROR"' <<<"$RESULT" | wc -l | tr -d '[:space:]')"
WARNINGS="$(grep -o '"type":"WARNING"' <<<"$RESULT" | wc -l | tr -d '[:space:]')"
set -e

if [ "$ERRORS" != "0" ] || [ "$WARNINGS" != "0" ]; then
  echo "❌ Plugin Check: ${ERRORS} error(s), ${WARNINGS} warning(s)"
  wp plugin check "$SLUG" --path="$WPDIR" 2>/dev/null || true
  exit 1
fi

echo "✅ Plugin Check passed: 0 errors, 0 warnings"
