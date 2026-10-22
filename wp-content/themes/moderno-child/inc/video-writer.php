<?php
/**
 * Style videos from the dashboard: add a video, edit its page's words, and have them written by AI
 * from what is in the video.
 *
 * Page Editor → Shoppable videos is the screen (inc/video-shop.php draws it). This module is what
 * sits behind it:
 *
 *   - "Add a video": a video from the media library becomes a video page — the page-video.php
 *     template, `_omc_video` with the file, its size and length, and a poster taken from the video
 *     itself in the browser. Published at once, or kept as a draft. `_omc_video_source = dashboard`.
 *   - The page's words — title, search title and snippet, intro, alt text, chapters, sections and
 *     questions — are saved in `_omc_video_copy` and laid over `_omc_video` by the `omc_video_data`
 *     filter, never written into it: .ftp-sync/import-videos.php rewrites `_omc_video` for the
 *     videos it imports, and would otherwise put the old words back.
 *   - "Write with AI": the browser takes stills from the video at even steps, the model (WHD → AI
 *     content) reads them with anything the shop wants to explain and the pieces tagged in the
 *     video, and writes the words as JSON — aimed at the shop's keyword list, like every AI writer
 *     here. Nothing is saved until Save is pressed: the words land in the form to be read first.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

const OMC_VIDEO_COPY   = '_omc_video_copy';
const OMC_VIDEO_SOURCE = '_omc_video_source';

/** The words a video page carries that can be edited here. */
function omc_video_copy_keys() {
	return [ 'title', 'seo_title', 'meta_description', 'intro', 'alt', 'chapters', 'sections', 'faqs' ];
}

/** The edited words over the imported ones: a field left empty keeps what was there. */
function omc_video_copy_over( $data, $post_id ) {
	$copy = $post_id ? json_decode( (string) get_post_meta( $post_id, OMC_VIDEO_COPY, true ), true ) : null;
	if ( is_array( $copy ) ) {
		foreach ( omc_video_copy_keys() as $key ) {
			if ( isset( $copy[ $key ] ) && '' !== $copy[ $key ] && [] !== $copy[ $key ] ) {
				$data[ $key ] = $copy[ $key ];
			}
		}
	}
	return $data;
}
add_filter( 'omc_video_data', 'omc_video_copy_over', 10, 2 );

/** A video's words as imported (or as added), before anything edited here. */
function omc_video_base( $page_id ) {
	remove_filter( 'omc_video_data', 'omc_video_copy_over', 10 );
	$v = omc_video_data( $page_id );
	add_filter( 'omc_video_data', 'omc_video_copy_over', 10, 2 );
	return $v;
}

/** Words in their stored shape: plain lines, a few inline tags in paragraphs, chapters in time order. */
function omc_video_copy_clean( array $in, $duration = 0 ) {
	$inline = [ 'strong' => [], 'em' => [], 'b' => [], 'i' => [], 'a' => [ 'href' => [] ] ];
	$line   = static fn( $v ) => trim( sanitize_text_field( is_scalar( $v ) ? (string) $v : '' ) );
	$prose  = static fn( $v ) => trim( wp_kses( is_scalar( $v ) ? (string) $v : '', $inline ) );
	$out    = [
		'title'            => $line( $in['title'] ?? '' ),
		'seo_title'        => $line( $in['seo_title'] ?? '' ),
		'meta_description' => $line( $in['meta_description'] ?? '' ),
		'intro'            => $prose( $in['intro'] ?? '' ),
		'alt'              => $line( $in['alt'] ?? '' ),
		'chapters'         => [],
		'sections'         => [],
		'faqs'             => [],
	];
	$seen = [];
	foreach ( array_slice( (array) ( $in['chapters'] ?? [] ), 0, 20 ) as $c ) {
		$at    = max( 0, (int) round( (float) ( $c['at'] ?? 0 ) ) );
		$at    = $duration ? min( $at, (int) $duration ) : $at;
		$label = $line( $c['label'] ?? '' );
		if ( '' !== $label && ! isset( $seen[ $at ] ) ) {
			$seen[ $at ]       = true;
			$out['chapters'][] = [ 'at' => $at, 'label' => $label ];
		}
	}
	usort( $out['chapters'], static fn( $a, $b ) => $a['at'] <=> $b['at'] );
	foreach ( array_slice( (array) ( $in['sections'] ?? [] ), 0, 8 ) as $sec ) {
		$paras   = array_values( array_filter( array_map( $prose, array_slice( (array) ( $sec['paragraphs'] ?? [] ), 0, 6 ) ), 'strlen' ) );
		$bullets = array_values( array_filter( array_map( $prose, array_slice( (array) ( $sec['bullets'] ?? [] ), 0, 10 ) ), 'strlen' ) );
		$heading = $line( $sec['heading'] ?? '' );
		if ( '' !== $heading || $paras || $bullets ) {
			$out['sections'][] = [ 'heading' => $heading, 'paragraphs' => $paras, 'bullets' => $bullets ];
		}
	}
	foreach ( array_slice( (array) ( $in['faqs'] ?? [] ), 0, 10 ) as $f ) {
		$q = $line( $f['q'] ?? '' );
		$a = $prose( $f['a'] ?? '' );
		if ( '' !== $q && '' !== $a ) {
			$out['faqs'][] = [ 'q' => $q, 'a' => $a ];
		}
	}
	return $out;
}

