# Changelog

## 0.1.0 - Unreleased

Initial release.

- Gist embeds via a block, the `[gist]` shortcode and pasted URLs; drop-in replacement for GistPress.
- Server-side fetching with caching; local CSS, no browser requests to GitHub.
- Dark mode (auto, light or dark override), line numbers, ranges and highlighting, multi-file gists.
- Markdown, notebook and CSV gist support.
- GistPress migration tool with backup.
- Security hardening: strict HTML sanitizing, GitHub-only hosts, size and time limits, token from `GISTFUL_GITHUB_TOKEN` only.
- WP-CLI cache command and clean uninstall.
- WordPress.org packaging and Plugin Check in CI.
