<?php
/**
 * Video Editor: any video in the media library, cut and dressed by hand — text with motion, stickers that
 * move, animated effects, filters, music and sound effects — then saved back as a new video, or a GIF.
 *
 * Everything happens in the browser (admin/video-engine.js + video-editor.js): the preview is the real
 * renderer drawing the same frames the export encodes, through Mediabunny (MPL-2.0, vendored unmodified in
 * admin/vendor) and the browser's own WebCodecs encoder, so the shared host never has to run ffmpeg. This
 * class owns the screen, the way in from the media library, chunked uploads (a minute of 1080p is more than
 * a host's upload limit, so files go up in pieces), and the sounds: the plugin's own public-domain library,
 * the shop's own audio, and a search of Openverse limited to CC0 and public-domain recordings, imported into
 * the media library before use so the editor reads them from its own site.
 *
 * Saving writes a new attachment and keeps the edit on it (_whd_video_design), so it can be opened and
 * changed again rather than started over. The original is never touched.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Video_Editor {

	const PAGE = 'whd-video-editor';

	/** The edit of a video saved from the editor, as JSON, and the clip it was made from. */
	const DESIGN = '_whd_video_design';
	const SOURCE = '_whd_video_source';

	/** A sound imported from Openverse: its record there (id, licence, creator, page). */
	const SOUND    = '_whd_sound';
	const SOUND_ID = '_whd_sound_id';

	/** The largest file the editor will take or hand back, and the largest piece of one. */
	const MAX_BYTES   = 512 * MB_IN_BYTES;
	const CHUNK_BYTES = 8 * MB_IN_BYTES;

	/** Most clips opened at once from a link. */
	const START_MAX = 12;

	/** Openverse: anonymous use allows 20 requests a minute and 200 a day, so searches are kept for a while. */
	const OPENVERSE   = 'https://api.openverse.org/v1/audio/';
	const SEARCH_KEEP = 12 * HOUR_IN_SECONDS;
	const SOUND_MAX   = 40 * MB_IN_BYTES;

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 15 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'rest' ] );
		add_filter( 'media_row_actions', [ __CLASS__, 'row_action' ], 10, 2 );
		add_filter( 'attachment_fields_to_edit', [ __CLASS__, 'attachment_field' ], 10, 2 );
	}

	/* ─────────────────────────── where it lives ─────────────────────────── */

	/** Under AI Writer, after the Video Builder. */
	public static function menu() {
		if ( ! class_exists( 'WHD_AI_Writer' ) || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		add_submenu_page( WHD_AI_Writer::PAGE_POST, __( 'Video Editor', 'whd' ), __( 'Video Editor', 'whd' ), 'upload_files', self::PAGE, [ __CLASS__, 'screen' ] );
	}

	/** The editor, opened on these clips. */
	public static function edit_url( array $ids ): string {
		return add_query_arg( [ 'page' => self::PAGE, 'ids' => implode( ',', array_map( 'absint', $ids ) ) ], admin_url( 'admin.php' ) );
	}

	private static function editable( int $id ): bool {
		$mime = (string) get_post_mime_type( $id );
		return 0 === strpos( $mime, 'video/' ) || 'image/gif' === $mime;
	}

	public static function row_action( $actions, $post ) {
		if ( $post && self::editable( (int) $post->ID ) && current_user_can( 'upload_files' ) ) {
			$actions['whd_video_editor'] = '<a href="' . esc_url( self::edit_url( [ (int) $post->ID ] ) ) . '">' . esc_html__( 'Edit in Video Editor', 'whd' ) . '</a>';
		}
		return $actions;
	}

	public static function attachment_field( $fields, $post ) {
		if ( $post && self::editable( (int) $post->ID ) && current_user_can( 'upload_files' ) ) {
			$fields['whd_video_editor'] = [
				'label' => __( 'Video Editor', 'whd' ),
				'input' => 'html',
				'html'  => '<a class="button button-small" href="' . esc_url( self::edit_url( [ (int) $post->ID ] ) ) . '">' . esc_html__( 'Add text, stickers, music or effects', 'whd' ) . '</a>',
			];
		}
		return $fields;
	}

	/* ─────────────────────────── the screen ─────────────────────────── */

	public static function assets() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which screen this is
		if ( ! isset( $_GET['page'] ) || self::PAGE !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks what to show
		$given = isset( $_GET['ids'] ) ? sanitize_text_field( wp_unslash( $_GET['ids'] ) ) : ( isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : '' );
		self::enqueue( array_slice( wp_parse_id_list( (string) $given ), 0, self::START_MAX ) );
	}

	/**
	 * Everything the editor needs on a screen. Its own screen opens straight away on $ids; the Video Builder
	 * loads it $lazy, and it opens under the results on whatever was just made.
	 */
	public static function enqueue( array $ids = [], bool $lazy = false ) {
		wp_enqueue_media();
		$families = [];
		foreach ( self::fonts() as $name => $axes ) {
			$families[] = 'family=' . str_replace( ' ', '+', $name ) . ( '' !== $axes ? ':' . $axes : '' );
		}
		// No version on the fonts: WordPress would rebuild the address and keep only the last family.
		wp_enqueue_style( 'whd-ve-fonts', 'https://fonts.googleapis.com/css2?' . implode( '&', $families ) . '&display=swap', [], null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_style( 'whd-video-editor', WHD_URL . 'admin/video-editor.css', [], WHD_VERSION );
		wp_enqueue_script( 'whd-ai-kit', WHD_URL . 'admin/ai-kit.js', [], WHD_VERSION, true );
		wp_enqueue_script( 'whd-video-presets', WHD_URL . 'admin/video-presets.js', [], WHD_VERSION, true );
		wp_enqueue_script( 'whd-video-engine', WHD_URL . 'admin/video-engine.js', [ 'whd-video-presets' ], WHD_VERSION, true );
		wp_enqueue_script( 'whd-video-editor', WHD_URL . 'admin/video-editor.js', [ 'whd-ai-kit', 'whd-video-engine', 'media-editor' ], WHD_VERSION, true );
		wp_enqueue_script( 'whd-video-editor-screen', WHD_URL . 'admin/video-editor-screen.js', [ 'whd-video-editor' ], WHD_VERSION, true );

		wp_localize_script( 'whd-video-engine', 'WHD_VE', [
			'rest'     => [
				'root'  => esc_url_raw( rest_url( 'whd/v1/video-editor/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			],
			'vendor'   => [
				'mediabunny' => WHD_URL . 'admin/vendor/mediabunny.min.js?ver=1.66.0',
				'gifenc'     => WHD_URL . 'admin/vendor/gifenc.esm.js?ver=1.0.3',
			],
			'start'    => array_values( array_filter( array_map( [ __CLASS__, 'clip' ], array_map( 'intval', $ids ) ) ) ),
			'lazy'     => $lazy,
			'chunk'    => 3 * MB_IN_BYTES,
			'fonts'    => array_keys( self::fonts() ),
			'stickers' => self::stickers(),
			'logos'    => self::logos(),
			'sounds'   => self::library(),
			'recent'   => self::recent_videos(),
			'brand'    => [
				'name'   => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
				'colors' => [ '#ffffff', '#1d1d1f', '#b98b7e', '#3a2b26', '#f7f2ed', '#efe6de', '#9c6f63', '#d9c9c0', '#e8c872', '#ff4f8b', '#46e8ff', '#8a1cff' ],
			],
			'currency' => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '$',
			'builder'  => class_exists( 'WHD_AI_Video' ) ? admin_url( 'admin.php?page=' . WHD_AI_Video::PAGE ) : '',
			'i18n'     => self::strings(),
		] );
	}

	/** The Image Editor's typefaces, so a design and a video can share a look. */
	private static function fonts(): array {
		return class_exists( 'WHD_Image_Editor' ) ? WHD_Image_Editor::fonts() : [ 'Montserrat' => 'wght@400;700;900', 'Playfair Display' => 'wght@400;700' ];
	}

	/** The live studio's moving 3D stickers (Noto Emoji, Apache 2.0 / OFL — both texts kept beside them). */
	private static function stickers(): array {
		$file = WHD_DIR . 'assets/live-stickers/stickers.json';
		$data = file_exists( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own file
		$out  = [];
		foreach ( (array) $data as $item ) {
			if ( empty( $item['file'] ) ) {
				continue;
			}
			$out[] = [
				'url'   => WHD_URL . 'assets/live-stickers/' . $item['file'],
				'label' => (string) ( $item['label'] ?? '' ),
				'group' => (string) ( $item['group'] ?? '' ),
				'anim'  => (string) ( $item['anim'] ?? '' ),
			];
		}
		return $out;
	}

	/** The shop's own marks, full size. */
	private static function logos(): array {
		$out = [];
		$dir = get_stylesheet_directory() . '/assets/img/';
		$uri = get_stylesheet_directory_uri() . '/assets/img/';
		foreach ( [ 'rose', 'black', 'white', 'cream' ] as $variant ) {
			if ( file_exists( $dir . 'oops-logo-' . $variant . '.png' ) ) {
				$out[] = [ 'name' => ucfirst( $variant ), 'url' => $uri . 'oops-logo-' . $variant . '.png' ];
			}
		}
		$custom = (int) get_theme_mod( 'custom_logo' );
		if ( $custom ) {
			$out[] = [ 'name' => __( 'Site logo', 'whd' ), 'url' => (string) wp_get_attachment_url( $custom ) ];
		}
		return $out;
	}

	/**
	 * The plugin's own sounds: public-domain music in moods, effects, and the seasonal tracks — the files the
	 * live studio uses, listed in assets/live-sounds/sounds.json with their sources and CC0 licences.
	 */
	private static function library(): array {
		$file = WHD_DIR . 'assets/live-sounds/sounds.json';
		$data = file_exists( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own file
		$out  = [ 'music' => [], 'fx' => [] ];
		foreach ( (array) ( $data['music'] ?? [] ) as $m ) {
			$out['music'][] = [
				'url'     => WHD_URL . 'assets/live-sounds/' . $m['file'],
				'title'   => (string) $m['title'],
				'by'      => (string) ( $m['artist'] ?? '' ),
				'group'   => (string) ( $m['mood'] ?? '' ),
				'seconds' => (int) ( $m['seconds'] ?? 0 ),
			];
		}
		foreach ( (array) ( $data['occasions'] ?? [] ) as $occasion => $tracks ) {
			foreach ( (array) $tracks as $m ) {
				$out['music'][] = [
					'url'     => WHD_URL . 'assets/live-sounds/' . $m['file'],
					'title'   => (string) $m['title'],
					'by'      => (string) ( $m['artist'] ?? '' ),
					'group'   => ucwords( str_replace( [ '-', '_' ], ' ', (string) $occasion ) ),
					'seconds' => (int) ( $m['seconds'] ?? 0 ),
				];
			}
		}
		foreach ( [ 'fx', 'reveal' ] as $kind ) {
			foreach ( (array) ( $data[ $kind ] ?? [] ) as $m ) {
				$out['fx'][] = [
					'url'     => WHD_URL . 'assets/live-sounds/' . $m['file'],
					'title'   => (string) ( $m['label'] ?? $m['title'] ?? '' ),
					'group'   => 'reveal' === $kind ? __( 'Reveal', 'whd' ) : __( 'Effects', 'whd' ),
					'seconds' => (float) ( $m['seconds'] ?? 0 ),
				];
			}
		}
		return $out;
	}

	/** Videos made in the Video Builder, to start an edit from. */
	private static function recent_videos(): array {
		if ( ! class_exists( 'WHD_AI_Video' ) ) {
			return [];
		}
		$ids = get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 12,
			'post_mime_type' => 'video',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		] );
		return array_values( array_filter( array_map( [ __CLASS__, 'clip' ], array_map( 'intval', $ids ) ) ) );
	}

	/** One library file as the editor wants it — and its edit, when the editor saved it. */
	public static function clip( int $id ): ?array {
		if ( ! $id || ! current_user_can( 'upload_files' ) ) {
			return null;
		}
		$mime = (string) get_post_mime_type( $id );
		if ( 0 !== strpos( $mime, 'video/' ) && 0 !== strpos( $mime, 'image/' ) ) {
			return null;
		}
		$meta   = wp_get_attachment_metadata( $id );
		$poster = (int) get_post_thumbnail_id( $id );
		$design = (string) get_post_meta( $id, self::DESIGN, true );
		return [
			'id'      => $id,
			'url'     => (string) wp_get_attachment_url( $id ),
			'mime'    => $mime,
			'title'   => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'seconds' => (float) ( $meta['length'] ?? 0 ),
			'width'   => (int) ( $meta['width'] ?? 0 ),
			'height'  => (int) ( $meta['height'] ?? 0 ),
			'poster'  => $poster ? (string) wp_get_attachment_image_url( $poster, 'medium' ) : ( 0 === strpos( $mime, 'image/' ) ? (string) wp_get_attachment_image_url( $id, 'medium' ) : '' ),
			'design'  => '' !== $design ? $design : null,
		];
	}

	private static function strings(): array {
		return [
			'error'        => __( 'Something went wrong', 'whd' ),
			'media'        => __( 'Clips', 'whd' ),
			'text'         => __( 'Text', 'whd' ),
			'stickers'     => __( 'Stickers', 'whd' ),
			'effects'      => __( 'Effects', 'whd' ),
			'music'        => __( 'Music', 'whd' ),
			'filters'      => __( 'Filters', 'whd' ),
			'project'      => __( 'Video', 'whd' ),
			'addClips'     => __( 'Add videos or photos', 'whd' ),
			'uploadClip'   => __( 'Upload from computer', 'whd' ),
			'fromBuilder'  => __( 'Recent videos', 'whd' ),
			'chooseClips'  => __( 'Choose videos or photos', 'whd' ),
			'useThese'     => __( 'Use these', 'whd' ),
			'emptyTitle'   => __( 'Start with a video', 'whd' ),
			'emptyNote'    => __( 'Choose videos or photos from your media library, or upload one. Several play one after another.', 'whd' ),
			'play'         => __( 'Play', 'whd' ),
			'pause'        => __( 'Pause', 'whd' ),
			'undo'         => __( 'Undo', 'whd' ),
			'redo'         => __( 'Redo', 'whd' ),
			'save'         => __( 'Save to media library', 'whd' ),
			'download'     => __( 'Download', 'whd' ),
			'exportAs'     => __( 'Save as', 'whd' ),
			'asVideo'      => __( 'Video (MP4)', 'whd' ),
			'asGif'        => __( 'GIF', 'whd' ),
			'quality'      => __( 'Size', 'whd' ),
			'gifWidth'     => __( 'GIF width', 'whd' ),
			'gifFps'       => __( 'Frames per second', 'whd' ),
			'rendering'    => __( 'Rendering your video…', 'whd' ),
			'renderingGif' => __( 'Rendering your GIF…', 'whd' ),
			'encodingAudio'=> __( 'Mixing the sound…', 'whd' ),
			'uploadingTo'  => __( 'Saving to the media library…', 'whd' ),
			'savedTo'      => __( 'Saved to your media library.', 'whd' ),
			'openSaved'    => __( 'Open it', 'whd' ),
			'editSaved'    => __( 'Edit the saved copy', 'whd' ),
			'cancel'       => __( 'Cancel', 'whd' ),
			'close'        => __( 'Close', 'whd' ),
			'delete'       => __( 'Delete', 'whd' ),
			'duplicate'    => __( 'Duplicate', 'whd' ),
			'split'        => __( 'Split at the playhead', 'whd' ),
			'boomerang'    => __( 'Boomerang (add it reversed)', 'whd' ),
			'moveLeft'     => __( 'Move earlier', 'whd' ),
			'moveRight'    => __( 'Move later', 'whd' ),
			'start'        => __( 'Starts', 'whd' ),
			'end'          => __( 'Ends', 'whd' ),
			'trimIn'       => __( 'Trim start', 'whd' ),
			'trimOut'      => __( 'Trim end', 'whd' ),
			'speed'        => __( 'Speed', 'whd' ),
			'volume'       => __( 'Volume', 'whd' ),
			'mute'         => __( 'Mute its sound', 'whd' ),
			'reverse'      => __( 'Play backwards', 'whd' ),
			'fit'          => __( 'Fit', 'whd' ),
			'fill'         => __( 'Fill the frame', 'whd' ),
			'whole'        => __( 'Show it whole', 'whd' ),
			'zoom'         => __( 'Zoom', 'whd' ),
			'posX'         => __( 'Across', 'whd' ),
			'posY'         => __( 'Down', 'whd' ),
			'motion'       => __( 'Slow zoom', 'whd' ),
			'duration'     => __( 'Length', 'whd' ),
			'fadeIn'       => __( 'Fade in', 'whd' ),
			'fadeOut'      => __( 'Fade out', 'whd' ),
			'transition'   => __( 'Into the next clip', 'whd' ),
			'filter'       => __( 'Filter', 'whd' ),
			'adjust'       => __( 'Adjust', 'whd' ),
			'brightness'   => __( 'Brightness', 'whd' ),
			'contrast'     => __( 'Contrast', 'whd' ),
			'saturation'   => __( 'Saturation', 'whd' ),
			'warmth'       => __( 'Warmth', 'whd' ),
			'applyAll'     => __( 'Use on every clip', 'whd' ),
			'yourText'     => __( 'Your text', 'whd' ),
			'font'         => __( 'Font', 'whd' ),
			'weight'       => __( 'Weight', 'whd' ),
			'size'         => __( 'Size', 'whd' ),
			'color'        => __( 'Colour', 'whd' ),
			'accent'       => __( 'Second colour', 'whd' ),
			'style'        => __( 'Style', 'whd' ),
			'align'        => __( 'Align', 'whd' ),
			'spacing'      => __( 'Letter spacing', 'whd' ),
			'caps'         => __( 'Capitals', 'whd' ),
			'italic'       => __( 'Italic', 'whd' ),
			'rotate'       => __( 'Rotate', 'whd' ),
			'opacity'      => __( 'Opacity', 'whd' ),
			'animIn'       => __( 'Comes in', 'whd' ),
			'animOut'      => __( 'Goes out', 'whd' ),
			'animLoop'     => __( 'While on screen', 'whd' ),
			'animTime'     => __( 'Animation time', 'whd' ),
			'flip'         => __( 'Flip', 'whd' ),
			'intensity'    => __( 'Amount', 'whd' ),
			'wholeVideo'   => __( 'Whole video', 'whd' ),
			'fromHere'     => __( 'From the playhead', 'whd' ),
			'library'      => __( 'Library', 'whd' ),
			'search'       => __( 'Search free sounds', 'whd' ),
			'searchHint'   => __( 'Music and sound effects anyone may use for anything — CC0 and public domain only, from Openverse.', 'whd' ),
			'searchPh'     => __( 'e.g. upbeat pop, whoosh, applause, cash register', 'whd' ),
			'kindAny'      => __( 'Everything', 'whd' ),
			'kindMusic'    => __( 'Music', 'whd' ),
			'kindFx'       => __( 'Sound effects', 'whd' ),
			'mine'         => __( 'My audio', 'whd' ),
			'uploadAudio'  => __( 'Upload audio', 'whd' ),
			'add'          => __( 'Add', 'whd' ),
			'adding'       => __( 'Adding…', 'whd' ),
			'preview'      => __( 'Listen', 'whd' ),
			'stopPreview'  => __( 'Stop', 'whd' ),
			'noResults'    => __( 'Nothing found. Try other words.', 'whd' ),
			'searching'    => __( 'Searching…', 'whd' ),
			'more'         => __( 'More', 'whd' ),
			'cc0'          => __( 'Free to use (CC0)', 'whd' ),
			'loop'         => __( 'Repeat to fill', 'whd' ),
			'offset'       => __( 'Start the sound at', 'whd' ),
			'sticker3d'    => __( 'Moving stickers', 'whd' ),
			'emoji'        => __( 'Emoji', 'whd' ),
			'logos'        => __( 'Your logo', 'whd' ),
			'fromLibrary'  => __( 'A picture from the media library', 'whd' ),
			'sizePreset'   => __( 'Shape', 'whd' ),
			'fps'          => __( 'Frames per second', 'whd' ),
			'background'   => __( 'Background', 'whd' ),
			'name'         => __( 'Name', 'whd' ),
			'track_video'  => __( 'Video', 'whd' ),
			'track_text'   => __( 'Text', 'whd' ),
			'track_sticker'=> __( 'Stickers', 'whd' ),
			'track_effect' => __( 'Effects', 'whd' ),
			'track_audio'  => __( 'Sound', 'whd' ),
			'zoomTimeline' => __( 'Zoom the timeline', 'whd' ),
			'nothingSel'   => __( 'Click anything on the video or the timeline to change it.', 'whd' ),
			'unsaved'      => __( 'You have changes that are not saved. Leave anyway?', 'whd' ),
			'restore'      => __( 'You have an edit that was not saved.', 'whd' ),
			'restoreIt'    => __( 'Carry on with it', 'whd' ),
			'discardIt'    => __( 'Start afresh', 'whd' ),
			'needBrowser'  => __( 'Saving a video needs a browser with WebCodecs — Chrome, Edge, or Safari 17 and newer.', 'whd' ),
			'needSecure'   => __( 'Saving a video needs the admin to be opened over https:// — browsers only encode video on secure pages.', 'whd' ),
			'noCodec'      => __( 'This browser cannot encode video at this size. Try a smaller size, or Chrome.', 'whd' ),
			'tooBig'       => __( 'That file is too large to save from the browser.', 'whd' ),
			'loadFailed'   => __( 'That clip could not be read.', 'whd' ),
			'keyHelp'      => __( 'Space plays and pauses · Delete removes · Ctrl+Z undoes · Ctrl+D duplicates · arrows nudge', 'whd' ),
			'saveFirst'    => __( 'Add a clip first.', 'whd' ),
			'importFailed' => __( 'That sound could not be added.', 'whd' ),
			'snapshot'     => __( 'Use this frame as the cover', 'whd' ),
			'none'         => __( 'None', 'whd' ),
			'on'           => __( 'On', 'whd' ),
			'seconds'      => __( 's', 'whd' ),
		];
	}

	public static function screen() {
		?>
		<div class="wrap whd-wrap whd-ved">
			<header class="whd-ved__head">
				<p class="whd-ved__eyebrow"><?php esc_html_e( 'AI Writer', 'whd' ); ?></p>
				<h1 class="whd-title"><?php esc_html_e( 'Video Editor', 'whd' ); ?></h1>
				<p class="whd-ved__lead"><?php esc_html_e( 'Cut and dress a video: text with motion, moving stickers, animated effects, filters, music and sound effects — free to use, from a library and a search. Save it as a new video or a GIF; the original is never changed.', 'whd' ); ?></p>
			</header>
			<hr class="wp-header-end">
			<div class="whd-ve" id="whd-ve"></div>
		</div>
		<?php
	}

	/* ─────────────────────────── the wires ─────────────────────────── */

	public static function rest() {
		$can = static function () {
			return current_user_can( 'upload_files' );
		};
		$routes = [
			'clip'          => static fn( WP_REST_Request $r ) => self::answer( self::clip( absint( $r->get_param( 'id' ) ) ) ?: new WP_Error( 'whd_ve_clip', __( 'That file is not there any more.', 'whd' ) ) ),
			'upload/start'  => static fn( WP_REST_Request $r ) => self::answer( self::upload_start( $r->get_json_params() ?: [] ) ),
			'upload/chunk'  => static fn( WP_REST_Request $r ) => self::answer( self::upload_chunk( (string) $r->get_param( 'token' ), (int) $r->get_param( 'index' ), (string) $r->get_body() ) ),
			'upload/finish' => static fn( WP_REST_Request $r ) => self::answer( self::upload_finish( $r->get_json_params() ?: [] ) ),
			'sounds/mine'   => static fn( WP_REST_Request $r ) => self::answer( self::my_audio() ),
			'sounds/search' => static fn( WP_REST_Request $r ) => self::answer( self::search( (string) $r->get_param( 'q' ), (string) $r->get_param( 'kind' ), max( 1, (int) $r->get_param( 'page' ) ) ) ),
			'sounds/import' => static fn( WP_REST_Request $r ) => self::answer( self::import( (string) $r->get_param( 'id' ) ) ),
		];
		foreach ( $routes as $route => $work ) {
			register_rest_route( 'whd/v1', '/video-editor/' . $route, [
				'methods'             => 'POST',
				'callback'            => $work,
				'permission_callback' => $can,
			] );
		}
	}

	private static function answer( $result ) {
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'message' => $result->get_error_message() ], 400 );
		}
		return new WP_REST_Response( $result, 200 );
	}

	/* ─────────────────────────── uploads, in pieces ─────────────────────────── */

	/** Where pieces wait until the last one arrives: in uploads, closed to the web. */
	private static function parts_dir(): string {
		$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'whd-video-parts/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '.htaccess' ) ) {
			file_put_contents( $dir . '.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . 'index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	/** What may be sent, by what the file says it is; checked again against its first bytes at the end. */
	private static function allowed(): array {
		return [
			'video/mp4'       => 'mp4',
			'video/webm'      => 'webm',
			'video/quicktime' => 'mov',
			'image/gif'       => 'gif',
			'audio/mpeg'      => 'mp3',
			'audio/mp4'       => 'm4a',
			'audio/x-m4a'     => 'm4a',
			'audio/wav'       => 'wav',
			'audio/x-wav'     => 'wav',
			'audio/ogg'       => 'ogg',
			'image/jpeg'      => 'jpg',
			'image/png'       => 'png',
			'image/webp'      => 'webp',
		];
	}

	private static function upload_start( array $args ) {
		$type = strtolower( sanitize_text_field( (string) ( $args['type'] ?? '' ) ) );
		$size = (int) ( $args['size'] ?? 0 );
		if ( ! isset( self::allowed()[ $type ] ) ) {
			return new WP_Error( 'whd_ve_type', __( 'That kind of file cannot be added here.', 'whd' ) );
		}
		if ( $size <= 0 || $size > self::MAX_BYTES ) {
			return new WP_Error( 'whd_ve_size', __( 'That file is too large to save from the browser.', 'whd' ) );
		}
		$dir = self::parts_dir();
		// Pieces nobody came back for go after a day.
		foreach ( (array) glob( $dir . '*.part' ) as $old ) {
			if ( is_string( $old ) && filemtime( $old ) < time() - DAY_IN_SECONDS ) {
				wp_delete_file( $old );
				wp_delete_file( substr( $old, 0, -5 ) . '.json' );
			}
		}
		$token = strtolower( wp_generate_password( 24, false, false ) );
		file_put_contents( $dir . $token . '.part', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dir . $token . '.json', wp_json_encode( [ 'user' => get_current_user_id(), 'type' => $type, 'size' => $size, 'next' => 0 ] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return [ 'token' => $token, 'chunk' => 3 * MB_IN_BYTES ];
	}

	/** The record of an upload in progress, if it is this person's. */
	private static function upload_record( string $token ) {
		$token = preg_replace( '/[^a-z0-9]/', '', strtolower( $token ) );
		$dir   = self::parts_dir();
		$info  = '' !== $token && file_exists( $dir . $token . '.json' ) ? json_decode( (string) file_get_contents( $dir . $token . '.json' ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own file
		if ( ! is_array( $info ) || (int) $info['user'] !== get_current_user_id() ) {
			return new WP_Error( 'whd_ve_token', __( 'That upload is not known here any more. Save again.', 'whd' ) );
		}
		$info['token'] = $token;
		$info['part']  = $dir . $token . '.part';
		$info['json']  = $dir . $token . '.json';
		return $info;
	}

	private static function upload_chunk( string $token, int $index, string $bytes ) {
		$info = self::upload_record( $token );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		if ( strlen( $bytes ) > self::CHUNK_BYTES ) {
			return new WP_Error( 'whd_ve_chunk', __( 'A piece of the file was too large.', 'whd' ) );
		}
		// A piece sent twice (a retry after a dropped answer) is not written twice.
		if ( $index < (int) $info['next'] ) {
			return [ 'next' => (int) $info['next'] ];
		}
		if ( $index !== (int) $info['next'] ) {
			return new WP_Error( 'whd_ve_order', __( 'A piece of the file arrived out of order. Save again.', 'whd' ) );
		}
		clearstatcache( true, $info['part'] );
		if ( filesize( $info['part'] ) + strlen( $bytes ) > (int) $info['size'] ) {
			return new WP_Error( 'whd_ve_size', __( 'More arrived than the file said it would be.', 'whd' ) );
		}
		file_put_contents( $info['part'], $bytes, FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$info['next'] = $index + 1;
		file_put_contents( $info['json'], wp_json_encode( array_intersect_key( $info, array_flip( [ 'user', 'type', 'size', 'next' ] ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return [ 'next' => $info['next'] ];
	}

	/** What a file's first bytes say it is. */
	private static function sniff( string $file ): string {
		$head = (string) file_get_contents( $file, false, null, 0, 16 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own file
		if ( strlen( $head ) < 12 ) {
			return '';
		}
		if ( 'ftyp' === substr( $head, 4, 4 ) ) {
			$brand = substr( $head, 8, 4 );
			if ( 'qt  ' === $brand ) {
				return 'video/quicktime';
			}
			return in_array( $brand, [ 'M4A ', 'M4B ' ], true ) ? 'audio/mp4' : 'video/mp4';
		}
		if ( "\x1A\x45\xDF\xA3" === substr( $head, 0, 4 ) ) {
			return 'video/webm';
		}
		if ( 'GIF8' === substr( $head, 0, 4 ) ) {
			return 'image/gif';
		}
		if ( 'ID3' === substr( $head, 0, 3 ) || ( "\xFF" === $head[0] && ( ord( $head[1] ) & 0xE0 ) === 0xE0 ) ) {
			return 'audio/mpeg';
		}
		if ( 'RIFF' === substr( $head, 0, 4 ) && 'WAVE' === substr( $head, 8, 4 ) ) {
			return 'audio/wav';
		}
		if ( 'RIFF' === substr( $head, 0, 4 ) && 'WEBP' === substr( $head, 8, 4 ) ) {
			return 'image/webp';
		}
		if ( 'OggS' === substr( $head, 0, 4 ) ) {
			return 'audio/ogg';
		}
		if ( "\xFF\xD8\xFF" === substr( $head, 0, 3 ) ) {
			return 'image/jpeg';
		}
		if ( "\x89PNG" === substr( $head, 0, 4 ) ) {
			return 'image/png';
		}
		return '';
	}

	/**
	 * The last piece has arrived: check the file is what it says, and add it to the media library.
	 *
	 * @param array $args token, title, kind (edit|gif|boomerang|source), design (JSON), source (attachment),
	 *                    poster (a JPEG data URL), made (from the Video Builder: it shows there too).
	 */
	private static function upload_finish( array $args ) {
		$info = self::upload_record( (string) ( $args['token'] ?? '' ) );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		clearstatcache( true, $info['part'] );
		if ( filesize( $info['part'] ) !== (int) $info['size'] ) {
			return new WP_Error( 'whd_ve_short', __( 'Not all of the file arrived. Save again.', 'whd' ) );
		}
		$mime = self::sniff( $info['part'] );
		$said = 'audio/x-m4a' === $info['type'] ? 'audio/mp4' : ( 'audio/x-wav' === $info['type'] ? 'audio/wav' : $info['type'] );
		// A browser may call an MP4 of sound only "video/mp4", and a QuickTime file "video/mp4"; trust the bytes within the family.
		$family = static fn( $m ) => explode( '/', (string) $m )[0];
		if ( '' === $mime || ( $family( $mime ) !== $family( $said ) && ! ( 'audio/mp4' === $mime && 'video/mp4' === $said ) ) ) {
			wp_delete_file( $info['part'] );
			wp_delete_file( $info['json'] );
			return new WP_Error( 'whd_ve_kind', __( 'What arrived was not the kind of file it said it was.', 'whd' ) );
		}
		$ext = self::allowed()[ $mime ] ?? 'bin';

		$uploads = wp_upload_dir();
		$title   = sanitize_text_field( (string) ( $args['title'] ?? '' ) );
		$title   = '' !== $title ? mb_substr( $title, 0, 140 ) : __( 'Edited video', 'whd' );
		$name    = wp_unique_filename( $uploads['path'], ( sanitize_title( $title ) ?: 'video' ) . '-' . strtolower( wp_generate_password( 4, false, false ) ) . '.' . $ext );
		$dest    = trailingslashit( $uploads['path'] ) . $name;
		if ( ! @rename( $info['part'], $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.rename_rename
			return new WP_Error( 'whd_ve_write', __( 'The file could not be written to the uploads folder.', 'whd' ) );
		}
		wp_delete_file( $info['json'] );
		$stat = @stat( dirname( $dest ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( $stat ) {
			@chmod( $dest, $stat['mode'] & 0000666 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		}

		$id = wp_insert_attachment( [
			'post_mime_type' => $mime,
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
			'guid'           => trailingslashit( $uploads['url'] ) . $name,
		], $dest );
		if ( is_wp_error( $id ) || ! $id ) {
			return is_wp_error( $id ) ? $id : new WP_Error( 'whd_ve_attach', __( 'The file could not be added to the media library.', 'whd' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $dest ) );

		$kind = sanitize_key( (string) ( $args['kind'] ?? 'edit' ) );
		if ( 'source' !== $kind ) {
			$design = (string) ( $args['design'] ?? '' );
			if ( '' !== $design && strlen( $design ) < 2 * MB_IN_BYTES && is_array( json_decode( $design, true ) ) ) {
				update_post_meta( $id, self::DESIGN, wp_slash( $design ) );
			}
			$source = absint( $args['source'] ?? 0 );
			if ( $source ) {
				update_post_meta( $id, self::SOURCE, $source );
			}
			if ( class_exists( 'WHD_AI_Video' ) ) {
				update_post_meta( $id, WHD_AI_Video::KIND, in_array( $kind, [ 'gif', 'boomerang', 'edit' ], true ) ? $kind : 'edit' );
				if ( ! empty( $args['made'] ) ) {
					update_post_meta( $id, WHD_AI_Video::MADE, 1 );
				}
			}
		}
		self::poster( (int) $id, (string) ( $args['poster'] ?? '' ), $title );

		return self::clip( (int) $id ) + [
			'edit'    => self::edit_url( [ (int) $id ] ),
			'library' => (string) get_edit_post_link( $id, 'raw' ),
			'tile'    => class_exists( 'WHD_AI_Video' ) ? WHD_AI_Video::tile( (int) $id ) : null,
		];
	}

	/** A cover frame for a video, so the media library and the screens show a picture instead of a blank. */
	public static function poster( int $id, string $data, string $title ) {
		if ( ! preg_match( '#^data:image/jpeg;base64,#', $data ) || 0 !== strpos( (string) get_post_mime_type( $id ), 'video/' ) ) {
			return;
		}
		$bytes = base64_decode( substr( $data, 23 ), true );
		if ( false === $bytes || strlen( $bytes ) > 4 * MB_IN_BYTES ) {
			return;
		}
		$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a broken file is simply not used
		if ( ! $info || 'image/jpeg' !== $info['mime'] ) {
			return;
		}
		$file = wp_upload_bits( ( sanitize_title( $title ) ?: 'video' ) . '-cover.jpg', null, $bytes );
		if ( ! empty( $file['error'] ) ) {
			return;
		}
		$pid = wp_insert_attachment( [
			'post_mime_type' => 'image/jpeg',
			/* translators: %s: video title */
			'post_title'     => sprintf( __( '%s — cover', 'whd' ), $title ),
			'post_status'    => 'inherit',
			'post_parent'    => $id,
		], $file['file'], $id );
		if ( is_wp_error( $pid ) || ! $pid ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $pid, wp_generate_attachment_metadata( $pid, $file['file'] ) );
		set_post_thumbnail( $id, $pid );
		if ( get_post_meta( $id, class_exists( 'WHD_AI_Video' ) ? WHD_AI_Video::MADE : '_whd_vb', true ) ) {
			update_post_meta( $pid, class_exists( 'WHD_AI_Video' ) ? WHD_AI_Video::MADE : '_whd_vb', 1 );
		}
	}

	/* ─────────────────────────── sounds ─────────────────────────── */

	/** The shop's own audio in the media library — including what was imported from Openverse. */
	private static function my_audio(): array {
		$ids = get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'audio',
			'posts_per_page' => 60,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		] );
		$out = [];
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$meta  = wp_get_attachment_metadata( $id );
			$sound = json_decode( (string) get_post_meta( $id, self::SOUND, true ), true );
			$out[] = [
				'id'      => $id,
				'url'     => (string) wp_get_attachment_url( $id ),
				'title'   => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'seconds' => (float) ( $meta['length'] ?? 0 ),
				'by'      => is_array( $sound ) ? (string) ( $sound['creator'] ?? '' ) : '',
				'licence' => is_array( $sound ) ? strtoupper( (string) ( $sound['license'] ?? '' ) ) : '',
			];
		}
		return $out;
	}

	/**
	 * Search Openverse for audio that anybody may use for anything: CC0 and public-domain marks only. Most of
	 * it is Freesound and Wikimedia. Results are kept for a while, so one shop shares the anonymous allowance.
	 */
	private static function search( string $q, string $kind, int $page ) {
		$q = trim( mb_substr( sanitize_text_field( $q ), 0, 80 ) );
		if ( '' === $q ) {
			return [ 'results' => [], 'more' => false ];
		}
		$kind = in_array( $kind, [ 'music', 'sound_effect' ], true ) ? $kind : '';
		$key  = 'whd_ve_ov_' . md5( strtolower( $q ) . '|' . $kind . '|' . $page );
		$hit  = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		/*
		 * By length rather than Openverse's category: most of what it holds comes from Freesound, which sets no
		 * category, so "sound_effect" and "music" both find nothing. Under 30 seconds is an effect; music runs longer.
		 */
		$url = add_query_arg( array_filter( [
			'q'         => $q,
			'license'   => 'cc0,pdm',
			'length'    => 'sound_effect' === $kind ? 'shortest' : ( 'music' === $kind ? 'short,medium' : '' ),
			'page_size' => 20,
			'page'      => $page,
			'mature'    => 'false',
		] ), self::OPENVERSE );
		$r = wp_remote_get( $url, [ 'timeout' => 20, 'headers' => [ 'Accept' => 'application/json' ], 'user-agent' => 'WHD Video Editor (WordPress)' ] );
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'whd_ve_search', __( 'The free sound search could not be reached. Try again.', 'whd' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		if ( 429 === $code ) {
			return new WP_Error( 'whd_ve_search', __( 'The free sound search is busy — wait a minute and try again.', 'whd' ) );
		}
		$body = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			return new WP_Error( 'whd_ve_search', __( 'The free sound search did not answer properly. Try again.', 'whd' ) );
		}
		$out = [];
		foreach ( (array) ( $body['results'] ?? [] ) as $item ) {
			$licence = strtolower( (string) ( $item['license'] ?? '' ) );
			if ( ! in_array( $licence, [ 'cc0', 'pdm' ], true ) || empty( $item['url'] ) ) {
				continue;
			}
			$out[] = [
				'id'      => sanitize_text_field( (string) $item['id'] ),
				'title'   => mb_substr( wp_strip_all_tags( (string) ( $item['title'] ?? '' ) ), 0, 120 ),
				'by'      => mb_substr( wp_strip_all_tags( (string) ( $item['creator'] ?? '' ) ), 0, 80 ),
				'seconds' => round( (int) ( $item['duration'] ?? 0 ) / 1000, 1 ),
				'preview' => esc_url_raw( (string) $item['url'] ),
				'page'    => esc_url_raw( (string) ( $item['foreign_landing_url'] ?? '' ) ),
				'licence' => strtoupper( $licence ),
				'source'  => sanitize_text_field( (string) ( $item['source'] ?? '' ) ),
			];
		}
		$result = [ 'results' => $out, 'more' => $page < (int) ( $body['page_count'] ?? 0 ), 'page' => $page ];
		set_transient( $key, $result, self::SEARCH_KEEP );
		return $result;
	}

	/**
	 * Bring one Openverse sound into the media library. Its record is read again from Openverse rather than
	 * trusted from the browser, and its licence checked again, before anything is downloaded.
	 */
	private static function import( string $id ) {
		$id = preg_replace( '/[^a-zA-Z0-9-]/', '', $id );
		if ( '' === $id ) {
			return new WP_Error( 'whd_ve_import', __( 'That sound could not be added.', 'whd' ) );
		}
		$have = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => self::SOUND_ID, 'meta_value' => $id, 'fields' => 'ids', 'numberposts' => 1 ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $have ) {
			return self::sound( (int) $have[0] );
		}
		$r = wp_remote_get( self::OPENVERSE . rawurlencode( $id ) . '/', [ 'timeout' => 20, 'headers' => [ 'Accept' => 'application/json' ], 'user-agent' => 'WHD Video Editor (WordPress)' ] );
		$item = is_wp_error( $r ) ? null : json_decode( wp_remote_retrieve_body( $r ), true );
		if ( ! is_array( $item ) || empty( $item['url'] ) ) {
			return new WP_Error( 'whd_ve_import', __( 'That sound could not be found on Openverse any more.', 'whd' ) );
		}
		$licence = strtolower( (string) ( $item['license'] ?? '' ) );
		if ( ! in_array( $licence, [ 'cc0', 'pdm' ], true ) ) {
			return new WP_Error( 'whd_ve_licence', __( 'That sound is not free to use for anything, so it was not added.', 'whd' ) );
		}
		$url = (string) $item['url'];
		if ( 0 !== strpos( $url, 'https://' ) ) {
			return new WP_Error( 'whd_ve_import', __( 'That sound is not on a secure address.', 'whd' ) );
		}
		$tmp = wp_tempnam( 'whd-sound' );
		$got = wp_safe_remote_get( $url, [ 'timeout' => 60, 'stream' => true, 'filename' => $tmp, 'limit_response_size' => self::SOUND_MAX, 'user-agent' => 'WHD Video Editor (WordPress)' ] );
		if ( is_wp_error( $got ) || 200 !== (int) wp_remote_retrieve_response_code( $got ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'whd_ve_import', __( 'The sound could not be downloaded. Try again.', 'whd' ) );
		}
		$mime = self::sniff( $tmp );
		if ( 0 !== strpos( $mime, 'audio/' ) && 'video/mp4' !== $mime ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'whd_ve_import', __( 'What was downloaded is not a sound file.', 'whd' ) );
		}
		$mime = 'video/mp4' === $mime ? 'audio/mp4' : $mime;
		$ext  = self::allowed()[ $mime ] ?? 'mp3';
		$title = mb_substr( wp_strip_all_tags( (string) ( $item['title'] ?? 'Sound' ) ), 0, 100 );
		$file  = [ 'name' => ( sanitize_title( $title ) ?: 'sound' ) . '.' . $ext, 'tmp_name' => $tmp ];
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$creator = mb_substr( wp_strip_all_tags( (string) ( $item['creator'] ?? '' ) ), 0, 80 );
		$aid     = media_handle_sideload( $file, 0, '' !== $creator ? $title . ' — ' . $creator : $title );
		if ( is_wp_error( $aid ) ) {
			wp_delete_file( $tmp );
			return $aid;
		}
		update_post_meta( $aid, self::SOUND_ID, $id );
		update_post_meta( $aid, self::SOUND, wp_slash( wp_json_encode( [
			'source'  => 'openverse',
			'id'      => $id,
			'license' => $licence,
			'creator' => $creator,
			'page'    => esc_url_raw( (string) ( $item['foreign_landing_url'] ?? '' ) ),
			'from'    => sanitize_text_field( (string) ( $item['source'] ?? '' ) ),
		] ) ) );
		return self::sound( (int) $aid );
	}

	private static function sound( int $id ): array {
		$meta = wp_get_attachment_metadata( $id );
		return [
			'id'      => $id,
			'url'     => (string) wp_get_attachment_url( $id ),
			'title'   => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'seconds' => (float) ( $meta['length'] ?? 0 ),
		];
	}
}
