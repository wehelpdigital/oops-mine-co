# WHD — Variation Tiers

Multi-level variations for WooCommerce, the way marketplace sellers think about them: **Variation 1 → Variation 2 → Variation 3**
(for example Colour → Size → Length). You define the tiers once, fill a single price / stock / SKU grid, and the plugin
creates and maintains the real WooCommerce variations. On the product page the shopper picks one level at a time and only
sees the combinations that exist.

## Setting up a product

1. Edit the product → **Product data** → **Variation tiers** tab.
2. **Add a level** and choose the attribute it uses (Colour, Size, …) — or pick *New attribute…* and type a name and how it
   should look (colour swatch, image, button, dropdown). The product is switched to *Variable* automatically.
3. Type the options for that level and press Enter. Existing options autocomplete; new ones are created on save.
   For colour swatches, click the dot to set the colour; for image swatches, click the square to pick an image.
4. Add the next level(s) the same way. Up to three levels; use ↑ ↓ to change which level comes first.
5. Fill the **Combinations** grid: untick a row to leave that combination out (e.g. no Blue in size 8), set prices,
   stock (leave empty = not tracked) and SKUs. *Fill every row* applies one value everywhere; *Image for all Black*
   sets the variation image for a whole colour.
6. Click **Update**. A notice reports how many variations were created, updated or removed.

The grid is the source of truth: variations that are not in it (unticked, or "Any …" ones left over from an earlier
setup) are removed on save. Products that already have variations load into the grid as they are, so nothing changes
until you edit the tab and Update.

## On the product page

WHD → **Variation tiers** holds the display options:

- reveal one level at a time (level 2 appears after level 1 is chosen);
- hide options that don't exist for the chosen higher level (instead of greying them out);
- auto-pick a level that has a single remaining option;
- number the levels and show the chosen value next to the label.

Everything else — swatches, price and stock updates, add to cart, quick view — is WooCommerce's own behaviour (and the
theme's / the Variation Swatches plugin's), so it keeps working with or without this plugin.

## For developers

- `WHDV_Model::state_for_product( $product )` — reads a product into `{ levels, combos, groups }`.
- `WHDV_Model::apply( $product_id, $state )` — writes that structure back as attributes, terms and variations. Idempotent.
- Product meta `_whdv_tiers` keeps the level order and group images; everything else is native WooCommerce data.
- Settings option: `whdv_settings`. Max levels: `WHDV_MAX_LEVELS`.
