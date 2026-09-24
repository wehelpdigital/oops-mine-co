<?php
/**
 * Import the style videos: media-library attachments + one page per video + the /videos/ hub.
 *
 *   php .ftp-sync/import-videos.php [--src=<dir>] [--dry-run]
 *   OMC_DB=live php .ftp-sync/import-videos.php            (live DB — the files must already be
 *                                                            in wp-content/uploads via `sync`)
 *
 * Content lives in .ftp-sync/content/videos/*.json (one per video, plus _hub.json). Media files are
 * looked up inside wp-content/uploads/<YYYY>/<MM>/ first; when they are missing and --src points at a
 * folder holding them, they are copied in. Attachments are created the way a normal upload does
 * (file in the uploads tree, attachment post, _wp_attached_file, generated metadata), so the owner can
 * pick the same video from the media library anywhere else.
 *
 * Idempotent: re-running updates the page content and leaves existing attachments alone.
 *
 * @package oopsmine-tools
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "CLI only.\n" );
}

$argv    = $_SERVER['argv'];
$dry_run = in_array( '--dry-run', $argv, true );
$src_dir = '';
foreach ( $argv as $a ) {
	if ( 0 === strpos( $a, '--src=' ) ) {
		$src_dir = rtrim( substr( $a, 6 ), '/\\' );
	}
}

$_SERVER['HTTP_HOST'] = getenv( 'OMC_DB' ) === 'live' ? 'oopsmineco.com' : 'oopsmine.test';
require dirname( __DIR__ ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$admin = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ] );
if ( $admin ) {
	wp_set_current_user( $admin[0]->ID );
}

$dir = __DIR__ . '/content/videos';
$log = [ 'db' => home_url(), 'dry_run' => $dry_run, 'videos' => [], 'hub' => null, 'notices' => [] ];

/** Find an existing attachment whose file ends with this basename. */
function omcv_find_attachment( $basename ) {
	global $wpdb;
	$id = $wpdb->get_var( $wpdb->prepare(
		"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s ORDER BY post_id DESC LIMIT 1",
		'%' . $wpdb->esc_like( $basename )
	) );
	return $id ? (int) $id : 0;
}

/**
 * Make sure $basename exists in uploads/<sub>/ and has an attachment; return the attachment id.
 * $meta: title, alt, caption, description for the attachment post.
 */
function omcv_attachment( $basename, $sub, $src_dir, array $meta, $dry_run, array &$log ) {
	$existing = omcv_find_attachment( $basename );
	if ( $existing ) {
		return $existing;
	}

	$uploads = wp_upload_dir();
	$target  = trailingslashit( $uploads['basedir'] ) . trailingslashit( $sub ) . $basename;

	if ( ! file_exists( $target ) ) {
		if ( ! $src_dir || ! file_exists( trailingslashit( $src_dir ) . $basename ) ) {
			$log['notices'][] = "missing media: $basename (not in uploads/$sub and no --src copy available)";
			return 0;
		}
		if ( $dry_run ) {
			$log['notices'][] = "would copy $basename into uploads/$sub";
			return 0;
		}
		wp_mkdir_p( dirname( $target ) );
		if ( ! copy( trailingslashit( $src_dir ) . $basename, $target ) ) {
			$log['notices'][] = "copy failed: $basename";
			return 0;
		}
	}

	if ( $dry_run ) {
		return 0;
	}

	$filetype = wp_check_filetype( $basename, null );
	$id       = wp_insert_attachment( [
		'post_mime_type' => $filetype['type'],
		'post_title'     => $meta['title'],
		'post_content'   => $meta['description'] ?? '',
		'post_excerpt'   => $meta['caption'] ?? '',
		'post_status'    => 'inherit',
	], $target, 0, true );

	if ( is_wp_error( $id ) ) {
		$log['notices'][] = "attachment failed for $basename: " . $id->get_error_message();
		return 0;
	}
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $target ) );
	if ( ! empty( $meta['alt'] ) ) {
		update_post_meta( $id, '_wp_attachment_image_alt', $meta['alt'] );
	}
	return (int) $id;
}

