# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repository is

The WordPress site for **Oops, Mine Co.** (a womenswear boutique, Chicwish-inspired design). The repo root *is* the
WordPress root and *is* the live document root — WordPress 7.1 core, the paid parent theme **Moderno** (`wp-content/
themes/moderno`, never edit), WooCommerce and the other plugins are all committed. Everything custom lives in three
places:

| Path | Role |
|---|---|
| `wp-content/themes/moderno-child/` | All site-specific front-end code: the OMC page templates (Home, About), brand CSS/JS, helpers, SEO output. |
| `wp-content/plugins/whd/` | "WHD" marketing plugin: popups + email designs built in a drag-and-drop block editor, abandoned-cart recovery, newsletter list, tracking scripts. |
| `.ftp-sync/` | Dev/deploy tooling: an MCP server + CLI for FTPS media sync, DB refresh from live, and the site publish script. Never deployed (dot-folder, hard-blocked). |

Per-environment files are git-ignored and hand-maintained: `wp-config.php`, `.htaccess`, `wp-content/uploads/`.

## Environments

- **Local**: `http://oopsmine.test/` (Apache vhost; plain `localhost` serves a different project). PHP is
  `C:/xampp/php/php.exe` (8.2), MariaDB is `127.0.0.1:3307`, database `oopsmine` (root, no password).
  Apache runs as a Windows service — config changes need an elevated restart.
- **Live**: `https://oopsmineco.com/` on cPanel (user `onmartph`); the docroot `/home/onmartph/oopsmineco.com` is a git
  checkout of `main`, but there is **no shell** and cPanel's "Update from Remote" button is unusable (5 s timeout bug,
  and the tree is dirty from FTP deploys). Live has its own database (`onmartph_oopsmine` on `15.235.219.232`,
  remote-MySQL whitelisted per public IP — connection failures usually mean the IP changed).
- The local DB is a **copy** of live, not shared (it was shared once; that caused 15–20 s page loads and settings
  leaking to live). The local `wp-config.php` has an `OMC_DB=live` branch: `OMC_DB=live php <script>` runs local code
  against the **live** database with `WP_HOME=https://oopsmineco.com`. Web requests never take that branch.

## Commands

There is no build step, bundler or test suite. PHP and JS are plain files loaded as-is.

```bash
# lint
C:/xampp/php/php.exe -l path/to/file.php
node --check path/to/file.js

# WordPress from the CLI (WP-CLI is not installed) — bootstrap WordPress in a script:
#   $_SERVER['HTTP_HOST'] = 'oopsmine.test'; require 'wp-load.php';
#   wp_set_current_user( <admin id> );   // Elementor throws "Access denied" on option updates without a user
#   require ABSPATH . 'wp-admin/includes/template.php';  // if calling admin page callbacks (submit_button etc.)

# .ftp-sync tool (credentials in the git-ignored .ftp-sync/config.json; see .ftp-sync/README.md)
node .ftp-sync/server.mjs status                     # connection + pending media
node .ftp-sync/server.mjs pin                        # re-trust the FTPS cert (see gotchas)
node .ftp-sync/server.mjs sync [--dry-run]           # push local media (wp-content/uploads) to live
node .ftp-sync/server.mjs pull [--overwrite] [dir]   # fetch media / any folder from live (--include-tracked for code)
node .ftp-sync/server.mjs upload <paths> --force     # DEPLOY code: push git-tracked files to live
node .ftp-sync/server.mjs db-pull                    # replace local DB with a fresh copy of live (backs up first)
php .ftp-sync/publish-site.php                       # (re)apply pages/menu/theme settings/WHD activation to local DB
OMC_DB=live php .ftp-sync/publish-site.php           # …to the LIVE DB (production write — user runs/approves this)
```

The same tool is registered as the `ftp-sync` MCP server in `.mcp.json` (tools `ftp_sync`, `ftp_pull`, `ftp_upload`,
`ftp_download`, `ftp_list`, `ftp_delete`, `ftp_status`, `ftp_mark_synced`).

### Deploy flow

1. commit → `git push origin main` (GitHub is history; the server cannot pull it)
2. `node .ftp-sync/server.mjs upload wp-content/themes/moderno-child wp-content/plugins/whd --force` (code)
3. `OMC_DB=live php .ftp-sync/publish-site.php` if pages/menu/settings changed (idempotent; creates the `OOPS10` coupon)
4. media moves separately with `sync` / `pull`; `uploads/elementor/css/` is pulled but never pushed

### Verifying changes

Check pages with `curl` against `http://oopsmine.test/` and read `wp-content/debug.log` (WP_DEBUG_LOG is on locally).
Product images and theme assets are lazy-loaded, so full-page screenshots need a scroll pass first; a Playwright +
Chromium harness for that lives in the session scratchpad, not in the repo.

## Architecture

### Child theme (`moderno-child`)

- **Page templates** are registered by header comment: `templates/page-home.php` ("OMC Home", the front page) and
  `templates/page-about.php` ("OMC About"). `templates/page-header.php` overrides the parent's part so those two
  templates don't get the theme's title band (keeps one `<h1>` per page); every other page falls through to the parent.
