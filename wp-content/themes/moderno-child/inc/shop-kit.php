<?php
/**
 * The shop kit: one way to show a piece and put it in the bag from places that are not product
 * grids — the hotspots on the home page's photographs, the cards under a style video, "Wear it
 * with" on a product page, the "This or that" deck.
 *
 * A piece is described once, by omc_piece(): name, price, link, photo and — for a piece with sizes —
 * every combination that can actually be bought, so the size can be chosen where the piece is shown.
 * A page registers the pieces it uses with omc_pieces_use(); they are printed once, as JSON, for
 * assets/js/omc-shop.js, which draws the pickers and adds to the bag through WooCommerce's own AJAX
 * endpoint, then fires WooCommerce's `added_to_cart` so the cart drawer, the fragments and the Meta
 * pixel react exactly as they do to the theme's own buttons.
 *
 * Hotspots are stored as [ x, y, product ], x and y in percent of the photograph's own frame (not of
 * the box it is shown in): the photos are shown with object-fit: cover, cropped differently on every
 * screen, so the script works out where each point lands after the crop and hides any that fall off.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything the browser needs to show and sell one piece, or null when it cannot be shown.
 *
 * @return array|null id, name, url, price, image, stock, type, add (simple: buyable now),
 *                    choose (has an "any" combination: sizes are picked on the product page),
 *                    options [ id, label, attrs, stock ] for a piece with sizes.
 */
function omc_piece( $product ) {
	static $cache = [];
	$product = $product instanceof WC_Product ? $product : ( function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product ) : null );
	if ( ! $product || 'publish' !== $product->get_status() || ! $product->is_visible() ) {
		return null;
	}
	$id = $product->get_id();
	if ( isset( $cache[ $id ] ) ) {
		return $cache[ $id ];
	}
	$image = (int) $product->get_image_id();
	$piece = [
		'id'      => $id,
		'name'    => html_entity_decode( $product->get_name(), ENT_QUOTES, 'UTF-8' ),
		'url'     => $product->get_permalink(),
		// Decoded: get_price_html() writes the currency as an entity, and the script sets text, not HTML.
		'price'   => trim( html_entity_decode( wp_strip_all_tags( $product->get_price_html() ), ENT_QUOTES, 'UTF-8' ) ),
		'image'   => $image ? (string) wp_get_attachment_image_url( $image, 'woocommerce_thumbnail' ) : wc_placeholder_img_src(),
		'stock'   => $product->is_in_stock(),
		'type'    => $product->is_type( 'variable' ) ? 'variable' : ( $product->is_type( 'simple' ) ? 'simple' : 'other' ),
		'add'     => false,
		'choose'  => false,
		'options' => [],
	];
	if ( 'simple' === $piece['type'] ) {
		$piece['add'] = $product->is_purchasable() && $product->is_in_stock() && ! $product->is_sold_individually();
	} elseif ( 'variable' === $piece['type'] ) {
		foreach ( $product->get_children() as $child ) {
			$v = wc_get_product( $child );
			if ( ! $v || ! $v->variation_is_visible() ) {
				continue;
			}
			$attrs = [];
			foreach ( $v->get_variation_attributes() as $key => $value ) {
				if ( '' === (string) $value ) {
					// "Any size": the bag needs the size spelled out, which only the product page asks for.
					$piece['choose'] = true;
					continue 2;
				}
				$attrs[ preg_replace( '/^attribute_/', '', $key ) ] = (string) $value;
			}
			$piece['options'][] = [
				'id'    => $v->get_id(),
				'label' => str_replace( ', ', ' / ', wc_get_formatted_variation( $v, true, false, false ) ),
				'attrs' => $attrs,
				'stock' => $v->is_in_stock() && $v->is_purchasable(),
			];
		}
		if ( ! $piece['options'] ) {
			$piece['choose'] = true;
		}
	} else {
		$piece['choose'] = true; // grouped, external: the product page knows what to do with them
	}
	return $cache[ $id ] = $piece;
}

/** The pieces this page shows outside product grids: [ id => piece ]. */
function omc_pieces( $add = null ) {
	static $pieces = [];
	if ( is_array( $add ) ) {
		foreach ( $add as $id => $piece ) {
			$pieces[ $id ] = $piece;
		}
	}
	return $pieces;
}

/**
 * Register pieces for this page and load the kit.
 *
 * @param int[] $ids Product ids.
 * @return array The pieces that can be shown, [ id => piece ], in the order asked for.
 */
function omc_pieces_use( array $ids ) {
	$out = [];
	foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
		$piece = $id ? omc_piece( $id ) : null;
		if ( $piece ) {
			$out[ $id ] = $piece;
		}
	}
	if ( $out ) {
		omc_pieces( $out );
		omc_shop_assets();
	}
	return $out;
}

