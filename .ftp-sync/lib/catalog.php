<?php
/**
 * Apply .ftp-sync/content/catalog.json to the store: browse categories, variant attributes,
 * the per-category variant presets, and the demo categories that get retired.
 *
 * Included by publish-site.php, so it uses that script's helpers (omc_pub_note, omc_pub_dry,
 * omc_pub_upsert_term, omc_pub_same). Idempotent: run it as often as you like.
 *
 * @package oopsmine-tools
 */

defined( 'ABSPATH' ) || exit;

/** The catalog definition, or null when the file is missing or unreadable. */
function omc_cat_config() {
	static $cfg = false;
	if ( false !== $cfg ) {
		return $cfg;
	}
	$file = dirname( __DIR__ ) . '/content/catalog.json';
	$cfg  = null;
	if ( file_exists( $file ) ) {
		$decoded = json_decode( (string) file_get_contents( $file ), true );
		$cfg     = is_array( $decoded ) ? $decoded : null;
		if ( null === $cfg ) {
			omc_pub_note( 'catalog.json: could not be parsed (' . json_last_error_msg() . '), skipped' );
		}
	}
	return $cfg;
}

/**
 * Create or update one product category, reusing an existing term with the same slug.
 *
 * Reuse matters: `dresses` and `skirts` already exist under the demo "Women" tree with products
 * attached. Moving the term keeps every assignment and every URL; deleting and recreating would
 * empty both and hand out a new permalink.
 *
 * @return int Term id, or 0 in a dry run / on failure.
 */
function omc_cat_term( array $spec, $parent_id = 0 ) {
	$slug = sanitize_title( $spec['slug'] );
	$term = get_term_by( 'slug', $slug, 'product_cat' );

	if ( $term ) {
		$changes = [];
		// WordPress stores "&" encoded, so compare decoded or "New & Now" looks changed every run.
		if ( html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) !== $spec['name'] ) {
			$changes['name'] = $spec['name'];
		}
		if ( (int) $term->parent !== (int) $parent_id ) {
			$changes['parent'] = (int) $parent_id;
		}
		if ( ! empty( $spec['description'] ) && $term->description !== $spec['description'] ) {
			$changes['description'] = $spec['description'];
		}
		if ( $changes ) {
			omc_pub_note( "product_cat {$slug}: " . implode( ', ', array_keys( $changes ) ) . ' updated' );
			if ( ! omc_pub_dry() ) {
				wp_update_term( $term->term_id, 'product_cat', $changes );
			}
		}
		return (int) $term->term_id;
	}

	omc_pub_note( "product_cat {$slug}: created ({$spec['name']})" );
	if ( omc_pub_dry() ) {
		return 0;
	}
	$made = wp_insert_term( $spec['name'], 'product_cat', [
		'slug'        => $slug,
		'parent'      => (int) $parent_id,
		'description' => (string) ( $spec['description'] ?? '' ),
	] );
	return is_wp_error( $made ) ? 0 : (int) $made['term_id'];
}

/** The whole browse tree, parents before children. */
function omc_cat_categories( array $cfg ) {
	$made = [];
	foreach ( (array) ( $cfg['categories'] ?? [] ) as $group ) {
		$parent_id = omc_cat_term( $group, 0 );
		$made[ $group['slug'] ] = $parent_id;
		foreach ( (array) ( $group['children'] ?? [] ) as $child ) {
			$made[ $child['slug'] ] = omc_cat_term( $child, $parent_id );
		}
	}
	return $made;
}

/**
 * Variant types as WooCommerce global attributes (pa_<slug>) plus their terms.
 *
 * "Color + Size" in the owner's table is not a third attribute — it is two levels picked one after
 * the other, which is exactly what the Variation Tiers plugin already does, so it needs no entry
 * of its own here.
 */
