<?php
/**
 * Alt text, titles and descriptions for the media library — and weight off the heavy files.
 *
 * Three jobs, each switchable, none of which invents anything:
 *
 *   --text      alt, title and description for every image that is missing one, written from the
 *               thing the picture belongs to (product name and category, or the page it sits on)
 *               plus the closest phrase from the keyword list.
 *   --compress  re-encode anything over the weight limit: 1600px on the long edge, quality 82,
 *               same format in and out. Thumbnails are rebuilt. Only kept if it actually shrank.
 *   --report    say what would change and stop. Implied by --dry-run.
 *
 * What it leaves alone, on purpose:
 *
 *   - captions. A caption is printed under the picture on the page; the other three are not. A
 *     caption written by a script would be visible copy nobody wrote.
 *   - the parent theme's demo pictures (moderno-*.jpg, attached to nothing, shown nowhere) and
 *     the favicon and WooCommerce placeholder. SEO text for a file no page loads is noise.
 *   - anything that already has the field. Nothing here overwrites a human.
 *
 * Usage:
 *   php .ftp-sync/fix-image-seo.php --dry-run
 *   php .ftp-sync/fix-image-seo.php --text
 *   php .ftp-sync/fix-image-seo.php --compress --limit=20
 *   php .ftp-sync/fix-image-seo.php --live --text        (production write — the owner runs this)
 */

$argv = $_SERVER['argv'] ?? [];
$flag = static function ( $name ) use ( $argv ) {
	return in_array( '--' . $name, $argv, true );
};
$value = static function ( $name, $default = 0 ) use ( $argv ) {
	foreach ( $argv as $a ) {
		if ( 0 === strpos( $a, '--' . $name . '=' ) ) {
			return substr( $a, strlen( $name ) + 3 );
		}
	}
	return $default;
};

$dry      = $flag( 'dry-run' ) || $flag( 'report' );
$do_text  = $flag( 'text' ) || $dry || ( ! $flag( 'compress' ) );
$do_shrink = $flag( 'compress' ) || $dry;
$limit    = (int) $value( 'limit', 0 );

/* The long edge and the weight above which a picture is worth re-encoding. A product photograph
   at 700 KB costs a shopper on a phone more than the detail is worth. */
const OMC_IMG_MAX_EDGE = 1600;
const OMC_IMG_QUALITY  = 82;
const OMC_IMG_HEAVY    = 300 * 1024;

/*
 * `--live` is the same thing as OMC_DB=live, set here rather than by the caller.
 *
 * The environment-variable form is bash syntax; PowerShell has no inline prefix for it and
 * fails before PHP is reached, and setting it by hand leaves it set for everything else typed
 * into that window afterwards. This has to happen before wp-config.php is read.
 */
if ( in_array( '--live', $argv, true ) ) {
	putenv( 'OMC_DB=live' );
}

$_SERVER['HTTP_HOST'] = getenv( 'OMC_DB' ) === 'live' ? 'oopsmineco.com' : 'oopsmine.test';
require __DIR__ . '/../wp-load.php';

/* Say out loud which database this is about to write to. */
printf(
	"%s  →  %s

",
	getenv( 'OMC_DB' ) === 'live' ? 'LIVE database' : 'local database',
	home_url( '/' )
);
require_once ABSPATH . 'wp-admin/includes/image.php';

/* ─────────────────────────── what a picture is of ─────────────────────────── */

/** Pictures the site never shows: the theme's demo set, the favicon, the placeholder. */
function omc_img_is_furniture( $id, $file ) {
	$name = basename( (string) $file );
	if ( preg_match( '/^moderno-\d+\./', $name ) ) {
		return true;
	}
	if ( preg_match( '/(favicon|woocommerce-placeholder|placeholder)/i', $name ) ) {
		return true;
	}

	return false;
}

/**
 * The post a picture belongs to: its parent, or whoever uses it as a thumbnail or in a gallery.
 *
 * @return array{0:int,1:string} post id and how it is used
 */
function omc_img_owner( $id, array $index ) {
	$parent = (int) get_post( $id )->post_parent;
	if ( $parent && in_array( get_post_type( $parent ), [ 'post', 'page', 'product' ], true )
		&& 'publish' === get_post_status( $parent ) ) {
		return [ $parent, 'attached' ];
	}
	if ( isset( $index[ $id ] ) ) {
		return $index[ $id ];
	}

	return [ 0, 'none' ];
}

