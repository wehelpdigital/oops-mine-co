<?php
/**
 * Where is each researched keyword actually used on the site?
 *
 * Fetches every published page, journal post, product category and product, keeps only the copy a
 * reader sees as that page's own — header, footer, popups, sidebars, post navigation, breadcrumbs,
 * product cards and related rows are other pages' titles, not this page's words — and counts on how
 * many URLs each keyword in content/keywords.csv appears verbatim.
 *
 * A keyword that lives on one page only is the thing to fix: content/plan.json assigns each keyword
 * to one owner page, and the journal posts and the home page's "Shop by style" row are where the
 * same phrase is meant to turn up again, linking back to that owner.
 *
 *   php .ftp-sync/keyword-audit.php                    local site; lists keywords on fewer than 2 pages
 *   php .ftp-sync/keyword-audit.php --min=3            …fewer than 3
 *   php .ftp-sync/keyword-audit.php --cached           re-use the pages fetched last time
 *   php .ftp-sync/keyword-audit.php --host=https://oopsmineco.com
 *                                                      same URLs, fetched from live (read-only; store
 *                                                      pages are skipped there — coming-soon mode)
 *   php .ftp-sync/keyword-audit.php --json=out.json    also write the full result
 */

$opt = [ 'min' => 2, 'host' => '', 'cached' => false, 'json' => '' ];
foreach ( array_slice( $argv, 1 ) as $a ) {
	if ( '--cached' === $a ) {
		$opt['cached'] = true;
	} elseif ( preg_match( '/^--(min|host|json)=(.+)$/', $a, $m ) ) {
		$opt[ $m[1] ] = $m[2];
	}
}

$_SERVER['HTTP_HOST'] = 'oopsmine.test';
require __DIR__ . '/../wp-load.php';

$remote = '' !== $opt['host'];
$cache  = sys_get_temp_dir() . '/omc-keyword-audit-' . md5( $opt['host'] ?: 'local' );
wp_mkdir_p( $cache );

/* ── what to fetch ── */
$urls = [];
$skip = [ 'cart', 'checkout', 'my-account', 'wishlist', 'maintenance-mode', 'order-tracking' ];
foreach ( get_posts( [ 'post_type' => [ 'page', 'post' ], 'post_status' => 'publish', 'posts_per_page' => -1 ] ) as $p ) {
	if ( ! in_array( $p->post_name, $skip, true ) ) {
		$urls[] = [ 'kind' => $p->post_type, 'slug' => $p->post_name, 'url' => get_permalink( $p ) ];
	}
}
if ( ! $remote ) {
	foreach ( get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true ] ) as $t ) {
		$urls[] = [ 'kind' => 'product_cat', 'slug' => $t->slug, 'url' => get_term_link( $t ) ];
	}
	foreach ( get_posts( [ 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => -1 ] ) as $p ) {
		$urls[] = [ 'kind' => 'product', 'slug' => $p->post_name, 'url' => get_permalink( $p ) ];
	}
}
if ( $remote ) {
	foreach ( $urls as &$u ) {
		$u['url'] = rtrim( $opt['host'], '/' ) . wp_make_link_relative( $u['url'] );
	}
	unset( $u );
}

/* Locally the store pages are behind WooCommerce's coming-soon gate: fetch as an administrator. */
$headers = [];
if ( ! $remote ) {
	$admin = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
	if ( $admin ) {
		$exp       = time() + 3600;
		$headers[] = 'Cookie: ' . LOGGED_IN_COOKIE . '=' . rawurlencode( wp_generate_auth_cookie( (int) $admin[0], $exp, 'logged_in' ) )
			. '; ' . AUTH_COOKIE . '=' . rawurlencode( wp_generate_auth_cookie( (int) $admin[0], $exp, 'auth' ) );
	}
}

$todo = [];
foreach ( $urls as $i => $u ) {
	$urls[ $i ]['file'] = $cache . '/' . $u['kind'] . '-' . sanitize_file_name( $u['slug'] ) . '.html';
	if ( ! $opt['cached'] || ! is_file( $urls[ $i ]['file'] ) ) {
		$todo[] = $i;
	}
}
printf( "%d URLs, %d to fetch from %s\n", count( $urls ), count( $todo ), $remote ? $opt['host'] : home_url() );
$mh = curl_multi_init();
foreach ( array_chunk( $todo, 6 ) as $chunk ) {
	$hs = [];
	foreach ( $chunk as $i ) {
		$h = curl_init( $urls[ $i ]['url'] );
		curl_setopt_array( $h, [ CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers ] );
		curl_multi_add_handle( $mh, $h );
		$hs[ $i ] = $h;
	}
	do {
		curl_multi_exec( $mh, $running );
		curl_multi_select( $mh );
	} while ( $running );
	foreach ( $hs as $i => $h ) {
		file_put_contents( $urls[ $i ]['file'], (string) curl_multi_getcontent( $h ) );
		curl_multi_remove_handle( $mh, $h );
	}
}

