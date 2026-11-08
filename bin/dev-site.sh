#!/usr/bin/env bash
# Local dev site: php -S + WordPress core from vendor/ + SQLite.
# Usage: bin/dev-site.sh [port]   (default 8888). Login: admin / password.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${1:-8888}"
SITE="$ROOT/.dev/site"
CORE="$ROOT/vendor/johnpbloch/wordpress-core"
WP="$ROOT/vendor/bin/wp"
URL="http://localhost:$PORT"

[ -d "$CORE" ] || { echo "Run composer install first." >&2; exit 1; }

php "$ROOT/bin/prepare-dev-site.php" "$SITE" "$PORT"

wp() { "$WP" --path="$CORE" --allow-root "$@"; }

if ! wp core is-installed 2>/dev/null; then
	wp core install --url="$URL" --title="Gistful Dev" --admin_user=admin \
		--admin_password=password --admin_email=admin@example.org --skip-email
fi
wp plugin activate gistful >/dev/null 2>&1 || wp plugin activate gistful

echo "Dev site: $URL (wp-admin: $URL/wp-admin, admin / password)"
exec php -S "localhost:$PORT" -t "$CORE" "$SITE/router.php"
