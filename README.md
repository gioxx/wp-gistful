# Gistful for GitHub

Embed GitHub Gists in WordPress. A modern replacement for GistPress.

Based on GistPress by Brady Vercher and Gary Jones. Licensed GPL-2.0-or-later.

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
```

`npm run test:php` delegates to `composer test`. The dev site and test database live under the git-ignored `.dev/` directory; WordPress core is in `vendor/johnpbloch/wordpress-core`.
