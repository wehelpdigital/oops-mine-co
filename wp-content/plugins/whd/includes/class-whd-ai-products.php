<?php
/**
 * Bulk AI product adder: a stack of pieces in, a stack of finished draft products out.
 *
 * The job this solves is the one that takes an afternoon. A shipment arrives, there are forty
 * photographs on a phone, and every one of them needs a name, a description nobody wants to
 * write forty times, a story, a search snippet, alt text on each picture, a category, a size
 * chart, a price — and usually a few more pictures than the phone took.
 *
 * Each card on this screen is the Write with AI panel from the product screen: the photographs,
 * what to write, keywords, anything to specify, and how many new pictures to draw, each on a model
 * or a ghost mannequin with its own instructions. Each piece then goes through the very same steps,
 * through the very same endpoints (WHD_AI_Studio) — the close look, the zoom, the copy and its
 * repair, every drawing checked against the photographs. Two things differ, because there is no
 * product yet and no screen of fields to fill:
 *
 *   create  an empty draft with the photographs on it, made first, so the steps have a product to
 *           work on (and a drawn picture has a product to belong to)
 *   save    what the product screen puts into its fields and saves with Update, written into the
 *           draft here: name, address, both descriptions, categories, size chart, search snippet,
 *           attributes, price and variations, the gallery — then the alt text and the story, and
 *           the score
 *
 * Pieces run one at a time, so a slow one never takes the others with it. Nothing is published:
 * every product lands as a draft for somebody to look at.
 *
 * Product Builder is the same screen for one piece: one card, no batch, a choice between a draft
 * and publishing it straight away, and the finished product handed back with links to open it.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_AI_Products {

	const PAGE = 'whd-ai-products';

	/** Product Builder: the same card, one piece. */
	const PAGE_ONE = 'whd-ai-product-builder';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 11 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'rest' ] );
	}

	/* ─────────────────────────── where it lives ─────────────────────────── */

	/** Under AI Writer, beside AI Posts and AI Pages: Product Builder first, then the batch. */
	public static function menu() {
		if ( ! class_exists( 'WHD_AI_Writer' ) || ! current_user_can( 'edit_products' ) ) {
			return;
		}
		add_submenu_page(
			WHD_AI_Writer::PAGE_POST,
			__( 'Product Builder', 'whd' ),
			__( 'Product Builder', 'whd' ),
			'edit_products',
			self::PAGE_ONE,
			[ __CLASS__, 'screen_one' ]
		);
		add_submenu_page(
			WHD_AI_Writer::PAGE_POST,
			__( 'Bulk products', 'whd' ),
			__( 'Bulk products', 'whd' ),
			'edit_products',
			self::PAGE,
			[ __CLASS__, 'screen' ]
		);
	}

	public static function assets() {
		// By the page, never the hook name: the hook carries where the screen sits in the menu.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which screen this is
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! in_array( $page, [ self::PAGE, self::PAGE_ONE ], true ) || ! class_exists( 'WHD_AI_Studio' ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'whd-keyword-picker', WHD_URL . 'admin/keyword-picker.css', [], WHD_VERSION );
		// The card is the product screen's panel, so it wears the panel's styles; this file adds the card.
		wp_enqueue_style( 'whd-ai-studio', WHD_URL . 'admin/ai-studio.css', [], WHD_VERSION );
		wp_enqueue_style( 'whd-ai-products', WHD_URL . 'admin/ai-products.css', [ 'whd-ai-studio', 'whd-keyword-picker' ], WHD_VERSION );
		wp_enqueue_script( 'whd-keyword-picker', WHD_URL . 'admin/keyword-picker.js', [], WHD_VERSION, true );
		wp_enqueue_script( 'whd-ai-kit', WHD_URL . 'admin/ai-kit.js', [], WHD_VERSION, true );
		wp_enqueue_script( 'whd-ai-products', WHD_URL . 'admin/ai-products.js', [ 'whd-ai-kit', 'whd-keyword-picker', 'media-editor' ], WHD_VERSION, true );

		$parts = [];
		foreach ( WHD_AI_Studio::part_labels() as $key => $label ) {
			$parts[] = [ 'key' => $key, 'name' => $label[0], 'note' => $label[1] ];
		}

		wp_localize_script( 'whd-ai-products', 'WHD_AIP', [
			// The keyword picker finds its list at rest.root + 'keywords'.
			'rest'       => [
				'root'  => esc_url_raw( rest_url( 'whd/v1/ai/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			],
			'studio'     => esc_url_raw( rest_url( 'whd/v1/ai/studio/' ) ),
			'product'    => esc_url_raw( rest_url( 'whd/v1/ai/product/' ) ),
			'ready'      => WHD_AI::ready(),
			'draw'       => class_exists( 'WHD_AI_Images' ) && WHD_AI_Images::ready(),
			// The sizes a new picture can be drawn at — the Image Editor's.
			'frames'     => class_exists( 'WHD_AI_Images' ) ? WHD_AI_Images::frame_list() : [],
			'parts'      => $parts,
			'totalMax'   => WHD_AI_Studio::TOTAL_MAX,
			'lookMax'    => WHD_AI_Studio::LOOK_MAX,
			'currency'   => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '',
			'categories' => self::category_choices(),
			// Product Builder: one piece, and it may be published as soon as it is made.
			'single'     => self::PAGE_ONE === $page,
			'publish'    => current_user_can( 'publish_products' ),
			'i18n'       => array_merge( WHD_AI_Studio::strings(), self::strings() ),
		] );
	}

	/** The words this screen adds to the panel's own. */
	private static function strings(): array {
		return [
			'untitled'     => __( 'New piece', 'whd' ),
			/* translators: 1: piece number, 2: how many pieces */
			'pieceOf'      => __( 'Piece %1$d of %2$d', 'whd' ),
			/* translators: %d: number of photographs */
			'photosN'      => __( '%d photograph(s)', 'whd' ),
			/* translators: %d: number of new pictures */
			'newN'         => __( '+%d new', 'whd' ),
			'removePiece'  => __( 'Remove this piece', 'whd' ),
			'fold'         => __( 'Fold this piece away', 'whd' ),
			'unfold'       => __( 'Open this piece', 'whd' ),
			'stateNeeds'   => __( 'Needs photographs', 'whd' ),
			'stateReady'   => __( 'Ready', 'whd' ),
			'stateWaiting' => __( 'Waiting its turn', 'whd' ),
			'stateWorking' => __( 'Working…', 'whd' ),
			'stateDone'    => __( 'Draft created', 'whd' ),
			'stateFailed'  => __( 'Stopped', 'whd' ),
			'copyAll'      => __( 'Use these choices on every piece', 'whd' ),
			/* translators: %d: number of other pieces */
			'copiedAll'    => __( 'Copied to %d other piece(s): what to write and the new pictures.', 'whd' ),
			'copiedNone'   => __( 'There is no other piece to copy them to yet.', 'whd' ),
			'known'        => __( 'Details you already know', 'whd' ),
			'knownNote'    => __( 'optional — used exactly as typed', 'whd' ),
			'fPrice'       => __( 'Price', 'whd' ),
			'fSale'        => __( 'Sale price', 'whd' ),
			'fStock'       => __( 'Stock', 'whd' ),
			'fSku'         => __( 'SKU', 'whd' ),
			'fCat'         => __( 'Category', 'whd' ),
			'pickedForMe'  => __( 'Picked for me', 'whd' ),
			'fromPhotos'   => __( 'from the photographs', 'whd' ),
			'goOne'        => __( 'Generate this piece', 'whd' ),
			'goAll'        => __( 'Generate all', 'whd' ),
			/* translators: %d: number of pieces */
			'goAllN'       => __( 'Generate %d pieces', 'whd' ),
			/* translators: 1: pieces ready, 2: pieces in all */
			'readyOf'      => __( '%1$d of %2$d ready', 'whd' ),
			/* translators: %d: number of drafts made */
			'madeN'        => __( '%d made', 'whd' ),
			'nothingReady' => __( 'Add photographs to a piece first — everything is written and drawn from them.', 'whd' ),
			'stepCreate'   => __( 'Starting the draft product', 'whd' ),
			'hintCreate'   => __( 'An empty draft with the photographs on it, for the steps below to work on.', 'whd' ),
			'stepSave'     => __( 'Filling in the product', 'whd' ),
			'hintSave'     => __( 'Name, descriptions, categories, size chart, snippet, price, variations, gallery, alt text and story — saved on the draft.', 'whd' ),
			/* translators: %d: number of pieces */
			'askTitle'     => __( 'Create %d draft product(s)?', 'whd' ),
			'askLead'      => __( 'Each piece is read, written and drawn in turn, the same way Write with AI does it on the product screen. Nothing is published — every one is saved as a draft for you to look over.', 'whd' ),
			/* translators: 1: fewest minutes, 2: most minutes */
			'askTime'      => __( 'About %1$d–%2$d minutes in all. Keep this tab open while it works.', 'whd' ),
			'askGo'        => __( 'Generate', 'whd' ),
			/* translators: 1: photographs, 2: new pictures */
			'askLine'      => __( '%1$d photograph(s), %2$d new', 'whd' ),
			/* translators: %d: number of drafts */
			'batchDone'    => __( '%d draft product(s) created — each card has a link to its draft.', 'whd' ),
			/* translators: 1: drafts made, 2: pieces in all */
			'batchSome'    => __( '%1$d of %2$d created. The ones that stopped say why on their card — generate again to retry them.', 'whd' ),
			'leave'        => __( 'Pieces are still being written. Leave anyway?', 'whd' ),
			'savedHead'    => __( 'Saved on the draft', 'whd' ),
			'openDraft'    => __( 'Open the draft', 'whd' ),
			/* translators: %d: SEO score out of 100 */
			'scoreN'       => __( 'SEO %d', 'whd' ),
			'draftKept'    => __( 'A draft was started for this piece. Generating again carries on with the same draft.', 'whd' ),
			'allPictures'  => __( 'The pictures', 'whd' ),
			'drawnTag'     => __( 'drawn', 'whd' ),
			'logHead'      => __( 'Steps, picture checks and what the AI saw', 'whd' ),
			'secPhotos'    => __( 'Photographs of the piece', 'whd' ),
			/* translators: %d: number of photographs */
			'somePiece'    => __( '%d chosen. The AI reads these close up and draws every new picture from them; the first is the main image.', 'whd' ),
			'nonePiece'    => __( 'Add at least one photograph — everything is written and drawn from them.', 'whd' ),
			'secWrite'     => __( 'What to write', 'whd' ),
			'secMore'      => __( 'More photographs', 'whd' ),
			'tickAll'      => __( 'Tick all', 'whd' ),
			'untickAll'    => __( 'Untick all', 'whd' ),
			'kwLabel'      => __( 'Keywords to work in', 'whd' ),
			'kwPlaceholder' => __( 'Search your keyword list, or type your own', 'whd' ),
			'kwNote'       => __( 'Leave it empty and the AI picks from your keyword list — the phrase that fits best becomes the focus keyphrase. Pick some to choose yourself; the first one you pick is the focus.', 'whd' ),
			'notesLabel'   => __( 'Anything to specify?', 'whd' ),
			'notesHint'    => __( 'e.g. comes in black, cream and navy — XS to XL — 1450, sale 999 — runs small, linen blend, has 4 buttons', 'whd' ),
			'notesNote'    => __( 'Facts about the piece go into the copy and win over what the AI sees. Colours and sizes to choose from, price, sale price and stock build the product — options become real variations.', 'whd' ),
			'countLabel'   => __( 'How many new pictures?', 'whd' ),
			'countNote'    => __( 'Always an even number in all, so the story’s picture-and-copy rows come out in pairs.', 'whd' ),
			'noDraw'       => __( 'Drawing pictures needs a Google AI key — the other providers are text only.', 'whd' ),
			// Product Builder.
			'untitledOne'  => __( 'New product', 'whd' ),
			'stateDoneOne' => __( 'Product created', 'whd' ),
			'createOne'    => __( 'Create the product', 'whd' ),
			'createAgain'  => __( 'Create it again', 'whd' ),
			'oneDone'      => __( 'Your product is ready.', 'whd' ),
			'doneHead'     => __( 'Your product is ready', 'whd' ),
			'doneDraft'    => __( 'Saved as a draft — read it over, then publish it from the product screen.', 'whd' ),
			'donePub'      => __( 'Published — it is in the shop now.', 'whd' ),
			'openProduct'  => __( 'Open the product', 'whd' ),
			'viewShop'     => __( 'View in the shop', 'whd' ),
			'preview'      => __( 'Preview', 'whd' ),
			'another'      => __( 'Build another product', 'whd' ),
			'isDraft'      => __( 'Draft', 'whd' ),
			'isPub'        => __( 'Published', 'whd' ),
		];
	}

	/** Every product category, so a piece can be pinned to one by hand. */
	private static function category_choices(): array {
		$out   = [];
		$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 300 ] );
		foreach ( is_wp_error( $terms ) ? [] : $terms as $term ) {
			if ( 'uncategorized' !== $term->slug ) {
				$out[] = [ 'id' => (int) $term->term_id, 'name' => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) ];
			}
		}

		return $out;
	}

	/* ─────────────────────────── the screens ─────────────────────────── */

	/** Product Builder: one card, one product, made and handed back. */
	public static function screen_one() {
		?>
		<div class="wrap whd-wrap whd-aip whd-aip--single">
			<header class="whd-aip__hero">
				<p class="whd-aip__eyebrow"><?php esc_html_e( 'AI Writer', 'whd' ); ?></p>
				<h1 class="whd-title"><?php esc_html_e( 'Product Builder', 'whd' ); ?></h1>
				<p class="whd-intro whd-aip__lead">
					<?php esc_html_e( 'Photos of a piece in, a finished product out: the name, both descriptions, the story, categories, size chart, product data, search snippet and new pictures — written and drawn by AI from your photos, then saved as a new product. The same steps as Write with AI on the product screen.', 'whd' ); ?>
				</p>
			</header>
			<hr class="wp-header-end">

			<?php if ( ! WHD_AI::ready() ) : ?>
				<div class="whd-note whd-note--warn">
					<?php esc_html_e( 'No model is connected yet, so nothing can be written.', 'whd' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=whd-ai' ) ); ?>"><?php esc_html_e( 'Connect one in WHD → AI content.', 'whd' ); ?></a>
				</div>
			<?php elseif ( ! class_exists( 'WHD_AI_Images' ) || ! WHD_AI_Images::ready() ) : ?>
				<div class="whd-note">
					<?php esc_html_e( 'Reading photographs and writing work; drawing new pictures needs a Google AI key, so the product keeps the photographs it has.', 'whd' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=whd-ai' ) ); ?>"><?php esc_html_e( 'Add one', 'whd' ); ?></a>
				</div>
			<?php endif; ?>

			<div class="whd-aip__done" id="whd-aip-done" hidden></div>
			<div class="whd-aip__list" id="whd-aip-rows"></div>

			<div class="whd-aip__bar">
				<?php if ( current_user_can( 'publish_products' ) ) : ?>
					<fieldset class="whd-aip__status">
						<legend><?php esc_html_e( 'When it is made', 'whd' ); ?></legend>
						<label><input type="radio" name="whd-aip-status" value="draft" checked> <span><?php esc_html_e( 'Save as a draft', 'whd' ); ?></span></label>
						<label><input type="radio" name="whd-aip-status" value="publish"> <span><?php esc_html_e( 'Publish it', 'whd' ); ?></span></label>
					</fieldset>
				<?php endif; ?>
				<span class="whd-aip__progress" id="whd-aip-progress" role="status" aria-live="polite"></span>
				<button type="button" class="button button-primary button-hero" id="whd-aip-run"><?php esc_html_e( 'Create the product', 'whd' ); ?></button>
			</div>
		</div>
		<?php
	}

	public static function screen() {
		?>
		<div class="wrap whd-wrap whd-aip">
			<h1 class="whd-title"><?php esc_html_e( 'Bulk products', 'whd' ); ?></h1>
			<p class="whd-intro whd-aip__lead">
				<?php esc_html_e( 'One card per piece, each with everything Write with AI has on the product screen: the photographs, what to write, keywords, anything to specify, and new pictures on a model or a ghost mannequin. Fill in as many as you like, then generate them all — each one is saved as a draft product.', 'whd' ); ?>
			</p>

			<details class="whd-aip__more">
				<summary><?php esc_html_e( 'How this works', 'whd' ); ?></summary>
				<div class="whd-aip__more-body">
					<p><?php esc_html_e( 'Every piece goes through the same steps as on the product screen: the photographs are read edge to edge, the details are cut out at full size and counted again, the copy is written from what is really there and checked against the house rules, your keyword list and the SEO score, and every new picture is drawn from your photographs and checked against them — drawn again if it got the piece wrong.', 'whd' ); ?></p>
					<p><?php esc_html_e( '“Anything to specify?” is read two ways, as it is there: facts about the piece go into the copy, and instructions about the listing — colours and sizes to choose from, price, sale price, stock — build the product, with options as real variations in the Variation tiers grid. Anything under “Details you already know” is used exactly as typed.', 'whd' ); ?></p>
					<p><?php esc_html_e( 'Pieces are made one at a time, so a slow one never holds up the rest, and one that stops never stops the others. Each finished card links to its draft until you leave the page.', 'whd' ); ?></p>
				</div>
			</details>

			<?php if ( ! WHD_AI::ready() ) : ?>
				<div class="whd-note whd-note--warn">
					<?php esc_html_e( 'No model is connected yet, so nothing can be written.', 'whd' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=whd-ai' ) ); ?>"><?php esc_html_e( 'Connect one in WHD → AI content.', 'whd' ); ?></a>
				</div>
			<?php elseif ( ! class_exists( 'WHD_AI_Images' ) || ! WHD_AI_Images::ready() ) : ?>
				<div class="whd-note">
					<?php esc_html_e( 'Reading photographs and writing work; drawing new pictures needs a Google AI key, so each piece keeps the photographs it has.', 'whd' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=whd-ai' ) ); ?>"><?php esc_html_e( 'Add one', 'whd' ); ?></a>
				</div>
			<?php endif; ?>

			<div class="whd-aip__list" id="whd-aip-rows"></div>

			<p class="whd-aip__add">
				<button type="button" class="button" id="whd-aip-add"><?php esc_html_e( '+ Add a piece', 'whd' ); ?></button>
				<button type="button" class="button" id="whd-aip-add-many"><?php esc_html_e( 'One piece per photograph…', 'whd' ); ?></button>
			</p>

			<div class="whd-aip__bar">
				<span class="whd-aip__progress" id="whd-aip-progress" role="status" aria-live="polite"></span>
				<button type="button" class="button button-primary button-hero" id="whd-aip-run"><?php esc_html_e( 'Generate all', 'whd' ); ?></button>
			</div>
		</div>
		<?php
	}

	/* ─────────────────────────── step one: a draft to work on ─────────────────────────── */

	/**
	 * An empty draft product with the piece's photographs on it.
	 *
	 * The studio's steps all work on a product — it is what they check permission against, and
	 * what a drawn picture is attached to — so the draft comes first. Generating a piece again
	 * after it stopped carries on with the same draft rather than leaving one behind per attempt,
	 * and clears out pictures the stopped run drew but never got as far as putting in the gallery.
	 *
	 * @param array $args images (attachment ids), name, post_id (a draft from an earlier attempt).
	 * @return array|WP_Error { id, edit }
	 */
	public static function create( array $args ) {
		$ids = self::image_ids( $args['images'] ?? [] );
		if ( ! $ids ) {
			return new WP_Error( 'whd_aip_no_images', __( 'Add at least one photograph to this piece — everything is written and drawn from them.', 'whd' ) );
		}

		$product = null;
		$again   = absint( $args['post_id'] ?? 0 );
		if ( $again && 'product' === get_post_type( $again ) && 'draft' === get_post_status( $again ) && current_user_can( 'edit_post', $again ) ) {
			$product = wc_get_product( $again ) ?: null;
		}
		if ( ! $product ) {
			$again   = 0;
			$product = new WC_Product_Simple();
			$product->set_status( 'draft' );
			$product->set_catalog_visibility( 'visible' );
		}
		$product->set_name( self::tidy_name( (string) ( $args['name'] ?? '' ) ) ?: __( 'New piece', 'whd' ) );
		$product->set_image_id( $ids[0] );
		$product->set_gallery_image_ids( array_slice( $ids, 1 ) );

		$id = $product->save();
		if ( ! $id ) {
			return new WP_Error( 'whd_aip_save', __( 'The draft product could not be created.', 'whd' ) );
		}
		if ( $again ) {
			// Its gallery is back to the photographs, so anything drawn last time is no longer in it.
			self::clear_unused_drawings( $id );
		}
		// Photographs nobody has claimed yet belong to this piece in the media library.
		foreach ( $ids as $aid ) {
			if ( ! wp_get_post_parent_id( $aid ) ) {
				wp_update_post( [ 'ID' => $aid, 'post_parent' => $id ] );
			}
		}

		return [ 'id' => (int) $id, 'edit' => get_edit_post_link( $id, 'raw' ) ];
	}

	/** Pictures drawn for this draft by a run that stopped before they reached its gallery. */
	private static function clear_unused_drawings( int $post_id ) {
		$product = wc_get_product( $post_id );
		if ( ! $product || ! class_exists( 'WHD_AI_Images' ) ) {
			return;
		}
		$kept  = array_merge( [ (int) $product->get_image_id() ], array_map( 'intval', $product->get_gallery_image_ids() ) );
		$drawn = get_posts( [
			'post_type'   => 'attachment',
			'post_parent' => $post_id,
			'post_status' => 'inherit',
			'numberposts' => 50,
			'fields'      => 'ids',
			'meta_key'    => WHD_AI_Images::META, // phpcs:ignore WordPress.DB.SlowDBQuery -- a handful of attachments on one draft
		] );
		foreach ( $drawn as $id ) {
			if ( ! in_array( (int) $id, $kept, true ) && current_user_can( 'delete_post', $id ) ) {
				wp_delete_attachment( (int) $id, true );
			}
		}
	}

	/* ─────────────────────────── the last step: fill it in ─────────────────────────── */

	/**
	 * Put the written piece into its draft, the way the product screen's fields and Update would.
	 *
	 * The write step answers with `screen` — one entry per field the product screen fills in — and
	 * that is what arrives here, from the browser, so every value is checked again before it is
	 * written: a name is text, the descriptions are post HTML, a category has to exist, a size
	 * chart has to be one of the shop's. Whatever the shop typed under "Details you already know"
	 * wins over what the AI worked out.
	 *
	 * Order matters for the attributes: the variations are built first, because building them
	 * rewrites the product's attributes, and fabric, fit and care are added after, around them.
	 *
	 * @param array $args screen, details { name, price, sale, stock, sku, category }, parts,
	 *                    images [ { id, slot } ] (photographs and drawn pictures, in order),
	 *                    draft { name, keyphrase, keywords, images, story, used }.
	 * @return array|WP_Error
	 */
	public static function save( int $post_id, array $args ) {
		$product = wc_get_product( $post_id );
		if ( ! $product || 'product' !== get_post_type( $post_id ) ) {
			return new WP_Error( 'whd_aip_gone', __( 'The draft for this piece is not there any more. Generate it again.', 'whd' ) );
		}

		$screen  = is_array( $args['screen'] ?? null ) ? $args['screen'] : [];
		$details = is_array( $args['details'] ?? null ) ? $args['details'] : [];
		$draft   = is_array( $args['draft'] ?? null ) ? $args['draft'] : [];
		$parts   = array_values( array_intersect( WHD_AI_Studio::PARTS, array_map( 'sanitize_key', array_filter( (array) ( $args['parts'] ?? [] ), 'is_scalar' ) ) ) );
		$rows    = [];
		$notices = [];

		/* The pictures, photographs first, in the order the run planned them. */
		$finals = [];
		foreach ( (array) ( $args['images'] ?? [] ) as $entry ) {
			$id = absint( is_array( $entry ) ? ( $entry['id'] ?? 0 ) : $entry );
			if ( $id && wp_attachment_is_image( $id ) && ! isset( $finals[ $id ] ) ) {
				$finals[ $id ] = max( 0, (int) ( is_array( $entry ) ? ( $entry['slot'] ?? count( $finals ) ) : count( $finals ) ) );
			}
		}
		asort( $finals );
		$gallery = array_keys( $finals );

		/* Name and web address. A draft has no address of its own until somebody publishes it, and a
		   product with none fails the check that asks whether its keyphrase is in one. */
		$name = self::tidy_name( (string) ( $details['name'] ?? '' ) ) ?: self::tidy_name( (string) ( $screen['title'] ?? '' ) );
		if ( '' !== $name ) {
			$product->set_name( $name );
			$rows[] = [ 'label' => __( 'Name', 'whd' ), 'detail' => $name ];
		}
		$name      = $name ?: self::tidy_name( $product->get_name() );
		$keyphrase = sanitize_text_field( (string) ( $screen['seo']['keyphrase'] ?? ( $draft['keyphrase'] ?? '' ) ) );
		$slug      = sanitize_title( (string) ( $screen['slug'] ?? '' ) ) ?: WHD_AI_Studio::slug_for( $name, $keyphrase );
		if ( '' !== $slug ) {
			$product->set_slug( $slug );
		}

		if ( ! empty( $screen['description'] ) ) {
			$product->set_description( wp_kses_post( (string) $screen['description'] ) );
			$rows[] = [ 'label' => __( 'Description', 'whd' ), 'detail' => sprintf( /* translators: %d: word count */ __( '%d words', 'whd' ), str_word_count( wp_strip_all_tags( (string) $screen['description'] ) ) ) ];
		}
		if ( ! empty( $screen['short'] ) ) {
			$product->set_short_description( wp_kses_post( (string) $screen['short'] ) );
			$rows[] = [ 'label' => __( 'Short description', 'whd' ), 'detail' => '' ];
		}

		/* A category the shop pinned is the category; otherwise the ones the AI picked, if they exist. */
		$pinned = absint( $details['category'] ?? 0 );
		$cats   = $pinned && term_exists( $pinned, 'product_cat' )
			? [ $pinned ]
			: array_values( array_filter( array_map( 'absint', (array) ( $screen['categories'] ?? [] ) ), static fn( $id ) => $id && term_exists( $id, 'product_cat' ) ) );
		if ( $cats ) {
			$product->set_category_ids( $cats );
			$rows[] = [ 'label' => __( 'Categories', 'whd' ), 'detail' => implode( ', ', array_map( static function ( $id ) {
				$term = get_term( $id, 'product_cat' );
				return $term && ! is_wp_error( $term ) ? html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) : '';
			}, $cats ) ) ];
		}

		/* Price, sale price, stock and SKU: typed beats worked out. On a product with variations they
		   go on every variation instead, below. */
		$plan  = is_array( $screen['price'] ?? null ) ? $screen['price'] : [];
		$typed = static function ( string $key, string $plan_key ) use ( $details, $plan ) {
			$value = trim( (string) ( $details[ $key ] ?? '' ) );
			return '' !== $value ? $value : trim( (string) ( $plan[ $plan_key ] ?? '' ) );
		};
		$price = self::money( $typed( 'price', 'regular' ) );
		$sale  = self::money( $typed( 'sale', 'sale' ) );
		$stock = $typed( 'stock', 'stock' );
		$stock = is_numeric( $stock ) ? (string) max( 0, (int) $stock ) : '';
		$sku   = sanitize_text_field( $typed( 'sku', 'sku' ) );
		$tiers = is_array( $screen['tiers'] ?? null ) && ! empty( $screen['tiers']['levels'] ) ? $screen['tiers'] : null;

		if ( ! $tiers ) {
			if ( '' !== $price ) {
				$product->set_regular_price( $price );
			}
			if ( '' !== $sale && ( '' === $price || (float) $sale < (float) $price ) ) {
				$product->set_sale_price( $sale );
			}
			if ( '' !== $stock ) {
				$product->set_manage_stock( true );
				$product->set_stock_quantity( (int) $stock );
				$product->set_stock_status( (int) $stock > 0 ? 'instock' : 'outofstock' );
			}
			$money = array_filter( [ $price, '' !== $sale ? sprintf( /* translators: %s: sale price */ __( 'sale %s', 'whd' ), $sale ) : '' ] );
			if ( $money || '' !== $stock ) {
				$rows[] = [ 'label' => __( 'Price', 'whd' ), 'detail' => implode( ' · ', array_merge( $money, '' !== $stock ? [ sprintf( /* translators: %s: stock count */ __( '%s in stock', 'whd' ), $stock ) ] : [] ) ) ];
			}
		}
		if ( '' !== $sku ) {
			$taken = function_exists( 'wc_get_product_id_by_sku' ) ? (int) wc_get_product_id_by_sku( $sku ) : 0;
			if ( $taken && $taken !== $post_id ) {
				/* translators: %s: the SKU */
				$notices[] = sprintf( __( 'The SKU “%s” is already another product’s, so it was left off this one.', 'whd' ), $sku );
			} else {
				$product->set_sku( $sku );
			}
		}

		if ( $gallery ) {
			$product->set_image_id( $gallery[0] );
			$product->set_gallery_image_ids( array_slice( $gallery, 1 ) );
			$rows[] = [ 'label' => __( 'Gallery', 'whd' ), 'detail' => sprintf( /* translators: %d: number of pictures */ _n( '%d picture', '%d pictures', count( $gallery ), 'whd' ), count( $gallery ) ) ];
		}

		$product->set_status( 'draft' );
		$product->save();

		/* The size chart, chosen by the AI from the shop's own — or none, when none fits. */
		if ( array_key_exists( 'size_chart', $screen ) && class_exists( 'WHD_Size_Charts' ) ) {
			$chart = absint( $screen['size_chart'] );
			$valid = $chart && WHD_Size_Charts::CPT === get_post_type( $chart ) && 'publish' === get_post_status( $chart );
			if ( $valid ) {
				update_post_meta( $post_id, WHD_Size_Charts::META, $chart );
				$rows[] = [ 'label' => __( 'Size chart', 'whd' ), 'detail' => html_entity_decode( get_the_title( $chart ), ENT_QUOTES, 'UTF-8' ) ];
			} else {
				delete_post_meta( $post_id, WHD_Size_Charts::META );
				$rows[] = [ 'label' => __( 'Size chart: none fits this piece', 'whd' ), 'detail' => '' ];
			}
		}

		/* The search snippet and the phrase it is aimed at. */
		if ( ! empty( $screen['seo'] ) && is_array( $screen['seo'] ) ) {
			$title = sanitize_text_field( (string) ( $screen['seo']['title'] ?? '' ) );
			$desc  = sanitize_textarea_field( (string) ( $screen['seo']['description'] ?? '' ) );
			if ( '' !== $title ) {
				update_post_meta( $post_id, '_omc_seo_title', $title );
			}
			if ( '' !== $desc ) {
				update_post_meta( $post_id, '_omc_meta_description', $desc );
			}
			$rows[] = [ 'label' => __( 'SEO title, meta description and keyphrase', 'whd' ), 'detail' => $keyphrase ];
		}
		if ( '' !== $keyphrase && class_exists( 'WHD_SEO' ) ) {
			update_post_meta( $post_id, WHD_SEO::KEYPHRASE, $keyphrase );
		}

		/* Variations, through the Variation Tiers model, so the product opens in its grid like any other. */
		$variations = 0;
		if ( $tiers ) {
			if ( class_exists( 'WHDV_Model' ) ) {
				foreach ( (array) ( $tiers['combos'] ?? [] ) as $key => $combo ) {
					$combo = is_array( $combo ) ? $combo : [];
					if ( '' !== $price ) {
						$combo['regular_price'] = $price;
					}
					if ( '' !== $sale ) {
						$combo['sale_price'] = $sale;
					}
					if ( '' !== $stock ) {
						$combo['stock'] = $stock;
					}
					$tiers['combos'][ $key ] = $combo;
				}
				$built      = WHDV_Model::apply( $post_id, $tiers );
				$variations = (int) ( $built['created'] ?? 0 ) + (int) ( $built['updated'] ?? 0 );
				$notices    = array_merge( $notices, array_values( (array) ( $built['notices'] ?? [] ) ) );
				$labels     = array_filter( array_map( static fn( $l ) => is_array( $l ) ? sanitize_text_field( (string) ( $l['label'] ?? '' ) ) : '', (array) $tiers['levels'] ) );
				$rows[]     = [ 'label' => __( 'Variations in the Variation tiers grid', 'whd' ), 'detail' => implode( ' → ', $labels ) . ' · ' . $variations ];
			} else {
				$notices[] = __( 'The notes asked for options, but the Variation Tiers plugin is not active, so it was left as a single piece.', 'whd' );
			}
		}

		/* Fabric, fit and care, around the tiers rather than in place of them. */
		$attributes = is_array( $screen['attributes'] ?? null ) ? $screen['attributes'] : [];
		if ( $attributes && WHD_AI_Studio::add_attributes( $post_id, $attributes ) ) {
			$rows[] = [ 'label' => __( 'Product data', 'whd' ), 'detail' => implode( ' · ', array_map(
				static fn( $label, $value ) => sanitize_text_field( (string) $label ) . ': ' . sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ),
				array_keys( $attributes ),
				$attributes
			) ) ];
		}

		/* Alt text on every picture and the story beside them — the studio's own last step. */
		$finished = WHD_AI_Studio::finish( $post_id, [
			'parts'  => $parts,
			'images' => array_map( static fn( $id, $slot ) => [ 'id' => $id, 'slot' => $slot ], array_keys( $finals ), $finals ),
			'draft'  => array_merge( $draft, [ 'name' => $name ] ),
		] );
		if ( is_array( $finished ) ) {
			if ( ! empty( $finished['story'] ) ) {
				$rows[] = [ 'label' => __( 'Product story', 'whd' ), 'detail' => sprintf( /* translators: %d: sections */ __( '%d sections, each beside its picture', 'whd' ), (int) $finished['story'] ) ];
			}
			if ( ! empty( $finished['dressed'] ) ) {
				$rows[] = [ 'label' => __( 'Alt text on the pictures', 'whd' ), 'detail' => sprintf( /* translators: %d: pictures */ _n( '%d picture', '%d pictures', (int) $finished['dressed'], 'whd' ), (int) $finished['dressed'] ) ];
			}
		}

		/* Where it came from, anything still missing, and the score. */
		if ( class_exists( 'WHD_AI_Writer' ) ) {
			update_post_meta( $post_id, WHD_AI_Writer::ORIGIN, 'ai' );
		}
		$seo = class_exists( 'WHD_AI_Admin' ) ? WHD_AI_Admin::finish_seo( $post_id ) : [];

		/* Product Builder may publish it straight away; everything else stays a draft for somebody to read. */
		if ( 'publish' === ( $details['status'] ?? '' ) && current_user_can( 'publish_products' ) ) {
			$product = wc_get_product( $post_id );
			$product->set_status( 'publish' );
			$product->save();
		}
		$published = 'publish' === get_post_status( $post_id );

		$thumbs = [];
		foreach ( $gallery as $id ) {
			$thumbs[] = [
				'id'    => $id,
				'thumb' => (string) wp_get_attachment_image_url( $id, 'thumbnail' ),
				'large' => (string) wp_get_attachment_image_url( $id, 'large' ),
				'drawn' => (bool) get_post_meta( $id, class_exists( 'WHD_AI_Images' ) ? WHD_AI_Images::META : '_whd_ai_image', true ),
			];
		}

		return [
			'id'         => $post_id,
			'name'       => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
			'edit'       => get_edit_post_link( $post_id, 'raw' ),
			'view'       => $published ? (string) get_permalink( $post_id ) : (string) get_preview_post_link( $post_id ),
			'status'     => $published ? 'publish' : 'draft',
			'score'      => (int) ( $seo['score'] ?? 0 ),
			'rating'     => (string) ( $seo['rating'] ?? '' ),
			'rows'       => $rows,
			'notices'    => array_values( array_unique( $notices ) ),
			'pictures'   => $thumbs,
			'variations' => $variations,
		];
	}

	/* ─────────────────────────── the small jobs ─────────────────────────── */

	private static function image_ids( $ids ): array {
		$out = [];
		foreach ( array_slice( (array) $ids, 0, WHD_AI_Studio::TOTAL_MAX ) as $id ) {
			$id = absint( is_array( $id ) ? ( $id['id'] ?? 0 ) : $id );
			if ( $id && wp_attachment_is_image( $id ) && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	private static function tidy_name( string $name ): string {
		$name = html_entity_decode( sanitize_text_field( wp_strip_all_tags( $name ) ), ENT_QUOTES, 'UTF-8' );
		$name = trim( preg_replace( '/\s+/u', ' ', $name ) );

		return mb_substr( $name, 0, 120 );
	}

	/** "₱1,450" and "1450.00" are the same price. */
	private static function money( $value ): string {
		$value = preg_replace( '/[^0-9.]/', '', (string) $value );

		return '' === $value || ! is_numeric( $value ) ? '' : wc_format_decimal( $value );
	}

	/* ─────────────────────────── the wires ─────────────────────────── */

	public static function rest() {
		register_rest_route( 'whd/v1', '/ai/product/create', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_create' ],
			'permission_callback' => static function () {
				return current_user_can( 'edit_products' );
			},
		] );
		register_rest_route( 'whd/v1', '/ai/product/save', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_save' ],
			'permission_callback' => static function ( WP_REST_Request $r ) {
				$id = (int) $r->get_param( 'post_id' );
				return $id && 'product' === get_post_type( $id ) && current_user_can( 'edit_post', $id );
			},
		] );
	}

	public static function rest_create( WP_REST_Request $r ) {
		return self::answer( self::create( $r->get_json_params() ?: [] ) );
	}

	public static function rest_save( WP_REST_Request $r ) {
		return self::answer( self::save( (int) $r->get_param( 'post_id' ), $r->get_json_params() ?: [] ) );
	}

	private static function answer( $result ) {
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'message' => $result->get_error_message() ], 400 );
		}

		return new WP_REST_Response( $result, 200 );
	}
}
