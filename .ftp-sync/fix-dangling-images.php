<?php
/**
 * Pictures that pages still point at after the file has gone.
 *
 * Deleting an attachment takes the file and the record; it does not go looking through the JSON
 * that product stories and landing pages keep their copy in. A story that names a picture nobody
 * can serve any more shows a broken frame on a live product page, and nothing in WordPress will
 * mention it.
 *
 *   php .ftp-sync/fix-dangling-images.php             what is broken
 *   php .ftp-sync/fix-dangling-images.php --fix       take the dead references out
 *   php .ftp-sync/fix-dangling-images.php --live …    against production
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

$fix     = in_array( '--fix', $argv, true );
$uploads = wp_get_upload_dir();

/** Is this URL something this site can still serve? */
function omc_image_is_there( $url, array $uploads ) {
	$url = (string) $url;
	if ( '' === $url || false === stripos( $url, '/uploads/' ) ) {
		return true; // not one of ours; leave it be
	}
	$rel  = preg_replace( '#^.*/uploads/#', '', strtok( $url, '?' ) );
	$path = trailingslashit( $uploads['basedir'] ) . $rel;

	return file_exists( $path );
}

/**
 * Walk a design and drop the image blocks whose file has gone.
 *
 * @return array|null the cleaned design, or null when nothing changed
 */
function omc_strip_dead_images( array $design, array $uploads, array &$dropped ) {
	$changed = false;

	foreach ( [ 'blocks', 'rows', 'sections' ] as $key ) {
		if ( empty( $design[ $key ] ) || ! is_array( $design[ $key ] ) ) {
			continue;
		}
		$kept = [];
		foreach ( $design[ $key ] as $block ) {
			$url = '';
			if ( is_array( $block ) ) {
				$url = (string) ( $block['props']['url'] ?? $block['image'] ?? $block['url'] ?? '' );
			}
			if ( '' !== $url && ! omc_image_is_there( $url, $uploads ) ) {
				$dropped[] = basename( strtok( $url, '?' ) );
				$changed   = true;
				continue;
			}
			$kept[] = $block;
		}
		$design[ $key ] = array_values( $kept );
	}

	return $changed ? $design : null;
}

global $wpdb;

$rows = $wpdb->get_results(
	"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
	 WHERE meta_key IN ( '_whd_story', '_omc_landing', '_omc_video' ) AND meta_value LIKE '%uploads%'",
	ARRAY_A
);

$broken = 0;
$mended = 0;

foreach ( $rows as $row ) {
	$design = json_decode( (string) $row['meta_value'], true );
	if ( ! is_array( $design ) ) {
		continue;
	}

	$dropped = [];
	$clean   = omc_strip_dead_images( $design, $uploads, $dropped );
	if ( ! $dropped ) {
		continue;
	}

	$broken++;
	printf(
		"  %-9s #%-6d %-34s %s\n",
		get_post_type( $row['post_id'] ),
		$row['post_id'],
		mb_substr( get_the_title( $row['post_id'] ), 0, 32 ),
		implode( ', ', array_slice( $dropped, 0, 3 ) )
	);

	if ( $fix && $clean ) {
		update_post_meta( $row['post_id'], $row['meta_key'], wp_slash( wp_json_encode( $clean ) ) );
		$mended++;
	}
}

if ( ! $broken ) {
	exit( "Nothing points at a picture that is gone.\n" );
}

printf( "\n%d page(s) name a picture that no longer exists\n", $broken );
echo $fix
	? sprintf( "%d mended\n", $mended )
	: "\nAdd --fix to take those references out.\n";
