<?php
/**
 * Draw the photographs the product stories are short of, in bulk.
 *
 * The story under the reviews alternates copy and picture down the page, so a product with two
 * pictures and four sections leaves two rows with nothing beside the words. The button on the
 * product screen fills one product; this fills the shop.
 *
 * Usage:
 *   php .ftp-sync/fill-product-photos.php --dry-run          what is short, and what it would cost
 *   php .ftp-sync/fill-product-photos.php --limit=10         draw for the ten emptiest products
 *   php .ftp-sync/fill-product-photos.php --slug=some-product
 *   php .ftp-sync/fill-product-photos.php --live --limit=10  (production write — the owner runs it)
 *
 * The pictures land in wp-content/uploads, which is git-ignored, so they reach live through
 * `node .ftp-sync/server.mjs sync` rather than through a commit. Attaching a picture and pointing
 * a story row at it are database writes, so the --live run is the site owner's.
 */

if ( in_array( '--live', $argv, true ) ) {
	putenv( 'OMC_DB=live' );
}
$live                   = 'live' === getenv( 'OMC_DB' );
$_SERVER['HTTP_HOST']   = $live ? 'oopsmineco.com' : 'oopsmine.test';
$_SERVER['REQUEST_URI'] = '/';

require dirname( __DIR__ ) . '/wp-load.php';

if ( ! class_exists( 'WHD_AI_Images' ) ) {
	exit( "The WHD plugin is not active, so there is nothing to draw with.\n" );
}

$dry   = in_array( '--dry-run', $argv, true );
$limit = 0;
$only  = '';
foreach ( $argv as $arg ) {
	if ( 0 === strpos( $arg, '--limit=' ) ) {
		$limit = (int) substr( $arg, 8 );
	}
	if ( 0 === strpos( $arg, '--slug=' ) ) {
		$only = sanitize_title( substr( $arg, 7 ) );
	}
}

// An administrator, because attaching to a product goes through WooCommerce's own save.
$admin = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
if ( $admin ) {
	wp_set_current_user( (int) $admin[0] );
}

printf(
	"%s  →  %s  via %s%s\n\n",
	$live ? 'LIVE database' : 'local database',
	home_url( '/' ),
	WHD_AI_Images::ready() ? 'Google ' . WHD_AI_Images::model() : 'NO KEY',
	$dry ? '   (dry run)' : ''
);
if ( ! WHD_AI_Images::ready() && ! $dry ) {
	exit( "No Google API key is set in WHD → AI content.\n" );
}

$short = [];
foreach ( get_posts( [ 'post_type' => 'product', 'numberposts' => -1, 'post_status' => 'publish', 'fields' => 'ids' ] ) as $id ) {
	$slug = get_post_field( 'post_name', $id );
	if ( $only && $slug !== $only ) {
		continue;
	}
	$missing = WHD_AI_Images::missing( $id );
	$have    = count( WHD_AI_Images::gallery( $id ) );
	// Nothing to copy a product from, so it is a job for a camera rather than for this.
	if ( $missing < 1 || $have < 1 ) {
		continue;
	}
	$short[] = [ 'id' => $id, 'slug' => $slug, 'missing' => $missing, 'have' => $have ];
}

// Emptiest first: the products where the band currently falls apart.
usort( $short, static fn( $a, $b ) => $b['missing'] <=> $a['missing'] ?: strcmp( $a['slug'], $b['slug'] ) );
if ( $limit > 0 ) {
	$short = array_slice( $short, 0, $limit );
}

$wanted = array_sum( array_column( $short, 'missing' ) );
printf( "%d product(s) short, %d picture(s) to draw\n\n", count( $short ), $wanted );

$made    = 0;
$failed  = 0;
$started = microtime( true );
foreach ( $short as $row ) {
	if ( $dry ) {
		printf( "   %-52s %d have, %d to draw\n", $row['slug'], $row['have'], $row['missing'] );
		continue;
	}

	$result = WHD_AI_Images::fill( $row['id'] );
	if ( is_wp_error( $result ) ) {
		$failed += $row['missing'];
		printf( "   %-52s FAILED  %s\n", $row['slug'], $result->get_error_message() );
		continue;
	}
	$made += count( $result['made'] );
	$failed += count( $result['skipped'] );
	printf(
		"   %-52s +%d%s\n",
		$row['slug'],
		count( $result['made'] ),
		$result['skipped'] ? '  (' . implode( '; ', $result['skipped'] ) . ')' : ''
	);
}

if ( $dry ) {
	printf( "\n%d picture(s) would be drawn.\n", $wanted );
	exit;
}

printf( "\n%d drawn, %d not, in %d minute(s)\n", $made, $failed, round( ( microtime( true ) - $started ) / 60 ) );
echo "Look through them in Media before this goes out, then: node .ftp-sync/server.mjs sync\n";