/** Every picture a published post uses, so an unattached one can still be placed. */
function omc_img_index() {
	$index = [];
	foreach ( get_posts( [ 'post_type' => [ 'post', 'page', 'product' ], 'post_status' => 'publish', 'posts_per_page' => -1 ] ) as $p ) {
		$thumb = (int) get_post_thumbnail_id( $p );
		if ( $thumb && ! isset( $index[ $thumb ] ) ) {
			$index[ $thumb ] = [ $p->ID, 'featured' ];
		}
		foreach ( array_filter( array_map( 'intval', explode( ',', (string) get_post_meta( $p->ID, '_product_image_gallery', true ) ) ) ) as $g ) {
			if ( ! isset( $index[ $g ] ) ) {
				$index[ $g ] = [ $p->ID, 'gallery' ];
			}
		}
	}

	return $index;
}

/* ─────────────────────────── the closest phrase we own ─────────────────────────── */

/** The keyword list, lowercased, with its word sets precomputed. */
function omc_img_keywords() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$cache = [];
	if ( ! class_exists( 'WHD_AI_Keywords' ) ) {
		return $cache;
	}
	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT keyword, volume FROM ' . WHD_AI_Keywords::table(), ARRAY_A );
	foreach ( (array) $rows as $row ) {
		$words = omc_img_words( $row['keyword'] );
		if ( $words ) {
			$cache[] = [ 'keyword' => $row['keyword'], 'volume' => (int) $row['volume'], 'words' => $words ];
		}
	}

	return $cache;
}

/** Words worth matching on: lowercase, no punctuation, nothing too short or too common. */
function omc_img_words( $text ) {
	static $stop = [ 'the', 'and', 'for', 'with', 'from', 'that', 'this', 'your', 'you', 'are',
		'what', 'how', 'who', 'its', 'our', 'out', 'can', 'all', 'one', 'two', 'has', 'have', 'into',
		'over', 'under', 'than', 'then', 'them', 'they', 'will', 'more', 'most', 'some', 'size' ];
	$text  = mb_strtolower( wp_strip_all_tags( (string) $text ) );
	$words = preg_split( '/[^a-z0-9]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
	$out   = [];
	foreach ( (array) $words as $w ) {
		if ( mb_strlen( $w ) < 3 || in_array( $w, $stop, true ) ) {
			continue;
		}
		$out[ rtrim( $w, 's' ) ] = true; // fold the plural, so "skirts" meets "skirt"
	}

	return $out;
}

/**
 * The keyword this picture's context is actually about.
 *
 * Scored on shared words, and a phrase has to share more than one of them before it counts — one
 * word in common is how "cute clothes" ends up on a photograph of a trench coat.
 */
function omc_img_keyword( $context, array $prefer = [] ) {
	$words = omc_img_words( $context );
	if ( ! $words ) {
		return '';
	}

	$best  = '';
	$score = 0;

	/* a keyword the page itself was written for wins, if it fits at all */
	foreach ( $prefer as $own ) {
		$shared = count( array_intersect_key( omc_img_words( $own ), $words ) );
		if ( $shared > $score ) {
			$score = $shared;
			$best  = $own;
		}
	}
	if ( $score >= 2 ) {
		return $best;
	}

	foreach ( omc_img_keywords() as $row ) {
		$shared = count( array_intersect_key( $row['words'], $words ) );
		if ( $shared < 2 || $shared < count( $row['words'] ) * 0.6 ) {
			continue; // most of the phrase has to be there, not just a word of it
		}
		if ( $shared > $score || ( $shared === $score && $row['volume'] > 0 && '' === $best ) ) {
			$score = $shared;
			$best  = $row['keyword'];
		}
	}

	return $score >= 2 ? $best : '';
}

/* ─────────────────────────── the words themselves ─────────────────────────── */

/** Trim to a length without cutting a word in half. */
function omc_img_clip( $text, $max ) {
	$text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
	$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
	if ( mb_strlen( $text ) <= $max ) {
		return $text;
	}
	$cut   = mb_substr( $text, 0, $max );
	$space = mb_strrpos( $cut, ' ' );

	return rtrim( false !== $space && $space > 20 ? mb_substr( $cut, 0, $space ) : $cut, ' ,;:–—-' );
}

/**
 * What to say about one picture.
 *
 * @return array{title:string,alt:string,description:string}
 */
function omc_img_words_for( $id, $owner_id, $how, $position ) {
	/* "Oops, Mine Co." already ends in a full stop; a sentence does not need a second. */
	$shop  = rtrim( html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ), '.' );
	$owner = $owner_id ? get_post( $owner_id ) : null;

	if ( ! $owner ) {
		return [ 'title' => '', 'alt' => '', 'description' => '' ];
	}

	$name = html_entity_decode( get_the_title( $owner ), ENT_QUOTES, 'UTF-8' );

	/* the keywords the owning page was written for */
	$prefer = (array) get_post_meta( $owner_id, '_whd_ai_keywords', true );
	if ( ! $prefer ) {
		$landing = get_post_meta( $owner_id, '_omc_landing', true );
		$decoded = is_string( $landing ) ? json_decode( $landing, true ) : $landing;
		$prefer  = is_array( $decoded ) ? (array) ( $decoded['keywords'] ?? [] ) : [];
	}

	if ( 'product' === $owner->post_type ) {
		$cats = wp_get_post_terms( $owner_id, 'product_cat', [ 'fields' => 'names' ] );
		$cats = is_wp_error( $cats ) ? [] : array_map( static fn( $c ) => html_entity_decode( $c, ENT_QUOTES, 'UTF-8' ), $cats );
		/* "Archive" is where the demo catalogue was put out to pasture; naming it in alt text
		   tells a reader nothing and a crawler less. */
		$cats = array_values( array_filter( $cats, static fn( $c ) => 'Archive' !== $c ) );
		$leaf = $cats ? (string) end( $cats ) : '';
		$key  = omc_img_keyword( $name . ' ' . implode( ' ', $cats ), $prefer );

		/* The product name is the description — it is what the photograph is of. Splicing in the
		   marketing copy only produced a sentence cut off in the middle: "…that deliver". The
		   keyword is appended only when the name does not already contain it. */
		$alt = $name;
		if ( $position > 1 ) {
			$alt .= ', view ' . $position;
		}
		if ( $key && false === mb_stripos( $name, $key ) ) {
			$alt .= ' — ' . $key;
		} elseif ( ! $key && $leaf && false === mb_stripos( $name, rtrim( $leaf, 's' ) ) ) {
			$alt .= ' — ' . $leaf;
		}

		$desc = sprintf(
			'%s%s from %s.%s',
			$name,
			$leaf ? ', ' . lcfirst( $leaf ) : '',
			$shop,
			$key ? ' Photographed for our ' . $key . ' range.' : ''
		);

		return [
			'title'       => $name,
			'alt'         => omc_img_clip( $alt, 125 ),
			'description' => omc_img_clip( $desc, 300 ),
		];
	}

	/* a post or a page */
	$key  = omc_img_keyword( $name . ' ' . omc_img_clip( $owner->post_excerpt, 120 ), $prefer );
	$what = 'featured' === $how ? '' : ' — ' . ( $position > 1 ? 'picture ' . $position : 'picture' );
	$alt  = $key && false === mb_stripos( $name, $key )
		? $name . ' — ' . $key
		: $name . $what;

	$desc = sprintf(
		'A photograph from “%s” on %s.%s',
		$name,
		$shop,
		$key ? ' It illustrates ' . $key . '.' : ''
	);

	return [
		'title'       => $name . ( $position > 1 ? ' — ' . $position : '' ),
		'alt'         => omc_img_clip( $alt, 125 ),
		'description' => omc_img_clip( $desc, 300 ),
	];
}

