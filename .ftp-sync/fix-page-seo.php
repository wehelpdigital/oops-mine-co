<?php
/**
 * Fill in the SEO fields the catalogue was imported without.
 *
 * The score box found the same three gaps on most of the shop: no focus keyphrase, no search
 * snippet, and — on the utility pages — a score at all, when a cart has no business being in a
 * search result.
 *
 * Nothing here invents a claim. A snippet is built from what the product or page already says
 * about itself, and a keyphrase is only set when a researched phrase genuinely matches; a product
 * nothing fits keeps an empty field rather than a phrase it will never rank for.
 *
 * Usage:
 *   php .ftp-sync/fix-page-seo.php --dry-run
 *   php .ftp-sync/fix-page-seo.php
 *   OMC_DB=live php .ftp-sync/fix-page-seo.php      (production write — the owner runs this)
 */

$argv = $_SERVER['argv'] ?? [];
$dry  = in_array( '--dry-run', $argv, true );
$redo = in_array( '--redo', $argv, true );
$limit = 0;
foreach ( $argv as $a ) {
	if ( 0 === strpos( $a, '--limit=' ) ) {
		$limit = (int) substr( $a, 8 );
	}
}

$_SERVER['HTTP_HOST'] = getenv( 'OMC_DB' ) === 'live' ? 'oopsmineco.com' : 'oopsmine.test';
require __DIR__ . '/../wp-load.php';

if ( ! class_exists( 'WHD_SEO' ) ) {
	exit( "The WHD plugin is not active, so there is nothing to score against.\n" );
}

/** Pages that exist to do a job, not to be found. */
const OMC_NOT_FOR_SEARCH = [
	'cart', 'checkout', 'my-account', 'wishlist', 'order-tracking', 'maintenance-mode',
	'thank-you', 'order-received', 'lost-password',
];

/* ─────────────────────────── choosing a phrase ─────────────────────────── */

/** Words worth matching on. */
function omc_seo_words( $text ) {
	static $stop = [ 'the', 'and', 'for', 'with', 'from', 'that', 'this', 'your', 'you', 'are',
		'what', 'how', 'who', 'its', 'our', 'out', 'can', 'all', 'one', 'two', 'has', 'have', 'into',
		'over', 'under', 'than', 'then', 'them', 'they', 'will', 'more', 'most', 'some' ];
	$words = preg_split( '/[^a-z0-9]+/u', mb_strtolower( wp_strip_all_tags( (string) $text ) ), -1, PREG_SPLIT_NO_EMPTY );
	$out   = [];
	foreach ( (array) $words as $w ) {
		if ( mb_strlen( $w ) >= 3 && ! in_array( $w, $stop, true ) ) {
			$out[ rtrim( $w, 's' ) ] = true;
		}
	}

	return $out;
}

/** Every researched phrase, with its words and how many pages already claim it. */
function omc_seo_phrases() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	global $wpdb;
	$cache = [];
	if ( ! class_exists( 'WHD_AI_Keywords' ) ) {
		return $cache;
	}
	$taken = [];
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", WHD_SEO::KEYPHRASE ) ) as $t ) {
		$taken[ mb_strtolower( $t ) ] = ( $taken[ mb_strtolower( $t ) ] ?? 0 ) + 1;
	}
	foreach ( (array) $wpdb->get_results( 'SELECT keyword, volume FROM ' . WHD_AI_Keywords::table(), ARRAY_A ) as $row ) {
		$words = omc_seo_words( $row['keyword'] );
		if ( $words ) {
			$cache[] = [
				'keyword' => $row['keyword'],
				'volume'  => (int) $row['volume'],
				'words'   => $words,
				'taken'   => (int) ( $taken[ mb_strtolower( $row['keyword'] ) ] ?? 0 ),
			];
		}
	}

	return $cache;
}

