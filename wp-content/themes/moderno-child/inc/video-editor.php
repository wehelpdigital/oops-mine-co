<?php
/**
 * The video editor: Page Editor → Shoppable videos → Edit video.
 *
 * A timeline for one video, to tidy a recording up before it is published: cut parts out, set where it
 * starts and ends, put words over it, choose a cover, move its pieces about, and add what shoppers can
 * touch — tap-to-shop dots, polls, buttons, "choose what's next" and email sign-ups.
 *
 * None of it changes the file. It is stored on the video's page (`_omc_video_edit`) and carried out by
 * the browser while the video plays (assets/js/omc-video-play.js), so any edit can be undone at any time
 * and the original recording is always there. The catch, which the screen says out loud, is that a part
 * cut out is skipped rather than gone: somebody with the file's own address could still watch it.
 *
 * Every time here is in the file's own seconds, so a cut never has to move anything else.
 * The moments of pieces stay where they have always been (inc/video-shop.php); this screen edits those too.
 * The schema keeps describing the file (its length, and #t= links in its seconds), since that is what a search engine fetches.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

const OMC_VIDEO_EDIT      = '_omc_video_edit';
const OMC_VIDEO_COUNTS    = '_omc_video_counts';
const OMC_VIDEO_EDIT_PAGE = 'omc-video-edit';
const OMC_VIDEO_ITEMS_MAX = 100;

/** One video's edit, as stored: [ cuts[ [ from, to ] ], items[] ]. */
function omc_video_edit( $page_id ) {
	static $cache = [];
	$page_id = (int) $page_id;
	if ( ! isset( $cache[ $page_id ] ) || wp_doing_ajax() || defined( 'REST_REQUEST' ) ) {
		$edit              = json_decode( (string) get_post_meta( $page_id, OMC_VIDEO_EDIT, true ), true );
		$cache[ $page_id ] = [
			'cuts'  => is_array( $edit['cuts'] ?? null ) ? $edit['cuts'] : [],
			'items' => is_array( $edit['items'] ?? null ) ? $edit['items'] : [],
		];
	}
	return $cache[ $page_id ];
}

/** Cut parts in order, joined where they touch, inside the video, and always leaving something to watch. */
function omc_video_cuts_clean( $cuts, $duration ) {
	$duration = (float) $duration;
	$rows     = [];
	foreach ( array_slice( (array) $cuts, 0, 200 ) as $c ) {
		$from = round( max( 0, (float) ( $c[0] ?? 0 ) ), 1 );
		$to   = round( (float) ( $c[1] ?? 0 ), 1 );
		$to   = $duration ? min( $to, $duration ) : $to;
		if ( $to - $from >= 0.2 ) {
			$rows[] = [ $from, $to ];
		}
	}
	usort( $rows, static fn( $a, $b ) => $a[0] <=> $b[0] );
	$out = [];
	foreach ( $rows as $c ) {
		$last = count( $out ) - 1;
		if ( $last >= 0 && $c[0] <= $out[ $last ][1] + 0.1 ) {
			$out[ $last ][1] = max( $out[ $last ][1], $c[1] );
		} else {
			$out[] = $c;
		}
	}
	$gone = array_sum( array_map( static fn( $c ) => $c[1] - $c[0], $out ) );
	return $duration && $duration - $gone < 1 ? [] : $out;
}