/* ─────────────────────────── weight ─────────────────────────── */

/**
 * Re-encode one file in place, smaller.
 *
 * @return array{0:bool,1:string} whether it changed, and what happened
 */
function omc_img_shrink( $id, $dry ) {
	$file = get_attached_file( $id );
	if ( ! $file || ! file_exists( $file ) ) {
		return [ false, 'no file on disk' ];
	}
	$was  = filesize( $file );
	$mime = (string) get_post_mime_type( $id );
	if ( ! in_array( $mime, [ 'image/jpeg', 'image/webp', 'image/png' ], true ) ) {
		return [ false, 'left alone (' . $mime . ')' ];
	}
	if ( $was <= OMC_IMG_HEAVY ) {
		return [ false, 'already light' ];
	}

	$editor = wp_get_image_editor( $file );
	if ( is_wp_error( $editor ) ) {
		return [ false, 'cannot open: ' . $editor->get_error_message() ];
	}
	$size = $editor->get_size();
	$long = max( (int) $size['width'], (int) $size['height'] );

	if ( $dry ) {
		return [ true, sprintf( '%d KB, %dpx → would re-encode', round( $was / 1024 ), $long ) ];
	}

	if ( $long > OMC_IMG_MAX_EDGE ) {
		$editor->resize(
			$size['width'] >= $size['height'] ? OMC_IMG_MAX_EDGE : null,
			$size['height'] > $size['width'] ? OMC_IMG_MAX_EDGE : null,
			false
		);
	}
	$editor->set_quality( OMC_IMG_QUALITY );

	/* write beside it first: a re-encode that comes out bigger is not an improvement */
	$temp = $file . '.omc-tmp';
	$done = $editor->save( $temp, $mime );
	if ( is_wp_error( $done ) ) {
		return [ false, 'could not write: ' . $done->get_error_message() ];
	}
	$temp_file = $done['path'] ?? $temp;
	$now       = file_exists( $temp_file ) ? filesize( $temp_file ) : 0;

	if ( ! $now || $now >= $was * 0.95 ) {
		@unlink( $temp_file );
		return [ false, sprintf( 'no real saving (%d KB → %d KB)', round( $was / 1024 ), round( $now / 1024 ) ) ];
	}

	if ( ! @rename( $temp_file, $file ) ) {
		@unlink( $temp_file );
		return [ false, 'could not replace the original' ];
	}

	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );

	return [ true, sprintf( '%d KB → %d KB  (−%d%%)', round( $was / 1024 ), round( $now / 1024 ), round( 100 - 100 * $now / $was ) ) ];
}