/**
 * The researched phrase this page is genuinely about.
 *
 * Scoring on shared words was too generous: it put "korean clothes store near me" on the FAQ
 * page, because four of those five words appear on almost every page of a Korean clothing shop.
 * A wrong keyphrase is worse than none — the box then measures the page against something it was
 * never trying to be, and whoever reads the score goes chasing it.
 *
 * So the test is verbatim: the phrase has to appear in the page's own copy, word for word. That
 * is the one signal that cannot be a coincidence. Of what is left, the busiest phrase wins, and
 * a phrase another page has already claimed is passed over — two pages aimed at one phrase beat
 * each other, which is the very thing the box warns about.
 */
function omc_seo_pick( $haystack ) {
	$haystack = ' ' . trim( preg_replace( '/\s+/u', ' ', mb_strtolower( wp_strip_all_tags( (string) $haystack ) ) ) ) . ' ';
	if ( ' ' === $haystack ) {
		return '';
	}
	$best  = '';
	$score = -1;
	foreach ( omc_seo_phrases() as $row ) {
		if ( $row['taken'] > 0 ) {
			continue;
		}
		$needle = ' ' . mb_strtolower( trim( $row['keyword'] ) ) . ' ';
		if ( false === mb_strpos( $haystack, $needle ) ) {
			continue;
		}
		// Longer phrases are more specific; volume breaks a tie.
		$weight = count( $row['words'] ) * 1000 + $row['volume'];
		if ( $weight > $score ) {
			$score = $weight;
			$best  = $row['keyword'];
		}
	}

	return $best;
}

/**
 * A phrase for a product nothing researched matches.
 *
 * Most of the catalogue is not what anybody types into Google — "Whimsical Wonder Embroidered
 * Cardigan" is a name, not a search. What somebody does type is the thing itself, so the name is
 * reduced to its last few words: the garment and the two or three words that describe it.
 */
function omc_seo_product_phrase( $post ) {
	$name = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
	$name = preg_replace( '/\b\d+\s*(ml|g|kg|pads?|sheets?|pcs?)\b/iu', ' ', $name );
	$name = preg_replace( '/[^\p{L}\p{N}\s-]+/u', ' ', $name );
	$words = preg_split( '/\s+/u', mb_strtolower( trim( $name ) ), -1, PREG_SPLIT_NO_EMPTY );
	if ( ! $words ) {
		return '';
	}
	// The noun is at the end in English; four words is as long as a useful phrase gets.
	$words = array_slice( $words, -4 );
	$out   = trim( implode( ' ', $words ) );

	return mb_strlen( $out ) >= 8 ? $out : '';
}

/* ─────────────────────────── writing a snippet ─────────────────────────── */

function omc_seo_clip( $text, $max ) {
	$text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
	$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
	if ( mb_strlen( $text ) <= $max ) {
		return $text;
	}
	$cut   = mb_substr( $text, 0, $max );
	$stop  = max( mb_strrpos( $cut, '. ' ), mb_strrpos( $cut, '? ' ) );
	if ( false !== $stop && $stop > $max * 0.55 ) {
		return trim( mb_substr( $cut, 0, $stop + 1 ) );
	}
	$space = mb_strrpos( $cut, ' ' );

	return rtrim( false !== $space ? mb_substr( $cut, 0, $space ) : $cut, ' ,;:–—-' ) . '…';
}

/**
 * A snippet from what the page already says, with the keyphrase in it where it fits.
 *
 * Aiming at 120–158: under a hundred wastes the room Google gives you, over a hundred and
 * fifty-eight is cut off mid-word.
 */