/** What may be over a video. Anything else, or anything with nothing to say, is dropped. */
function omc_video_items_clean( $items, $duration ) {
	$line = static fn( $v, $max ) => mb_substr( trim( sanitize_text_field( is_scalar( $v ) ? (string) $v : '' ) ), 0, $max );
	$out  = [];
	$ids  = [];
	foreach ( array_slice( (array) $items, 0, OMC_VIDEO_ITEMS_MAX ) as $in ) {
		if ( ! is_array( $in ) ) {
			continue;
		}
		$id = substr( sanitize_key( (string) ( $in['id'] ?? '' ) ), 0, 16 ) ?: wp_generate_password( 8, false, false );
		$id = strtolower( $id );
		if ( isset( $ids[ $id ] ) ) {
			continue;
		}
		$at   = round( max( 0, (float) ( $in['at'] ?? 0 ) ), 1 );
		$item = [
			'id'   => $id,
			'type' => '',
			'at'   => $duration ? min( $at, (float) $duration ) : $at,
			'for'  => round( max( 1, min( OMC_VIDEO_SHOP_LONGEST, (float) ( $in['for'] ?? 6 ) ) ), 1 ),
			'x'    => round( max( 0, min( 1, (float) ( $in['x'] ?? 0 ) ) ), 3 ),
			'y'    => round( max( 0, min( 1, (float) ( $in['y'] ?? 0 ) ) ), 3 ),
		];
		switch ( $in['type'] ?? '' ) {
			case 'text':
				$item['type']  = 'text';
				$item['text']  = $line( $in['text'] ?? '', 140 );
				$item['style'] = in_array( $in['style'] ?? '', [ 'serif', 'sans', 'label' ], true ) ? $in['style'] : 'serif';
				$item['size']  = in_array( $in['size'] ?? '', [ 's', 'm', 'l' ], true ) ? $in['size'] : 'm';
				$good          = '' !== $item['text'];
				break;
			case 'spot':
				$item['type']    = 'spot';
				$item['product'] = absint( $in['product'] ?? 0 );
				$good            = $item['product'] && 'product' === get_post_type( $item['product'] );
				break;
			case 'poll':
				$item['type']     = 'poll';
				$item['question'] = $line( $in['question'] ?? '', 140 );
				$item['options']  = array_slice( array_values( array_filter( array_map( static fn( $o ) => $line( $o, 60 ), (array) ( $in['options'] ?? [] ) ), 'strlen' ) ), 0, 4 );
				$item['pause']    = ! empty( $in['pause'] );
				$good             = '' !== $item['question'] && count( $item['options'] ) >= 2;
				break;
			case 'button':
				$item['type']  = 'button';
				$item['label'] = $line( $in['label'] ?? '', 40 );
				$item['url']   = esc_url_raw( (string) ( $in['url'] ?? '' ), [ 'http', 'https' ] );
				$good          = '' !== $item['label'] && '' !== $item['url'];
				break;
			case 'jump':
				$item['type']     = 'jump';
				$item['question'] = $line( $in['question'] ?? '', 140 );
				$item['choices']  = [];
				foreach ( array_slice( (array) ( $in['choices'] ?? [] ), 0, 4 ) as $choice ) {
					$label = $line( $choice['label'] ?? '', 40 );
					if ( '' !== $label ) {
						$to                  = round( max( 0, (float) ( $choice['to'] ?? 0 ) ), 1 );
						$item['choices'][] = [ 'label' => $label, 'to' => $duration ? min( $to, (float) $duration ) : $to ];
					}
				}
				$item['pause'] = ! empty( $in['pause'] );
				$good          = '' !== $item['question'] && count( $item['choices'] ) >= 2;
				break;
			case 'signup':
				$item['type']    = 'signup';
				$item['heading'] = $line( $in['heading'] ?? '', 80 );
				$item['button']  = $line( $in['button'] ?? '', 24 );
				$good            = '' !== $item['heading'] && '' !== $item['button'];
				break;
			default:
				$good = false;
		}
		if ( $good ) {
			$ids[ $id ] = true;
			$out[]      = $item;
		}
	}
	usort( $out, static fn( $a, $b ) => $a['at'] <=> $b['at'] );
	return $out;
}

/** A moment in the file, on the clock a viewer sees once the cuts are skipped. */
function omc_video_shown( $at, array $cuts ) {
	$shown = (float) $at;
	foreach ( $cuts as $c ) {
		if ( $at >= $c[1] ) {
			$shown -= $c[1] - $c[0];
		} elseif ( $at > $c[0] ) {
			$shown -= $at - $c[0];
		}
	}
	return max( 0, $shown );
}

/** The clock label for a moment on the video page being shown. */
function omc_video_clock_shown( $at ) {
	return omc_video_clock( omc_video_shown( $at, omc_video_edit( get_the_ID() )['cuts'] ) );
}

/** Whether a piece's time on screen survives the cuts. One entirely inside a cut is not in the video. */
function omc_video_moment_kept( array $moment ) {
	$from = (float) $moment['at'];
	$to   = $from + (float) $moment['for'];
	foreach ( omc_video_edit( get_the_ID() )['cuts'] as $c ) {
		if ( $from >= $c[0] && $to <= $c[1] ) {
			return false;
		}
	}
	return true;
}

