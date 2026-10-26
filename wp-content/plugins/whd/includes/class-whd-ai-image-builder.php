<?php
/**
 * Image Builder: product photographs in, new pictures of the product out.
 *
 * One screen under AI Writer. Upload photographs of a piece (or pick them from the media library),
 * say how many new images to make, and set up each one: on a model or on a ghost mannequin, with a
 * prompt of its own. The pictures are made exactly the way the product screen makes them, through
 * WHD_AI_Studio's own steps: the photographs read edge to edge, the details cut out at full size
 * and counted again, every picture drawn from the photographs and the close-ups, then checked
 * against them and drawn once more with the mistakes named when it got the piece wrong. There is
 * simply no product: the steps run with post 0, and the pictures land in the media library
 * unattached, marked as made here so the screen can show them again after a reload.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_AI_Image_Builder {

	const PAGE = 'whd-ai-image-builder';

	/** On a picture made here: that it was, and the prompt it was made with. */
	const MADE = '_whd_ib';
	const ASK  = '_whd_ib_ask';

	/** Most pictures one run makes. */
	const MAX = 8;

	/** How many earlier pictures the screen shows when it opens. */
	const RECENT = 12;

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 12 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'rest' ] );
	}

	/* ─────────────────────────── where it lives ─────────────────────────── */

	/** Under AI Writer, after Bulk products. */
	public static function menu() {
		if ( ! class_exists( 'WHD_AI_Writer' ) || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		add_submenu_page(
			WHD_AI_Writer::PAGE_POST,
			__( 'Image Builder', 'whd' ),
			__( 'Image Builder', 'whd' ),
			'upload_files',
			self::PAGE,
			[ __CLASS__, 'screen' ]
		);
	}

	public static function assets() {
		// By the page, never the hook name: the hook carries where the screen sits in the menu.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which screen this is
		if ( ! isset( $_GET['page'] ) || self::PAGE !== sanitize_key( wp_unslash( $_GET['page'] ) ) || ! class_exists( 'WHD_AI_Studio' ) ) {
			return;
		}
		wp_enqueue_media();
		// The loader and the lightbox are the studio's, so their styles come from its sheet.
		wp_enqueue_style( 'whd-ai-studio', WHD_URL . 'admin/ai-studio.css', [], WHD_VERSION );
		wp_enqueue_style( 'whd-image-builder', WHD_URL . 'admin/image-builder.css', [ 'whd-ai-studio' ], WHD_VERSION );
		wp_enqueue_script( 'whd-ai-kit', WHD_URL . 'admin/ai-kit.js', [], WHD_VERSION, true );
		wp_enqueue_script( 'whd-image-builder', WHD_URL . 'admin/image-builder.js', [ 'whd-ai-kit', 'media-editor' ], WHD_VERSION, true );
		// The Image Editor under the results, built only when a picture is sent to it.
		if ( class_exists( 'WHD_Image_Editor' ) ) {
			WHD_Image_Editor::enqueue( [], true );
			wp_enqueue_script( 'whd-image-builder-edit', WHD_URL . 'admin/image-builder-edit.js', [ 'whd-image-editor-screen', 'whd-image-builder' ], WHD_VERSION, true );
		}

		wp_localize_script( 'whd-image-builder', 'WHD_IB', [
			'rest'    => [
				'root'  => esc_url_raw( rest_url( 'whd/v1/ai/builder/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'jobs'  => esc_url_raw( rest_url( 'whd/v1/ai/studio/job' ) ),
				'last'  => esc_url_raw( rest_url( 'whd/v1/ai/studio/last' ) ),
				'media' => esc_url_raw( rest_url( 'wp/v2/media' ) ),
			],
			'ready'   => self::ready(),
			'max'     => self::MAX,
			'photos'  => WHD_AI_Studio::LOOK_MAX,
			// The sizes an image can be drawn at — the Image Editor's.
			'frames'  => WHD_AI_Images::frame_list(),
			'recent'  => self::recent(),
			'i18n'    => array_merge( WHD_AI_Studio::strings(), self::strings() ),
		] );
	}

	/** Reading photographs needs a model, and drawing needs the Google key on top. */
	private static function ready(): bool {
		return WHD_AI::ready() && class_exists( 'WHD_AI_Images' ) && WHD_AI_Images::ready();
	}

	/** A picture made here, as a tile on the screen wants it. */
	private static function tile( int $id ): ?array {
		if ( ! $id || ! wp_attachment_is_image( $id ) ) {
			return null;
		}
		$meta = wp_get_attachment_metadata( $id );
		return [
			'dims'  => ! empty( $meta['width'] ) ? sprintf( '%d × %d', $meta['width'], $meta['height'] ) : '',
			'id'    => $id,
			'thumb' => (string) wp_get_attachment_image_url( $id, 'thumbnail' ),
			'url'   => (string) wp_get_attachment_image_url( $id, 'medium_large' ),
			'large' => (string) wp_get_attachment_image_url( $id, 'large' ),
			'full'  => (string) wp_get_attachment_url( $id ),
			'edit'  => (string) get_edit_post_link( $id, 'raw' ),
			'tweak' => class_exists( 'WHD_Image_Editor' ) ? WHD_Image_Editor::edit_url( [ $id ] ) : '',
			'mode'  => 'mannequin' === get_post_meta( $id, '_whd_ai_shot', true ) ? 'mannequin' : 'model',
			'ask'   => (string) get_post_meta( $id, self::ASK, true ),
			'mine'  => (int) get_post_field( 'post_author', $id ) === get_current_user_id(),
			'date'  => (string) get_the_date( '', $id ),
		];
	}

	/** What was made here before, newest first — so a reload does not lose sight of it. */
	private static function recent(): array {
		$ids = get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => self::RECENT,
			'meta_key'       => self::MADE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a dozen rows, on one admin screen
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		] );
		return array_values( array_filter( array_map( [ __CLASS__, 'tile' ], array_map( 'intval', $ids ) ) ) );
	}

	private static function strings(): array {
		return [
			'uploading'    => __( 'Uploading…', 'whd' ),
			/* translators: 1: file name, 2: the reason */
			'uploadFailed' => __( '“%1$s” could not be uploaded: %2$s', 'whd' ),
			/* translators: %s: file name */
			'notImage'     => __( '“%s” is not a JPG, PNG or WebP photo.', 'whd' ),
			/* translators: %d: the most photographs one run reads */
			'photoLimit'   => __( 'Up to %d photos — the first ones are kept.', 'whd' ),
			'library'      => __( 'Choose photos', 'whd' ),
			'useThese'     => __( 'Use these photos', 'whd' ),
			'removePhoto'  => __( 'Remove this photo', 'whd' ),
			'addMore'      => __( 'Add more', 'whd' ),
			/* translators: %d: image number */
			'imageN'       => __( 'Image %d', 'whd' ),
			'onModel'      => __( 'On a model', 'whd' ),
			'mannequin'    => __( 'Ghost mannequin', 'whd' ),
			'prompt'       => __( 'Prompt', 'whd' ),
			'optional'     => __( 'optional', 'whd' ),
			/* translators: %s: what an image left blank shows */
			'blankIs'      => __( 'Leave blank for: %s', 'whd' ),
			'copyAll'      => __( 'Copy to all', 'whd' ),
			'copyAllLong'  => __( 'Use this prompt for every image', 'whd' ),
			'copiedAll'    => __( 'Copied', 'whd' ),
			'clear'        => __( 'Clear', 'whd' ),
			'chipsModel'   => [
				__( 'Full length', 'whd' ),
				__( 'Waist up', 'whd' ),
				__( 'Back view', 'whd' ),
				__( 'Walking', 'whd' ),
				__( 'Seated', 'whd' ),
				__( 'City street', 'whd' ),
				__( 'Café', 'whd' ),
				__( 'Plain studio backdrop', 'whd' ),
			],
			'chipsGhost'   => [
				__( 'Front view', 'whd' ),
				__( 'Back view', 'whd' ),
				__( 'Side view', 'whd' ),
				__( 'Three-quarter view', 'whd' ),
				__( 'Close-up of the details', 'whd' ),
				__( 'Open, showing the inside', 'whd' ),
				__( 'Pure white background', 'whd' ),
			],
			// What an image left blank is asked to be: the nth model shot gets the nth line.
			'blankModel'   => [
				__( 'Full length, walking, relaxed — a natural everyday moment', 'whd' ),
				__( 'Three-quarter view, standing at ease', 'whd' ),
				__( 'Back view, looking over her shoulder, so the back of the piece shows', 'whd' ),
				__( 'Waist up and closer, so the fabric and the details read', 'whd' ),
				__( 'Seated, relaxed, the whole piece in view', 'whd' ),
				__( 'Side view, full length, mid-stride', 'whd' ),
				__( 'Leaning against a wall, at ease, full length', 'whd' ),
				__( 'Close-up of the details, worn', 'whd' ),
			],
			'blankGhost'   => [
				__( 'Front view of the whole piece', 'whd' ),
				__( 'Back view of the whole piece', 'whd' ),
				__( 'Three-quarter view from the left', 'whd' ),
				__( 'Side view', 'whd' ),
				__( 'Close-up of the fastenings and trims', 'whd' ),
				__( 'Three-quarter view from the right', 'whd' ),
				__( 'Close-up of the collar or neckline', 'whd' ),
				__( 'Front view, a little from above', 'whd' ),
			],
			// One and more than one, picked in the browser by the count.
			/* translators: %d: number of images */
			'generateN'    => [ __( 'Generate %d image', 'whd' ), __( 'Generate %d images', 'whd' ) ],
			'needPhotos'   => __( 'Add at least one photo of the product to start.', 'whd' ),
			'waitUpload'   => __( 'Wait for the photos to finish uploading.', 'whd' ),
			'needReady'    => __( 'Drawing images needs a connected model and a Google AI key — add them in WHD → AI content.', 'whd' ),
			/* translators: %d: number of photos */
			'sumPhotos'    => [ __( '%d photo', 'whd' ), __( '%d photos', 'whd' ) ],
			/* translators: %d: number of images */
			'sumModel'     => __( '%d on a model', 'whd' ),
			/* translators: %d: number of images */
			'sumGhost'     => __( '%d on a ghost mannequin', 'whd' ),
			/* translators: 1: fewest minutes, 2: most minutes */
			'sumTime'      => __( 'About %1$d–%2$d minutes', 'whd' ),
			'sumNone'      => __( 'No photos yet', 'whd' ),
			'frameSame'     => __( 'Same shape as your first photo', 'whd' ),
			'frameSameNote' => __( 'The nearest shape the model draws to your first photo, at the size set in WHD → AI content. Pick a size to get images made exactly to it — for a post, a story, a banner or the shop.', 'whd' ),
			/* translators: 1: image number, 2: how many */
			'stepImage'    => __( 'Image %1$d of %2$d', 'whd' ),
			'genDone'      => __( 'Your images are ready — they are in your media library.', 'whd' ),
			'waiting'      => __( 'Waiting its turn', 'whd' ),
			'drawing'      => __( 'Drawing…', 'whd' ),
			'earlier'      => __( 'Made earlier', 'whd' ),
			'view'         => __( 'View larger', 'whd' ),
			'download'     => __( 'Download', 'whd' ),
			'openLibrary'  => __( 'Open in media library', 'whd' ),
			'tweak'        => __( 'Add text or effects in Image Editor', 'whd' ),
			'redo'         => __( 'Draw again', 'whd' ),
			'delete'       => __( 'Delete', 'whd' ),
			'dismiss'      => __( 'Remove from this list', 'whd' ),
			'confirmDel'   => __( 'Delete this image from the media library? This cannot be undone.', 'whd' ),
			'notDeleted'   => __( 'This image could not be deleted here — it may be in use on a product. Delete it from the media library instead.', 'whd' ),
			'noPrompt'     => __( 'No prompt', 'whd' ),
		];
	}

	/* ─────────────────────────── the screen ─────────────────────────── */

	public static function screen() {
		$ready = self::ready();
		$icon  = static function ( string $paths, int $size = 20 ) {
			return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
		};
		?>
		<div class="wrap whd-wrap whd-ib" id="whd-ib">
			<header class="whd-ib__head">
				<p class="whd-ib__eyebrow"><?php esc_html_e( 'AI Writer', 'whd' ); ?></p>
				<h1 class="whd-title"><?php esc_html_e( 'Image Builder', 'whd' ); ?></h1>
				<p class="whd-ib__lead"><?php esc_html_e( 'New pictures of a product from the photos you already have — worn by a model or on a ghost mannequin. Every picture is drawn from your photos, checked against them, and saved to your media library.', 'whd' ); ?></p>
			</header>
			<hr class="wp-header-end">

			<?php if ( ! $ready ) : ?>
				<div class="whd-note whd-note--warn">
					<?php esc_html_e( 'Drawing images needs a connected model and a Google AI key.', 'whd' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=whd-ai' ) ); ?>"><?php esc_html_e( 'Add them in WHD → AI content.', 'whd' ); ?></a>
				</div>
			<?php endif; ?>

			<div class="whd-ib__layout">
				<div class="whd-ib__main">

					<section class="whd-ib__card" aria-labelledby="whd-ib-h-photos">
						<header class="whd-ib__card-head">
							<span class="whd-ib__num" aria-hidden="true">1</span>
							<div>
								<h2 class="whd-ib__h" id="whd-ib-h-photos"><?php esc_html_e( 'Product photos', 'whd' ); ?></h2>
								<p class="whd-ib__sub">
									<?php
									/* translators: %d: the most photographs one run reads */
									echo esc_html( sprintf( __( 'Upload or choose up to %d. A front, a back and close-ups of the details give the truest results.', 'whd' ), WHD_AI_Studio::LOOK_MAX ) );
									?>
								</p>
							</div>
						</header>

						<div class="whd-ib__drop" id="whd-ib-drop">
							<input type="file" id="whd-ib-file" class="screen-reader-text" accept="image/jpeg,image/png,image/webp" multiple tabindex="-1">
							<div class="whd-ib__drop-empty" id="whd-ib-drop-empty">
								<span class="whd-ib__drop-icon"><?php echo $icon( '<path d="M4 16.5V18a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-1.5"/><path d="M12 15V4"/><path d="m7.5 8.5 4.5-4.5 4.5 4.5"/>', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup ?></span>
								<p class="whd-ib__drop-title"><?php esc_html_e( 'Drag your product photos here', 'whd' ); ?></p>
								<p class="whd-ib__drop-or"><?php esc_html_e( 'or', 'whd' ); ?></p>
								<div class="whd-ib__drop-btns">
									<button type="button" class="whd-ib__btn whd-ib__btn--dark" data-upload><?php echo $icon( '<path d="M12 16V5"/><path d="m7.5 9.5 4.5-4.5 4.5 4.5"/><path d="M5 19h14"/>', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e( 'Upload from computer', 'whd' ); ?></span></button>
									<button type="button" class="whd-ib__btn" data-library><?php echo $icon( '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m20.5 16-4.5-4.5L7 19.5"/>', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e( 'Choose from media library', 'whd' ); ?></span></button>
								</div>
								<p class="whd-ib__drop-note"><?php esc_html_e( 'JPG, PNG or WebP', 'whd' ); ?></p>
							</div>
							<div class="whd-ib__thumbs" id="whd-ib-photos" hidden></div>
							<div class="whd-ib__drop-veil" aria-hidden="true"><?php esc_html_e( 'Drop to upload', 'whd' ); ?></div>
						</div>
						<p class="whd-ib__msg" id="whd-ib-photos-msg" role="status" aria-live="polite"></p>
					</section>

					<section class="whd-ib__card" aria-labelledby="whd-ib-h-count">
						<header class="whd-ib__card-head">
							<span class="whd-ib__num" aria-hidden="true">2</span>
							<div>
								<h2 class="whd-ib__h" id="whd-ib-h-count"><?php esc_html_e( 'How many images?', 'whd' ); ?></h2>
								<p class="whd-ib__sub"><?php esc_html_e( 'Each one takes about a minute: it is drawn, checked against your photos, and drawn again if it got the piece wrong.', 'whd' ); ?></p>
							</div>
						</header>
						<div class="whd-ib__count" id="whd-ib-count" role="radiogroup" aria-labelledby="whd-ib-h-count"></div>
						<div class="whd-ib__frame" id="whd-ib-frame"></div>
						<div class="whd-ib__setall">
							<span class="whd-ib__setall-label"><?php esc_html_e( 'Set every image to', 'whd' ); ?></span>
							<button type="button" class="whd-ib__chip whd-ib__chip--mode" data-all="model"><?php esc_html_e( 'On a model', 'whd' ); ?></button>
							<button type="button" class="whd-ib__chip whd-ib__chip--mode" data-all="mannequin"><?php esc_html_e( 'Ghost mannequin', 'whd' ); ?></button>
						</div>
					</section>

					<section class="whd-ib__card" aria-labelledby="whd-ib-h-shots">
						<header class="whd-ib__card-head">
							<span class="whd-ib__num" aria-hidden="true">3</span>
							<div>
								<h2 class="whd-ib__h" id="whd-ib-h-shots"><?php esc_html_e( 'Set up each image', 'whd' ); ?></h2>
								<p class="whd-ib__sub"><?php esc_html_e( 'Choose how each one is shown and, if you like, describe it: the pose, the angle, the setting, the light. A prompt left blank gets a different view, so the set never repeats itself.', 'whd' ); ?></p>
							</div>
						</header>
						<div class="whd-ib__shots" id="whd-ib-shots"></div>
					</section>
				</div>

				<aside class="whd-ib__side" aria-label="<?php esc_attr_e( 'Summary', 'whd' ); ?>">
					<div class="whd-ib__summary">
						<div class="whd-ib__sum-photos" id="whd-ib-sum-photos" aria-hidden="true"></div>
						<dl class="whd-ib__sum-list">
							<div><dt><?php esc_html_e( 'Photos', 'whd' ); ?></dt><dd id="whd-ib-sum-p"></dd></div>
							<div><dt><?php esc_html_e( 'Images', 'whd' ); ?></dt><dd id="whd-ib-sum-i"></dd></div>
							<div><dt><?php esc_html_e( 'Time', 'whd' ); ?></dt><dd id="whd-ib-sum-t"></dd></div>
							<div><dt><?php esc_html_e( 'Saved to', 'whd' ); ?></dt><dd><?php esc_html_e( 'Media library', 'whd' ); ?></dd></div>
						</dl>
						<button type="button" class="whd-ib__go" id="whd-ib-go"<?php disabled( ! $ready ); ?>>
							<?php echo $icon( '<path d="M12 3.5 13.9 9l5.6 1.9-5.6 1.9L12 18.5l-1.9-5.7-5.6-1.9L10.1 9z"/><path d="M19 3v3M17.5 4.5h3"/>', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span id="whd-ib-go-label"><?php esc_html_e( 'Generate images', 'whd' ); ?></span>
						</button>
						<p class="whd-ib__why" id="whd-ib-why" role="status" aria-live="polite"></p>
					</div>
				</aside>
			</div>

			<section class="whd-ib__results" id="whd-ib-results" aria-labelledby="whd-ib-h-results">
				<header class="whd-ib__results-head">
					<h2 class="whd-ib__h" id="whd-ib-h-results"><?php esc_html_e( 'Your images', 'whd' ); ?></h2>
					<p class="whd-ib__sub"><?php esc_html_e( 'Every image is saved to your media library as soon as it is made. Newest first.', 'whd' ); ?></p>
					<?php if ( class_exists( 'WHD_Image_Editor' ) ) : ?>
						<button type="button" class="button whd-ib__edit-all" id="whd-ib-edit-all" hidden><?php esc_html_e( 'Edit these images ↓', 'whd' ); ?></button>
					<?php endif; ?>
				</header>
				<div class="whd-ib__tiles" id="whd-ib-tiles"></div>
				<p class="whd-ib__empty" id="whd-ib-empty"><?php esc_html_e( 'Images you make appear here.', 'whd' ); ?></p>
			</section>

			<?php if ( class_exists( 'WHD_Image_Editor' ) ) : ?>
				<section class="whd-ib__edit" id="whd-ib-edit" aria-labelledby="whd-ib-h-edit" hidden>
					<header class="whd-ib__results-head">
						<h2 class="whd-ib__h" id="whd-ib-h-edit"><?php esc_html_e( 'Image Editor', 'whd' ); ?></h2>
						<p class="whd-ib__sub"><?php esc_html_e( 'Add text, stickers, shapes, templates and filters. Saving adds a new image to the media library; the one you made is kept as it is.', 'whd' ); ?></p>
						<button type="button" class="button-link" data-ib-edit-close><?php esc_html_e( 'Close the editor', 'whd' ); ?></button>
					</header>
					<div class="whd-ie" id="whd-ie"></div>
				</section>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ─────────────────────────── the wires ─────────────────────────── */

	public static function rest() {
		$can = static function () {
			return current_user_can( 'upload_files' ) && current_user_can( 'edit_posts' );
		};
		/*
		 * The steps that wait on a model are the studio's own, run as background jobs and asked after
		 * at studio/job. Post 0: there is no product, and nothing is attached to one.
		 */
		$jobs = [
			'look'    => static fn( WP_REST_Request $r ) => WHD_AI_Studio::look( 0, (array) $r->get_param( 'images' ) ),
			'inspect' => static fn( WP_REST_Request $r ) => WHD_AI_Studio::inspect( 0, (array) $r->get_param( 'facts' ) ),
			'draw'    => static fn( WP_REST_Request $r ) => self::draw( $r->get_json_params() ?: [] ),
			'check'   => static fn( WP_REST_Request $r ) => WHD_AI_Studio::check( 0, $r->get_json_params() ?: [] ),
		];
		foreach ( $jobs as $step => $work ) {
			register_rest_route( 'whd/v1', '/ai/builder/' . $step, [
				'methods'             => 'POST',
				'callback'            => static function ( WP_REST_Request $r ) use ( $step, $work ) {
					return WHD_AI_Studio::background( 'builder-' . $step, static fn() => self::answer( $work( $r ) ) );
				},
				'permission_callback' => $can,
			] );
		}
		register_rest_route( 'whd/v1', '/ai/builder/discard', [
			'methods'             => 'POST',
			'callback'            => static fn( WP_REST_Request $r ) => self::answer( [ 'deleted' => WHD_AI_Studio::discard( 0, absint( $r->get_param( 'image' ) ) ) ] ),
			'permission_callback' => $can,
		] );
	}

	/** One picture, drawn by the studio with no product, then marked as made here. */
	private static function draw( array $args ) {
		$pic = WHD_AI_Studio::draw( 0, $args );
		if ( is_wp_error( $pic ) ) {
			return $pic;
		}
		update_post_meta( $pic['id'], self::MADE, 1 );
		$ask = mb_substr( sanitize_textarea_field( (string) ( $args['shot']['ask'] ?? '' ) ), 0, 500 );
		if ( '' !== $ask ) {
			update_post_meta( $pic['id'], self::ASK, $ask );
		}
		return self::tile( (int) $pic['id'] ) ?: $pic;
	}

	private static function answer( $result ) {
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'message' => $result->get_error_message() ], 400 );
		}
		return new WP_REST_Response( $result, 200 );
	}
}