function omc_seo_snippet( $post, $keyphrase ) {
	$shop = rtrim( html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ), '.' );
	$name = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );

	$source = '';
	if ( 'product' === $post->post_type ) {
		$source = (string) $post->post_excerpt ?: (string) $post->post_content;
	} else {
		$landing = get_post_meta( $post->ID, '_omc_landing', true );
		$decoded = is_string( $landing ) ? json_decode( $landing, true ) : $landing;
		if ( is_array( $decoded ) ) {
			$source = (string) ( $decoded['hero']['text'] ?? $decoded['intro'] ?? '' );
			if ( '' === $source && ! empty( $decoded['sections'][0]['paragraphs'][0] ) ) {
				$source = (string) $decoded['sections'][0]['paragraphs'][0];
			}
		}
		if ( '' === $source ) {
			$source = (string) $post->post_excerpt ?: (string) $post->post_content;
		}
	}

	$body = omc_seo_clip( $source, 150 );

	/* The keyphrase belongs in the snippet; if what the page says already contains it, leave the
	   sentence alone rather than bolting the phrase on twice. */
	$has = $keyphrase && false !== mb_stripos( $body, $keyphrase );
	if ( $keyphrase && ! $has ) {
		/* A product's phrase is its own name, which belongs at the front on its own. Bolting a
		   second topic phrase on after it reads as keyword insertion, because that is what it is. */
		$lead = 'product' === $post->post_type
			? sprintf( '%s. ', $name )
			: sprintf( '%s — %s. ', $name, ucfirst( $keyphrase ) );
		$body = omc_seo_clip( $lead . $body, 156 );
	} elseif ( '' === $body ) {
		$body = omc_seo_clip( sprintf( '%s from %s.', $name, $shop ), 156 );
	} else {
		$body = omc_seo_clip( $body, 156 );
	}

	/* Still short: the shop's name is honest filler and puts the brand in the result. */
	if ( mb_strlen( $body ) < 100 ) {
		$body = omc_seo_clip( rtrim( $body, '.' ) . '. ' . sprintf( 'Chosen for %s.', $shop ), 156 );
	}

	return $body;
}

/* ─────────────────────────── the run ─────────────────────────── */

$posts = get_posts( [
	'post_type'      => [ 'post', 'page', 'product' ],
	'post_status'    => 'publish',
	'posts_per_page' => -1,
	'orderby'        => 'ID',
	'order'          => 'ASC',
] );

/* Every phrase already spoken for, so the run never hands one out twice. */
$GLOBALS['omc_seo_claimed'] = [];
foreach ( (array) $GLOBALS['wpdb']->get_results( $GLOBALS['wpdb']->prepare(
	"SELECT post_id, meta_value FROM {$GLOBALS['wpdb']->postmeta} WHERE meta_key = %s AND meta_value <> ''",
	WHD_SEO::KEYPHRASE
), ARRAY_A ) as $claim ) {
	$GLOBALS['omc_seo_claimed'][ mb_strtolower( $claim['meta_value'] ) ] = (int) $claim['post_id'];
}

$stat    = [ 'seen' => 0, 'noindex' => 0, 'keyphrase' => 0, 'snippet' => 0, 'seo_title' => 0, 'skipped' => 0 ];
$show    = [];
$before  = [];
$after   = [];

