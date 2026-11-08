# Changelog

## Unreleased

- Copy buttons: "Copy" on every file and "Copy all" on gists with more than one file. They copy exactly the lines shown (no line numbers), announce the result to screen readers and fall back to the legacy copy command on sites without HTTPS. A small deferred script, served from the site, loads only on pages that show a gist with the buttons; without JavaScript nothing changes. Opt out globally (Settings > Gistful), per block (Display panel) or with `copy="no"` on the shortcode.
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
