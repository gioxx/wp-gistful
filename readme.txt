=== Gistful for GitHub ===
Contributors: gioxx
Tags: gist, github, embed, code, syntax highlighting
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Embed GitHub Gists with a block, the [gist] shortcode or a pasted URL. A modern, drop-in replacement for GistPress.

== Description ==

Gistful for GitHub embeds GitHub Gists in your posts and pages. It is a drop-in replacement for GistPress: the same `[gist]` shortcode and pasted gist URLs keep working, so you can switch without editing your content.

Features:

* Block editor block, `[gist]` shortcode and pasted gist URLs (oEmbed-style) all supported.
* Local CSS, no browser requests to GitHub. Gists are fetched and cached by your server; visitors' browsers never contact GitHub because of this plugin.
* Dark mode: follows the visitor's system preference automatically, or force light or dark in the settings.
* Line numbers, line ranges and line highlighting, file selection for multi-file gists, and Markdown, notebook and CSV files handled sensibly.
* Migration tool (Tools > Gistful Migration) to convert GistPress content, with a backup so changes can be reverted.
* Security hardening: gist HTML is sanitized with a strict allow-list, requests go only to GitHub over verified TLS with short timeouts and size limits, and the optional API token is read from a constant and never stored in the database or printed.
* WP-CLI cache command and clean uninstall.

Credits: based on GistPress by Brady Vercher and Gary Jones (https://github.com/bradyvercher/gistpress).

== Installation ==

1. Upload the `gistful` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen.
2. Activate it through the Plugins screen.
3. Add the Gist block, use `[gist]https://gist.github.com/user/id[/gist]`, or paste a gist URL on its own line.
4. Optional: adjust Settings > Gistful.

== Frequently Asked Questions ==

= How do I migrate from GistPress? =

Deactivate GistPress, activate Gistful, then open Tools > Gistful Migration. Existing `[gist]` shortcodes and pasted URLs already render without changes; the tool converts GistPress content to the block if you want it, and keeps a backup.

= How does dark mode work? =

By default the color scheme follows the visitor's system preference. In Settings > Gistful you can force light or dark. Themes can override the CSS custom properties on `.gistful`.

= How do I disable the plugin's CSS? =

Untick the option to load the stylesheet in Settings > Gistful, then style the `.gistful` markup from your theme.

= Can I use a GitHub token to raise API rate limits? =

Yes. Define the constant in `wp-config.php`:

`define( 'GISTFUL_GITHUB_TOKEN', 'your-token' );`

The token is only read from this constant. It is never saved in the database and never printed. A token with no scopes is enough for public gists.

= Why do some files show a link instead? =

Very large files, or gists that GitHub returns truncated, are not expanded beyond the configured limit. A link to the file on GitHub is shown instead.

== Screenshots ==

1. A gist embedded in a post, in light mode.
2. The same gist in dark mode.
3. The Gistful settings screen.

== Changelog ==

= 0.1.0 =
* Initial release: block, shortcode and URL embeds, local CSS, dark mode, GistPress migration tool, security hardening, WP-CLI cache command.

== Upgrade Notice ==

= 0.1.0 =
Initial release.

== Privacy ==

Your server contacts `gist.github.com` and `api.github.com` to fetch the gists you embed; no personal data of your visitors is sent, and the request carries a User-Agent with the plugin version and your site URL. The results are cached in your database. The plugin's own CSS and scripts are served from your site and make no browser requests to external domains. Markdown gists may contain images, and those images are served by GitHub, so a visitor's browser will request them from GitHub's servers. The plugin does not set cookies or track visitors.

== Source code ==

The full, unminified source, including the block source under `src/`, is available at https://github.com/gioxx/wp-gistful. The compiled block in `build/` is produced with `npm ci && npm run build` (`@wordpress/scripts`).

== Credits ==

Based on GistPress by Brady Vercher and Gary Jones: https://github.com/bradyvercher/gistpress
