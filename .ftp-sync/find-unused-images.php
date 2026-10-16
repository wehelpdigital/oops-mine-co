<?php
/**
 * Which pictures nothing points at any more.
 *
 * Years of edits leave a media library full of things no page loads: heroes that were replaced,
 * crops cut for sizes the theme no longer registers, imports that were run twice. This finds
 * them — and, far more importantly, finds everything that IS still in use, because the cost of
 * being wrong is not symmetrical. A file deleted in error is a hole in a live page.
 *
 * Nothing is deleted without --delete, and the report comes first either way.
 *
 *   php .ftp-sync/find-unused-images.php                 what is unused
 *   php .ftp-sync/find-unused-images.php --orphans       stray files on disk as well
 *   php .ftp-sync/find-unused-images.php --delete        actually remove them
 *   php .ftp-sync/find-unused-images.php --live …        against the production database
 *
 * Where a reference can hide, and all of these are checked:
 *
 *   - featured images, and every product gallery
 *   - <img>, srcset and bare URLs in post content, in any post type and any status, drafts
 *     included: a draft is somebody's unfinished work, not a free deletion
 *   - the JSON the site keeps copy in: _omc_landing, _whd_story, _omc_video, the popup and
 *     email designs, the stylist
 *   - every term's thumbnail, and the Customizer's theme mods
 *   - the site icon, the WooCommerce placeholder, and the paths the theme hard-codes in
 *     omc_images(), the hero, the banners and the mosaic
 *   - .ftp-sync/content/photos.json, which is the manifest live is rebuilt from
 *
 * An attachment is only ever a candidate when none of those mention it, by id or by file name.
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

$delete  = in_array( '--delete', $argv, true );

/*
 * The trap in --live.
 *
 * OMC_DB=live points the database at production, but wp_get_upload_dir() still answers with
 * the folder on THIS machine, because it is derived from where WordPress is installed rather
 * than from anything in the database. Deleting with both set would take the rows off the live
 * site, delete the matching files from the local copy, and leave the real ones on the server
 * orphaned — the worst of all three outcomes, and silently.
 *
 * So against live this removes database rows only, and prints the files for the FTP tool to
 * take off the server afterwards.
 */
$rows_only = getenv( 'OMC_DB' ) === 'live';
if ( $rows_only && $orphans ) {
	$orphans = false;
	echo "--orphans is ignored against live: the files it would scan are the ones on this machine.

";
}

/*
 * Ids to spare whatever the reasoning says. A low attachment id collides with every price
 * and every count in the database, so a second pass that searches for the number rather than
 * for a reference will flag them — and one of them really is referenced by Elementor. Three
 * files kept needlessly costs nothing; one deleted wrongly is a hole in a page.
 */
$spare = [];
foreach ( $argv as $arg ) {
	if ( 0 === strpos( $arg, '--except=' ) ) {
		$spare = array_map( 'intval', array_filter( explode( ',', substr( $arg, 9 ) ) ) );
	}
}
$orphans = in_array( '--orphans', $argv, true );

/* ─────────────────────────── what is in use ─────────────────────────── */

global $wpdb;

$used_ids   = [];   // attachment ids somebody points at
$used_files = [];   // base file names somebody mentions
$why        = [];   // id => how it is used, for the report

$keep_id = static function ( $id, $reason ) use ( &$used_ids, &$why ) {
	$id = (int) $id;
	if ( $id > 0 ) {
		$used_ids[ $id ] = true;
		$why[ $id ]      = $why[ $id ] ?? $reason;
	}
};

/** Pull every file name that looks like an upload out of a lump of text or serialised data. */
$keep_text = static function ( $text ) use ( &$used_files ) {
	if ( ! is_scalar( $text ) || '' === (string) $text ) {
		return;
	}
	if ( preg_match_all( '#[\w\-]+\.(?:jpe?g|png|gif|webp|avif|svg)#i', (string) $text, $m ) ) {
		foreach ( $m[0] as $name ) {
			$used_files[ strtolower( $name ) ] = true;
		}
	}
};

/* ── featured images and product galleries ── */

