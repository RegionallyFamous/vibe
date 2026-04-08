=== Vibe Check ===
Contributors: regionallyfamous
Tags: block, quiz, personality, gutenberg, share, claude, ai
Requires at least: 6.5
Tested up to: 6.8
Stable tag: 1.0.7
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A Gutenberg block for personality-style quizzes. Generate quizzes with **Anthropic Claude** (API key in settings), share results on **X, Facebook, and Reddit**, and get Open Graph / Twitter meta (including a server-rendered preview JPEG) for shared result URLs.

== Description ==

* **Structured authoring** — Define **three outcomes** on the block (ids, titles, descriptions, **result images** from the Media Library), then **Generate questions from outcomes** so Claude only writes questions and scoring—your result copy stays yours. Edit questions and answers on the **canvas** before publishing.
* **Claude generation** — In the sidebar, pick a mode: **questions from my outcomes** (recommended), **full quiz**, **questions only**, or **results only**. Optional **style presets** (personality, this-or-that, fandom). Your API key stays on the server.
* **Result screen & links** — Per outcome, optional **link URL** and **button label**; optional **auto-redirect** after a short countdown (with “Go now” and **Cancel**). Visitors can still use the share buttons first.
* **Share** — One-tap links for **X, Facebook, and Reddit**. Share copy invites friends to take the quiz; filters let you tune invitation lines and hashtags.
* **Link previews** — `?quiz_result=` adds `og:title`, `og:description`, `og:image`, and Twitter tags for social crawlers. **Settings → Default share image** sets `og:image` for the quiz page URL before someone takes the quiz (no query string).

Contributors uses the WordPress.org–style slug `regionallyfamous`; change it if you submit to the plugin directory under a different username.

== Installation ==