/* ── the page's own copy ── */
function omc_ka_norm( $s ) {
	$s = html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$s = str_replace( [ '’', '‘', '“', '”', "\xc2\xa0" ], [ "'", "'", '"', '"', ' ' ], $s );
	return ' ' . trim( preg_replace( '/\s+/u', ' ', mb_strtolower( $s ) ) ) . ' ';
}

function omc_ka_copy( $html ) {
	if ( '' === trim( $html ) ) {
		return '';
	}
	libxml_use_internal_errors( true );
	$doc = new DOMDocument();
	$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
	$xp = new DOMXPath( $doc );
	$cut = '//script|//style|//noscript|//svg|//template|//header[@id="main-header"]|//footer|//aside'
		. '|//*[@id="wpadminbar"]|//*[contains(concat(" ",@class," ")," whd-popup ")]|//*[contains(@class,"c-header-search")]'
		. '|//*[contains(concat(" ",@class," ")," c-footer ")]|//*[contains(@class,"c-sidebar")]|//nav[contains(@class,"c-post__nav")]'
		. '|//*[contains(@class,"c-breadcrumbs")]|//*[contains(concat(" ",@class," ")," c-product-grid__item ")]'
		. '|//*[contains(@class,"c-product__products")]|//*[contains(@class,"related")]|//*[contains(@class,"upsells")]';
	foreach ( iterator_to_array( $xp->query( $cut ) ) as $n ) {
		$n->parentNode && $n->parentNode->removeChild( $n );
	}
	$body = $xp->query( '//body' )->item( 0 );

	// Tags out with a space each, so "dress</p><p>korean" does not glue into one word.
	return $body ? omc_ka_norm( preg_replace( '/<[^>]+>/', ' ', $doc->saveHTML( $body ) ) ) : '';
}

$copy = [];
foreach ( $urls as $u ) {
	$copy[ $u['kind'] . ':' . $u['slug'] ] = omc_ka_copy( (string) @file_get_contents( $u['file'] ) );
}

/* ── the keywords ── */
$rows = array_map( 'str_getcsv', file( __DIR__ . '/content/keywords.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) );
array_shift( $rows );
$keywords = [];
foreach ( $rows as $r ) {
	if ( count( $r ) < 4 ) {
		continue;
	}
	$needle = trim( omc_ka_norm( $r[0] ) );
	$re     = '/(?<![\p{L}\p{N}])' . preg_quote( $needle, '/' ) . '(?![\p{L}\p{N}])/u';
	$on     = [];
	foreach ( $copy as $key => $text ) {
		$n = preg_match_all( $re, $text );
		if ( $n ) {
			$on[ $key ] = $n;
		}
	}
	$keywords[] = [ 'keyword' => trim( $r[0] ), 'volume' => (int) $r[1], 'difficulty' => (int) $r[2], 'owner' => trim( $r[3] ), 'pages' => count( $on ), 'on' => $on ];
}

/* ── report ── */
$min   = max( 1, (int) $opt['min'] );
$short = array_values( array_filter( $keywords, fn( $k ) => $k['pages'] < $min ) );
usort( $short, fn( $a, $b ) => $b['volume'] <=> $a['volume'] );
$none = count( array_filter( $keywords, fn( $k ) => ! $k['pages'] ) );

printf( "\n%d keywords: %d on no page, %d on fewer than %d pages, %d on %d or more\n", count( $keywords ), $none, count( $short ), $min, count( $keywords ) - count( $short ), $min );
foreach ( $short as $k ) {
	printf( "  %5d  sd %2d  %d page(s)  %-44s owner %s\n", $k['volume'], $k['difficulty'], $k['pages'], $k['keyword'], $k['owner'] );
}

$top = $keywords;
usort( $top, fn( $a, $b ) => $b['volume'] / ( $b['difficulty'] + 10 ) <=> $a['volume'] / ( $a['difficulty'] + 10 ) );
echo "\nthe 25 best keywords (volume against difficulty), and where they are:\n";
foreach ( array_slice( $top, 0, 25 ) as $k ) {
	$main = array_intersect( array_keys( $k['on'] ), [ 'page:home', 'page:about-us' ] );
	printf( "  %5d  sd %2d  %2d pages  %-36s %s\n", $k['volume'], $k['difficulty'], $k['pages'], $k['keyword'], $main ? 'on ' . implode( ', ', str_replace( 'page:', '', $main ) ) : '' );
}

if ( $opt['json'] ) {
	file_put_contents( $opt['json'], wp_json_encode( $keywords, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	echo "\nfull result: {$opt['json']}\n";
}
