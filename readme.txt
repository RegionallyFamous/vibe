=== Vibe Check ===
Contributors: vibecheck
Tags: block, quiz, personality, gutenberg, share, claude, ai
Requires at least: 6.5
Tested up to: 6.8
Stable tag: 1.5.21
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A Gutenberg block for personality-style quizzes. Generate quizzes with **Anthropic Claude** (API key in settings), **Save image** for a server-rendered share JPEG, and Open Graph / Twitter meta for shared result URLs.

== Description ==

* **Structured authoring** — Define **three outcomes** on the block (ids, titles, descriptions, **result images** from the Media Library), then **Generate questions from outcomes** so Claude only writes questions and scoring—your result copy stays yours. Edit questions and answers on the **canvas** before publishing.
* **Claude generation** — In the sidebar, pick a mode: **questions from my outcomes** (recommended), **full quiz**, **questions only**, or **results only**. Optional **style presets** (personality, this-or-that, fandom). Your API key stays on the server.
* **Result screen & links** — Per outcome, optional **link URL** and **button label**; optional **auto-redirect** after a short countdown (with “Go now” and **Cancel**). Visitors can still share or save the result card first.
* **Share & save** — One-tap links for **X, Facebook, and Reddit**, plus **Save image** (same server-rendered JPEG as Open Graph previews). Share copy invites friends to take the quiz; filters let you tune invitation lines and hashtags.
* **Link previews** — `?quiz_result=` adds `og:title`, `og:description`, `og:image`, and Twitter tags for social crawlers. **Settings → Default share image** sets `og:image` for the quiz page URL before someone takes the quiz (no query string).

Replace the Contributors line with your WordPress.org username before submitting to the plugin directory.

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

= Where are Open Graph images served? =

`GET /wp-json/vibe-check/v1/og-image?post_id=…&result_id=…` returns a JPEG. When `?quiz_result=` is present on a singular post, meta tags include title, description, and image for crawlers.

**Default share image** (under **Settings → Vibe Check**) is used for the **same post URL without** `?quiz_result=` so Facebook, X, and others show a preview image when someone shares the quiz before taking it. After a result is shared, the generated result card image is used instead.

= Privacy and data =

* **Claude** — Prompts are sent from WordPress to Anthropic’s API **only on the server** when an editor uses “Generate”. Visitors never receive your API key.
* **Share images** — **Save image** downloads a JPEG from your site’s **Open Graph image** REST endpoint (generated on the server). Images are not uploaded to a third-party host by this plugin.
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

* **Result row** — Native `<a href>` links (no JavaScript required) open **X**, **Facebook**, and **Reddit** intents with copy that includes your result plus an invitation to take the quiz. **Save image** downloads the same JPEG used for Open Graph previews when the block is on a published post (`post_id` &gt; 0). Optional **hashtags** from `vibe_check_share_hashtags` are folded into the X post text (when they fit) and the Reddit title.
* **Instagram / TikTok** — There is no universal “post this URL” API; visitors can **Save image**, open the app, and paste the quiz link from the address bar (or use a link sticker / bio link as the platform allows).
* **Link previews** — Shared URLs with `?quiz_result=` include `og:image` (quiz JPEG), title, and description. A short **“Take the quiz…”** line is appended to the description for scroll-stopping snippets (filterable). The **default share image** from Settings applies to the plain quiz page URL (see FAQ).

**Filters**

* **`vibe_check_share_strings`** — (array) Override `invitationShort`, `invitationLong`, and/or `hashtags` passed to the front-end script (default strings are translated in PHP).
* **`vibe_check_share_hashtags`** — (string) Optional hashtag line appended to the long share caption (runs before `vibe_check_share_strings` merges `hashtags`).
* **`vibe_check_og_result_description`** — (string) Final Open Graph / Twitter description for valid `?quiz_result=` URLs, after the plugin appends its CTA. Args: `$og_desc`, `$post_id`, `$result_id`, `$ctx`.
* **`vibe_check_default_share_image_attachment_id`** — (int) Attachment ID from Settings (or override). Return `0` to skip default `og:image` tags.
* **`vibe_check_default_share_image_url`** — (string) Image URL for default share preview. Args: `$url`, `$attachment_id`, `$post_id` (context; `0` if not singular).

