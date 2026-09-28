<?php
/**
 * Clear every garment-type category assignment, so the sort rules in catalog.json re-file the whole
 * catalogue from scratch on the next publish run.
 *
 *   php .ftp-sync/reset-product-types.php
 *   OMC_DB=live php .ftp-sync/reset-product-types.php   (production write)
 *   …then run publish-site.php, which does the re-sorting.
 *
 * Needed when a sort rule changes: publish-site only ADDS a product to a category, it never takes
 * one away, so a product mis-filed by an older rule would otherwise stay where it was put. Only the
 * twelve Clothing types are touched — the Clothing parent and New & Now are left alone.
 *
 * @package oopsmine-tools
 */
$_SERVER['HTTP_HOST'] = getenv( 'OMC_DB' ) === 'live' ? 'oopsmineco.com' : 'oopsmine.test';
require dirname( __DIR__ ) . '/wp-load.php';

global $wpdb;
$slugs = [
	'tops', 'blouses', 'tees', 'sweaters-knits', 'dresses', 'skirts',
	'pants-trousers', 'jeans', 'shorts', 'jackets-outerwear',
	'matching-sets', 'rompers-jumpsuits',
];
$cleared = 0;
foreach ( $slugs as $slug ) {
	$term = get_term_by( 'slug', $slug, 'product_cat' );
	if ( ! $term ) {
		continue;
	}
	$cleared += (int) $wpdb->delete( $wpdb->term_relationships, [ 'term_taxonomy_id' => $term->term_taxonomy_id ] );
	wp_update_term_count_now( [ $term->term_id ], 'product_cat' );
}
echo "cleared {$cleared} type assignments on " . home_url() . "\n";
