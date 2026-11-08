# Changelog

## Unreleased

- Fix: GitHub occasionally serves a gist without syntax highlighting, and that copy was cached for the full TTL (and could replace the highlighted backup), so code showed without colours until the cache was cleared. Such a response (even a one-line snippet) is now treated like the API fallback: cached for at most one hour, never stored over a highlighted backup of the same code, and replaced by that backup when the post has one. Copies are compared by their code, so an edited gist always shows its current code and updates the backup.
- Plugins screen: "View on GitHub" (when the row does not already link to the repository) and "More plugins by Gioxx" links under the plugin description.

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