/* ─────────────────────────── the run ─────────────────────────── */

$ids   = get_posts( [
	'post_type'      => 'attachment',
	'post_mime_type' => 'image',
	'post_status'    => 'inherit',
	'posts_per_page' => -1,
	'orderby'        => 'ID',
	'order'          => 'ASC',
	'fields'         => 'ids',
] );
$index = omc_img_index();
$seen  = [];

$stat = [ 'looked' => 0, 'skipped' => 0, 'title' => 0, 'alt' => 0, 'desc' => 0, 'shrunk' => 0, 'saved' => 0 ];
$show = [];

foreach ( $ids as $id ) {
	if ( $limit && $stat['looked'] >= $limit ) {
		break;
	}
	$file = get_attached_file( $id );
	if ( omc_img_is_furniture( $id, $file ) ) {
		$stat['skipped']++;
		continue;
	}
	/* Weight is weight: a 900 KB picture costs a phone the same whether or not a post claims
	   it. Re-encoding therefore runs on everything that is not theme furniture, while the words
	   below need something to be written about and so need an owner. */
	if ( $do_shrink ) {
		$before = file_exists( (string) $file ) ? filesize( $file ) : 0;
		[ $changed, $note ] = omc_img_shrink( $id, $dry );
		if ( $changed ) {
			$stat['shrunk']++;
			if ( ! $dry ) {
				clearstatcache( true, (string) $file );
				$stat['saved'] += max( 0, $before - ( file_exists( (string) $file ) ? filesize( $file ) : 0 ) );
			}
			echo '  ' . basename( (string) $file ) . '  ' . $note . "
";
		}
	}

	[ $owner_id, $how ] = omc_img_owner( $id, $index );
	if ( ! $owner_id ) {
		$stat['skipped']++;
		continue;
	}
	$stat['looked']++;

	// where this picture comes in its owner's set, so two of the same product do not read alike
	$seen[ $owner_id ] = ( $seen[ $owner_id ] ?? 0 ) + 1;
	$position          = $seen[ $owner_id ];

	$att   = get_post( $id );
	$alt   = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
	$title = trim( (string) $att->post_title );
	$desc  = trim( (string) $att->post_content );

	$need_title = '' === $title;
	$need_alt   = '' === $alt;
	$need_desc  = '' === $desc;

	if ( $do_text && ( $need_title || $need_alt || $need_desc ) ) {
		$words = omc_img_words_for( $id, $owner_id, $how, $position );

		if ( $need_title && $words['title'] ) {
			$stat['title']++;
			if ( ! $dry ) {
				wp_update_post( [ 'ID' => $id, 'post_title' => $words['title'] ] );
			}
		}
		if ( $need_alt && $words['alt'] ) {
			$stat['alt']++;
			if ( ! $dry ) {
				update_post_meta( $id, '_wp_attachment_image_alt', $words['alt'] );
			}
		}
		if ( $need_desc && $words['description'] ) {
			$stat['desc']++;
			if ( ! $dry ) {
				wp_update_post( [ 'ID' => $id, 'post_content' => $words['description'] ] );
			}
		}

		if ( count( $show ) < 6 && $words['alt'] ) {
			$show[] = [ $id, $words ];
		}
	}

}

echo "\n";
printf( "%s\n", $dry ? '── what would change ──' : '── done ──' );
printf( "  looked at      %d images  (%d skipped: demo files, placeholders, nothing uses them)\n", $stat['looked'], $stat['skipped'] );
printf( "  titles written %d\n", $stat['title'] );
printf( "  alt written    %d\n", $stat['alt'] );
printf( "  descriptions   %d\n", $stat['desc'] );
printf( "  re-encoded     %d%s\n", $stat['shrunk'], $stat['saved'] ? '  (' . size_format( $stat['saved'] ) . ' saved)' : '' );

if ( $show ) {
	echo "\n── a few, as they would read ──\n";
	foreach ( $show as [ $id, $w ] ) {
		printf( "  #%d\n    title: %s\n    alt  : %s\n    desc : %s\n", $id, $w['title'], $w['alt'], $w['description'] );
	}
}
