<?php
/**
 * Make every variation reachable from the product page.
 *
 * A variable product offers the options that are attached to it as attribute terms. Its
 * variations are a separate thing, and the catalogue import left the two out of step: one
 * sweater has nineteen variations across five sizes and four colours, but only "One Size" is
 * attached to it — so fifteen of them cannot be chosen, and a shopper who picks cream is offered
 * no size at all.
 *
 * This only ever *adds* the terms the variations already use. Nothing is deleted and no variation
 * is touched: a variation that exists becomes selectable, and a product that was already correct
 * is left alone. The reverse repair — deleting the odd variation instead — destroys data, so it
 * is not done here; the run prints the products that look odd afterwards so a person can decide.
 *
 *   php .ftp-sync/fix-unreachable-variations.php              what would change
 *   php .ftp-sync/fix-unreachable-variations.php --apply      change it
 *   php .ftp-sync/fix-unreachable-variations.php --live --apply   …on production
 */

$argv  = $_SERVER['argv'] ?? [];
$apply = in_array( '--apply', $argv, true );

if ( in_array( '--live', $argv, true ) ) {
	putenv( 'OMC_DB=live' );
}

$_SERVER['HTTP_HOST'] = getenv( 'OMC_DB' ) === 'live' ? 'oopsmineco.com' : 'oopsmine.test';
require __DIR__ . '/../wp-load.php';

printf(
	"%s  →  %s   (%s)\n\n",
	getenv( 'OMC_DB' ) === 'live' ? 'LIVE database' : 'local database',
	home_url( '/' ),
	$apply ? 'applying' : 'dry run'
);

if ( ! function_exists( 'wc_get_product' ) ) {
	exit( "WooCommerce is not active.\n" );
}

$products = get_posts( [
	'post_type'      => 'product',
	'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
	'posts_per_page' => -1,
	'fields'         => 'ids',
] );

$looked  = 0;
$broken  = 0;
$added   = 0;
$mixed   = [];

foreach ( $products as $product_id ) {
	$product = wc_get_product( $product_id );
	if ( ! $product || ! $product->is_type( 'variable' ) ) {
		continue;
	}
	$looked++;

	/* Which terms the variations actually use, per attribute. */
	$used = [];
	foreach ( $product->get_children() as $variation_id ) {
		$variation = wc_get_product( $variation_id );
		if ( ! $variation ) {
			continue;
		}
		foreach ( $variation->get_attributes() as $taxonomy => $slug ) {
			if ( '' === $slug || 0 !== strpos( $taxonomy, 'pa_' ) ) {
				continue; // "Any…", or a per-product attribute this does not govern
			}
			$used[ $taxonomy ][ $slug ] = true;
		}
	}
	if ( ! $used ) {
		continue;
	}

	$missing = [];
	foreach ( $used as $taxonomy => $slugs ) {
		$have = wp_get_post_terms( $product_id, $taxonomy, [ 'fields' => 'slugs' ] );
		$have = is_wp_error( $have ) ? [] : $have;
		$gap  = array_diff( array_keys( $slugs ), $have );
		if ( $gap ) {
			$missing[ $taxonomy ] = array_values( $gap );
		}
	}

	/* A size list holding both "one size" and real sizes is a question, not a fault. */
	$sizes = array_keys( $used['pa_size'] ?? [] );
	if ( count( $sizes ) > 1 && in_array( 'one-size', $sizes, true ) ) {
		$mixed[] = sprintf( '#%d %s', $product_id, get_the_title( $product_id ) );
	}

	if ( ! $missing ) {
		continue;
	}
	$broken++;

	$reachable = count( $product->get_children() );
	printf( "  #%-6d %-42s %d variation(s)\n", $product_id, mb_substr( get_the_title( $product_id ), 0, 40 ), $reachable );
	foreach ( $missing as $taxonomy => $slugs ) {
		printf( "      %-12s missing: %s\n", $taxonomy, implode( ', ', $slugs ) );
		if ( ! $apply ) {
			$added += count( $slugs );
			continue;
		}
		$ids = [];
		foreach ( $slugs as $slug ) {
			$term = get_term_by( 'slug', $slug, $taxonomy );
			if ( $term ) {
				$ids[] = (int) $term->term_id;
			}
		}
		if ( $ids ) {
			wp_set_object_terms( $product_id, $ids, $taxonomy, true );
			$added += count( $ids );
		}
	}

	if ( $apply ) {
		/* The attribute has to stay marked as one the variations are built from. */
		$attributes = $product->get_attributes();
		foreach ( array_keys( $missing ) as $taxonomy ) {
			if ( isset( $attributes[ $taxonomy ] ) ) {
				$attributes[ $taxonomy ]->set_options( wp_get_post_terms( $product_id, $taxonomy, [ 'fields' => 'ids' ] ) );
				$attributes[ $taxonomy ]->set_variation( true );
				$attributes[ $taxonomy ]->set_visible( true );
			}
		}
		$product->set_attributes( $attributes );
		$product->save();
		wc_delete_product_transients( $product_id );
	}
}

printf( "\n%d variable product(s) looked at, %d with variations nobody could reach.\n", $looked, $broken );
printf( "%d option(s) %s.\n", $added, $apply ? 'added' : 'would be added' );

if ( $mixed ) {
	printf( "\n── worth a look, not touched ──\n%d product(s) offer \"one size\" alongside real sizes:\n", count( $mixed ) );
	foreach ( $mixed as $line ) {
		echo '  ' . $line . "\n";
	}
	echo "That is usually two different items merged under one product by the import. Deleting the\n";
	echo "odd variation is the fix, and deleting is yours to decide.\n";
}

if ( ! $apply && $added ) {
	echo "\nAdd --apply to make them selectable.\n";
}
