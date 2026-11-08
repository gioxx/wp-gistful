# Gistful for GitHub

Embed GitHub Gists in WordPress. A modern replacement for GistPress.

Based on GistPress by Brady Vercher and Gary Jones. Licensed GPL-2.0-or-later.

## Features

- Block, `[gist]` shortcode and pasted URLs; drop-in replacement for GistPress.
- Server-side fetch and cache; local CSS, no browser requests to GitHub.
- Dark mode (auto or forced), line numbers, ranges, highlighting, multi-file gists.
- Markdown, notebook and CSV gists.
- GistPress migration tool, WP-CLI cache command, hardened HTML sanitizing.
- Optional API token via the `GISTFUL_GITHUB_TOKEN` constant (never stored).

## Requirements

PHP 8.1+, WordPress 6.5+.

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

## Credits

Based on [GistPress](https://github.com/bradyvercher/gistpress) by Brady Vercher and Gary Jones.