1. Upload the `vibe-check` folder to `/wp-content/plugins/`, or install the zip from Plugins → Add New → Upload Plugin.
2. Activate **Vibe Check** through the **Plugins** menu.
3. Run `npm install && npm run build` in the plugin directory if you use the source repository (the `build/` folder must exist).
4. **Settings → Vibe Check** — paste your [Anthropic API key](https://console.anthropic.com/) (or define `VIBE_CHECK_CLAUDE_API_KEY` in `wp-config.php`). Optionally choose a **Default share image** so link previews show a branded image when the quiz page is shared before anyone selects a result.
5. Add the **Vibe Check** block and use **Generate** in the sidebar.

== Frequently Asked Questions ==

= Where do I put my API key? =

**Settings → Vibe Check** in WordPress admin, or add this to `wp-config.php` (above “That’s all”):

`define( 'VIBE_CHECK_CLAUDE_API_KEY', 'sk-ant-api03-...' );`

Never commit keys to git. The key is only used in PHP; never sent to site visitors.

= Does this work without Node / npm? =

You need the compiled assets in `build/` (from `npm run build`). Ship a release zip that already includes `build/`.

= How do updates from GitHub work? =

The plugin checks the [RegionallyFamous/vibe](https://github.com/RegionallyFamous/vibe) **Releases** API (no token needed for this public repo). When a newer semver tag exists and the release includes a **.zip** asset (e.g. `vibe-check.zip`), **Dashboard → Updates** can install it. For a **private** fork or mirror, define a GitHub personal access token in `wp-config.php` as `GITHUB_UPDATER_TOKEN` before the plugin loads.

**If you don’t see an update:** use **Dashboard → Updates → Check again** (WordPress caches update checks for several hours). The GitHub object must be a **published Release** (not only a lightweight tag). The plugin folder should be `wp-content/plugins/vibe-check/` with main file `vibe-check.php` so WordPress matches the update to the right plugin. **Settings → Vibe Check → Clear update caches** (if you can update plugins) wipes WordPress’s plugin update transient and the GitHub release cache, then use **Check again** on Updates. The settings screen also shows your **installed plugin basename** (e.g. `vibe-check/vibe-check.php`) so you can confirm it matches a normal install. Developers can define `VIBE_CHECK_UPDATER_DEBUG` as `true` in `wp-config.php` together with `WP_DEBUG_LOG` to log failed GitHub API requests to `debug.log`.

= Where are Open Graph images served? =

`GET /wp-json/vibe-check/v1/og-image?post_id=…&result_id=…` returns a JPEG. When `?quiz_result=` is present on a singular post, meta tags include title, description, and image for crawlers.

**Default share image** (under **Settings → Vibe Check**) is used for the **same post URL without** `?quiz_result=` so Facebook, X, and others show a preview image when someone shares the quiz before taking it. After a result is shared, the generated result card image is used instead.

= Privacy and data =

* **Claude** — Prompts are sent from WordPress to Anthropic’s API **only on the server** when an editor uses “Generate”. Visitors never receive your API key.
* **Link preview images** — Social crawlers fetch a server-generated JPEG from your site’s **Open Graph image** REST endpoint when someone shares a result URL. Images are not uploaded to a third-party host by this plugin.
* **Last result (optional)** — The front end may store the last quiz result title in **sessionStorage** so the intro can show “Last time: …”. It is not sent to the server.

= My result images look broken in the share preview =

If outcome images are hosted on another domain (CDN, separate media domain), the server may be unable to load them for the OG card unless files are readable from your WordPress host. Prefer storing images in the **Media Library** on the same installation, or ensure offloaded media remains accessible to PHP/GD.

= Rate limits =

* **Quiz generation** — Up to **20 successful** Anthropic API responses per WordPress user per rolling hour (transient-based). Failed network/API calls do not count; validation errors before calling Claude do not count.
* **OG image endpoint** — Up to **90** JPEG requests per IP per minute (abuse protection). Successful responses are cached in a transient (see below). Behind a reverse proxy, you can opt into reading the client IP from `CF-Connecting-IP` / `X-Forwarded-For` only if you enable the `vibe_check_trust_proxy_headers` filter (default: off, to avoid spoofing on misconfigured hosts).

= Developer filters (OG cache, rate limit IP) =

* **`vibe_check_og_jpeg_cache_ttl`** — (int) Seconds to store generated OG JPEG bytes in a transient. Default `DAY_IN_SECONDS`. Return `0` to disable caching.
* **`vibe_check_og_jpeg_cache_max_bytes`** — (int) Maximum JPEG size to cache (default 900000). Larger responses are not stored.
* **`vibe_check_trust_proxy_headers`** — (bool) When `true`, the OG rate limiter uses `HTTP_CF_CONNECTING_IP` or the first hop of `HTTP_X_FORWARDED_FOR` if valid; otherwise `REMOTE_ADDR`. Default `false`.

Cache keys include post ID, a hash of post content, `result_id`, and a generation counter bumped on `save_post` for posts that contain the quiz block, so edits invalidate cached images.

= Sharing & social =

* **Result row** — Native `<a href>` links (no JavaScript required) open **X**, **Facebook**, and **Reddit** intents with copy that includes your result plus an invitation to take the quiz. Optional **hashtags** from `vibe_check_share_hashtags` are folded into the X post text (when they fit) and the Reddit title.
* **Instagram / TikTok** — There is no universal “post this URL” API; visitors can take a screenshot, open the app, and paste the quiz link from the address bar (or use a link sticker / bio link as the platform allows).
* **Link previews** — Shared URLs with `?quiz_result=` include `og:image` (quiz JPEG), title, description, `og:site_name`, `og:locale`, and image alt text (`og:image:alt` / `twitter:image:alt`). A short **“Take the quiz…”** line is appended to the description for scroll-stopping snippets (filterable). The **default share image** from Settings applies to the plain quiz page URL; the plugin can also output matching OG/Twitter **text** tags for that URL when no SEO plugin already does (see filters).

**Filters**

* **`vibe_check_share_strings`** — (array) Override `invitationShort`, `invitationLong`, and/or `hashtags` passed to the front-end script (default strings are translated in PHP).
* **`vibe_check_share_hashtags`** — (string) Optional hashtag line appended to the long share caption (runs before `vibe_check_share_strings` merges `hashtags`).
* **`vibe_check_og_result_description`** — (string) Final Open Graph / Twitter description for valid `?quiz_result=` URLs, after the plugin appends its CTA. Args: `$og_desc`, `$post_id`, `$result_id`, `$ctx`.
* **`vibe_check_og_result_image_alt`** — (string) Alt text for the generated result `og:image` / `twitter:image`. Args: `$img_alt`, `$post_id`, `$result_id`, `$ctx`.
* **`vibe_check_quiz_landing_open_graph_text`** — (bool) When `true` (default), print `og:title`, `og:description`, `og:url`, `og:type`, `og:site_name`, `og:locale`, and matching Twitter title/description on the quiz landing URL (no `?quiz_result=`). Set `false` if your SEO plugin already outputs those to avoid duplicates. Args: `$print`, `$post`.
* **`vibe_check_quiz_landing_og_title`** — (string) OG/Twitter title for the quiz landing URL. Args: `$title`, `$post`.
* **`vibe_check_quiz_landing_og_description`** — (string) Plain-text OG/Twitter description for the quiz landing (excerpt or trimmed content before truncation). Args: `$description`, `$post`.
* **`vibe_check_default_share_image_attachment_id`** — (int) Attachment ID from Settings (or override). Return `0` to skip default `og:image` tags.
* **`vibe_check_default_share_image_url`** — (string) Image URL for default share preview. Args: `$url`, `$attachment_id`, `$post_id` (context; `0` if not singular).

= Is the API key encrypted in the database? =

Keys saved under **Settings → Vibe Check** are stored as a normal WordPress option (not encrypted). For production, prefer defining `VIBE_CHECK_CLAUDE_API_KEY` in `wp-config.php` so the key is not in the database.

== Changelog ==

= 1.0.7 =
* **GitHub updater (critical)** — Use the correct **directory `slug`** in update metadata (e.g. `vibe-check`), matching WordPress core expectations; `plugin` stays the full basename (e.g. `vibe-check/vibe-check.php`).
* **GitHub updater** — Also hook **`site_transient_update_plugins`** so updates merge when the transient is **read**, not only when `pre_set_site_transient_update_plugins` runs (fixes many “host never shows update” cases).
* **GitHub updater** — Populate **`no_update`** when the site is already current (WordPress 5.5+ auto-update UI).
* **Debugging** — Optional `VIBE_CHECK_UPDATER_DEBUG` + `WP_DEBUG_LOG` logs GitHub HTTP/API failures.

= 1.0.6 =
* **Metadata** — Plugin author **Regionally Famous** with **Author URI** https://github.com/RegionallyFamous/vibe. Readme Contributors slug and package/composer author fields aligned.

= 1.0.5 =
* **GitHub updates** — Match the installed plugin by **real path** (any `…/vibe-check.php` in `update_plugins->checked` that resolves to this plugin), so renamed install folders still receive update offers.
* **GitHub updates** — **Settings → Vibe Check → Clear update caches** (for users who can update plugins) wipes WordPress’s plugin update transient and the GitHub release JSON cache; the screen shows your **installed plugin basename** (e.g. `vibe-check/vibe-check.php`).
* **Developer** — `VIBE_CHECK_GITHUB_OWNER` / `VIBE_CHECK_GITHUB_REPO` constants; `GitHub_Plugin_Updater::release_transient_key()` and `clear_static_memo()` for cache control.
* **Tests** — PHPUnit coverage for the GitHub updater (mocked HTTP); optional live API check; `scripts/verify-github-release.php` and `npm run verify:github-release`.

= 1.0.4 =
* **Performance** — Transient cache for the **sanitized** quiz derived from post content (`vibe_check_get_sanitized_quiz_from_post`), so `?quiz_result=` meta and the OG JPEG endpoint avoid re-running the full sanitizer on every request after the first.
* **OG image REST** — `Content-Length` and `X-Content-Type-Options: nosniff` on JPEG responses for clearer client/CDN behavior.
* **GitHub updater** — Reject release API responses larger than 2 MiB before JSON decode (abuse / error guard).
* **Uninstall** — Remove `ghu_` updater transients in addition to `vibe_check_` transients.
* **Housekeeping** — Fix indentation in Claude settings registration.

= 1.0.3 =
* **GitHub updater** — Fix owner/repo sanitization: normalize case **before** stripping characters so mixed-case org names (e.g. `RegionallyFamous`) resolve to the correct API path. Allow **api.github.com** zipball URLs when a release has no `.zip` asset (fallback package URL).

= 1.0.2 =
* **GitHub updater** — Sanitize owner/repo for API URLs; only accept **HTTPS** package links on GitHub-controlled hosts (blocks rogue zip URLs from API JSON). Stricter HTTP client defaults; bounded JSON decode depth; capped changelog body and tag length; PAT length cap; skip plugin details modal when no trusted zip.
* **Performance** — Same-request memo so update checks don’t call the GitHub API twice; single `init` pass for view-script translations + share strings; prime attachment **postmeta** when enriching result images (fewer queries).

= 1.0.1 =
* **Updates** — GitHub Releases integration (`github-updater.php`): **Dashboard → Updates** can install new versions from [RegionallyFamous/vibe](https://github.com/RegionallyFamous/vibe) when a release includes a `.zip` asset. Public repo needs no token; optional `GITHUB_UPDATER_TOKEN` in `wp-config.php` for private mirrors.
* **Share** — Removed front-end **Save image** download control; tightened quiz JSON validation on the client; **X** icon uses a reliable stroke mark; **Facebook** share uses `sharer.php` (fixes broken shares from `sharer/sharer.php` on many hosts).
* **Housekeeping** — `Plugin URI` points at GitHub; readme FAQ for GitHub updates; release zip includes the updater file.

= 1.0.0 =
* First stable release: **Vibe Check** quiz block with structured outcomes, **Anthropic Claude** generation (server-side REST API; API key in Settings or `wp-config.php`).
* **Share & previews** — Native share links (X, Facebook, Reddit), Open Graph / Twitter meta for `?quiz_result=` (server-rendered preview JPEG), optional **default share image** in Settings for the plain quiz URL.
* **Safety & limits** — Sanitized quiz payload, size limits on REST and `data-quiz`, generation and OG JPEG rate limiting, uninstall option cleanup.

== Upgrade Notice ==

= 1.0.7 =
Fixes GitHub-based update detection (correct slug + read-path hook). Clear update caches once under Settings → Vibe Check if needed.

= 1.0.6 =
Author and contributor metadata point to Regionally Famous / GitHub; no functional changes.

= 1.0.5 =
Clear stale update caches from Settings if Dashboard → Updates misses a release; better matching when the plugin folder was renamed.

= 1.0.4 =
Faster OG/quiz meta paths, tighter GitHub updater response handling, and cleaner uninstall.

= 1.0.3 =
Critical fix for GitHub-based updates when the repository owner name contains uppercase letters.

= 1.0.2 =
Hardened GitHub updater and small performance improvements for updates and quiz media.

= 1.0.1 =
GitHub-based updates, share row tweaks (no Save image), and Facebook share URL fix.

= 1.0.0 =
First stable release of Vibe Check.