/* ─────────────────────────── on the page ─────────────────────────── */

add_action( 'wp_enqueue_scripts', static function () {
	if ( ! is_page_template( 'templates/page-video.php' ) ) {
		return;
	}
	$edit = omc_video_edit( get_queried_object_id() );
	if ( $edit['cuts'] || $edit['items'] ) {
		$ver = static fn( $rel ) => file_exists( OMC_DIR . $rel ) ? (string) filemtime( OMC_DIR . $rel ) : OMC_VERSION;
		wp_enqueue_style( 'omc-video-play', OMC_URI . '/assets/css/omc-video-play.css', [ 'omc-video' ], $ver( '/assets/css/omc-video-play.css' ) );
		wp_enqueue_script( 'omc-video-play', OMC_URI . '/assets/js/omc-video-play.js', [ 'omc-video' ], $ver( '/assets/js/omc-video-play.js' ), true );
	}
}, 1001 );

/** What the player needs. A video nobody has edited prints nothing, and keeps the browser's own player. */
function omc_video_play_data( array $v ) {
	$page = (int) get_the_ID();
	$edit = omc_video_edit( $page );
	if ( ! $edit['cuts'] && ! $edit['items'] ) {
		return;
	}
	$items  = [];
	$pieces = [];
	foreach ( $edit['items'] as $item ) {
		$items[] = $item;
		if ( 'spot' === $item['type'] ) {
			$pieces[] = (int) $item['product'];
		}
	}
	if ( $pieces ) {
		omc_pieces_use( $pieces );
	}
	$counts = json_decode( (string) get_post_meta( $page, OMC_VIDEO_COUNTS, true ), true );
	$polls  = [];
	foreach ( $edit['items'] as $item ) {
		if ( 'poll' === $item['type'] ) {
			$polls[ $item['id'] ] = omc_video_tally( (array) ( $counts[ $item['id'] ] ?? [] ), count( $item['options'] ) );
		}
	}
	$data = [
		'page'     => $page,
		'duration' => (float) $v['duration'],
		'cuts'     => $edit['cuts'],
		'items'    => $items,
		'counts'   => (object) $polls,
		'place'    => omc_video_pop_place( $v['slug'] ),
		'act'      => esc_url_raw( rest_url( 'omc/v1/video-act' ) ),
		'signup'   => [
			'url'    => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			'nonce'  => wp_create_nonce( 'omc_newsletter' ),
			'source' => 'video:' . sanitize_key( $v['slug'] ),
		],
		'i18n'     => omc_video_play_strings(),
	];
	echo '<script type="application/json" id="omc-video-play">' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON, hexed for a script tag
}

function omc_video_play_strings() {
	return [
		'play'     => __( 'Play', 'moderno-child' ),
		'pause'    => __( 'Pause', 'moderno-child' ),
		'mute'     => __( 'Sound on or off', 'moderno-child' ),
		'full'     => __( 'Full screen', 'moderno-child' ),
		'seek'     => __( 'Move through the video', 'moderno-child' ),
		'close'    => __( 'Close', 'moderno-child' ),
		'now'      => __( 'On screen now', 'moderno-child' ),
		'shop'     => __( 'Shop this piece', 'moderno-child' ),
		'email'    => __( 'Your email address', 'moderno-child' ),
		'badEmail' => __( 'Please enter a valid email address.', 'moderno-child' ),
		'oops'     => __( 'Something went wrong. Please try again.', 'moderno-child' ),
	];
}

/* ─────────────────────────── the screen ─────────────────────────── */

add_action( 'admin_menu', static function () {
	// A screen reached from Shoppable videos: registered with an empty parent, or WordPress cannot resolve its hook.
	add_submenu_page( '', __( 'Edit video', 'moderno-child' ), __( 'Edit video', 'moderno-child' ), 'edit_theme_options', OMC_VIDEO_EDIT_PAGE, 'omc_video_edit_screen' );
}, 12 );