foreach ( $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id'" ) as $id ) {
	$keep_id( $id, 'featured image' );
}
foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery'" ) as $list ) {
	foreach ( array_filter( array_map( 'intval', explode( ',', (string) $list ) ) ) as $id ) {
		$keep_id( $id, 'product gallery' );
	}
}

/* ── anything written into a post, of any type and any status ── */

$rows = $wpdb->get_results(
	"SELECT ID, post_content, post_excerpt FROM {$wpdb->posts}
	 WHERE post_status NOT IN ( 'trash', 'auto-draft' ) AND post_type <> 'attachment'",
	ARRAY_A
);
foreach ( $rows as $row ) {
	$blob = $row['post_content'] . ' ' . $row['post_excerpt'];
	$keep_text( $blob );
	// Gutenberg writes the id into the class; keep that too.
	if ( preg_match_all( '/wp-image-(\d+)/', $blob, $m ) ) {
		foreach ( $m[1] as $id ) {
			$keep_id( $id, 'in the copy of #' . $row['ID'] );
		}
	}
}

/* ── the JSON the site keeps its copy in, plus anything else in post meta ── */

$meta_rows = $wpdb->get_results(
	"SELECT meta_key, meta_value FROM {$wpdb->postmeta}
	 WHERE meta_key IN ( '_omc_landing', '_whd_story', '_omc_video', '_ip_product_video_thumb', '_elementor_data', '_product_image_gallery' )
	    OR meta_key LIKE '%image%' OR meta_key LIKE '%thumb%' OR meta_key LIKE '%gallery%' OR meta_key LIKE '%photo%'",
	ARRAY_A
);
foreach ( $meta_rows as $row ) {
	$keep_text( $row['meta_value'] );
	if ( is_numeric( trim( (string) $row['meta_value'] ) ) ) {
		$keep_id( $row['meta_value'], 'meta ' . $row['meta_key'] );
	}
	if ( preg_match_all( '/"id"\s*:\s*"?(\d{1,7})"?/', (string) $row['meta_value'], $m ) ) {
		foreach ( $m[1] as $id ) {
			$keep_id( $id, 'meta ' . $row['meta_key'] );
		}
	}
}

/* ── options: designs, settings, theme mods ── */

foreach ( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_value LIKE '%wp-content/uploads%' OR option_name IN ( 'site_icon', 'woocommerce_placeholder_image', 'whd_popups', 'whd_emails' ) OR option_name LIKE 'theme_mods_%'", ARRAY_A ) as $row ) {
	$keep_text( $row['option_value'] );
	if ( is_numeric( trim( (string) $row['option_value'] ) ) ) {
		$keep_id( $row['option_value'], 'option ' . $row['option_name'] );
	}
	$un = maybe_unserialize( $row['option_value'] );
	if ( is_array( $un ) ) {
		array_walk_recursive( $un, static function ( $v, $k ) use ( $keep_text, $keep_id, $row ) {
			$keep_text( $v );
			if ( is_numeric( $v ) && in_array( (string) $k, [ 'id', 'image_id', 'thumbnail_id', 'attachment_id', 'logo', 'custom_logo', 'site_icon' ], true ) ) {
				$keep_id( $v, 'option ' . $row['option_name'] );
			}
		} );
	}
}

/* ── term meta: category thumbnails ── */

foreach ( $wpdb->get_results( "SELECT meta_key, meta_value FROM {$wpdb->termmeta}", ARRAY_A ) as $row ) {
	$keep_text( $row['meta_value'] );
	if ( is_numeric( trim( (string) $row['meta_value'] ) ) && false !== stripos( $row['meta_key'], 'thumb' ) ) {
		$keep_id( $row['meta_value'], 'category thumbnail' );
	}
}

/* ── the paths the theme hard-codes ── */

foreach ( [ 'omc_images', 'omc_hero_images', 'omc_home_banners', 'omc_home_mosaic', 'omc_testimonials', 'omc_fb_live' ] as $fn ) {
	if ( ! function_exists( $fn ) ) {
		continue;
	}
	// array_walk_recursive takes its array by reference, so it needs a variable, not a cast.
	$value = (array) call_user_func( $fn );
	array_walk_recursive( $value, static function ( $v ) use ( $keep_text, $keep_id ) {
		$keep_text( $v );
		if ( is_int( $v ) && $v > 0 ) {
			$keep_id( $v, 'theme configuration' );
		}
	} );
}