/** The kit's script and styles; safe to call any number of times, from the header or the footer. */
function omc_shop_assets() {
	$ver = static fn( $rel ) => file_exists( OMC_DIR . $rel ) ? (string) filemtime( OMC_DIR . $rel ) : OMC_VERSION;
	wp_enqueue_style( 'omc-shop', OMC_URI . '/assets/css/omc-shop.css', [ 'omc' ], $ver( '/assets/css/omc-shop.css' ) );
	wp_enqueue_script( 'omc-shop', OMC_URI . '/assets/js/omc-shop.js', [ 'jquery' ], $ver( '/assets/js/omc-shop.js' ), true );
}

/* The pieces, once, before the footer scripts print (they read this on load). */
add_action( 'wp_footer', static function () {
	$pieces = omc_pieces();
	if ( ! $pieces ) {
		return;
	}
	$data = [
		'pieces' => (object) $pieces,
		'ajax'   => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'add_to_cart' ) : '',
		'cart'   => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
		'i18n'   => [
			'add'      => __( 'Add to bag', 'moderno-child' ),
			'adding'   => __( 'Adding…', 'moderno-child' ),
			'added'    => __( 'In your bag', 'moderno-child' ),
			'choose'   => __( 'Choose your size', 'moderno-child' ),
			'options'  => __( 'Choose options', 'moderno-child' ),
			'size'     => __( 'Size', 'moderno-child' ),
			'soldOut'  => __( 'Sold out', 'moderno-child' ),
			'gone'     => __( 'sold out', 'moderno-child' ),
			'view'     => __( 'View the piece', 'moderno-child' ),
			'close'    => __( 'Close', 'moderno-child' ),
			'failed'   => __( 'That could not go in the bag. Open the piece and try there.', 'moderno-child' ),
			/* translators: %d: number of pieces */
			'outfit'   => __( 'Shop the look (%d)', 'moderno-child' ),
			'outfitH'  => __( 'Everything in this photo', 'moderno-child' ),
			'addAll'   => __( 'Add them all to the bag', 'moderno-child' ),
			'pickAll'  => __( 'Choose a size for each piece first.', 'moderno-child' ),
			/* translators: %s: piece name */
			'spot'     => __( '%s — see the piece', 'moderno-child' ),
		],
	];
	echo '<script id="omc-shop-data" type="application/json">' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}, 4 );

/* ─────────────────────────── hotspots ─────────────────────────── */

/**
 * Hotspots in their stored shape: [ [ x, y, product ] ], x/y 0–100 with one decimal, products that
 * exist. At most twelve on a photo; any more and the photo stops being a photo.
 */
function omc_spots_clean( $spots ) {
	$out = [];
	foreach ( array_slice( (array) $spots, 0, 12 ) as $s ) {
		if ( ! is_array( $s ) ) {
			continue;
		}
		$product = absint( $s['product'] ?? 0 );
		if ( ! $product || 'product' !== get_post_type( $product ) ) {
			continue;
		}
		$out[] = [
			'x'       => round( max( 0, min( 100, (float) ( $s['x'] ?? 0 ) ) ), 1 ),
			'y'       => round( max( 0, min( 100, (float) ( $s['y'] ?? 0 ) ) ), 1 ),
			'product' => $product,
		];
	}
	return $out;
}

/**
 * The hotspot layer for one photograph, to print inside the box that holds the <img>.
 *
 * @param array  $spots  Stored hotspots.
 * @param string $img    CSS selector, from the layer's parent, of the photo the points belong to.
 * @param array  $args   class (extra), index (which slide of a slideshow), pause (selector of a slideshow to pause).
 * @return string HTML, or '' when no point names a piece that can be shown.
 */