function omc_cat_attributes( array $cfg ) {
	$made = [];
	foreach ( (array) ( $cfg['attributes'] ?? [] ) as $attr ) {
		$slug  = sanitize_title( $attr['slug'] );
		$label = (string) $attr['label'];
		$type  = (string) ( $attr['type'] ?? 'select' );
		$id    = wc_attribute_taxonomy_id_by_name( $slug );

		if ( ! $id ) {
			omc_pub_note( "attribute pa_{$slug}: created ({$label}, {$type})" );
			if ( ! omc_pub_dry() ) {
				$id = wc_create_attribute( [
					'name'         => $label,
					'slug'         => $slug,
					'type'         => $type,
					'order_by'     => 'menu_order',
					'has_archives' => false,
				] );
				if ( is_wp_error( $id ) ) {
					omc_pub_note( "attribute pa_{$slug}: FAILED — " . $id->get_error_message() );
					continue;
				}
				delete_transient( 'wc_attribute_taxonomies' );
				WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
				register_taxonomy( 'pa_' . $slug, [ 'product' ], [ 'hierarchical' => false, 'show_ui' => false ] );
			}
		}
		if ( omc_pub_dry() ) {
			$made[ $attr['slug'] ] = 0;
			continue;
		}

		$tax = 'pa_' . $slug;
		if ( ! taxonomy_exists( $tax ) ) {
			register_taxonomy( $tax, [ 'product' ], [ 'hierarchical' => false, 'show_ui' => false ] );
		}

		$position = 0;
		foreach ( (array) ( $attr['terms'] ?? [] ) as $raw ) {
			$name  = is_array( $raw ) ? (string) $raw['name'] : (string) $raw;
			$color = is_array( $raw ) ? (string) ( $raw['color'] ?? '' ) : '';
			$term  = get_term_by( 'name', $name, $tax );
			if ( ! $term ) {
				$new = wp_insert_term( $name, $tax );
				if ( is_wp_error( $new ) ) {
					continue;
				}
				omc_pub_note( "attribute pa_{$slug}: term \"{$name}\" added" );
				$term = get_term( $new['term_id'], $tax );
			}
			// The swatch colour the theme and the Variation Swatches plugin read.
			if ( $color && get_term_meta( $term->term_id, 'product_attribute_color', true ) !== $color ) {
				update_term_meta( $term->term_id, 'product_attribute_color', $color );
			}
			update_term_meta( $term->term_id, 'order', ++$position );
		}
		$made[ $attr['slug'] ] = (int) $id;
	}
	return $made;
}

/**
 * Store the per-category variant presets for the Variation Tiers tab to read.
 *
 * Kept as slugs rather than term ids so the option survives a rebuild of the taxonomy.
 */
function omc_cat_presets( array $cfg ) {
	$presets = [];
	foreach ( (array) ( $cfg['presets'] ?? [] ) as $cat => $attrs ) {
		$presets[ sanitize_title( $cat ) ] = array_values( array_map( 'sanitize_title', (array) $attrs ) );
	}
	$current = get_option( 'whdv_presets', [] );
	if ( omc_pub_same( $current, $presets ) ) {
		return count( $presets );
	}
	omc_pub_note( 'option whdv_presets: ' . count( $presets ) . ' category presets stored' );
	if ( ! omc_pub_dry() ) {
		update_option( 'whdv_presets', $presets, false );
	}
	return count( $presets );
}

/**
 * Delete the categories named in `retire`.
 *
 * Only the term goes; products keep existing and simply lose that one category. A term that still
 * has children is left alone, so a mis-ordered list cannot orphan a subcategory.
 */
function omc_cat_retire( array $cfg ) {
	$gone = 0;
	foreach ( (array) ( $cfg['retire'] ?? [] ) as $slug ) {
		$term = get_term_by( 'slug', sanitize_title( $slug ), 'product_cat' );
		if ( ! $term ) {
			continue;
		}
		$children = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => $term->term_id ] );
		if ( ! is_wp_error( $children ) && $children ) {
			omc_pub_note( "product_cat {$slug}: kept, it still has " . count( $children ) . ' subcategories (retire those first)' );
			continue;
		}
		omc_pub_note( "product_cat {$slug}: deleted ({$term->count} products keep their other categories)" );
		if ( ! omc_pub_dry() ) {
			wp_delete_term( $term->term_id, 'product_cat' );
		}
		$gone++;
	}
	return $gone;
}

/**
 * Put the demo products into the new clothing categories, by what the product actually is.
 *
 * Matched on the title because the demo catalogue has no other signal. A product that matches
 * nothing is left where it is rather than guessed at.
 */
