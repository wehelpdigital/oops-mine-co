<?php
/**
 * Register the prepared photographs in the media library, with the words that belong to them.
 *
 *   php .ftp-sync/import-photos.php [--dry-run]
 *   php .ftp-sync/import-photos.php --live [--dry-run]   (production write — the site owner runs this)
 *
 * `--live` does what OMC_DB=live does, without the caller needing a shell that supports an inline
 * environment-variable prefix — PowerShell does not — and without leaving the variable set for
 * whatever is typed into that window next.
 *
 * `.ftp-sync/tools/prepare-photos.php` writes the files; this gives each one a title, alt text and
 * a description, and asks WordPress to generate its sizes. Idempotent: an image already in the
 * library is found by its path and updated in place, so running it twice changes nothing twice.
 *
 * The words live in `.ftp-sync/content/photos.json` beside the picture they describe, so the alt
 * text can never drift from the photograph it is meant to be describing.
 */

/*
 * `--live` is the same thing as OMC_DB=live, set here rather than by the caller.
 *
 * The environment-variable form is bash syntax; PowerShell has no inline prefix for it and fails
 * before PHP is reached, and setting it by hand leaves it set for everything else typed into that
 * window afterwards. This has to happen before wp-config.php is read, which is what wp-load does.
 */
if ( in_array( '--live', $argv, true ) ) {
	putenv( 'OMC_DB=live' );
}

$live = 'live' === getenv( 'OMC_DB' );
$_SERVER['HTTP_HOST'] = $live ? 'oopsmineco.com' : 'oopsmine.test';
$_SERVER['REQUEST_URI'] = '/';
require dirname( __DIR__ ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

$dry = in_array( '--dry-run', $argv, true );

$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ] );
if ( $admins ) {
	wp_set_current_user( $admins[0]->ID );
}

$plan = dirname( __DIR__ ) . '/.ftp-sync/content/photos.json';
$rows = json_decode( (string) file_get_contents( $plan ), true );
if ( ! is_array( $rows ) ) {
	exit( "no usable plan at $plan\n" );
}

$uploads = wp_get_upload_dir();
$made    = 0;
$updated = 0;
$missing = [];

printf( "%s  →  %s\n\n", getenv( 'OMC_DB' ) === 'live' ? 'LIVE database' : 'local database', home_url( '/' ) );

foreach ( $rows as $row ) {
	$slug = $row['slug'] ?? '';
	if ( '' === $slug ) {
		continue;
	}
	$rel  = 'omc-2026/' . $slug . '.webp';
	$path = trailingslashit( $uploads['basedir'] ) . $rel;

	if ( ! file_exists( $path ) ) {
		$missing[] = $rel;
		continue;
	}

	// Already here? Find it by the path WordPress stores, not by guessing at the title.
	global $wpdb;
	$id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
		$rel
	) );

	if ( $dry ) {
		printf( "%-8s %s\n", $id ? 'update' : 'create', $rel );
		continue;
	}

	if ( ! $id ) {
		$id = wp_insert_attachment( [
			'post_mime_type' => 'image/webp',
			'post_title'     => $row['title'] ?? $slug,
			'post_content'   => $row['description'] ?? '',
			'post_excerpt'   => $row['alt'] ?? '',   // WordPress shows the excerpt as the caption
			'post_status'    => 'inherit',
			'post_name'      => $slug,
		], $path );
		if ( is_wp_error( $id ) || ! $id ) {
			$missing[] = $rel . ' (insert failed)';
			continue;
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
		$made++;
	} else {
		wp_update_post( [
			'ID'           => $id,
			'post_title'   => $row['title'] ?? $slug,
			'post_content' => $row['description'] ?? '',
			'post_excerpt' => $row['alt'] ?? '',
		] );
		$updated++;
	}

	update_post_meta( $id, '_wp_attachment_image_alt', (string) ( $row['alt'] ?? '' ) );

	// Hang it where it belongs: a category's tile, or a journal post's card. Matched by slug, since
	// attachment and term ids differ between this machine and live.
	$where  = '';
	$assign = $row['assign'] ?? [];
	if ( ! empty( $assign['term'] ) ) {
		list( $tax, $term_slug ) = array_pad( explode( ':', (string) $assign['term'], 2 ), 2, '' );
		$term = $term_slug ? get_term_by( 'slug', $term_slug, $tax ) : null;
		if ( $term && ! is_wp_error( $term ) ) {
			update_term_meta( $term->term_id, 'thumbnail_id', $id );
			$where = ' -> ' . $tax . '/' . $term_slug;
		} else {
			$where = ' -> ' . $assign['term'] . ' NOT FOUND';
		}
	}
	if ( ! empty( $assign['post'] ) ) {
		$post = get_page_by_path( (string) $assign['post'], OBJECT, [ 'post', 'page' ] );
		if ( $post ) {
			set_post_thumbnail( $post->ID, $id );
			$where = ' -> ' . $post->post_name;
		} else {
			$where = ' -> ' . $assign['post'] . ' NOT FOUND';
		}
	}

	printf( "#%-6d %-30s %-40s%s
", $id, $slug, mb_strimwidth( (string) ( $row['alt'] ?? '' ), 0, 38, '...' ), $where );
}

echo "\n";
if ( $missing ) {
	printf( "%d file(s) not prepared yet — run tools/prepare-photos.php first:\n", count( $missing ) );
	foreach ( array_slice( $missing, 0, 10 ) as $m ) {
		echo "  $m\n";
	}
}
printf( "%d created, %d updated%s\n", $made, $updated, $dry ? ' (dry run — nothing written)' : '' );
