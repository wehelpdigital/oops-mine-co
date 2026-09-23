<?php
/**
 * Tier model: reads a product's variation structure into "levels + combinations", and writes that structure
 * back as real WooCommerce global attributes, terms and product variations.
 *
 * State shape (what the admin app sends and receives):
 *   levels: [ { taxonomy, label, type, new, options: [ { key, id, name, slug, color, image } ] } ]  (max WHDV_MAX_LEVELS)
 *   combos: { "<key>|<key>|…": { on, sku, regular_price, sale_price, stock, oos, image } }        keys follow level order
 *   groups: { "<level-1 key>": { image } }                                                        default image per level-1 option
 * An option key is the term slug for existing terms and "new:<slug>" for terms typed in the app.
 */

defined( 'ABSPATH' ) || exit;

final class WHDV_Model {

	const META = '_whdv_tiers';

	/* ───────────────────────────── read ───────────────────────────── */

	/** Every global attribute with its terms and swatch meta — the admin app's pick lists. */
	public static function catalog() {
		$out = [];
		foreach ( wc_get_attribute_taxonomies() as $tax ) {
			$taxonomy = wc_attribute_taxonomy_name( $tax->attribute_name );
			$terms    = taxonomy_exists( $taxonomy ) ? get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 400, 'orderby' => 'name' ] ) : [];
			$out[]    = [
				'taxonomy' => $taxonomy,
				'id'       => (int) $tax->attribute_id,
				'label'    => $tax->attribute_label,
				'type'     => $tax->attribute_type ?: 'select',
				'terms'    => array_values( array_map( [ __CLASS__, 'term_data' ], is_wp_error( $terms ) ? [] : $terms ) ),
			];
		}
		return $out;
	}

	/** A term + the swatch meta the theme / Variation Swatches plugin read. */
	public static function term_data( WP_Term $t ) {
		$image = (int) get_term_meta( $t->term_id, 'product_attribute_image', true );
		return [
			'id'        => (int) $t->term_id,
			'name'      => $t->name,
			'slug'      => $t->slug,
			'color'     => (string) get_term_meta( $t->term_id, 'product_attribute_color', true ),
			'image'     => $image,
			'image_url' => $image ? (string) wp_get_attachment_image_url( $image, 'thumbnail' ) : '',
		];
	}

	/** The product's current variation attributes and variations, as tier state for the admin app. */
	public static function state_for_product( WC_Product $product ) {
		$state = [ 'levels' => [], 'combos' => [], 'groups' => [], 'notices' => [], 'dirty' => false ];
		$attrs = array_values( $product->get_attributes() );
		usort( $attrs, function ( $a, $b ) {
			return $a->get_position() <=> $b->get_position();
		} );
		foreach ( $attrs as $attr ) {
			if ( ! $attr->get_variation() ) {
				continue;
			}
			if ( ! $attr->is_taxonomy() ) {
				/* translators: %s: attribute name */
				$state['notices'][] = sprintf( __( '"%s" is a custom (per-product) attribute. Tiers work with global attributes (Products → Attributes); this one is ignored here and left as it is.', 'whd-variations' ), wc_attribute_label( $attr->get_name() ) );
				continue;
			}
			if ( count( $state['levels'] ) >= WHDV_MAX_LEVELS ) {
				/* translators: %d: number of levels */
				$state['notices'][] = sprintf( __( 'This product has more than %d variation attributes; only the first ones are shown as tiers.', 'whd-variations' ), WHDV_MAX_LEVELS );
				break;
			}
			$taxonomy = $attr->get_name();
			$tax      = wc_get_attribute( $attr->get_id() );
			$options  = [];
			foreach ( (array) $attr->get_terms() as $term ) {
				$options[] = [ 'key' => $term->slug ] + self::term_data( $term );
			}
			$state['levels'][] = [
				'taxonomy' => $taxonomy,
				'label'    => wc_attribute_label( $taxonomy ),
				'type'     => $tax ? $tax->type : 'select',
				'new'      => false,
				'options'  => $options,
			];
		}
		if ( $state['levels'] && $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( ! $v ) {
					continue;
				}
				$va  = $v->get_attributes();
				$key = [];
				foreach ( $state['levels'] as $lvl ) {
					$slug = (string) ( $va[ $lvl['taxonomy'] ] ?? '' );
					if ( '' === $slug ) {
						$key = null;
						break;
					}
					$key[] = $slug;
				}
				if ( null === $key ) {
					/* translators: %d: variation id */
					$state['notices'][] = sprintf( __( 'Variation #%d uses "Any…" for one of the tiers, so it cannot be shown in the grid. The grid is the source of truth: that variation is removed when you save the tiers.', 'whd-variations' ), $vid );
					continue;
				}
				$image = (int) $v->get_image_id( 'edit' );
				$state['combos'][ implode( '|', $key ) ] = [
					'on'            => true,
					'id'            => $vid,
					'sku'           => (string) $v->get_sku( 'edit' ),
					'regular_price' => (string) $v->get_regular_price( 'edit' ),
					'sale_price'    => (string) $v->get_sale_price( 'edit' ),
					'stock'         => $v->get_manage_stock( 'edit' ) ? (string) $v->get_stock_quantity( 'edit' ) : '',
					'oos'           => ! $v->get_manage_stock( 'edit' ) && 'outofstock' === $v->get_stock_status( 'edit' ),
					'image'         => $image,
					'image_url'     => $image ? (string) wp_get_attachment_image_url( $image, 'thumbnail' ) : '',
				];
			}
		}
		$saved = get_post_meta( $product->get_id(), self::META, true );
		if ( is_array( $saved ) && ! empty( $saved['groups'] ) ) {
			foreach ( (array) $saved['groups'] as $k => $g ) {
				$img = (int) ( $g['image'] ?? 0 );
				if ( $img ) {
					$state['groups'][ $k ] = [ 'image' => $img, 'image_url' => (string) wp_get_attachment_image_url( $img, 'thumbnail' ) ];
				}
			}
		}
		return $state;
	}

	/* ───────────────────────────── write ───────────────────────────── */

	/**
	 * Apply a tier state to a product: makes it variable, ensures attributes/terms exist, sets the product's
	 * variation attributes, then creates / updates / deletes variations to match the enabled combinations.
	 * Returns counts + human-readable notices.
	 */
	public static function apply( $product_id, array $state ) {
		$result = [ 'created' => 0, 'updated' => 0, 'deleted' => 0, 'notices' => [], 'ok' => false ];
		$levels = array_slice( array_values( (array) ( $state['levels'] ?? [] ) ), 0, WHDV_MAX_LEVELS );
		$levels = array_values( array_filter( $levels, function ( $l ) {
			return is_array( $l ) && ! empty( $l['options'] );
		} ) );
		if ( ! $levels ) {
			$result['notices'][] = __( 'No tiers with options were defined, so nothing was changed.', 'whd-variations' );
			return $result;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return $result;
		}
		if ( ! $product->is_type( 'variable' ) ) {
			// Same data, variable class: the save() below writes the product_type term. (Setting the term first
			// and re-fetching doesn't work — WooCommerce caches the product type per request.)
			$product = new WC_Product_Variable( $product_id );
		}

		// 1. Attributes + terms.
		$resolved = [];
		foreach ( $levels as $lvl ) {
			$taxonomy = self::resolve_taxonomy( $lvl, $result );
			if ( ! $taxonomy ) {
				/* translators: %s: level label */
				$result['notices'][] = sprintf( __( 'Could not create or find the attribute for "%s". Nothing was changed.', 'whd-variations' ), (string) ( $lvl['label'] ?? '' ) );
				return $result;
			}
			$type = self::taxonomy_type( $taxonomy );
			$opts = [];
			foreach ( (array) $lvl['options'] as $opt ) {
				if ( ! is_array( $opt ) ) {
					continue;
				}
				$term = self::resolve_term( $taxonomy, $opt );
				if ( ! $term ) {
					/* translators: %s: option name */
					$result['notices'][] = sprintf( __( 'Option "%s" could not be saved and was skipped.', 'whd-variations' ), (string) ( $opt['name'] ?? '' ) );
					continue;
				}
				self::save_swatch_meta( $term->term_id, $opt, $type );
				$key          = (string) ( $opt['key'] ?? $term->slug );
				$opts[ $key ] = [ 'id' => (int) $term->term_id, 'slug' => $term->slug, 'name' => $term->name ];
			}
			if ( ! $opts ) {
				$result['notices'][] = __( 'A tier ended up with no valid options. Nothing was changed.', 'whd-variations' );
				return $result;
			}
			$resolved[] = [ 'taxonomy' => $taxonomy, 'label' => wc_attribute_label( $taxonomy ), 'options' => $opts ];
		}
		$level_taxes = wp_list_pluck( $resolved, 'taxonomy' );

		// 2. Product attributes: the levels (variation) first, other attributes kept but not used for variations.
		$attributes = [];
		foreach ( $resolved as $i => $lvl ) {
			$attr = new WC_Product_Attribute();
			$attr->set_id( wc_attribute_taxonomy_id_by_name( $lvl['taxonomy'] ) );
			$attr->set_name( $lvl['taxonomy'] );
			$attr->set_options( array_values( wp_list_pluck( $lvl['options'], 'id' ) ) );
			$attr->set_position( $i );
			$attr->set_visible( true );
			$attr->set_variation( true );
			$attributes[ $lvl['taxonomy'] ] = $attr;
		}
		$pos = count( $resolved );
		foreach ( $product->get_attributes() as $name => $attr ) {
			if ( in_array( $name, $level_taxes, true ) ) {
				continue;
			}
			if ( $attr->get_variation() ) {
				$attr->set_variation( false );
				/* translators: %s: attribute label */
				$result['notices'][] = sprintf( __( '"%s" is no longer a tier; it stays on the product as a plain attribute.', 'whd-variations' ), wc_attribute_label( $name ) );
			}
			$attr->set_position( $pos++ );
			$attributes[ $name ] = $attr;
		}
		$product->set_attributes( $attributes );
		$product->save();

		// 3. Which combinations should exist.
		$combos  = (array) ( $state['combos'] ?? [] );
		$groups  = (array) ( $state['groups'] ?? [] );
		$desired = [];
		foreach ( self::cartesian( $resolved ) as $keys ) {
			$c = $combos[ implode( '|', $keys ) ] ?? null;
			if ( ! is_array( $c ) || empty( $c['on'] ) ) {
				continue;
			}
			$slugs = [];
			foreach ( $keys as $li => $key ) {
				$slugs[ $resolved[ $li ]['taxonomy'] ] = $resolved[ $li ]['options'][ $key ]['slug'];
			}
			if ( empty( $c['image'] ) && ! empty( $groups[ $keys[0] ]['image'] ) ) {
				$c['image'] = (int) $groups[ $keys[0] ]['image'];
			}
			$desired[ implode( '|', array_values( $slugs ) ) ] = [ 'attrs' => $slugs, 'data' => $c ];
		}

		// 4. Existing variations keyed the same way.
		$product  = wc_get_product( $product_id );
		$children = [];
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v ) {
				continue;
			}
			$va  = $v->get_attributes();
			$key = [];
			foreach ( $level_taxes as $t ) {
				$s = (string) ( $va[ $t ] ?? '' );
				if ( '' === $s ) {
					$key = null;
					break;
				}
				$key[] = $s;
			}
			if ( null === $key ) {
				// "Any …" for a tier (typically a variation left over from before a level was added): the grid is
				// the source of truth, so it goes.
				$v->delete( true );
				$result['deleted']++;
				continue;
			}
			$k = implode( '|', $key );
			if ( isset( $children[ $k ] ) ) { // duplicate combination: keep the first, drop the rest
				$v->delete( true );
				$result['deleted']++;
				continue;
			}
			$children[ $k ] = $v;
		}
		foreach ( $children as $k => $v ) {
			if ( ! isset( $desired[ $k ] ) ) {
				$v->delete( true );
				$result['deleted']++;
				unset( $children[ $k ] );
			}
		}

		// 5. Create / update.
		foreach ( $desired as $k => $d ) {
			$v      = $children[ $k ] ?? null;
			$is_new = ! $v;
			if ( $is_new ) {
				$v = new WC_Product_Variation();
				$v->set_parent_id( $product_id );
				$v->set_status( 'publish' );
			}
			$v->set_attributes( $d['attrs'] );
			self::fill_variation( $v, $d['data'], $result );
			$v->save();
			if ( $is_new ) {
				$result['created']++;
			} else {
				$result['updated']++;
			}
		}
		WC_Product_Variable::sync( $product_id );
		wc_delete_product_transients( $product_id );

		// 6. Remember the structure (level order + group images) for the app and the front end.
		$saved_groups = [];
		foreach ( $groups as $gk => $g ) {
			if ( ! empty( $g['image'] ) ) {
				$saved_groups[ sanitize_text_field( (string) $gk ) ] = [ 'image' => (int) $g['image'] ];
			}
		}
		update_post_meta( $product_id, self::META, [
			'levels'  => array_map( function ( $l ) {
				return [ 'taxonomy' => $l['taxonomy'], 'label' => $l['label'] ];
			}, $resolved ),
			'groups'  => $saved_groups,
			'updated' => time(),
		] );
		$result['ok'] = true;
		return $result;
	}

	/* ───────────────────────────── helpers ───────────────────────────── */

	/** Existing taxonomy for a level, or a new global attribute created from its label/type. */
	private static function resolve_taxonomy( array $lvl, array &$result ) {
		$taxonomy = sanitize_key( (string) ( $lvl['taxonomy'] ?? '' ) );
		if ( $taxonomy && taxonomy_exists( $taxonomy ) && wc_attribute_taxonomy_id_by_name( $taxonomy ) ) {
			return $taxonomy;
		}
		$label = sanitize_text_field( (string) ( $lvl['label'] ?? '' ) );
		if ( '' === $label ) {
			return '';
		}
		$slug = wc_sanitize_taxonomy_name( $label );
		foreach ( wc_get_attribute_taxonomies() as $t ) {
			if ( 0 === strcasecmp( $t->attribute_label, $label ) || $t->attribute_name === $slug ) {
				$existing = wc_attribute_taxonomy_name( $t->attribute_name );
				if ( ! taxonomy_exists( $existing ) ) {
					self::register_now( $existing, $t->attribute_label );
				}
				return $existing;
			}
		}
		$types = array_keys( wc_get_attribute_types() );
		$type  = in_array( $lvl['type'] ?? '', $types, true ) ? $lvl['type'] : 'select';
		$id    = wc_create_attribute( [ 'name' => $label, 'slug' => $slug, 'type' => $type, 'order_by' => 'menu_order', 'has_archives' => false ] );
		if ( is_wp_error( $id ) ) {
			$result['notices'][] = $id->get_error_message();
			return '';
		}
		$taxonomy = wc_attribute_taxonomy_name( $slug );
		self::register_now( $taxonomy, $label );
		/* translators: %s: attribute label */
		$result['notices'][] = sprintf( __( 'Created the global attribute "%s" (Products → Attributes).', 'whd-variations' ), $label );
		return $taxonomy;
	}

	/** Attribute taxonomies are registered on init; a brand-new one has to be registered for this request too. */
	private static function register_now( $taxonomy, $label ) {
		if ( taxonomy_exists( $taxonomy ) ) {
			return;
		}
		register_taxonomy( $taxonomy, apply_filters( 'woocommerce_taxonomy_objects_' . $taxonomy, [ 'product' ] ), apply_filters( 'woocommerce_taxonomy_args_' . $taxonomy, [
			'labels'                => [ 'name' => $label ],
			'hierarchical'          => false,
			'show_ui'               => false,
			'query_var'             => true,
			'rewrite'               => false,
			'show_in_nav_menus'     => false,
			'update_count_callback' => '_wc_term_recount',
		] ) );
	}

	private static function taxonomy_type( $taxonomy ) {
		$id  = wc_attribute_taxonomy_id_by_name( $taxonomy );
		$tax = $id ? wc_get_attribute( $id ) : null;
		return $tax && ! empty( $tax->type ) ? $tax->type : 'select';
	}

	/** Existing term by id, else by name/slug, else created. */
	private static function resolve_term( $taxonomy, array $opt ) {
		$id = (int) ( $opt['id'] ?? 0 );
		if ( $id ) {
			$t = get_term( $id, $taxonomy );
			if ( $t && ! is_wp_error( $t ) ) {
				return $t;
			}
		}
		$name = sanitize_text_field( (string) ( $opt['name'] ?? '' ) );
		if ( '' === $name ) {
			return null;
		}
		$found = term_exists( $name, $taxonomy );
		if ( $found ) {
			$t = get_term( is_array( $found ) ? (int) $found['term_id'] : (int) $found, $taxonomy );
			if ( $t && ! is_wp_error( $t ) ) {
				return $t;
			}
		}
		$ins = wp_insert_term( $name, $taxonomy );
		if ( is_wp_error( $ins ) ) {
			return null;
		}
		$t = get_term( (int) $ins['term_id'], $taxonomy );
		return $t && ! is_wp_error( $t ) ? $t : null;
	}

	/** Swatch meta in the format the theme and the Variation Swatches plugin read. Empty values never erase. */
	private static function save_swatch_meta( $term_id, array $opt, $type ) {
		if ( 'color' === $type && ! empty( $opt['color'] ) ) {
			$c = sanitize_hex_color( (string) $opt['color'] );
			if ( $c ) {
				update_term_meta( $term_id, 'product_attribute_color', $c );
			}
		}
		if ( 'image' === $type && ! empty( $opt['image'] ) && wp_attachment_is_image( (int) $opt['image'] ) ) {
			update_term_meta( $term_id, 'product_attribute_image', (int) $opt['image'] );
		}
	}

	private static function fill_variation( WC_Product_Variation $v, array $d, array &$result ) {
		$v->set_regular_price( wc_format_decimal( (string) ( $d['regular_price'] ?? '' ) ) );
		$v->set_sale_price( wc_format_decimal( (string) ( $d['sale_price'] ?? '' ) ) );

		$sku = wc_clean( (string) ( $d['sku'] ?? '' ) );
		if ( '' === $sku || wc_product_has_unique_sku( $v->get_id(), $sku ) ) {
			try {
				$v->set_sku( $sku );
			} catch ( WC_Data_Exception $e ) {
				$result['notices'][] = $e->getMessage();
			}
		} else {
			/* translators: %s: SKU */
			$result['notices'][] = sprintf( __( 'SKU "%s" is already used by another product and was not applied.', 'whd-variations' ), $sku );
		}

		$stock = trim( (string) ( $d['stock'] ?? '' ) );
		if ( '' !== $stock && is_numeric( $stock ) ) {
			$v->set_manage_stock( true );
			$v->set_stock_quantity( (int) $stock );
			$v->set_stock_status( (int) $stock > 0 ? 'instock' : 'outofstock' );
		} else {
			$v->set_manage_stock( false );
			$v->set_stock_quantity( null );
			$v->set_stock_status( ! empty( $d['oos'] ) ? 'outofstock' : 'instock' );
		}

		$image = (int) ( $d['image'] ?? 0 );
		$v->set_image_id( $image && wp_attachment_is_image( $image ) ? $image : '' );
	}

	/** All option-key combinations across levels, in level order. */
	private static function cartesian( array $resolved ) {
		$rows = [ [] ];
		foreach ( $resolved as $lvl ) {
			$next = [];
			foreach ( $rows as $row ) {
				foreach ( array_keys( $lvl['options'] ) as $key ) {
					$next[] = array_merge( $row, [ $key ] );
				}
			}
			$rows = $next;
		}
		return $rows;
	}
}