function omc_spots_layer( $spots, $img = 'img', $args = [] ) {
	$a      = wp_parse_args( $args, [ 'class' => '', 'index' => '', 'pause' => '' ] );
	$spots  = omc_spots_clean( $spots );
	$pieces = omc_pieces_use( wp_list_pluck( $spots, 'product' ) );
	$spots  = array_values( array_filter( $spots, static fn( $s ) => isset( $pieces[ $s['product'] ] ) ) );
	if ( ! $spots ) {
		return '';
	}
	$html = '<div class="omc-spots ' . esc_attr( $a['class'] ) . '" data-img="' . esc_attr( $img ) . '"'
		. ( '' !== $a['index'] ? ' data-index="' . (int) $a['index'] . '"' : '' )
		. ( $a['pause'] ? ' data-pause="' . esc_attr( $a['pause'] ) . '"' : '' ) . '>';
	foreach ( $spots as $s ) {
		$name  = $pieces[ $s['product'] ]['name'];
		$html .= '<button type="button" class="omc-spot" data-x="' . esc_attr( $s['x'] ) . '" data-y="' . esc_attr( $s['y'] ) . '" data-piece="' . (int) $s['product'] . '"'
			. ' aria-label="' . esc_attr( sprintf( /* translators: %s: piece name */ __( '%s — see the piece', 'moderno-child' ), $name ) ) . '" aria-haspopup="dialog">'
			. '<span class="omc-spot__dot" aria-hidden="true"></span></button>';
	}
	$ids = array_values( array_unique( wp_list_pluck( $spots, 'product' ) ) );
	if ( count( $ids ) > 1 ) {
		$html .= '<button type="button" class="omc-spots__outfit" data-outfit="' . esc_attr( implode( ',', $ids ) ) . '">'
			. omc_icon( 'hand' ) . '<span>' . esc_html( sprintf( /* translators: %d: number of pieces */ __( 'Shop the look (%d)', 'moderno-child' ), count( $ids ) ) ) . '</span></button>';
	}
	return $html . '</div>';
}

/* ─────────────────────────── finding a piece (admin pickers) ─────────────────────────── */

add_action( 'rest_api_init', static function () {
	register_rest_route( 'omc/v1', '/pieces', [
		'methods'             => 'GET',
		'permission_callback' => static fn() => current_user_can( 'edit_theme_options' ) || current_user_can( 'edit_pages' ),
		'callback'            => static function ( WP_REST_Request $r ) {
			if ( ! function_exists( 'wc_get_products' ) ) {
				return new WP_REST_Response( [], 200 );
			}
			$include = array_filter( array_map( 'absint', explode( ',', (string) $r->get_param( 'include' ) ) ) );
			$search  = sanitize_text_field( (string) $r->get_param( 'search' ) );
			$args    = [ 'status' => 'publish', 'limit' => 20, 'orderby' => 'date', 'order' => 'DESC', 'type' => [ 'simple', 'variable' ] ];
			if ( $include ) {
				$args['include'] = $include;
				$args['limit']   = count( $include );
			} elseif ( '' !== $search ) {
				$args['s'] = $search;
			}
			$out = [];
			foreach ( wc_get_products( $args ) as $p ) {
				$out[] = [
					'id'    => $p->get_id(),
					'name'  => html_entity_decode( $p->get_name(), ENT_QUOTES, 'UTF-8' ),
					'thumb' => (string) wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' ),
					'price' => trim( html_entity_decode( wp_strip_all_tags( $p->get_price_html() ), ENT_QUOTES, 'UTF-8' ) ),
					'stock' => $p->is_in_stock(),
				];
			}
			return new WP_REST_Response( $out, 200 );
		},
	] );
} );

/** Name, thumbnail and price of some products, for admin screens to paint without asking: [ id => brief ]. */
function omc_piece_brief( array $ids ) {
	$out = [];
	foreach ( array_unique( array_filter( array_map( 'intval', $ids ) ) ) as $id ) {
		$p = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
		if ( $p ) {
			$out[ $id ] = [
				'id'    => $id,
				'name'  => html_entity_decode( $p->get_name(), ENT_QUOTES, 'UTF-8' ),
				'thumb' => (string) wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' ),
				'price' => trim( html_entity_decode( wp_strip_all_tags( $p->get_price_html() ), ENT_QUOTES, 'UTF-8' ) ),
				'stock' => $p->is_in_stock(),
			];
		}
	}
	return $out;
}

/** The admin piece search (assets/js/omc-piece-picker.js) and its settings, for a screen that needs it. */
function omc_piece_picker_assets() {
	$ver = static fn( $rel ) => file_exists( OMC_DIR . $rel ) ? (string) filemtime( OMC_DIR . $rel ) : OMC_VERSION;
	wp_enqueue_style( 'omc-piece-picker', OMC_URI . '/assets/css/omc-piece-picker.css', [], $ver( '/assets/css/omc-piece-picker.css' ) );
	wp_enqueue_script( 'omc-piece-picker', OMC_URI . '/assets/js/omc-piece-picker.js', [], $ver( '/assets/js/omc-piece-picker.js' ), true );
	wp_localize_script( 'omc-piece-picker', 'OMC_PIECES_API', [
		'rest'  => esc_url_raw( rest_url( 'omc/v1/pieces' ) ),
		'nonce' => wp_create_nonce( 'wp_rest' ),
		'i18n'  => [
			'search'  => __( 'Search the shop…', 'moderno-child' ),
			'none'    => __( 'Nothing by that name.', 'moderno-child' ),
			'loading' => __( 'Looking…', 'moderno-child' ),
			'soldOut' => __( 'sold out', 'moderno-child' ),
			'close'   => __( 'Close', 'moderno-child' ),
		],
	] );
}
