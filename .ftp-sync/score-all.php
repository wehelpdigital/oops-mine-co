<?php
/**
 * Give every post, page and product an SEO score, so the lists are full on the first look.
 *
 * The column works it out on demand too, but only a few rows at a time — reading a page properly
 * costs a tenth of a second or so, and twenty at once is a wait nobody asked for. This does the
 * lot in one go.
 *
 *   php .ftp-sync/score-all.php                 score anything without one
 *   php .ftp-sync/score-all.php --all           score everything again
 *   php .ftp-sync/score-all.php --live …        against production
 */

if ( in_array( '--live', $argv, true ) ) {
	putenv( 'OMC_DB=live' );
}

$_SERVER['HTTP_HOST'] = getenv( 'OMC_DB' ) === 'live' ? 'oopsmineco.com' : 'oopsmine.test';
require __DIR__ . '/../wp-load.php';

printf(
	"%s  →  %s\n\n",
	getenv( 'OMC_DB' ) === 'live' ? 'LIVE database' : 'local database',
	home_url( '/' )
);

if ( ! class_exists( 'WHD_SEO' ) ) {
	exit( "The WHD plugin is not active, so there is nothing to score with.\n" );
}

$again = in_array( '--all', $argv, true );

$posts = get_posts( [
	'post_type'      => WHD_SEO::TYPES,
	'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future' ],
	'posts_per_page' => -1,
	'orderby'        => 'ID',
	'order'          => 'ASC',
] );

$done    = 0;
$skipped = 0;
$bands   = [ 'good' => 0, 'ok' => 0, 'bad' => 0 ];
$worst   = [];

foreach ( $posts as $post ) {
	if ( ! $again && '' !== (string) get_post_meta( $post->ID, WHD_SEO::SCORE, true ) ) {
		$skipped++;
		continue;
	}

	$result = WHD_SEO::analyse( WHD_SEO::doc_from_post( $post->ID ) );
	update_post_meta( $post->ID, WHD_SEO::SCORE, (int) $result['score'] );
	$done++;
	$bands[ $result['rating'] ]++;

	$worst[] = [
		'score' => (int) $result['score'],
		'type'  => $post->post_type,
		'id'    => $post->ID,
		'title' => get_the_title( $post ),
	];
}

printf( "%d scored", $done );
echo $skipped ? sprintf( ", %d already had one (--all does those too)\n\n", $skipped ) : "\n\n";

if ( $done ) {
	printf( "  good (80+)   %d\n", $bands['good'] );
	printf( "  needs work   %d\n", $bands['ok'] );
	printf( "  poor (<55)   %d\n\n", $bands['bad'] );

	usort( $worst, static fn( $a, $b ) => $a['score'] <=> $b['score'] );
	echo "the ten lowest:\n";
	foreach ( array_slice( $worst, 0, 10 ) as $w ) {
		printf( "  %3d  %-8s #%-6d %s\n", $w['score'], $w['type'], $w['id'], mb_substr( $w['title'], 0, 46 ) );
	}
}
