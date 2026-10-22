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
 * On the video page each moment becomes a card ("Shop this look at 1:23") with its size picker,
 * and the piece on screen right now sits under the player with its own Add to bag
 * (assets/js/omc-video.js follows the video's time).
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

const OMC_VIDEO_SHOP_OPTION = 'omc_video_shop';
const OMC_VIDEO_SHOP_PAGE   = 'omc-video-shop';

/** The moments of one video, in time order: [ [ at, product ] ]. */
function omc_video_moments( $slug ) {
	$all = (array) get_option( OMC_VIDEO_SHOP_OPTION, [] );
	$out = [];
	foreach ( (array) ( $all[ sanitize_key( (string) $slug ) ] ?? [] ) as $m ) {
		$product = absint( $m['product'] ?? 0 );
		if ( $product ) {
			$out[] = [ 'at' => max( 0, (int) ( $m['at'] ?? 0 ) ), 'product' => $product ];
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

/** Under the player: the piece on screen right now, with its Add to bag (filled by omc-video.js). */
function omc_video_shop_now( array $v ) {
	list( $moments ) = omc_video_shop_moments( $v );
	if ( $moments ) {
		echo '<div class="omc-vshop-now" data-omc-vshop-now data-label-now="' . esc_attr__( 'On screen now', 'moderno-child' ) . '" data-label-first="' . esc_attr__( 'In this video', 'moderno-child' ) . '" aria-live="polite" hidden></div>';
	}
}

/** "Shop this video": every moment as a card — "Shop this look at 1:23" — with its size picker. */
function omc_video_shop( array $v ) {
	list( $moments, $pieces ) = omc_video_shop_moments( $v );
	if ( ! $moments ) {
		return;
	}
	?>
	<section class="omc-vshop" data-omc-vshop aria-labelledby="omc-vshop-title">
		<div class="omc-vshop__head">
			<p class="omc-eyebrow" id="omc-vshop-title"><?php esc_html_e( 'Shop this video', 'moderno-child' ); ?></p>
		</div>
		<ol class="omc-vshop__list">
			<?php foreach ( $moments as $m ) : ?>
				<?php $piece = $pieces[ $m['product'] ]; ?>
				<li class="omc-vshop__item" data-at="<?php echo (int) $m['at']; ?>" data-piece="<?php echo (int) $m['product']; ?>">
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
			<p class="omc-vsa__lead"><?php esc_html_e( 'Which piece is on screen when, for each style video. Play the video, pause where the look is, then “Add a piece here” and choose it. On the video page each one becomes a card — “Shop this look at 1:23” — and the piece on screen sits under the player with its own Add to bag.', 'moderno-child' ); ?></p>
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
	wp_enqueue_style( 'omc-video-shop-admin', OMC_URI . '/assets/css/video-shop-admin.css', [ 'omc-piece-picker' ], $ver( '/assets/css/video-shop-admin.css' ) );
	wp_enqueue_script( 'omc-video-shop-admin', OMC_URI . '/assets/js/video-shop-admin.js', [ 'omc-piece-picker' ], $ver( '/assets/js/video-shop-admin.js' ), true );

	$videos   = [];
	$products = [];
	foreach ( omc_video_pages() as $pid => $v ) {
		$moments = omc_video_moments( $v['slug'] );
		foreach ( $moments as $m ) {
			$products[] = $m['product'];
		}
		$videos[] = [
			'slug'     => $v['slug'],
			'title'    => html_entity_decode( (string) $v['title'], ENT_QUOTES, 'UTF-8' ),
			'src'      => omc_video_src( $v ),
			'poster'   => omc_video_poster( $v, 'medium_large' ),
			'duration' => (int) $v['duration'],
			'view'     => (string) get_permalink( $pid ),
			'chapters' => array_values( array_filter( array_map( static fn( $c ) => isset( $c['at'], $c['label'] ) ? [ 'at' => (int) $c['at'], 'label' => (string) $c['label'] ] : null, (array) $v['chapters'] ) ) ),
			'moments'  => $moments,
		];
	}
	wp_localize_script( 'omc-video-shop-admin', 'OMC_VSA', [
		'rest'     => esc_url_raw( rest_url( 'omc/v1/video-shop' ) ),
		'nonce'    => wp_create_nonce( 'wp_rest' ),
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
			'empty'    => __( 'No pieces in this video yet.', 'moderno-child' ),
			'chapters' => __( 'Chapters — click one to add a piece at its start:', 'moderno-child' ),
			'view'     => __( 'View the page', 'moderno-child' ),
			'noVideos' => __( 'There are no style videos yet. They are imported by .ftp-sync/import-videos.php.', 'moderno-child' ),
			'leave'    => __( 'You have changes that are not saved. Leave anyway?', 'moderno-child' ),
			'noPiece'  => __( 'No piece chosen', 'moderno-child' ),
		],
	] );
} );

add_action( 'rest_api_init', static function () {
	register_rest_route( 'omc/v1', '/video-shop', [
		'methods'             => 'POST',
		'permission_callback' => static fn() => current_user_can( 'edit_theme_options' ),
		'callback'            => static function ( WP_REST_Request $r ) {
			$known = [];
			foreach ( omc_video_pages() as $v ) {
				$known[ $v['slug'] ] = (int) $v['duration'];
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
					$clean[] = [ 'at' => $known[ $slug ] ? min( $at, $known[ $slug ] ) : $at, 'product' => $product ];
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
			return new WP_REST_Response( [ 'videos' => $out ], 200 );
		},
	] );
} );
