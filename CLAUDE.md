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
| `wp-content/plugins/whd-variations/` | "WHD — Variation Tiers": multi-level product variations (Colour → Size → …) built from one grid on the product screen, and one-level-at-a-time picking on the product page. |
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
php .ftp-sync/import-videos.php [--dry-run]          # style videos: media-library attachments + one page each
OMC_DB=live php .ftp-sync/import-videos.php          # …to the LIVE DB (production write — user runs/approves this)
```

The same tool is registered as the `ftp-sync` MCP server in `.mcp.json` (tools `ftp_sync`, `ftp_pull`, `ftp_upload`,
`ftp_download`, `ftp_list`, `ftp_delete`, `ftp_status`, `ftp_mark_synced`).

### Deploy flow

1. commit → `git push origin main` (GitHub is history; the server cannot pull it)
2. `node .ftp-sync/server.mjs upload wp-content/themes/moderno-child wp-content/plugins/whd wp-content/plugins/whd-variations --force` (code)
3. `OMC_DB=live php .ftp-sync/publish-site.php` if pages/menu/settings changed (idempotent; creates the `OOPS10` coupon)
4. media moves separately with `sync` / `pull`; `uploads/elementor/css/` is pulled but never pushed
5. `OMC_DB=live php .ftp-sync/import-videos.php` after a `sync`, if the video pages or their media changed

Steps 3 and 5 write to the production database. The agent harness blocks them, so the site owner runs them; a
`--dry-run` first shows exactly which rows would change.

### Verifying changes

Check pages with `curl` against `http://oopsmine.test/` and read `wp-content/debug.log` (WP_DEBUG_LOG is on locally).
Product images and theme assets are lazy-loaded, so full-page screenshots need a scroll pass first; a Playwright +
Chromium harness for that lives in the session scratchpad, not in the repo.

## Architecture

### Child theme (`moderno-child`)

- **Page templates** are registered by header comment: `templates/page-home.php` ("OMC Home", the front page),
  `templates/page-about.php` ("OMC About"), `page-landing.php`, `page-faq.php`, `page-contact.php`,
  `page-policy.php` (the keyword/content pages) and `page-video.php` / `page-videos.php` (style videos).
  `templates/page-header.php` overrides the parent's part so those templates don't get the theme's title band
  (keeps one `<h1>` per page) — **add every new template's path to its `$omc_own_hero` list or the page ships
  two `<h1>`s**; every other page falls through to the parent.
- `footer.php` overrides the parent's footer on every page. The parent renders an Elementor "footer page" there, which
  needs Elementor's frontend CSS (not loaded on the OMC templates) and carried the demo's fake contact details. The
  child footer is plain markup: brand, Shop/Help link columns from `omc_footer_links()` (filter `omc_footer_links`;
  only existing categories/published pages are linked), newsletter form (hidden on the front page, which already ends
  with the newsletter band — filter `omc_footer_newsletter`), copyright. It keeps the parent's `</main>`,
  `.c-footer` classes and `wp_footer()`.
- The parent prints its combined CSS as handle `ideapark-core` at `wp_enqueue_scripts` priority 999; the child enqueues
  `style.css` → `omc.css` at priority 1000 with that dependency. Parent layout classes to reuse:
  `l-section__container` (centred 1170px, fluid below) and `l-section__container-wide`. Theme settings are read with
  `ideapark_mod()`; the announcement bar is an `html_block` post referenced by the `header_advert_bar_page` mod; the
  logo comes from `omc_logo( $variant )` / `omc_logo_img()`: the Customizer logo when set, otherwise the brand PNGs
  bundled in `assets/img/` — `rose` (theme rose-gold; header, footer, schema), `cream` (hero), plus the client's
  `black`/`white` originals. The tinted files are generated from the white master by
  `.ftp-sync/tools/make-logo-variants.php` (re-run it if the palette changes). Rendered by the child's
  `templates/header-logo.php` / `header-logo-mobile.php` overrides, sized via the parent's `--logo-size*` and
  `--header-height-mobile` variables redefined on `.c-header` in omc.css. The same monogram is stamped as a
  semi-transparent **watermark** on every photo (hero, banners, mosaic, carousel, live card, category/product/journal
  cards) with pure CSS pseudo-elements — see the "Watermark" block in omc.css: bottom-right by default, bottom-left
  where copy sits on the right, top-right on carousel slides; rose tint on white product shots; hidden on product-card
  hover so the add-to-cart bar is clean. Per-host `--omc-wm`, `--omc-wm-inset`, `--omc-wm-opacity`.
