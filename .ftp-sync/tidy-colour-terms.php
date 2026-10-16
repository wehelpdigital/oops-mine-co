<?php
/**
 * Tidy the colour attribute: merge the spellings, leave the shades alone.
 *
 * The shop has 74 colour terms because the catalogue import took supplier colour names verbatim.
 * Most of them are real — "Smoke Grey" and "Pale Grey" are different colours and a boutique is
 * entitled to say so. Two kinds of thing in there are not:
 *
 *   the same colour spelled two ways   Gray (15 variations) and Grey (22) are one colour, and a
 *                                      shopper filtering by grey currently sees half the rack.
 *   a typo in the display name         "WIne", "Ink Gray"
 *
 * Only those are touched, and only after checking that no product ends up with two variations
 * that mean the same thing. Everything else is printed as a question for a person: merging
 * "Classic Black" into "Black" would quietly erase a distinction somebody may have meant.
 *
 *   php .ftp-sync/tidy-colour-terms.php              what would change (the default)
 *   php .ftp-sync/tidy-colour-terms.php --apply      change it
 *   php .ftp-sync/tidy-colour-terms.php --live --apply   …on production (the owner runs this)
 */

$argv  = $_SERVER['argv'] ?? [];
$apply = in_array( '--apply', $argv, true );

/* `--live` is the same thing as OMC_DB=live, set here because PowerShell has no inline prefix. */
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

if ( ! taxonomy_exists( 'pa_colour' ) ) {
	exit( "There is no pa_colour attribute on this site.\n" );
}

/* ── the two kinds of tidying, written out rather than guessed ── */

/** Merges: every variation and product on the left moves to the right, then the left is deleted. */
const OMC_COLOUR_MERGE = [
	'gray' => 'grey',
];

/** Renames: the same term, spelled properly. */
const OMC_COLOUR_RENAME = [
	'ink-gray' => 'Ink Grey',
	'wine'     => 'Wine',
];

/* ── what the merge would do, and whether it is safe ── */

$changed = 0;

foreach ( OMC_COLOUR_MERGE as $from_slug => $to_slug ) {
	$from = get_term_by( 'slug', $from_slug, 'pa_colour' );
	$to   = get_term_by( 'slug', $to_slug, 'pa_colour' );

	if ( ! $from ) {
		printf( "  %-10s → %-10s nothing to merge, it is already gone\n", $from_slug, $to_slug );
		continue;
	}
	if ( ! $to ) {
		printf( "  %-10s → %-10s SKIPPED: there is no \"%s\" term to merge into\n", $from_slug, $to_slug, $to_slug );
		continue;
	}

	/* Every variation wearing the old spelling. */
	$variations = get_posts( [
		'post_type'      => 'product_variation',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_key'       => 'attribute_pa_colour', // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value'     => $from_slug,            // phpcs:ignore WordPress.DB.SlowDBQuery
	] );

	/* Would any product end up with the same colour and size twice? */
	$clash = [];
	foreach ( $variations as $vid ) {
		$parent = (int) get_post_field( 'post_parent', $vid );
		$size   = (string) get_post_meta( $vid, 'attribute_pa_size', true );
		foreach ( (array) get_posts( [
			'post_type'      => 'product_variation',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'post_parent'    => $parent,
			'fields'         => 'ids',
			'meta_key'       => 'attribute_pa_colour', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => $to_slug,              // phpcs:ignore WordPress.DB.SlowDBQuery
		] ) as $other ) {
			if ( (string) get_post_meta( $other, 'attribute_pa_size', true ) === $size ) {
				$clash[] = sprintf( '#%d (%s) already has %s / %s', $parent, get_the_title( $parent ), $to_slug, $size ?: 'no size' );
			}
		}
	}

	printf( "  %-10s → %-10s %d variation(s)\n", $from_slug, $to_slug, count( $variations ) );

	if ( $clash ) {
		echo "      SKIPPED, it would make duplicate variations:\n";
		foreach ( array_unique( $clash ) as $line ) {
			echo '        ' . $line . "\n";
		}
		continue;
	}

	$parents = [];
	foreach ( $variations as $vid ) {
		$parents[ (int) get_post_field( 'post_parent', $vid ) ] = true;
	}
	printf( "      across %d product(s), no duplicates would be made\n", count( $parents ) );

	if ( ! $apply ) {
		$changed += count( $variations );
		continue;
	}

	/* The variations themselves. */
	foreach ( $variations as $vid ) {
		update_post_meta( $vid, 'attribute_pa_colour', $to_slug );

		/* The generated line that restates the attributes, so it stops saying the old word. */
		$excerpt = (string) get_post_field( 'post_excerpt', $vid );
		if ( '' !== $excerpt && false !== stripos( $excerpt, $from->name ) ) {
			wp_update_post( [
				'ID'           => $vid,
				'post_excerpt' => str_ireplace( $from->name, $to->name, $excerpt ),
			] );
		}
		$changed++;
	}

	/* The parent products: they list which colours they come in. */
	foreach ( array_keys( $parents ) as $product_id ) {
		wp_remove_object_terms( $product_id, $from->term_id, 'pa_colour' );
		wp_set_object_terms( $product_id, $to->term_id, 'pa_colour', true );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}
	}

	/* Anything else still pointing at it — a product with the term but no variation using it. */
	foreach ( (array) get_objects_in_term( $from->term_id, 'pa_colour' ) as $object_id ) {
		wp_remove_object_terms( (int) $object_id, $from->term_id, 'pa_colour' );
		wp_set_object_terms( (int) $object_id, $to->term_id, 'pa_colour', true );
	}

	wp_delete_term( $from->term_id, 'pa_colour' );
	printf( "      merged, and \"%s\" removed\n", $from->name );
}

