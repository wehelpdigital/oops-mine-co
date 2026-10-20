<?php
/**
 * Bulk AI product adder: a rack of photographs in, a rack of finished products out.
 *
 * The job this solves is the one that takes an afternoon. A shipment arrives, there are forty
 * photographs on a phone, and every one of them needs a name, a description nobody wants to
 * write forty times, a search snippet, alt text on each picture, a category and a price. The
 * price is the only part of that a person actually knows.
 *
 * So the screen asks for the pictures and nothing else. Everything on a row except the images is
 * optional, and whatever is left blank is worked out from the photograph itself: the model looks
 * at the garment, says what it is — cut, colour, fabric, neckline, length, what it would be worn
 * with — and the copy is written from those facts rather than from a guess about a filename.
 *
 * Three steps per row, each its own request, because one call that did all three would sit for
 * three minutes behind a spinner and time out on the row that mattered:
 *
 *   look   the picture is read and described as facts
 *   write  the facts become a name, two descriptions, a snippet and alt text for each picture
 *   make   a draft product is created, scored, and given a photograph if it is short of one
 *
 * Nothing is published. Every product lands as a draft for somebody to look at, which is the
 * same promise the rest of the AI writing makes.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_AI_Products {

	const PAGE = 'whd-ai-products';

	/** How many pictures a product should end up with before we stop drawing more. */
	const WANT_IMAGES = 2;

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 11 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'rest' ] );
	}

	/* ─────────────────────────── where it lives ─────────────────────────── */

	/** Under AI Writer, beside AI Posts and AI Pages. */
	public static function menu() {
		if ( ! class_exists( 'WHD_AI_Writer' ) || ! current_user_can( 'edit_products' ) ) {
			return;
		}
		add_submenu_page(
			WHD_AI_Writer::PAGE_POST,
			__( 'Bulk products', 'whd' ),
			__( 'Bulk products', 'whd' ),
			'edit_products',
			self::PAGE,
			[ __CLASS__, 'screen' ]
		);
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'whd-ai-products', WHD_URL . 'admin/ai-products.css', [], WHD_VERSION );
		wp_enqueue_script( 'whd-ai-products', WHD_URL . 'admin/ai-products.js', [ 'wp-api-fetch', 'media-editor' ], WHD_VERSION, true );
		wp_localize_script( 'whd-ai-products', 'WHD_AIP', [
			'root'       => esc_url_raw( rest_url( 'whd/v1/ai/product/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'currency'   => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '',
			'categories' => self::category_choices(),
			'i18n'       => [
				'pickImages'  => __( 'Add photographs', 'whd' ),
				'onePhoto'    => __( '1 photograph · change', 'whd' ),
				'manyPhotos'  => __( '%d photographs · change', 'whd' ),
				'required'    => __( 'required', 'whd' ),
				'untitled'    => __( 'New piece', 'whd' ),
				'commands'    => __( 'Commands', 'whd' ),
				'worksItOut'  => __( 'Worked out for me', 'whd' ),
				'fromPhoto'   => __( 'from the photograph', 'whd' ),
				'fName'       => __( 'Name', 'whd' ),
				'fPrice'      => __( 'Price', 'whd' ),
				'fSale'       => __( 'Sale', 'whd' ),
				'fStock'      => __( 'Stock', 'whd' ),
				'fSku'        => __( 'SKU', 'whd' ),
				'fCat'        => __( 'Category', 'whd' ),
				'useImages'   => __( 'Use these', 'whd' ),
				'noImages'    => __( 'This row needs at least one photograph.', 'whd' ),
				'looking'     => __( 'Looking at the photograph…', 'whd' ),
				'writing'     => __( 'Writing it up…', 'whd' ),
				'making'      => __( 'Creating the product…', 'whd' ),
				'drawing'     => __( 'Drawing a second photograph…', 'whd' ),
				'done'        => __( 'Done', 'whd' ),
				'failed'      => __( 'Failed', 'whd' ),
				'aimedAt'     => __( 'Focus keyphrase “%s”', 'whd' ),
				'alsoUses'    => __( 'also uses %s', 'whd' ),
				'edit'        => __( 'Open it', 'whd' ),
				'remove'      => __( 'Remove this row', 'whd' ),
				'confirmRun'  => __( 'This will create %d draft product(s). Carry on?', 'whd' ),
				'commandHint' => __( 'e.g. comes in black, cream and navy — XS to XL — 1450, sale 999 — runs small, linen blend', 'whd' ),
			],
		] );
	}

	/** Every product category, so a row can be pinned to one by hand. */
	private static function category_choices(): array {
		$out   = [];
		$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 300 ] );
		foreach ( is_wp_error( $terms ) ? [] : $terms as $term ) {
			$out[] = [ 'id' => (int) $term->term_id, 'name' => $term->name ];
		}

		return $out;
	}

	/* ─────────────────────────── the screen ─────────────────────────── */

	public static function screen() {
		?>
		<div class="wrap whd-wrap whd-aip">
			<h1 class="whd-title"><?php esc_html_e( 'Bulk products', 'whd' ); ?></h1>
			<p class="whd-intro whd-aip__lead">
				<?php esc_html_e( 'One card per piece. Add the photographs, say anything you want in your own words, and leave the rest blank — everything else is worked out from the picture. Each piece is created as a draft.', 'whd' ); ?>
			</p>

			<details class="whd-aip__more">
				<summary><?php esc_html_e( 'How this works', 'whd' ); ?></summary>
				<div class="whd-aip__more-body">
					<p><?php esc_html_e( 'The model looks at each photograph and writes the name, both descriptions, the search snippet and the alt text on every image. Anything you fill in yourself is kept exactly as you typed it — your price, your SKU, your name for it.', 'whd' ); ?></p>
					<p><?php esc_html_e( 'The Commands box is for everything a field has no room for, written the way you would say it: “comes in black, cream and navy, XS to XL, 1450, runs small”. Options to choose between become real variations, built the way the Variation tiers grid builds them, so the product opens in that grid afterwards. Anything about the piece rather than the listing — a fabric, a fit note — goes into the writing instead.', 'whd' ); ?></p>
					<p><?php esc_html_e( 'Pieces are made one at a time, so a slow one never takes the others with it. Each finished product keeps a link on its card until you leave the page.', 'whd' ); ?></p>
				</div>
			</details>

			<?php if ( ! WHD_AI::ready() ) : ?>
				<div class="whd-note whd-note--warn">
					<?php esc_html_e( 'No model is connected yet, so nothing can be written.', 'whd' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=whd-ai' ) ); ?>"><?php esc_html_e( 'Connect one in WHD → AI content.', 'whd' ); ?></a>
				</div>
			<?php elseif ( ! class_exists( 'WHD_AI_Images' ) || ! WHD_AI_Images::ready() ) : ?>
				<div class="whd-note">
					<?php esc_html_e( 'Reading photographs works; drawing extra ones needs a Google AI key, so rows with a single picture will keep the single picture.', 'whd' ); ?>
				</div>
			<?php endif; ?>

			<div class="whd-aip__list" id="whd-aip-rows"></div>

			<p class="whd-aip__add">
				<button type="button" class="button" id="whd-aip-add"><?php esc_html_e( '+ Add a piece', 'whd' ); ?></button>
				<button type="button" class="button" id="whd-aip-add-many"><?php esc_html_e( 'One per photograph…', 'whd' ); ?></button>
			</p>

			<div class="whd-aip__bar">
				<span class="whd-aip__progress" id="whd-aip-progress" role="status" aria-live="polite"></span>
				<button type="button" class="button button-primary button-hero" id="whd-aip-run"><?php esc_html_e( 'Write and create them', 'whd' ); ?></button>
			</div>
		</div>
		<?php
	}

	/* ─────────────────────────── step one: look ─────────────────────────── */

	/**
	 * Read the photographs and say what the garment is.
	 *
	 * Facts only. The writing happens in the next step, against what this returns, so that a
	 * description can never describe a colour or a neckline the picture does not have.
	 *
	 * @param int[] $ids   Attachment IDs.
	 * @param array $hints What the person already filled in on the row.
	 * @return array|WP_Error
	 */
	public static function look( array $ids, array $hints = [] ) {
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return new WP_Error( 'whd_aip_no_images', __( 'That row has no photographs to look at.', 'whd' ) );
		}

		$known = array_filter( [
			'name'     => (string) ( $hints['name'] ?? '' ),
			'category' => (string) ( $hints['category_name'] ?? '' ),
			'commands' => self::commands( $hints ),
		] );

		$user = "Look at the photograph or photographs and describe the item as facts. It is usually a garment; it may be an accessory or something for the skin.\n\n"
			. ( $known ? "What the shop has already told us, which is true and must not be contradicted:\n" . wp_json_encode( $known ) . "\n\n" : '' )
			. "Answer as JSON and nothing else, with these keys:\n"
			. '{"garment":"what it is in two or three words, e.g. ribbed knit cardigan, or gel moisturiser",'
			. '"colour":"the main colour as a shopper would say it",'
			. '"other_colours":["any others visible"],'
			. '"fabric":"what it looks to be made of, hedged if unsure",'
			. '"texture":"ribbed, brushed, smooth, pleated…",'
			. '"silhouette":"how it sits on the body",'
			. '"neckline":"", "sleeve":"", "length":"", "closure":"",'
			. '"not_clothing":"if this is not something worn, say what kind of thing it is; otherwise empty",'
			. '"details":["the things worth pointing out: pockets, buttons, a slit, a tie"],'
			. '"styling":["what it would be worn with"],'
			. '"occasion":["where it would be worn"],'
			. '"season":"",'
			. '"category":"the single best shop category for it",'
			. '"name_ideas":["three short product names, no brand, no superlatives"],'
			. '"images":[{"shows":"what this particular photograph shows, one short phrase"}]'
			. "}\n\n"
			. "Rules:\n"
			. "- One entry in \"images\" per photograph, in the order they were sent.\n"
			. "- Say only what you can see. If you cannot tell the fabric, say so in the value rather than inventing one.\n"
			. "- No measurements, no price, no stock, no country of origin, no brand names, no claims about who made it.\n"
			. '- Plain words a shopper would use, not a catalogue.';

		$reply = WHD_AI::call( self::system(), $user, [ 'json' => true, 'images' => $ids, 'max_tokens' => 6000 ] );
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		$facts = self::decode( $reply );
		if ( ! is_array( $facts ) ) {
			return new WP_Error( 'whd_aip_unreadable', __( 'The model’s description of the picture could not be read.', 'whd' ) );
		}

		$facts['image_ids'] = $ids;

		return $facts;
	}

	/* ─────────────────────────── step two: write ─────────────────────────── */

	/**
	 * Turn the facts into everything a finished product page needs.
	 *
	 * The focus keyphrase and the supporting keywords come from the shop's researched keyword list,
	 * the same way Write with AI on the product screen chooses them: the model picks from the
	 * phrases that fit this piece (WHD_AI_Keywords::shortlist()), never one another page is already
	 * aimed at, and the copy has to carry every one word for word. The keyphrase used to be the
	 * product's own name, with the list as optional vocabulary, and the researched phrases mostly
	 * went unused. Only when nothing on the list fits does the name stand in.
	 *
	 * @param array $facts What look() returned.
	 * @param array $hints What the person filled in.
	 * @return array|WP_Error
	 */
	public static function write( array $facts, array $hints = [] ) {
		$ctx        = self::keyword_context( $facts, $hints );
		$brief      = $ctx ? WHD_AI_Studio::keyword_brief( $ctx ) : '';
		$vocabulary = $ctx ? [] : self::vocabulary( $facts );

		$given = array_filter( [
			'name'     => (string) ( $hints['name'] ?? '' ),
			'commands' => self::commands( $hints ),
		] );
		$levels = min( 3, defined( 'WHDV_MAX_LEVELS' ) ? (int) WHDV_MAX_LEVELS : 3 );

		$user = "Write the shop page for this piece, from these facts and nothing else.\n\n"
			. "FACTS\n" . wp_json_encode( self::without( $facts, [ 'image_ids' ] ) ) . "\n\n"
			. ( $given ? "THE SHOP'S OWN WORDS, which win over anything above:\n" . wp_json_encode( $given ) . "\n\n" : '' )
			. ( '' !== $brief ? $brief . "\n\n" : '' )
			. ( $vocabulary ? "WORDS SHOPPERS USE for this kind of thing. Work in the ones that genuinely fit, and ignore the rest:\n- " . implode( "\n- ", $vocabulary ) . "\n\n" : '' )
			. "Answer as JSON and nothing else:\n"
			. '{"name":"the product name, 3-7 words, no brand, no superlatives, no ALL CAPS",'
			. ( '' !== $brief ? '"keyphrase":"the focus keyphrase, copied exactly from the keyword list","keywords":["supporting keywords, copied exactly from the keyword list"],' : '' )
			. '"short":"the line under the title: one or two sentences, what it is and how it sits",'
			. '"description":"the long description as HTML: 220 to 300 words across four or five <p>, with two <h3> subheadings between them. No <h1>, no <h2>.",'
			. '"seo_title":"30 to 60 characters, starting with the keyphrase or the product name, one phrase — no pipes, no shop name",'
			. '"meta_description":"120 to 155 characters, says what it is and who it suits",'
			. '"attributes":{"Colour":"","Fabric":"","Fit":""},'
			. '"care":"one sentence, hedged — general advice for this fabric, never a care label you have not read",'
			. '"images":[{"alt":"what this photograph shows, for somebody who cannot see it","title":"a short title","caption":"","description":"a sentence"}],'
			. '"second_shot":"if a second photograph would help, one sentence describing what it should show; otherwise an empty string",'
			. '"plan":{"type":"simple or variable",'
			. '"levels":[{"label":"Colour","options":["Black","Cream"]}],'
			. '"price":"","sale":"","stock":"","sku":"","category":"","tags":[]}'
			. "}\n\n"
			. "Rules:\n"
			. ( '' !== $brief
				? "- \"keyphrase\" and \"keywords\": as the keyword brief above says — from the shop's list, copied exactly. Put the keyphrase in the name too when it reads as a natural product name (\"Korean Fashion Jacket\" can; \"Dress Korean Fashion\" cannot); a name that reads naturally matters more.\n"
					. "- The keyphrase appears word for word in all of these: the first sentence of \"short\", \"seo_title\" near the start, \"meta_description\", one of the two <h3> subheadings, and the \"alt\" of the first picture. Past that, at most twice more in all the copy.\n"
					. "- Every one of the \"keywords\" appears word for word in \"short\" or \"description\", in a sentence that reads naturally.\n"
				: "- The product name must appear word for word in all of these: the first sentence of \"short\", \"seo_title\", \"meta_description\", one of the two <h3> subheadings, and the \"alt\" of the first picture.\n" )
			. "- Write 220 to 300 words in \"description\". A page shorter than that reads as a stub, and one mention of the name in a short page reads as stuffing.\n"
			. "- Short sentences. Most under twenty words, and no sentence carrying three ideas joined by dashes where two full stops would do.\n"
			. "- One entry in \"images\" per photograph in the facts, in the same order.\n"
			. "- Never state a measurement, a delivery time, a discount, a stock figure, a review count, a brand or a person's name.\n"
			. "- Never say where it was made unless the shop said so above.\n"
			. "- Write as the shop, to one person, the way the house rules say.\n\n"
			. "THE PLAN is how the product is built, and it is where the shop's commands are obeyed.\n"
			. "- \"variable\" only when the commands name options to choose between — colours, sizes, lengths.\n"
			. "  A piece that comes one way is \"simple\" and \"levels\" is empty. If the commands are empty,\n"
			. "  \"levels\" is empty: the colour you can see in the photograph is a fact about the piece, not a\n"
			. "  choice a shopper makes, and it belongs in the copy.\n"
			. "- A level needs two or more options. One option is not a choice.\n"
			. sprintf( "- At most %d levels, in the order a shopper picks them: colour first, then size.\n", $levels )
			. "- Use the shop's own words for an option. \"S, M, L\" stays \"S\", \"M\", \"L\"; do not invent sizes,\n"
			. "  colours or a size run that was not asked for.\n"
			. "- price, sale, stock, sku, category and tags: fill one only when the commands say so. Leave the\n"
			. "  rest empty rather than guessing — an invented price is worse than no price.\n"
			. '- Anything in the commands that is about the writing, not the building — a fabric, a fit note, what '
			. 'to call it — belongs in the copy above, not here.';

		/*
		 * A generous budget, because these models reason before they answer and the reasoning is
		 * drawn from the same allowance. Asked for a 300-word description on 4,000, the reply
		 * came back cut off mid-string — which, when JSON was asked for, is a reply that cannot
		 * be read at all, and the row simply failed.
		 */
		$reply = WHD_AI::call( self::system(), $user, [ 'json' => true, 'max_tokens' => 12000 ] );
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		$draft = self::decode( $reply );
		if ( ! is_array( $draft ) || '' === trim( (string) ( $draft['name'] ?? '' ) ) ) {
			return new WP_Error(
				'whd_aip_unwritable',
				sprintf(
					/* translators: %d: how many characters came back */
					__( 'The write-up came back unreadable (%d characters) — usually that means it was cut off part way through. Raise the token limit in WHD → AI content and try the row again.', 'whd' ),
					strlen( (string) $reply )
				)
			);
		}

		/* The shop's own words win, every time. */
		if ( '' !== trim( (string) ( $hints['name'] ?? '' ) ) ) {
			$draft['name'] = sanitize_text_field( (string) $hints['name'] );
		}

		$draft['name']           = self::tidy_name( (string) $draft['name'] );
		$draft['image_ids']      = (array) ( $facts['image_ids'] ?? [] );
		$draft['category_guess'] = (string) ( $facts['category'] ?? '' );

		/* The list is stored in lower case and copied word for word: "korean" → "Korean". */
		if ( class_exists( 'WHD_AI_Studio' ) ) {
			foreach ( [ 'short', 'description', 'seo_title', 'meta_description' ] as $field ) {
				$draft[ $field ] = WHD_AI_Studio::proper( (string) ( $draft[ $field ] ?? '' ) );
			}
			foreach ( (array) ( $draft['images'] ?? [] ) as $i => $image ) {
				foreach ( is_array( $image ) ? [ 'alt', 'title', 'caption', 'description' ] : [] as $field ) {
					$draft['images'][ $i ][ $field ] = WHD_AI_Studio::proper( (string) ( $image[ $field ] ?? '' ) );
				}
			}
		}

		$copy  = wp_strip_all_tags( (string) ( $draft['short'] ?? '' ) . ' ' . (string) ( $draft['description'] ?? '' ) );
		$draft = array_merge( $draft, $ctx ? self::aim( $draft, $copy, $ctx ) : [ 'keyphrase' => self::phrase_from_name( $draft['name'] ), 'keywords' => [] ] );

		/*
		 * The copy is checked for every keyword it is aimed at. There is no second pass here to put
		 * one in — a row already runs three model calls — so a miss is said on the row instead.
		 */
		$aimed           = array_values( array_filter( array_merge( [ (string) $draft['keyphrase'] ], (array) $draft['keywords'] ) ) );
		$house           = WHD_AI::check( $copy, $aimed );
		$house['issues'] = array_values( array_filter( (array) $house['issues'], static fn( $i ) => 'keyword' !== ( $i['type'] ?? '' ) ) );
		foreach ( $ctx ? (array) $house['keywords'] : [] as $keyword => $in ) {
			if ( ! $in ) {
				$house['issues'][] = [
					'type'   => 'keyword',
					/* translators: %s: a keyword */
					'detail' => sprintf( __( 'The keyword “%s” from the shop\'s list is not in the copy word for word — add it when you edit the product.', 'whd' ), $keyword ),
				];
			}
		}
		$draft['house'] = $house;

		return $draft;
	}

	/**
	 * What the keyword brief needs: the list phrases that fit this piece. Empty when the studio or
	 * the list is missing, and the old vocabulary hint is used instead.
	 */
	private static function keyword_context( array $facts, array $hints ): array {
		if ( ! class_exists( 'WHD_AI_Studio' ) || ! class_exists( 'WHD_AI_Keywords' ) ) {
			return [];
		}
		$about = [];
		foreach ( [ 'garment', 'category', 'colour', 'fabric', 'texture', 'silhouette', 'neckline', 'sleeve', 'length', 'season', 'not_clothing' ] as $key ) {
			$about[] = is_scalar( $facts[ $key ] ?? null ) ? (string) $facts[ $key ] : '';
		}
		foreach ( [ 'other_colours', 'occasion' ] as $key ) {
			$about[] = implode( ' ', array_filter( (array) ( $facts[ $key ] ?? [] ), 'is_scalar' ) );
		}
		$about[] = (string) ( $hints['name'] ?? '' );
		$about[] = (string) ( $hints['category_name'] ?? '' );
		$about[] = self::commands( $hints );

		return [
			'keywords'  => [],
			'shortlist' => WHD_AI_Keywords::shortlist( implode( ' ', $about ), 0, 20 ),
			'post_id'   => 0,
			'facts'     => $facts,
		];
	}

	/**
	 * WHD_AI_Studio::aim(), then held to what the copy really says. The studio repairs copy that
	 * misses its keyphrase; this screen does not, so a focus phrase the copy does not carry gives
	 * way to one from the list that it does, and supporting keywords the copy never uses are
	 * swapped for list phrases it does use.
	 */
	private static function aim( array $draft, string $copy, array $ctx ): array {
		$aim  = WHD_AI_Studio::aim( $draft, (string) $draft['name'], $ctx );
		$text = mb_strtolower( $copy . ' ' . $draft['name'] );
		$in   = static fn( string $k ): bool => '' !== trim( $k ) && false !== mb_strpos( $text, mb_strtolower( trim( $k ) ) );

		if ( ! $in( (string) $aim['keyphrase'] ) ) {
			foreach ( $ctx['shortlist'] as $k ) {
				if ( ! $k['taken'] && ! $k['general'] && $in( $k['keyword'] ) ) {
					$aim['keyphrase']        = $k['keyword'];
					$aim['keyphrase_from']   = 'list';
					$aim['keyphrase_volume'] = $k['volume'];
					break;
				}
			}
		}
		$keywords = array_values( array_filter( (array) $aim['keywords'], $in ) );
		foreach ( $ctx['shortlist'] as $k ) {
			if ( count( $keywords ) < 2 && $in( $k['keyword'] ) && ! in_array( $k['keyword'], $keywords, true ) && mb_strtolower( $k['keyword'] ) !== mb_strtolower( $aim['keyphrase'] ) ) {
				$keywords[] = $k['keyword'];
			}
		}
		// Nothing from the list made it in: say so, rather than quietly aiming at nothing.
		$aim['keywords'] = $keywords ?: (array) $aim['keywords'];

		return $aim;
	}

	/* ─────────────────────────── step three: make ─────────────────────────── */

	/**
	 * Create the draft product, hang the pictures on it, and score it.
	 *
	 * @param array $draft What write() returned.
	 * @param array $hints What the person filled in.
	 * @return array|WP_Error
	 */
	public static function make( array $draft, array $hints = [] ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'whd_aip_no_woo', __( 'WooCommerce is not active, so there is nothing to create a product in.', 'whd' ) );
		}
		$name = self::tidy_name( (string) ( $draft['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'whd_aip_no_name', __( 'That row has no product name.', 'whd' ) );
		}

		$ids = array_values( array_filter( array_map( 'absint', (array) ( $draft['image_ids'] ?? [] ) ) ) );

		/* The pictures get their words before anything points at them. */
		self::dress_images( $ids, (array) ( $draft['images'] ?? [] ), $name );

		/* A single photograph is a thin product page, so draw the one the writer asked for. */
		$drawn = 0;
		if ( count( $ids ) < self::WANT_IMAGES && '' !== trim( (string) ( $draft['second_shot'] ?? '' ) ) ) {
			$extra = self::draw( (string) $draft['second_shot'], $name );
			if ( $extra ) {
				$ids[]  = $extra;
				$drawn++;
			}
		}

		$product = new WC_Product_Simple();
		$product->set_name( $name );
		/* A draft WooCommerce saves has no post_name at all until somebody publishes it, and a
		   product with no web address fails the check that asks whether its phrase is in one.
		   Naming it now also means the URL matches the name somebody chose, not a later edit —
		   with the focus keyphrase in front when the name does not carry it. */
		$keyphrase = trim( (string) ( $draft['keyphrase'] ?? '' ) ) ?: self::phrase_from_name( $name );
		$product->set_slug( class_exists( 'WHD_AI_Studio' ) ? WHD_AI_Studio::slug_for( $name, $keyphrase ) : sanitize_title( $name ) );
		$product->set_status( 'draft' );
		$product->set_short_description( (string) ( $draft['short'] ?? '' ) );
		$product->set_description( (string) ( $draft['description'] ?? '' ) );
		$product->set_catalog_visibility( 'visible' );

		/* The row's fields first, then anything the commands asked for. */
		$plan  = is_array( $draft['plan'] ?? null ) ? $draft['plan'] : [];
		$price = self::money( self::pick( $hints, $plan, 'price' ) );
		if ( '' !== $price ) {
			$product->set_regular_price( $price );
		}
		$sale = self::money( self::pick( $hints, $plan, 'sale' ) );
		if ( '' !== $sale ) {
			$product->set_sale_price( $sale );
		}
		$sku = sanitize_text_field( self::pick( $hints, $plan, 'sku' ) );
		if ( '' !== $sku && function_exists( 'wc_get_product_id_by_sku' ) && ! wc_get_product_id_by_sku( $sku ) ) {
			$product->set_sku( $sku );
		}
		$stock = self::pick( $hints, $plan, 'stock' );
		if ( '' !== trim( $stock ) ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( (int) $stock );
		}
		$product->set_stock_status( '' !== trim( $stock ) && (int) $stock < 1 ? 'outofstock' : 'instock' );

		if ( $ids ) {
			$product->set_image_id( $ids[0] );
			if ( count( $ids ) > 1 ) {
				$product->set_gallery_image_ids( array_slice( $ids, 1 ) );
			}
		}

		$category = self::category_for( $hints, self::pick( $hints, $plan, 'category' ) ?: (string) ( $draft['category_guess'] ?? '' ) );
		if ( $category ) {
			$product->set_category_ids( [ $category ] );
		}

		$id = $product->save();
		if ( is_wp_error( $id ) || ! $id ) {
			return is_wp_error( $id ) ? $id : new WP_Error( 'whd_aip_save', __( 'The product could not be saved.', 'whd' ) );
		}

		/*
		 * The descriptive attributes, minus anything that is about to become a tier. Left in,
		 * a product built from "comes in black, cream and navy" ended up listing Colour twice:
		 * once as the swatch row a shopper chooses from, and once as a flat line of text saying
		 * what the model saw in the photograph.
		 */
		$tier_labels = [];
		foreach ( (array) ( $plan['levels'] ?? [] ) as $level ) {
			if ( is_array( $level ) && '' !== trim( (string) ( $level['label'] ?? '' ) ) ) {
				$tier_labels[] = mb_strtolower( trim( (string) $level['label'] ) );
			}
		}
		self::attributes( (int) $id, (array) ( $draft['attributes'] ?? [] ), (string) ( $draft['care'] ?? '' ), $tier_labels );

		/*
		 * The options the commands asked for, as real variations — and only ever from a command.
		 * Nobody who left the box empty is asking for a variable product, however many colours
		 * the model can see in the photograph.
		 */
		$variations = '' !== self::commands( $hints )
			? self::build_variations( (int) $id, $plan, $price, $sale, $stock )
			: [ 'made' => 0, 'notices' => [] ];

		/* Where it came from, and what it is aimed at. */
		if ( class_exists( 'WHD_AI_Writer' ) ) {
			update_post_meta( $id, WHD_AI_Writer::ORIGIN, 'ai' );
		}
		update_post_meta( $id, '_omc_seo_title', self::fit( (string) ( $draft['seo_title'] ?? '' ), 60 ) ?: $name );
		update_post_meta( $id, '_omc_meta_description', self::fit( (string) ( $draft['meta_description'] ?? '' ), 155 ) );
		if ( class_exists( 'WHD_SEO' ) ) {
			update_post_meta( $id, WHD_SEO::KEYPHRASE, sanitize_text_field( $keyphrase ) );
		}
		$keywords = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $draft['keywords'] ?? [] ) ) ) );
		if ( class_exists( 'WHD_AI_Keywords' ) ) {
			WHD_AI_Keywords::mark_used( array_merge( [ $keyphrase ], $keywords ) );
		}

		/* Anything still missing, and the score. */
		$seo = class_exists( 'WHD_AI_Admin' )
			? WHD_AI_Admin::finish_seo( (int) $id )
			: [];

		return [
			'id'         => (int) $id,
			'name'       => $name,
			'keyphrase'  => $keyphrase,
			'keywords'   => $keywords,
			'edit'       => get_edit_post_link( $id, 'raw' ),
			'images'     => count( $ids ),
			'drawn'      => $drawn,
			'score'      => (int) ( $seo['score'] ?? 0 ),
			'rating'     => (string) ( $seo['rating'] ?? '' ),
			'house'      => (array) ( $draft['house']['issues'] ?? [] ),
			'variations' => (int) $variations['made'],
			'tiers'      => array_values( array_filter( array_map(
				static function ( $level ) {
					return is_array( $level ) ? (string) ( $level['label'] ?? '' ) : '';
				},
				(array) ( $plan['levels'] ?? [] )
			) ) ),
			'notices'    => $variations['notices'],
		];
	}

	/* ─────────────────────────── the small jobs ─────────────────────────── */

	/** The voice, the rules and the banned words the rest of the writing already uses. */
	private static function system(): string {
		return WHD_AI::system_prompt();
	}

	/**
	 * What the shop typed into the row's command box.
	 *
	 * Free text, on purpose. "Comes in black, cream and navy, XS to XL, 1450, runs small" is how
	 * somebody actually describes a piece, and asking them to put each part in its own little
	 * field is how a bulk screen stops being quicker than doing it by hand. The model reads it
	 * both ways: as facts for the copy, and as instructions for how to build the product.
	 */
	private static function commands( array $hints ): string {
		$text = (string) ( $hints['commands'] ?? ( $hints['notes'] ?? '' ) );

		return trim( preg_replace( '/[ \t]+/', ' ', $text ) );
	}

	/**
	 * A value the shop typed into the row wins over one the model read out of the commands.
	 *
	 * Both are the shop talking; the field is just the less ambiguous of the two.
	 */
	private static function pick( array $hints, array $plan, string $key ): string {
		$typed = trim( (string) ( $hints[ $key ] ?? '' ) );

		return '' !== $typed ? $typed : trim( (string) ( $plan[ $key ] ?? '' ) );
	}

	/**
	 * Build the options the commands asked for, as real WooCommerce variations.
	 *
	 * Handed to the Variation Tiers model rather than done here: it already knows how to create
	 * a global attribute, find or make each term, switch the product to variable and reconcile
	 * the combinations — and a product built through it opens in the Variation tiers grid like
	 * any other, instead of being a second kind of variable product nothing else understands.
	 *
	 * @return array{made:int,notices:string[]}
	 */
	private static function build_variations( int $product_id, array $plan, string $price, string $sale, string $stock ): array {
		$out = [ 'made' => 0, 'notices' => [] ];

		/*
		 * A level is a choice, so it needs at least two things to choose between.
		 *
		 * Asked to describe a photograph with no commands at all, the model still volunteered
		 * "Colour: Black" — and that built a variable product with exactly one variation, which
		 * moved the price off the parent and left the shop page showing no price at all. One
		 * option is a fact about the piece, not a choice a shopper makes.
		 */
		$levels = array_values( array_filter( (array) ( $plan['levels'] ?? [] ), static function ( $level ) {
			if ( ! is_array( $level ) || '' === trim( (string) ( $level['label'] ?? '' ) ) ) {
				return false;
			}
			$options = array_filter( array_map( 'trim', array_map( 'strval', (array) ( $level['options'] ?? [] ) ) ) );

			return count( array_unique( $options ) ) > 1;
		} ) );
		if ( ! $levels ) {
			return $out;
		}
		if ( ! class_exists( 'WHDV_Model' ) ) {
			$out['notices'][] = __( 'The commands asked for options, but the Variation Tiers plugin is not active, so it was left as a single piece.', 'whd' );
			return $out;
		}

		$max   = defined( 'WHDV_MAX_LEVELS' ) ? (int) WHDV_MAX_LEVELS : 3;
		$state = [ 'levels' => [], 'combos' => [], 'groups' => [], 'dirty' => true ];

		foreach ( array_slice( $levels, 0, $max ) as $level ) {
			$options = [];
			foreach ( (array) $level['options'] as $option ) {
				$name = trim( wp_strip_all_tags( is_array( $option ) ? (string) ( $option['name'] ?? '' ) : (string) $option ) );
				if ( '' === $name ) {
					continue;
				}
				$key = sanitize_title( $name );
				if ( '' === $key || isset( $options[ $key ] ) ) {
					continue;
				}
				$options[ $key ] = [ 'key' => $key, 'name' => $name ];
			}
			if ( $options ) {
				$state['levels'][] = [
					'label'   => sanitize_text_field( (string) $level['label'] ),
					'type'    => 'select',
					'options' => array_values( $options ),
				];
			}
		}
		if ( ! $state['levels'] ) {
			return $out;
		}

		/* Every combination, on, carrying the row's price and stock. */
		$keys = [ [] ];
		foreach ( $state['levels'] as $level ) {
			$next = [];
			foreach ( $keys as $so_far ) {
				foreach ( $level['options'] as $option ) {
					$next[] = array_merge( $so_far, [ $option['key'] ] );
				}
			}
			$keys = $next;
		}
		foreach ( $keys as $combo ) {
			$state['combos'][ implode( '|', $combo ) ] = [
				'on'            => 1,
				'sku'           => '',
				'regular_price' => $price,
				'sale_price'    => $sale,
				'stock'         => $stock,
				'image'         => 0,
			];
		}

		$result          = WHDV_Model::apply( $product_id, $state );
		$out['made']     = (int) ( $result['created'] ?? 0 ) + (int) ( $result['updated'] ?? 0 );
		$out['notices']  = array_values( (array) ( $result['notices'] ?? [] ) );

		return $out;
	}

	/** Words shoppers use for this kind of thing, from the researched list. */
	private static function vocabulary( array $facts ): array {
		if ( ! class_exists( 'WHD_AI_Keywords' ) ) {
			return [];
		}
		$seeds = array_filter( [
			(string) ( $facts['garment'] ?? '' ),
			(string) ( $facts['category'] ?? '' ),
			(string) ( $facts['colour'] ?? '' ),
		] );
		$out = [];
		foreach ( $seeds as $seed ) {
			$word = trim( (string) array_slice( preg_split( '/\s+/u', $seed ), -1 )[0] ?? '' );
			if ( mb_strlen( $word ) < 3 ) {
				continue;
			}
			foreach ( (array) WHD_AI_Keywords::all( [ 'search' => $word, 'limit' => 6 ] ) as $row ) {
				$phrase = (string) ( is_object( $row ) ? $row->keyword : ( $row['keyword'] ?? '' ) );
				if ( '' !== $phrase ) {
					$out[ $phrase ] = true;
				}
			}
		}

		return array_slice( array_keys( $out ), 0, 12 );
	}

	/** Alt, title, caption and description on each photograph. */
	private static function dress_images( array $ids, array $words, string $name ) {
		foreach ( array_values( $ids ) as $i => $id ) {
			$w   = is_array( $words[ $i ] ?? null ) ? $words[ $i ] : [];
			$alt = sanitize_text_field( (string) ( $w['alt'] ?? '' ) ) ?: $name;

			/* The first picture is the one a search engine reads, so it says what the thing is
			   called even when the model described the scene instead. */
			if ( 0 === $i && false === mb_stripos( $alt, $name ) ) {
				$alt = $name . ' — ' . lcfirst( $alt );
			}

			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
			wp_update_post( [
				'ID'           => $id,
				'post_title'   => sanitize_text_field( (string) ( $w['title'] ?? '' ) ) ?: $alt,
				'post_excerpt' => sanitize_text_field( (string) ( $w['caption'] ?? '' ) ),
				'post_content' => sanitize_textarea_field( (string) ( $w['description'] ?? '' ) ) ?: $alt,
			] );
		}
	}

	/** One more photograph, in the shop's own look. */
	private static function draw( string $brief, string $name ) {
		if ( ! class_exists( 'WHD_AI_Images' ) || ! WHD_AI_Images::ready() ) {
			return 0;
		}
		$id = WHD_AI_Images::make(
			$brief,
			sanitize_title( $name ) ?: 'product',
			$name . ' — ' . wp_strip_all_tags( $brief ),
			'',
			[ 'aspect' => '3:4' ]
		);

		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/** What the model saw, as product attributes somebody can filter on later. */
	private static function attributes( int $id, array $pairs, string $care, array $skip = [] ) {
		$keep = [];
		foreach ( $pairs as $label => $value ) {
			$label = sanitize_text_field( (string) $label );
			$label = function_exists( 'mb_convert_case' ) ? mb_convert_case( $label, MB_CASE_TITLE, 'UTF-8' ) : ucwords( $label );
			$value = sanitize_text_field( (string) $value );
			if ( '' === $label || '' === $value ) {
				continue;
			}
			if ( in_array( mb_strtolower( $label ), $skip, true ) ) {
				continue; // a shopper chooses this one; it is not a line of text about the piece
			}
			$attribute = new WC_Product_Attribute();
			$attribute->set_name( $label );
			$attribute->set_options( [ $value ] );
			$attribute->set_visible( true );
			$attribute->set_variation( false );
			$keep[] = $attribute;
		}
		if ( '' !== trim( $care ) ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_name( __( 'Care', 'whd' ) );
			$attribute->set_options( [ sanitize_text_field( $care ) ] );
			$attribute->set_visible( true );
			$attribute->set_variation( false );
			$keep[] = $attribute;
		}
		if ( ! $keep ) {
			return;
		}
		$product = wc_get_product( $id );
		if ( $product ) {
			$product->set_attributes( $keep );
			$product->save();
		}
	}

	/** The category the row asked for, or the nearest existing one to what the model guessed. */
	private static function category_for( array $hints, string $guess ) {
		$chosen = (int) ( $hints['category'] ?? 0 );
		if ( $chosen && term_exists( $chosen, 'product_cat' ) ) {
			return $chosen;
		}
		$guess = trim( $guess );
		if ( '' === $guess ) {
			return 0;
		}
		$term = get_term_by( 'name', $guess, 'product_cat' );
		if ( $term ) {
			return (int) $term->term_id;
		}
		/* A near miss is better than nothing: "Knitwear" for "knitwear and cardigans". */
		foreach ( (array) get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 300 ] ) as $t ) {
			if ( is_object( $t ) && false !== mb_stripos( $guess, $t->name ) ) {
				return (int) $t->term_id;
			}
		}

		return 0;
	}

	/** A product ranks for the thing it is, and its name says what it is. */
	private static function phrase_from_name( string $name ): string {
		$name  = html_entity_decode( $name, ENT_QUOTES, 'UTF-8' );
		$name  = preg_replace( '/[^\p{L}\p{N}\s-]+/u', ' ', $name );
		$words = preg_split( '/\s+/u', mb_strtolower( trim( (string) $name ) ), -1, PREG_SPLIT_NO_EMPTY );

		return $words ? trim( implode( ' ', array_slice( $words, -4 ) ) ) : '';
	}

	private static function tidy_name( string $name ): string {
		$name = sanitize_text_field( wp_strip_all_tags( $name ) );
		$name = trim( preg_replace( '/\s+/u', ' ', $name ) );

		return mb_substr( $name, 0, 120 );
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

	private static function money( $value ): string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$value = preg_replace( '/[^0-9.]/', '', $value );

		return '' === $value ? '' : (string) (float) $value;
	}

	private static function without( array $array, array $keys ): array {
		foreach ( $keys as $key ) {
			unset( $array[ $key ] );
		}

		return $array;
	}

	/** JSON from a model, which sometimes arrives wrapped in a code fence whatever you ask. */
	private static function decode( string $reply ) {
		$reply = trim( $reply );
		$reply = (string) preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', $reply );
		$data  = json_decode( $reply, true );
		if ( is_array( $data ) ) {
			return $data;
		}
		$start = strpos( $reply, '{' );
		$end   = strrpos( $reply, '}' );
		if ( false === $start || false === $end || $end <= $start ) {
			return null;
		}

		return json_decode( substr( $reply, $start, $end - $start + 1 ), true );
	}

	/* ─────────────────────────── the wires ─────────────────────────── */

	public static function rest() {
		$can = static function () {
			return current_user_can( 'edit_products' );
		};
		foreach ( [ 'look', 'write', 'make' ] as $step ) {
			register_rest_route( 'whd/v1', '/ai/product/' . $step, [
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'rest_' . $step ],
				'permission_callback' => $can,
			] );
		}
	}

	public static function rest_look( WP_REST_Request $r ) {
		return self::answer( self::look(
			(array) $r->get_param( 'images' ),
			(array) $r->get_param( 'hints' )
		) );
	}

	public static function rest_write( WP_REST_Request $r ) {
		return self::answer( self::write(
			(array) $r->get_param( 'facts' ),
			(array) $r->get_param( 'hints' )
		) );
	}

	public static function rest_make( WP_REST_Request $r ) {
		return self::answer( self::make(
			(array) $r->get_param( 'draft' ),
			(array) $r->get_param( 'hints' )
		) );
	}

	private static function answer( $result ) {
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'message' => $result->get_error_message() ], 400 );
		}

		return new WP_REST_Response( $result, 200 );
	}
}
