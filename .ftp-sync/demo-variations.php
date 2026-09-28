<?php
/**
 * Give the demo products real variations built from the owner's variant tables, so the store shows
 * how the tier picker behaves before the real catalogue arrives.
 *
 *   php .ftp-sync/demo-variations.php [--dry-run] [--limit=N] [--only=<slug>]
 *   OMC_DB=live php .ftp-sync/demo-variations.php        (production write — the owner runs this)
 *
 * Each product's levels come from its category preset (option `whdv_presets`, written from
 * catalog.json). A dress gets Colour, Size and Length; a matching set gets Colour, Size and Set
 * Option; a Limited Find gets none, because a one-of-a-kind piece has nothing to choose.
 *
 * Idempotent: it hands the same state to WHDV_Model::apply() every run, and that method makes the
 * variations equal the enabled combinations rather than appending to them.
 *
 * @package oopsmine-tools
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "CLI only.\n" );
}

$argv    = $_SERVER['argv'];
$dry_run = in_array( '--dry-run', $argv, true );
$limit   = 0;
$only    = '';
foreach ( $argv as $a ) {
	if ( 0 === strpos( $a, '--limit=' ) ) {
		$limit = (int) substr( $a, 8 );
	}
	if ( 0 === strpos( $a, '--only=' ) ) {
		$only = substr( $a, 7 );
	}
}

$_SERVER['HTTP_HOST'] = getenv( 'OMC_DB' ) === 'live' ? 'oopsmineco.com' : 'oopsmine.test';
require dirname( __DIR__ ) . '/wp-load.php';

if ( ! class_exists( 'WHDV_Model' ) ) {
	exit( "The WHD — Variation Tiers plugin is not active.\n" );
}
$admin = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ] );
if ( $admin ) {
	wp_set_current_user( $admin[0]->ID );
}

/*
 * How many options to use per level. A demo wants to look real, not to mint a thousand rows: three
 * colours by three sizes is enough to show the picker stepping through, and keeps a 64-product
 * catalogue at roughly ten variations each instead of forty.
 */
$take = [
	'pa_color'      => 3,
	'pa_size'       => 3,
	'pa_length'     => 2,
	'pa_set-option' => 3,
	'pa_fit'        => 2,
	'pa_material'   => 2,
	'pa_pattern'    => 2,
	'pa_cut'        => 2,
	'pa_bundle'     => 2,
	'pa_shoe-size'  => 3,
];

/* Sizes read S, M, L rather than the first three alphabetically. */
$prefer = [
	'pa_size'       => [ 'S', 'M', 'L', 'XL', 'XS', '2XL' ],
	'pa_length'     => [ 'Regular', 'Long', 'Short' ],
	'pa_set-option' => [ 'Full Set', 'Top only', 'Pants only' ],
	'pa_color'      => [ 'Black', 'Cream', 'Mocha', 'Olive' ],
];

/** The options for one level, trimmed to a demo-sized set and ordered the way a shopper reads them. */
function omcv_level( $taxonomy, array $take, array $prefer ) {
	$terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
	if ( is_wp_error( $terms ) || ! $terms ) {
		return null;
	}
	$order = $prefer[ $taxonomy ] ?? [];
	usort( $terms, static function ( $a, $b ) use ( $order ) {
		$ia = array_search( $a->name, $order, true );
		$ib = array_search( $b->name, $order, true );
		$ia = ( false === $ia ) ? 99 : $ia;
		$ib = ( false === $ib ) ? 99 : $ib;
		return $ia === $ib ? strcmp( $a->name, $b->name ) : $ia - $ib;
	} );
	$terms = array_slice( $terms, 0, $take[ $taxonomy ] ?? 3 );

	$tax_obj = wc_get_attribute( wc_attribute_taxonomy_id_by_name( str_replace( 'pa_', '', $taxonomy ) ) );
	return [
		'taxonomy' => $taxonomy,
		'label'    => $tax_obj ? $tax_obj->name : $taxonomy,
		'type'     => $tax_obj ? $tax_obj->type : 'select',
		'new'      => false,
		'options'  => array_map( static function ( $t ) {
			return [
				'key'   => $t->slug,
				'id'    => (int) $t->term_id,
				'name'  => $t->name,
				'slug'  => $t->slug,
				'color' => (string) get_term_meta( $t->term_id, 'product_attribute_color', true ),
				'image' => 0,
			];
		}, $terms ),
	];
}

/** Every combination of the level options, as "key|key|key". */
function omcv_combos( array $levels ) {
	$rows = [ [] ];
	foreach ( $levels as $level ) {
		$next = [];
		foreach ( $rows as $row ) {
			foreach ( $level['options'] as $option ) {
				$next[] = array_merge( $row, [ $option['key'] ] );
			}
		}
		$rows = $next;
	}
	return array_map( static function ( $row ) {
		return implode( '|', $row );
	}, $rows );
}

$products = get_posts( [
	'post_type'   => 'product',
	'numberposts' => $limit ?: -1,
	'post_status' => 'publish',
	'orderby'     => 'ID',
	'order'       => 'ASC',
] );

$log = [ 'db' => home_url(), 'dry_run' => $dry_run, 'products' => 0, 'variations' => 0, 'skipped' => [], 'errors' => [] ];

foreach ( $products as $post ) {
	$product = wc_get_product( $post->ID );
	if ( ! $product ) {
		continue;
	}
	if ( $only && ! has_term( $only, 'product_cat', $post->ID ) ) {
		continue;
	}

	$preset = WHDV_Model::preset_for_product( $post->ID );
	if ( null === $preset || ! $preset ) {
		// A Limited Find, or a category that asks for no variants: leave it simple.
		$log['skipped'][ $post->post_title ] = 'no preset (one-of-a-kind)';
		continue;
	}

	$levels = [];
	foreach ( $preset as $taxonomy ) {
		$level = omcv_level( $taxonomy, $take, $prefer );
		if ( $level ) {
			$levels[] = $level;
		}
	}
	if ( ! $levels ) {
		$log['skipped'][ $post->post_title ] = 'no terms on the preset attributes';
		continue;
	}

	/*
	 * Price every combination the same as the product itself, so the demo keeps the price it already
	 * shows instead of inventing a range. Stock is a round number: this is a display exercise.
	 */
	$price = $product->get_regular_price() ?: $product->get_price();
	$price = $price ?: '';

	$combos = [];
	foreach ( omcv_combos( $levels ) as $key ) {
		$combos[ $key ] = [
			'on'            => true,
			'sku'           => '',
			'regular_price' => (string) $price,
			'sale_price'    => '',
			'stock'         => 10,
			'oos'           => false,
			'image'         => 0,
		];
	}

	$state = [ 'levels' => $levels, 'combos' => $combos, 'groups' => [], 'dirty' => true ];

	printf(
		"%-52s %s => %d combos\n",
		mb_substr( $post->post_title, 0, 50 ),
		implode( ' / ', array_column( $levels, 'label' ) ),
		count( $combos )
	);

	if ( $dry_run ) {
		$log['products']++;
		$log['variations'] += count( $combos );
		continue;
	}

	$result = WHDV_Model::apply( $post->ID, $state );
	if ( empty( $result['ok'] ) && ! empty( $result['notices'] ) ) {
		$log['errors'][ $post->post_title ] = $result['notices'];
		continue;
	}
	$log['products']++;
	$log['variations'] += (int) $result['created'] + (int) $result['updated'];
}

if ( ! $dry_run ) {
	wc_delete_product_transients();
}

$log['skipped'] = count( $log['skipped'] );
echo json_encode( $log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