- `functions.php` is the configuration surface. Section content is data, not markup: `omc_images()`,
  `omc_home_banners()` (`campaign`, `campaign2`, `carousel`, `wide`, `wide2`, `wide3`; `more` tiles and
  `omc_feature_products()` are kept but no longer rendered — the editorial, editor's picks and tile-row sections were
  removed from the home template), `omc_home_categories()`, `omc_usp_items()`, `omc_home_mosaic()` (the seven-tile
  "Shop the look" grid under the Live card — sizes 2x2/1x1/1x2/2x1 on a dense 4-column grid, square tiles set the
  row height; optional `focus` = object-position), `omc_hero_phrases()` (rotating hero line), `omc_testimonials()` (approved 4–5★ WooCommerce reviews first, padded with curated quotes; filters
  `omc_testimonials_fallback` / `omc_testimonials`; rendered after the journal as a centred one-at-a-time spotlight on
  a blurred, darkened photo band — large white quote, arrows + counter below, cross-fade autoplay — `.js-omc-quotes`
  in omc.js; the `image` each item carries is currently unused by the template), `omc_fb_live()` (the "next Facebook Live" card under the trust strip: photo, countdown and an email form
  that subscribes with source `fb_live` and then reveals the Facebook link — date, copy, page URL and on/off are theme
  mods `omc_live_*` edited in Customizer → "Facebook Live (home banner)"; the date defaults to next Saturday 8 pm
  site time until one is set), plus filters `omc_home_product_category`, `omc_home_feature_category`, `omc_home_type_words`,
  `omc_category_tile_image`, `omc_social_profiles`, `omc_front_description`, `omc_footer_links`, `omc_hero_images`
  (hero blur-dissolve slideshow; the first entry is the eager LCP image) and `omc_social_links` (Facebook, Instagram,
  email, Pinterest — URLs from the parent's Customizer "Social Media Links" + header email). On desktop those icons
  render in the logo row's top-left cell through the child's `templates/header-other.php` (the parent's "Other"
  header block is enabled there in `header_blocks_1`); on phones they are prepended to the announcement bar at
  `wp_head` priority 2 via the parent's `_advert_bar` temp mod and hidden ≥768px by CSS. `omc_banner()`
  renders every banner variant (`split` with `side`, `wide`, `tile`; an `images` array makes it cross-fade).
  Images are referenced by upload-relative path and resolved via `_wp_attached_file` so the same code works on any
  environment.
- Product grids are the WooCommerce `[products]` shortcode so the parent's product cards, quick view and swatches keep
  working; grids are scoped to the Women category tree by default.
- SEO output (meta description, Open Graph, JSON-LD Organization/WebSite/AboutPage) is emitted only when no SEO plugin
  is active (`omc_has_seo_plugin()`).
- `assets/js/omc.js` (no dependencies): scroll reveal, the square edit carousel (native snap-scroll + autoplay), banner
  cross-fades, the typewriter heading, and the newsletter forms (`omc_newsletter_form( $args )` — AJAX `omc_subscribe`
  with a `source` field, which fires `omc_newsletter_subscribed( $email, $source )` for the plugin; the WHD subscriber
  table records that source). In that handler read the URL with `getAttribute('action')`: the hidden
  `<input name="action">` shadows `form.action`. Motion features respect `prefers-reduced-motion` and pause off-screen.
- Design tokens are CSS custom properties at the top of `assets/css/omc.css` (rose-gold `--omc-rose`, cream/sand,
  Cormorant Garamond display + Manrope body).
- `assets/css/omc-pages.css` (enqueued after omc.css) restyles the **theme's own screens** — shop/category grids,
  product page, blog grid, cart/account/Elementor pages — to the home page's look: serif rose-gold page titles
  (centred, the demo's line-art category strip hidden), the sand product card everywhere (including related-product
  carousels), the journal card for blog posts, the 1170px container for the product page. It pairs with two
  Customizer values that publish-site.php sets: `product_grid_width = boxed` and `product_page_layout = layout-4`
  (the theme's only contained product layout). Grid columns: 4 desktop, 3 tablet (home page stays 4), 2 phones.

### Feature modules (`moderno-child/inc/`)

`functions.php` requires every name in one list near the top:

```php
foreach ( [ 'video', 'landing', 'pdp', 'cart', 'social' ] as $omc_module ) { ... }
```

Each file is self-contained and hooks itself, so adding a feature means dropping in `inc/<name>.php` and adding the
name to that array. Assets are enqueued either inside the module (landing, social — at `wp_enqueue_scripts` 1001) or
in `moderno_child_enqueue_styles()` (video, cart, pdp), always with the `omc` or `omc-pages` handle as a dependency.

| Module | What it owns |
|---|---|
| `video.php` | Style-video pages: `_omc_video` meta, player, chapters, `VideoObject` + `Clip` schema, the hub's `ItemList`. |
| `landing.php` | The four content templates: `_omc_landing` meta, section/table/FAQ renderers, `FAQPage` schema, the contact form endpoint, `omc-landing-page--{landing,faq,contact,policy}` body classes. |
| `pdp.php` | Product page: size-guide modal, scarcity line, trust badges, review photos. |
| `cart.php` | Slide-out cart drawer, free-shipping progress bar, drawer trust line. |
| `social.php` | Social/UGC band and the feed on the home and product pages. |

Two shared filters tie them together: `omc_meta_description` (a page supplies its own description to
`omc_seo_head()`) and `omc_social_links` / `omc_social_profiles` (footer icons and the Organization `sameAs`).

**The free-shipping bar makes a commercial promise.** `omc_free_shipping_rule()` has no fallback number on purpose:
it reads a real WooCommerce free-shipping zone first, then `whd_settings['free_shipping_threshold']` (0 means off).
The store currently has no shipping zones at all, so the bar stays hidden. Never reintroduce a default.

### Page content (`.ftp-sync/content/`)

Content is data, not markup, and is not in the database until the publish script runs.

- `plan.json` — which of the 460 researched keywords belong to which page, plus the deliberately excluded ones and
  the reason for each (pet clothing, game items, other retailers' names, children's sizing).
- `pages/<slug>.json` — one document per page: `title`, `seo_title`, `meta_description`, `keywords`, `hero`,
  `sections[]` (heading, paragraphs, bullets, optional `table` and `faqs`), `faqs[]`, `cta`, `related_slugs`,
  `author_notes`. `type` picks the template: landing → page-landing, faq → page-faq, contact → page-contact,
  policy/sizeguide → page-policy.
- `videos/<slug>.json` — the same idea for the video pages, imported by `.ftp-sync/import-videos.php`.

`publish-site.php` writes each document to `_omc_landing` (JSON) plus `_omc_meta_description` / `_omc_seo_title`, and
keeps a plain-HTML copy in `post_content` as the fallback when the meta is missing.

Rules the copy has to keep (checked deterministically, not by eye):

- every string in a page's `keywords` array appears **verbatim** in reader-visible copy;
- no entry from the banned-word list appears in reader-visible copy — the one exception is `however` inside the
  client's return policy, which is quoted, not rewritten;
- nothing is asserted that the store cannot back up: no delivery estimates, carriers, discounts, stock or review
  counts, named brands or people. The store has no shipping zones, so "shown at checkout" claims about delivery are
  false.

### Verifying the store pages

WooCommerce "Coming soon" mode is on (local and live), so shop/product/cart pages only render for logged-in users
with permission. The screenshot harness logs in as a temporary administrator that a CLI script creates and later
deletes. The WHD welcome popup opens 6 s after load — hide `.whd-popup` before long captures or its overlay shows up
as a dark block in screenshots. In Git Bash set `MSYS_NO_PATHCONV=1` before passing URL paths like `/shop/` to node
scripts, or they get rewritten to `C:/Program Files/Git/shop/`.

### WHD plugin

- One design format for popups and emails: `{ settings, blocks[] }` stored in the options `whd_popups` (ids
  `exit_intent`, `welcome`) and `whd_emails` (one per trigger). `WHD_Blocks` owns block definitions, sanitising and
  rendering — div markup for popups, table/inline-style HTML for emails — so the admin live preview is the real output.
- Emails take over WooCommerce via the `woocommerce_locate_template` filter (enabled designs point at
  `templates/wc-email.php`) and `woocommerce_email_subject_{id}`; disabled triggers keep WooCommerce defaults.
  `abandoned_cart` and `welcome_subscriber` are custom triggers sent with `wp_mail()`. Merge tags come from
  `WHD_Emails::context()`; `preview_context()` fabricates data when the store has no orders. Every customer
  email WooCommerce 11 sends is a trigger, including `customer_failed_order`, `customer_cancelled_order` and
  `customer_verify_email` (its one-time `{verify_url}` comes from the template vars) — those three ship with the
  WHD design off. A paid invoice takes WooCommerce's separate `customer_invoice_paid` subject filter. The editor's
  "Send test email" uses the real sender (WooCommerce's for WooCommerce triggers, `WHD_Emails::headers()` for the
  rest). A scratchpad test fires every trigger by its real event (order statuses, refund, note, invoice, account,
  reset, verify, a cart aged 50 h through three cron runs, a restock, the stylist, sign-ups, contact form, review)
  with mail and Klaviyo intercepted; all 20 send, and the six inputs reach Klaviyo as list or profile.
- Email platform (`WHD_Integrations`, option `whd_integrations`): Mailchimp / Klaviyo / webhook. Two paths, and the
  difference is consent — a sign-up (popup, newsletter form, stylist, checkout box ticked) goes through
  `WHD_Subscribers` and joins the list; everything else the shop learns (an order without the tick, an abandoned
  cart, a back-in-stock request, the contact form, a new account, a review) goes through `WHD_Contacts::hand_over()`
  as a Klaviyo *profile* with no list behind it. The setting `list_everyone` (WHD → Integrations → "Who joins the
  list", default off) sends all of them to the list instead. Live is on Klaviyo, list `RexmCz` ("OopsMineCo.Com
  Subscribers"), single opt-in. The account also has `SNvVK5` "Email List" (local points there for tests),
  `UAVPfy` "Preview List" and `WpmGL3` "Text Messaging List" (double opt-in). Live and local use **different
  private keys** — Klaviyo shows the key's last four as the consent `method_detail`: live `ea1e`, local `342c`.
  The subscription job answers 202 before doing anything and never reports a failed list add: on 2026-10-19 live
  sign-ups came out consent = SUBSCRIBED with no list while the same calls with the local key joined `RexmCz`.
  So `push_klaviyo()` now also adds the profile with `POST lists/{id}/relationships/profiles/`, which answers for
  itself (and is a no-op for members), and "Test connection" names the list, fails on a double opt-in one, and
  checks the key may write to it (an empty add: 401/403 = no permission, 400 = allowed, nobody added). The local
  key has no data-privacy scope, so test profiles cannot be deleted through the API — remove them from the list.
  Pushes run in the same request once the response has gone (`fastcgi_finish_request` / `litespeed_finish_request`),
  with a one-off cron event behind them as the retry: WP-Cron only fires on a later page view, and a coming-soon
  shop gets almost none, so a cron-only push sat in the queue for hours. Every sign-up pushes again — `synced_at`
  cannot tell whether the profile still exists at Klaviyo. API results *and* failed automation emails are in
  WHD → Integrations → "Last API calls".
- **Meta Pixel + Conversions API** (`WHD_Scripts` for the browser side, `WHD_Capi` for the server side; settings
  in `whd_scripts`: `fb_pixel_id` — live `4575941526022691` — `fb_capi_token`, `fb_test_code`). Every pixel event
  (PageView, ViewContent, ViewCategory, Search, AddToCart, InitiateCheckout, AddPaymentInfo, Purchase, Lead,
  CompleteRegistration) is also sent from the server with the **same event_id**, so Meta counts it once: page events
  print `fbq(…,{eventID})` and queue the server copy; redirect events (non-AJAX add to cart, registration, order
  placed) send the server copy at once and park the id for the next page; AJAX AddToCart returns its id in the
  cart fragments (`whd-capi-atc`, harmless as a selector); Lead ids come back in the sign-up answers (theme filter
  `omc_subscribe_response`, WHD popup answer) and pixel.js listens to both `omc:subscribed` and `whd:subscribed`.
  Purchase uses `order.<id>` and is also sent on payment/processing, once per order (`_whd_capi_purchase`), with
  the checkout's IP/UA and `_fbp`/`_fbc` kept on the order. One batched Graph call per request, after the response
  closes (`WHD_Plugin::after_response()`, which is shared with Klaviyo — only one place may close the connection).
  Admins are excluded like the pixel. Never send synthetic events without a Test events code that is *live in
  Events Manager*: Meta processes a non-matching code as a real event. The "Send a test event" button refuses to
  run without a code for that reason.
- **Automation emails must come from the shop's domain.** `WHD_Emails::sender()` uses the WHD sender only when it is
  on the site's domain, else WooCommerce's (`hello@oopsmineco.com`), and keeps the typed address as Reply-To. The
  sender used to default to the admin's Gmail address: mail claiming gmail.com sent from the host fails DMARC and
  Gmail rejects it outright, so the welcome email (the only place the sign-up code lives) never arrived.
- **The sign-up code is never shown on screen.** Every modal that offers a discount — the welcome popup, the exit
  popup, the theme's discount modal and the Facebook Live card — asks for a first name and an email and says to look
  in the inbox. `WHD_Subscribers::send_welcome()` sends it, and re-sends on a repeat sign-up whose source is in
  `CODE_SOURCES`, so that promise stays true for somebody who fills the form in twice. The code and its value come
  from the merge tags `welcome_coupon` / `welcome_discount` (`WHD_Emails::welcome_coupon()` prefers `exit_coupon` and
  skips a code WooCommerce no longer has) rather than being typed into copy — the modals used to promise 15% while
  the email sent a 10% code. The exit popup's countdown was removed with the same change: the coupon has no expiry,
  so it counted down to nothing. Saved designs beat the plugin defaults, so changing any of this also needs
  `.ftp-sync/fix-signup-designs.php` (idempotent, dry run by default, `--live --apply` for production).
- Abandoned carts: table `{prefix}whd_carts`, guests captured by an inline checkout script → AJAX, cron
  `whd_cart_cron` (custom 15-min schedule), restore links `?whd_recover=<token>`, unsubscribe `?whd_unsub=`.
  Subscribers: table `{prefix}whd_subscribers`. Activation creates both tables and default designs.
- **AI Writer** is one top-level menu with three screens: AI Posts (`whd-ai-posts`, also the parent slug), AI Pages
  (`whd-ai-pages`) and **Bulk products** (`whd-ai-products`, `WHD_AI_Products`, WooCommerce only). Admin assets key off
  `$_GET['page']`, never the hook name — the hook carries where an item sits in the menu, so matching on it breaks the
  moment a screen moves under a parent.
- Bulk products: a table of rows where only the photographs are required. Three REST steps per row, driven one row at a
  time from `admin/ai-products.js` — `look` (a vision call describing the item as facts), `write` (facts → name, both
  descriptions, SEO title, snippet, per-image alt/title/caption/description) and `make` (a **draft** `WC_Product_Simple`,
  pictures dressed and attached, attributes, category, `WHD_AI_Admin::finish_seo()`, score). `WHD_AI_Images::make()`
  draws a second photograph when the writer asks for one. Each row has a **Commands** box — free text, read both as
  facts for the copy ("runs small, linen blend") and as instructions for the build ("black, cream and navy, XS to XL,
  1450, sale 999"). The write step returns a `plan` (type, levels, price/sale/stock/sku/category) and `make()` hands
  any levels to `WHDV_Model::apply()`, so a product built here opens in the Variation tiers grid like any other.
  Variations are built **only when the command box is non-empty** and a level has two or more options: left to its
  own devices the model offers "Colour: Black" off the photograph, which made a variable product with one variation
  and moved the price off the parent, so the shop page showed no price. A tier's label is also skipped when the
  descriptive attributes are written, or the product lists Colour twice. Other gotchas the first real run found: a draft WooCommerce saves
  has **no `post_name`** until published, so the slug is set explicitly or `key_slug` fails on every product; Gemini's
  reasoning comes out of `max_tokens`, so the write step needs a budget far above the reply length or the JSON arrives
  truncated and unreadable; and the keyphrase is derived from the product's own name, never from the researched keyword
  list — that list is passed as vocabulary only (handing products topic phrases put a hundred of them in competition).
- **Write with AI on the product screen** is `WHD_AI_Studio` (`includes/class-whd-ai-studio.php`, `admin/ai-studio.js`):
  a meta box in its own context `whd_top`, printed at `edit_form_after_title` so it sits above the description (no
  position in the normal column can — the editor is not a meta box). Products no longer get the old `whd-ai-box`
  panel; pages and journal posts still do. Flow: pick photographs (defaults to featured + gallery) → tick parts
  (name, description, short, story, categories, size chart, product data, SEO) + keywords + a notes box read like
  Bulk's commands → number of new pictures, offered only as counts that make the total even (3 photos → 1/3/5),
  each with a mode (model / ghost mannequin) and instructions. Eight REST steps under `whd/v1/ai/studio/`, one
  request each so none runs long: `look` (photos at 1536px, `detail: high` → facts, whole-garment `countables` with
  `all_visible`, `colourways`, `absent`, boxes in thousandths), `inspect` (those boxes cut from the **original**
  files at full resolution, plus 2×2 tiles as a fallback, re-read and corrected), `write` (all copy in one JSON
  reply; house check + `WHD_SEO::analyse` on a synthetic doc), `repair` (its own request, once, when `write` says
  `repair: true`), `draw` + `check` per picture (references + detail crops; the checker counts the drawing against
  the spec; one redraw with the problems named, the worse attempt deleted via `discard`), `finish` (alt text and
  the story, which have no field on the screen). Everything that **has** a field (title, slug while unpublished,
  description, excerpt, categories, size chart select, SEO box, price, gallery) is filled in the browser and saved
  by Update; variants go through `window.WHDV_App.load( state )` in the Variation tiers grid; fabric/fit/care ride
  a hidden field merged at `woocommerce_process_product_meta` priority 30. Lessons from the first real runs: a
  count must be for the whole piece, not per photograph (the first prompt produced "exactly 1 front button"); a
  loose colour name beats the reference ("red plaid" drew bright tartan for a muted rust ombre), so colours are
  named precisely and the brief says colours come from the photographs; with two colourways in the photos, each
  shot names one and its photo goes first, the other labelled "cut and details only"; and a single request that
  wrote and repaired took 130 s, which is why repair is a separate step. When a ticked field already has content,
  Generate opens a `<dialog>` (appended to `body` — the panel sits inside `form#post`): **Write into a copy**
  (default; `copy` step = WooCommerce's own `WC_Admin_Duplicate_Product::product_duplicate()`, the run's settings
  parked in a per-user transient keyed to the copy, picked up once by `?whd_studio=run` on the copy's screen and
  started on `load`), **Overwrite**, or Cancel. A duplicate **shares the original's attachments**, so `dress()`
  never rewrites alt/title on a photo another product also uses (`shared()`) — it only fills empty fields there.
  **On live the zoom step first died with a bare HTTP 500 and nothing in `debug.log`** — a PHP fatal there comes
  back as JSON *and* is logged, so the process itself was killed (the photos are ~1,000px, so not memory; Imagick
  under LiteSpeed is the likely culprit, and WordPress 7.1 does not cap Imagick's threads). Every studio request
  now runs `prepare()`: GD before Imagick (`wp_image_editors`), `wp_raise_memory_limit( 'image' )`, crops from the
  original only when it is JPEG/PNG/WebP ≤ 16 MP (else the `-scaled` copy). Each risky step writes a note
  (`mark()`, transient `whd_studio_mark_{user}`) that the route wrapper clears on return; when a response is an
  HTML page, the panel calls `studio/last` and shows where the server stopped. A failed zoom no longer stops the
  run — it continues on the first look's facts with a warning line. While a run works, a `<dialog>` loader
  (`loader` in ai-studio.js) covers the page: viewfinder over the first photo (the `view` = medium URL each photo
  object carries), step + hint, a bar ticked once per progress line (`4 + pictures`, `+1` for a repair), time so
  far against an estimate (150 s + 55 s a picture), drawn thumbnails. Nothing closes it mid-run — Escape is
  swallowed and there is deliberately no "Hide" (the owner asked for it gone: editing fields under a run that is
  about to fill them causes trouble); it closes itself on success, and on failure shows the reason and a Close.
  Clicking a chosen photo or a drawn picture opens `lightbox` (another body-level `<dialog>`): the `large` size
  (photo objects carry `thumb`/`view`/`large`), the check's verdict and notes for a drawn one, ← → and arrows
  through its set, Escape / × / a click on the dark to close, focus back on the thumbnail.
  **No request waits on a model.** Live (LiteSpeed) ended "Writing the page" — a 60–110 s wait with no output —
  with a bare 500 and nothing logged, and the WAF passes HTML bodies, so it was the host's time limit. The six model
  steps (`JOBS`) answer 202 `{ job }` at once and do the work in `WHD_Plugin::after_response()` (connection closed
  via `litespeed_finish_request`); the result is a transient `whd_studio_job_{user}_{job}` that `studio/job` hands
  back once; the panel polls every 2.5 s, gives up after 8 min with the server's `last` note. A fatal inside a job
  is written onto the job by `shutdown()`. Locally (Apache + mod_php, nothing can close the connection early) the
  job just runs before the 202 is delivered, so behaviour there is unchanged.
- **Who appears in drawn pictures** is one list, `WHD_AI_Images::people()` (filter `whd_ai_image_people`): young
  women with a stated age (23–30) and background — Korean, Thai, Japanese, Chinese, Filipina, alternating with
  white — plus `age_rule()` (22–32) in every brief. Product sets offset the list by product id; journal, page and
  Bulk-products pictures (`make()`) take the next person from a random start. The old casting ran "twenties"
  to "fifties" with a rule demanding a different age per picture, so every set had an older woman in it.
- `WHD_AI::call()` takes `$options['images']` (attachment IDs or paths) and sends them as vision parts for all three
  providers; `WHD_AI::picture_part()` resizes a copy to `VISION_EDGE` first so a phone photo is not sent whole.
- Admin: menu `WHD`; editor screen `admin.php?page=whd-editor&type=popup|email&id=<id>` (vanilla JS in
  `admin/editor.js`) talks to REST `whd/v1/design|render|test-email` (`manage_options`). Popups print in `wp_footer`
  at priority 5 — it must stay below 20 or their assets never enqueue. `?whd_preview=<popup id>` shows a popup to admins.

### WHD — Variation Tiers plugin (`whd-variations`)

- Purpose: "multi-level variations". A tier is a WooCommerce **global attribute** (`pa_*`) used for variations; up to
  `WHDV_MAX_LEVELS` (3) tiers per product, ordered = the attribute positions. Every enabled combination in the grid is a
  real `product_variation`, so cart, stock, orders, the theme's cards/quick view and the Variation Swatches plugin all
  keep working. Nothing is stored in a parallel structure except `_whdv_tiers` (level order + per-level-1 group images).
- Admin: product screen → Product data → **Variation tiers** tab. WooCommerce's own **Variations** tab is hidden on any
  variable product the grid can show whole (`WHDV_Admin::grid_can_show()`: global attributes only, at most
  `WHDV_MAX_LEVELS` of them) — two adjacent tabs with nearly the same name read as two systems when they are two views
  of one set of rows. Setting `hide_wc_variations` (default on) turns it off, which is needed for per-variation weight
  / shipping class / description / downloadable files; the live shop uses none of those. Scoping the rule to products
  carrying `_whdv_tiers` does not work — every one of those is a draft. `WHDV_Admin`, `admin/tiers.js`, vanilla JS, state in
  the hidden `whdv_state` JSON field). The app switches the product type to *variable* and re-clicks its own tab
  (WooCommerce's type-change handler jumps to the first tab). Options can be existing terms (autocomplete) or new
  ones; a level can be a new global attribute (created with the swatch type — color/image/button/select). Swatch
  colours/images are saved as the term meta the theme/Variation Swatches read (`product_attribute_color`,
  `product_attribute_image`). The grid is grouped by level-1 option with per-group default images and bulk fills.
- Save: `woocommerce_process_product_meta` (after WooCommerce's own save) → `WHDV_Model::apply()` **only when the app
  was touched** (`dirty`), so a plain title edit never re-syncs variations. `apply()` is idempotent: ensures
  attributes/terms, sets the product's variation attributes (other attributes stay but stop being variation
  attributes), then creates/updates/deletes variations so they equal the enabled combinations — including "Any…" and
  duplicate variations (the grid is the source of truth). Converting simple → variable is done by instantiating
  `WC_Product_Variable( $id )` and saving; setting the `product_type` term and re-fetching does not work because
  WooCommerce caches the product type per request.
- Front end (`assets/front.js`, jQuery because WooCommerce fires its events through jQuery): rows of
  `table.variations` are the levels; progressive reveal, hide `li.variable-item.disabled` (the swatches plugin's
  "not available" class), auto-select single remaining option, step numerals + chosen value. Binds on DOM ready and on
  WooCommerce's `wc_variation_form` event (quick view / AJAX). Settings: WHD → Variation tiers (`whdv_settings`).
- Testing locally: store pages are in WooCommerce "Coming soon" mode, so product pages need a logged-in admin (or a
  share link); the Playwright harness logs in with a temporary admin created by a CLI script and deleted afterwards.

### Site content that is *not* in code

The front page, About page, the 37 keyword pages and journal posts, the product and occasion categories, the Main
Menu, announcement block, Customizer palette, WooCommerce and legal-page options, plugin activation and the
popup/email designs are all database rows created by `.ftp-sync/publish-site.php`. If a fresh environment shows the
demo homepage, run that script (it is idempotent). The video pages and their media come from
`.ftp-sync/import-videos.php`, which is also idempotent.

Both scripts honour `OMC_DB=live`, which writes to the **production** database. That step is the site owner's to
run or approve — it is a production write, and the agent harness blocks it.

## Gotchas

- **Kaspersky on the dev PC intercepts TLS** and re-signs certificates, and rotates them. The FTP tool therefore pins the
  fingerprint it sees; a "does not match the pinned fingerprint" error with issuer still *Kaspersky* and subject
  `autoconfig.rs5-sgp.serverhostgroup.com` just needs `node .ftp-sync/server.mjs pin`. A different issuer is **not**
  automatically an attack — when Kaspersky stops intercepting, the host's own Let's Encrypt certificate appears
  instead (seen once as `CN=YR2`, chaining to ISRG Root X1), and pinning that is strictly better than pinning a
  re-signed one. Check before re-pinning rather than guessing either way:
  `(echo QUIT; sleep 3) | openssl s_client -starttls ftp -connect ftp.oopsmineco.com:21 | grep -E "subject=|issuer=|Verify return"`.
  Same subject, a real CA and `Verify return code: 0 (ok)` is the benign case. An unknown issuer, a changed subject,
  or a chain that does not verify is the real problem — stop and ask.
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
- **Moderno caches its Customizer CSS.** The palette is concatenated into `uploads/moderno/min.css` behind the option
  `ideapark_styles_hash`, and that hash is built from file mtimes and the theme version — no theme-mod input. Writing
  mods from the CLI therefore leaves the stale sheet in place and the site keeps serving the demo palette while the
  script reports success. `publish-site.php` now deletes the hash options at the end, the way the Customizer does.
- **WooCommerce "coming soon" gates whichever page is the terms page.** With `woocommerce_store_pages_only = yes`,
  `/shop/`, `/cart/`, product pages *and* `woocommerce_terms_page_id` render the maintenance screen for logged-out
  visitors. A content page showing two `<h1>`s and ~80 kB instead of ~98 kB is usually this, not a template bug.
- **Hidden admin pages** (a screen reachable by URL but not listed in a menu, like the WHD editor) must be registered
  with an empty parent: `add_submenu_page( '', …, 'slug', … )` → hook `admin_page_slug`. Registering under a parent
  and then `remove_submenu_page()` leaves a hook WordPress can no longer resolve and every visit, even by an
  administrator, gets "Sorry, you are not allowed to access this page". Set `$GLOBALS['title']` on `load-admin_page_slug`
  and use the `parent_file` filter to keep the menu highlighted.