= Is the API key encrypted in the database? =

Keys saved under **Settings → Vibe Check** are stored as a normal WordPress option (not encrypted). For production, prefer defining `VIBE_CHECK_CLAUDE_API_KEY` in `wp-config.php` so the key is not in the database.

== Changelog ==

= 1.5.21 =
* **Front end** — Removed **More sharing options** (html2canvas capture, Web Share fallback, and modal copy panel). Sharing stays on the result row: native intent links plus **Save image** (OG JPEG). Removed the `vibe_check` CustomEvent hook that only fired from the old panel/share flows.
* **Front end** — Result share row shows **X, Facebook, and Reddit** only (plus **Save image**). Hashtags from `vibe_check_share_hashtags` are applied to the X text and Reddit title.

= 1.5.20 =
* **Settings** — **Default share image**: pick an image from the Media Library for Open Graph / Twitter previews when the **quiz page is shared without** `?quiz_result=` (before someone finishes the quiz). Result links still use the generated result JPEG. The block outputs `data-vibe-check-default-share-image` with the image URL for optional front-end use. Filters: `vibe_check_default_share_image_attachment_id`, `vibe_check_default_share_image_url`.

= 1.5.19 =
* **Social sharing** — Result row and “More sharing options” intents use **inline SVG icons** (with `aria-label`) instead of text labels; **Save image** uses a download icon.
* **Reliability** — Share intent URLs use **length-capped** copy so WhatsApp, Threads, Bluesky, email, and similar apps are not given overlong query strings; Telegram uses a short `text` plus `url` (no duplicate mega-caption).

= 1.5.18 =
* **Social sharing** — Invitation-focused share copy (short line for X, longer caption with link for other apps). Result footer adds **LinkedIn, Reddit, Telegram, Threads, Bluesky, Email** as native links alongside X, Facebook, WhatsApp, and Save image.
* **More sharing options** — **Copy for Instagram / TikTok** (caption + optional hashtags); Bluesky intent in the panel; refreshed IG/TikTok how-to text.
* **Link previews** — `og:description` / Twitter description append a **take the quiz** CTA; filter `vibe_check_og_result_description` for full control.
* **Developers** — `vibe_check_share_strings` and `vibe_check_share_hashtags` localize invitation copy and hashtags for the front end.

= 1.5.17 =
* **Performance** — OG JPEG endpoint caches generated images in a transient (filterable TTL and size cap); cache invalidates when post content or the quiz block’s save generation changes.
* **Privacy** — Removed third-party QR image from the share panel; hints point to Copy link / Save image instead.
* **Performance** — `preconnect` to Google Fonts hosts on singular posts that include the quiz block, and in the block editor when fonts load.
* **Reliability** — Optional filter `vibe_check_trust_proxy_headers` for OG rate-limit client IP behind trusted proxies (Cloudflare / load balancers).

= 1.5.16 =
* **Front end** — Share / Save: **document-level capture-phase** click handler (runs before bubble `stopPropagation` from themes) plus fresh `data-quiz` parse per click; **screen-reader announcements** when share/save cannot run (missing DOM, invalid quiz JSON, or no matching outcome); optional `console.warn` when `wp.env.DEBUG` is on.

= 1.5.15 =
* **Front end** — Share / Save: **string-normalized** outcome ids (`data-vibe-check-winner` vs JSON `quiz.results[].id`) so Share no longer no-ops when ids differ by type; direct **click** listeners on the Share and Save buttons (same pattern as Retake). **Deep link** `?quiz_result=` uses the same id comparison.

= 1.5.14 =
* **Front end** — Result card no longer stretches with a flex-grown inner column; outcome copy uses a fixed-height dashed panel (`--vibe-check-result-desc-height`, default clamp) with measured truncation; result area scrolls inside the quiz when needed. **Share / Save** — delegated click handler resolves text-node targets inside buttons; higher z-index and explicit pointer-events on share controls.