/* ── and the manifest live is rebuilt from ── */

$manifest = __DIR__ . '/content/photos.json';
if ( file_exists( $manifest ) ) {
	foreach ( (array) json_decode( file_get_contents( $manifest ), true ) as $row ) {
		if ( ! empty( $row['slug'] ) ) {
			$used_files[ strtolower( $row['slug'] . '.webp' ) ] = true;
		}
	}
}

/* ─────────────────────────── the verdict ─────────────────────────── */

$all = get_posts( [
	'post_type'      => 'attachment',
	'post_mime_type' => 'image',
	'post_status'    => 'inherit',
	'posts_per_page' => -1,
	'fields'         => 'ids',
] );

$uploads = wp_get_upload_dir();
$unused  = [];

/*
 * One file, two records. An import run twice leaves two attachments pointing at the same
 * file — the WooCommerce placeholder on live is exactly this — and the one nothing points at
 * looks deletable while its twin is what the shop actually uses. Removing the row is
 * harmless; removing the file breaks the twin. So a file shared with any other attachment is
 * never offered for deletion at all.
 */
$file_users = [];
foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'" ) as $one ) {
	$one = strtolower( (string) $one );
	$file_users[ $one ] = ( $file_users[ $one ] ?? 0 ) + 1;
}
$kept    = 0;
$bytes   = 0;
$shared  = [];

foreach ( $all as $id ) {
	$file = (string) get_post_meta( $id, '_wp_attached_file', true );
	$base = strtolower( basename( $file ) );
	$meta = wp_get_attachment_metadata( $id );

	$in_use = isset( $used_ids[ $id ] ) || isset( $used_files[ $base ] );

	/* A crop counts as the picture it was cut from: if anything points at a derived size, the
	   original stays. */
	if ( ! $in_use && ! empty( $meta['sizes'] ) ) {
		foreach ( $meta['sizes'] as $size ) {
			if ( isset( $used_files[ strtolower( $size['file'] ?? '' ) ] ) ) {
				$in_use = true;
				break;
			}
		}
	}

	/* An attachment attached to something that still exists is somebody's, even if no page has
	   got round to showing it. */
	$parent = (int) get_post( $id )->post_parent;
	if ( ! $in_use && $parent && get_post_status( $parent ) && 'trash' !== get_post_status( $parent ) ) {
		$in_use = true;
		$why[ $id ] = 'attached to ' . get_post_type( $parent ) . ' #' . $parent;
	}

	if ( $in_use ) {
		$kept++;
		continue;
	}

	$path   = trailingslashit( $uploads['basedir'] ) . $file;
	$size   = file_exists( $path ) ? filesize( $path ) : 0;
	foreach ( (array) ( $meta['sizes'] ?? [] ) as $one ) {
		$crop = trailingslashit( dirname( $path ) ) . ( $one['file'] ?? '' );
		$size += file_exists( $crop ) ? filesize( $crop ) : 0;
	}
	if ( in_array( (int) $id, $spare, true ) ) {
		$kept++;
		continue;
	}

	if ( ( $file_users[ strtolower( $file ) ] ?? 0 ) > 1 ) {
		$kept++;
		$shared[] = $file;
		continue;
	}

	$bytes   += $size;
	$unused[] = [ 'id' => $id, 'file' => $file, 'bytes' => $size, 'title' => get_the_title( $id ) ];
}

printf( "%d images in the library\n", count( $all ) );
printf( "  still pointed at : %d\n", $kept );
printf( "  nothing uses     : %d   (%s with their crops)\n\n", count( $unused ), size_format( $bytes ) );

usort( $unused, static fn( $a, $b ) => $b['bytes'] <=> $a['bytes'] );
foreach ( array_slice( $unused, 0, 25 ) as $u ) {
	printf( "  %7s  #%-6d %s\n", size_format( $u['bytes'] ), $u['id'], $u['file'] );
}
if ( count( $unused ) > 25 ) {
	printf( "  … and %d more\n", count( $unused ) - 25 );
}