function omc_cat_sort_products( array $terms ) {
	$rules = [
		'rompers-jumpsuits' => [ 'jumpsuit', 'romper', 'playsuit' ],
		'matching-sets'     => [ ' set', 'two-piece', '2-piece', 'co-ord' ],
		'jackets-outerwear' => [ 'jacket', 'coat', 'blazer', 'parka', 'trench', 'bomber' ],
		'sweaters-knits'    => [ 'sweater', 'knit', 'cardigan', 'pullover', 'jumper' ],
		'tees'              => [ 't-shirt', 'tee', 'tank' ],
		'blouses'           => [ 'blouse', 'shirt' ],
		'shorts'            => [ 'short' ],
		'jeans'             => [ 'jean', 'denim' ],
		'pants-trousers'    => [ 'pant', 'trouser', 'legging', 'chino' ],
		'skirts'            => [ 'skirt' ],
		'dresses'           => [ 'dress', 'gown' ],
		'tops'              => [ 'top', 'cami', 'bodysuit', 'blouson' ],
	];

	$products = get_posts( [ 'post_type' => 'product', 'numberposts' => -1, 'post_status' => 'publish' ] );
	$sorted   = 0;
	$fallback = isset( $terms['clothing'] ) ? (int) $terms['clothing'] : 0;
	$skip = array_map( 'strtolower', (array) ( $GLOBALS['omc_cat_skip_words'] ?? [] ) );
	foreach ( $products as $product ) {
		$title = strtolower( $product->post_title );

		// Footwear and accessories are not garment types. Without this a "Low Top Sneaker"
		// matches the Tops rule and a shoe becomes the Tops category tile.
		foreach ( $skip as $word ) {
			if ( false !== strpos( $title, $word ) ) {
				continue 2;
			}
		}
		foreach ( $rules as $slug => $needles ) {
			if ( empty( $terms[ $slug ] ) ) {
				continue;
			}
			foreach ( $needles as $needle ) {
				if ( false === strpos( $title, $needle ) ) {
					continue;
				}
				if ( has_term( (int) $terms[ $slug ], 'product_cat', $product->ID ) ) {
					break 2; // already there
				}
				omc_pub_note( "product #{$product->ID} \"{$product->post_title}\": added to {$slug}" );
				if ( ! omc_pub_dry() ) {
					wp_set_object_terms( $product->ID, [ (int) $terms[ $slug ] ], 'product_cat', true );
				}
				$sorted++;
				break 2;
			}
		}

		/*
		 * Nothing in the title said what it is. Park it under Clothing rather than leave it out of
		 * the browse tree entirely — an unreachable product is worse than a roughly-filed one.
		 */
		if ( $fallback && ! omc_cat_in_tree( $product->ID, $terms ) ) {
			omc_pub_note( "product #{$product->ID} \"{$product->post_title}\": no type matched, filed under Clothing" );
			if ( ! omc_pub_dry() ) {
				wp_set_object_terms( $product->ID, [ $fallback ], 'product_cat', true );
			}
			$sorted++;
		}
	}
	return $sorted;
}

/** Is this product in any of the categories we just built? */
function omc_cat_in_tree( $product_id, array $terms ) {
	$ids = array_values( array_filter( array_map( 'intval', $terms ) ) );
	return $ids ? has_term( $ids, 'product_cat', $product_id ) : false;
}

/**
 * Delete the global attributes named in `retire_attributes`.
 *
 * Their terms go with the taxonomy. Products keep existing and simply lose that attribute, so a
 * variable product built on a retired attribute becomes a simple one rather than breaking.
 */
function omc_cat_retire_attributes( array $cfg ) {
	$gone = 0;
	foreach ( (array) ( $cfg['retire_attributes'] ?? [] ) as $slug ) {
		$slug = sanitize_title( $slug );
		$id   = wc_attribute_taxonomy_id_by_name( $slug );
		if ( ! $id ) {
			continue;
		}
		$tax   = 'pa_' . $slug;
		$count = taxonomy_exists( $tax ) ? (int) wp_count_terms( [ 'taxonomy' => $tax, 'hide_empty' => false ] ) : 0;
		omc_pub_note( "attribute pa_{$slug}: deleted ({$count} terms)" );
		if ( ! omc_pub_dry() ) {
			wc_delete_attribute( $id );
			delete_transient( 'wc_attribute_taxonomies' );
			WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
		}
		$gone++;
	}
	return $gone;
}

/**
 * Set the demo products named in `unpublish_products` to draft.
 *
 * Draft, never delete: the owner may want one back, and a deleted product takes its images and its
 * variations with it. Already-drafted ones are left alone, so re-running is quiet.
 */
function omc_cat_unpublish( array $cfg ) {
	$ids  = (array) ( $cfg['unpublish_products']['ids'] ?? [] );
	$gone = 0;
	foreach ( $ids as $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || 'product' !== $post->post_type || 'publish' !== $post->post_status ) {
			continue;
		}
		omc_pub_note( "product #{$id} \"{$post->post_title}\": set to draft (menswear/kidswear demo item)" );
		if ( ! omc_pub_dry() ) {
			wp_update_post( [ 'ID' => (int) $id, 'post_status' => 'draft' ] );
		}
		$gone++;
	}
	return $gone;
}

/** Run the whole thing. Returns a short summary for the publish log. */
function omc_cat_apply() {
	$cfg = omc_cat_config();
	if ( ! $cfg ) {
		return [ 'skipped' => 'no catalog.json' ];
	}
	$GLOBALS['omc_cat_skip_words'] = (array) ( $cfg['sort_skip']['words'] ?? [] );
	$unpublished = omc_cat_unpublish( $cfg );
	$terms = omc_cat_categories( $cfg );
	$attrs = omc_cat_attributes( $cfg );
	return [
		'categories' => count( array_filter( $terms ) ),
		'attributes' => count( $attrs ),
		'presets'    => omc_cat_presets( $cfg ),
		'sorted'     => omc_cat_sort_products( $terms ),
		'retired'    => omc_cat_retire( $cfg ),
		'attrs_gone' => omc_cat_retire_attributes( $cfg ),
		'unpublished' => $unpublished,
	];
}