= 1.5.13 =
* **Front end** — Result description box shrink-wraps to the copy (fixes a tall empty band inside the dashed frame); tighter card padding and a slightly smaller result image on small screens; description fit clears inline height before measuring.

= 1.5.12 =
* **Front end** — Share / Save / Retake sit in `.vibe-check-result-card-footer` outside the overflow-clipped inner column; `?quiz_result=` deep links validate id shape/length; **45s timeout** on html2canvas capture to avoid a stuck “busy” state.
* **Security & reliability** — Anthropic: max outbound request size, max inbound body before JSON decode; API key / system addendum sanitization and length limits; filter `vibe_check_user_can_generate_quiz`. OG JPEG: max image bytes before GD load, `nosniff` on image responses.

= 1.5.11 =
* **Front end** — Locked quiz viewport height (no page scroll); compact result card; outcome description **fits the flex slot** (measured + truncated with ellipsis; full text on `title` when trimmed). Share/save handlers scoped to the result card; safer `ResizeObserver` + window resize fallback; higher z-index on share/save and Retake so controls stay clickable.
* **Claude** — System prompts include a filterable per-outcome description length target (`vibe_check_claude_result_description_soft_max_chars`, default 480 characters).

= 1.5.10 =
* **Front end** — Quiz column defaults to **full width** of the block container (`--vibe-check-inner-max-width`, default `100%`); result and calculating panels match. Responsive horizontal padding; wider result image and prose measure on large screens. Editor result preview uses full preview width.

= 1.5.9 =
* **Front end** — Per-answer **feedback** uses the same row height as the chosen answer, a short fade-out on the answer, then an animated swap to the chip style (surface → yellow chip + shadow). Read time scales with feedback length (minimum ~1.4s after the enter animation).

= 1.5.8 =
* **Front end** — Optional per-answer **feedback** now replaces the chosen answer row in place (chip-style) instead of a second toast below the question card.

= 1.5.7 =
* **Front end** — Shorter pause after choosing an answer when optional per-answer **feedback** is shown (toast hold reduced from 1.2s to 0.28s) so the quiz feels responsive.

= 1.5.6 =
* **Front end** — Default quiz styling aligned with Tier4-style neo-brutalist palette (paper/ink, yellow chip, mint accents): **Big Shoulders Display** + **Space Mono** via Google Fonts; primary CTAs and shadows tuned to match; optional `--vibe-check-stage-min-height` so intro/questions/result share a consistent minimum height; result image frame is **5:7** (card proportion) with rounded corners instead of a circle.
* **Result copy** — Long outcome descriptions split into a prominent **lead sentence** plus paragraph chunks (newlines respected; long prose grouped by sentence); improved line length, line-height, and panel styling for readability.
* **OG image** — JPEG palette updated to match the block (paper, ink, yellow chip); footer quiz line uses ink for contrast.
* **Share capture** — Capture background and font stack updated for the new theme.

= 1.5.5 =
* **Front end** — Quiz UI aligned with the updated design: cream grid shell, Playfair / DM Sans / JetBrains Mono (Google Fonts when the block renders), intro card with meta chips, SVG progress ring + step dots, answer rows with letter tiles, feedback toast, “calculating” interstitial, result card with color bar, circular result image, optional tagline chip, confetti on reveal (respects reduced motion).
* **Block** — Optional **quiz subtitle** (intro) and optional **tagline** per outcome (result chip); subtitle and tagline in sanitized `data-quiz` JSON.
* **Editor** — Fields for subtitle and per-outcome tagline.
* **PHP** — `tagline` preserved when merging regenerated results from the base block (with CTAs / image).

= 1.5.4 =
* **Front end** — Default accent is teal (replacing red/pink); intro card with kicker line; calmer per-answer feedback (“Note” label, chip/ink styling). Tighter spacing and type scale across intro, questions, result card, and share UI. OG JPEG accent color matches block CSS.

= 1.5.3 =
* **Claude JSON** — More robust parsing when the model adds prose, multiple markdown code fences, a UTF-8 BOM, or trailing commas: try each fence, decode whole text, then extract the first balanced JSON object. Fewer “could not parse” failures.
* **Tests** — PHPUnit coverage for JSON extraction (`tests/ClaudeParseTest.php`).