/* ─────────────────────────── stray files ─────────────────────────── */

$stray = [];
if ( $orphans ) {
	$known = [];
	foreach ( $all as $id ) {
		$file = (string) get_post_meta( $id, '_wp_attached_file', true );
		$known[ strtolower( basename( $file ) ) ] = true;
		foreach ( (array) ( wp_get_attachment_metadata( $id )['sizes'] ?? [] ) as $one ) {
			$known[ strtolower( $one['file'] ?? '' ) ] = true;
		}
	}

	$strayBytes = 0;
	$dir = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $uploads['basedir'], FilesystemIterator::SKIP_DOTS ) );
	foreach ( $dir as $f ) {
		if ( ! $f->isFile() || ! preg_match( '/\.(jpe?g|png|gif|webp|avif)$/i', $f->getFilename() ) ) {
			continue;
		}
		$name = strtolower( $f->getFilename() );
		if ( isset( $known[ $name ] ) || isset( $used_files[ $name ] ) ) {
			continue;
		}
		/* Windows hands back backslashes while basedir carries forward ones, so the prefix never
		   matched and the path was stored whole — then prefixed again, and nothing was found. */
		$stray[] = ltrim( str_replace(
			str_replace( '\\', '/', trailingslashit( $uploads['basedir'] ) ),
			'',
			str_replace( '\\', '/', $f->getPathname() )
		), '/' );
		$strayBytes += $f->getSize();
	}

	printf( "\nfiles on disk that no attachment knows about: %d  (%s)\n", count( $stray ), size_format( $strayBytes ) );
	foreach ( array_slice( $stray, 0, 12 ) as $s ) {
		printf( "  %s\n", $s );
	}
	if ( count( $stray ) > 12 ) {
		printf( "  … and %d more\n", count( $stray ) - 12 );
	}
}

/* ─────────────────────────── removal ─────────────────────────── */

/* The list, for anything that wants to check this work rather than trust it. */
if ( in_array( '--json', $argv, true ) ) {
	$to = __DIR__ . '/../unused.json';
	file_put_contents( $to, wp_json_encode( $unused, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	printf( "
written %s
", $to );
}

if ( ! $delete ) {
	echo "\nNothing has been deleted. Add --delete to remove the unused ones.\n";
	exit;
}

$gone = 0;
$freed = 0;
foreach ( $unused as $u ) {
	$freed += $u['bytes'];
	// false: leave the files alone, because the ones reachable from here are the local copies.
	if ( wp_delete_attachment( $u['id'], ! $rows_only ) ) {
		$gone++;
	}
}
printf( "\n%d attachments removed, %s freed\n", $gone, size_format( $freed ) );

/* Against live the files are still sitting on the server, so say which ones and how to take
   them off. The list is a plain file because the next step is a different tool. */
if ( $rows_only && $gone ) {
	$list = __DIR__ . '/../remove-from-live.txt';
	$paths = array_map( static fn( $u ) => 'wp-content/uploads/' . $u['file'], $unused );
	file_put_contents( $list, implode( "\n", $paths ) . "\n" );
	printf(
		"\nThe rows are gone from live. The files are still on the server — %d of them, listed in\n  %s\nTake them off with:  node .ftp-sync/server.mjs rm <path> [path…]\n",
		count( $paths ),
		$list
	);
}

/*
 * The crops left behind when a picture was replaced: still on disk, in no attachment record,
 * referenced by nothing. They are the clearest waste there is, and the reason the library grew
 * two sets of sizes for every hero.
 */
if ( $orphans ) {
	$swept      = 0;
	$sweptBytes = 0;
	foreach ( $stray as $rel ) {
		$path = trailingslashit( $uploads['basedir'] ) . $rel;
		if ( ! file_exists( $path ) ) {
			continue;
		}
		$size = filesize( $path );
		if ( @unlink( $path ) ) {
			$swept++;
			$sweptBytes += $size;
		}
	}
	printf( "%d stray files removed, %s freed\n", $swept, size_format( $sweptBytes ) );
}