/** Upsert a page by slug with a template and meta. */
function omcv_page( $slug, $title, $content, $template, array $metas, $dry_run, array &$log ) {
	$page = get_page_by_path( $slug );
	$args = [
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => $title,
		'post_name'    => $slug,
		'post_content' => $content,
	];
	if ( $dry_run ) {
		$log['notices'][] = ( $page ? 'would update' : 'would create' ) . " page /$slug/";
		return $page ? (int) $page->ID : 0;
	}
	if ( $page ) {
		$args['ID'] = $page->ID;
		$id         = wp_update_post( $args, true );
	} else {
		$id = wp_insert_post( $args, true );
	}
	if ( is_wp_error( $id ) ) {
		$log['notices'][] = "page $slug failed: " . $id->get_error_message();
		return 0;
	}
	update_post_meta( $id, '_wp_page_template', $template );
	foreach ( $metas as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	// An Elementor-built page would swallow the template's output.
	foreach ( [ '_elementor_edit_mode', '_elementor_template_type', '_elementor_data', '_elementor_version' ] as $em ) {
		delete_post_meta( $id, $em );
	}
	return (int) $id;
}

/* ── the videos ─────────────────────────────────────────────────────────── */

$files = glob( $dir . '/*.json' ) ?: [];
$order = 0;

foreach ( $files as $file ) {
	$data = json_decode( file_get_contents( $file ), true );
	if ( ! is_array( $data ) || ! empty( $data['hub'] ) || empty( $data['slug'] ) || empty( $data['file'] ) ) {
		continue;
	}
	$order += 10;

	$sub      = gmdate( 'Y/m', strtotime( $data['upload_date'] ?? 'now' ) );
	$poster_id = ! empty( $data['poster'] )
		? omcv_attachment( $data['poster'], $sub, $src_dir, [
			'title'   => $data['title'] . ' — poster',
			'alt'     => $data['alt'] ?? $data['title'],
			'caption' => '',
		], $dry_run, $log )
		: 0;

	$video_id = omcv_attachment( $data['file'], $sub, $src_dir, [
		'title'       => $data['title'],
		'alt'         => $data['alt'] ?? $data['title'],
		'caption'     => $data['meta_description'] ?? '',
		'description' => $data['intro'] ?? '',
	], $dry_run, $log );

	if ( $video_id && $poster_id ) {
		set_post_thumbnail( $video_id, $poster_id ); // WordPress shows this as the video's preview image
	}

	// Real dimensions and duration come from the attachment metadata the upload generated.
	$vmeta  = $video_id ? (array) wp_get_attachment_metadata( $video_id ) : [];
	$data['attachment'] = $video_id;
	$data['poster_id']  = $poster_id;
	$data['src']        = $video_id ? wp_get_attachment_url( $video_id ) : '';
	$data['poster_url'] = $poster_id ? wp_get_attachment_url( $poster_id ) : '';
	$data['width']      = (int) ( $vmeta['width'] ?? 0 );
	$data['height']     = (int) ( $vmeta['height'] ?? 0 );
	$data['duration']   = (float) ( $vmeta['length'] ?? $vmeta['length_formatted'] ?? 0 );
	if ( ! $data['duration'] && ! empty( $vmeta['length_formatted'] ) ) {
		$parts            = array_reverse( explode( ':', $vmeta['length_formatted'] ) );
		$data['duration'] = (int) ( $parts[0] ?? 0 ) + 60 * (int) ( $parts[1] ?? 0 ) + 3600 * (int) ( $parts[2] ?? 0 );
	}

	// Plain-HTML fallback so the page still reads if the template is ever missing.
	$html = '<p>' . esc_html( $data['intro'] ?? '' ) . '</p>';
	foreach ( (array) ( $data['sections'] ?? [] ) as $s ) {
		$html .= '<h2>' . esc_html( $s['heading'] ?? '' ) . '</h2>';
		foreach ( (array) ( $s['paragraphs'] ?? [] ) as $p ) {
			$html .= '<p>' . wp_kses_post( $p ) . '</p>';
		}
		if ( ! empty( $s['bullets'] ) ) {
			$html .= '<ul>';
			foreach ( (array) $s['bullets'] as $b ) {
				$html .= '<li>' . wp_kses_post( $b ) . '</li>';
			}
			$html .= '</ul>';
		}
	}
	foreach ( (array) ( $data['faqs'] ?? [] ) as $f ) {
		$html .= '<h3>' . esc_html( $f['q'] ) . '</h3><p>' . wp_kses_post( $f['a'] ) . '</p>';
	}

	$page_id = omcv_page(
		$data['slug'],
		$data['title'],
		$html,
		'templates/page-video.php',
		[
			'_omc_video'            => wp_slash( wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
			'_omc_meta_description' => $data['meta_description'] ?? '',
			'_omc_seo_title'        => $data['seo_title'] ?? '',
		],
		$dry_run,
		$log
	);

	if ( $page_id && ! $dry_run ) {
		wp_update_post( [ 'ID' => $page_id, 'menu_order' => $order ] );
		if ( $poster_id ) {
			set_post_thumbnail( $page_id, $poster_id );
		}
	}

	$log['videos'][] = [
		'slug'       => $data['slug'],
		'page'       => $page_id,
		'video'      => $video_id,
		'poster'     => $poster_id,
		'duration'   => $data['duration'],
		'dimensions' => $data['width'] . 'x' . $data['height'],
		'url'        => $page_id ? get_permalink( $page_id ) : '',
	];
}

/* ── the hub ────────────────────────────────────────────────────────────── */

$hub_file = $dir . '/_hub.json';
$hub      = file_exists( $hub_file ) ? json_decode( file_get_contents( $hub_file ), true ) : [];
$hub      = is_array( $hub ) ? $hub : [];
$hub_id   = omcv_page(
	$hub['slug'] ?? 'videos',
	$hub['title'] ?? 'Style videos',
	$hub['content'] ?? '',
	'templates/page-videos.php',
	[
		'_omc_video_hub'        => 1,
		'_omc_intro'            => $hub['intro'] ?? '',
		'_omc_meta_description' => $hub['meta_description'] ?? '',
		'_omc_seo_title'        => $hub['seo_title'] ?? '',
	],
	$dry_run,
	$log
);
$log['hub'] = [ 'id' => $hub_id, 'url' => $hub_id ? get_permalink( $hub_id ) : '' ];

echo wp_json_encode( $log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