/** The words of one video as the screen edits them, with its focus keyphrase. */
function omc_video_copy_of( $page_id, array $v ) {
	$out = [];
	foreach ( omc_video_copy_keys() as $key ) {
		$out[ $key ] = $v[ $key ] ?? '';
	}
	$out              = omc_video_copy_clean( $out, (int) $v['duration'] );
	$out['keyphrase'] = (string) get_post_meta( $page_id, '_whd_focus_keyphrase', true );
	return $out;
}

/**
 * Keep a video page's words — only the ones that differ from what was imported, so a better import
 * still reaches every field nobody changed here (and emptying a field brings the imported one back).
 * The page's own title, snippet and plain-HTML fallback follow for a video added here; an imported
 * one keeps its post as the importer wrote it.
 */
function omc_video_copy_save( $page_id, array $given ) {
	$v = omc_video_base( $page_id );
	if ( ! $v ) {
		return null;
	}
	$copy = omc_video_copy_clean( $given, (int) $v['duration'] );
	$base = omc_video_copy_clean( $v, (int) $v['duration'] );
	foreach ( $copy as $key => $value ) {
		if ( $value === $base[ $key ] || '' === $value || [] === $value ) { // empty means "the imported words"
			unset( $copy[ $key ] );
		}
	}
	if ( $copy ) {
		update_post_meta( $page_id, OMC_VIDEO_COPY, wp_slash( wp_json_encode( $copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
	} else {
		delete_post_meta( $page_id, OMC_VIDEO_COPY );
	}
	$key = sanitize_text_field( (string) ( $given['keyphrase'] ?? '' ) );
	if ( '' !== $key ) {
		update_post_meta( $page_id, '_whd_focus_keyphrase', $key );
	} else {
		delete_post_meta( $page_id, '_whd_focus_keyphrase' );
	}
	if ( 'dashboard' === get_post_meta( $page_id, OMC_VIDEO_SOURCE, true ) ) {
		$v    = omc_video_data( $page_id );
		$html = $v['intro'] ? '<p>' . wp_kses_post( $v['intro'] ) . '</p>' : '';
		foreach ( (array) $v['sections'] as $s ) {
			$html .= ( $s['heading'] ? '<h2>' . esc_html( $s['heading'] ) . '</h2>' : '' );
			foreach ( (array) $s['paragraphs'] as $p ) {
				$html .= '<p>' . wp_kses_post( $p ) . '</p>';
			}
			if ( $s['bullets'] ) {
				$html .= '<ul><li>' . implode( '</li><li>', array_map( 'wp_kses_post', (array) $s['bullets'] ) ) . '</li></ul>';
			}
		}
		foreach ( (array) $v['faqs'] as $f ) {
			$html .= '<h3>' . esc_html( $f['q'] ) . '</h3><p>' . wp_kses_post( $f['a'] ) . '</p>';
		}
		wp_update_post( [ 'ID' => $page_id, 'post_title' => $v['title'], 'post_content' => $html ] );
		update_post_meta( $page_id, '_omc_meta_description', $v['meta_description'] );
		update_post_meta( $page_id, '_omc_seo_title', $v['seo_title'] );
	}
	return omc_video_copy_of( $page_id, omc_video_data( $page_id ) );
}

/** A still from the browser (a JPEG data URL) into the media library. @return int attachment id, 0 on failure. */
function omc_video_save_still( $data_url, $name ) {
	if ( ! is_string( $data_url ) || ! preg_match( '#^data:image/jpeg;base64,#', $data_url ) ) {
		return 0;
	}
	$bytes = base64_decode( substr( $data_url, strlen( 'data:image/jpeg;base64,' ) ), true );
	$info  = $bytes ? @getimagesizefromstring( $bytes ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a broken file is refused below
	if ( ! $info || 'image/jpeg' !== $info['mime'] || strlen( $bytes ) > 8 * MB_IN_BYTES ) {
		return 0;
	}
	$file = wp_upload_bits( sanitize_file_name( ( sanitize_title( $name ) ?: 'video' ) . '-poster.jpg' ), null, $bytes );
	if ( ! empty( $file['error'] ) ) {
		return 0;
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$id = wp_insert_attachment( [
		'post_mime_type' => 'image/jpeg',
		/* translators: %s: video title */
		'post_title'     => sprintf( __( '%s — poster', 'moderno-child' ), $name ),
		'post_status'    => 'inherit',
	], $file['file'] );
	if ( ! $id || is_wp_error( $id ) ) {
		return 0;
	}
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file['file'] ) );
	update_post_meta( $id, '_wp_attachment_image_alt', $name );
	return (int) $id;
}

/** A video from the media library as a new video page. @return array|WP_Error [ page, slug ] */
function omc_video_create( array $args ) {
	$att  = absint( $args['attachment'] ?? 0 );
	$mime = $att ? (string) get_post_mime_type( $att ) : '';
	if ( 0 !== strpos( $mime, 'video/' ) ) {
		return new WP_Error( 'omc_video', __( 'Choose a video file from the media library.', 'moderno-child' ) );
	}
	$title = sanitize_text_field( (string) ( $args['title'] ?? '' ) ) ?: html_entity_decode( get_the_title( $att ), ENT_QUOTES, 'UTF-8' );
	$meta  = (array) wp_get_attachment_metadata( $att );
	$w     = absint( $args['width'] ?? 0 ) ?: (int) ( $meta['width'] ?? 0 );
	$h     = absint( $args['height'] ?? 0 ) ?: (int) ( $meta['height'] ?? 0 );
	$dur   = (float) ( $args['duration'] ?? 0 ) ?: (float) ( $meta['length'] ?? 0 );

	// A key no other video has: the moments and the importer both go by it.
	$taken = [];
	$first = 1;
	foreach ( omc_video_pages( true ) as $id => $v ) {
		$taken[ $v['slug'] ] = true;
		$first               = min( $first, (int) get_post_field( 'menu_order', $id ) );
	}
	$base = sanitize_key( sanitize_title( $title ) ) ?: 'style-video'; // the moments are keyed by sanitize_key
	$slug = $base;
	for ( $i = 2; isset( $taken[ $slug ] ) || get_page_by_path( $slug ); $i++ ) {
		$slug = $base . '-' . $i;
	}

	$status  = 'draft' === ( $args['status'] ?? '' ) ? 'draft' : 'publish';
	$page_id = wp_insert_post( [
		'post_type'    => 'page',
		'post_status'  => $status,
		'post_title'   => $title,
		'post_name'    => $slug,
		'post_content' => '',
		'menu_order'   => $first - 1, // ahead of every other video: the hub lists them by this, lowest first
	], true );
	if ( is_wp_error( $page_id ) ) {
		return $page_id;
	}

	$poster = omc_video_save_still( $args['poster'] ?? '', $title );
	if ( $poster ) {
		set_post_thumbnail( $att, $poster );
		set_post_thumbnail( $page_id, $poster );
	}
	$data = [
		'slug'             => $slug,
		'title'            => $title,
		'seo_title'        => '',
		'meta_description' => '',
		'intro'            => '',
		'alt'              => $title,
		'orientation'      => $w && $h ? ( $w < $h ? 'portrait' : ( $w === $h ? 'square' : 'landscape' ) ) : 'portrait',
		'upload_date'      => current_time( 'Y-m-d' ),
		'attachment'       => $att,
		'poster_id'        => $poster,
		'src'              => (string) wp_get_attachment_url( $att ),
		'poster_url'       => $poster ? (string) wp_get_attachment_url( $poster ) : '',
		'width'            => $w,
		'height'           => $h,
		'duration'         => round( $dur, 2 ),
		'chapters'         => [],
		'sections'         => [],
		'faqs'             => [],
		'shop'             => [],
		'related'          => [],
	];
	update_post_meta( $page_id, '_wp_page_template', 'templates/page-video.php' );
	update_post_meta( $page_id, OMC_VIDEO_META, wp_slash( wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
	update_post_meta( $page_id, OMC_VIDEO_SOURCE, 'dashboard' );
	return [ 'page' => (int) $page_id, 'slug' => $slug ];
}

/* ─────────────────────────── writing it with AI ─────────────────────────── */

function omc_video_ai_ready() {
	return class_exists( 'WHD_AI' ) && WHD_AI::ready();
}

/**
 * Words for a video page, written from stills of the video.
 *
 * @param int    $page_id The video page.
 * @param array  $stills  [ [ at (seconds), path (a JPEG on disk) ] ], in time order.
 * @param string $notes   What the shop wants to say about it, in its own words.
 * @param bool   $pieces  Tell the model about the pieces tagged in the video.
 * @return array|WP_Error [ content, keyphrase, warnings ]
 */
function omc_video_ai_write( $page_id, array $stills, $notes, $pieces ) {
	$v = omc_video_data( $page_id );
	if ( ! $v ) {
		return new WP_Error( 'omc_video', __( 'That video page is gone.', 'moderno-child' ) );
	}
	$duration = (int) round( (float) $v['duration'] );
	$lines    = [];
	foreach ( $stills as $i => $s ) {
		$lines[] = sprintf( 'Still %d — %s into the video (at = %d)', $i + 1, omc_video_clock( $s['at'] ), $s['at'] );
	}
	$tagged = [];
	if ( $pieces ) {
		foreach ( omc_video_moments( $v['slug'] ) as $m ) {
			$p = function_exists( 'wc_get_product' ) ? wc_get_product( $m['product'] ) : null;
			if ( $p ) {
				$tagged[] = sprintf( '- at %s: %s — %s', omc_video_clock( $m['at'] ), html_entity_decode( $p->get_name(), ENT_QUOTES, 'UTF-8' ), html_entity_decode( wp_strip_all_tags( $p->get_price_html() ), ENT_QUOTES, 'UTF-8' ) );
			}
		}
	}

	$about = trim( $v['title'] . ' ' . $notes . ' ' . implode( ' ', $tagged ) );
	$list  = class_exists( 'WHD_AI_Keywords' ) ? WHD_AI_Keywords::shortlist( $about, (int) $page_id, 12, true ) : [];
	$kw    = [];
	foreach ( $list as $k ) {
		$kw[] = '- ' . $k['keyword'] . ( ! empty( $k['taken'] ) ? ' — already another page\'s focus keyphrase: work it in, never choose it as "keyphrase"' : '' );
	}

	$prompt = "## The video\nA short style video for the shop's website, " . $duration . " seconds long. You are given " . count( $stills ) . " stills from it, in this order:\n" . implode( "\n", $lines )
		. ( '' !== trim( $v['title'] ) ? "\n\nIts working title: " . $v['title'] : '' )
		. ( '' !== trim( $notes ) ? "\n\n## What the shop says about it — facts to use, in the shop's own words\n" . trim( $notes ) : '' )
		. ( $tagged ? "\n\n## The pieces tagged in it (name — price)\n" . implode( "\n", $tagged ) : '' )
		. ( $kw ? "\n\n## The shop's keyword list — researched search phrases that fit this video\n" . implode( "\n", $kw ) : '' )
		. "\n\n## What to write\n"
		. "The written version of the video: what a shopper with the sound off reads beside the player. Describe what the stills show and what the shop says — the pieces, the fabric and colour you can see, the cut, how it moves, how it is styled. "
		. "Do not invent anything: no prices other than those given, no sizes, stock, delivery, discounts, materials the shop did not state or the stills do not show, and no names of people or other brands.\n"
		. "Reply as JSON and nothing else, in exactly this shape:\n"
		. '{"title":"","seo_title":"","meta_description":"","intro":"","alt":"","keyphrase":"","keywords":[],"chapters":[{"at":0,"label":""}],"sections":[{"heading":"","paragraphs":[""],"bullets":[""]}],"faqs":[{"q":"","a":""}]}' . "\n"
		. "- title: the page's heading, a plain description of the video, at most 70 characters.\n"
		. "- seo_title: at most 60 characters. meta_description: 140 to 155 characters.\n"
		. "- intro: one or two sentences under the title.\n"
		. "- alt: what the video shows, for someone who cannot see it, at most 125 characters.\n"
		. "- chapters: 3 to 6 key moments, in order; each \"at\" is one of the stills' \"at\" values above and the first is 0; labels of 2 to 6 words.\n"
		. "- sections: 2 to 4, each a plain heading, 1 to 3 paragraphs of 2 to 4 sentences, and optionally 3 to 5 short bullet facts.\n"
		. "- faqs: 2 to 4 questions a shopper would ask about what is in the video, answered only from what is known; when the answer is not known, choose a different question.\n"
		. ( $kw
			? "- keyphrase: the ONE phrase from the keyword list that describes this video most exactly, copied exactly. keywords: two to four more from the list that genuinely fit, copied exactly. The keyphrase appears word for word in the title or the intro; every keyword appears word for word somewhere in the intro, sections or faqs, in a sentence that reads naturally.\n"
			: "- keyphrase: what a shopper would type to find this, two to four words, appearing word for word in the title. keywords: empty.\n" );

	$raw = WHD_AI::call( WHD_AI::system_prompt(), $prompt, [
		'json'       => true,
		'images'     => wp_list_pluck( $stills, 'path' ),
		// A model that reasons first spends that from the same allowance: with ten stills, 4,000
		// left it 160 tokens of reply — cut off part way, and unreadable as JSON.
		'max_tokens' => 16000,
		'timeout'    => 240, // a background job: nobody's page is waiting on it
	] );
	if ( is_wp_error( $raw ) ) {
		return $raw;
	}
	if ( preg_match( '/\{.*\}/s', (string) $raw, $m ) ) {
		$raw = $m[0];
	}
	$data = json_decode( (string) $raw, true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'omc_video', __( 'The model’s answer could not be read. Try again.', 'moderno-child' ) );
	}

	// Chapters only where there is a still to point at: the model was told to, and is held to it.
	$times = wp_list_pluck( $stills, 'at' );
	if ( ! empty( $data['chapters'] ) && is_array( $data['chapters'] ) ) {
		foreach ( $data['chapters'] as $i => $c ) {
			$at = (int) ( $c['at'] ?? 0 );
			$near = 0;
			foreach ( $times as $t ) {
				$near = abs( $t - $at ) < abs( $near - $at ) ? $t : $near;
			}
			$data['chapters'][ $i ]['at'] = 0 === $i ? 0 : $near;
		}
	}
	$content   = omc_video_copy_clean( $data, $duration );
	$keyphrase = sanitize_text_field( (string) ( $data['keyphrase'] ?? '' ) );
	if ( method_exists( 'WHD_AI_Studio', 'proper' ) ) {
		// The keyword list is lower case and the model copies it: "korean" → "Korean", as the studio does.
		array_walk_recursive( $content, static function ( &$value ) {
			$value = is_string( $value ) ? WHD_AI_Studio::proper( $value ) : $value;
		} );
	}

	// What the shop checks every page for: the banned words, and every keyword in the words.
	$text     = strtolower( wp_strip_all_tags( wp_json_encode( $content, JSON_UNESCAPED_UNICODE ) ) );
	$warnings = [];
	foreach ( WHD_AI::banned_words() as $word ) {
		if ( '' !== $word && preg_match( '/\b' . preg_quote( strtolower( $word ), '/' ) . '\b/u', $text ) ) {
			/* translators: %s: a word the shop does not use */
			$warnings[] = sprintf( __( 'Uses “%s”, which is on the banned list — change it before saving.', 'moderno-child' ), $word );
		}
	}
	foreach ( array_merge( [ $keyphrase ], (array) ( $data['keywords'] ?? [] ) ) as $phrase ) {
		$phrase = strtolower( trim( (string) $phrase ) );
		if ( '' !== $phrase && false === strpos( $text, $phrase ) ) {
			/* translators: %s: a search phrase */
			$warnings[] = sprintf( __( '“%s” is not in the words yet — work it in, or change the keyphrase.', 'moderno-child' ), $phrase );
		}
	}
	return [ 'content' => $content + [ 'keyphrase' => $keyphrase ], 'warnings' => $warnings ];
}

/* ─────────────────────────── the wires ─────────────────────────── */

add_action( 'rest_api_init', static function () {
	$can = static fn() => current_user_can( 'edit_theme_options' );
	$page_of = static function ( $id ) {
		$id = absint( $id );
		return $id && 'page' === get_post_type( $id ) && omc_video_data( $id ) ? $id : 0;
	};

	register_rest_route( 'omc/v1', '/video-shop/create', [
		'methods'             => 'POST',
		'permission_callback' => static fn() => current_user_can( 'edit_theme_options' ) && current_user_can( 'upload_files' ) && current_user_can( 'publish_pages' ),
		'callback'            => static function ( WP_REST_Request $r ) {
			$made = omc_video_create( (array) $r->get_json_params() );
			return is_wp_error( $made ) ? new WP_REST_Response( [ 'message' => $made->get_error_message() ], 400 ) : new WP_REST_Response( $made, 200 );
		},
	] );

	register_rest_route( 'omc/v1', '/video-shop/status', [
		'methods'             => 'POST',
		'permission_callback' => static fn() => current_user_can( 'edit_theme_options' ) && current_user_can( 'publish_pages' ),
		'callback'            => static function ( WP_REST_Request $r ) use ( $page_of ) {
			$id = $page_of( $r->get_param( 'page' ) );
			if ( ! $id ) {
				return new WP_REST_Response( [ 'message' => __( 'That video page is gone.', 'moderno-child' ) ], 404 );
			}
			$status = 'publish' === $r->get_param( 'status' ) ? 'publish' : 'draft';
			wp_update_post( [ 'ID' => $id, 'post_status' => $status ] );
			return new WP_REST_Response( [ 'status' => get_post_status( $id ), 'view' => 'publish' === get_post_status( $id ) ? get_permalink( $id ) : get_preview_post_link( $id ) ], 200 );
		},
	] );

	register_rest_route( 'omc/v1', '/video-shop/write', [
		'methods'             => 'POST',
		'permission_callback' => $can,
		'callback'            => static function ( WP_REST_Request $r ) use ( $page_of ) {
			$id = $page_of( $r->get_param( 'page' ) );
			if ( ! $id ) {
				return new WP_REST_Response( [ 'message' => __( 'That video page is gone.', 'moderno-child' ) ], 404 );
			}
			if ( ! omc_video_ai_ready() ) {
				return new WP_REST_Response( [ 'message' => __( 'Connect an AI model first, in WHD → AI content.', 'moderno-child' ) ], 400 );
			}
			// The stills, to files the model's picture reader can open; removed once it has read them.
			$stills = [];
			foreach ( array_slice( (array) $r->get_param( 'frames' ), 0, 10 ) as $i => $f ) {
				$data  = (string) ( $f['data'] ?? '' );
				$bytes = 0 === strpos( $data, 'data:image/jpeg;base64,' ) ? base64_decode( substr( $data, 23 ), true ) : false;
				$info  = $bytes ? @getimagesizefromstring( $bytes ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors -- refused below
				if ( ! $info || 'image/jpeg' !== $info['mime'] || strlen( $bytes ) > 3 * MB_IN_BYTES ) {
					continue;
				}
				$path = trailingslashit( get_temp_dir() ) . 'omc-still-' . wp_generate_password( 10, false, false ) . '-' . $i . '.jpg';
				if ( false !== file_put_contents( $path, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					$stills[] = [ 'at' => max( 0, (int) ( $f['at'] ?? 0 ) ), 'path' => $path ];
				}
			}
			if ( ! $stills ) {
				return new WP_REST_Response( [ 'message' => __( 'No stills came through from the video. Play it once, then try again.', 'moderno-child' ) ], 400 );
			}
			$notes  = substr( sanitize_textarea_field( (string) $r->get_param( 'notes' ) ), 0, 3000 );
			$pieces = (bool) $r->get_param( 'pieces' );
			$work   = static function () use ( $id, $stills, $notes, $pieces ) {
				try {
					$out = omc_video_ai_write( $id, $stills, $notes, $pieces );
				} finally {
					foreach ( $stills as $s ) {
						wp_delete_file( $s['path'] );
					}
				}
				return is_wp_error( $out ) ? new WP_REST_Response( [ 'message' => $out->get_error_message() ], 400 ) : $out;
			};
			// A model reading ten pictures takes longer than the host lets a request wait: a job, polled.
			if ( class_exists( 'WHD_AI_Studio' ) ) {
				return WHD_AI_Studio::background( 'video-write', $work );
			}
			$out = $work();
			return $out instanceof WP_REST_Response ? $out : new WP_REST_Response( $out, 200 );
		},
	] );
} );
