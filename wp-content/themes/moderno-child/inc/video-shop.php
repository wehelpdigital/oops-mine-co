<?php
/**
 * Shoppable style videos: the pieces in a video, each tied to the moment it is on screen.
 *
 * Page Editor → Shoppable videos is where they are set: every video page with its player beside a
 * list of moments — a time and a piece. Pause where the look is, press "Add a piece here", pick the
 * piece. They are kept in the option `omc_video_shop`, keyed by the video's slug rather than its
 * page id: .ftp-sync/import-videos.php rewrites the video's own data (`_omc_video`) every time it
 * runs, and the slug is what stays the same on every copy of the site.
 *
 * On the video page each moment does two things (assets/js/omc-video.js follows the video's time):
 * at its time the piece slides in as a window over the video — photo, name, price, size and Add to
 * bag — and stays for that moment's seconds (8 unless set) or until the next piece; and it has a
 * card in the list, "Shop this look at 1:23".
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

const OMC_VIDEO_SHOP_OPTION = 'omc_video_shop';
const OMC_VIDEO_SHOP_PAGE   = 'omc-video-shop';
const OMC_VIDEO_SHOP_FOR    = 8;   // seconds the small window stays over the video, unless set per moment

/** How long a moment's window stays: 3 to 60 seconds. */
function omc_video_moment_for( $value ) {
	$value = (int) $value;
	return $value ? max( 3, min( 60, $value ) ) : OMC_VIDEO_SHOP_FOR;
}

/** The moments of one video, in time order: [ [ at, product, for ] ]. */
function omc_video_moments( $slug ) {
	$all = (array) get_option( OMC_VIDEO_SHOP_OPTION, [] );
	$out = [];
	foreach ( (array) ( $all[ sanitize_key( (string) $slug ) ] ?? [] ) as $m ) {
		$product = absint( $m['product'] ?? 0 );
		if ( $product ) {
			$out[] = [ 'at' => max( 0, (int) ( $m['at'] ?? 0 ) ), 'product' => $product, 'for' => omc_video_moment_for( $m['for'] ?? 0 ) ];
		}
	}
	usort( $out, static fn( $a, $b ) => $a['at'] <=> $b['at'] );
	return $out;
}

/** The moments that name a piece the shop can show, with the pieces registered for the page. */
function omc_video_shop_moments( array $v ) {
	static $cache = [];
	if ( ! isset( $cache[ $v['slug'] ] ) ) {
		$moments              = omc_video_moments( $v['slug'] );
		$pieces               = omc_pieces_use( wp_list_pluck( $moments, 'product' ) );
		$cache[ $v['slug'] ] = [ array_values( array_filter( $moments, static fn( $m ) => isset( $pieces[ $m['product'] ] ) ) ), $pieces ];
	}
	return $cache[ $v['slug'] ];
}

/** "Shop this video": every moment as a card — "Shop this look at 1:23" — with its size picker. */
function omc_video_shop( array $v ) {
	list( $moments, $pieces ) = omc_video_shop_moments( $v );
	if ( ! $moments ) {
		return;
	}
	?>
	<section class="omc-vshop" data-omc-vshop data-label-now="<?php esc_attr_e( 'On screen now', 'moderno-child' ); ?>" aria-labelledby="omc-vshop-title">
		<div class="omc-vshop__head">
			<p class="omc-eyebrow" id="omc-vshop-title"><?php esc_html_e( 'Shop this video', 'moderno-child' ); ?></p>
		</div>
		<ol class="omc-vshop__list">
			<?php foreach ( $moments as $m ) : ?>
				<?php $piece = $pieces[ $m['product'] ]; ?>
				<li class="omc-vshop__item" data-at="<?php echo (int) $m['at']; ?>" data-for="<?php echo (int) $m['for']; ?>" data-piece="<?php echo (int) $m['product']; ?>">
					<button type="button" class="omc-vshop__seek js-omc-moment" data-at="<?php echo (int) $m['at']; ?>">
						<img src="<?php echo esc_url( $piece['image'] ); ?>" alt="" loading="lazy" decoding="async">
						<span class="omc-vshop__time"><?php echo esc_html( omc_video_clock( $m['at'] ) ); ?></span>
					</button>
					<div class="omc-vshop__body">
						<span class="omc-vshop__at">
							<?php
							/* translators: %s: a moment in the video, e.g. 1:23 */
							echo esc_html( sprintf( __( 'Shop this look at %s', 'moderno-child' ), omc_video_clock( $m['at'] ) ) );
							?>
						</span>
						<a class="omc-vshop__name" href="<?php echo esc_url( $piece['url'] ); ?>"><?php echo esc_html( $piece['name'] ); ?></a>
						<span class="omc-vshop__price"><?php echo esc_html( $piece['price'] ); ?></span>
						<div data-omc-picker="<?php echo (int) $m['product']; ?>" data-compact></div>
					</div>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php $ids = array_values( array_unique( wp_list_pluck( $moments, 'product' ) ) ); ?>
		<?php if ( count( $ids ) > 1 ) : ?>
			<button type="button" class="omc-btn omc-btn--outline omc-vshop__all" data-outfit="<?php echo esc_attr( implode( ',', $ids ) ); ?>">
				<?php
				/* translators: %d: number of pieces */
				echo esc_html( sprintf( __( 'Everything in this video (%d)', 'moderno-child' ), count( $ids ) ) );
				?>
			</button>
		<?php endif; ?>
	</section>
	<?php
}