add_action( 'load-admin_page_' . OMC_VIDEO_EDIT_PAGE, static function () {
	$GLOBALS['title'] = __( 'Edit video', 'moderno-child' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a hidden page has no title of its own
	// An editor needs the whole screen: update nags land in the middle of its top bar otherwise.
	remove_all_actions( 'admin_notices' );
	remove_all_actions( 'all_admin_notices' );
} );

add_filter( 'parent_file', static function ( $file ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which menu to light
	if ( isset( $_GET['page'] ) && OMC_VIDEO_EDIT_PAGE === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
		$GLOBALS['submenu_file'] = OMC_VIDEO_SHOP_PAGE; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		return OMC_PE_PAGE;
	}
	return $file;
} );

/** The video page a screen's ?video= names, by its slug: [ id, data ], or null. */
function omc_video_edit_target() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which video to open
	$slug = isset( $_GET['video'] ) ? sanitize_key( wp_unslash( $_GET['video'] ) ) : '';
	foreach ( omc_video_pages( true ) as $id => $v ) {
		if ( $v['slug'] === $slug ) {
			return [ (int) $id, $v ];
		}
	}
	return null;
}

function omc_video_edit_screen() {
	$target = omc_video_edit_target();
	if ( ! $target ) {
		printf(
			'<div class="wrap"><h1>%1$s</h1><p>%2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Edit video', 'moderno-child' ),
			esc_html__( 'That video could not be found.', 'moderno-child' ),
			esc_url( admin_url( 'admin.php?page=' . OMC_VIDEO_SHOP_PAGE ) ),
			esc_html__( 'Back to Shoppable videos', 'moderno-child' )
		);
		return;
	}
	echo '<div class="wrap omc-ve" id="omc-ve"></div><noscript><div class="notice notice-error"><p>' . esc_html__( 'This screen needs JavaScript.', 'moderno-child' ) . '</p></div></noscript>';
}

