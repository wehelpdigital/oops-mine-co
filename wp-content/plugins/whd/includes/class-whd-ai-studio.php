<?php
/**
 * Write with AI, on the product screen: the photographs in, a finished product page out.
 *
 * The panel sits above the description, because on a new product it is the first thing anybody
 * should touch. It asks three things, in the order the work has to happen:
 *
 *   1. which photographs show the piece — the model reads these, and every new picture is drawn
 *      from them, so nothing else can start until there is at least one;
 *   2. what to write — name, both descriptions, the story, categories, size chart, product data
 *      and the search snippet, each one a tick — plus keywords from the shop's list and a box for
 *      anything the photographs cannot say;
 *   3. how many new pictures to draw, and of what. The choices are always the ones that leave an
 *      even number in all, because the story lays copy and picture out in alternating rows and an
 *      odd one leaves the last row with nothing on its other side.
 *
 * Then one button, and five steps, each its own request so a slow one never times out the rest:
 *
 *   look     the photographs, read whole at a higher resolution than usual: what the piece is, and
 *            every detail that can be counted, counted, with boxes around the ones worth a closer look
 *   inspect  those boxes cut out of the originals at full resolution and read again — the zoom. A
 *            first look at a whole photograph is where five buttons become four, or a single welt
 *            pocket becomes two; the close-up is where it is put right
 *   write    the checked facts become every piece of copy, checked against the house rules, the
 *            keywords and the SEO score — and, when it fails, a repair: the draft sent back once with
 *            the faults listed, as a request of its own
 *   draw     each new picture, from the photographs and the close-ups, with the checked details
 *            spelled out as the brief — then read back by the same eye that did the inspecting,
 *            and drawn once more with the mistakes named if it got the piece wrong
 *   finish   the alt text on every picture and the story, which has no field on this screen
 *
 * Everything that does have a field on this screen — the name, both descriptions, categories, size
 * chart, the SEO box, price and the variation grid — is filled in the browser and saved by Update,
 * like any other edit. Written from here instead, the next Update would post the old values the
 * screen was loaded with straight back over them.
 *
 * A run that overwrites a product first keeps the product as it was saved (keep), and Undo puts
 * all of it back — before or after Update — and deletes the pictures the run drew (undo).
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_AI_Studio {

	const BOX     = 'whd-ai-studio';
	const CONTEXT = 'whd_top';
	const NONCE   = 'whd_ai_studio';

	/** What can be written, in the order the panel lists them. */
	const PARTS = [ 'name', 'description', 'short', 'story', 'categories', 'size_chart', 'product_data', 'seo' ];

	/** Photographs read in one look. Past this the request slows down and the reading does not improve. */
	const LOOK_MAX = 6;

	/** The long edge photographs are read at the first time — sharper than the default, because counting is the job. */
	const LOOK_EDGE = 1536;

	/** Close-ups cut for the second look, and the long edge each one is sent at. */
	const CROPS_MAX = 8;
	const CROP_EDGE = 1024;

	/** References handed to the image model are shrunk to this; a 4,000-pixel phone photograph only makes the request heavier. */
	const REF_EDGE = 1536;

	/** Most pictures a product ends up with through this panel. */
	const TOTAL_MAX = 12;

	/**
	 * Seconds to wait for a model's answer. Writing the whole page took about a minute here and
	 * ran past the default 90 seconds on the live host ("cURL error 28 … 90001 milliseconds"). These
	 * calls run in a background job, with no page held open, so waiting longer costs nothing.
	 */
	const MODEL_WAIT = 240;

	/** Where an overwrite keeps the product as it was (post meta), and how many days Undo is offered. */
	const UNDO      = '_whd_studio_undo';
	const UNDO_DAYS = 7;

	/** What Undo puts back on the product itself — through WooCommerce, so its caches and lookup tables follow. */
	const UNDO_PROPS = [ 'name', 'slug', 'description', 'short_description', 'category_ids', 'regular_price', 'sale_price', 'sku', 'manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'image_id', 'gallery_image_ids' ];

	/** …on each variation. */
	const UNDO_VARIATION = [ 'attributes', 'regular_price', 'sale_price', 'sku', 'manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'image_id', 'status', 'menu_order', 'description' ];

	/**
	 * …and the plugins' own fields a run fills: the size chart (WHD_Size_Charts::META), the search
	 * snippet and its score (WHD_SEO), the story and its switch (WHD_Product_Story::META), and the
	 * Variation tiers' levels (WHDV_Model::META).
	 */
	const UNDO_META = [ '_whd_size_chart', '_omc_seo_title', '_omc_meta_description', '_whd_focus_keyphrase', '_whd_seo_score', '_whd_story', '_whd_story_off', '_whdv_tiers' ];

	/** The aspect ratios the image model accepts, as width / height. */
	const RATIOS = [
		'1:1'  => 1.0,
		'2:3'  => 0.6667,
		'3:2'  => 1.5,
		'3:4'  => 0.75,
		'4:3'  => 1.3333,
		'4:5'  => 0.8,
		'5:4'  => 1.25,
		'9:16' => 0.5625,
		'16:9' => 1.7778,
	];

	public static function init() {
		add_action( 'add_meta_boxes_product', [ __CLASS__, 'meta_box' ] );
		add_action( 'edit_form_after_title', [ __CLASS__, 'print_top' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ], 20 );
		add_action( 'rest_api_init', [ __CLASS__, 'rest' ] );
		// After WooCommerce saves the attributes (10) and the Variation tiers grid builds its own (20).
		add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_attributes' ], 30 );
		// A copy starts with nothing to undo: the original's Undo would put the original's words on it.
		add_filter( 'woocommerce_duplicate_product_exclude_meta', static fn( $keys ) => array_merge( (array) $keys, [ self::UNDO ] ) );
	}

	/** On wherever the AI panel is switched on for products, in WHD → AI content → Settings. */
	public static function active(): bool {
		return function_exists( 'wc_get_product' ) && in_array( 'product', (array) WHD_AI::get()['post_types'], true );
	}

	/* ─────────────────────────── where it sits ─────────────────────────── */

	/**
	 * A real meta box, in a context of its own that is printed under the title.
	 *
	 * Being a meta box keeps what people expect of one — it folds, it can be hidden from Screen
	 * Options — and the context of its own is what puts it above the description, which no
	 * position in the normal column can do: the editor is not a meta box and always comes first.
	 */
	public static function meta_box() {
		if ( ! self::active() ) {
			return;
		}
		add_meta_box( self::BOX, __( 'Write with AI', 'whd' ), [ __CLASS__, 'box' ], 'product', self::CONTEXT, 'high' );
	}

	public static function print_top( $post ) {
		if ( ! $post || 'product' !== $post->post_type || ! self::active() ) {
			return;
		}
		echo '<div class="whd-studio-top">';
		do_meta_boxes( get_current_screen(), self::CONTEXT, $post );
		echo '</div>';
	}

	public static function box( $post ) {
		if ( ! WHD_AI::ready() ) {
			printf(
				'<p class="whd-ai-off">%s <a href="%s">%s</a></p>',
				esc_html__( 'No model is connected yet, so this panel cannot write anything.', 'whd' ),
				esc_url( admin_url( 'admin.php?page=whd-ai' ) ),
				esc_html__( 'Connect one in WHD → AI content.', 'whd' )
			);
			return;
		}
		$draw  = class_exists( 'WHD_AI_Images' ) && WHD_AI_Images::ready();
		$parts = self::part_labels();
		wp_nonce_field( self::NONCE, 'whd_studio_nonce' );
		?>
		<input type="hidden" name="whd_studio_attrs" id="whd-studio-attrs" value="">
		<div class="whd-studio" id="whd-studio">
			<p class="whd-studio__lead">
				<?php esc_html_e( 'Choose the photographs, tick what you want written, and press Generate. The AI reads the pictures up close — every button, pocket and trim counted — and writes the page from what is really there. Everything lands in its own field below for you to read before you press Update.', 'whd' ); ?>
			</p>

			<section class="whd-studio__step">
				<h3 class="whd-studio__h"><span class="whd-studio__n" aria-hidden="true">1</span><?php esc_html_e( 'Photographs of the piece', 'whd' ); ?></h3>
				<div class="whd-studio__photos" id="whd-studio-photos"></div>
				<p class="description" id="whd-studio-photos-note"></p>
			</section>

			<section class="whd-studio__step">
				<h3 class="whd-studio__h"><span class="whd-studio__n" aria-hidden="true">2</span><?php esc_html_e( 'What to write', 'whd' ); ?></h3>
				<div class="whd-studio__parts" role="group" aria-label="<?php esc_attr_e( 'What to write', 'whd' ); ?>">
					<?php foreach ( $parts as $key => $label ) : ?>
						<label class="whd-studio__part">
							<input type="checkbox" data-part="<?php echo esc_attr( $key ); ?>" form="whd-studio-none" checked>
							<span class="whd-studio__part-name"><?php echo esc_html( $label[0] ); ?></span>
							<span class="whd-studio__part-note"><?php echo esc_html( $label[1] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
				<p class="whd-studio__quick">
					<button type="button" class="button-link" data-parts="all"><?php esc_html_e( 'Tick all', 'whd' ); ?></button>
					<span aria-hidden="true">·</span>
					<button type="button" class="button-link" data-parts="none"><?php esc_html_e( 'Untick all', 'whd' ); ?></button>
				</p>

				<div class="whd-studio__field">
					<span class="whd-studio__label"><?php esc_html_e( 'Keywords to work in', 'whd' ); ?></span>
					<div class="whd-kw" id="whd-studio-keywords" data-whd-keywords
						data-placeholder="<?php esc_attr_e( 'Search your keyword list, or type your own', 'whd' ); ?>"></div>
					<p class="whd-ai-suggest" id="whd-studio-suggest" hidden>
						<span class="whd-ai-suggest__label"><?php esc_html_e( 'Suggested — click to add:', 'whd' ); ?></span>
					</p>
					<p class="description">
						<?php esc_html_e( 'Leave this empty and the AI chooses from your list in WHD → AI content → Keywords: the phrase that fits this piece best becomes the focus keyphrase, and two to four more are worked into the copy word for word — never a phrase another page is already aimed at. Pick keywords here to choose them yourself; the first one you pick becomes the focus keyphrase.', 'whd' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=whd-ai&tab=keywords' ) ); ?>"><?php esc_html_e( 'Manage the list', 'whd' ); ?></a>
					</p>
				</div>

				<div class="whd-studio__field">
					<label class="whd-studio__label" for="whd-studio-notes"><?php esc_html_e( 'Anything to specify?', 'whd' ); ?></label>
					<textarea id="whd-studio-notes" rows="3" class="whd-studio__notes"
						placeholder="<?php esc_attr_e( 'e.g. comes in black, cream and navy — XS to XL — 1450, sale 999 — runs small, linen blend, has 4 buttons', 'whd' ); ?>"></textarea>
					<p class="description"><?php esc_html_e( 'Read two ways. Facts about the piece (“runs small”, “linen blend”, “4 buttons”) go into the copy and win over anything the AI thinks it sees. Instructions about the listing (colours and sizes to choose from, price, sale price, stock, SKU) build the product data — options become real variations in the Variation tiers grid.', 'whd' ); ?></p>
				</div>
			</section>

			<section class="whd-studio__step">
				<h3 class="whd-studio__h"><span class="whd-studio__n" aria-hidden="true">3</span><?php esc_html_e( 'More photographs', 'whd' ); ?></h3>
				<?php if ( ! $draw ) : ?>
					<p class="description">
						<?php esc_html_e( 'Drawing pictures needs a Google AI key — the other providers are text only.', 'whd' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=whd-ai' ) ); ?>"><?php esc_html_e( 'Add one', 'whd' ); ?></a>
					</p>
				<?php else : ?>
					<p class="whd-studio__label" id="whd-studio-count-label"><?php esc_html_e( 'How many new pictures?', 'whd' ); ?></p>
					<div class="whd-studio__counts" id="whd-studio-counts" role="radiogroup" aria-labelledby="whd-studio-count-label"></div>
					<p class="description" id="whd-studio-count-note"><?php esc_html_e( 'Always an even number in all, so the story’s picture-and-copy rows come out in pairs. New pictures go on the end of the gallery; nothing already there is touched.', 'whd' ); ?></p>
					<div class="whd-studio__shots" id="whd-studio-shots"></div>
				<?php endif; ?>
			</section>

			<div class="whd-studio__go">
				<button type="button" class="button button-primary button-large" id="whd-studio-go"><?php esc_html_e( 'Generate', 'whd' ); ?></button>
				<span class="whd-studio__status" id="whd-studio-status" role="status" aria-live="polite"></span>
			</div>

			<ol class="whd-studio__progress" id="whd-studio-progress" hidden></ol>
			<div class="whd-studio__saw" id="whd-studio-saw" hidden></div>
			<div class="whd-studio__drawn" id="whd-studio-drawn" hidden></div>
			<div class="whd-studio__result" id="whd-studio-result" hidden></div>
		</div>
		<?php
	}

	/** [ label, what it means ] for each part. */
	public static function part_labels(): array {
		return [
			'name'         => [ __( 'Product name', 'whd' ), __( 'the title', 'whd' ) ],
			'description'  => [ __( 'Description', 'whd' ), __( 'the long one, under the tab', 'whd' ) ],
			'short'        => [ __( 'Short description', 'whd' ), __( 'beside the price', 'whd' ) ],
			'story'        => [ __( 'Product story', 'whd' ), __( 'picture-and-copy rows', 'whd' ) ],
			'categories'   => [ __( 'Categories', 'whd' ), __( 'picked for you', 'whd' ) ],
			'size_chart'   => [ __( 'Size chart', 'whd' ), __( 'picked for you', 'whd' ) ],
			'product_data' => [ __( 'Product data', 'whd' ), __( 'variants, price, fabric, fit, care', 'whd' ) ],
			'seo'          => [ __( 'Search snippet', 'whd' ), __( 'SEO title, meta, keyphrase, alt text', 'whd' ) ],
		];
	}

	public static function assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || 'product' !== $screen->post_type || ! self::active() ) {
			return;
		}
		global $post;
		$post_id = $post ? (int) $post->ID : 0;

		wp_enqueue_media();
		wp_enqueue_style( 'whd-ai-studio', WHD_URL . 'admin/ai-studio.css', [], WHD_VERSION );
		wp_enqueue_script( 'whd-ai-kit', WHD_URL . 'admin/ai-kit.js', [], WHD_VERSION, true );
		wp_enqueue_script( 'whd-ai-studio', WHD_URL . 'admin/ai-studio.js', [ 'whd-ai-kit', 'whd-keyword-picker', 'media-editor' ], WHD_VERSION, true );

		$status = $post ? (string) $post->post_status : 'auto-draft';
		wp_localize_script( 'whd-ai-studio', 'WHD_AI_STUDIO', [
			// A copy made a moment ago to write into: the run it was made for starts on arrival.
			'autorun'   => $post_id ? self::take_run( $post_id ) : null,
			// Only a product that exists in the database can be copied; a new one has nothing to copy yet.
			'canCopy'   => 'auto-draft' !== $status && current_user_can( 'edit_products' ),
			'rest'      => [
				'root'  => esc_url_raw( rest_url( 'whd/v1/ai/studio/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			],
			'postId'    => $post_id,
			'images'    => self::current_images( $post_id ),
			'draw'      => class_exists( 'WHD_AI_Images' ) && WHD_AI_Images::ready(),
			'totalMax'  => self::TOTAL_MAX,
			'lookMax'   => self::LOOK_MAX,
			// WooCommerce ticks this on every product it saves; replacing it is not replacing a choice.
			'defaultCat' => (int) get_option( 'default_product_cat' ),
			'hasStory'  => $post_id && class_exists( 'WHD_Product_Story' ) && WHD_Product_Story::has( $post_id ),
			// The web address follows the name until the piece has been out in the world; after that a
			// new name must not move it, or every link to it breaks.
			'canSlug'   => ! in_array( $status, [ 'publish', 'private', 'future' ], true ),
			// Offered, never pre-picked: a picked keyword must appear word for word, and the matcher
			// behind these is loose enough to offer "petite fashion over 50" for an overshirt.
			'suggested' => $post ? self::suggested( $post ) : [],
			// An overwrite that can still be undone, and whether this page is the one Undo came back to.
			'undo'      => $post_id ? self::undo_offer( $post_id ) : null,
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which note to show
			'undone'    => isset( $_GET['whd_studio'] ) && 'undone' === sanitize_key( wp_unslash( $_GET['whd_studio'] ) ),
			'i18n'      => self::strings(),
		] );
	}

	/**
	 * List keywords to offer as one-click chips, from what the product is already called and filed
	 * under. The run picks its own from the photographs anyway; these are for somebody who wants
	 * to choose. Phrases another page aims at are left out — they would only become a second
	 * page's focus keyphrase.
	 */
	private static function suggested( WP_Post $post ): array {
		if ( ! class_exists( 'WHD_AI_Keywords' ) || 'auto-draft' === $post->post_status ) {
			return [];
		}
		$cats = wp_get_post_terms( $post->ID, 'product_cat', [ 'fields' => 'names' ] );
		$list = WHD_AI_Keywords::shortlist( $post->post_title . ' ' . implode( ' ', is_array( $cats ) ? $cats : [] ), $post->ID, 12 );
		$list = array_filter( $list, static fn( $k ) => ! $k['taken'] && ! $k['general'] );
		return array_slice( array_values( wp_list_pluck( $list, 'keyword' ) ), 0, 6 );
	}

	/** The product's own pictures, featured first, as the panel starts with them chosen. */
	private static function current_images( int $post_id ): array {
		$out = [];
		$ids = $post_id && class_exists( 'WHD_AI_Images' ) ? WHD_AI_Images::gallery( $post_id ) : [];
		foreach ( $ids as $id ) {
			$thumb = wp_get_attachment_image_url( $id, 'thumbnail' );
			if ( $thumb ) {
				$out[] = [ 'id' => (int) $id, 'thumb' => $thumb, 'view' => (string) wp_get_attachment_image_url( $id, 'medium' ), 'large' => (string) wp_get_attachment_image_url( $id, 'large' ) ];
			}
		}
		return $out;
	}

	public static function strings(): array {
		return [
			'choose'      => __( 'Choose photographs', 'whd' ),
			'change'      => __( 'Add or change', 'whd' ),
			'use'         => __( 'Use these', 'whd' ),
			'remove'      => __( 'Remove from this run', 'whd' ),
			'none'        => __( 'No photographs chosen yet. Pick at least one — everything is written and drawn from them.', 'whd' ),
			/* translators: %d: number of photographs */
			'some'        => __( '%d chosen. These are what the AI reads, close up, and what new pictures are drawn from. Any not yet on the product are added to its gallery.', 'whd' ),
			/* translators: %d: the most photographs one run reads */
			'tooMany'     => __( 'Only the first %d are read; the rest still go in the gallery.', 'whd' ),
			'noNew'       => __( 'None', 'whd' ),
			/* translators: %d: pictures in total */
			'inAll'       => __( '%d in all', 'whd' ),
			/* translators: %d: picture number */
			'picture'     => __( 'Picture %d', 'whd' ),
			'onModel'     => __( 'On a model', 'whd' ),
			'mannequin'   => __( 'Ghost mannequin', 'whd' ),
			'askLabel'    => __( 'Instructions for this picture (optional)', 'whd' ),
			'askModel'    => [
				__( 'e.g. walking down a quiet street, autumn light, full length', 'whd' ),
				__( 'e.g. back view, looking over her shoulder', 'whd' ),
				__( 'e.g. sitting at a café table, waist up', 'whd' ),
				__( 'e.g. close-up of the buttons and the cuff, worn', 'whd' ),
			],
			'askGhost'    => [
				__( 'e.g. front view, buttoned', 'whd' ),
				__( 'e.g. back view', 'whd' ),
				__( 'e.g. three-quarter view from the left', 'whd' ),
				__( 'e.g. open, showing the lining', 'whd' ),
			],
			'leftBlank'   => __( 'Left blank, the AI picks what the set is missing — a back view, a close-up of the detail that matters, the whole outfit.', 'whd' ),
			'generate'    => __( 'Generate', 'whd' ),
			'again'       => __( 'Generate again', 'whd' ),
			'needPhoto'   => __( 'Choose at least one photograph first.', 'whd' ),
			'needWork'    => __( 'Tick something to write, or choose some new pictures.', 'whd' ),
			'modalTitle'  => __( 'This product already has content', 'whd' ),
			'modalLead'   => __( 'Generating would replace what is in:', 'whd' ),
			/* translators: %d: number of variations */
			'modalVars'   => __( 'its %d variations, when you press Update', 'whd' ),
			'copyTitle'   => __( 'Write into a copy', 'whd' ),
			'copyNote'    => __( 'A draft copy of this product, as it was last saved, gets the new content. This one stays exactly as it is — delete the copy if you do not like it.', 'whd' ),
			'copyOff'     => __( 'Save the product once to be able to write into a copy instead.', 'whd' ),
			'overTitle'   => __( 'Overwrite this product', 'whd' ),
			'overNote'    => __( 'The fields listed are replaced on this screen. Nothing is saved until you press Update — and Undo puts it all back, even after.', 'whd' ),
			/* translators: %s: the error */
			'keepFailed'  => __( 'Nothing was changed — what is on the product now could not be kept for Undo (%s).', 'whd' ),
			'undoNow'     => __( 'Not what you wanted? Undo puts back everything that was here before — also after you press Update.', 'whd' ),
			/* translators: %s: date and time, e.g. October 23, 2026 at 3:42 pm */
			'undoSince'   => __( 'Write with AI replaced this product’s content on %s.', 'whd' ),
			'undoButton'  => __( 'Undo — put back what was here', 'whd' ),
			'undoTitle'   => __( 'Put back what was here before?', 'whd' ),
			/* translators: %s: date and time, e.g. October 23, 2026 at 3:42 pm */
			'undoLead'    => __( 'The product goes back to how it was saved before Write with AI ran on %s:', 'whd' ),
			'undoList'    => [
				__( 'the name, both descriptions, categories, size chart and search snippet', 'whd' ),
				__( 'price, stock, attributes and variations', 'whd' ),
				__( 'the main image, the gallery, the pictures’ alt text and the product story', 'whd' ),
				__( 'the new pictures it drew are deleted from the media library', 'whd' ),
			],
			'undoFoot'    => __( 'Anything else changed on this product since then goes back too, and anything typed on this screen but not saved is dropped. The page reloads to show it.', 'whd' ),
			'undoGo'      => __( 'Undo', 'whd' ),
			'undoing'     => __( 'Putting it back…', 'whd' ),
			'undone'      => __( 'Put back as it was before Write with AI ran.', 'whd' ),
			'cancel'      => __( 'Cancel', 'whd' ),
			'hintLook'       => __( 'Every photograph, edge to edge — what it is, its colours, and everything that can be counted.', 'whd' ),
			'hintZoom'       => __( 'Close-ups of the buttons, seams and trims, cut from your photographs and counted again.', 'whd' ),
			'hintWrite'      => __( 'Name, descriptions, story and search snippet, in the shop’s own voice.', 'whd' ),
			'hintRepair'     => __( 'Sent back once with the faults listed — keeping whichever draft is better.', 'whd' ),
			'hintModel'      => __( 'On a model, drawn from your photographs — then checked against them.', 'whd' ),
			'hintGhost'      => __( 'On a ghost mannequin, drawn from your photographs — then checked against them.', 'whd' ),
			'hintFinish'     => __( 'Alt text on every picture, and the story beside them.', 'whd' ),
			'hintCopy'       => __( 'A draft copy of this product, so the original stays as it is.', 'whd' ),
			'tooLong'        => __( 'This step has been running for eight minutes, which is far longer than it should — it has been given up on.', 'whd' ),
			'loaderClose'    => __( 'Close', 'whd' ),
			'lightbox'       => __( 'Picture, enlarged', 'whd' ),
			'close'          => __( 'Close', 'whd' ),
			'previous'       => __( 'Previous picture', 'whd' ),
			'next'           => __( 'Next picture', 'whd' ),
			/* translators: 1: position, 2: how many */
			'ofTotal'        => __( '%1$d / %2$d', 'whd' ),
			/* translators: %d: photograph number */
			'photoN'         => __( 'Photograph %d', 'whd' ),
			/* translators: %d: photograph number */
			'viewPhoto'      => __( 'View photograph %d larger', 'whd' ),
			/* translators: %d: picture number */
			'viewPicture'    => __( 'View picture %d larger', 'whd' ),
			'loaderProgress' => __( 'Progress', 'whd' ),
			'loaderDone'     => __( 'Done', 'whd' ),
			'loaderDoneNote' => __( 'Everything is in its field — read it over, then press Update.', 'whd' ),
			/* translators: %s: time so far, e.g. 1:42 */
			'loaderSoFar'    => __( '%s so far', 'whd' ),
			/* translators: 1: fewest minutes, 2: most minutes */
			'loaderUsually'  => __( 'usually %1$d–%2$d minutes', 'whd' ),
			'copying'     => __( 'Making the copy…', 'whd' ),
			'copied'      => __( 'Copy made — opening it…', 'whd' ),
			/* translators: %s: name of the product this one was copied from */
			'isCopy'      => __( 'This is a copy of “%s”. The new content is being written into it; the original stays as it was. Press Update when you are happy with it.', 'whd' ),
			'stepLook'    => __( 'Reading the photographs', 'whd' ),
			'stepZoom'    => __( 'Zooming in on the details', 'whd' ),
			'stepWrite'   => __( 'Writing the page', 'whd' ),
			/* translators: %d: number of faults */
			'stepRepair'  => __( 'Putting right %d thing(s) the checks found', 'whd' ),
			/* translators: %d: number of faults fixed */
			'fixedN'      => __( '%d fixed', 'whd' ),
			'keptFirst'   => __( 'the rewrite was no better, so the first draft stands', 'whd' ),
			/* translators: 1: picture number, 2: pictures to draw */
			'stepDraw'    => __( 'Drawing picture %1$d of %2$d', 'whd' ),
			'stepCheck'   => __( 'checking it against the real piece', 'whd' ),
			'stepRedraw'  => __( 'drawing it again with the mistakes named', 'whd' ),
			'stepFinish'  => __( 'Alt text and the product story', 'whd' ),
			/* translators: %d: number of photographs */
			'looked'      => __( '%d photograph(s) read', 'whd' ),
			/* translators: %d: number of close-ups */
			'zoomed'      => __( '%d close-up(s) checked', 'whd' ),
			'zoomNone'    => __( 'nothing small enough to need a close-up', 'whd' ),
			/* translators: %s: the error */
			'zoomSkipped' => __( 'skipped (%s) — carrying on with the first look', 'whd' ),
			'saw'         => __( 'What the AI saw', 'whd' ),
			'sawNote'     => __( 'Every picture and every line of copy is held to this. If something here is wrong, say so in “Anything to specify?” and generate again — what you write there wins.', 'whd' ),
			'counted'     => __( 'Counted', 'whd' ),
			'atLeast'     => __( 'at least', 'whd' ),
			'without'     => __( 'Does not have', 'whd' ),
			'corrected'   => __( 'Put right on the close-ups', 'whd' ),
			'drawnHead'   => __( 'New pictures', 'whd' ),
			'matches'     => __( 'Matches the piece', 'whd' ),
			'lookAgain'   => __( 'Check this one', 'whd' ),
			'failedDraw'  => __( 'Could not be drawn', 'whd' ),
			'resultHead'  => __( 'Filled in — read it over, then press Update to keep it', 'whd' ),
			'fName'       => __( 'Name', 'whd' ),
			'fDesc'       => __( 'Description', 'whd' ),
			'fShort'      => __( 'Short description', 'whd' ),
			'fStory'      => __( 'Product story (saved now)', 'whd' ),
			'fCats'       => __( 'Categories', 'whd' ),
			'fChart'      => __( 'Size chart', 'whd' ),
			'fChartNone'  => __( 'Size chart: none fits this piece', 'whd' ),
			'fData'       => __( 'Product data', 'whd' ),
			'fVariants'   => __( 'Variations in the Variation tiers grid', 'whd' ),
			'fSeo'        => __( 'SEO title, meta description and keyphrase', 'whd' ),
			'fAlt'        => __( 'Alt text on the pictures (saved now)', 'whd' ),
			/* translators: %d: number of story sections */
			'sections'    => __( '%d sections, each beside its picture', 'whd' ),
			/* translators: %d: number of pictures */
			'pictures'    => __( '%d pictures', 'whd' ),
			'fGallery'    => __( 'Gallery', 'whd' ),
			'notPlaced'   => __( 'Not found on this screen — copy it from below', 'whd' ),
			'keywordsIn'  => __( 'Keywords from your list in the copy', 'whd' ),
			'focusHead'   => __( 'Focus keyphrase', 'whd' ),
			'focusPicked' => __( 'the one you picked', 'whd' ),
			'focusList'   => __( 'from your keyword list', 'whd' ),
			/* translators: %s: searches a month */
			'focusListVol' => __( 'from your keyword list · %s searches a month', 'whd' ),
			'focusName'   => __( 'from the product’s name — nothing in your keyword list fits this piece. Add a phrase for it to the list to aim at one next time.', 'whd' ),
			'stillWrong'  => __( 'Still worth a look', 'whd' ),
			'repaired'    => __( 'Found in the first draft and put right automatically', 'whd' ),
			'error'       => __( 'Something went wrong', 'whd' ),
			'stopped'     => __( 'Stopped', 'whd' ),
			'done'        => __( 'Done', 'whd' ),
			'deleteImage' => __( 'Delete image', 'whd' ),
		];
	}

	/* ─────────────────────────── writing into a copy ─────────────────────────── */

	/**
	 * Duplicate the product, and leave this run waiting on the copy.
	 *
	 * For a product that already has words on it, overwriting is not the only way to try what the
	 * AI would do. The copy is WooCommerce's own Duplicate — draft, "(Copy)" on the name, the same
	 * photographs, categories and variations — so it is a product like any other, and deleting it
	 * afterwards is the whole of the undo.
	 *
	 * The run itself happens on the copy's own screen, so everything still goes through its fields
	 * and its Update: this hands over the settings and the page picks them up on arrival.
	 *
	 * @param array $run images, parts, keywords, notes, count, shots — what the panel had chosen.
	 * @return array|WP_Error { id, name, edit }
	 */
	public static function copy( int $post_id, array $run ) {
		$product = wc_get_product( $post_id );
		if ( ! $product || 'auto-draft' === get_post_status( $post_id ) ) {
			return new WP_Error( 'whd_studio_no_copy', __( 'Save the product once first — a product that has never been saved has nothing to copy.', 'whd' ) );
		}
		if ( ! current_user_can( 'edit_products' ) ) {
			return new WP_Error( 'whd_studio_no_copy', __( 'You are not allowed to create products.', 'whd' ) );
		}
		if ( ! class_exists( 'WC_Admin_Duplicate_Product' ) ) {
			// Loaded by WooCommerce's admin, and a REST request is not the admin.
			include_once WC_ABSPATH . 'includes/admin/class-wc-admin-duplicate-product.php';
		}
		$copy = ( new WC_Admin_Duplicate_Product() )->product_duplicate( $product );
		if ( ! $copy || ! $copy->get_id() ) {
			return new WP_Error( 'whd_studio_copy_failed', __( 'The copy could not be made.', 'whd' ) );
		}

		set_transient( self::run_key( $copy->get_id() ), [
			'from'     => $post_id,
			'images'   => self::image_ids( $run['images'] ?? [] ),
			'parts'    => self::parts( $run['parts'] ?? [] ),
			'keywords' => WHD_AI::clean_keywords( (array) ( $run['keywords'] ?? [] ) ),
			'notes'    => self::commands( $run['notes'] ?? '' ),
			'count'    => max( 0, min( self::TOTAL_MAX, (int) ( $run['count'] ?? 0 ) ) ),
			'shots'    => array_map( static fn( $s ) => [ 'mode' => $s['mode'], 'ask' => $s['ask'] ], self::shots( $run['shots'] ?? [] ) ),
		], HOUR_IN_SECONDS );

		return [
			'id'   => $copy->get_id(),
			'name' => html_entity_decode( $copy->get_name(), ENT_QUOTES, 'UTF-8' ),
			'edit' => add_query_arg( 'whd_studio', 'run', get_edit_post_link( $copy->get_id(), 'raw' ) ),
		];
	}

	/** The run left waiting on a copy, once: taking it removes it, so a reload does not start it again. */
	private static function take_run( int $post_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only says whether to look; the run is keyed to this user
		if ( ! isset( $_GET['whd_studio'] ) || 'run' !== sanitize_key( wp_unslash( $_GET['whd_studio'] ) ) ) {
			return null;
		}
		$run = get_transient( self::run_key( $post_id ) );
		if ( ! is_array( $run ) ) {
			return null;
		}
		delete_transient( self::run_key( $post_id ) );

		$run['photos'] = [];
		foreach ( $run['images'] as $id ) {
			$thumb = wp_get_attachment_image_url( $id, 'thumbnail' );
			if ( $thumb ) {
				$run['photos'][] = [ 'id' => (int) $id, 'thumb' => $thumb, 'view' => (string) wp_get_attachment_image_url( $id, 'medium' ), 'large' => (string) wp_get_attachment_image_url( $id, 'large' ) ];
			}
		}
		$run['fromName'] = html_entity_decode( get_the_title( (int) $run['from'] ), ENT_QUOTES, 'UTF-8' );
		unset( $run['images'] );

		return $run;
	}

	private static function run_key( int $post_id ): string {
		return 'whd_studio_run_' . get_current_user_id() . '_' . $post_id;
	}

	/* ─────────────────────────── step one: look ─────────────────────────── */

	/**
	 * The voice that reads photographs. Not the copywriter's.
	 *
	 * The brief and the house rules are for writing; an inspector given them starts describing
	 * how a piece makes you feel. This one is told to count and to say where things are, and to
	 * say when it cannot see something rather than fill it in from what is usual.
	 */
	private static function inspector(): string {
		return 'You are the product inspector for an online boutique. You look at photographs of one product and report exactly what is there, the way a garment technologist writes a spec sheet: every visible detail, counted one at a time, with where it sits. You never estimate, never round, and never borrow a detail from what pieces like this usually have. When something cannot be seen you say so. You answer with JSON only.';
	}

	/**
	 * Read the photographs whole and say what the piece is, down to the last button.
	 *
	 * @param int[]  $ids      Attachments chosen in the panel.
	 * @param string $commands What the shop typed, which is true whatever the picture seems to say.
	 * @return array|WP_Error
	 */
	public static function look( int $post_id, array $ids, string $commands = '' ) {
		$ids = self::image_ids( $ids );
		if ( ! $ids ) {
			return new WP_Error( 'whd_studio_no_images', __( 'Choose at least one photograph of the piece first.', 'whd' ) );
		}
		$read = array_slice( $ids, 0, self::LOOK_MAX );
		$n    = count( $read );

		$user = sprintf( "You are looking at %d photograph(s) of one product, numbered 1 to %d in the order they are attached. Study each one edge to edge, the way you would before writing its spec sheet: the whole piece first, then every part of it up close.\n\n", $n, $n )
			. ( '' !== $commands ? "WHAT THE SHOP HAS TOLD US — true, and it wins over anything you think you see:\n" . $commands . "\n\n" : '' )
			. "Answer with JSON only, in exactly this shape:\n"
			. "{\n"
			. '"kind": "garment, footwear, bag, jewellery, accessory, beauty or other",' . "\n"
			. '"garment": "what it is in two to four plain words, e.g. ribbed knit cardigan",' . "\n"
			. '"colour": "the main colour as a shopper would say it",' . "\n"
			. '"other_colours": ["every other colour on it — trims, buttons, the print"],' . "\n"
			. '"pattern": "solid, or the print described exactly: the motif, its size, its colours, which way it runs",' . "\n"
			. '"fabric": "what it looks to be made of, hedged if you cannot be sure",' . "\n"
			. '"texture": "ribbed, brushed, smooth, pleated, quilted…",' . "\n"
			. '"silhouette": "how it sits on the body",' . "\n"
			. '"neckline": "", "collar": "", "sleeve": "", "cuff": "", "length": "", "hem": "", "waist": "", "closure": "",' . "\n"
			. '"colourways": [{"name": "what the shop calls it, or a plain name", "tones": "its colours exactly, e.g. muted rust, chocolate brown, slate blue and ivory", "images": [1]}],' . "\n"
			. '"countables": [{"item": "front buttons", "count": 4, "all_visible": true, "where": "centre front placket, evenly spaced from the neckline to the hem", "look": "small, round, tortoiseshell", "confidence": "high"}],' . "\n"
			. '"details": ["everything else worth knowing — stitching, trims, a slit, a split hem, a tie, any lettering that is part of the design"],' . "\n"
			. '"absent": ["what this piece does not have that a similar piece might — no pockets, no belt, no visible lining, no logo"],' . "\n"
			. '"views": [{"image": 1, "shows": "front, on a model, full length"}],' . "\n"
			. '"regions": [{"image": 1, "box": [120, 380, 640, 620], "look_for": "count the buttons down the placket"}],' . "\n"
			. '"styling": ["what it would be worn with"],' . "\n"
			. '"occasion": ["where it would be worn"],' . "\n"
			. '"season": "",' . "\n"
			. '"category": "the single best kind-of-thing category for it, e.g. Cardigans",' . "\n"
			. '"name_ideas": ["three short product names: no brand, no superlatives"]' . "\n"
			. "}\n\n"
			. "RULES\n"
			. "- Count, do not estimate. Every detail that can be counted goes in \"countables\": buttons (down the front, on each cuff, on pockets, on straps), pockets, pleats, tiers, ruffles, straps, belt loops, bows, zips, toggles, panels, eyelets, stripes when there are only a few. Count them one at a time and say where they are.\n"
			. "- One entry per kind of detail on the whole piece — not one per photograph. Put together what all the photographs show: if one shows the top three buttons and another the bottom two of the same placket, that is one entry, \"front buttons\", and the count is how many the piece has.\n"
			. "- \"all_visible\" is true only when every one of them can be seen in at least one photograph, so the count is the whole number. When some could be hidden — an arm, a fold, an open front, the edge of the picture — it is false, \"count\" is how many you can see, and \"where\" says what hides the rest. Never fill in a number from what is usual.\n"
			. "- \"colourways\": only when the photographs show the same piece in more than one colour — one entry per colour, with the photographs that show it. Otherwise an empty list.\n"
			. "- Colours as they would look in daylight, named precisely — \"muted rust, slate blue and brown\", not \"red\"; \"oatmeal\", not \"beige\" when it is oatmeal. Whoever copies this piece from your words will reach for the commonest thing a loose name means: \"red plaid\" gets drawn as a bright red tartan. Name a colourway the same careful way. If two photographs disagree, say in \"details\" which one you trust and why.\n"
			. "- \"regions\": three to eight boxes around the details that need a closer look to get exactly right — fastenings, cuffs, collar, pockets, trims, the print, the hem, any hardware. Coordinates are thousandths of that photograph's height and width, top-left [0, 0], in the order [ymin, xmin, ymax, xmax].\n"
			. "- One entry in \"views\" per photograph.\n"
			. '- Only what you can see. No measurements, no price, no stock, no country of origin, no brand names, no claims about how or where it was made.';

		self::mark( sprintf( 'reading %d photograph(s) with the model', $n ) );
		$reply = WHD_AI::call( self::inspector(), $user, [
			'json'        => true,
			'images'      => $read,
			'max_tokens'  => 10000,
			'timeout'     => self::MODEL_WAIT,
			'vision_edge' => self::LOOK_EDGE,
			'detail'      => 'high',
		] );
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		$facts = self::decode( $reply );
		if ( ! is_array( $facts ) ) {
			return new WP_Error( 'whd_studio_unreadable', __( 'The model’s reading of the photographs came back unreadable. Try again.', 'whd' ) );
		}

		$facts              = self::tidy_facts( $facts, $n );
		$facts['image_ids'] = $read;

		return $facts;
	}

	/* ─────────────────────────── step two: zoom in ─────────────────────────── */

	/**
	 * Cut the details out of the originals at full resolution, and read them again.
	 *
	 * A whole photograph sent to a model is shrunk to a thousand-odd pixels on its long side, and at
	 * that size four small buttons on a busy knit are a smudge with a number attached to it. The
	 * same buttons cut out of the original fill the frame. So the first look says where to look,
	 * and this looks there — with a 2×2 grid of the main photograph as a fallback, for a first look
	 * that drew its boxes in the wrong place or drew none.
	 *
	 * @return array|WP_Error The facts, with the countables and the fine detail checked.
	 */
	public static function inspect( int $post_id, array $facts, string $commands = '' ) {
		$facts = self::tidy_facts( $facts, count( self::image_ids( $facts['image_ids'] ?? [] ) ) );
		$ids   = self::image_ids( $facts['image_ids'] ?? [] );
		if ( ! $ids ) {
			return new WP_Error( 'whd_studio_no_images', __( 'There are no photographs to look at more closely.', 'whd' ) );
		}

		$crops = self::crops( $ids, $facts['regions'], self::CROPS_MAX );
		if ( count( $crops ) < 3 ) {
			foreach ( array_slice( $ids, 0, 2 ) as $i => $id ) {
				foreach ( self::quarters() as $label => $box ) {
					$path = self::cut( $id, $box, self::CROP_EDGE );
					if ( $path ) {
						$crops[] = [
							'path'  => $path,
							'image' => $i + 1,
							'label' => sprintf( 'the %s of the photograph, enlarged', $label ),
						];
					}
				}
			}
		}
		if ( ! $crops ) {
			$facts['inspected'] = 0;
			return $facts;
		}

		$order = [ '1. Photograph 1, whole — for orientation only.' ];
		foreach ( $crops as $i => $crop ) {
			$order[] = sprintf( '%d. A close-up cut from photograph %d: %s.', $i + 2, $crop['image'], $crop['label'] );
		}

		$first = array_intersect_key( $facts, array_flip( [ 'garment', 'colour', 'other_colours', 'pattern', 'neckline', 'collar', 'sleeve', 'cuff', 'length', 'hem', 'waist', 'closure', 'countables', 'details', 'absent' ] ) );

		$user = "A first look at this product was taken from the whole photographs. You now have close-ups cut from the same photographs at full resolution, so the small details can be checked properly.\n\n"
			. "ATTACHED, IN ORDER\n" . implode( "\n", $order ) . "\n\n"
			. ( '' !== $commands ? "WHAT THE SHOP HAS TOLD US — true, and it wins over anything you see:\n" . $commands . "\n\n" : '' )
			. "THE FIRST LOOK SAID\n" . wp_json_encode( $first, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n\n"
			. "Check it against the close-ups. Recount every countable, one at a time. Correct anything that is wrong, add anything it missed, and drop anything that is not really there. Where a close-up and a whole photograph seem to disagree, trust the close-up. Something the close-ups do not show stays as the first look had it.\n"
			. "Keep one entry per kind of detail on the whole piece, never one per photograph or per close-up: two close-ups of the same placket are one \"front buttons\" entry. \"all_visible\" stays false unless every one of them has now been seen.\n\n"
			. "Answer with JSON only:\n"
			. "{\n"
			. '"countables": [{"item": "", "count": 0, "all_visible": true, "where": "", "look": "", "confidence": "high, medium or low", "checked": true}],' . "\n"
			. '"colour": "", "other_colours": [], "pattern": "", "neckline": "", "collar": "", "sleeve": "", "cuff": "", "hem": "", "closure": "",' . "\n"
			. '"details": ["the whole list, corrected"],' . "\n"
			. '"absent": ["the whole list, corrected"],' . "\n"
			. '"fine_print": "what a copy of this piece must reproduce that the fields above do not hold — stitch lines, trim widths, the finish on any hardware, where a print starts and stops",' . "\n"
			. '"corrections": ["what changed from the first look and what the close-up showed, e.g. front buttons: 5 → 4, the fifth was a reflection"]' . "\n"
			. "}\n"
			. '"checked" is true for a countable you could actually see in a close-up. Leave a field empty only when the close-ups say nothing about it.';

		self::mark( sprintf( 'checking %d close-up(s) with the model', count( $crops ) ) );
		$reply = WHD_AI::call( self::inspector(), $user, [
			'json'       => true,
			'images'     => array_merge( [ $ids[0] ], wp_list_pluck( $crops, 'path' ) ),
			'max_tokens' => 10000,
			'timeout'    => self::MODEL_WAIT,
			'detail'     => 'high',
		] );
		self::forget( $crops );
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		$checked = self::decode( $reply );
		if ( ! is_array( $checked ) ) {
			// The first look stands; the close-ups were a second opinion, not the only one.
			$facts['inspected'] = 0;
			return $facts;
		}
		$checked = self::clean( $checked );

		$counted = self::countables( $checked['countables'] ?? [] );
		if ( $counted ) {
			$facts['countables'] = $counted;
		}
		foreach ( [ 'colour', 'pattern', 'neckline', 'collar', 'sleeve', 'cuff', 'hem', 'closure', 'fine_print' ] as $key ) {
			if ( '' !== trim( (string) ( $checked[ $key ] ?? '' ) ) ) {
				$facts[ $key ] = (string) $checked[ $key ];
			}
		}
		foreach ( [ 'other_colours', 'details', 'absent', 'corrections' ] as $key ) {
			$list = self::strings_of( $checked[ $key ] ?? [] );
			if ( $list ) {
				$facts[ $key ] = $list;
			}
		}
		$facts['inspected'] = count( $crops );

		return $facts;
	}

	/** Overlapping quarters of a photograph, for when nothing better says where to look. */
	private static function quarters(): array {
		return [
			'top-left quarter'     => [ 0, 0, 560, 560 ],
			'top-right quarter'    => [ 0, 440, 560, 1000 ],
			'bottom-left quarter'  => [ 440, 0, 1000, 560 ],
			'bottom-right quarter' => [ 440, 440, 1000, 1000 ],
		];
	}

	/**
	 * The regions the first look asked about, cut out as temporary files.
	 *
	 * @return array[] { path, image (1-based), label }
	 */
	private static function crops( array $ids, array $regions, int $max ): array {
		$out = [];
		foreach ( $regions as $region ) {
			if ( count( $out ) >= $max ) {
				break;
			}
			$id = $ids[ (int) $region['image'] - 1 ] ?? 0;
			if ( ! $id ) {
				continue;
			}
			$path = self::cut( (int) $id, $region['box'], self::CROP_EDGE );
			if ( $path ) {
				$out[] = [ 'path' => $path, 'image' => (int) $region['image'], 'label' => (string) $region['look_for'] ];
			}
		}
		return $out;
	}

	/**
	 * Cut one box out of a photograph's original file and save it as a temporary JPEG.
	 *
	 * Padded on every side and never smaller than a quarter of the picture across, because a box
	 * drawn by a model is a good guess at where something is, not a measurement — a crop that
	 * keeps a little of what surrounds the detail still has the detail in it when the box is off.
	 *
	 * @param array $box [ ymin, xmin, ymax, xmax ] in thousandths.
	 * @return string Path, or '' when it could not be cut.
	 */
	private static function cut( int $id, array $box, int $edge ): string {
		try {
			$file = self::original( $id );
			self::mark( sprintf( 'cutting a close-up from photo #%d (%s)', $id, basename( $file ) ) );
			return self::cut_from( $file, $box, $edge );
		} catch ( Throwable $e ) {
			// One crop that will not open is a missing close-up, not a broken run.
			return '';
		}
	}

	private static function cut_from( string $file, array $box, int $edge ): string {
		if ( '' === $file ) {
			return '';
		}
		// What WordPress's own image screens ask for (WP_MAX_MEMORY_LIMIT) before opening a picture.
		wp_raise_memory_limit( 'image' );
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return '';
		}
		$size = $editor->get_size();
		$w    = (int) $size['width'];
		$h    = (int) $size['height'];
		if ( $w < 64 || $h < 64 ) {
			return '';
		}

		[ $y0, $x0, $y1, $x1 ] = array_map( static fn( $v ) => max( 0, min( 1000, (float) $v ) ) / 1000, array_values( $box ) );
		$bw    = max( 0.01, $x1 - $x0 );
		$bh    = max( 0.01, $y1 - $y0 );
		$cx    = ( $x0 + $x1 ) / 2;
		$cy    = ( $y0 + $y1 ) / 2;
		$least = 0.25 * min( $w, $h );
		$cw    = min( $w, max( $least, $bw * $w * 1.3 ) );
		$ch    = min( $h, max( $least, $bh * $h * 1.3 ) );
		$sx    = (int) round( max( 0, min( $w - $cw, $cx * $w - $cw / 2 ) ) );
		$sy    = (int) round( max( 0, min( $h - $ch, $cy * $h - $ch / 2 ) ) );
		$cw    = (int) round( $cw );
		$ch    = (int) round( $ch );

		// Enlarged to fill the frame the model reads, but never past three times: beyond that it is blur.
		$scale = min( 3, $edge / max( $cw, $ch ) );
		$editor->crop( $sx, $sy, $cw, $ch, (int) round( $cw * $scale ), (int) round( $ch * $scale ) );
		$editor->set_quality( 90 );

		$path  = trailingslashit( get_temp_dir() ) . 'whd-studio-' . wp_generate_password( 10, false, false ) . '.jpg';
		$saved = $editor->save( $path, 'image/jpeg' );

		return is_wp_error( $saved ) || empty( $saved['path'] ) ? '' : (string) $saved['path'];
	}

	/** Past this many pixels the original is not worth what it costs to open: the web copy is used instead. */
	const ORIGINAL_MAX_PIXELS = 16000000;

	/**
	 * The file to cut close-ups from: the upload itself where that is safe, else WordPress's web copy.
	 *
	 * WordPress keeps the upload as it arrived and works from a copy scaled to 2,560 pixels. The
	 * upload has the most detail, and it can also be a 24-megapixel phone photograph, or the HEIC an
	 * iPhone sent, which WordPress keeps beside the JPEG it made. Opened whole, either one is enough
	 * for a shared host to kill the request outright — no error, no log, a bare HTTP 500 — which
	 * is what the zoom step did on the live shop the first time it ran. So the upload is used only
	 * when it is an ordinary web image of a sensible size; the web copy is still plenty for a crop.
	 */
	private static function original( int $id ): string {
		$copy = (string) get_attached_file( $id );
		$orig = function_exists( 'wp_get_original_image_path' ) ? (string) wp_get_original_image_path( $id ) : '';
		if ( '' !== $orig && $orig !== $copy && file_exists( $orig ) ) {
			$type = (string) ( wp_check_filetype( $orig )['type'] ?? '' );
			$size = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $orig ) : @getimagesize( $orig ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( in_array( $type, [ 'image/jpeg', 'image/png', 'image/webp' ], true )
				&& $size && (int) $size[0] * (int) $size[1] <= self::ORIGINAL_MAX_PIXELS ) {
				return $orig;
			}
		}
		return '' !== $copy && file_exists( $copy ) ? $copy : '';
	}

	private static function forget( array $crops ) {
		foreach ( $crops as $crop ) {
			if ( ! empty( $crop['path'] ) && file_exists( $crop['path'] ) ) {
				wp_delete_file( $crop['path'] );
			}
		}
	}

	/**
	 * The checked facts as a brief: every line something a picture or a sentence can get wrong.
	 *
	 * Built here rather than by the model so it says exactly what was counted, in the same words,
	 * to the copywriter, the illustrator and the inspector who checks the illustrator's work.
	 */
	public static function spec( array $facts ): string {
		$lines = [];
		$what  = trim( (string) ( $facts['garment'] ?? '' ) );
		if ( '' !== $what ) {
			$lines[] = 'What it is: ' . $what;
		}
		$ways = self::colourways( $facts['colourways'] ?? [] );
		if ( count( $ways ) > 1 ) {
			$lines[] = 'The photographs show ' . count( $ways ) . ' colourways: ' . implode( '; ', array_map( static function ( $way ) {
				return $way['name']
					. ( '' !== $way['tones'] ? ', which is ' . $way['tones'] : '' )
					. ( $way['images'] ? ' (photograph ' . implode( ' and ', $way['images'] ) . ')' : '' );
			}, $ways ) ) . '. A picture shows one of them, copied from the photograph that shows it — never a mix of the two.';
		} elseif ( '' !== trim( (string) ( $facts['colour'] ?? '' ) ) ) {
			$lines[] = 'Colour: ' . $facts['colour'];
		}
		$others = self::strings_of( $facts['other_colours'] ?? [] );
		if ( $others ) {
			$lines[] = 'Other colours on it: ' . implode( ', ', $others );
		}
		$labels = [
			'pattern'    => 'Pattern',
			'fabric'     => 'Fabric (as it looks)',
			'texture'    => 'Texture',
			'silhouette' => 'Silhouette',
			'neckline'   => 'Neckline',
			'collar'     => 'Collar',
			'sleeve'     => 'Sleeves',
			'cuff'       => 'Cuffs',
			'length'     => 'Length',
			'hem'        => 'Hem',
			'waist'      => 'Waist',
			'closure'    => 'Closure',
		];
		foreach ( $labels as $key => $label ) {
			$value = trim( (string) ( $facts[ $key ] ?? '' ) );
			if ( '' !== $value && ! in_array( strtolower( $value ), [ 'none', 'n/a', 'not visible', 'unknown' ], true ) ) {
				$lines[] = $label . ': ' . $value;
			}
		}
		foreach ( self::countables( $facts['countables'] ?? [] ) as $c ) {
			$where   = ( '' !== $c['where'] ? ' — ' . $c['where'] : '' ) . ( '' !== $c['look'] ? '; ' . $c['look'] : '' );
			$lines[] = $c['all_visible']
				? sprintf( 'Exactly %d %s%s', $c['count'], $c['item'], $where )
				// A number that is only what one pose let us see is not the number to draw.
				: sprintf( '%s: at least %d can be seen%s. The rest are hidden in the photographs, so copy the size and spacing the references show', ucfirst( $c['item'] ), $c['count'], $where );
		}
		foreach ( self::strings_of( $facts['details'] ?? [] ) as $detail ) {
			$lines[] = $detail;
		}
		if ( '' !== trim( (string) ( $facts['fine_print'] ?? '' ) ) ) {
			$lines[] = (string) $facts['fine_print'];
		}
		$absent = self::strings_of( $facts['absent'] ?? [] );
		if ( $absent ) {
			$lines[] = 'It does NOT have any of these, so none may be added: ' . implode( '; ', $absent );
		}

		return $lines ? '- ' . implode( "\n- ", $lines ) : '';
	}

	/* ─────────────────────────── step three: write ─────────────────────────── */

	/**
	 * Every ticked piece of copy, in one reply, and checked.
	 *
	 * One reply rather than one per field, for the same reason the two descriptions were already
	 * written together: copy written in separate passes is how a page ends up with a short
	 * description, a long one and a story that do not quite agree about the piece.
	 *
	 * A draft that fails the checks comes back marked for repair, and the panel asks for that as a
	 * request of its own: one reply of this size takes the best part of a minute, and two of them
	 * in one request is the kind of wait a shared host ends for you.
	 *
	 * @param array $args parts, keywords, commands, images (chosen ids), shots, current { title, short, description }.
	 * @return array|WP_Error
	 */
	public static function write( int $post_id, array $facts, array $args ) {
		$ctx = self::write_context( $post_id, $facts, $args );

		/*
		 * A generous budget: these models reason before they answer, out of the same allowance, and
		 * a reply cut off part way through is JSON that cannot be read at all.
		 */
		self::mark( 'writing the copy with the model' );
		$reply = WHD_AI::call( WHD_AI::system_prompt(), self::write_prompt( $ctx ), [ 'json' => true, 'max_tokens' => 20000, 'timeout' => self::MODEL_WAIT ] );
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		$raw = self::decode( $reply );
		if ( ! is_array( $raw ) ) {
			return new WP_Error(
				'whd_studio_unwritable',
				sprintf(
					/* translators: %d: how many characters came back */
					__( 'The write-up came back unreadable (%d characters) — usually that means it was cut off part way through. Try again; if it keeps happening, untick the story or ask for fewer pictures.', 'whd' ),
					strlen( (string) $reply )
				)
			);
		}

		return self::drafted( self::settle_draft( $raw, $ctx ), $raw, $ctx, [], 1 );
	}

	/**
	 * Send a draft back once with what is wrong with it listed, and keep whichever is better.
	 *
	 * @param array $args The same as write(), plus raw: the reply being repaired.
	 * @return array|WP_Error
	 */
	public static function repair( int $post_id, array $facts, array $args ) {
		$ctx = self::write_context( $post_id, $facts, $args );
		$raw = is_array( $args['raw'] ?? null ) ? $args['raw'] : [];
		if ( ! $raw || strlen( (string) wp_json_encode( $raw ) ) > 120000 ) {
			return new WP_Error( 'whd_studio_no_draft', __( 'There is no draft to put right.', 'whd' ) );
		}
		$draft  = self::settle_draft( $raw, $ctx );
		$issues = self::problems( $draft, $ctx );
		if ( ! $issues ) {
			return self::drafted( $draft, $raw, $ctx, [], 2 );
		}

		self::mark( 'rewriting the copy with the model' );
		$again = WHD_AI::call(
			WHD_AI::system_prompt(),
			self::write_prompt( $ctx ) . "\n\nYOUR PREVIOUS ANSWER\n" . wp_json_encode( $raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				. "\n\nWHAT IS WRONG WITH IT\n- " . implode( "\n- ", $issues )
				. "\n\nAnswer again with the whole JSON, every key, with all of those put right and everything that was already right kept as it was.",
			[ 'json' => true, 'max_tokens' => 20000, 'timeout' => self::MODEL_WAIT ]
		);
		$second = is_wp_error( $again ) ? null : self::decode( $again );
		if ( is_array( $second ) ) {
			$redraft = self::settle_draft( $second, $ctx );
			$left    = self::problems( $redraft, $ctx );
			// Kept only if it is actually better — a second pass can trade one fault for two.
			if ( count( $left ) <= count( $issues ) ) {
				return self::drafted( $redraft, $second, $ctx, array_values( array_diff( $issues, $left ) ), 2 );
			}
		}

		return self::drafted( $draft, $raw, $ctx, [], 2 );
	}

	/** The draft as the panel receives it: the checks, what the screen should hold, and whether to repair. */
	private static function drafted( array $draft, array $raw, array $ctx, array $repaired, int $attempts ): array {
		$draft['issues']   = self::problems( $draft, $ctx );
		$draft['repaired'] = $repaired;
		$draft['attempts'] = $attempts;
		$draft['repair']   = $attempts < 2 && $draft['issues'] && ! empty( WHD_AI::get()['auto_repair'] );
		$draft['raw']      = $draft['repair'] ? $raw : null;
		$draft['model']    = WHD_AI::model();
		$draft['used']     = self::used_keywords( $draft, array_merge( [ (string) $draft['keyphrase'] ], (array) $draft['keywords'], wp_list_pluck( $ctx['shortlist'], 'keyword' ) ) );
		$draft['screen']   = self::screen( $ctx['post_id'], $draft, $ctx );

		return $draft;
	}

	/** Everything the writer is told, worked out once from what the panel sent. */
	private static function write_context( int $post_id, array $facts, array $args ): array {
		$facts    = self::tidy_facts( $facts, count( self::image_ids( $facts['image_ids'] ?? [] ) ) );
		$parts    = self::parts( $args['parts'] ?? [] );
		$keywords = WHD_AI::clean_keywords( $args['keywords'] ?? [] );
		$commands = self::commands( $args['commands'] ?? '' );
		$have     = self::image_ids( ! empty( $args['images'] ) ? $args['images'] : ( $facts['image_ids'] ?? [] ) );
		$shots    = self::shots( $args['shots'] ?? [] );
		$current  = self::current( (array) ( $args['current'] ?? [] ), $post_id );
		$total    = count( $have ) + count( $shots );
		$sections = in_array( 'story', $parts, true ) ? max( 3, min( 8, $total ) ) : 0;
		$fixed    = in_array( 'name', $parts, true ) ? '' : self::tidy_name( $current['title'] );

		/*
		 * The keywords this piece is written around come from the shop's list: the ones that fit it,
		 * found from what the piece is, what the shop said about it and where it is filed. The ones
		 * the shop picked by hand are left out here, because they come first anyway.
		 */
		$cats  = $post_id ? wp_get_post_terms( $post_id, 'product_cat', [ 'fields' => 'names' ] ) : [];
		$about = implode( ' ', array_merge(
			array_map( 'strval', array_intersect_key( $facts, array_flip( [ 'garment', 'category', 'colour', 'pattern', 'fabric', 'texture', 'silhouette', 'neckline', 'sleeve', 'length', 'season' ] ) ) ),
			self::strings_of( $facts['other_colours'] ?? [] ),
			self::strings_of( $facts['occasion'] ?? [] ),
			is_array( $cats ) ? $cats : [],
			[ $commands, $current['title'] ]
		) );
		$shortlist = class_exists( 'WHD_AI_Keywords' ) ? array_values( array_filter(
			WHD_AI_Keywords::shortlist( $about, $post_id, 20 ),
			static fn( $k ) => ! in_array( mb_strtolower( $k['keyword'] ), array_map( 'mb_strtolower', $keywords ), true )
		) ) : [];

		return [
			'parts'     => $parts,
			'keywords'  => $keywords, // picked by hand in the panel
			'shortlist' => $shortlist,
			'commands'  => $commands,
			'facts'     => $facts,
			'have'      => count( $have ),
			'shots'     => $shots,
			'total'     => $total,
			'sections'  => $sections,
			'fixed'     => $fixed,
			'current'   => $current,
			'post_id'   => $post_id,
		];
	}

	private static function write_prompt( array $ctx ): string {
		$parts = $ctx['parts'];
		$has   = static fn( $part ) => in_array( $part, $parts, true );
		$facts = self::without( $ctx['facts'], [ 'regions', 'image_ids', 'inspected', 'skipped', 'corrections', 'views', 'name_ideas' ] );
		$out   = [];

		$out[] = "Write the shop page for this piece. Everything you say about it comes from THE PIECE below, which was read off its photographs and checked on close-ups. Never add a detail it does not list, and never change a count. If a count is not worth mentioning, leave the number out rather than write a different one.";
		$out[] = "THE PIECE\n" . wp_json_encode( $facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$out[] = "THE PIECE, DETAIL BY DETAIL (the counts are exact)\n" . self::spec( $ctx['facts'] );
		if ( ! empty( $ctx['facts']['name_ideas'] ) ) {
			$out[] = "NAME IDEAS FROM THE FIRST LOOK, to start from or ignore\n- " . implode( "\n- ", self::strings_of( $ctx['facts']['name_ideas'] ) );
		}

		if ( '' !== $ctx['commands'] ) {
			$out[] = "THE SHOP'S OWN WORDS — they win over anything above. Read them two ways: facts about the piece go into the copy; instructions about the listing (options to choose between, price, sale price, stock, SKU, category) go into \"plan\".\n" . $ctx['commands'];
		}

		$said = trim( $ctx['current']['short'] . "\n" . $ctx['current']['description'] );
		if ( '' !== $said ) {
			$out[] = "WHAT THE PAGE SAYS NOW. Facts in it the photographs cannot show — fabric content, fit, care — are the shop's and are true. Do not reuse its wording.\n" . wp_trim_words( $said, 250 );
		}

		if ( '' !== $ctx['fixed'] ) {
			$out[] = 'THE NAME IS FIXED: "' . $ctx['fixed'] . '". Use it exactly, everywhere the rules below say the name.';
		}

		$out[] = self::keyword_brief( $ctx );

		$out[] = "THE PICTURES\nThe page will carry " . $ctx['total'] . " pictures, in this order:\n" . self::picture_list( $ctx );

		if ( $has( 'categories' ) ) {
			$out[] = "CATEGORIES — choose from this list only, by id:\n" . self::category_list()
				. ( self::filing_examples() ? "\nHow the shop has filed its recent pieces:\n" . self::filing_examples() : '' )
				. "\nChoose the category that says what the piece is, plus any collection the shop files every new piece under. One to three ids.";
		}
		if ( $has( 'size_chart' ) ) {
			$charts = self::chart_list();
			if ( $charts ) {
				$out[] = "SIZE CHARTS — the id of the one that fits this piece, or 0 when none does (skincare, a bag, a one-size accessory):\n" . $charts;
			}
		}
		if ( $has( 'product_data' ) ) {
			$known = self::attribute_list();
			if ( $known ) {
				$out[] = "ATTRIBUTES THE SHOP ALREADY USES — use these names and these spellings of the options wherever they fit:\n" . $known;
			}
		}

		$out[] = "Answer with JSON only, with these keys:\n" . self::answer_shape( $ctx );
		$out[] = "RULES\n" . self::write_rules( $ctx );

		return implode( "\n\n", $out );
	}

	/**
	 * What the copy is aimed at: keywords from the shop's own list.
	 *
	 * The keyphrase used to come from the product's own name, with the list passed along as words
	 * the model could use or ignore, so the researched phrases mostly went unused. Now the model
	 * chooses its focus keyphrase and two to four supporting keywords from the shortlist of list
	 * phrases that fit this piece, copies them exactly, and the copy is checked for every one of
	 * them afterwards. Only when nothing on the list fits does the product's name stand in.
	 */
	public static function keyword_brief( array $ctx ): string {
		$lines = [];
		if ( $ctx['keywords'] ) {
			$lines[] = "KEYWORDS THE SHOP CHOSE FOR THIS PIECE — the first is the focus keyphrase. Every one of them appears word for word in the copy.\n- " . implode( "\n- ", $ctx['keywords'] );
		}
		if ( $ctx['shortlist'] ) {
			$list = [];
			foreach ( $ctx['shortlist'] as $k ) {
				$list[] = '- ' . $k['keyword'] . ' (' . number_format_i18n( $k['volume'] ) . ' searches a month)'
					. ( $k['taken'] ? ' — already another page\'s focus keyphrase: work it in, never aim at it' : '' )
					. ( $k['general'] ? ' — about the shop, not the piece: a supporting keyword at most' : '' );
			}
			$lines[] = "THE SHOP'S KEYWORD LIST — the researched search phrases that fit this piece, best match first:\n" . implode( "\n", $list );
			$lines[] = ( $ctx['keywords'] ? 'From that list, as well as the shop\'s own:' : 'From that list:' ) . "\n"
				. ( $ctx['keywords'] ? '' : "- \"keyphrase\" is the ONE phrase that describes this piece most exactly — a shopper who typed it would be glad to land on this page. Copied exactly as it is written there. Never one marked as another page's focus, never one marked \"about the shop\", and never one that claims something the piece is not: petite, a season it is not for, an occasion it does not suit.\n" )
				. "- \"keywords\" are two to four more from the list that genuinely fit, copied exactly. Each one then has to appear word for word in the copy — the description, the short description or the story — in a sentence that reads naturally. Change nothing about them, not the order of the words, not a plural; if one is awkward, build the sentence around it rather than bending it.\n"
				. '- The list is stored in lower case. In the copy, capitalise its words the way English does — "Korean", "Thai", "K-pop", the first word of a sentence. Capitals are not counted when the copy is checked; the words and their order are.';
		} elseif ( ! $ctx['keywords'] ) {
			$lines[] = "THE SHOP'S KEYWORD LIST has nothing that fits this piece. \"keyphrase\" is then what a shopper would type to find it, two to four words, and it has to appear word for word in the name. \"keywords\" is empty.";
		}

		$taken = self::taken_phrases( $ctx['post_id'], $ctx['facts'] );
		if ( $taken ) {
			$lines[] = "FOCUS KEYPHRASES OTHER PAGES ALREADY USE — two pages aimed at one phrase beat each other:\n- " . implode( "\n- ", $taken );
		}

		return implode( "\n\n", $lines );
	}

	/** The JSON asked for: only the keys for the parts that were ticked, plus the ones every run needs. */
	private static function answer_shape( array $ctx ): string {
		$has  = static fn( $part ) => in_array( $part, $ctx['parts'], true );
		$keys = [
			'"name": "the product name"',
			'"keyphrase": "the focus keyphrase, copied exactly from the keyword list"',
			'"keywords": ["supporting keywords, copied exactly from the keyword list"]',
		];
		if ( $has( 'short' ) ) {
			$keys[] = '"short": "the short description, plain text"';
		}
		if ( $has( 'description' ) ) {
			$keys[] = '"description": "the long description, as HTML"';
		}
		if ( $has( 'story' ) ) {
			$keys[] = '"story": [{"heading": "", "paragraphs": ["", ""], "image": 1}]';
		}
		if ( $has( 'seo' ) ) {
			$keys[] = '"seo_title": ""';
			$keys[] = '"meta_description": ""';
		}
		if ( $has( 'categories' ) ) {
			$keys[] = '"categories": [0]';
		}
		if ( $has( 'size_chart' ) ) {
			$keys[] = '"size_chart": 0';
		}
		if ( $has( 'product_data' ) ) {
			$keys[] = '"attributes": {"Fabric": "", "Fit": ""}';
			$keys[] = '"care": "one sentence, hedged — general advice for this fabric, never a care label you have not read"';
			$keys[] = '"plan": {"type": "simple or variable", "levels": [{"label": "Color", "options": ["Black", "Cream"]}], "price": "", "sale": "", "stock": "", "sku": ""}';
		}
		$keys[] = '"images": [{"alt": "", "title": "", "caption": "", "description": ""}]';
		if ( $ctx['shots'] ) {
			$keys[] = '"shots": [{"brief": ""}]';
		}

		return "{\n" . implode( ",\n", $keys ) . "\n}";
	}

	private static function write_rules( array $ctx ): string {
		$has   = static fn( $part ) => in_array( $part, $ctx['parts'], true );
		$rules = [];

		$rules[] = '"name": 3 to 7 words in Title Case like the shop\'s other names, ending with what the piece is ("… Knit Cardigan"). No brand, no superlatives, no ALL CAPS.';
		$rules[] = '"keyphrase" and "keywords": as the keyword brief above says — from the shop\'s list, copied exactly. Put the keyphrase in the name too when it reads as a natural product name ("Korean Fashion Jacket" can; "Dress Korean Fashion" cannot); a name that reads naturally matters more.';

		$where = [];
		if ( $has( 'short' ) ) {
			$where[] = 'the first sentence of "short"';
		}
		if ( $has( 'seo' ) ) {
			$where[] = '"seo_title", near the start';
			$where[] = '"meta_description"';
		}
		if ( $has( 'description' ) ) {
			$where[] = 'one of the two <h3> in "description"';
		}
		if ( $has( 'story' ) ) {
			$where[] = 'the first story heading or the first story paragraph';
		}
		$where[] = 'the "alt" of picture 1';
		$rules[] = 'The keyphrase appears word for word in ' . implode( ', ', $where ) . '. Past that, at most twice more in all the copy — a page that keeps saying it reads as stuffing.';

		if ( $has( 'short' ) ) {
			$rules[] = '"short": plain text, no HTML. One or two sentences, at most 40 words: what it is and how it sits.';
		}
		if ( $has( 'description' ) ) {
			$rules[] = '"description": HTML, 220 to 300 words: four or five <p>, with two <h3> subheadings between them. No <h1>, no <h2>, no list of specifications THE PIECE does not give. Name the details the way THE PIECE lists them — if it says exactly four buttons, the copy says four buttons or says nothing about how many. A count THE PIECE gives as "at least" is not the whole number, so it is never written as one.';
		}
		if ( $has( 'story' ) ) {
			$rules[] = sprintf(
				'"story": exactly %d sections, in picture order: section 1 sits beside picture 1, section 2 beside picture 2, and so on, and "image" is that picture\'s number. Each has a "heading" (sentence case, 3 to 8 words) and one or two "paragraphs" of 40 to 80 words in all, plain text. Write each section about what its picture shows — the back view\'s section about the back, the close-up\'s about that detail. The story is about wearing it: a real week, what it goes with, how it moves, when to size up. It never repeats a sentence from the description.',
				$ctx['sections']
			);
		}
		if ( $has( 'seo' ) ) {
			$rules[] = '"seo_title": 30 to 60 characters, starting with the keyphrase or the name, written as one phrase — no pipes or dashes stringing keywords together, and no shop name: the site adds it.';
			$rules[] = '"meta_description": 120 to 155 characters, with the keyphrase in it: what it is and who it suits.';
		}
		if ( $has( 'categories' ) ) {
			$rules[] = '"categories": ids from the CATEGORIES list only.';
		}
		if ( $has( 'size_chart' ) ) {
			$rules[] = '"size_chart": an id from the SIZE CHARTS list, or 0.';
		}
		if ( $has( 'product_data' ) ) {
			$rules[] = '"attributes": two to four plain facts a shopper would filter on — Fabric, Fit, Neckline, Sleeve — each a few words, from THE PIECE and the shop\'s words. No attribute for anything listed in "plan" as a level.';
			$rules[] = '"plan" is how the product is built, and it is where the shop\'s instructions are obeyed. "variable" only when the shop\'s words name options to choose between — colours, sizes, lengths; otherwise "simple" and "levels" is empty. With no shop\'s words at all, "levels" is empty: the colour in the photograph is a fact about the piece, not a choice a shopper makes. A level needs two or more options; at most three levels, in the order a shopper picks them — colour first, then size. Use the shop\'s own words for each option and do not invent sizes or colours that were not asked for. price, sale, stock and sku: only when the shop\'s words give them — an invented price is worse than none.';
		}
		$rules[] = sprintf( '"images": exactly %d entries, one per picture in THE PICTURES, in the same order. "alt" says what that picture shows, for somebody who cannot see it; picture 1\'s alt contains the product name.', $ctx['total'] );
		if ( $ctx['shots'] ) {
			$rules[] = sprintf(
				'"shots": exactly %d entries, one per new picture, in order. "brief" is two or three sentences for the photographer: the view (front, back, side, three-quarter, close-up), the framing (full length, waist up, detail) and the pose or setting. Where the shop asked for something, the brief does exactly that. Where nothing was asked, choose what the set is missing — a back view when no picture shows the back, a close-up of the detail that matters most, a full-length look at the whole outfit — and never two new pictures that show the same thing. When the photographs show more than one colourway, every brief names the one its picture shows, and the new pictures take turns between them unless the shop asked otherwise. Never ask for a detail THE PIECE does not have.',
				count( $ctx['shots'] )
			);
		}
		$rules[] = 'Short sentences, most under 20 words, and join some of them with and, but, so, because or when — it should read as prose, not as a list.';
		$rules[] = 'Never state a measurement, a delivery time, a shipping promise, a discount, a stock figure, a review count, a brand or a person\'s name. Never say where it was made unless the shop said so.';
		$rules[] = 'Write as the shop, to one person, the way the house rules say.';

		return '- ' . implode( "\n- ", $rules );
	}

	/** "1. (already on the product) front, on a model, full length" — one line per picture the page will have. */
	private static function picture_list( array $ctx ): string {
		$views = [];
		foreach ( (array) ( $ctx['facts']['views'] ?? [] ) as $view ) {
			$views[ (int) ( $view['image'] ?? 0 ) ] = (string) ( $view['shows'] ?? '' );
		}
		$lines = [];
		for ( $i = 1; $i <= $ctx['have']; $i++ ) {
			$lines[] = sprintf( '%d. (a photograph the shop took) %s', $i, $views[ $i ] ?? 'another view of the piece' );
		}
		foreach ( $ctx['shots'] as $j => $shot ) {
			$lines[] = sprintf(
				'%d. (new, to be drawn — %s) %s',
				$ctx['have'] + $j + 1,
				'mannequin' === $shot['mode'] ? 'ghost mannequin, no person' : 'worn by a model',
				'' !== $shot['ask'] ? 'the shop asked for: "' . $shot['ask'] . '"' : 'nothing asked — you choose'
			);
		}
		return implode( "\n", $lines );
	}

	/**
	 * Tidy the reply into something every later step can trust.
	 *
	 * Ids that are not categories, a chart that does not exist, a keyphrase the name does not
	 * contain, seven story sections when four were asked for: each is put right or dropped here, so
	 * nothing downstream has to wonder.
	 */
	private static function settle_draft( array $raw, array $ctx ): array {
		$has   = static fn( $part ) => in_array( $part, $ctx['parts'], true );
		$draft = [];

		$draft['name']      = '' !== $ctx['fixed'] ? $ctx['fixed'] : self::tidy_name( (string) ( $raw['name'] ?? '' ) );
		$draft = array_merge( $draft, self::aim( $raw, $draft['name'], $ctx ) );

		if ( $has( 'short' ) ) {
			$draft['short'] = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) ( $raw['short'] ?? '' ) ) ) );
		}
		if ( $has( 'description' ) ) {
			$draft['description'] = self::description_html( (string) ( $raw['description'] ?? '' ) );
		}
		if ( $has( 'story' ) ) {
			$draft['story'] = self::story_sections( $raw['story'] ?? [], $ctx['sections'], $ctx['total'] );
		}
		if ( $has( 'seo' ) ) {
			$draft['seo_title']        = self::fit( (string) ( $raw['seo_title'] ?? '' ), 60 ) ?: self::fit( $draft['name'], 60 );
			$draft['meta_description'] = self::fit( (string) ( $raw['meta_description'] ?? '' ), 156 );
		}
		if ( $has( 'categories' ) ) {
			$draft['categories'] = self::category_ids( $raw['categories'] ?? [] );
		}
		if ( $has( 'size_chart' ) ) {
			$draft['size_chart'] = self::chart_id( $raw['size_chart'] ?? 0 );
		}
		if ( $has( 'product_data' ) ) {
			$attributes = [];
			foreach ( (array) ( $raw['attributes'] ?? [] ) as $label => $value ) {
				$label = sanitize_text_field( (string) $label );
				$value = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
				if ( '' !== $label && '' !== $value && count( $attributes ) < 6 ) {
					$attributes[ mb_convert_case( $label, MB_CASE_TITLE, 'UTF-8' ) ] = $value;
				}
			}
			$care = sanitize_text_field( (string) ( $raw['care'] ?? '' ) );
			if ( '' !== $care ) {
				$attributes[ __( 'Care', 'whd' ) ] = $care;
			}
			$draft['attributes'] = $attributes;
			$draft['plan']       = self::plan( (array) ( $raw['plan'] ?? [] ), $ctx['commands'] );
		}

		$draft['images'] = self::image_words( (array) ( $raw['images'] ?? [] ), $ctx['total'], $draft['name'] );

		/* The list is lower case and the copy carries it word for word, so "this korean fashion
		   jacket" came back however the model was asked. Proper words are put right here instead. */
		foreach ( [ 'short', 'description', 'seo_title', 'meta_description' ] as $field ) {
			if ( isset( $draft[ $field ] ) ) {
				$draft[ $field ] = self::proper( $draft[ $field ] );
			}
		}
		foreach ( (array) ( $draft['story'] ?? [] ) as $n => $section ) {
			$draft['story'][ $n ]['heading']    = self::proper( $section['heading'] );
			$draft['story'][ $n ]['paragraphs'] = array_map( [ __CLASS__, 'proper' ], $section['paragraphs'] );
		}
		foreach ( $draft['images'] as $n => $words ) {
			$draft['images'][ $n ] = array_map( [ __CLASS__, 'proper' ], $words );
		}
		$draft['shots']  = [];
		foreach ( $ctx['shots'] as $j => $shot ) {
			$brief = sanitize_textarea_field( (string) ( $raw['shots'][ $j ]['brief'] ?? '' ) );
			if ( '' === $brief ) {
				$brief = '' !== $shot['ask'] ? $shot['ask'] : ( 'mannequin' === $shot['mode'] ? 'Front view of the whole piece.' : 'Full length, worn, a relaxed everyday moment.' );
			}
			$shot['brief']    = mb_substr( $brief, 0, 1200 );
			$draft['shots'][] = $shot;
		}

		return $draft;
	}

	/**
	 * What is still wrong with a draft, in words the model can act on.
	 *
	 * The house check (banned words, keywords) and the same SEO analysis the score box runs, limited
	 * to the checks this draft can actually move. A web address is not something copy can fix.
	 *
	 * @return string[]
	 */
	private static function problems( array $draft, array $ctx ): array {
		$has    = static fn( $part ) => in_array( $part, $ctx['parts'], true );
		$issues = [];

		$copy = implode( "\n", array_filter( [
			(string) ( $draft['short'] ?? '' ),
			(string) ( $draft['description'] ?? '' ),
			self::story_text( (array) ( $draft['story'] ?? [] ) ),
		] ) );
		if ( '' !== trim( $copy ) ) {
			/*
			 * Every keyword the page is aimed at has to be in the words a shopper reads — whatever the
			 * "word for word" setting says, because these were chosen from the list to be used, not
			 * offered as ideas. The focus keyphrase is checked here too, not only by the SEO score.
			 */
			$aimed = array_values( array_filter( array_merge( [ (string) ( $draft['keyphrase'] ?? '' ) ], (array) ( $draft['keywords'] ?? [] ) ) ) );
			$check = WHD_AI::check( $copy, $aimed );
			foreach ( $check['issues'] as $issue ) {
				if ( in_array( $issue['type'], [ 'banned', 'structure' ], true ) ) {
					$issues[] = $issue['detail'];
				}
			}
			foreach ( $check['keywords'] as $keyword => $found ) {
				if ( ! $found ) {
					$issues[] = sprintf( 'The keyword "%s" from the shop\'s keyword list does not appear word for word in the description, the short description or the story. Add a sentence that contains it exactly.', $keyword );
				}
			}
		}
		$labels = implode( "\n", array_filter( [ $draft['name'], (string) ( $draft['seo_title'] ?? '' ), (string) ( $draft['meta_description'] ?? '' ) ] ) );
		foreach ( WHD_AI::check( $labels )['issues'] as $issue ) {
			if ( 'banned' === $issue['type'] ) {
				$issues[] = $issue['detail'] . ' (in the name or the search snippet)';
			}
		}

		if ( '' === $draft['name'] ) {
			$issues[] = 'There is no "name".';
		}
		if ( $has( 'description' ) ) {
			$words = str_word_count( wp_strip_all_tags( $draft['description'] ) );
			if ( $words < 200 ) {
				$issues[] = sprintf( 'The description is %d words; it needs 220 to 300.', $words );
			}
			if ( substr_count( strtolower( $draft['description'] ), '<h3' ) < 2 ) {
				$issues[] = 'The description needs two <h3> subheadings.';
			}
		}
		if ( $has( 'story' ) && count( $draft['story'] ) < $ctx['sections'] ) {
			$issues[] = sprintf( 'The story has %1$d sections; it needs exactly %2$d, one beside each picture.', count( $draft['story'] ), $ctx['sections'] );
		}
		if ( $has( 'short' ) && '' === $draft['short'] ) {
			$issues[] = 'There is no "short".';
		}

		if ( class_exists( 'WHD_SEO' ) && '' !== $draft['keyphrase'] ) {
			$short = $has( 'short' ) ? $draft['short'] : $ctx['current']['short'];
			$long  = $has( 'description' ) ? $draft['description'] : $ctx['current']['description_html'];
			$doc   = [
				'post_id'          => 0,
				'post_type'        => 'product',
				'title'            => $draft['name'],
				'content'          => ( '' !== trim( $short ) ? '<p>' . esc_html( $short ) . '</p>' : '' ) . $long,
				'slug'             => sanitize_title( $draft['name'] ),
				'meta_description' => $has( 'seo' ) ? $draft['meta_description'] : '',
				'seo_title'        => $has( 'seo' ) ? $draft['seo_title'] : '',
				'keyphrase'        => $draft['keyphrase'],
			];
			/* check id => [ the parts that can move it, whether "could be better" counts too ] */
			$movable = [
				'key_title'   => [ [ 'seo', 'name' ], false ],
				'title'       => [ [ 'seo' ], true ],
				'key_meta'    => [ [ 'seo' ], false ],
				'meta'        => [ [ 'seo' ], true ],
				'key_first'   => [ [ 'short', 'description' ], false ],
				'key_heading' => [ [ 'description' ], true ],
				'density'     => [ [ 'description', 'short' ], true ],
				'length'      => [ [ 'description' ], true ],
				'spread'      => [ [ 'description' ], false ],
				'para'        => [ [ 'description' ], true ],
				'sentence'    => [ [ 'description', 'short' ], true ],
				'ease'        => [ [ 'description', 'short' ], false ],
				'claims'      => [ [ 'description', 'short' ], false ],
			];
			$analysis = WHD_SEO::analyse( $doc );
			foreach ( (array) ( $analysis['groups'] ?? [] ) as $group ) {
				foreach ( (array) $group['checks'] as $check ) {
					$rule = $movable[ $check['id'] ] ?? null;
					if ( ! $rule || ! array_intersect( $rule[0], $ctx['parts'] ) ) {
						continue;
					}
					if ( 'bad' === $check['status'] || ( $rule[1] && 'ok' === $check['status'] ) ) {
						$issues[] = 'SEO: ' . wp_strip_all_tags( $check['text'] );
					}
				}
			}
		}

		return array_values( array_unique( $issues ) );
	}

	/**
	 * What the screen should hold once this is placed: one entry per field the browser fills in.
	 */
	private static function screen( int $post_id, array $draft, array $ctx ): array {
		$has    = static fn( $part ) => in_array( $part, $ctx['parts'], true );
		$screen = [];

		if ( $has( 'name' ) && '' !== $draft['name'] ) {
			$screen['title'] = $draft['name'];
			$screen['slug']  = self::slug_for( $draft['name'], (string) $draft['keyphrase'] );
		}
		if ( $has( 'description' ) && '' !== $draft['description'] ) {
			$screen['description'] = $draft['description'];
		}
		if ( $has( 'short' ) && '' !== $draft['short'] ) {
			$screen['short'] = '<p>' . esc_html( $draft['short'] ) . '</p>';
		}
		if ( $has( 'categories' ) && $draft['categories'] ) {
			$screen['categories'] = $draft['categories'];
			$screen['category_names'] = array_values( array_filter( array_map( static function ( $id ) {
				$term = get_term( $id, 'product_cat' );
				return $term && ! is_wp_error( $term ) ? html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) : '';
			}, $draft['categories'] ) ) );
		}
		if ( $has( 'size_chart' ) ) {
			$screen['size_chart'] = $draft['size_chart'];
			$screen['size_chart_name'] = $draft['size_chart'] ? html_entity_decode( get_the_title( $draft['size_chart'] ), ENT_QUOTES, 'UTF-8' ) : '';
		}
		if ( $has( 'seo' ) ) {
			$screen['seo'] = [
				'title'       => $draft['seo_title'],
				'description' => $draft['meta_description'],
				'keyphrase'   => $draft['keyphrase'],
			];
		}
		if ( $has( 'product_data' ) ) {
			$plan  = $draft['plan'];
			$tiers = self::tiers( $plan, $post_id );
			$skip  = array_map( [ __CLASS__, 'label_key' ], wp_list_pluck( $tiers['levels'] ?? [], 'label' ) );

			$screen['attributes'] = array_filter(
				$draft['attributes'],
				static fn( $label ) => ! in_array( self::label_key( (string) $label ), $skip, true ),
				ARRAY_FILTER_USE_KEY
			);
			$screen['price'] = array_filter( [
				'regular' => $plan['price'],
				'sale'    => $plan['sale'],
				'stock'   => $plan['stock'],
				'sku'     => $plan['sku'],
			], static fn( $v ) => '' !== $v );
			if ( $tiers ) {
				$screen['tiers']       = $tiers;
				$screen['tier_labels'] = wp_list_pluck( $tiers['levels'], 'label' );
				$screen['variations']  = count( $tiers['combos'] );
			}
		}

		return $screen;
	}

	/* ─────────────────────────── step four: draw, and check ─────────────────────────── */

	/**
	 * Draw one new picture of the piece and put it in the media library.
	 *
	 * The references are the chosen photographs, whole, and the close-ups of the details that most
	 * need to come out right — so the model is copying the real buttons, not a description of them.
	 * The checked facts go in the brief as well, line by line, with the things the piece does not
	 * have spelled out: an image model's commonest mistake on clothes is adding a pocket.
	 *
	 * @param array $args images, facts, shot { mode, ask, brief }, position (1-based in the set), name, fixes[].
	 * @return array|WP_Error { id, url, thumb, large }
	 */
	public static function draw( int $post_id, array $args ) {
		if ( ! class_exists( 'WHD_AI_Images' ) || ! WHD_AI_Images::ready() ) {
			return new WP_Error( 'whd_studio_no_draw', __( 'Drawing pictures needs a Google AI key. Add one in WHD → AI content.', 'whd' ) );
		}
		$refs = self::image_ids( $args['images'] ?? [] );
		if ( ! $refs ) {
			return new WP_Error( 'whd_studio_no_refs', __( 'There is no photograph of the piece to draw it from.', 'whd' ) );
		}
		$read     = self::image_ids( $args['facts']['image_ids'] ?? $refs );
		$facts    = self::tidy_facts( (array) ( $args['facts'] ?? [] ), count( $read ) );
		$shot     = self::shots( [ $args['shot'] ?? [] ] )[0];
		$position = max( 1, (int) ( $args['position'] ?? 1 ) );
		$name     = self::tidy_name( (string) ( $args['name'] ?? '' ) );
		$fixes    = array_slice( self::strings_of( $args['fixes'] ?? [] ), 0, 12 );
		if ( '' === $name ) {
			$product = wc_get_product( $post_id );
			$name    = $product ? self::tidy_name( $product->get_name() ) : '';
		}

		/*
		 * Which colourway this picture is of, when the photographs show more than one: its own
		 * photographs go first, and the others are labelled as being there for the cut only. Given
		 * both with no word about which, the model averages them into a colour neither one is.
		 */
		$own = self::colourway_for( $facts, $shot );
		if ( $own ) {
			$first = [];
			foreach ( $own['images'] as $n ) {
				if ( isset( $read[ $n - 1 ] ) ) {
					$first[] = $read[ $n - 1 ];
				}
			}
			$refs = array_values( array_unique( array_merge( $first, $refs ) ) );
		}
		$other = static function ( $id ) use ( $own, $read ) {
			$n = array_search( $id, $read, true );
			return $own && false !== $n && ! in_array( $n + 1, $own['images'], true );
		};

		/* Gemini's Pro image model reads up to fourteen references well; the Flash ones are best kept to a handful. */
		$room  = false !== strpos( WHD_AI_Images::model(), 'pro' ) ? 8 : 5;
		$whole = array_slice( $refs, 0, min( 3, $room - 1 ) );
		$close = self::crops( $read, $facts['regions'], max( 0, $room - count( $whole ) ) );

		$parts  = [];
		$labels = [];
		foreach ( $whole as $id ) {
			$part = WHD_AI::picture_part( $id, self::REF_EDGE );
			if ( $part ) {
				$parts[]  = [ 'inlineData' => [ 'mimeType' => $part['mime'], 'data' => $part['data'] ] ];
				$labels[] = $other( $id )
					? sprintf( 'Image %d: the same piece in another colourway — copy its cut and details, never its colours.', count( $parts ) )
					: sprintf( 'Image %d: a photograph of the real product, whole%s.', count( $parts ), $own ? ', in the colourway to show' : '' );
			}
		}
		foreach ( $close as $crop ) {
			$part = WHD_AI::picture_part( $crop['path'], self::CROP_EDGE );
			if ( $part ) {
				$parts[]  = [ 'inlineData' => [ 'mimeType' => $part['mime'], 'data' => $part['data'] ] ];
				$labels[] = sprintf(
					'Image %d: a close-up of the real product — %s%s.',
					count( $parts ),
					$crop['label'],
					$own && ! in_array( $crop['image'], $own['images'], true ) ? ' (another colourway: for the detail only)' : ''
				);
			}
		}
		self::forget( $close );
		if ( ! $parts ) {
			return new WP_Error( 'whd_studio_refs_unreadable', __( 'None of the chosen photographs could be read from disk.', 'whd' ) );
		}

		$parts[] = [ 'text' => self::draw_prompt( $facts, $shot, $position, $post_id, $labels, $fixes, $refs[0] ) ];

		self::mark( 'drawing a picture with the image model' );
		$image = WHD_AI_Images::request( $parts, [
			'aspectRatio' => self::aspect( $refs[0] ),
			'imageSize'   => WHD_AI_Images::size(),
		] );
		if ( is_wp_error( $image ) ) {
			return $image;
		}

		$id = self::store( $image, $post_id, $name, $shot, $position );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return [
			'id'    => $id,
			'url'   => wp_get_attachment_image_url( $id, 'medium' ),
			'thumb' => wp_get_attachment_image_url( $id, 'thumbnail' ),
			'large' => wp_get_attachment_image_url( $id, 'large' ),
		];
	}

	private static function draw_prompt( array $facts, array $shot, int $position, int $post_id, array $labels, array $fixes, int $first ): string {
		$beauty = 'beauty' === strtolower( (string) ( $facts['kind'] ?? '' ) );
		$worn   = in_array( strtolower( (string) ( $facts['kind'] ?? 'garment' ) ), [ 'garment', 'footwear', 'jewellery', 'accessory', 'bag', '' ], true );
		$ratio  = self::aspect( $first );
		$out    = [];

		$out[] = 'A new photograph of a product we sell, for its page in our online shop, made from the reference images attached above.';
		$out[] = "THE REFERENCES\n" . implode( "\n", $labels ) . "\nThe references are the truth about the product. Wherever this brief and the references seem to disagree about the product itself, the references win.";
		$out[] = "THE PRODUCT, EXACTLY — every point here is checked when the picture comes back\n" . self::spec( $facts )
			. "\n- Same length, same proportions, same neckline, same sleeve length and same hem as the references."
			. "\n- Colours, tones and the pattern are copied from the reference photographs, not from their names in this brief. The names are approximate; the photographs are exact. Match how saturated or muted the colours are, the scale of the pattern and the way it runs.";
		if ( $fixes ) {
			$out[] = "THE LAST ATTEMPT GOT THESE WRONG — put every one of them right:\n- " . implode( "\n- ", $fixes );
		}

		$out[] = "THIS PICTURE\n" . $shot['brief'] . ( '' !== $shot['ask'] ? "\nAsked for by the shop, and it wins over anything else here about the picture: \"" . $shot['ask'] . '"' : '' )
			. ( count( self::colourways( $facts['colourways'] ?? [] ) ) > 1 ? "\nThe references show more than one colourway. Show only the one named above, copied from the reference that shows it." : '' );

		if ( 'mannequin' === $shot['mode'] ) {
			$out[] = $worn
				? "HOW IT IS SHOWN\nA ghost-mannequin product photograph: the piece as if worn by an invisible body — natural shape and volume, the inside of the back neckline visible through the neck opening, sleeves filled out, fastenings done up the way the references show them. No person, no mannequin, no stand, no hanger, no props. The whole piece in frame and centred; front view unless THIS PICTURE asks for another angle. A seamless warm off-white background, soft even studio light, a soft shadow beneath."
				: "HOW IT IS SHOWN\nA clean product photograph: the item on its own, no hands, no person, centred on a seamless warm off-white background, soft even studio light, a soft shadow beneath, the label side turned slightly so it reads as shape and colour.";
		} else {
			$people = WHD_AI_Images::people();
			$scenes = WHD_AI_Images::scenes();
			$person = $people[ ( $position + $post_id ) % count( $people ) ];
			$scene  = $scenes[ $position % count( $scenes ) ];
			$out[]  = "WHO\n" . $person . "\n\nWHERE, unless THIS PICTURE says otherwise\n" . $scene;
			$out[]  = "HOW IT IS SHOWN\n" . ( $beauty
				? 'Show her using the product the way somebody actually would — holding it, or smoothing it into her skin — with the packaging the same shape and the same colours as the references, held at a slight angle so the label reads as shape and colour rather than as words.'
				: 'Show her wearing the product from the references. It must be the same piece: the same colour, the same pattern at the same scale, the same length, neckline, collar, sleeves and cuffs, the same fastenings in the same number, the same trims. The rest of the outfit is plain and quiet, and nothing she wears or holds covers the details listed above.' );
			$out[]  = "THE LOOK\n" . WHD_AI_Images::look();
		}

		$rules = [
			sprintf( 'one single photograph, aspect ratio %s, nothing split or collaged', $ratio ),
			'nothing added to the product that the references do not show, and nothing left off that they do',
			'no added text, lettering, logo, watermark or border — but any print or lettering that is part of the product itself stays exactly as the references show it',
			'seams, hands and faces intact; nothing warped, melted or doubled',
			'a real photograph, not a rendering or an illustration',
		];
		if ( 'mannequin' !== $shot['mode'] ) {
			array_splice( $rules, 1, 0, [
				'a different person from anyone in the references: a different face, different hair',
				WHD_AI_Images::age_rule(),
				'relaxed and unposed, not looking into the lens',
				'her whole head inside the frame — the top of the hair included, the face not cut by any edge — unless THIS PICTURE asks for a close-up',
			] );
		}
		$out[] = "RULES\n- " . implode( "\n- ", $rules );

		return implode( "\n\n", $out );
	}

	/**
	 * Read a drawn picture back against the real thing.
	 *
	 * The same inspector that counted the buttons on the photographs counts them on the drawing,
	 * with the photographs and the close-ups beside it — so "four buttons" is checked against four
	 * real buttons, not against the drawing's own idea of what a cardigan has.
	 *
	 * @return array|WP_Error { ok, score (0–10), problems[], counts }
	 */
	public static function check( int $post_id, array $args ) {
		$image = absint( $args['image'] ?? 0 );
		if ( ! $image || ! wp_attachment_is_image( $image ) ) {
			return new WP_Error( 'whd_studio_no_image', __( 'There is no picture to check.', 'whd' ) );
		}
		$refs  = self::image_ids( $args['images'] ?? [] );
		$read  = self::image_ids( $args['facts']['image_ids'] ?? $refs );
		$facts = self::tidy_facts( (array) ( $args['facts'] ?? [] ), count( $read ) );
		$shot  = self::shots( [ $args['shot'] ?? [] ] )[0];
		$own   = self::colourway_for( $facts, $shot );
		if ( $own ) {
			$first = array_values( array_filter( array_map( static fn( $n ) => $read[ $n - 1 ] ?? 0, $own['images'] ) ) );
			$refs  = array_values( array_unique( array_merge( $first, $refs ) ) );
		}
		$whole = array_slice( $refs, 0, 2 );
		$close = self::crops( $read, $facts['regions'], 2 );

		$order = [];
		foreach ( $whole as $i => $id ) {
			$n       = array_search( $id, $read, true );
			$order[] = $own && false !== $n && ! in_array( $n + 1, $own['images'], true )
				? sprintf( '%d. The real product in another colourway — compare the cut and details, not the colour.', $i + 1 )
				: sprintf( '%d. A photograph of the real product%s.', $i + 1, $own ? ', in the colourway the drawing should show' : '' );
		}
		foreach ( $close as $crop ) {
			$order[] = sprintf( '%d. A close-up of the real product: %s.', count( $order ) + 1, $crop['label'] );
		}
		$drawn   = count( $order ) + 1;
		$order[] = sprintf( '%d. THE DRAWN PICTURE — the one to check.', $drawn );

		$user = "A picture was drawn by an image model to show the product in the real photographs. Check it the way you would before it goes on the shop.\n\n"
			. "ATTACHED, IN ORDER\n" . implode( "\n", $order ) . "\n\n"
			. "THE PRODUCT, EXACTLY\n" . self::spec( $facts ) . "\n\n"
			. 'THE PICTURE WAS MEANT TO BE ' . ( 'mannequin' === $shot['mode'] ? 'a ghost-mannequin shot with no person in it' : 'the product worn or used by a model' ) . ': ' . $shot['brief'] . "\n\n"
			. sprintf( "Check picture %d against the real product, detail by detail:\n", $drawn )
			. "- count every countable above that is visible in it, one at a time\n"
			. "- colour, pattern and its scale, how the fabric looks, length, neckline, collar, sleeves, cuffs, hem, fastenings, trims\n"
			. "- anything added that the real product does not have: a pocket, a belt, extra buttons, a logo, lettering, a second layer\n"
			. "- anything the real product has that is missing where it should be visible\n"
			. "- the picture itself: warped hands or faces, extra fingers or limbs, a head cut off on a model shot, garbled text, a person or a mannequin in a ghost-mannequin shot\n"
			. "Something hidden by the pose, the angle or the crop is not a problem. Only what can be seen and is wrong. A detail given as \"at least\" has no exact number to match: it is wrong only when the drawing clearly shows fewer, or a different size or spacing from the real one.\n"
			. "When the real photographs show more than one colourway, the drawing must match the one its brief names, and be one colourway, not a mix.\n\n"
			. "Answer with JSON only:\n"
			. '{"counts": {"front buttons": 4}, "problems": ["each one specific, e.g. shows five front buttons; the product has four"], "score": 0, "ok": true}' . "\n"
			. '"score" is 0 to 10, where 10 means a shopper could not tell it from the real product. "ok" is false when any problem would mislead a shopper about the product or makes the picture unusable; a difference in the light or the styling is not a problem.';

		self::mark( 'checking a drawn picture with the model' );
		$reply = WHD_AI::call( self::inspector(), $user, [
			'json'        => true,
			'images'      => array_merge( $whole, wp_list_pluck( $close, 'path' ), [ $image ] ),
			'max_tokens'  => 6000,
			'timeout'     => self::MODEL_WAIT,
			'vision_edge' => 1280,
			'detail'      => 'high',
		] );
		self::forget( $close );
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		$verdict = self::decode( $reply );
		if ( ! is_array( $verdict ) ) {
			return new WP_Error( 'whd_studio_check_unreadable', __( 'The check came back unreadable.', 'whd' ) );
		}
		$problems = array_slice( self::strings_of( $verdict['problems'] ?? [] ), 0, 10 );
		$score    = max( 0, min( 10, (int) ( $verdict['score'] ?? 0 ) ) );

		/* A pass with a quibble attached still passes, if the inspector rated it close to the real thing. */
		return [
			'ok'       => ! empty( $verdict['ok'] ) && ( ! $problems || $score >= 8 ),
			'score'    => $score,
			'problems' => $problems,
		];
	}

	/**
	 * Throw away a drawing that lost to a better attempt. Only ever one this panel made, on this
	 * product — or, for Image Builder (no product, post 0), one this same person drew.
	 */
	public static function discard( int $post_id, int $id ): bool {
		if ( ! $id || ! get_post_meta( $id, WHD_AI_Images::META, true ) || (int) get_post_field( 'post_parent', $id ) !== $post_id ) {
			return false;
		}
		if ( 0 === $post_id && (int) get_post_field( 'post_author', $id ) !== get_current_user_id() ) {
			return false;
		}
		if ( ! current_user_can( 'delete_post', $id ) ) {
			return false;
		}
		return (bool) wp_delete_attachment( $id, true );
	}

	/** Into the media library, attached to the product, marked as drawn. */
	private static function store( array $image, int $post_id, string $name, array $shot, int $position ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$ext  = 'image/png' === $image['mime'] ? 'png' : 'jpg';
		$stem = sanitize_title( $name ) ?: 'product';
		$file = wp_upload_bits( sanitize_file_name( $stem . '-' . $position . '-' . wp_generate_password( 4, false, false ) . '.' . $ext ), null, $image['data'] );
		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'whd_studio_write', $file['error'] );
		}

		$what = wp_trim_words( wp_strip_all_tags( $shot['brief'] ), 14, '' );
		$alt  = trim( ( $name ? $name . ', ' : '' ) . lcfirst( rtrim( $what, '.' ) ), ', ' );

		$id = wp_insert_attachment( [
			'post_mime_type' => $image['mime'],
			'post_title'     => $name ?: $stem,
			'post_status'    => 'inherit',
		], $file['file'], $post_id );
		if ( is_wp_error( $id ) || ! $id ) {
			return is_wp_error( $id ) ? $id : new WP_Error( 'whd_studio_attach', __( 'The picture could not be added to the media library.', 'whd' ) );
		}

		self::mark( 'saving the drawn picture to the media library' );
		WHD_AI_Images::settle( (int) $id, $file['file'], $alt );
		update_post_meta( $id, WHD_AI_Images::META, 1 );
		update_post_meta( $id, '_whd_ai_shot', $shot['mode'] );

		return (int) $id;
	}

	/** The supported ratio nearest the first photograph's, so a drawn picture sits in the gallery like the rest. */
	private static function aspect( int $id ): string {
		$meta = wp_get_attachment_metadata( $id );
		$w    = (int) ( $meta['width'] ?? 0 );
		$h    = (int) ( $meta['height'] ?? 0 );
		if ( ! $w || ! $h ) {
			return '3:4';
		}
		$want = log( $w / $h );
		$best = '3:4';
		$gap  = INF;
		foreach ( self::RATIOS as $ratio => $value ) {
			$d = abs( log( $value ) - $want );
			if ( $d < $gap ) {
				$gap  = $d;
				$best = $ratio;
			}
		}
		return $best;
	}

	/* ─────────────────────────── step five: finish ─────────────────────────── */

	/**
	 * The two things with no field on this screen: the pictures' own words, and the story.
	 *
	 * @param array $args parts, draft { name, keyphrase, images[], story[], used[] }, images [ { id, slot } ].
	 * @return array|WP_Error
	 */
	public static function finish( int $post_id, array $args ) {
		$parts = self::parts( $args['parts'] ?? [] );
		$draft = (array) ( $args['draft'] ?? [] );
		$name  = self::tidy_name( (string) ( $draft['name'] ?? '' ) );
		if ( '' === $name ) {
			$product = wc_get_product( $post_id );
			$name    = $product ? self::tidy_name( $product->get_name() ) : '';
		}

		$finals = [];
		foreach ( (array) ( $args['images'] ?? [] ) as $entry ) {
			$id = absint( is_array( $entry ) ? ( $entry['id'] ?? 0 ) : $entry );
			if ( $id && wp_attachment_is_image( $id ) && ! isset( $finals[ $id ] ) ) {
				$finals[ $id ] = max( 0, (int) ( is_array( $entry ) ? ( $entry['slot'] ?? count( $finals ) ) : count( $finals ) ) );
			}
		}

		/* Alt text, title, caption and description on every picture. With the search snippet ticked
		   they are rewritten; without it only the empty ones are filled. */
		$words   = (array) ( $draft['images'] ?? [] );
		$rewrite = in_array( 'seo', $parts, true );
		$dressed = 0;
		$first   = true;
		self::remember_pictures( $post_id, array_keys( $finals ) );
		foreach ( $finals as $id => $slot ) {
			$dressed += (int) self::dress( $id, is_array( $words[ $slot ] ?? null ) ? $words[ $slot ] : [], $name, $first, $rewrite, $post_id );
			$first    = false;
		}

		$story = 0;
		if ( in_array( 'story', $parts, true ) && class_exists( 'WHD_Product_Story' ) && ! empty( $draft['story'] ) ) {
			$story = self::save_story( $post_id, self::story_sections( $draft['story'], 8, 12 ), $finals );
		}

		if ( class_exists( 'WHD_AI_Keywords' ) && ! empty( $draft['used'] ) ) {
			WHD_AI_Keywords::mark_used( WHD_AI::clean_keywords( (array) $draft['used'] ) );
		}

		return [ 'dressed' => $dressed, 'story' => $story ];
	}

	/**
	 * The story as blocks: a heading, its copy, and the picture it was written beside.
	 *
	 * The section names its picture by number in the planned set; a picture that could not be drawn
	 * leaves a gap, which is given the next picture nobody else is using rather than left empty.
	 *
	 * @param array $finals attachment id => planned slot (0-based).
	 * @return int Sections saved.
	 */
	private static function save_story( int $post_id, array $sections, array $finals ): int {
		if ( ! $sections ) {
			return 0;
		}
		$by_slot = array_flip( $finals );
		$spare   = array_keys( $finals );
		$used    = [];
		$blocks  = [];

		foreach ( $sections as $section ) {
			$id = $by_slot[ (int) $section['image'] - 1 ] ?? 0;
			if ( ! $id || isset( $used[ $id ] ) ) {
				$id = 0;
				foreach ( $spare as $candidate ) {
					if ( ! isset( $used[ $candidate ] ) ) {
						$id = $candidate;
						break;
					}
				}
			}

			$blocks[] = [
				'type'  => 'heading',
				'props' => [ 'text' => $section['heading'], 'level' => 'h2', 'align' => 'left' ],
			];
			$blocks[] = [
				'type'  => 'text',
				'props' => [
					'text'  => implode( "\n", array_map( static fn( $p ) => '<p>' . esc_html( $p ) . '</p>', $section['paragraphs'] ) ),
					'align' => 'left',
					'size'  => 17,
				],
			];
			if ( $id ) {
				$used[ $id ] = true;
				$blocks[]    = [
					'type'  => 'image',
					'props' => [
						'url'   => (string) wp_get_attachment_image_url( $id, 'large' ),
						'alt'   => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
						'width' => 100,
					],
				];
			}
		}

		$design           = WHD_Product_Story::get( $post_id );
		$design['blocks'] = $blocks;
		WHD_Product_Story::save( $post_id, $design );
		update_post_meta( $post_id, WHD_Product_Story::META . '_off', '1' ); // "_off" = 1 reads as on; see WHD_Product_Story::enabled()

		return count( $sections );
	}

	/** Is this picture the main image or in the gallery of any product but this one (or its variations)? */
	private static function shared( int $id, int $post_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- two indexed reads per picture, on a button press
		$users = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
			 WHERE post_id <> %d AND ( ( meta_key = '_thumbnail_id' AND meta_value = %d )
			    OR ( meta_key = '_product_image_gallery' AND FIND_IN_SET( %d, meta_value ) ) )",
			$post_id,
			$id,
			$id
		) );
		foreach ( (array) $users as $user ) {
			$owner = get_post( (int) $user );
			if ( $owner && (int) $owner->post_parent !== $post_id && 'revision' !== $owner->post_type ) {
				return true;
			}
		}
		return false;
	}

	/** One picture's alt, title, caption and description. */
	private static function dress( int $id, array $w, string $name, bool $first, bool $rewrite, int $post_id = 0 ): bool {
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return false;
		}
		$drawn = (bool) get_post_meta( $id, WHD_AI_Images::META, true );
		/*
		 * A photograph another product also shows keeps its words, and only its empty fields are
		 * filled. A copy made to write into shares every one of the original's photographs, so
		 * rewriting them would rename the original's pictures after the copy — which is exactly
		 * what writing into a copy promises not to do.
		 */
		$force = ( $rewrite || $drawn ) && ! self::shared( $id, $post_id );

		$alt = sanitize_text_field( (string) ( $w['alt'] ?? '' ) ) ?: $name;
		/* The first picture is the one a search engine reads, so it says what the thing is called
		   even when the model described the scene instead. */
		if ( $first && '' !== $name && false === mb_stripos( $alt, $name ) ) {
			$alt = $name . ' — ' . lcfirst( $alt );
		}
		if ( '' === $alt ) {
			return false;
		}

		$now = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		if ( $force || '' === trim( $now ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}

		$post   = get_post( $id );
		$update = [ 'ID' => $id ];
		$fields = [
			'post_title'   => sanitize_text_field( (string) ( $w['title'] ?? '' ) ) ?: $alt,
			'post_excerpt' => sanitize_text_field( (string) ( $w['caption'] ?? '' ) ),
			'post_content' => sanitize_textarea_field( (string) ( $w['description'] ?? '' ) ) ?: $alt,
		];
		foreach ( $fields as $field => $value ) {
			if ( '' !== $value && ( $force || '' === trim( (string) $post->$field ) ) ) {
				$update[ $field ] = $value;
			}
		}
		if ( count( $update ) > 1 ) {
			wp_update_post( $update );
		}

		return true;
	}

	/* ─────────────────────────── undo ─────────────────────────── */

	/**
	 * Keep the product as it is saved now, just before an overwrite puts anything in its fields.
	 *
	 * What is kept is what is saved, not what the screen shows: the run fills the screen and Update
	 * saves it, and by the time somebody wants the old words back they have often pressed Update.
	 * One run's worth is kept — a second overwrite keeps the product as the first one left it.
	 *
	 * @return array|WP_Error { when }
	 */
	public static function keep( int $post_id ) {
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return new WP_Error( 'whd_studio_keep', __( 'The product could not be read.', 'whd' ) );
		}
		$props = [];
		foreach ( self::UNDO_PROPS as $prop ) {
			$props[ $prop ] = $product->{'get_' . $prop}( 'edit' );
		}
		// As plain arrays: an object stored in post meta needs its class to be read back.
		$props['attributes'] = array_map( static fn( WC_Product_Attribute $a ) => [
			'id'        => $a->get_id(),
			'name'      => $a->get_name(),
			'options'   => $a->get_options(),
			'position'  => $a->get_position(),
			'visible'   => $a->get_visible(),
			'variation' => $a->get_variation(),
		], $product->get_attributes( 'edit' ) );

		$variations = [];
		foreach ( self::variation_ids( $post_id ) as $id ) {
			$variation = wc_get_product( $id );
			if ( $variation instanceof WC_Product_Variation ) {
				$variations[ $id ] = self::variation_props( $variation );
			}
		}

		$meta = [];
		foreach ( self::UNDO_META as $key ) {
			$meta[ $key ] = metadata_exists( 'post', $post_id, $key ) ? get_post_meta( $post_id, $key, true ) : null;
		}

		$record = [
			'at'         => time(),
			'by'         => get_current_user_id(),
			'type'       => $product->get_type(),
			'props'      => $props,
			'variations' => $variations,
			'meta'       => $meta,
			'pictures'   => [], // filled in by finish(), before it rewrites their words
		];
		update_post_meta( $post_id, self::UNDO, wp_slash( $record ) );

		return [ 'when' => self::when( $record['at'] ) ];
	}

	/**
	 * Put the product back as keep() found it, and delete the pictures drawn for it since.
	 *
	 * Called before Update, this mostly puts back what the run saved at once — the story, the
	 * pictures' words, a main image it set — and the reload that follows drops the rest from the
	 * screen. Called after Update, it puts back everything Update saved too.
	 *
	 * @return array|WP_Error { edit, deleted }
	 */
	public static function undo( int $post_id ) {
		$record = get_post_meta( $post_id, self::UNDO, true );
		if ( ! is_array( $record ) || empty( $record['props'] ) || ! self::fresh( $record ) ) {
			delete_post_meta( $post_id, self::UNDO );
			return new WP_Error( 'whd_studio_no_undo', __( 'There is nothing to undo on this product any more.', 'whd' ) );
		}
		$class = WC_Product_Factory::get_classname_from_product_type( (string) $record['type'] );
		if ( ! $class || ! class_exists( $class ) ) {
			return new WP_Error( 'whd_studio_no_undo', __( 'The kind of product this was is not available any more, so it cannot be put back.', 'whd' ) );
		}
		self::mark( 'putting the product back as it was' );

		/* Variations it did not have then go first, which frees their SKUs; a product going back to
		   simple takes none with it. */
		$keep = 'variable' === $record['type'] ? (array) $record['variations'] : [];
		foreach ( self::variation_ids( $post_id ) as $id ) {
			if ( ! isset( $keep[ $id ] ) ) {
				$variation = wc_get_product( $id );
				if ( $variation ) {
					$variation->delete( true );
				}
			}
		}

		// Read as the kind it was: saving it as that kind is how WooCommerce changes a product's type back.
		$product    = new $class( $post_id );
		$props      = (array) $record['props'];
		$attributes = [];
		foreach ( (array) ( $props['attributes'] ?? [] ) as $key => $a ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_id( (int) $a['id'] );
			$attribute->set_name( (string) $a['name'] );
			$attribute->set_options( (array) $a['options'] );
			$attribute->set_position( (int) $a['position'] );
			$attribute->set_visible( (bool) $a['visible'] );
			$attribute->set_variation( (bool) $a['variation'] );
			$attributes[ $key ] = $attribute;
		}
		unset( $props['attributes'] );
		$product->set_props( $props ); // a prop that will not take (an SKU somebody else has since) is skipped, not fatal
		$product->set_attributes( $attributes );
		$product->save();

		foreach ( $keep as $id => $was ) {
			$id     = (int) $id;
			$exists = 'product_variation' === get_post_type( $id ) && (int) wp_get_post_parent_id( $id ) === $post_id;
			$variation = $exists ? new WC_Product_Variation( $id ) : new WC_Product_Variation();
			if ( $exists && self::variation_props( $variation ) == $was ) { // phpcs:ignore Universal.Operators.StrictComparisons -- '12' and 12 are the same price
				continue;
			}
			if ( ! $exists ) {
				$variation->set_parent_id( $post_id );
			}
			$variation->set_props( (array) $was );
			$variation->save();
		}
		if ( 'variable' === $record['type'] ) {
			WC_Product_Variable::sync( $post_id );
		}

		foreach ( (array) $record['meta'] as $key => $value ) {
			if ( ! in_array( $key, self::UNDO_META, true ) ) {
				continue;
			}
			if ( null === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, wp_slash( $value ) );
			}
		}

		// The pictures it drew: out of the gallery above, out of the media library here.
		$deleted = 0;
		foreach ( self::drawn_since( $post_id, (int) $record['at'] ) as $id ) {
			if ( ! self::shared( $id, $post_id ) && self::discard( $post_id, $id ) ) {
				$deleted++;
			}
		}

		foreach ( (array) ( $record['pictures'] ?? [] ) as $id => $was ) {
			$id = (int) $id;
			if ( 'attachment' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			if ( '' === (string) $was['alt'] ) {
				delete_post_meta( $id, '_wp_attachment_image_alt' );
			} else {
				update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( (string) $was['alt'] ) );
			}
			wp_update_post( wp_slash( [
				'ID'           => $id,
				'post_title'   => (string) $was['post_title'],
				'post_excerpt' => (string) $was['post_excerpt'],
				'post_content' => (string) $was['post_content'],
			] ) );
		}

		// An autosave of the run's words would be offered back ("there is an autosave more recent…").
		$autosave = wp_get_post_autosave( $post_id, get_current_user_id() );
		if ( $autosave ) {
			wp_delete_post_revision( $autosave->ID );
		}

		delete_post_meta( $post_id, self::UNDO );
		wc_delete_product_transients( $post_id );
		clean_post_cache( $post_id );

		return [
			'edit'    => add_query_arg( 'whd_studio', 'undone', get_edit_post_link( $post_id, 'raw' ) ),
			'deleted' => $deleted,
		];
	}

	/** What finish() is about to rewrite on each picture, kept the first time only: Undo goes back to before the first run. */
	private static function remember_pictures( int $post_id, array $ids ) {
		$record = get_post_meta( $post_id, self::UNDO, true );
		if ( ! is_array( $record ) || ! self::fresh( $record ) ) {
			return;
		}
		$record['pictures'] = (array) ( $record['pictures'] ?? [] );
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( $post && ! isset( $record['pictures'][ $post->ID ] ) ) {
				$record['pictures'][ $post->ID ] = [
					'alt'          => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
					'post_title'   => $post->post_title,
					'post_excerpt' => $post->post_excerpt,
					'post_content' => $post->post_content,
				];
			}
		}
		update_post_meta( $post_id, self::UNDO, wp_slash( $record ) );
	}

	/** For the panel: { when } while the product has an overwrite that can still be undone, else null. */
	private static function undo_offer( int $post_id ) {
		$record = get_post_meta( $post_id, self::UNDO, true );
		return is_array( $record ) && self::fresh( $record ) ? [ 'when' => self::when( (int) $record['at'] ) ] : null;
	}

	private static function fresh( array $record ): bool {
		return time() - (int) ( $record['at'] ?? 0 ) < self::UNDO_DAYS * DAY_IN_SECONDS;
	}

	/** "October 23, 2026 at 3:42 pm", in the site's own formats and time zone. */
	private static function when( int $at ): string {
		/* translators: 1: date, 2: time */
		return sprintf( __( '%1$s at %2$s', 'whd' ), wp_date( get_option( 'date_format' ), $at ), wp_date( get_option( 'time_format' ), $at ) );
	}

	/** Every variation of a product, enabled (publish) or not (private). */
	private static function variation_ids( int $post_id ): array {
		return array_map( 'intval', get_posts( [
			'post_parent'      => $post_id,
			'post_type'        => 'product_variation',
			'post_status'      => [ 'publish', 'private' ],
			'fields'           => 'ids',
			'numberposts'      => -1,
			'orderby'          => 'menu_order ID',
			'order'            => 'ASC',
			'suppress_filters' => true,
		] ) );
	}

	private static function variation_props( WC_Product_Variation $variation ): array {
		$props = [];
		foreach ( self::UNDO_VARIATION as $prop ) {
			$props[ $prop ] = $variation->{'get_' . $prop}( 'edit' );
		}
		return $props;
	}

	/** Pictures this panel drew for the product at or after a time (attached to it, marked as drawn). */
	private static function drawn_since( int $post_id, int $at ): array {
		if ( ! class_exists( 'WHD_AI_Images' ) ) {
			return [];
		}
		return array_map( 'intval', get_posts( [
			'post_type'        => 'attachment',
			'post_status'      => 'inherit',
			'post_parent'      => $post_id,
			'fields'           => 'ids',
			'numberposts'      => -1,
			'meta_key'         => WHD_AI_Images::META, // phpcs:ignore WordPress.DB.SlowDBQuery -- a handful of attachments, on a button press
			'date_query'       => [ [ 'column' => 'post_date_gmt', 'after' => gmdate( 'Y-m-d H:i:s', $at ), 'inclusive' => true ] ],
			'suppress_filters' => true,
		] ) );
	}

	/**
	 * Fabric, fit and care, as attributes on the product, saved with Update.
	 *
	 * WooCommerce rebuilds the product's attributes from the Attributes tab on every save, so one
	 * written from the panel straight to the database would be gone the moment Update was pressed.
	 * The panel puts them in a hidden field instead and they are merged in here, after WooCommerce
	 * and the Variation tiers grid have both done their saving.
	 */
	public static function save_attributes( $post_id ) {
		if ( empty( $_POST['whd_studio_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['whd_studio_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( empty( $_POST['whd_studio_attrs'] ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$given = json_decode( wp_unslash( $_POST['whd_studio_attrs'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised pair by pair in add_attributes()
		if ( is_array( $given ) ) {
			self::add_attributes( (int) $post_id, $given );
		}
	}

	/**
	 * Fabric, fit and care onto a product as plain attributes, label => value, leaving its tiers alone.
	 *
	 * Bulk products calls this directly, once the variations are built: there is no Update there,
	 * so nothing comes along afterwards to rebuild the attributes from a tab.
	 *
	 * @return int How many were added or rewritten.
	 */
	public static function add_attributes( int $post_id, array $given ): int {
		$product = wc_get_product( $post_id );
		if ( ! $given || ! $product ) {
			return 0;
		}

		$changed    = 0;
		$attributes = $product->get_attributes();
		$tiers      = [];
		foreach ( $attributes as $attribute ) {
			if ( $attribute->get_variation() ) {
				$tiers[] = self::label_key( wc_attribute_label( $attribute->get_name() ) );
			}
		}

		foreach ( $given as $label => $value ) {
			$label = sanitize_text_field( (string) $label );
			$value = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
			if ( '' === $label || '' === $value || in_array( self::label_key( $label ), $tiers, true ) ) {
				continue; // a shopper chooses this one; it is not a line of text about the piece
			}
			$found = null;
			foreach ( $attributes as $key => $attribute ) {
				if ( self::label_key( wc_attribute_label( $attribute->get_name() ) ) === self::label_key( $label ) ) {
					$found = $key;
					break;
				}
			}
			if ( null !== $found ) {
				// A global attribute has terms of its own somebody chose; only a plain one is rewritten.
				if ( ! $attributes[ $found ]->is_taxonomy() ) {
					$attributes[ $found ]->set_options( [ $value ] );
					$changed++;
				}
				continue;
			}
			$attribute = new WC_Product_Attribute();
			$attribute->set_name( $label );
			$attribute->set_options( [ $value ] );
			$attribute->set_position( count( $attributes ) );
			$attribute->set_visible( true );
			$attribute->set_variation( false );
			$attributes[ sanitize_title( $label ) ] = $attribute;
			$changed++;
		}

		$product->set_attributes( $attributes );
		$product->save();

		return $changed;
	}

	/* ─────────────────────────── what the model is told about the shop ─────────────────────────── */

	/** "145: Clothing › Dresses" — every category a product can go in. */
	private static function category_list(): string {
		$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 300 ] );
		if ( is_wp_error( $terms ) ) {
			return '';
		}
		$by_id = [];
		foreach ( $terms as $term ) {
			$by_id[ $term->term_id ] = $term;
		}
		$lines = [];
		foreach ( $terms as $term ) {
			if ( 'uncategorized' === $term->slug ) {
				continue;
			}
			$path = [ $term->name ];
			$up   = $term;
			$hops = 0;
			while ( $up->parent && isset( $by_id[ $up->parent ] ) && $hops++ < 5 ) {
				$up     = $by_id[ $up->parent ];
				$path[] = $up->name;
			}
			$lines[] = $term->term_id . ': ' . html_entity_decode( implode( ' › ', array_reverse( $path ) ), ENT_QUOTES, 'UTF-8' );
		}
		return implode( "\n", $lines );
	}

	/** How the last few products were filed, so the model copies the shop's habit rather than inventing one. */
	private static function filing_examples(): string {
		$lines = [];
		foreach ( get_posts( [ 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 6 ] ) as $p ) {
			$names = wp_get_post_terms( $p->ID, 'product_cat', [ 'fields' => 'names' ] );
			if ( $names && ! is_wp_error( $names ) ) {
				$lines[] = '- ' . html_entity_decode( $p->post_title . ': ' . implode( ', ', $names ), ENT_QUOTES, 'UTF-8' );
			}
		}
		return implode( "\n", $lines );
	}

	private static function chart_list(): string {
		if ( ! class_exists( 'WHD_Size_Charts' ) ) {
			return '';
		}
		$lines = [];
		foreach ( WHD_Size_Charts::all() as $chart ) {
			$covers = array_filter( array_map( static function ( $id ) {
				$term = get_term( (int) $id, 'product_cat' );
				return $term && ! is_wp_error( $term ) ? html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) : '';
			}, (array) get_post_meta( $chart->ID, WHD_Size_Charts::CATS, true ) ) );
			$lines[] = $chart->ID . ': ' . html_entity_decode( $chart->post_title, ENT_QUOTES, 'UTF-8' )
				. ( $covers ? ' — for ' . implode( ', ', $covers ) : '' )
				. ( get_post_meta( $chart->ID, WHD_Size_Charts::DEF, true ) ? ' (the shop\'s default for clothing)' : '' );
		}
		return implode( "\n", $lines );
	}

	/** "Color: Black, Cream, Navy…" — the global attributes and the spellings already in use. */
	private static function attribute_list(): string {
		if ( ! class_exists( 'WHDV_Model' ) ) {
			return '';
		}
		$lines = [];
		foreach ( WHDV_Model::catalog() as $attribute ) {
			$terms   = array_slice( wp_list_pluck( (array) $attribute['terms'], 'name' ), 0, 40 );
			$lines[] = '- ' . $attribute['label'] . ( $terms ? ': ' . implode( ', ', $terms ) : '' );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Proper words capitalised, and the first letter of the text: "korean fashion jacket in brushed
	 * plaid" → "Korean fashion jacket in brushed plaid". HTML tags are left alone.
	 */
	public static function proper( string $text ): string {
		static $words = null;
		if ( null === $words ) {
			$words = (array) apply_filters( 'whd_ai_proper_words', [
				'korean', 'korea', 'thai', 'thailand', 'asian', 'asia', 'japanese', 'japan', 'chinese', 'china',
				'filipina', 'filipino', 'philippines', 'vietnamese', 'vietnam', 'seoul', 'bangkok', 'tokyo',
				'manila', 'kpop', 'christmas', 'xmas', 'valentine', 'valentines',
			] );
		}
		// Outside tags only, so an attribute or a tag name is never touched.
		$parts = preg_split( '/(<[^>]*>)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( $parts as $i => $part ) {
			if ( '' === $part || '<' === $part[0] ) {
				continue;
			}
			$parts[ $i ] = preg_replace_callback(
				'/\b(' . implode( '|', array_map( 'preg_quote', $words ) ) . ')\b/i',
				static fn( $m ) => 'xmas' === strtolower( $m[1] ) ? 'Xmas' : ucfirst( strtolower( $m[1] ) ),
				$part
			);
		}
		$text = implode( '', $parts );
		// The first letter the reader sees, if the model began in lower case.
		return (string) preg_replace_callback( '/^((?:\s|<[^>]*>)*)(\p{Ll})/u', static fn( $m ) => $m[1] . mb_strtoupper( $m[2] ), $text );
	}

	/**
	 * The web address: the name, with the focus keyphrase in front when the name does not carry it.
	 *
	 * A list phrase does not always make a good product name ("Dress Korean Fashion"), so the name
	 * is allowed to leave it out — but the address is one of the places the score looks for it, and
	 * nobody reads an address as prose. "korean-fashion-jacket-plaid-shacket", not three words more.
	 */
	public static function slug_for( string $name, string $keyphrase ): string {
		$slug = sanitize_title( $name );
		$key  = sanitize_title( $keyphrase );
		if ( '' === $key || false !== strpos( '-' . $slug . '-', '-' . $key . '-' ) ) {
			return $slug;
		}
		$rest = array_diff( explode( '-', $slug ), explode( '-', $key ) );
		return sanitize_title( $key . '-' . implode( '-', array_slice( array_values( $rest ), -3 ) ) );
	}

	/** Focus keyphrases other products already aim at, among the ones that share a word with this piece. */
	private static function taken_phrases( int $post_id, array $facts ): array {
		if ( ! class_exists( 'WHD_SEO' ) ) {
			return [];
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one read, on a button press
		$rows  = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = %s AND pm.post_id <> %d AND p.post_status IN ('publish','draft','pending','private') LIMIT 500",
			WHD_SEO::KEYPHRASE,
			$post_id
		) );
		$words = array_filter(
			preg_split( '/[^\p{L}]+/u', mb_strtolower( (string) ( $facts['garment'] ?? '' ) ), -1, PREG_SPLIT_NO_EMPTY ),
			static fn( $w ) => mb_strlen( $w ) >= 4
		);
		$out = [];
		foreach ( (array) $rows as $phrase ) {
			foreach ( $words as $word ) {
				if ( false !== mb_stripos( (string) $phrase, preg_replace( '/s$/', '', $word ) ) ) {
					$out[] = (string) $phrase;
					break;
				}
			}
		}
		return array_slice( $out, 0, 40 );
	}

	/**
	 * Tiers for the Variation tiers grid, in the shape its own app keeps.
	 *
	 * Each level is matched to a global attribute the shop already has — "Colour" finds Color — and
	 * each option to a term already in it, so a run of the panel adds variations to the shop's
	 * existing colours rather than a second set of them. Where two attributes answer to the same
	 * name, the one the category presets use wins.
	 */
	private static function tiers( array $plan, int $post_id ): array {
		if ( empty( $plan['levels'] ) || ! class_exists( 'WHDV_Model' ) ) {
			return [];
		}
		$catalog = WHDV_Model::catalog();
		$preset  = (array) ( WHDV_Model::preset_for_product( $post_id ) ?? [] );
		$presets = (array) get_option( 'whdv_presets', [] );
		$default = array_map( 'wc_attribute_taxonomy_name', (array) ( $presets['_default'] ?? [] ) );

		$levels = [];
		foreach ( array_slice( $plan['levels'], 0, defined( 'WHDV_MAX_LEVELS' ) ? (int) WHDV_MAX_LEVELS : 3 ) as $level ) {
			$known = null;
			foreach ( $catalog as $attribute ) {
				$same = self::label_key( $attribute['label'] ) === self::label_key( $level['label'] )
					|| self::label_key( str_replace( 'pa_', '', $attribute['taxonomy'] ) ) === self::label_key( $level['label'] );
				if ( ! $same ) {
					continue;
				}
				$preferred = in_array( $attribute['taxonomy'], $preset, true ) || in_array( $attribute['taxonomy'], $default, true );
				if ( ! $known || $preferred ) {
					$known = $attribute;
				}
				if ( $preferred ) {
					break;
				}
			}

			$options = [];
			foreach ( $level['options'] as $name ) {
				$term = null;
				foreach ( $known ? (array) $known['terms'] : [] as $candidate ) {
					if ( 0 === strcasecmp( $candidate['name'], $name ) || $candidate['slug'] === sanitize_title( $name ) ) {
						$term = $candidate;
						break;
					}
				}
				// A new option is written the way the shop's existing ones are: "Red Plaid", not "red plaid".
				$options[] = $term
					? [ 'key' => $term['slug'], 'id' => $term['id'], 'name' => $term['name'], 'slug' => $term['slug'], 'color' => $term['color'], 'image' => $term['image'], 'image_url' => $term['image_url'] ]
					: [ 'key' => 'new:' . sanitize_title( $name ), 'id' => 0, 'name' => $name === mb_strtolower( $name ) ? mb_convert_case( $name, MB_CASE_TITLE, 'UTF-8' ) : $name, 'slug' => '', 'color' => '', 'image' => 0, 'image_url' => '' ];
			}
			$levels[] = [
				'taxonomy' => $known ? $known['taxonomy'] : '',
				'label'    => $known ? $known['label'] : $level['label'],
				'type'     => $known ? $known['type'] : 'select',
				'new'      => ! $known,
				'options'  => $options,
			];
		}
		if ( ! $levels ) {
			return [];
		}

		$keys = [ [] ];
		foreach ( $levels as $level ) {
			$next = [];
			foreach ( $keys as $so_far ) {
				foreach ( $level['options'] as $option ) {
					$next[] = array_merge( $so_far, [ $option['key'] ] );
				}
			}
			$keys = $next;
		}
		$combos = [];
		foreach ( $keys as $combo ) {
			$combos[ implode( '|', $combo ) ] = [
				'on'            => true,
				'id'            => 0,
				'sku'           => '',
				'regular_price' => $plan['price'],
				'sale_price'    => $plan['sale'],
				'stock'         => $plan['stock'],
				'oos'           => false,
				'image'         => 0,
				'image_url'     => '',
			];
		}

		return [ 'levels' => $levels, 'combos' => $combos, 'groups' => [], 'notices' => [], 'dirty' => true ];
	}

	/* ─────────────────────────── tidying ─────────────────────────── */

	/** "Colour", "color", "COLOR " are one attribute. */
	private static function label_key( string $label ): string {
		$key = preg_replace( '/[^a-z]/', '', strtolower( remove_accents( $label ) ) );
		return str_replace( 'colour', 'color', (string) $key );
	}

	private static function image_ids( $ids ): array {
		$out = [];
		foreach ( (array) $ids as $id ) {
			$id = absint( is_array( $id ) ? ( $id['id'] ?? 0 ) : $id );
			if ( $id && wp_attachment_is_image( $id ) && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	private static function parts( $given ): array {
		return array_values( array_intersect( self::PARTS, array_map( 'sanitize_key', array_filter( (array) $given, 'is_scalar' ) ) ) );
	}

	private static function shots( $given ): array {
		$out = [];
		foreach ( array_slice( (array) $given, 0, self::TOTAL_MAX ) as $shot ) {
			$shot  = is_array( $shot ) ? $shot : [];
			$out[] = [
				'mode'  => 'mannequin' === ( $shot['mode'] ?? '' ) ? 'mannequin' : 'model',
				'ask'   => mb_substr( sanitize_textarea_field( (string) ( $shot['ask'] ?? '' ) ), 0, 500 ),
				'brief' => mb_substr( sanitize_textarea_field( (string) ( $shot['brief'] ?? '' ) ), 0, 1200 ),
			];
		}
		return $out;
	}

	private static function commands( $text ): string {
		return mb_substr( trim( preg_replace( '/[ \t]+/', ' ', sanitize_textarea_field( (string) $text ) ) ), 0, 2000 );
	}

	/** What the screen says now, sent by the panel — unsaved edits included. */
	private static function current( array $given, int $post_id ): array {
		$post = get_post( $post_id );
		$html = (string) ( $given['description'] ?? ( $post ? $post->post_content : '' ) );
		return [
			'title'            => sanitize_text_field( (string) ( $given['title'] ?? ( $post ? $post->post_title : '' ) ) ),
			'short'            => trim( wp_strip_all_tags( (string) ( $given['short'] ?? ( $post ? $post->post_excerpt : '' ) ) ) ),
			'description'      => trim( wp_strip_all_tags( $html ) ),
			'description_html' => wp_kses_post( $html ),
		];
	}

	private static function tidy_facts( array $facts, int $photos ): array {
		$ids   = $facts['image_ids'] ?? [];
		$facts = self::clean( $facts );
		$facts['image_ids'] = self::image_ids( $ids );

		$facts['countables'] = self::countables( $facts['countables'] ?? [] );
		$facts['colourways'] = self::colourways( $facts['colourways'] ?? [] );

		$regions = [];
		foreach ( (array) ( $facts['regions'] ?? [] ) as $region ) {
			$box   = array_values( (array) ( $region['box'] ?? [] ) );
			$image = (int) ( $region['image'] ?? 0 );
			if ( count( $box ) !== 4 || $image < 1 || $image > max( 1, $photos ) || count( array_filter( $box, 'is_numeric' ) ) !== 4 ) {
				continue;
			}
			$box = array_map( static fn( $v ) => max( 0, min( 1000, (int) round( (float) $v ) ) ), $box );
			if ( $box[0] > $box[2] ) {
				[ $box[0], $box[2] ] = [ $box[2], $box[0] ];
			}
			if ( $box[1] > $box[3] ) {
				[ $box[1], $box[3] ] = [ $box[3], $box[1] ];
			}
			if ( $box[2] - $box[0] < 5 || $box[3] - $box[1] < 5 ) {
				continue;
			}
			$regions[] = [ 'image' => $image, 'box' => $box, 'look_for' => sanitize_text_field( (string) ( $region['look_for'] ?? '' ) ) ?: 'the detail here' ];
		}
		$facts['regions'] = array_slice( $regions, 0, self::CROPS_MAX );

		$views = [];
		foreach ( (array) ( $facts['views'] ?? [] ) as $view ) {
			if ( is_array( $view ) && (int) ( $view['image'] ?? 0 ) > 0 ) {
				$views[] = [ 'image' => (int) $view['image'], 'shows' => (string) ( $view['shows'] ?? '' ) ];
			}
		}
		$facts['views'] = $views;

		foreach ( [ 'other_colours', 'details', 'absent', 'styling', 'occasion', 'name_ideas', 'corrections' ] as $key ) {
			$facts[ $key ] = self::strings_of( $facts[ $key ] ?? [] );
		}

		return $facts;
	}

	private static function countables( $list ): array {
		$out = [];
		foreach ( (array) $list as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$item = trim( sanitize_text_field( (string) ( $c['item'] ?? '' ) ) );
			if ( '' === $item || ! is_numeric( $c['count'] ?? null ) ) {
				continue;
			}
			$confidence = strtolower( (string) ( $c['confidence'] ?? '' ) );
			$confidence = in_array( $confidence, [ 'high', 'medium', 'low' ], true ) ? $confidence : 'medium';
			$out[]      = [
				'item'        => $item,
				'count'       => max( 0, (int) $c['count'] ),
				// Not said either way: complete only when the inspector was sure of it.
				'all_visible' => isset( $c['all_visible'] ) ? ! empty( $c['all_visible'] ) && 'false' !== $c['all_visible'] : 'high' === $confidence,
				'where'       => sanitize_text_field( (string) ( $c['where'] ?? '' ) ),
				'look'        => sanitize_text_field( (string) ( $c['look'] ?? '' ) ),
				'confidence'  => $confidence,
				'checked'     => ! empty( $c['checked'] ),
			];
		}
		return array_slice( $out, 0, 30 );
	}

	/**
	 * The colourway a shot is of: the one whose name shares the most words with its brief.
	 *
	 * The writer is told to name it in every brief, but names come back paraphrased — "the rust
	 * plaid" for "muted rust, slate and brown ombre plaid" — so this matches on words, not on the
	 * whole string. Null when there is only one colourway or nothing matches.
	 */
	private static function colourway_for( array $facts, array $shot ) {
		$ways = self::colourways( $facts['colourways'] ?? [] );
		if ( count( $ways ) < 2 ) {
			return null;
		}
		$words = static fn( $s ) => array_filter(
			preg_split( '/[^\p{L}]+/u', mb_strtolower( $s ), -1, PREG_SPLIT_NO_EMPTY ),
			static fn( $w ) => mb_strlen( $w ) >= 3 && ! in_array( $w, [ 'and', 'the', 'with', 'plaid', 'check', 'print', 'colourway' ], true )
		);
		$said = $words( $shot['brief'] . ' ' . $shot['ask'] );
		$best = null;
		$most = 0;
		foreach ( $ways as $way ) {
			$hits = count( array_intersect( $words( $way['name'] ), $said ) );
			if ( $hits > $most ) {
				$most = $hits;
				$best = $way;
			}
		}
		return $best;
	}

	/** [ { name, images[] } ] — the colours the photographs show the piece in, when there is more than one. */
	private static function colourways( $list ): array {
		$out = [];
		foreach ( (array) $list as $way ) {
			$name = is_array( $way ) ? trim( sanitize_text_field( (string) ( $way['name'] ?? '' ) ) ) : '';
			if ( '' !== $name ) {
				$out[] = [
					'name'   => $name,
					'tones'  => sanitize_text_field( (string) ( $way['tones'] ?? '' ) ),
					'images' => array_values( array_filter( array_map( 'absint', (array) ( $way['images'] ?? [] ) ) ) ),
				];
			}
		}
		return array_slice( $out, 0, 8 );
	}

	/** Strings out of whatever a model sent where a list of strings was asked for. */
	private static function strings_of( $list ): array {
		$out = [];
		foreach ( (array) $list as $item ) {
			$item = is_scalar( $item ) ? trim( sanitize_text_field( (string) $item ) ) : '';
			if ( '' !== $item && ! in_array( $item, $out, true ) ) {
				$out[] = $item;
			}
		}
		return array_slice( $out, 0, 30 );
	}

	/**
	 * A model's reply, or the same reply coming back from the browser, made safe to put in a prompt.
	 *
	 * Plain values only, at a sensible size: facts make a round trip through the page between
	 * steps, and nothing that comes back should be able to grow a prompt without limit.
	 */
	private static function clean( $value, int $depth = 0 ) {
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return mb_substr( sanitize_textarea_field( $value ), 0, 1500 );
		}
		if ( ! is_array( $value ) || $depth > 4 ) {
			return '';
		}
		$out = [];
		foreach ( array_slice( $value, 0, 60, true ) as $key => $item ) {
			$out[ is_int( $key ) ? $key : mb_substr( sanitize_text_field( (string) $key ), 0, 60 ) ] = self::clean( $item, $depth + 1 );
		}
		return $out;
	}

	private static function tidy_name( string $name ): string {
		$name = html_entity_decode( sanitize_text_field( wp_strip_all_tags( $name ) ), ENT_QUOTES, 'UTF-8' );
		$name = trim( preg_replace( '/\s+/u', ' ', $name ) );
		return 'auto draft' === strtolower( $name ) ? '' : mb_substr( $name, 0, 120 );
	}

	/**
	 * The focus keyphrase and the supporting keywords, held to the shop's list.
	 *
	 * The model is asked to choose both from the shortlist; this checks that it did, and puts it
	 * right when it did not. A focus keyphrase off the list is replaced by the best list phrase that
	 * names the piece (the copy is then checked against that one, and repaired if it misses it);
	 * supporting keywords off the list are dropped, and when none are left the next best list
	 * phrases are used. Keywords the shop picked by hand always come first.
	 *
	 * @return array { keyphrase, keyphrase_from: picked|list|name, keyphrase_volume, keywords[] }
	 */
	public static function aim( array $raw, string $name, array $ctx ): array {
		$norm   = static fn( $s ) => trim( preg_replace( '/\s+/u', ' ', mb_strtolower( wp_strip_all_tags( (string) $s ) ) ) );
		$picked = $ctx['keywords'];
		$list   = [];
		foreach ( $ctx['shortlist'] as $k ) {
			$list[ $norm( $k['keyword'] ) ] = $k;
		}
		$aimable = static fn( $k ) => $k && ! $k['taken'] && ! $k['general'];

		$volume = 0;
		if ( $picked ) {
			$focus = $picked[0];
			$from  = 'picked';
		} else {
			$said = $list[ $norm( $raw['keyphrase'] ?? '' ) ] ?? null;
			if ( $aimable( $said ) ) {
				$focus  = $said['keyword'];
				$volume = $said['volume'];
				$from   = 'list';
			} else {
				$best = null;
				foreach ( $ctx['shortlist'] as $k ) {
					if ( $k['names'] && $aimable( $k ) ) {
						$best = $k;
						break;
					}
				}
				if ( $best ) {
					$focus  = $best['keyword'];
					$volume = $best['volume'];
					$from   = 'list';
				} else {
					$focus = self::keyphrase( (string) ( $raw['keyphrase'] ?? '' ), $name );
					$from  = 'name';
				}
			}
		}

		$support = [];
		$offered = array_merge( array_slice( $picked, 1 ), self::strings_of( $raw['keywords'] ?? [] ) );
		foreach ( $offered as $word ) {
			$key = $norm( $word );
			if ( $key === $norm( $focus ) ) {
				continue;
			}
			$exact = in_array( $word, $picked, true ) ? $word : ( $list[ $key ]['keyword'] ?? '' );
			if ( '' !== $exact && ! in_array( $exact, $support, true ) ) {
				$support[] = $exact;
			}
		}
		// The model chose nothing from the list although the list had phrases: the best of them.
		$from_list = array_diff( $support, $picked );
		foreach ( $from_list ? [] : $ctx['shortlist'] as $k ) {
			if ( count( $support ) - count( array_slice( $picked, 1 ) ) >= 2 ) {
				break;
			}
			if ( $norm( $k['keyword'] ) !== $norm( $focus ) && ! in_array( $k['keyword'], $support, true ) ) {
				$support[] = $k['keyword'];
			}
		}

		return [
			'keyphrase'        => $focus,
			'keyphrase_from'   => $from,
			'keyphrase_volume' => $volume,
			'keywords'         => array_slice( $support, 0, max( 4, count( $picked ) ) ),
		];
	}

	/** The phrase the model chose, if the name really contains it; otherwise the name's own last words. */
	private static function keyphrase( string $phrase, string $name ): string {
		$norm   = static fn( $s ) => trim( preg_replace( '/\s+/u', ' ', preg_replace( '/[^\p{L}\p{N}\s-]+/u', ' ', mb_strtolower( html_entity_decode( $s, ENT_QUOTES, 'UTF-8' ) ) ) ) );
		$phrase = $norm( $phrase );
		$named  = $norm( $name );
		$words  = preg_split( '/\s+/u', $phrase, -1, PREG_SPLIT_NO_EMPTY );
		if ( '' !== $phrase && count( $words ) <= 5 && false !== mb_strpos( ' ' . $named . ' ', ' ' . $phrase . ' ' ) ) {
			return $phrase;
		}
		$words = preg_split( '/\s+/u', $named, -1, PREG_SPLIT_NO_EMPTY );
		return $words ? implode( ' ', array_slice( $words, -4 ) ) : '';
	}

	/** The long description as the HTML the editor keeps: paragraphs and third-level headings. */
	private static function description_html( string $html ): string {
		$html = trim( $html );
		if ( '' === $html ) {
			return '';
		}
		if ( false === strpos( $html, '<' ) ) {
			$html = WHD_AI::to_html( $html );
		}
		$html = preg_replace( '/<(\/?)h[12](\s[^>]*)?>/i', '<$1h3>', $html );

		return trim( wp_kses( $html, [
			'p'      => [],
			'h3'     => [],
			'h4'     => [],
			'strong' => [],
			'em'     => [],
			'ul'     => [],
			'ol'     => [],
			'li'     => [],
			'br'     => [],
		] ) );
	}

	/** Sections with a heading, one or two paragraphs and a picture number in range. */
	private static function story_sections( $raw, int $max, int $pictures ): array {
		$out = [];
		foreach ( array_values( (array) $raw ) as $i => $section ) {
			if ( ! is_array( $section ) || count( $out ) >= max( 1, $max ) ) {
				continue;
			}
			$heading = trim( sanitize_text_field( (string) ( $section['heading'] ?? '' ) ) );
			$paras   = array_slice( self::strings_of( is_array( $section['paragraphs'] ?? null ) ? $section['paragraphs'] : [ $section['paragraphs'] ?? ( $section['text'] ?? '' ) ] ), 0, 3 );
			if ( '' === $heading || ! $paras ) {
				continue;
			}
			$image = (int) ( $section['image'] ?? ( $i + 1 ) );
			$out[] = [
				'heading'    => $heading,
				'paragraphs' => $paras,
				'image'      => $image >= 1 && $image <= max( 1, $pictures ) ? $image : $i + 1,
			];
		}
		return $out;
	}

	private static function story_text( array $story ): string {
		$text = '';
		foreach ( $story as $section ) {
			$text .= ( $section['heading'] ?? '' ) . "\n" . implode( "\n", (array) ( $section['paragraphs'] ?? [] ) ) . "\n";
		}
		return $text;
	}

	/** Words for every picture in the set, one entry each, the name standing in where the model sent nothing. */
	private static function image_words( array $raw, int $total, string $name ): array {
		$out = [];
		for ( $i = 0; $i < $total; $i++ ) {
			$w     = is_array( $raw[ $i ] ?? null ) ? $raw[ $i ] : [];
			$out[] = [
				'alt'         => sanitize_text_field( (string) ( $w['alt'] ?? '' ) ) ?: $name,
				'title'       => sanitize_text_field( (string) ( $w['title'] ?? '' ) ),
				'caption'     => sanitize_text_field( (string) ( $w['caption'] ?? '' ) ),
				'description' => sanitize_textarea_field( (string) ( $w['description'] ?? '' ) ),
			];
		}
		return $out;
	}

	private static function category_ids( $raw ): array {
		$out = [];
		foreach ( (array) $raw as $value ) {
			$term = is_numeric( $value )
				? get_term( (int) $value, 'product_cat' )
				: get_term_by( 'name', sanitize_text_field( (string) $value ), 'product_cat' );
			if ( $term && ! is_wp_error( $term ) && 'uncategorized' !== $term->slug && ! in_array( (int) $term->term_id, $out, true ) ) {
				$out[] = (int) $term->term_id;
			}
		}
		return array_slice( $out, 0, 3 );
	}

	private static function chart_id( $raw ): int {
		$id = (int) $raw;
		if ( ! $id || ! class_exists( 'WHD_Size_Charts' ) ) {
			return 0;
		}
		return in_array( $id, array_map( 'intval', wp_list_pluck( WHD_Size_Charts::all(), 'ID' ) ), true ) ? $id : 0;
	}

	/** The build plan, with levels only from the shop's own words and only when there is a choice to make. */
	private static function plan( array $raw, string $commands ): array {
		$plan = [
			'levels' => [],
			'price'  => self::money( $raw['price'] ?? '' ),
			'sale'   => self::money( $raw['sale'] ?? '' ),
			'stock'  => is_numeric( $raw['stock'] ?? null ) ? (string) max( 0, (int) $raw['stock'] ) : '',
			'sku'    => sanitize_text_field( (string) ( $raw['sku'] ?? '' ) ),
		];
		/*
		 * Nobody who left the notes empty is asking for a variable product, however many colours the
		 * model can see — the Bulk products screen learned that one the hard way: "Colour: Black"
		 * made a product with one variation and no price on the shop page.
		 */
		if ( '' === $commands ) {
			return array_merge( $plan, [ 'price' => '', 'sale' => '', 'stock' => '', 'sku' => '' ] );
		}
		/*
		 * A price the shop typed is not something to leave to the model: on the first real run it
		 * read "S to L, 1890" and built six variations with no price at all. Whatever the reply left
		 * empty is read straight from the notes.
		 */
		foreach ( self::numbers_in( $commands ) as $key => $value ) {
			if ( '' === $plan[ $key ] ) {
				$plan[ $key ] = $value;
			}
		}
		/*
		 * The model puts the price in "sku" as well, given half a chance: "S to L, 1890" came back with
		 * SKU 1890 on a real run, which the product screen would have typed into the SKU field and
		 * WooCommerce would then refuse on the next product with that price. A SKU is taken only when
		 * the shop's words say one, and never when it is just one of the numbers already read.
		 */
		$digits = preg_replace( '/[^0-9.]/', '', $plan['sku'] );
		if ( '' !== $plan['sku'] && ( ! preg_match( '/\b(?:sku|item code|style (?:no|number|code)|product code|code)\b/iu', $commands )
			|| ( '' !== $digits && in_array( self::money( $digits ), array_filter( [ $plan['price'], $plan['sale'] ] ), true ) ) ) ) {
			$plan['sku'] = '';
		}
		foreach ( (array) ( $raw['levels'] ?? [] ) as $level ) {
			if ( ! is_array( $level ) ) {
				continue;
			}
			$label   = sanitize_text_field( (string) ( $level['label'] ?? '' ) );
			$options = [];
			foreach ( (array) ( $level['options'] ?? [] ) as $option ) {
				$option = sanitize_text_field( is_array( $option ) ? (string) ( $option['name'] ?? '' ) : (string) $option );
				if ( '' !== $option && ! in_array( mb_strtolower( $option ), array_map( 'mb_strtolower', $options ), true ) ) {
					$options[] = $option;
				}
			}
			if ( '' !== $label && count( $options ) > 1 ) {
				$plan['levels'][] = [ 'label' => $label, 'options' => array_slice( $options, 0, 30 ) ];
			}
		}
		return $plan;
	}

	/**
	 * Price, sale price and stock as the shop writes them in the notes.
	 *
	 * "1450, sale 999, 12 in stock", "₱1,890", "price: 799". A bare number counts as the price only
	 * when it has three digits or more, so "4 buttons" and "size 8 to 12" are never read as one, and
	 * a number with a unit after it (cm, %, ml) is a measurement, not money.
	 *
	 * @return array Any of price, sale, stock that were found.
	 */
	private static function numbers_in( string $text ): array {
		$out  = [];
		$cash = '(?:₱|php|p|\$|rs\.?|usd)?\s*';
		$num  = '(\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?|\d+(?:\.\d{1,2})?)';
		if ( preg_match( '/\b(?:sale|on sale|sale price|now)\s*(?:price)?\s*(?:at|of|for)?\s*[:=]?\s*' . $cash . $num . '/iu', $text, $m ) ) {
			$out['sale'] = self::money( $m[1] );
			$text        = str_replace( $m[0], ' ', $text );
		}
		if ( preg_match( '/\b(?:stock|qty|quantity)\s*(?:of)?\s*[:=]?\s*(\d+)\b|\b(\d+)\s*(?:pcs|pieces|units)?\s*(?:in stock|left|available)\b/iu', $text, $m ) ) {
			$out['stock'] = (string) (int) ( '' !== ( $m[1] ?? '' ) ? $m[1] : $m[2] );
			$text         = str_replace( $m[0], ' ', $text );
		}
		if ( preg_match( '/\b(?:price|priced at|regular|srp)\s*[:=]?\s*' . $cash . $num . '/iu', $text, $m )
			|| preg_match( '/(?:₱|php\s*|\$)\s*' . $num . '/iu', $text, $m )
			|| preg_match( '/(?<!\w)(?<!\d[.,])(\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?|\d{3,7}(?:\.\d{1,2})?)(?!\w|[.,]\d|\s*(?:cm|mm|in\b|inch|%|ml|g\b|gsm|x\b))/iu', $text, $m ) ) {
			$out['price'] = self::money( $m[1] );
		}
		return array_filter( $out, static fn( $v ) => '' !== $v );
	}

	private static function money( $value ): string {
		$value = preg_replace( '/[^0-9.]/', '', (string) $value );
		return '' === $value || ! is_numeric( $value ) ? '' : wc_format_decimal( $value );
	}

	/** Trim to a length without cutting a word in half. */
	private static function fit( string $text, int $max ): string {
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $max );
		$space = mb_strrpos( $cut, ' ' );
		return rtrim( false !== $space ? mb_substr( $cut, 0, $space ) : $cut, ' ,;:-' );
	}

	/** Which of the keywords the copy really carries, word for word. */
	private static function used_keywords( array $draft, array $keywords ): array {
		$copy = WHD_AI::check(
			implode( "\n", [ (string) ( $draft['short'] ?? '' ), (string) ( $draft['description'] ?? '' ), self::story_text( (array) ( $draft['story'] ?? [] ) ) ] ),
			$keywords
		)['keywords'];
		return array_keys( array_filter( $copy ) );
	}

	private static function without( array $array, array $keys ): array {
		return array_diff_key( $array, array_flip( $keys ) );
	}

	/** JSON from a model, which sometimes arrives wrapped in a code fence whatever you ask. */
	private static function decode( string $reply ) {
		$reply = trim( (string) preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', trim( $reply ) ) );
		$data  = json_decode( $reply, true );
		if ( is_array( $data ) ) {
			return $data;
		}
		$start = strpos( $reply, '{' );
		$end   = strrpos( $reply, '}' );
		if ( false === $start || false === $end || $end <= $start ) {
			return null;
		}
		$data = json_decode( substr( $reply, $start, $end - $start + 1 ), true );
		return is_array( $data ) ? $data : null;
	}

	/** Seeing and drawing both take longer than PHP's default allows on a Windows server. */
	private static function long_request() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- some hosts disable it; carry on regardless
		}
	}

	/**
	 * Every request of the panel's, before it does anything.
	 *
	 * GD before Imagick for the cropping and resizing these requests do. Imagick runs outside PHP,
	 * and under LiteSpeed — the live host — it can take the whole PHP process down with it: no error,
	 * no log line, a bare HTTP 500, which is what the zoom step got the first time it ran there. GD
	 * runs inside PHP's own memory limit, where running out is an error that can be reported, and
	 * these photographs are small enough that it is no slower. Imagick is still there for a format
	 * GD cannot open.
	 */
	private static function prepare() {
		self::long_request();
		wp_raise_memory_limit( 'image' );
		add_filter( 'wp_image_editors', [ __CLASS__, 'gd_first' ] );
		register_shutdown_function( [ __CLASS__, 'shutdown' ] );
		// Worked out once, here, so writing a note never has to do anything that could itself fail.
		self::$editor = (string) _wp_image_editor_choose( [ 'mime_type' => 'image/jpeg' ] );
	}

	/** The image library this request's crops and resizes go through, for the note. */
	private static $editor = '';

	public static function gd_first( $editors ) {
		$editors = (array) $editors;
		$gd      = array_search( 'WP_Image_Editor_GD', $editors, true );
		if ( false !== $gd ) {
			unset( $editors[ $gd ] );
			array_unshift( $editors, 'WP_Image_Editor_GD' );
		}
		return array_values( $editors );
	}

	/**
	 * A note of what the request is doing, kept until it finishes.
	 *
	 * When a host kills a PHP process there is nothing left to say what it was doing. This note is
	 * written before each step that could do that and cleared when the request ends normally, so a
	 * note that is still there afterwards is the answer — and the panel asks for it when a request
	 * comes back as a server error page instead of an answer.
	 */
	private static function mark( string $doing ) {
		set_transient( self::mark_key(), [
			'doing'  => $doing,
			'editor' => self::$editor ?: '?',
			'memory' => size_format( memory_get_usage( true ) ),
			'limit'  => (string) ini_get( 'memory_limit' ),
			'at'     => time(),
		], 10 * MINUTE_IN_SECONDS );
	}

	private static function mark_key(): string {
		return 'whd_studio_mark_' . get_current_user_id();
	}

	/** A fatal error that does reach PHP is added to the note, so it is not lost with the page. */
	public static function shutdown() {
		$error = error_get_last();
		if ( ! $error || ! in_array( $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ], true ) ) {
			return;
		}
		$mark          = get_transient( self::mark_key() );
		$mark          = is_array( $mark ) ? $mark : [ 'doing' => '', 'at' => time() ];
		$mark['fatal'] = wp_strip_all_tags( $error['message'] ) . ' (' . basename( (string) $error['file'] ) . ':' . (int) $error['line'] . ')';
		set_transient( self::mark_key(), $mark, 10 * MINUTE_IN_SECONDS );

		// A job that died this way is over: say so, rather than leave the panel waiting on it.
		if ( '' !== self::$job ) {
			set_transient( self::job_key( self::$job ), [
				'status'  => 'error',
				'message' => sprintf(
					/* translators: 1: what it was doing, 2: the error */
					__( 'The server stopped while %1$s: %2$s', 'whd' ),
					$mark['doing'] ?: __( 'working', 'whd' ),
					$mark['fatal']
				),
			], HOUR_IN_SECONDS );
		}
	}

	/** What the last request was doing when it stopped, said once. */
	public static function rest_last() {
		$mark = get_transient( self::mark_key() );
		delete_transient( self::mark_key() );
		if ( ! is_array( $mark ) || time() - (int) ( $mark['at'] ?? 0 ) > 10 * MINUTE_IN_SECONDS ) {
			return new WP_REST_Response( [ 'said' => '' ], 200 );
		}
		$said = ! empty( $mark['fatal'] )
			? sprintf(
				/* translators: 1: what it was doing, 2: the error */
				__( 'The server stopped while %1$s: %2$s', 'whd' ),
				$mark['doing'] ?: __( 'working', 'whd' ),
				$mark['fatal']
			)
			: sprintf(
				/* translators: 1: what it was doing, 2: image library, 3: memory in use, 4: memory limit */
				__( 'The server stopped while %1$s (image library %2$s, %3$s of %4$s memory in use). The host ended the request without saying why.', 'whd' ),
				$mark['doing'],
				$mark['editor'] ?? '?',
				$mark['memory'] ?? '?',
				$mark['limit'] ?? '?'
			);
		return new WP_REST_Response( [ 'said' => $said ], 200 );
	}

	/* ─────────────────────────── the wires ─────────────────────────── */

	public static function rest() {
		$can = static function ( WP_REST_Request $r ) {
			$id = (int) $r->get_param( 'post_id' );
			return $id && 'product' === get_post_type( $id ) && current_user_can( 'edit_post', $id );
		};
		foreach ( [ 'copy', 'look', 'inspect', 'write', 'repair', 'draw', 'check', 'discard', 'finish', 'keep', 'undo' ] as $step ) {
			register_rest_route( 'whd/v1', '/ai/studio/' . $step, [
				'methods'             => 'POST',
				'callback'            => static function ( WP_REST_Request $r ) use ( $step ) {
					self::prepare();
					if ( in_array( $step, self::JOBS, true ) ) {
						return self::start_job( $step, static fn() => call_user_func( [ __CLASS__, 'rest_' . $step ], $r ) );
					}
					$answer = call_user_func( [ __CLASS__, 'rest_' . $step ], $r );
					// Got this far, so whatever the note said did not stop it.
					delete_transient( self::mark_key() );
					return $answer;
				},
				'permission_callback' => $can,
			] );
		}
		// Jobs are per user, so anybody who may start one may ask after it — AI Posts runs them too.
		$own = static function () {
			return current_user_can( 'edit_posts' );
		};
		register_rest_route( 'whd/v1', '/ai/studio/job', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_job' ],
			'permission_callback' => $own,
		] );
		register_rest_route( 'whd/v1', '/ai/studio/last', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_last' ],
			'permission_callback' => $own,
		] );
	}

	/* ─────────────────────────── jobs ─────────────────────────── */

	/** The steps that wait on a model, and so may take longer than a host keeps a request open. */
	const JOBS = [ 'look', 'inspect', 'write', 'repair', 'draw', 'check' ];

	/** The job this request is working on, for the shutdown handler. */
	private static $job = '';

	/**
	 * Start a slow step and answer at once; the work carries on after the answer has gone.
	 *
	 * Writing the copy waits on the model for a minute or two, and the live host ends a request that
	 * has said nothing for about that long — it came back as a bare HTTP 500 at "Writing the page"
	 * with nothing logged, having never reached an error. So no request waits on a model any more:
	 * this one hands back a job number, closes the connection (the same way the Klaviyo pushes do,
	 * through WHD_Plugin::after_response()), and only then does the work. The panel asks how the job
	 * is getting on every few seconds, and each of those requests is over in a moment.
	 *
	 * Where the server cannot close a connection early (Apache with mod_php, as on the local site)
	 * the job simply runs before the answer is finished, which is how it worked before.
	 */
	private static function start_job( string $step, callable $work ): WP_REST_Response {
		$job = strtolower( wp_generate_password( 20, false, false ) );
		set_transient( self::job_key( $job ), [ 'status' => 'running', 'step' => $step, 'at' => time() ], HOUR_IN_SECONDS );

		WHD_Plugin::after_response( static function ( $closed ) use ( $job, $work ) {
			self::$job = $job;
			try {
				$answer = $work();
				$data   = $answer instanceof WP_REST_Response ? $answer->get_data() : $answer;
				$failed = $answer instanceof WP_REST_Response && $answer->get_status() >= 400;
				set_transient( self::job_key( $job ), $failed
					? [ 'status' => 'error', 'message' => (string) ( $data['message'] ?? __( 'Something went wrong', 'whd' ) ) ]
					: [ 'status' => 'done', 'result' => $data, 'closed' => (bool) $closed ], HOUR_IN_SECONDS );
			} catch ( Throwable $e ) {
				set_transient( self::job_key( $job ), [ 'status' => 'error', 'message' => $e->getMessage() ], HOUR_IN_SECONDS );
			}
			self::$job = '';
			delete_transient( self::mark_key() );
		} );

		return new WP_REST_Response( [ 'job' => $job ], 202 );
	}

	/**
	 * Any slow step, from any screen, as a job: answered at once with { job }, done after the
	 * response has gone, asked after through studio/job like the studio's own steps.
	 *
	 * @param string   $step What it is, for the record.
	 * @param callable $work Returns a WP_REST_Response (or plain data) — the job's answer.
	 */
	public static function background( string $step, callable $work ): WP_REST_Response {
		self::prepare();
		return self::start_job( $step, $work );
	}

	/** How a job is getting on: running, or done with its answer, or failed with the reason — said once. */
	public static function rest_job( WP_REST_Request $r ) {
		$job   = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $r->get_param( 'job' ) ) );
		$state = '' !== $job ? get_transient( self::job_key( $job ) ) : false;
		if ( ! is_array( $state ) ) {
			return new WP_REST_Response( [ 'status' => 'error', 'message' => __( 'That job is not known here any more. Generate again.', 'whd' ) ], 200 );
		}
		if ( 'running' !== $state['status'] ) {
			delete_transient( self::job_key( $job ) );
			return new WP_REST_Response( $state, 200 );
		}
		$mark = get_transient( self::mark_key() );
		return new WP_REST_Response( [
			'status'  => 'running',
			'seconds' => time() - (int) $state['at'],
			'doing'   => is_array( $mark ) ? (string) ( $mark['doing'] ?? '' ) : '',
		], 200 );
	}

	/** Per user, so one person's job number is no use to anybody else. */
	private static function job_key( string $job ): string {
		return 'whd_studio_job_' . get_current_user_id() . '_' . $job;
	}

	public static function rest_copy( WP_REST_Request $r ) {
		return self::answer( self::copy( (int) $r->get_param( 'post_id' ), (array) ( $r->get_json_params() ?: [] ) ) );
	}

	public static function rest_look( WP_REST_Request $r ) {
		return self::answer( self::look( (int) $r->get_param( 'post_id' ), (array) $r->get_param( 'images' ), self::commands( $r->get_param( 'commands' ) ) ) );
	}

	public static function rest_inspect( WP_REST_Request $r ) {
		return self::answer( self::inspect( (int) $r->get_param( 'post_id' ), (array) $r->get_param( 'facts' ), self::commands( $r->get_param( 'commands' ) ) ) );
	}

	public static function rest_write( WP_REST_Request $r ) {
		return self::answer( self::write( (int) $r->get_param( 'post_id' ), (array) $r->get_param( 'facts' ), self::write_args( $r ) ) );
	}

	public static function rest_repair( WP_REST_Request $r ) {
		return self::answer( self::repair( (int) $r->get_param( 'post_id' ), (array) $r->get_param( 'facts' ), self::write_args( $r ) + [ 'raw' => (array) $r->get_param( 'raw' ) ] ) );
	}

	private static function write_args( WP_REST_Request $r ): array {
		return [
			'parts'    => (array) $r->get_param( 'parts' ),
			'keywords' => (array) $r->get_param( 'keywords' ),
			'commands' => (string) $r->get_param( 'commands' ),
			'images'   => (array) $r->get_param( 'images' ),
			'shots'    => (array) $r->get_param( 'shots' ),
			'current'  => (array) $r->get_param( 'current' ),
		];
	}

	public static function rest_draw( WP_REST_Request $r ) {
		return self::answer( self::draw( (int) $r->get_param( 'post_id' ), $r->get_json_params() ?: [] ) );
	}

	public static function rest_check( WP_REST_Request $r ) {
		return self::answer( self::check( (int) $r->get_param( 'post_id' ), $r->get_json_params() ?: [] ) );
	}

	public static function rest_discard( WP_REST_Request $r ) {
		return self::answer( [ 'deleted' => self::discard( (int) $r->get_param( 'post_id' ), absint( $r->get_param( 'image' ) ) ) ] );
	}

	public static function rest_finish( WP_REST_Request $r ) {
		return self::answer( self::finish( (int) $r->get_param( 'post_id' ), $r->get_json_params() ?: [] ) );
	}

	public static function rest_keep( WP_REST_Request $r ) {
		return self::answer( self::keep( (int) $r->get_param( 'post_id' ) ) );
	}

	public static function rest_undo( WP_REST_Request $r ) {
		return self::answer( self::undo( (int) $r->get_param( 'post_id' ) ) );
	}

	private static function answer( $result ) {
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'message' => $result->get_error_message() ], 400 );
		}
		return new WP_REST_Response( $result, 200 );
	}
}