- The parent prints its combined CSS as handle `ideapark-core` at `wp_enqueue_scripts` priority 999; the child enqueues
  `style.css` → `omc.css` at priority 1000 with that dependency. Parent layout classes to reuse:
  `l-section__container` (centred 1170px, fluid below) and `l-section__container-wide`. Theme settings are read with
  `ideapark_mod()`; the announcement bar is an `html_block` post referenced by the `header_advert_bar_page` mod; the
  header shows the site name as a text logo when the `logo` mod is empty.
- `functions.php` is the configuration surface. Section content is data, not markup: `omc_images()`,
  `omc_home_banners()` (`campaign`, `campaign2`, `carousel`, `wide`, `more`), `omc_home_categories()`,
  `omc_usp_items()`, `omc_feature_products()`, plus filters `omc_home_product_category`, `omc_home_feature_category`,
  `omc_home_type_words`, `omc_category_tile_image`, `omc_social_profiles`, `omc_front_description`. `omc_banner()`
  renders every banner variant (`split` with `side`, `wide`, `tile`; an `images` array makes it cross-fade).
  Images are referenced by upload-relative path and resolved via `_wp_attached_file` so the same code works on any
  environment.
- Product grids are the WooCommerce `[products]` shortcode so the parent's product cards, quick view and swatches keep
  working; grids are scoped to the Women category tree by default.
- SEO output (meta description, Open Graph, JSON-LD Organization/WebSite/AboutPage) is emitted only when no SEO plugin
  is active (`omc_has_seo_plugin()`).
- `assets/js/omc.js` (no dependencies): scroll reveal, the square edit carousel (native snap-scroll + autoplay), banner
  cross-fades, the typewriter heading, and the newsletter form (AJAX `omc_subscribe`, which fires
  `omc_newsletter_subscribed` for the plugin). Motion features respect `prefers-reduced-motion` and pause off-screen.
- Design tokens are CSS custom properties at the top of `assets/css/omc.css` (rose-gold `--omc-rose`, cream/sand,
  Cormorant Garamond display + Manrope body).

### WHD plugin

- One design format for popups and emails: `{ settings, blocks[] }` stored in the options `whd_popups` (ids
  `exit_intent`, `welcome`) and `whd_emails` (one per trigger). `WHD_Blocks` owns block definitions, sanitising and
  rendering — div markup for popups, table/inline-style HTML for emails — so the admin live preview is the real output.
- Emails take over WooCommerce via the `woocommerce_locate_template` filter (enabled designs point at
  `templates/wc-email.php`) and `woocommerce_email_subject_{id}`; disabled triggers keep WooCommerce defaults.
  `abandoned_cart` and `welcome_subscriber` are custom triggers sent with `wp_mail()`. Merge tags come from
  `WHD_Emails::context()`; `preview_context()` fabricates data when the store has no orders.
- Abandoned carts: table `{prefix}whd_carts`, guests captured by an inline checkout script → AJAX, cron
  `whd_cart_cron` (custom 15-min schedule), restore links `?whd_recover=<token>`, unsubscribe `?whd_unsub=`.
  Subscribers: table `{prefix}whd_subscribers`. Activation creates both tables and default designs.
- Admin: menu `WHD`; editor screen `admin.php?page=whd-editor&type=popup|email&id=<id>` (vanilla JS in
  `admin/editor.js`) talks to REST `whd/v1/design|render|test-email` (`manage_options`). Popups print in `wp_footer`
  at priority 5 — it must stay below 20 or their assets never enqueue. `?whd_preview=<popup id>` shows a popup to admins.

### Site content that is *not* in code

The front page, About page, Main Menu, announcement block, Customizer palette, plugin activation and demo popup/email
settings are database rows created by `.ftp-sync/publish-site.php`. If a fresh environment shows the demo homepage,
run that script (it is idempotent).

## Gotchas

- **Kaspersky on the dev PC intercepts TLS** and re-signs certificates, and rotates them. The FTP tool therefore pins the
  fingerprint it sees; a "does not match the pinned fingerprint" error with issuer still *Kaspersky* and subject
  `autoconfig.rs5-sgp.serverhostgroup.com` just needs `node .ftp-sync/server.mjs pin`. A different issuer/subject
  would be a real problem.
- `db-pull` **destroys local-only database content** (pages, settings) — it is for refreshing from live, not merging.
- The FTP tool refuses git-tracked files unless `--force` (uploading them dirties the server's git tree); uploads never
  touch `wp-config.php`, `.htaccess` or `.git/`.
- The parent theme's generated CSS (`uploads/moderno/`) and Elementor's (`uploads/elementor/css/`) embed the local
  hostname; never push them. Elementor records "CSS generated" in post meta, so after editing pages on live run
  `pull --overwrite` or local pages render unstyled.
- WordPress on live auto-updates themes/plugins, which modifies tracked files there; `DISALLOW_FILE_MODS` in the live
  `wp-config.php` prevents that.
- Large heredocs in the Bash tool get mangled (backslashes collapse, long ones truncate) — write files with the Write
  tool instead.