add_action( 'admin_enqueue_scripts', static function () {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which screen this is
	if ( ! isset( $_GET['page'] ) || OMC_VIDEO_EDIT_PAGE !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
		return;
	}
	$target = omc_video_edit_target();
	if ( ! $target ) {
		return;
	}
	list( $id, $v ) = $target;
	$ver            = static fn( $rel ) => file_exists( OMC_DIR . $rel ) ? (string) filemtime( OMC_DIR . $rel ) : OMC_VERSION;
	wp_enqueue_style( 'omc-ve-fonts', 'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;1,500&family=Manrope:wght@400;500;600;700&display=swap', [], null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	omc_piece_picker_assets();
	wp_enqueue_style( 'omc-ve-video', OMC_URI . '/assets/css/omc-video.css', [], $ver( '/assets/css/omc-video.css' ) );
	wp_enqueue_style( 'omc-ve-play', OMC_URI . '/assets/css/omc-video-play.css', [ 'omc-ve-video' ], $ver( '/assets/css/omc-video-play.css' ) );
	wp_enqueue_style( 'omc-ve', OMC_URI . '/assets/css/video-editor.css', [ 'omc-ve-play', 'omc-piece-picker' ], $ver( '/assets/css/video-editor.css' ) );
	wp_enqueue_script( 'omc-ve-play', OMC_URI . '/assets/js/omc-video-play.js', [], $ver( '/assets/js/omc-video-play.js' ), true );
	wp_enqueue_script( 'omc-ve', OMC_URI . '/assets/js/video-editor.js', [ 'omc-ve-play', 'omc-piece-picker' ], $ver( '/assets/js/video-editor.js' ), true );

	$edit     = omc_video_edit( $id );
	$moments  = omc_video_moments( $v['slug'] );
	$products = wp_list_pluck( $moments, 'product' );
	foreach ( $edit['items'] as $item ) {
		if ( 'spot' === $item['type'] ) {
			$products[] = (int) $item['product'];
		}
	}
	$status = (string) get_post_status( $id );
	wp_localize_script( 'omc-ve', 'OMC_VE', [
		'video'    => [
			'page'     => $id,
			'slug'     => $v['slug'],
			'title'    => html_entity_decode( (string) $v['title'], ENT_QUOTES, 'UTF-8' ),
			'status'   => $status,
			'added'    => 'dashboard' === get_post_meta( $id, OMC_VIDEO_SOURCE, true ),
			'src'      => omc_video_src( $v ),
			'poster'   => omc_video_poster( $v, 'medium_large' ),
			'duration' => (float) $v['duration'],
			'width'    => (int) $v['width'],
			'height'   => (int) $v['height'],
			'orient'   => (string) $v['orientation'],
			'view'     => (string) ( 'publish' === $status ? get_permalink( $id ) : get_preview_post_link( $id ) ),
		],
		'cuts'     => $edit['cuts'],
		'items'    => $edit['items'],
		'moments'  => $moments,
		'place'    => omc_video_pop_place( $v['slug'] ),
		'products' => (object) omc_piece_brief( $products ),
		'counts'   => (object) ( json_decode( (string) get_post_meta( $id, OMC_VIDEO_COUNTS, true ), true ) ?: [] ),
		'back'     => esc_url_raw( admin_url( 'admin.php?page=' . OMC_VIDEO_SHOP_PAGE . '#pieces-' . $v['slug'] ) ),
		'shopUrl'  => function_exists( 'wc_get_page_permalink' ) ? esc_url_raw( wc_get_page_permalink( 'shop' ) ) : esc_url_raw( home_url( '/' ) ),
		'canPub'   => current_user_can( 'publish_pages' ),
		'rest'     => [
			'save'   => esc_url_raw( rest_url( 'omc/v1/video-edit' ) ),
			'cover'  => esc_url_raw( rest_url( 'omc/v1/video-edit/cover' ) ),
			'status' => esc_url_raw( rest_url( 'omc/v1/video-shop/status' ) ),
			'nonce'  => wp_create_nonce( 'wp_rest' ),
		],
		'play'     => omc_video_play_strings(),
		'i18n'     => omc_video_edit_strings(),
	] );
} );

function omc_video_edit_strings() {
	return [
		'back'        => __( 'Shoppable videos', 'moderno-child' ),
		'save'        => __( 'Save', 'moderno-child' ),
		'saving'      => __( 'Saving…', 'moderno-child' ),
		'saved'       => __( 'All changes saved', 'moderno-child' ),
		'unsaved'     => __( 'Unsaved changes', 'moderno-child' ),
		'failed'      => __( 'Could not save', 'moderno-child' ),
		'leave'       => __( 'You have changes that are not saved. Leave anyway?', 'moderno-child' ),
		'undo'        => __( 'Undo', 'moderno-child' ),
		'redo'        => __( 'Redo', 'moderno-child' ),
		'published'   => __( 'Published', 'moderno-child' ),
		'draft'       => __( 'Draft', 'moderno-child' ),
		'publish'     => __( 'Publish', 'moderno-child' ),
		'unpublish'   => __( 'Make it a draft', 'moderno-child' ),
		'saveFirst'   => __( 'Save your changes first.', 'moderno-child' ),
		'view'        => __( 'View the page', 'moderno-child' ),
		'preview'     => __( 'Preview the page', 'moderno-child' ),
		'play'        => __( 'Play', 'moderno-child' ),
		'pause'       => __( 'Pause', 'moderno-child' ),
		'back5'       => __( 'Back 5 seconds', 'moderno-child' ),
		'on5'         => __( 'On 5 seconds', 'moderno-child' ),
		/* translators: 1: length after cuts, 2: length of the recording */
		'edited'      => __( 'Plays for %1$s of %2$s', 'moderno-child' ),
		/* translators: %s: length of the video */
		'whole'       => __( 'Plays for %s', 'moderno-child' ),
		'startHere'   => __( 'Start here', 'moderno-child' ),
		'startTip'    => __( 'Cut everything before this point', 'moderno-child' ),
		'endHere'     => __( 'End here', 'moderno-child' ),
		'endTip'      => __( 'Cut everything after this point', 'moderno-child' ),
		'cutPart'     => __( 'Cut the marked part', 'moderno-child' ),
		'cutHint'     => __( 'Drag along the Video row to mark a part to cut.', 'moderno-child' ),
		'zoom'        => __( 'Zoom', 'moderno-child' ),
		'trackVideo'  => __( 'Video', 'moderno-child' ),
		'trackPieces' => __( 'Pieces', 'moderno-child' ),
		'trackPlay'   => __( 'Interactive', 'moderno-child' ),
		'trackText'   => __( 'Words', 'moderno-child' ),
		'tooMuch'     => __( 'That would cut the whole video.', 'moderno-child' ),
		'addTitle'    => __( 'Add at the playhead', 'moderno-child' ),
		'addText'     => __( 'Words', 'moderno-child' ),
		'addPiece'    => __( 'Piece pop-up', 'moderno-child' ),
		'addSpot'     => __( 'Tap-to-shop dot', 'moderno-child' ),
		'addPoll'     => __( 'Poll', 'moderno-child' ),
		'addButton'   => __( 'Button', 'moderno-child' ),
		'addJump'     => __( 'Choose what’s next', 'moderno-child' ),
		'addSignup'   => __( 'Email sign-up', 'moderno-child' ),
		'hintText'    => __( 'A line over the picture', 'moderno-child' ),
		'hintPiece'   => __( 'The piece, its price and a bag button', 'moderno-child' ),
		'hintSpot'    => __( 'A dot on the clothes that opens the piece', 'moderno-child' ),
		'hintPoll'    => __( 'A question, with the results after voting', 'moderno-child' ),
		'hintButton'  => __( 'A link to anywhere', 'moderno-child' ),
		'hintJump'    => __( 'Buttons that jump to another part', 'moderno-child' ),
		'hintSignup'  => __( 'Joins your email list', 'moderno-child' ),
		'cover'       => __( 'Cover', 'moderno-child' ),
		'coverHint'   => __( 'Shown before the video plays and wherever it is listed. Move to the frame you want first.', 'moderno-child' ),
		'coverUse'    => __( 'Use this frame', 'moderno-child' ),
		'coverDone'   => __( 'The cover is changed.', 'moderno-child' ),
		'coverFail'   => __( 'The cover could not be changed.', 'moderno-child' ),
		'keysTitle'   => __( 'Keys', 'moderno-child' ),
		'keys'        => __( 'Space plays. ← → move a second, with Shift five. I and O start and end at the playhead. Delete removes what is selected.', 'moderno-child' ),
		'startsAt'    => __( 'Starts at', 'moderno-child' ),
		'staysFor'    => __( 'Stays for (seconds)', 'moderno-child' ),
		'from'        => __( 'From', 'moderno-child' ),
		'to'          => __( 'To', 'moderno-child' ),
		'remove'      => __( 'Remove', 'moderno-child' ),
		'copy'        => __( 'Copy', 'moderno-child' ),
		'cutTitle'    => __( 'A part that is cut', 'moderno-child' ),
		'cutPutBack'  => __( 'Put this part back', 'moderno-child' ),
		'rangeTitle'  => __( 'The marked part', 'moderno-child' ),
		'cutNote'     => __( 'The video skips this part when it plays. The file itself is not changed, so anybody with the file’s own address could still watch it. Don’t rely on a cut to hide something private.', 'moderno-child' ),
		'text'        => __( 'Words', 'moderno-child' ),
		'style'       => __( 'Style', 'moderno-child' ),
		'styleSerif'  => __( 'Serif', 'moderno-child' ),
		'styleSans'   => __( 'Capitals', 'moderno-child' ),
		'styleLabel'  => __( 'On a label', 'moderno-child' ),
		'size'        => __( 'Size', 'moderno-child' ),
		'sizeS'       => __( 'Small', 'moderno-child' ),
		'sizeM'       => __( 'Medium', 'moderno-child' ),
		'sizeL'       => __( 'Large', 'moderno-child' ),
		'piece'       => __( 'Piece', 'moderno-child' ),
		'choose'      => __( 'Choose the piece', 'moderno-child' ),
		'dragDot'     => __( 'Drag the dot onto the clothes in the picture.', 'moderno-child' ),
		'dragCard'    => __( 'Drag it on the picture to place it.', 'moderno-child' ),
		'popNote'     => __( 'All this video’s pieces pop up in the same spot. Drag it on the picture to move them all.', 'moderno-child' ),
		'question'    => __( 'Question', 'moderno-child' ),
		'answers'     => __( 'Answers, one a line (2 to 4)', 'moderno-child' ),
		'pauseFor'    => __( 'Stop the video until they answer', 'moderno-child' ),
		/* translators: %d: number of votes */
		'votes'       => __( '%d votes so far', 'moderno-child' ),
		/* translators: %d: number of clicks */
		'clicks'      => __( '%d clicks so far', 'moderno-child' ),
		'label'       => __( 'Label', 'moderno-child' ),
		'link'        => __( 'Link', 'moderno-child' ),
		'choices'     => __( 'Buttons', 'moderno-child' ),
		'choiceTo'    => __( 'Jumps to', 'moderno-child' ),
		'choiceNow'   => __( 'Use the playhead', 'moderno-child' ),
		'heading'     => __( 'Heading', 'moderno-child' ),
		'buttonWord'  => __( 'Button', 'moderno-child' ),
		'signupNote'  => __( 'Sign-ups join your list with this video as their source. Don’t promise a code here unless your welcome email sends one.', 'moderno-child' ),
		'dText'       => __( 'New in this week', 'moderno-child' ),
		'dPollQ'      => __( 'Which would you wear?', 'moderno-child' ),
		'dPollA'      => __( 'The dress', 'moderno-child' ),
		'dPollB'      => __( 'The skirt', 'moderno-child' ),
		'dButton'     => __( 'Shop all new arrivals', 'moderno-child' ),
		'dJumpQ'      => __( 'What would you like to see?', 'moderno-child' ),
		'dJumpA'      => __( 'The dresses', 'moderno-child' ),
		'dJumpB'      => __( 'The knitwear', 'moderno-child' ),
		'dSignup'     => __( 'See new pieces first', 'moderno-child' ),
		'dSignupBtn'  => __( 'Join', 'moderno-child' ),
		'sample'      => __( 'On screen now', 'moderno-child' ),
		'noPiece'     => __( 'No piece chosen', 'moderno-child' ),
	];
}

/* ─────────────────────────── the wires ─────────────────────────── */

add_action( 'rest_api_init', static function () {
	$video_page = static function ( $id ) {
		$id = absint( $id );
		return $id && 'page' === get_post_type( $id ) && omc_video_data( $id ) ? $id : 0;
	};

	register_rest_route( 'omc/v1', '/video-edit', [
		'methods'             => 'POST',
		'permission_callback' => static fn() => current_user_can( 'edit_theme_options' ),
		'callback'            => static function ( WP_REST_Request $r ) use ( $video_page ) {
			$id = $video_page( $r->get_param( 'page' ) );
			if ( ! $id ) {
				return new WP_REST_Response( [ 'message' => __( 'That video page is gone.', 'moderno-child' ) ], 404 );
			}
			$v        = omc_video_data( $id );
			$duration = (float) $v['duration'];
			$cuts     = omc_video_cuts_clean( $r->get_param( 'cuts' ), $duration );
			$items    = omc_video_items_clean( $r->get_param( 'items' ), $duration );
			if ( $cuts || $items ) {
				update_post_meta( $id, OMC_VIDEO_EDIT, wp_slash( wp_json_encode( [ 'cuts' => $cuts, 'items' => $items ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
			} else {
				delete_post_meta( $id, OMC_VIDEO_EDIT );
			}

			$moments = omc_video_moments_clean( $r->get_param( 'moments' ), (int) $duration );
			$all     = (array) get_option( OMC_VIDEO_SHOP_OPTION, [] );
			if ( $moments ) {
				$all[ $v['slug'] ] = $moments;
			} else {
				unset( $all[ $v['slug'] ] );
			}
			update_option( OMC_VIDEO_SHOP_OPTION, $all, false );

			$place  = (array) $r->get_param( 'place' );
			$x      = round( max( 0, min( 1, (float) ( $place['x'] ?? 0 ) ) ), 3 );
			$y      = round( max( 0, min( 1, (float) ( $place['y'] ?? 0 ) ) ), 3 );
			$places = (array) get_option( OMC_VIDEO_SHOP_PLACE, [] );
			if ( $x || $y ) {
				$places[ $v['slug'] ] = [ 'x' => $x, 'y' => $y ];
			} else {
				unset( $places[ $v['slug'] ] );
			}
			update_option( OMC_VIDEO_SHOP_PLACE, $places, false );

			return new WP_REST_Response( [ 'cuts' => $cuts, 'items' => $items, 'moments' => omc_video_moments( $v['slug'] ), 'place' => omc_video_pop_place( $v['slug'] ) ], 200 );
		},
	] );

	register_rest_route( 'omc/v1', '/video-edit/cover', [
		'methods'             => 'POST',
		'permission_callback' => static fn() => current_user_can( 'edit_theme_options' ) && current_user_can( 'upload_files' ),
		'callback'            => static function ( WP_REST_Request $r ) use ( $video_page ) {
			$id = $video_page( $r->get_param( 'page' ) );
			// An imported video's data is rewritten by the importer, which would put its old cover back.
			if ( ! $id || 'dashboard' !== get_post_meta( $id, OMC_VIDEO_SOURCE, true ) ) {
				return new WP_REST_Response( [ 'message' => __( 'That video’s cover cannot be changed here.', 'moderno-child' ) ], 400 );
			}
			$v      = omc_video_base( $id );
			$poster = omc_video_save_still( (string) $r->get_param( 'poster' ), (string) $v['title'] );
			if ( ! $poster ) {
				return new WP_REST_Response( [ 'message' => __( 'That frame could not be saved.', 'moderno-child' ) ], 400 );
			}
			$data               = json_decode( (string) get_post_meta( $id, OMC_VIDEO_META, true ), true );
			$data['poster_id']  = $poster;
			$data['poster_url'] = (string) wp_get_attachment_url( $poster );
			update_post_meta( $id, OMC_VIDEO_META, wp_slash( wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
			set_post_thumbnail( $id, $poster );
			if ( ! empty( $data['attachment'] ) ) {
				set_post_thumbnail( (int) $data['attachment'], $poster );
			}
			return new WP_REST_Response( [ 'poster' => (string) wp_get_attachment_image_url( $poster, 'medium_large' ) ], 200 );
		},
	] );

	/*
	 * A viewer's vote or click. Open to anybody, so it only ever adds one to something that already exists
	 * on a published video, a few times per address per item. The browser remembers a vote as well, so
	 * this limit is only there for somebody working at it.
	 */
	register_rest_route( 'omc/v1', '/video-act', [
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => static function ( WP_REST_Request $r ) use ( $video_page ) {
			$id = $video_page( $r->get_param( 'page' ) );
			if ( ! $id || 'publish' !== get_post_status( $id ) ) {
				return new WP_REST_Response( [ 'message' => 'gone' ], 404 );
			}
			$found = null;
			foreach ( omc_video_edit( $id )['items'] as $item ) {
				if ( $item['id'] === (string) $r->get_param( 'item' ) ) {
					$found = $item;
				}
			}
			if ( ! $found || in_array( $found['type'], [ 'text', 'signup' ], true ) ) {
				return new WP_REST_Response( [ 'message' => 'gone' ], 404 );
			}
			$choice = max( 0, (int) $r->get_param( 'choice' ) );
			if ( 'poll' === $found['type'] && $choice >= count( $found['options'] ) ) {
				return new WP_REST_Response( [ 'message' => 'no such answer' ], 400 );
			}
			$choice = 'poll' === $found['type'] ? $choice : 0; // a click is a click, whichever button it was
			$who    = 'omc_va_' . md5( ( $_SERVER['REMOTE_ADDR'] ?? '' ) . '|' . $id . '|' . $found['id'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$times  = (int) get_transient( $who );
			$counts = omc_video_count_add( $id, $found['id'], $choice, $times < 5 );
			set_transient( $who, $times + 1, 10 * MINUTE_IN_SECONDS );
			return new WP_REST_Response( 'poll' === $found['type'] ? [ 'counts' => omc_video_tally( $counts, count( $found['options'] ) ) ] : [ 'ok' => true ], 200 );
		},
	] );
} );

/** Add one under a lock, so two votes at once both count. @return array This item's counts now. */
function omc_video_count_add( $page_id, $item_id, $choice, $add ) {
	global $wpdb;
	$lock = 'omc_video_counts_' . (int) $page_id;
	$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	wp_cache_delete( (int) $page_id, 'post_meta' );
	$all = json_decode( (string) get_post_meta( $page_id, OMC_VIDEO_COUNTS, true ), true );
	$all = is_array( $all ) ? $all : [];
	$row = (array) ( $all[ $item_id ] ?? [] );
	if ( $add ) {
		$row[ $choice ] = (int) ( $row[ $choice ] ?? 0 ) + 1;
		$all[ $item_id ] = $row;
		update_post_meta( $page_id, OMC_VIDEO_COUNTS, wp_slash( wp_json_encode( $all ) ) );
	}
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return $row;
}

/** Counts as a plain list, one per answer. */
function omc_video_tally( array $row, $answers ) {
	$out = [];
	for ( $i = 0; $i < $answers; $i++ ) {
		$out[] = (int) ( $row[ $i ] ?? 0 );
	}
	return $out;
}
