#!/usr/bin/env bash
set -euo pipefail

# bin/live-env/install.sh <wp-version>
#
# Installs a real WordPress + SportsPress + WooCommerce site and this
# repo's eight sportspress-* plugins into it, for the live-environment
# matrix CI job (.github/workflows/live-environment.yml). Also runnable
# by hand against any MySQL-backed host for local verification -- matching
# how scripts/release-guard.php and scripts/build-manifest.php are both CI
# steps and standalone CLI tools.
#
# Env (all optional, matching the matrix job's mysql service container):
#   WP_DB_HOST      (default 127.0.0.1)
#   WP_DB_NAME      (default wordpress)
#   WP_DB_USER      (default root)
#   WP_DB_PASSWORD  (default root)
#   WP_ROOT         (default ./wp)
#
# Usage: bin/live-env/install.sh <wp-version>
#   <wp-version>: a WordPress version string ("6.9", "7.0", "7.1"), or
#   "latest" to omit --version and let wp-cli install the newest release.

WP_VERSION="${1:?usage: install.sh <wp-version>}"
WP_ROOT="${WP_ROOT:-$(pwd)/wp}"
WP_DB_HOST="${WP_DB_HOST:-127.0.0.1}"
WP_DB_NAME="${WP_DB_NAME:-wordpress}"
WP_DB_USER="${WP_DB_USER:-root}"
WP_DB_PASSWORD="${WP_DB_PASSWORD:-root}"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

mkdir -p "$WP_ROOT"
cd "$WP_ROOT"

echo "== core download ($WP_VERSION) =="
if [ "$WP_VERSION" = "latest" ]; then
  wp core download --allow-root
else
  wp core download --version="$WP_VERSION" --allow-root
fi

echo "== config =="
wp config create \
  --dbname="$WP_DB_NAME" --dbuser="$WP_DB_USER" --dbpass="$WP_DB_PASSWORD" --dbhost="$WP_DB_HOST" \
  --allow-root

# The service container's own healthcheck gates job start, but wp-cli's first
# connection can still race the container's internal init on a cold start.
for i in $(seq 1 30); do
  wp db check --allow-root >/dev/null 2>&1 && break
  sleep 2
done

echo "== core install =="
wp core install \
  --url="http://localhost:8080" --title="Live Environment Matrix" \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.test \
  --skip-email --allow-root

echo "== SportsPress + WooCommerce (wordpress.org) =="
wp plugin install sportspress woocommerce --activate --allow-root

echo "== this repo's plugins, copied in (not symlinked, so build/ and every"
echo "   vendored asset comes along -- matches bulk-plugin-installer-for-wordpress) =="
for slug in sportspress-admin-tools sportspress-etransfer-automation sportspress-events-manager \
            sportspress-league-manager sportspress-player-registration sportspress-player-tools \
            sportspress-schedule-generator sportspress-score-sheets; do
  rsync -a --exclude=.git "$REPO_ROOT/$slug/" "wp-content/plugins/$slug/"
done

# Parent first, alone: every child's check_activation_requirements() does
# class_exists( 'SPAT_Plugin_Manager' ), which only resolves once the parent
# is active. Activating everything in one call races that check under
# WordPress's own undefined multi-plugin activation order.
wp plugin activate sportspress-admin-tools --allow-root
wp plugin activate \
  sportspress-etransfer-automation sportspress-events-manager sportspress-league-manager \
  sportspress-player-registration sportspress-player-tools sportspress-schedule-generator \
  sportspress-score-sheets \
  --allow-root

echo "== starting the site (wp-cli's PHP built-in server) =="
( wp server --host=0.0.0.0 --port=8080 --allow-root >/tmp/wp-server.log 2>&1 & )
for i in $(seq 1 30); do
  curl -sf http://localhost:8080/ >/dev/null 2>&1 && break
  sleep 1
done
if ! curl -sf http://localhost:8080/ >/dev/null 2>&1; then
  echo "site did not come up; wp-server log:" >&2
  cat /tmp/wp-server.log >&2
  exit 1
fi

echo "== done: $WP_ROOT, serving at http://localhost:8080 =="
