<?php
/**
 * Give every product the story that sits between its tabs and the community notes.
 *
 *   php .ftp-sync/import-stories.php [--dry-run]
 *   php .ftp-sync/import-stories.php --live        (production write — the site owner runs this)
 *
 * WHD renders `_whd_story` as alternating spreads: a heading opens a row, the text under it is the
 * copy, the picture in it goes to the figure, and rows flip sides as they go down the page. Without
 * one, that band of the product page is empty — which is what it has been for every product in the
 * shop.
 *
 * Two rows each. The first is written per product in content/stories.json: why this one was bought,
 * in the shop's own words, which is the thing a description cannot say. The second is built from the
 * catalogue — sizes, colours, and the measurements out of the description — because a list of facts
 * next to a photograph answers a different question from a paragraph, and nobody has to write it.
 *
 * Idempotent: it overwrites each product's story and leaves everything else alone.
 */

if ( in_array( '--live', $argv, true ) ) {
	putenv( 'OMC_DB=live' );
}
$live = 'live' === getenv( 'OMC_DB' );
$_SERVER['HTTP_HOST']   = $live ? 'oopsmineco.com' : 'oopsmine.test';
$_SERVER['REQUEST_URI'] = '/';

require dirname( __DIR__ ) . '/wp-load.php';

if ( ! class_exists( 'WHD_Product_Story' ) ) {
	exit( "The WHD plugin is not active here.\n" );
}

$dry = in_array( '--dry-run', $argv, true );

$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ] );
if ( $admins ) {
	wp_set_current_user( $admins[0]->ID );
}

$plan    = json_decode( (string) file_get_contents( __DIR__ . '/content/catalog-2026.json' ), true );
$stories = json_decode( (string) file_get_contents( __DIR__ . '/content/stories.json' ), true );
if ( ! is_array( $plan ) || ! is_array( $stories ) ) {
	exit( "catalog-2026.json or stories.json is not readable JSON\n" );
}

printf( "%s  →  %s%s\n\n", $live ? 'LIVE database' : 'local database', home_url( '/' ), $dry ? '   (dry run)' : '' );

/**
 * The sentence in a description that carries the measurements.
 *
 * They are written to a pattern — "Chest 44–56 inches, length 23 inches." — so one pass over the
 * sentences finds it. A product without one simply does not get that line.
 */
function omc_story_measurements( $description ) {
	// Tags out first, with a space in their place: the description carries <h3> headings now, and
	// splitting the raw HTML leaves "How it fits" glued to the sentence after it.
	$plain = preg_replace( '#<h3>.*?</h3>#s', ' ', (string) $description );   // headings have no full stop, so they glue to the sentence after them
	$plain = trim( preg_replace( '/\s+/', ' ', strip_tags( str_replace( '<', ' <', $plain ) ) ) );
	foreach ( preg_split( '/(?<=[.!?])\s+/', $plain ) as $sentence ) {
		if ( preg_match( '/\b(inches|inch)\b/i', $sentence ) && preg_match( '/\b(chest|length|waist|rise|inseam|bust|hip)\b/i', $sentence ) ) {
			return trim( $sentence );
		}
	}

	return '';
}

/** A heading, a paragraph and a picture: one row of the story. */
function omc_story_row( $heading, $html, $image ) {
	$blocks = [
		[ 'type' => 'heading', 'props' => [ 'text' => $heading, 'level' => 'h3', 'align' => 'left' ] ],
		[ 'type' => 'text', 'props' => [ 'text' => $html, 'align' => 'left', 'size' => 16 ] ],
	];
	if ( $image ) {
		$blocks[] = [ 'type' => 'image', 'props' => [ 'url' => $image['url'], 'alt' => $image['alt'], 'width' => 100 ] ];
	}

	return $blocks;
}

$done    = 0;
$skipped = [];
foreach ( (array) $plan['products'] as $p ) {
	$slug = $p['slug'];
	$post = get_page_by_path( $slug, OBJECT, 'product' );
	if ( ! $post ) {
		$skipped[] = "$slug (no such product)";
		continue;
	}
	$story = $stories[ $slug ] ?? null;
	if ( ! $story || empty( $story['lead'] ) ) {
		$skipped[] = "$slug (nothing written)";
		continue;
	}

	$product = wc_get_product( $post->ID );
	$gallery = array_merge( [ (int) $product->get_image_id() ], $product->get_gallery_image_ids() );
	$gallery = array_values( array_filter( array_map( 'intval', $gallery ) ) );

	$picture = function ( $i ) use ( $gallery, $p ) {
		if ( empty( $gallery[ $i ] ) ) {
			return null;
		}
		$url = wp_get_attachment_image_url( $gallery[ $i ], 'large' );

		return $url ? [ 'url' => $url, 'alt' => get_post_meta( $gallery[ $i ], '_wp_attachment_image_alt', true ) ?: $p['title'] ] : null;
	};

	/* ── the facts row, assembled rather than written ── */
	$facts = [];
	$sizes = array_values( (array) ( $p['sizes'] ?? [] ) );
	if ( $sizes && [ 'One Size' ] !== $sizes ) {
		$facts[] = sprintf( '<strong>Sizes</strong> %s', implode( ', ', $sizes ) );
	} elseif ( [ 'One Size' ] === $sizes ) {
		$facts[] = '<strong>Size</strong> One size';
	}
	$colours = array_values( array_filter( array_column( (array) ( $p['colours'] ?? [] ), 'name' ) ) );
	if ( $colours ) {
		$facts[] = sprintf( '<strong>Colours</strong> %s', implode( ', ', $colours ) );
	}
	$measure = omc_story_measurements( $p['description'] ?? '' );
	if ( $measure ) {
		$facts[] = sprintf( '<strong>Measurements</strong> %s', rtrim( $measure, '.' ) );
	}
	if ( ! empty( $story['note'] ) ) {
		$facts[] = $story['note'];
	}

	$blocks = omc_story_row(
		$story['heading'] ?? 'Why we took this one',
		wpautop( $story['lead'] ),
		$picture( 0 )
	);
	// The styling paragraph lives here and nowhere else, so the page does not say it twice.
	if ( ! empty( $story['wear'] ) ) {
		$blocks = array_merge( $blocks, omc_story_row(
			$story['wear_heading'] ?? 'Wearing it',
			wpautop( $story['wear'] ),
			$picture( 1 )
		) );
	}
	if ( $facts ) {
		$blocks = array_merge( $blocks, omc_story_row(
			$story['facts_heading'] ?? 'The particulars',
			'<p>' . implode( '<br>', $facts ) . '</p>',
			$picture( empty( $story['wear'] ) ? 1 : 2 )
		) );
	}

	if ( $dry ) {
		printf( "   %-52s %d block(s), %d picture(s)\n", $slug, count( $blocks ), count( array_filter( [ $picture( 0 ), $picture( 1 ) ] ) ) );
		$done++;
		continue;
	}

	WHD_Product_Story::save( $post->ID, [ 'settings' => [], 'blocks' => $blocks ] );
	delete_post_meta( $post->ID, WHD_Product_Story::META . '_off' );   // on, which is the default once a story exists
	$done++;
	printf( "   #%-6d %-52s %d block(s)\n", $post->ID, $slug, count( $blocks ) );
}

printf( "\n%d story(ies) %s, %d skipped\n", $done, $dry ? 'planned' : 'written', count( $skipped ) );
foreach ( array_slice( $skipped, 0, 12 ) as $s ) {
	echo "   $s\n";
}