/* ─────────────────────────── the admin screen ─────────────────────────── */

add_action( 'admin_menu', static function () {
	// The Page Editor's own entry first, or WordPress would send its menu to this screen.
	add_submenu_page( OMC_PE_PAGE, __( 'Page Editor', 'moderno-child' ), __( 'Home & About', 'moderno-child' ), 'edit_theme_options', OMC_PE_PAGE, 'omc_pe_screen' );
	add_submenu_page( OMC_PE_PAGE, __( 'Shoppable videos', 'moderno-child' ), __( 'Shoppable videos', 'moderno-child' ), 'edit_theme_options', OMC_VIDEO_SHOP_PAGE, 'omc_video_shop_screen' );
}, 11 );

function omc_video_shop_screen() {
	?>
	<div class="wrap omc-vsa" id="omc-vsa">
		<header class="omc-vsa__head">
			<p class="omc-vsa__eyebrow"><?php esc_html_e( 'Site', 'moderno-child' ); ?></p>
			<h1 class="omc-vsa__title"><?php esc_html_e( 'Shoppable videos', 'moderno-child' ); ?></h1>
			<p class="omc-vsa__lead"><?php esc_html_e( 'Every style video and its page. “Add a video” makes a new video page from a video in the media library. Under “Pieces in the video”, pause where the look is, then “Add a piece here” and choose it: on the page the piece pops up as a window over the video at that time, with its size and Add to bag. Under “Page words” are the words beside the video — write them yourself, or have AI write them from what is in the video and what you tell it.', 'moderno-child' ); ?></p>
		</header>
		<hr class="wp-header-end">
		<noscript><div class="notice notice-error"><p><?php esc_html_e( 'This screen needs JavaScript.', 'moderno-child' ); ?></p></div></noscript>
		<div class="omc-vsa__app" id="omc-vsa-app"></div>
	</div>
	<?php
}