/* ── the typos ── */

echo "\n── spelling ──\n";
foreach ( OMC_COLOUR_RENAME as $slug => $name ) {
	$term = get_term_by( 'slug', $slug, 'pa_colour' );
	if ( ! $term ) {
		printf( "  %-12s not here\n", $slug );
		continue;
	}
	if ( $term->name === $name ) {
		printf( "  %-12s already \"%s\"\n", $slug, $name );
		continue;
	}
	printf( "  %-12s \"%s\" → \"%s\"\n", $slug, $term->name, $name );
	if ( $apply ) {
		wp_update_term( $term->term_id, 'pa_colour', [ 'name' => $name ] );
		$changed++;
	}
}

/* ── the ones a person has to decide ── */

echo "\n── not touched, because only you know ──\n";

$ingredients = [];
$singles     = [];
foreach ( (array) get_terms( [ 'taxonomy' => 'pa_colour', 'hide_empty' => false ] ) as $term ) {
	if ( ! is_object( $term ) ) {
		continue;
	}
	$used = (int) count( (array) get_posts( [
		'post_type'      => 'product_variation',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_key'       => 'attribute_pa_colour', // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value'     => $term->slug,           // phpcs:ignore WordPress.DB.SlowDBQuery
	] ) );
	if ( in_array( $term->slug, [ 'arbutin', 'cica', 'hyaluronic-acid', 'tea-tree', 'vitamin', 'retinol', 'niacinamide' ], true ) ) {
		$ingredients[] = sprintf( '%s (%d)', $term->name, $used );
	} elseif ( 1 === $used ) {
		$singles[] = $term->name;
	}
}

if ( $ingredients ) {
	echo "  These are in the Colour attribute but they are not colours — they are what is in the\n";
	echo "  bottle. A shopper filtering by colour sees them in the list:\n    " . implode( ', ', $ingredients ) . "\n";
	echo "  Moving them needs a second attribute (Formula, say), which is a decision, not a tidy-up.\n\n";
}
if ( $singles ) {
	printf( "  %d colours are used by exactly one variation — supplier names taken verbatim:\n", count( $singles ) );
	echo '    ' . implode( ', ', array_slice( $singles, 0, 18 ) ) . ( count( $singles ) > 18 ? ', …' : '' ) . "\n";
	echo "  Some are real shades. Merging any of them into a plain colour would erase a distinction\n";
	echo "  somebody may have meant, so none were.\n";
}

echo "\n";
if ( $apply ) {
	if ( function_exists( 'delete_transient' ) ) {
		delete_transient( 'wc_attribute_taxonomies' );
	}
	printf( "%d change(s) made.\n", $changed );
} else {
	printf( "%d change(s) would be made. Add --apply to make them.\n", $changed );
}
