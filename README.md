# Gistful for GitHub

Embed GitHub Gists in WordPress. A modern replacement for GistPress.

Based on GistPress by Brady Vercher and Gary Jones. Licensed GPL-3.0-or-later.

## Features

- Block, `[gist]` shortcode and pasted URLs; drop-in replacement for GistPress.
- Server-side fetch and cache; local CSS and a small local script only for the copy buttons, so the plugin's own assets make no browser requests to GitHub (images inside Markdown gists are served by GitHub).
- "Copy" button per code and CSV file and "Copy all" on multi-file gists (only the lines shown; Markdown, shown rendered, has none); opt out in the settings, per block or with `copy="no"`.
- Dark mode (auto or forced), line numbers, ranges, highlighting, multi-file gists.
- Markdown and CSV render inline; files GitHub shows with special viewers (notebooks, maps, 3D models) get a link to GitHub.
- GistPress migration tool, WP-CLI cache command, hardened HTML sanitizing.
- Optional API token via the `GISTFUL_GITHUB_TOKEN` constant (never stored).

## Requirements

PHP 8.1+, WordPress 6.5+.

## Installation

Download the latest release zip and upload it from Plugins → Add New → Upload Plugin, or build it yourself with `bin/package.sh`. If GistPress is active, Gistful takes over its `[gist]` shortcode and pasted URLs; Tools → Gistful Migration imports its backups and cleans up its data.

More plugins by the same author: [wordpress.gioxx.org](https://wordpress.gioxx.org/).

## Development

Everything is project-local: no Docker or MySQL needed. Tests run on SQLite.

```bash
composer install
composer test                       # PHPUnit (WP test suite on SQLite)
composer test -- --filter test_name # run a subset
composer lint                       # PHPCS (WordPress standards)
composer serve                      # dev site on http://localhost:8888 (admin / password)
bin/dev-site.sh 9000                # same, custom port
npm ci                              # JS dependencies
npm run build                       # compile the block into build/
npm run test:unit                   # Jest
bin/package.sh                      # build dist/gistful.zip (WordPress.org package)
```

`npm run test:php` delegates to `composer test`. The dev site and test database live under the git-ignored `.dev/` directory; WordPress core is in `vendor/johnpbloch/wordpress-core`.

## Releasing

Bump `Version` in `gistful.php`, `Stable tag` in `readme.txt` and the version in `package.json`, and add a `## X.Y.Z - YYYY-MM-DD` section to `CHANGELOG.md`. When the change to `gistful.php` reaches `main`, the Release workflow builds the package with `bin/package.sh` and publishes a GitHub release `vX.Y.Z` with `gistful-X.Y.Z.zip` and that changelog section. A version that already has a tag is skipped.

## Credits

Based on [GistPress](https://github.com/bradyvercher/gistpress) by Brady Vercher and Gary Jones.

## TODO

- WordPress.org screenshots: add `== Screenshots ==` captions to `readme.txt` together with `screenshot-N.png` files in the SVN `/assets` folder (light mode, dark mode, settings screen).