= 1.5.2 =
* **Generation** — If Claude returns a different number of answers per question, the plugin now normalizes counts (trim, then pad with neutral “Another option” rows) before validation instead of failing with “All questions must have the same number of answers.”

= 1.5.1 =
* **Anthropic HTTP** — Avoid cURL error 28 on slow first-byte responses: disable WordPress’s low-speed transfer abort for `api.anthropic.com` only. Request timeout default 120s; filter `vibe_check_claude_request_timeout`.
* **Editor** — During generation: rotating status messages (sidebar) and a canvas banner so authors know what’s happening while Claude runs.

= 1.5.0 =
* **Generation bounds** — Configurable quiz shape via `vibe_check_quiz_generation_config` (default: 3–6 outcomes, 3–5 answers per question, 3–5 questions). Claude prompts and REST validation use the same limits.
* **System prompt** — Optional **Settings → System prompt addendum** appended after schema rules; filter `vibe_check_claude_system_prompt` for themes or mu-plugins.
* **API cache** — Short-lived transient cache for identical model requests (`vibe_check_claude_response_cache_ttl`, default 5 minutes). Rate limit applies to uncached API calls only.
* **Result descriptions** — Default max length raised to 1200 characters; filter `vibe_check_max_result_description_length`.
* **OG images** — Attachment images for OG cards load via local file when possible, with a URL fallback when the file is missing (e.g. offloaded media).
* **Editor** — 3–6 outcomes with add/remove; **Fandom character template** for placeholder outcome structure; clearer validation errors for model shape.
* **Settings** — Prominent notice that API keys in the database are stored in plain text unless using `wp-config.php`.

= 1.4.0 =
* **Result images** — Each outcome uses a **Media Library** image (Block Editor `MediaUpload` / `core` data store) instead of emoji. Sanitized `imageId` + server-side `imageUrl` / `imageAlt` in `data-quiz` for the front end.
* **Share text** — Share caption no longer includes an emoji (two-part sprintf: result title + quiz title).
* **Claude prompts** — JSON schemas and merge behavior updated; author-only fields (`imageId`, CTAs) preserved when regenerating questions.
* **Deep link** — `?quiz_result=` opens the matching result screen without taking the quiz (same param as OG share URLs).
* **Share UX** — “Creating image…” while capturing; adaptive jpeg scale when Save Data / low CPU; dated download filenames; redirect hint before auto-redirect; share panel: Instagram tip, QR code, Threads intent; `vibe_check` CustomEvent analytics (`action`: `share` | `save`, plus `method`).
* **OG image** — When an outcome has a Media Library image, the REST OG JPEG composites it into the 1200×630 card.
* **Editor** — After Generate, scroll to the first question and brief highlight.
* **A11y** — Reduced-motion: result stagger delays zeroed; capture state uses `aria-busy`.


= 1.3.1 =
* Readme: rate-limit FAQ (successful API responses only), structured-authoring / CTA / redirect description, API key storage FAQ.
* Editor: clearer REST error messages by stage (`questions`, `results`, `sanitize`) and rate limit.
* Front end: `wp-i18n` strings for view script; focus moves to result heading, then Retake returns focus to Start; auto-redirect adds **Cancel redirect** and a cancelled message.
* Dev: PHPUnit + Composer (`composer test`) with stubs for quiz sanitization and CTA merge tests.

= 1.3.0 =
* **Structured authoring** — Define three outcomes on the block (ids, titles, descriptions, result images); **Generate questions from outcomes** sends them to Claude as authoritative; questions and scoring merge without overwriting your result copy. Canvas editor for outcomes and question/answer text; sidebar defaults to this flow with full / partial modes available.
* **Security & reliability** — Size limits on REST `existing` payloads and Claude JSON parsing; `data-quiz` length guard on server and front end; hardened outbound Anthropic HTTP (no redirects, SSL verify, unsafe URLs rejected); generation rate limit counts only after a successful API response; optional `vibe_check_claude_max_tokens` filter.
* **Performance** — Transient cache for parsed quiz block attributes (OG image and share meta) keyed by post content hash.

