# Changelog

## 0.1.0 - 2026-11-08

Initial release.

- Gist embeds via a block, the `[gist]` shortcode and pasted URLs; drop-in replacement for GistPress.
- Server-side fetching with caching; local CSS, with no browser requests to GitHub from the plugin's own assets (Markdown gist images are served by GitHub).
- Dark mode (auto, light or dark override), line numbers, ranges and highlighting, multi-file gists.
- Markdown and CSV render inline; notebooks, maps and 3D models get a link to GitHub.
- GistPress migration tool with backup.
- Security hardening: strict HTML sanitizing, GitHub-only hosts, size and time limits, token from `GISTFUL_GITHUB_TOKEN` only.
- WP-CLI cache command and clean uninstall.
- WordPress.org packaging and Plugin Check in CI.