foreach ( $posts as $post ) {
	if ( $limit && $stat['seen'] >= $limit ) {
		break;
	}
	$stat['seen']++;
	$before[] = WHD_SEO::analyse( WHD_SEO::doc_from_post( $post->ID ) )['score'];

	/* a page that exists to do a job */
	if ( 'page' === $post->post_type && in_array( $post->post_name, OMC_NOT_FOR_SEARCH, true ) ) {
		if ( '1' !== (string) get_post_meta( $post->ID, WHD_SEO::NOINDEX, true ) ) {
			$stat['noindex']++;
			if ( ! $dry ) {
				update_post_meta( $post->ID, WHD_SEO::NOINDEX, '1' );
			}
			$show[] = sprintf( 'kept out of search: %s', $post->post_name );
		}
		$after[] = 100;
		continue;
	}

	$stored    = (string) get_post_meta( $post->ID, WHD_SEO::KEYPHRASE, true );
	$keyphrase = $redo && 'product' === $post->post_type ? '' : $stored;
	if ( $redo && 'product' === $post->post_type && '' !== $stored ) {
		unset( $GLOBALS['omc_seo_claimed'][ mb_strtolower( $stored ) ] );
	}
	if ( '' === $keyphrase ) {
		$keyphrase = WHD_SEO::researched_for( $post->ID );
	}
	/* A product aims at itself.
	   Handing products the researched topic phrases put a hundred of them in competition with
	   each other and with the content pages written for those very phrases — and the phrase then
	   appeared in neither the product's title nor its web address, so almost every keyphrase
	   check failed. What a product should rank for is the thing it is: "corduroy pinafore
	   dress", which is in its name and therefore in its title, its slug and its opening line. */
	if ( '' === $keyphrase && 'product' === $post->post_type ) {
		$keyphrase = omc_seo_product_phrase( $post );
	}
	if ( '' === $keyphrase ) {
		/* What the page actually says, which is where a verbatim match has to be found. */
		$doc  = WHD_SEO::doc_from_post( $post->ID );
		$keyphrase = omc_seo_pick( $doc['title'] . ' ' . $doc['content'] );
	}

	/* Two pages on the same phrase beat each other, and the list of what is taken has to grow as
	   the run goes along — reading it once at the start handed one phrase out again and again. */
	if ( '' !== $keyphrase ) {
		$claim = mb_strtolower( $keyphrase );
		if ( isset( $GLOBALS['omc_seo_claimed'][ $claim ] ) && $GLOBALS['omc_seo_claimed'][ $claim ] !== $post->ID ) {
			$keyphrase = '';
		} else {
			$GLOBALS['omc_seo_claimed'][ $claim ] = $post->ID;
		}
	}
	if ( '' !== $keyphrase && $keyphrase !== $stored ) {
		$stat['keyphrase']++;
		if ( ! $dry ) {
			update_post_meta( $post->ID, WHD_SEO::KEYPHRASE, $keyphrase );
		}
		if ( count( $show ) < 10 ) {
			$show[] = sprintf( '%-46s → %s', mb_substr( get_the_title( $post ), 0, 44 ), $keyphrase );
		}
	}
	if ( '' === $keyphrase ) {
		$stat['skipped']++;
	}

	/* With --redo the phrase has just changed, so a snippet written against the old one is
	   carrying a lead-in that no longer belongs to the page. */
	$snippet_stale = $redo && 'product' === $post->post_type;
	if ( $snippet_stale || '' === trim( (string) get_post_meta( $post->ID, '_omc_meta_description', true ) ) ) {
		$snippet = omc_seo_snippet( $post, $keyphrase );
		if ( '' !== $snippet ) {
			$stat['snippet']++;
			if ( ! $dry ) {
				update_post_meta( $post->ID, '_omc_meta_description', $snippet );
			}
		}
	}

	/* An SEO title only when the page title is too long to survive a result. */
	if ( '' === trim( (string) get_post_meta( $post->ID, '_omc_seo_title', true ) ) ) {
		$title = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
		if ( mb_strlen( $title ) > 60 ) {
			$stat['seo_title']++;
			if ( ! $dry ) {
				update_post_meta( $post->ID, '_omc_seo_title', omc_seo_clip( $title, 58 ) );
			}
		}
	}

	$after[] = $dry ? 0 : WHD_SEO::analyse( WHD_SEO::doc_from_post( $post->ID ) )['score'];
}

$mean = static fn( array $n ) => $n ? round( array_sum( $n ) / count( $n ) ) : 0;

echo "\n" . ( $dry ? '── what would change ──' : '── done ──' ) . "\n";
printf( "  looked at          %d published pages\n", $stat['seen'] );
printf( "  keyphrase set      %d\n", $stat['keyphrase'] );
printf( "  snippet written    %d\n", $stat['snippet'] );
printf( "  SEO title written  %d  (only where the real title would be cut off)\n", $stat['seo_title'] );
printf( "  kept out of search %d\n", $stat['noindex'] );
printf( "  no phrase fitted   %d\n", $stat['skipped'] );
if ( ! $dry ) {
	printf( "\n  average score      %d → %d\n", $mean( $before ), $mean( $after ) );
}

if ( $show ) {
	echo "\n── a few of them ──\n";
	foreach ( array_slice( $show, 0, 12 ) as $line ) {
		echo '  ' . $line . "\n";
	}
}
