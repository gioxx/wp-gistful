#!/usr/bin/env bash
# Build the WordPress.org package: dist/gistful/ and dist/gistful.zip.
# The plugin has no runtime Composer dependencies, so vendor/ is never shipped.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

npm ci
npm run build

rm -rf dist
mkdir -p dist/gistful
rsync -a --exclude-from=.distignore ./ dist/gistful/

# Fail if anything dev-only slipped into the package.
forbidden=( src tests vendor node_modules docs bin .github .dev .superpowers dist
	composer.json composer.lock package.json package-lock.json jest.config.js
	phpcs.xml.dist phpunit.xml.dist README.md CHANGELOG.md .gitignore .git
	.phpunit.result.cache .DS_Store .distignore )
bad=0
for path in "${forbidden[@]}"; do
	if [ -e "dist/gistful/$path" ]; then
		echo "Forbidden path in package: $path" >&2
		bad=1
	fi
done
[ "$bad" -eq 0 ] || exit 1

for required in gistful.php uninstall.php readme.txt LICENSE includes assets/css/gistful.css assets/js/migration.js build/blocks/gist/block.json build/blocks/gist/index.js; do
	[ -e "dist/gistful/$required" ] || { echo "Missing from package: $required" >&2; exit 1; }
done

(cd dist && rm -f gistful.zip && zip -qr gistful.zip gistful)
echo "Built dist/gistful.zip"