= 1.2.0 =
* Open Graph / Twitter: `og:title`, `og:description`, `og:url`, `twitter:title`, `twitter:description`, `twitter:image` for shared result links.
* Editor: generation modes (full / questions only / results only), style presets, clearer validation errors.
* Front end: stepped progress, optional per-answer feedback lines, “Last time” on intro via sessionStorage, improved download filenames, lazy-loaded html2canvas, clipboard copy fallback.
* Theme hooks: document `--vibe-check-*` CSS variables for overrides.
* Readme: privacy and rate-limit notes.

= 1.1.0 =
* Claude quiz generation (Settings → Anthropic API key; block sidebar “Generate with Claude”).

= 1.0.0 =
* Initial release: Vibe Check block, html2canvas share/save, optional OG images and `?quiz_result=` meta, sanitized quiz payload, REST rate limiting, uninstall cleanup.

== Upgrade Notice ==

= 1.5.21 =
The **More sharing options** button and in-browser capture panel are removed; the result row still has one-tap share links and **Save image** (server JPEG).

= 1.5.20 =
Optional **Default share image** in Settings for link previews when the quiz page URL is shared before a result is chosen.

= 1.5.19 =
Share row shows network icons; share links are built with safer URL lengths so intents open reliably. Copy-caption actions are unchanged.

= 1.5.18 =
Wider social sharing: more one-tap networks on the result card, invitation-style share copy, Instagram/TikTok caption copy with optional hashtags, and a stronger link-preview description. New filters for share strings and OG description.

= 1.5.17 =
OG JPEG responses are cached server-side (with filters); the share panel no longer loads a third-party QR image; optional proxy-aware rate limiting via a filter; Google Fonts preconnect on quiz pages.

= 1.5.16 =
Share and Save use a capture-phase listener so theme scripts are less likely to block clicks; failed actions announce a short message instead of doing nothing.

= 1.5.15 =
Share and Save Image work reliably when outcome ids are numbers in JSON; buttons use direct listeners instead of bubbling-only delegation.

= 1.5.14 =
Fixed-height outcome text slot and more reliable Share / Save clicks; less empty space in the result layout.

= 1.5.13 =
Tighter result layout: the outcome description area fits the text instead of leaving a large empty band below it.

= 1.5.12 =
Hardening for generation and OG images; more reliable share/save controls and capture timeout on the front end.

= 1.5.11 =
Tighter fixed-height quiz layout, measured result descriptions, and more reliable Share / Save Image controls.

= 1.5.10 =
Quiz UI uses the full content width by default (override with `--vibe-check-inner-max-width` if you want a narrow column).

= 1.5.9 =
Richer feedback animation and longer, length-based read time before the next step.

= 1.5.8 =
Per-answer feedback appears in the answer slot you picked.

= 1.5.7 =
Snappier answer flow when feedback toasts are enabled.

= 1.5.6 =
Typography and colors refresh (Tier4-style defaults), taller consistent quiz stage, card-shaped result images, and easier-to-read result descriptions.

= 1.5.5 =
Major front-end quiz refresh: new typography and layout, calculating step before results, and optional subtitle/tagline fields in the block.

= 1.5.4 =
Refined quiz appearance: new default colors, tighter layout, and improved intro and answer-note styling.

= 1.5.3 =
More reliable parsing of Claude responses when JSON is wrapped in extra text or minor formatting issues.

= 1.5.2 =
More tolerant quiz JSON when the model returns mismatched answer counts per question.

= 1.5.1 =
More reliable Claude requests on slow networks and clearer in-editor progress while generating.

= 1.5.0 =
Configurable generation limits, system prompt addendum and filter, response caching, longer result descriptions, OG image load fallback, 3–6 outcomes and fandom template in the editor, and clearer API key storage notice.

= 1.3.1 =
Documentation updates, translated front-end strings, redirect cancel, accessibility focus fixes, and PHPUnit tests.

= 1.3.0 =
Structured quiz authoring on the block, Claude `from_outcomes` mode, security/reliability hardening, and OG parse caching.

= 1.2.0 =
Adds OG text meta, editor generation modes, UX polish, and privacy documentation.
