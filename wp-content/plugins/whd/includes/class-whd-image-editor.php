<?php
/**
 * Image Editor: any image in the media library, dressed by hand — on the lines of Canva.
 *
 * Choose the images to edit (from the library, or uploaded there and then), and each one opens in
 * an editor in the browser: text in thirty-odd fonts with style presets, templates, stickers and
 * badges, shapes, filters and adjustments, sizes for every place a picture goes, layers, undo.
 * Built on Fabric.js (MIT, vendored in admin/vendor). Saving writes a new image to the library and
 * leaves the original alone; the layers are kept on it, so it can be opened and edited again
 * rather than started over.
 *
 * Text is put on pictures here, by hand, and never asked of the image model, which garbles it.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Image_Editor {

	const PAGE = 'whd-image-editor';

	/** The layers of an image saved from the editor, as JSON, and the photo it was made from. */
	const DESIGN = '_whd_design';
	const SOURCE = '_whd_design_source';

	/** The largest image the editor will hand back, in bytes of the encoded file. */
	const MAX_BYTES = 20 * MB_IN_BYTES;

	/** Most images opened at once from a link. */
	const START_MAX = 12;

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 13 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'rest' ] );
		// A way in from the media library.
		add_filter( 'media_row_actions', [ __CLASS__, 'row_action' ], 10, 2 );
		add_filter( 'attachment_fields_to_edit', [ __CLASS__, 'attachment_field' ], 10, 2 );
	}

	/* ─────────────────────────── where it lives ─────────────────────────── */

	/** Under AI Writer, after Image Builder. */
	public static function menu() {
		if ( ! class_exists( 'WHD_AI_Writer' ) || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		add_submenu_page(
			WHD_AI_Writer::PAGE_POST,
			__( 'Image Editor', 'whd' ),
			__( 'Image Editor', 'whd' ),
			'upload_files',
			self::PAGE,
			[ __CLASS__, 'screen' ]
		);
	}

	/** The editor, opened on these images. */
	public static function edit_url( array $ids ): string {
		return add_query_arg( [ 'page' => self::PAGE, 'ids' => implode( ',', array_map( 'absint', $ids ) ) ], admin_url( 'admin.php' ) );
	}

	public static function row_action( $actions, $post ) {
		if ( $post && wp_attachment_is_image( $post->ID ) && current_user_can( 'upload_files' ) ) {
			$actions['whd_image_editor'] = '<a href="' . esc_url( self::edit_url( [ (int) $post->ID ] ) ) . '">' . esc_html__( 'Edit in Image Editor', 'whd' ) . '</a>';
		}
		return $actions;
	}

	/** The same link in the attachment details, which is what the media grid shows. */
	public static function attachment_field( $fields, $post ) {
		if ( $post && wp_attachment_is_image( $post->ID ) && current_user_can( 'upload_files' ) ) {
			$fields['whd_image_editor'] = [
				'label' => __( 'Image Editor', 'whd' ),
				'input' => 'html',
				'html'  => '<a class="button button-small" href="' . esc_url( self::edit_url( [ (int) $post->ID ] ) ) . '">' . esc_html__( 'Add text, stickers or effects', 'whd' ) . '</a>',
			];
		}
		return $fields;
	}

	/* ─────────────────────────── the screen ─────────────────────────── */

	/** The typefaces the editor offers, as Google Fonts families with the weights it uses. */
	public static function fonts(): array {
		return (array) apply_filters( 'whd_image_editor_fonts', [
			'Cormorant Garamond' => 'ital,wght@0,400;0,600;1,400;1,600',
			'Playfair Display'   => 'ital,wght@0,400;0,700;0,900;1,400',
			'DM Serif Display'   => 'ital@0;1',
			'Abril Fatface'      => '',
			'Libre Baskerville'  => 'ital,wght@0,400;0,700;1,400',
			'Lora'               => 'ital,wght@0,400;0,600;1,400',
			'Bodoni Moda'        => 'ital,wght@0,400;0,700;1,400',
			'Cinzel'             => 'wght@400;700',
			'Montserrat'         => 'ital,wght@0,300;0,500;0,700;0,900;1,400',
			'Poppins'            => 'wght@300;500;700;900',
			'Manrope'            => 'wght@400;600;800',
			'Inter'              => 'wght@400;600;800',
			'Raleway'            => 'wght@300;600;800',
			'Josefin Sans'       => 'wght@300;600',
			'Space Grotesk'      => 'wght@400;700',
			'Oswald'             => 'wght@400;600',
			'Bebas Neue'         => '',
			'Anton'              => '',
			'Archivo Black'      => '',
			'League Spartan'     => 'wght@400;800',
			'Righteous'          => '',
			'Shrikhand'          => '',
			'Great Vibes'        => '',
			'Dancing Script'     => 'wght@400;700',
			'Pacifico'           => '',
			'Sacramento'         => '',
			'Allura'             => '',
			'Parisienne'         => '',
			'Satisfy'            => '',
			'Caveat'             => 'wght@400;700',
			'Amatic SC'          => 'wght@400;700',
			'Lobster'            => '',
			'Permanent Marker'   => '',
			'Courier Prime'      => 'wght@400;700',
		] );
	}

	private static function fonts_url(): string {
		$families = [];
		foreach ( self::fonts() as $name => $axes ) {
			$families[] = 'family=' . str_replace( ' ', '+', $name ) . ( '' !== $axes ? ':' . $axes : '' );
		}
		return 'https://fonts.googleapis.com/css2?' . implode( '&', $families ) . '&display=swap';
	}

	/** The shop's own marks, for the editor's "Your logo" — the full-size masters, not the 400px copies. */
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
		return (array) apply_filters( 'whd_image_editor_logos', $out );
	}

	public static function assets() {
		// By the page, never the hook name: the hook carries where the screen sits in the menu.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which screen this is
		if ( ! isset( $_GET['page'] ) || self::PAGE !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		wp_enqueue_media();
		// No version on the fonts: WordPress would rebuild the address and keep only the last family.
		wp_enqueue_style( 'whd-ie-fonts', self::fonts_url(), [], null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_style( 'whd-image-editor', WHD_URL . 'admin/image-editor.css', [], WHD_VERSION );
		wp_enqueue_script( 'whd-ai-kit', WHD_URL . 'admin/ai-kit.js', [], WHD_VERSION, true );
		wp_enqueue_script( 'whd-fabric', WHD_URL . 'admin/vendor/fabric.min.js', [], '5.5.2', true );
		wp_enqueue_script( 'whd-image-presets', WHD_URL . 'admin/image-presets.js', [], WHD_VERSION, true );
		wp_enqueue_script( 'whd-image-editor', WHD_URL . 'admin/image-editor.js', [ 'whd-ai-kit', 'whd-fabric', 'whd-image-presets', 'media-editor' ], WHD_VERSION, true );
		wp_enqueue_script( 'whd-image-editor-screen', WHD_URL . 'admin/image-editor-screen.js', [ 'whd-image-editor' ], WHD_VERSION, true );

		// Opened on images from elsewhere — the media library, Image Builder: ?ids=1,2,3 (or ?edit=1).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks what to show
		$given = isset( $_GET['ids'] ) ? sanitize_text_field( wp_unslash( $_GET['ids'] ) ) : ( isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : '' );
		$start = array_values( array_filter( array_map( [ __CLASS__, 'photo' ], array_slice( wp_parse_id_list( (string) $given ), 0, self::START_MAX ) ) ) );

		wp_localize_script( 'whd-image-editor-screen', 'WHD_IE', [
			'rest'     => [
				'root'  => esc_url_raw( rest_url( 'whd/v1/image-editor/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			],
			'start'    => $start,
			'fonts'    => array_keys( self::fonts() ),
			'logos'    => self::logos(),
			'brand'    => [
				'name'   => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
				'site'   => preg_replace( '#^https?://(www\.)?#', '', untrailingslashit( home_url() ) ),
				'colors' => [ '#b98b7e', '#3a2b26', '#f7f2ed', '#efe6de', '#ffffff', '#1d1d1f', '#9c6f63', '#d9c9c0' ],
			],
			'currency' => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '',
			'i18n'     => self::strings(),
		] );
	}

	/** One library image as the editor wants it — and its layers, when the editor saved it. */
	public static function photo( int $id ): ?array {
		if ( ! $id || ! wp_attachment_is_image( $id ) || ! current_user_can( 'upload_files' ) ) {
			return null;
		}
		$design = (string) get_post_meta( $id, self::DESIGN, true );
		$source = (int) get_post_meta( $id, self::SOURCE, true );
		return [
			'id'     => $id,
			'thumb'  => (string) wp_get_attachment_image_url( $id, 'thumbnail' ),
			'view'   => (string) wp_get_attachment_image_url( $id, 'medium' ),
			'large'  => (string) wp_get_attachment_image_url( $id, 'large' ),
			'full'   => (string) wp_get_attachment_url( $id ),
			// What the editor works on: up to 2048 on the long side, enough for any format it offers.
			'work'   => (string) ( wp_get_attachment_image_url( $id, '2048x2048' ) ?: wp_get_attachment_url( $id ) ),
			'title'  => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'alt'    => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'design' => '' !== $design ? $design : null,
			'source' => $source && wp_attachment_is_image( $source ) ? (string) wp_get_attachment_url( $source ) : '',
		];
	}

	private static function strings(): array {
		return [
			'openPhoto'     => __( 'Choose images', 'whd' ),
			'openPhotoD'    => __( 'From your media library, or upload new ones', 'whd' ),
			'blank'         => __( 'Blank design', 'whd' ),
			'editorEmpty'   => __( 'Choose the images you want to edit — from your media library, or upload new ones — or start from a blank design.', 'whd' ),
			'tabTemplates'  => __( 'Templates', 'whd' ),
			'tabText'       => __( 'Text', 'whd' ),
			'tabStickers'   => __( 'Stickers', 'whd' ),
			'tabShapes'     => __( 'Shapes', 'whd' ),
			'tabEffects'    => __( 'Effects', 'whd' ),
			'tabPhotos'     => __( 'Photos', 'whd' ),
			'tabSize'       => __( 'Size', 'whd' ),
			'addHeading'    => __( 'Add a heading', 'whd' ),
			'addSub'        => __( 'Add a subheading', 'whd' ),
			'addBody'       => __( 'Add a little body text', 'whd' ),
			'combos'        => __( 'Font combinations', 'whd' ),
			'badges'        => __( 'Badges', 'whd' ),
			'decor'         => __( 'Decorations', 'whd' ),
			'emoji'         => __( 'Emoji', 'whd' ),
			'filters'       => __( 'Filters', 'whd' ),
			'adjust'        => __( 'Adjust', 'whd' ),
			'brightness'    => __( 'Brightness', 'whd' ),
			'contrast'      => __( 'Contrast', 'whd' ),
			'saturation'    => __( 'Saturation', 'whd' ),
			'vibrance'      => __( 'Vibrance', 'whd' ),
			'warmth'        => __( 'Warmth', 'whd' ),
			'hue'           => __( 'Hue', 'whd' ),
			'blur'          => __( 'Blur', 'whd' ),
			'grain'         => __( 'Grain', 'whd' ),
			'vignette'      => __( 'Vignette', 'whd' ),
			'resetFx'       => __( 'Reset', 'whd' ),
			'fxOn'          => __( 'Applies to the selected photo, or the background photo when nothing is selected.', 'whd' ),
			'addPhoto'      => __( 'Add a photo on top', 'whd' ),
			'replacePhoto'  => __( 'Replace the background photo', 'whd' ),
			'yourLogo'      => __( 'Your logo', 'whd' ),
			'formats'       => __( 'Formats', 'whd' ),
			'custom'        => __( 'Custom', 'whd' ),
			'width'         => __( 'Width', 'whd' ),
			'height'        => __( 'Height', 'whd' ),
			'apply'         => __( 'Apply', 'whd' ),
			'fill'          => __( 'Fill the frame', 'whd' ),
			'fit'           => __( 'Fit inside', 'whd' ),
			'background'    => __( 'Background colour', 'whd' ),
			'undo'          => __( 'Undo', 'whd' ),
			'redoE'         => __( 'Redo', 'whd' ),
			'zoomIn'        => __( 'Zoom in', 'whd' ),
			'zoomOut'       => __( 'Zoom out', 'whd' ),
			'zoomFit'       => __( 'Fit to screen', 'whd' ),
			'save'          => __( 'Save to media library', 'whd' ),
			'saving'        => __( 'Saving…', 'whd' ),
			/* translators: %s: link to the saved image */
			'saved'         => __( 'Saved — %s', 'whd' ),
			'openSaved'     => __( 'open it in the media library', 'whd' ),
			'download'      => __( 'Download', 'whd' ),
			'format'        => __( 'File type', 'whd' ),
			'props'         => __( 'Selected', 'whd' ),
			'nothingSel'    => __( 'Click anything on the picture to change it. Double-click text to type.', 'whd' ),
			'layers'        => __( 'Layers', 'whd' ),
			'font'          => __( 'Font', 'whd' ),
			'size'          => __( 'Size', 'whd' ),
			'colour'        => __( 'Colour', 'whd' ),
			'fillColour'    => __( 'Fill', 'whd' ),
			'outline'       => __( 'Outline', 'whd' ),
			'outlineW'      => __( 'Outline width', 'whd' ),
			'spacing'       => __( 'Letter spacing', 'whd' ),
			'lineH'         => __( 'Line height', 'whd' ),
			'opacity'       => __( 'Opacity', 'whd' ),
			'shadow'        => __( 'Shadow', 'whd' ),
			'highlight'     => __( 'Highlight', 'whd' ),
			'radius'        => __( 'Corner radius', 'whd' ),
			'label'         => __( 'Label', 'whd' ),
			'bold'          => __( 'Bold', 'whd' ),
			'italic'        => __( 'Italic', 'whd' ),
			'underline'     => __( 'Underline', 'whd' ),
			'caps'          => __( 'Capitals', 'whd' ),
			'alignL'        => __( 'Align left', 'whd' ),
			'alignC'        => __( 'Centre', 'whd' ),
			'alignR'        => __( 'Align right', 'whd' ),
			'forward'       => __( 'Bring forward', 'whd' ),
			'backward'      => __( 'Send backward', 'whd' ),
			'duplicate'     => __( 'Duplicate', 'whd' ),
			'delete'        => __( 'Delete', 'whd' ),
			'lock'          => __( 'Lock', 'whd' ),
			'unlock'        => __( 'Unlock', 'whd' ),
			'flipH'         => __( 'Flip', 'whd' ),
			'centreH'       => __( 'Centre across', 'whd' ),
			'centreV'       => __( 'Centre down', 'whd' ),
			'show'          => __( 'Show', 'whd' ),
			'hide'          => __( 'Hide', 'whd' ),
			'basePhoto'     => __( 'Photo', 'whd' ),
			'unsaved'       => __( 'You have changes that are not saved. Leave anyway?', 'whd' ),
			'switchUnsaved' => __( 'not saved', 'whd' ),
			'savedTag'      => __( 'saved', 'whd' ),
			'use'           => __( 'Edit these', 'whd' ),
			'error'         => __( 'Something went wrong', 'whd' ),
			'tooBig'        => __( 'That image is too large to save from the browser — choose a smaller size or JPG.', 'whd' ),
		];
	}

	public static function screen() {
		?>
		<div class="wrap whd-wrap whd-ied">
			<header class="whd-ied__head">
				<p class="whd-ied__eyebrow"><?php esc_html_e( 'AI Writer', 'whd' ); ?></p>
				<h1 class="whd-title"><?php esc_html_e( 'Image Editor', 'whd' ); ?></h1>
				<p class="whd-ied__lead"><?php esc_html_e( 'Choose the images you want to edit and dress them up — text, stickers, badges, templates, filters and sizes for every place a picture goes. Saving adds a new image to your media library; the original is never changed.', 'whd' ); ?></p>
			</header>
			<hr class="wp-header-end">
			<div class="whd-ie" id="whd-ie"></div>
		</div>
		<?php
	}

	/* ─────────────────────────── the wires ─────────────────────────── */

	public static function rest() {
		$can = static function () {
			return current_user_can( 'upload_files' );
		};
		register_rest_route( 'whd/v1', '/image-editor/save', [
			'methods'             => 'POST',
			'callback'            => static fn( WP_REST_Request $r ) => self::answer( self::save( $r->get_json_params() ?: [] ) ),
			'permission_callback' => $can,
		] );
		register_rest_route( 'whd/v1', '/image-editor/photo', [
			'methods'             => 'POST',
			'callback'            => static function ( WP_REST_Request $r ) {
				$photo = self::photo( absint( $r->get_param( 'id' ) ) );
				return $photo ? self::answer( $photo ) : self::answer( new WP_Error( 'whd_ie_photo', __( 'That image is not there any more.', 'whd' ) ) );
			},
			'permission_callback' => $can,
		] );
	}

	/**
	 * Save what the editor made as a new image in the library, with its layers kept on it.
	 *
	 * The picture arrives as a data URL from the canvas. It is checked to be an image of the kind it
	 * says it is before anything is written, and the original it was made from is left untouched.
	 *
	 * @param array $args data (data URL), name, source (attachment id), alt, design (JSON).
	 * @return array|WP_Error
	 */
	public static function save( array $args ) {
		$data = (string) ( $args['data'] ?? '' );
		if ( ! preg_match( '#^data:image/(png|jpeg|webp);base64,#', $data, $m ) ) {
			return new WP_Error( 'whd_ie_data', __( 'The editor did not send an image.', 'whd' ) );
		}
		$bytes = base64_decode( substr( $data, strlen( $m[0] ) ), true );
		if ( false === $bytes || strlen( $bytes ) > self::MAX_BYTES ) {
			return new WP_Error( 'whd_ie_size', __( 'That image is too large to save from the browser — choose a smaller size or JPG.', 'whd' ) );
		}
		$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a broken file is answered below
		$mime = 'image/' . $m[1];
		if ( ! $info || $info['mime'] !== $mime ) {
			return new WP_Error( 'whd_ie_data', __( 'What arrived was not the image it said it was.', 'whd' ) );
		}

		$source = absint( $args['source'] ?? 0 );
		$source = $source && wp_attachment_is_image( $source ) ? $source : 0;
		$name   = sanitize_text_field( (string) ( $args['name'] ?? '' ) );
		if ( '' === $name ) {
			$name = $source ? html_entity_decode( get_the_title( $source ), ENT_QUOTES, 'UTF-8' ) : __( 'Design', 'whd' );
		}
		$ext  = 'jpeg' === $m[1] ? 'jpg' : $m[1];
		$file = wp_upload_bits( sanitize_file_name( ( sanitize_title( $name ) ?: 'design' ) . '-edited-' . wp_generate_password( 4, false, false ) . '.' . $ext ), null, $bytes );
		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'whd_ie_write', $file['error'] );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id = wp_insert_attachment( [
			'post_mime_type' => $mime,
			/* translators: %s: name of the picture it was made from */
			'post_title'     => sprintf( __( '%s — edited', 'whd' ), $name ),
			'post_status'    => 'inherit',
		], $file['file'] );
		if ( is_wp_error( $id ) || ! $id ) {
			return is_wp_error( $id ) ? $id : new WP_Error( 'whd_ie_attach', __( 'The image could not be added to the media library.', 'whd' ) );
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file['file'] ) );

		$alt = sanitize_text_field( (string) ( $args['alt'] ?? '' ) );
		if ( '' === $alt && $source ) {
			$alt = (string) get_post_meta( $source, '_wp_attachment_image_alt', true );
		}
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}

		$design = (string) ( $args['design'] ?? '' );
		if ( '' !== $design && strlen( $design ) < 3 * MB_IN_BYTES && is_array( json_decode( $design, true ) ) ) {
			update_post_meta( $id, self::DESIGN, wp_slash( $design ) );
		}
		if ( $source ) {
			update_post_meta( $id, self::SOURCE, $source );
		}

		return [
			'id'    => (int) $id,
			'thumb' => (string) wp_get_attachment_image_url( $id, 'thumbnail' ),
			'url'   => (string) wp_get_attachment_url( $id ),
			'edit'  => (string) get_edit_post_link( $id, 'raw' ),
			'again' => self::edit_url( [ (int) $id ] ),
		];
	}

	private static function answer( $result ) {
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'message' => $result->get_error_message() ], 400 );
		}
		return new WP_REST_Response( $result, 200 );
	}
}