add_action( 'admin_enqueue_scripts', static function () {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which screen this is
	if ( ! isset( $_GET['page'] ) || OMC_VIDEO_SHOP_PAGE !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
		return;
	}
	$ver = static fn( $rel ) => file_exists( OMC_DIR . $rel ) ? (string) filemtime( OMC_DIR . $rel ) : OMC_VERSION;
	wp_enqueue_style( 'omc-pe-fonts', 'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;1,500&display=swap', [], null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	omc_piece_picker_assets();
	wp_enqueue_media(); // "Add a video" picks from the media library
	wp_enqueue_style( 'omc-video-shop-admin', OMC_URI . '/assets/css/video-shop-admin.css', [ 'omc-piece-picker' ], $ver( '/assets/css/video-shop-admin.css' ) );
	wp_enqueue_script( 'omc-video-shop-admin', OMC_URI . '/assets/js/video-shop-admin.js', [ 'omc-piece-picker' ], $ver( '/assets/js/video-shop-admin.js' ), true );

	$videos   = [];
	$products = [];
	foreach ( omc_video_pages( true ) as $pid => $v ) {
		$moments = omc_video_moments( $v['slug'] );
		foreach ( $moments as $m ) {
			$products[] = $m['product'];
		}
		$status   = (string) get_post_status( $pid );
		$videos[] = [
			'page'     => (int) $pid,
			'slug'     => $v['slug'],
			'title'    => html_entity_decode( (string) $v['title'], ENT_QUOTES, 'UTF-8' ),
			'status'   => $status,
			'added'    => 'dashboard' === get_post_meta( $pid, OMC_VIDEO_SOURCE, true ),
			'src'      => omc_video_src( $v ),
			'poster'   => omc_video_poster( $v, 'medium_large' ),
			'duration' => (int) $v['duration'],
			'view'     => (string) ( 'publish' === $status ? get_permalink( $pid ) : get_preview_post_link( $pid ) ),
			'moments'  => $moments,
			'content'  => omc_video_copy_of( $pid, $v ),
		];
	}
	wp_localize_script( 'omc-video-shop-admin', 'OMC_VSA', [
		'rest'     => esc_url_raw( rest_url( 'omc/v1/video-shop' ) ),
		'create'   => esc_url_raw( rest_url( 'omc/v1/video-shop/create' ) ),
		'status'   => esc_url_raw( rest_url( 'omc/v1/video-shop/status' ) ),
		'write'    => esc_url_raw( rest_url( 'omc/v1/video-shop/write' ) ),
		'job'      => esc_url_raw( rest_url( 'whd/v1/ai/studio/job' ) ),
		'nonce'    => wp_create_nonce( 'wp_rest' ),
		'ai'       => omc_video_ai_ready(),
		'aiSetup'  => class_exists( 'WHD_AI_Admin' ) ? esc_url_raw( admin_url( 'admin.php?page=' . WHD_AI_Admin::PAGE ) ) : '',
		'canAdd'   => current_user_can( 'upload_files' ) && current_user_can( 'publish_pages' ),
		'videos'   => $videos,
		'products' => (object) omc_piece_brief( $products ),
		'i18n'     => [
			'save'     => __( 'Save', 'moderno-child' ),
			'saving'   => __( 'Saving…', 'moderno-child' ),
			'saved'    => __( 'All changes saved', 'moderno-child' ),
			'unsaved'  => __( 'Unsaved changes', 'moderno-child' ),
			'failed'   => __( 'Could not save', 'moderno-child' ),
			'addHere'  => __( 'Add a piece here', 'moderno-child' ),
			'addHint'  => __( 'Pause where the look is, then add the piece.', 'moderno-child' ),
			'choose'   => __( 'Choose the piece', 'moderno-child' ),
			'change'   => __( 'Change', 'moderno-child' ),
			'remove'   => __( 'Remove', 'moderno-child' ),
			'jump'     => __( 'Play from here', 'moderno-child' ),
			'now'      => __( 'Use the time now', 'moderno-child' ),
			'time'     => __( 'Time (m:ss)', 'moderno-child' ),
			'showFor'  => __( 'Shown over the video for this many seconds', 'moderno-child' ),
			'forDefault' => OMC_VIDEO_SHOP_FOR,
			'empty'    => __( 'No pieces in this video yet.', 'moderno-child' ),
			'chapters' => __( 'Chapters — click one to add a piece at its start:', 'moderno-child' ),
			'chaptersPlay' => __( 'Chapters — click one to play from it:', 'moderno-child' ),
			'view'     => __( 'View the page', 'moderno-child' ),
			'preview'  => __( 'Preview the page', 'moderno-child' ),
			'noVideos' => __( 'No style videos yet — add the first one above.', 'moderno-child' ),
			'leave'    => __( 'You have changes that are not saved. Leave anyway?', 'moderno-child' ),
			'noPiece'  => __( 'No piece chosen', 'moderno-child' ),
			// Add a video
			'addVideo'   => __( 'Add a video', 'moderno-child' ),
			'addLead'    => __( 'A video from the media library becomes a new video page. Upload it there, or choose one that is already in the library.', 'moderno-child' ),
			'pickVideo'  => __( 'Choose a video', 'moderno-child' ),
			'otherVideo' => __( 'Choose another', 'moderno-child' ),
			'title'      => __( 'Title', 'moderno-child' ),
			'titleHint'  => __( 'The heading on the page. It can be changed later, or written by AI under “Page words”.', 'moderno-child' ),
			'poster'     => __( 'Cover picture', 'moderno-child' ),
			'posterHint' => __( 'Shown before the video plays and wherever the video is listed. Play or scrub the video to the frame you want, then “Use this frame”.', 'moderno-child' ),
			'useFrame'   => __( 'Use this frame', 'moderno-child' ),
			'publishNow' => __( 'Publish it now', 'moderno-child' ),
			'asDraft'    => __( 'Keep it as a draft', 'moderno-child' ),
			'create'     => __( 'Add the video', 'moderno-child' ),
			'creating'   => __( 'Adding…', 'moderno-child' ),
			'cancel'     => __( 'Cancel', 'moderno-child' ),
			'notVideo'   => __( 'That is not a video file.', 'moderno-child' ),
			'noFrame'    => __( 'The cover could not be taken from this video. Play it for a moment, then “Use this frame”.', 'moderno-child' ),
			'leaveAdd'   => __( 'You have changes that are not saved, and adding a video reloads this screen. Add it anyway?', 'moderno-child' ),
			// A video's card
			'published'  => __( 'Published', 'moderno-child' ),
			'draft'      => __( 'Draft', 'moderno-child' ),
			'publish'    => __( 'Publish', 'moderno-child' ),
			'unpublish'  => __( 'Make it a draft', 'moderno-child' ),
			'statusFail' => __( 'Could not change it', 'moderno-child' ),
			'tabPieces'  => __( 'Pieces in the video', 'moderno-child' ),
			'tabWords'   => __( 'Page words', 'moderno-child' ),
			// Page words
			'imported'   => __( 'These words came with the video’s import. Change any of them here; a field you empty goes back to the imported words.', 'moderno-child' ),
			'fTitle'     => __( 'Heading', 'moderno-child' ),
			'fIntro'     => __( 'Intro', 'moderno-child' ),
			'fIntroHint' => __( 'One or two sentences under the heading.', 'moderno-child' ),
			'fSeoTitle'  => __( 'Search title', 'moderno-child' ),
			'fSeoHint'   => __( 'The title in search results, up to 60 characters. Empty: the heading.', 'moderno-child' ),
			'fMeta'      => __( 'Search snippet', 'moderno-child' ),
			'fMetaHint'  => __( 'The line under it in search results, 140–155 characters.', 'moderno-child' ),
			'fKey'       => __( 'Focus keyphrase', 'moderno-child' ),
			'fKeyHint'   => __( 'The search phrase this page aims at.', 'moderno-child' ),
			'fAlt'       => __( 'What the video shows', 'moderno-child' ),
			'fAltHint'   => __( 'For people who cannot see it, and for search engines. Up to 125 characters.', 'moderno-child' ),
			'fChapters'  => __( 'Chapters', 'moderno-child' ),
			'fChapHint'  => __( 'The key moments, listed beside the video; search engines can show them as jumps into it.', 'moderno-child' ),
			'addChapter' => __( 'Add a chapter at the time now', 'moderno-child' ),
			'chapLabel'  => __( 'What happens', 'moderno-child' ),
			'fSections'  => __( 'Sections', 'moderno-child' ),
			'addSection' => __( 'Add a section', 'moderno-child' ),
			'heading'    => __( 'Heading', 'moderno-child' ),
			'paragraphs' => __( 'Paragraphs — a blank line between each', 'moderno-child' ),
			'bullets'    => __( 'Points — one a line (optional)', 'moderno-child' ),
			'fFaqs'      => __( 'Questions', 'moderno-child' ),
			'addFaq'     => __( 'Add a question', 'moderno-child' ),
			'q'          => __( 'Question', 'moderno-child' ),
			'a'          => __( 'Answer', 'moderno-child' ),
			// Write it with AI
			'aiTitle'    => __( 'Write it with AI', 'moderno-child' ),
			'aiLead'     => __( 'Stills are taken from the video and read by the AI model, with anything you explain here and the shop’s keyword list. The words land in the form below to read and change — nothing is saved until you press Save.', 'moderno-child' ),
			'aiNotes'    => __( 'Explain the video (optional)', 'moderno-child' ),
			'aiNotesPh'  => __( 'What it shows, the fabric, how to style it, who it is for, anything to mention or leave out…', 'moderno-child' ),
			'aiPieces'   => __( 'Tell it about the pieces tagged in the video', 'moderno-child' ),
			'aiGo'       => __( 'Write the page from the video', 'moderno-child' ),
			'aiStills'   => __( 'Taking stills from the video…', 'moderno-child' ),
			'aiSending'  => __( 'Sending them to the AI model…', 'moderno-child' ),
			/* translators: %s: time so far, e.g. 0:42 */
			'aiWriting'  => __( 'Writing the page… %s', 'moderno-child' ),
			'aiDone'     => __( 'Written. Read it through, change what you like, then Save.', 'moderno-child' ),
			'aiFail'     => __( 'Could not write it', 'moderno-child' ),
			'aiNoStills' => __( 'No stills could be taken from this video in this browser.', 'moderno-child' ),
			'aiTimeout'  => __( 'It is taking too long. Try again in a moment.', 'moderno-child' ),
			'aiOff'      => __( 'Connect an AI model in WHD → AI content to have these words written from the video.', 'moderno-child' ),
			'aiSetup'    => __( 'Set it up', 'moderno-child' ),
			'aiReplace'  => __( 'This replaces the words in the form below. Go on?', 'moderno-child' ),
			'aiWarn'     => __( 'Worth a look before saving:', 'moderno-child' ),
		],
	] );
} );

add_action( 'rest_api_init', static function () {
	register_rest_route( 'omc/v1', '/video-shop', [
		'methods'             => 'POST',
		'permission_callback' => static fn() => current_user_can( 'edit_theme_options' ),
		'callback'            => static function ( WP_REST_Request $r ) {
			$known = [];
			$pages = [];
			foreach ( omc_video_pages( true ) as $pid => $v ) {
				$known[ $v['slug'] ] = (int) $v['duration'];
				$pages[ $v['slug'] ] = (int) $pid;
			}
			// The page words (inc/video-writer.php), only for the videos whose words changed.
			$words = [];
			foreach ( (array) $r->get_param( 'content' ) as $slug => $given ) {
				$slug = sanitize_key( (string) $slug );
				if ( isset( $pages[ $slug ] ) && is_array( $given ) && current_user_can( 'edit_post', $pages[ $slug ] ) ) {
					$words[ $slug ] = omc_video_copy_save( $pages[ $slug ], $given );
				}
			}
			$all = (array) get_option( OMC_VIDEO_SHOP_OPTION, [] );
			foreach ( (array) $r->get_param( 'videos' ) as $slug => $moments ) {
				$slug = sanitize_key( (string) $slug );
				if ( ! isset( $known[ $slug ] ) ) {
					continue;
				}
				$clean = [];
				foreach ( array_slice( (array) $moments, 0, 40 ) as $m ) {
					$product = absint( $m['product'] ?? 0 );
					if ( ! $product || 'product' !== get_post_type( $product ) ) {
						continue;
					}
					$at      = max( 0, (int) ( $m['at'] ?? 0 ) );
					$clean[] = [ 'at' => $known[ $slug ] ? min( $at, $known[ $slug ] ) : $at, 'product' => $product, 'for' => omc_video_moment_for( $m['for'] ?? 0 ) ];
				}
				usort( $clean, static fn( $a, $b ) => $a['at'] <=> $b['at'] );
				if ( $clean ) {
					$all[ $slug ] = $clean;
				} else {
					unset( $all[ $slug ] );
				}
			}
			update_option( OMC_VIDEO_SHOP_OPTION, $all, false );
			$out = [];
			foreach ( array_keys( $known ) as $slug ) {
				$out[ $slug ] = omc_video_moments( $slug );
			}
			return new WP_REST_Response( [ 'videos' => $out, 'content' => (object) array_filter( $words ) ], 200 );
		},
	] );
} );
