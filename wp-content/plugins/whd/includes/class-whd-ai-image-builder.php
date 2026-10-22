<?php
/**
 * Image Builder: product photographs in, finished pictures out — drawn by the AI, then dressed by
 * hand.
 *
 * Two steps on one screen, under AI Writer:
 *
 *   1 Generate         The product's photographs, how it is shown (on a model, a ghost mannequin, a
 *                      flat lay or on its own), the background in the shop's own words, anything
 *                      else to ask, the format and how many. The pictures are made the way the
 *                      product screen makes them, through WHD_AI_Studio's own steps: the photographs
 *                      read edge to edge, the details cut out at full size and counted again, every
 *                      picture drawn from the photographs and the close-ups, then checked against
 *                      them and drawn once more with the mistakes named when it got the piece wrong.
 *                      There is simply no product: the steps run with post 0, and the pictures land
 *                      in the media library unattached.
 *
 *   2 Post production  An editor in the browser for the pictures that were kept — or for any photo
 *                      in the library, or a new one: text in thirty-odd fonts with style presets,
 *                      templates, stickers and badges, shapes, filters and adjustments, sizes for
 *                      every place a picture goes, layers, undo. Built on Fabric.js (MIT, vendored
 *                      in admin/vendor). Saving writes a new image to the library and leaves the
 *                      original alone; the layers are kept on it, so it can be opened and edited
 *                      again rather than started over.
 *
 * Text is put on pictures here, by hand, and never asked of the image model, which garbles it.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_AI_Image_Builder {

	const PAGE = 'whd-ai-image-builder';

	/** The layers of an image saved from the editor, as JSON, and the photo it was made from. */
	const DESIGN = '_whd_design';
	const SOURCE = '_whd_design_source';

	/** Most pictures one run makes. */
	const MAX = 8;

	/** The largest image the editor will hand back, in bytes of the encoded file. */
	const MAX_BYTES = 20 * MB_IN_BYTES;

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 12 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'rest' ] );
		// A way in from the media library, for a photo that needs no drawing at all.
		add_filter( 'media_row_actions', [ __CLASS__, 'row_action' ], 10, 2 );
		add_filter( 'attachment_fields_to_edit', [ __CLASS__, 'attachment_field' ], 10, 2 );
	}

	/* ─────────────────────────── where it lives ─────────────────────────── */

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

	public static function edit_url( int $id ): string {
		return add_query_arg( [ 'page' => self::PAGE, 'edit' => $id ], admin_url( 'admin.php' ) );
	}

	public static function row_action( $actions, $post ) {
		if ( $post && wp_attachment_is_image( $post->ID ) && current_user_can( 'upload_files' ) ) {
			$actions['whd_image_builder'] = '<a href="' . esc_url( self::edit_url( (int) $post->ID ) ) . '">' . esc_html__( 'Edit in Image Builder', 'whd' ) . '</a>';
		}
		return $actions;
	}

	/** The same link in the attachment details, which is what the media grid shows. */
	public static function attachment_field( $fields, $post ) {
		if ( $post && wp_attachment_is_image( $post->ID ) && current_user_can( 'upload_files' ) ) {
			$fields['whd_image_builder'] = [
				'label' => __( 'Image Builder', 'whd' ),
				'input' => 'html',
				'html'  => '<a class="button button-small" href="' . esc_url( self::edit_url( (int) $post->ID ) ) . '">' . esc_html__( 'Add text, stickers or effects', 'whd' ) . '</a>',
			];
		}
		return $fields;
	}

	/* ─────────────────────────── the screen ─────────────────────────── */

	/** The typefaces the editor offers, as Google Fonts families with the weights it uses. */
	public static function fonts(): array {
		return (array) apply_filters( 'whd_image_builder_fonts', [
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
		return (array) apply_filters( 'whd_image_builder_logos', $out );
	}

	public static function assets() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which screen this is
		if ( ! isset( $_GET['page'] ) || self::PAGE !== sanitize_key( wp_unslash( $_GET['page'] ) ) || ! class_exists( 'WHD_AI_Studio' ) ) {
			return;
		}
		wp_enqueue_media();
		// No version on the fonts: WordPress would rebuild the address and keep only the last family.
		wp_enqueue_style( 'whd-ib-fonts', self::fonts_url(), [], null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_style( 'whd-ai-studio', WHD_URL . 'admin/ai-studio.css', [], WHD_VERSION );
		wp_enqueue_style( 'whd-image-builder', WHD_URL . 'admin/image-builder.css', [ 'whd-ai-studio' ], WHD_VERSION );
		wp_enqueue_script( 'whd-ai-kit', WHD_URL . 'admin/ai-kit.js', [], WHD_VERSION, true );
		wp_enqueue_script( 'whd-fabric', WHD_URL . 'admin/vendor/fabric.min.js', [], '5.5.2', true );
		wp_enqueue_script( 'whd-image-presets', WHD_URL . 'admin/image-presets.js', [], WHD_VERSION, true );
		wp_enqueue_script( 'whd-image-editor', WHD_URL . 'admin/image-editor.js', [ 'whd-ai-kit', 'whd-fabric', 'whd-image-presets', 'media-editor' ], WHD_VERSION, true );
		wp_enqueue_script( 'whd-image-builder', WHD_URL . 'admin/image-builder.js', [ 'whd-ai-kit', 'whd-image-editor', 'media-editor' ], WHD_VERSION, true );

		// Opened from the media library on one photo: the editor starts on it.
		$start = isset( $_GET['edit'] ) ? self::photo( absint( $_GET['edit'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		wp_localize_script( 'whd-image-builder', 'WHD_IB', [
			'rest'     => [
				'root'  => esc_url_raw( rest_url( 'whd/v1/ai/builder/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'jobs'  => esc_url_raw( rest_url( 'whd/v1/ai/studio/job' ) ),
				'last'  => esc_url_raw( rest_url( 'whd/v1/ai/studio/last' ) ),
			],
			'ready'    => WHD_AI::ready(),
			'draw'     => class_exists( 'WHD_AI_Images' ) && WHD_AI_Images::ready(),
			'max'      => self::MAX,
			'lookMax'  => WHD_AI_Studio::LOOK_MAX,
			'start'    => $start,
			'fonts'    => array_keys( self::fonts() ),
			'logos'    => self::logos(),
			'brand'    => [
				'name'   => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
				'site'   => preg_replace( '#^https?://(www\.)?#', '', untrailingslashit( home_url() ) ),
				'colors' => [ '#b98b7e', '#3a2b26', '#f7f2ed', '#efe6de', '#ffffff', '#1d1d1f', '#9c6f63', '#d9c9c0' ],
			],
			'currency' => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '',
			'i18n'     => array_merge( WHD_AI_Studio::strings(), self::strings() ),
		] );
	}

	/** One library image as the editor wants it — and its layers, when the editor saved it. */
	private static function photo( int $id ): ?array {
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
			'stepGen'       => __( 'Generate', 'whd' ),
			'stepEdit'      => __( 'Post production', 'whd' ),
			'modeModel'     => __( 'On a model', 'whd' ),
			'modeModelD'    => __( 'Worn by a model, in a setting', 'whd' ),
			'modeGhost'     => __( 'Ghost mannequin', 'whd' ),
			'modeGhostD'    => __( 'As if worn, nobody in it', 'whd' ),
			'modeFlat'      => __( 'Flat lay', 'whd' ),
			'modeFlatD'     => __( 'Laid out, seen from above', 'whd' ),
			'modeStill'     => __( 'On its own', 'whd' ),
			'modeStillD'    => __( 'The piece in a setting, no person', 'whd' ),
			'matchPhoto'    => __( 'Like my photo', 'whd' ),
			'formatSquare'  => __( 'Square', 'whd' ),
			'formatPortrait' => __( 'Portrait', 'whd' ),
			'formatStory'   => __( 'Story', 'whd' ),
			'formatWide'    => __( 'Wide', 'whd' ),
			/* translators: %d: picture number */
			'imageN'        => __( 'Image %d', 'whd' ),
			'instructions'  => __( 'Instructions for this image (optional)', 'whd' ),
			'keep'          => __( 'Keep', 'whd' ),
			'kept'          => __( 'Kept', 'whd' ),
			'redo'          => __( 'Draw again', 'whd' ),
			'editThis'      => __( 'Edit', 'whd' ),
			'view'          => __( 'View larger', 'whd' ),
			/* translators: %d: number of images */
			'editKept'      => __( 'Edit the %d kept →', 'whd' ),
			'editNone'      => __( 'Keep at least one to edit it', 'whd' ),
			/* translators: %d: number of images */
			'dropRest'      => __( 'Delete the %d not kept', 'whd' ),
			'dropped'       => __( 'Deleted from the media library.', 'whd' ),
			'resultsHead'   => __( 'Your new images', 'whd' ),
			'resultsNote'   => __( 'Every image is in the media library already. Tick the ones to keep, delete the rest, or take the kept ones on to post production.', 'whd' ),
			'needPhotos'    => __( 'Choose at least one photograph of the product first.', 'whd' ),
			'needDraw'      => __( 'Drawing images needs a Google AI key. Add one in WHD → AI content.', 'whd' ),
			/* translators: 1: image number, 2: how many */
			'stepImage'     => __( 'Image %1$d of %2$d', 'whd' ),
			'hintFlat'      => __( 'Laid flat and seen from above, drawn from your photos — then checked against them.', 'whd' ),
			'hintStill'     => __( 'On its own in a setting, drawn from your photos — then checked against them.', 'whd' ),
			'genDone'       => __( 'Your images are ready below — keep the ones you like.', 'whd' ),
			'variety'       => [
				'model'     => [ 'full length, walking, relaxed', 'three-quarter view, standing, hand in a pocket', 'waist up, close, looking away', 'seated, legs crossed', 'back view, looking over her shoulder', 'close-up of the details, worn', 'full length, side view', 'leaning against a wall, at ease' ],
				'mannequin' => [ 'front view', 'back view', 'three-quarter view from the left', 'side view', 'close-up of the fastenings and trims', 'front view, open', 'three-quarter view from the right', 'close-up of the collar or neckline' ],
				'flatlay'   => [ 'the whole piece, centred', 'folded neatly with a few accessories', 'sleeves arranged, a little movement', 'close-up of the texture', 'with a pair of shoes and a bag', 'on linen, soft morning light', 'the whole piece from slightly further away', 'detail of the label area and trims' ],
				'still'     => [ 'hanging on a wooden hanger against a wall', 'draped over a chair', 'on a shelf in a calm room', 'folded on a bed in morning light', 'hanging on a rail with soft shadows', 'laid on a bench by a window', 'on a hook by a door', 'resting on a stool, close' ],
			],
			// Post production.
			'openPhoto'     => __( 'Open a photo', 'whd' ),
			'openPhotoD'    => __( 'From your media library, or upload one', 'whd' ),
			'blank'         => __( 'Blank design', 'whd' ),
			'editorEmpty'   => __( 'Open a photo to start — one you generated in step 1, or any photo in your media library.', 'whd' ),
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
			'tooBig'        => __( 'That image is too large to save from the browser — choose a smaller size or JPG.', 'whd' ),
		];
	}

	public static function screen() {
		$draw = class_exists( 'WHD_AI_Images' ) && WHD_AI_Images::ready();
		?>
		<div class="wrap whd-wrap whd-ib" id="whd-ib">
			<h1 class="whd-title"><?php esc_html_e( 'Image Builder', 'whd' ); ?></h1>
			<p class="whd-intro whd-ib__lead"><?php esc_html_e( 'Turn your product photos into new images — on a model, on a ghost mannequin, laid flat or on their own, against the background you describe — then dress them up with text, stickers and effects. Or open any photo and go straight to post production.', 'whd' ); ?></p>

			<nav class="whd-ib__steps" role="tablist" aria-label="<?php esc_attr_e( 'Steps', 'whd' ); ?>">
				<button type="button" class="whd-ib__stepbtn is-on" role="tab" aria-selected="true" aria-controls="whd-ib-gen" data-go="gen"><span class="whd-ib__stepn">1</span><span><?php esc_html_e( 'Generate', 'whd' ); ?></span></button>
				<span class="whd-ib__stepline" aria-hidden="true"></span>
				<button type="button" class="whd-ib__stepbtn" role="tab" aria-selected="false" aria-controls="whd-ib-edit" data-go="edit"><span class="whd-ib__stepn">2</span><span><?php esc_html_e( 'Post production', 'whd' ); ?></span></button>
			</nav>

			<section class="whd-ib__panel whd-studio" id="whd-ib-gen" role="tabpanel">
				<?php if ( ! $draw ) : ?>
					<div class="whd-note whd-note--warn">
						<?php esc_html_e( 'Drawing images needs a Google AI key — the other providers are text only. Post production works without one.', 'whd' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=whd-ai' ) ); ?>"><?php esc_html_e( 'Add one in WHD → AI content.', 'whd' ); ?></a>
					</div>
				<?php endif; ?>

				<div class="whd-ib__cols">
					<div class="whd-ib__col">
						<section class="whd-studio__step">
							<h3 class="whd-studio__h"><span class="whd-studio__n" aria-hidden="true">1</span><?php esc_html_e( 'Photos of the product', 'whd' ); ?></h3>
							<div class="whd-studio__photos" id="whd-ib-photos"></div>
							<p class="description" id="whd-ib-photos-note"></p>
						</section>

						<section class="whd-studio__step">
							<h3 class="whd-studio__h"><span class="whd-studio__n" aria-hidden="true">2</span><?php esc_html_e( 'How it is shown', 'whd' ); ?></h3>
							<div class="whd-ib__modes" id="whd-ib-modes" role="radiogroup" aria-label="<?php esc_attr_e( 'How it is shown', 'whd' ); ?>"></div>
						</section>
					</div>

					<div class="whd-ib__col">
						<section class="whd-studio__step">
							<h3 class="whd-studio__h"><span class="whd-studio__n" aria-hidden="true">3</span><?php esc_html_e( 'The scene', 'whd' ); ?></h3>
							<label class="whd-studio__label" for="whd-ib-bg"><?php esc_html_e( 'Background', 'whd' ); ?></label>
							<textarea id="whd-ib-bg" rows="2" class="whd-studio__notes" placeholder="<?php esc_attr_e( 'e.g. a quiet Seoul side street in autumn, warm low sun, blurred café windows behind her', 'whd' ); ?>"></textarea>
							<div class="whd-ib__chips" id="whd-ib-bg-chips"></div>

							<div class="whd-studio__field">
								<label class="whd-studio__label" for="whd-ib-asks"><?php esc_html_e( 'Anything else?', 'whd' ); ?></label>
								<textarea id="whd-ib-asks" rows="2" class="whd-studio__notes" placeholder="<?php esc_attr_e( 'e.g. styled with straight-leg jeans and white sneakers, relaxed and candid, soft natural light', 'whd' ); ?>"></textarea>
								<div class="whd-ib__chips" id="whd-ib-ask-chips"></div>
								<p class="description"><?php esc_html_e( 'The pose, the mood, what it is styled with, the light, how close. What you write here wins over everything else about the picture — but never over the product itself, which is always drawn as your photos show it.', 'whd' ); ?></p>
							</div>
						</section>

						<section class="whd-studio__step">
							<h3 class="whd-studio__h"><span class="whd-studio__n" aria-hidden="true">4</span><?php esc_html_e( 'Format and how many', 'whd' ); ?></h3>
							<p class="whd-studio__label" id="whd-ib-format-label"><?php esc_html_e( 'Format', 'whd' ); ?></p>
							<div class="whd-studio__counts" id="whd-ib-format" role="radiogroup" aria-labelledby="whd-ib-format-label"></div>
							<div class="whd-studio__field">
								<p class="whd-studio__label" id="whd-ib-count-label"><?php esc_html_e( 'How many images?', 'whd' ); ?></p>
								<div class="whd-studio__counts" id="whd-ib-count" role="radiogroup" aria-labelledby="whd-ib-count-label"></div>
							</div>
						</section>
					</div>
				</div>

				<section class="whd-studio__step">
					<h3 class="whd-studio__h"><span class="whd-studio__n" aria-hidden="true">5</span><?php esc_html_e( 'Each image', 'whd' ); ?> <span class="whd-ib__opt"><?php esc_html_e( 'optional', 'whd' ); ?></span></h3>
					<p class="description"><?php esc_html_e( 'Left blank, each one gets a different pose or angle so the set does not repeat itself. Change the way any one is shown, or say exactly what it should be.', 'whd' ); ?></p>
					<div class="whd-studio__shots whd-ib__shots" id="whd-ib-shots"></div>
				</section>

				<div class="whd-studio__go">
					<button type="button" class="button button-primary button-large" id="whd-ib-go"<?php disabled( ! $draw ); ?>><?php esc_html_e( 'Generate images', 'whd' ); ?></button>
					<span class="whd-studio__status" id="whd-ib-status" role="status" aria-live="polite"></span>
				</div>

				<div class="whd-ib__results" id="whd-ib-results" hidden></div>
			</section>

			<section class="whd-ib__panel whd-ib__panel--edit" id="whd-ib-edit" role="tabpanel" hidden>
				<div class="whd-ie" id="whd-ie"></div>
			</section>
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
			'look'    => static fn( WP_REST_Request $r ) => WHD_AI_Studio::look( 0, (array) $r->get_param( 'images' ), self::notes( $r->get_param( 'commands' ) ) ),
			'inspect' => static fn( WP_REST_Request $r ) => WHD_AI_Studio::inspect( 0, (array) $r->get_param( 'facts' ), self::notes( $r->get_param( 'commands' ) ) ),
			'draw'    => static fn( WP_REST_Request $r ) => WHD_AI_Studio::draw( 0, $r->get_json_params() ?: [] ),
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
		register_rest_route( 'whd/v1', '/ai/builder/save', [
			'methods'             => 'POST',
			'callback'            => static fn( WP_REST_Request $r ) => self::answer( self::save( $r->get_json_params() ?: [] ) ),
			'permission_callback' => $can,
		] );
		register_rest_route( 'whd/v1', '/ai/builder/photo', [
			'methods'             => 'POST',
			'callback'            => static function ( WP_REST_Request $r ) {
				$photo = self::photo( absint( $r->get_param( 'id' ) ) );
				return $photo ? self::answer( $photo ) : self::answer( new WP_Error( 'whd_ib_photo', __( 'That image is not there any more.', 'whd' ) ) );
			},
			'permission_callback' => $can,
		] );
	}

	private static function notes( $text ): string {
		return mb_substr( trim( preg_replace( '/[ \t]+/', ' ', sanitize_textarea_field( (string) $text ) ) ), 0, 2000 );
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
			return new WP_Error( 'whd_ib_data', __( 'The editor did not send an image.', 'whd' ) );
		}
		$bytes = base64_decode( substr( $data, strlen( $m[0] ) ), true );
		if ( false === $bytes || strlen( $bytes ) > self::MAX_BYTES ) {
			return new WP_Error( 'whd_ib_size', __( 'That image is too large to save from the browser — choose a smaller size or JPG.', 'whd' ) );
		}
		$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a broken file is answered below
		$mime = 'image/' . $m[1];
		if ( ! $info || $info['mime'] !== $mime ) {
			return new WP_Error( 'whd_ib_data', __( 'What arrived was not the image it said it was.', 'whd' ) );
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
			return new WP_Error( 'whd_ib_write', $file['error'] );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id = wp_insert_attachment( [
			'post_mime_type' => $mime,
			/* translators: %s: name of the picture it was made from */
			'post_title'     => sprintf( __( '%s — edited', 'whd' ), $name ),
			'post_status'    => 'inherit',
		], $file['file'] );
		if ( is_wp_error( $id ) || ! $id ) {
			return is_wp_error( $id ) ? $id : new WP_Error( 'whd_ib_attach', __( 'The image could not be added to the media library.', 'whd' ) );
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
			'again' => self::edit_url( (int) $id ),
		];
	}

	private static function answer( $result ) {
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'message' => $result->get_error_message() ], 400 );
		}
		return new WP_REST_Response( $result, 200 );
	}
}
