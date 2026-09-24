# .ftp-sync/content

Site **copy** that lives in git, not in the database. Each file is the source of truth for one page;
running the publish script turns it into WordPress rows.

| Folder / file | Read by | Becomes |
|---|---|---|
| `pages/*.json` | `php .ftp-sync/publish-site.php` (section 4) | a page or blog post + the product category it fronts |
| `plan.json` | the same script (section 4c/5) | occasion categories + the order of the mega-menu columns |
| `videos/*.json` | `php .ftp-sync/import-videos.php` | the shoppable video pages and their hub |

Nothing here is deployed: the JSON stays local (it is inside the hard-blocked `.ftp-sync/` dot-folder).
The *result* of importing it is database content, so the same script is run once against the live DB
after a deploy:

```bash
php .ftp-sync/publish-site.php --dry-run    # report every change, write nothing
php .ftp-sync/publish-site.php              # local database
OMC_DB=live php .ftp-sync/publish-site.php  # LIVE database — the site owner runs this one
```

The import is idempotent: editing a JSON file and re-running updates only the fields that really
changed (`"changed": 0` in the log means the database already matches the files).

## pages/ — the contract

One file per page, named `<slug>.json`. Only `slug`, `title` and `type` are required; everything else
is optional and simply produces less markup.

```jsonc
{
  "slug": "korean-fashion",              // required; the page URL, /korean-fashion/
  "type": "landing",                     // required; landing | journal | faq | policy | contact | sizeguide
  "status": "publish",                   // publish (default) | draft | pending | private
  "title": "H1, 40–70 chars, contains the primary keyword",   // required
  "seo_title": "≤ 60 chars",             // → post meta _omc_seo_title
  "meta_description": "140–160 chars",   // → post meta _omc_meta_description (and the excerpt fallback)
  "primary_keyword": "korean fashion online store",
  "keywords": ["every keyword assigned to this page, verbatim"],
  "excerpt": "≤ 160 chars",              // journal posts; falls back to meta_description

  "hero": { "eyebrow": "Style edit", "intro": "1–2 sentences", "cta_text": "Shop Korean fashion", "cta_url": "/product-category/korean-fashion/" },

  // landing pages only: the product category the page fronts
  "category": {
    "slug": "korean-fashion",
    "name": "Korean Fashion",
    "parent": "women",                   // a product_cat slug; missing parent = top level
    "description": "1–2 sentences shown on the category archive",
    "assign_products": [379, 376]        // existing product IDs, appended (never removed)
  },

  "sections": [
    {
      "heading": "H2 with a keyword where natural",
      "paragraphs": ["60–120 words each"],
      "bullets": ["optional", "3–6 short items"],
      "table": { "caption": "Fit", "head": ["Size", "Bust"], "rows": [["S", "84 cm"]] },
      "faqs": [ { "q": "…", "a": "…" } ],   // optional, inside a section
      "cta": { "text": "Shop dresses", "url": "/product-category/dresses/" }
    }
  ],

  "faqs": [ { "q": "Question a shopper asks", "a": "40–90 words" } ],
  "cta": { "heading": "Find something yours", "text": "…", "button_text": "Shop new arrivals", "button_url": "/shop/?orderby=date", "capture_email": true },
  "related_slugs": ["thai-fashion", "petite-dresses"],
  "author_notes": "facts to confirm, images to add — never rendered"
}
```

Copy rules (voice, banned words, keywords used verbatim, no invented facts) live in the build brief,
not here. Inside `paragraphs`, `bullets` and table cells you may use `<a> <em> <strong> <b> <i> <br>
<small>` — everything else is stripped.

### What the publish script does with a file

1. **Category** — when `category.slug` is present the `product_cat` term is created or updated
   (name, parent, description) and `assign_products` are *appended* to it, so a product keeps the
   categories it already has. Several files may point at the same category (the petite evening/wedding
   pages share the `evening` and `wedding-guest` occasion categories); the last one wins on the
   description, so keep them identical or leave one empty.
2. **Page** — upserted by slug. `type` picks the page template:

   | type | post type | template |
   |---|---|---|
   | `landing` | page | `templates/page-landing.php` |
   | `faq` | page | `templates/page-faq.php` |
   | `contact` | page | `templates/page-contact.php` |
   | `policy`, `sizeguide` | page | `templates/page-policy.php` |
   | `journal` | post, category "Style notes" | the theme's post template |

3. **Meta** — the whole document is stored as `_omc_landing` (JSON) for the template to render, plus
   `_omc_meta_description` and `_omc_seo_title`.
4. **post_content** — a plain-HTML copy of the document (h2/p/ul/table, then the FAQ as h3/p, then the
   CTA). No template markup: it is the fallback the page shows if its template is missing, and what
   feeds search results, feeds and the editor.
5. **Elementor** — if the page being replaced was an Elementor page, every `_elementor*` meta is
   deleted so the page template renders instead.

### Rules the import enforces

- A file with invalid JSON, no `slug`/`title`, or an unknown `type` is **skipped with a reason** in the
  log — it never stops the run, so a half-written folder is safe.
- The WooCommerce pages (`shop`, `cart`, `checkout`, `my-account`), the front page and the blog page
  are never touched, by slug and by ID.
- Pages are matched by slug, so a file keeps the URL the site already uses instead of creating a
  second page: `contacts`, `faq`, `refund_returns`, `privacy-policy`, `order-tracking` exist from the
  demo import; `shipping-policy`, `terms-and-conditions` and `size-guide` are created on first run.
- `--dry-run` writes nothing, so new pages and categories report ID `0` and `products: 0`
  (product assignment needs the term that would have been created). Run it again for real to see the
  true counts.

## Adding a page

1. Pick the slug and the keywords from `plan.json` (`pages[]` lists slug, type, category and the
   keywords assigned to it; a keyword must appear verbatim in the copy).
2. Write `pages/<slug>.json` with the shape above. Use site-relative links
   (`/product-category/<slug>/`, `/<page-slug>/`, `/shop/`, `/blog/`).
3. `C:/xampp/php/php.exe .ftp-sync/publish-site.php --dry-run` — read the `content` block of the log:
   the file must appear under `imported`, not under `skipped`.
4. Run it for real and open `http://oopsmine.test/<slug>/`.
5. A landing page whose style is listed in the menu's "Shop by style" column, or a journal post listed
   in `plan.json`, joins the mega menu on that same run — the menu is rebuilt only when its contents
   change.
6. Commit the JSON file. After deploying the code, the site owner runs the publish script once against
   the live database.

## plan.json

The keyword plan (generated from the client's keyword list): `pages[]` (slug, type, category, assigned
keywords), `occasion_categories[]` (the Casual / Workwear / Evening / Wedding Guest categories and the
products that seed them), `excluded[]` (keywords deliberately not used) and `notes`. The publish script
reads it for the occasion categories and for the journal links in the mega menu; when it is missing,
occasion categories are seeded by matching product titles instead.
