<?php
/**
 * Product story: an editable block of content on the product page, below the gallery and above
 * "You might also like".
 *
 * It exists for two readers at once. The shopper gets the part a spec list never covers — how a
 * piece actually wears on a Tuesday, what it sits next to in a closet, when to size up. Search
 * engines get real prose on a page that would otherwise be a title, a price and a photograph.
 *
 * Built on the same block format as the popups and emails ({ settings, blocks[] }), so it reuses
 * WHD_Blocks for the definitions, sanitising and rendering, and the existing drag-and-drop editor
 * screen for the editing. The only new part is where it is stored: post meta on the product rather
 * than a site-wide option.
 *
 * @package WHD
 */

defined( 'ABSPATH' ) || exit;

class WHD_Product_Story {

	const META = '_whd_story';

	public static function init() {
		add_action( 'add_meta_boxes', [ __CLASS__, 'meta_box' ] );
		add_action( 'save_post_product', [ __CLASS__, 'save_toggle' ], 10, 2 );

		// 10 is the tabs, 20 the up-sells and related products. 15 puts the story between them.
		add_action( 'woocommerce_after_single_product_summary', [ __CLASS__, 'render' ], 15 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
	}

	/* ─────────────────────────── Storage ─────────────────────────── */

	/** The story design for a product, defaults filled in. */
	public static function get( $product_id ) {
		$raw = get_post_meta( (int) $product_id, self::META, true );
		$raw = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		return WHD_Blocks::with_defaults( is_array( $raw ) ? $raw : [], 'popup' );
	}

	/** Store a design against a product. Returns what was actually saved. */
	public static function save( $product_id, $design ) {
		$clean = WHD_Blocks::sanitize_design( $design, 'popup' );
		update_post_meta( (int) $product_id, self::META, wp_slash( wp_json_encode( $clean ) ) );
		return $clean;
	}

	/** Has this product been given a story with something in it? */
	public static function has( $product_id ) {
		$raw = get_post_meta( (int) $product_id, self::META, true );
		$raw = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		return ! empty( $raw['blocks'] );
	}

	/** Is the story switched on for this product? Off by default only once a story exists. */
	public static function enabled( $product_id ) {
		if ( ! self::has( $product_id ) ) {
			return false;
		}
		return '0' !== (string) get_post_meta( (int) $product_id, self::META . '_off', true );
	}

	/* ─────────────────────────── Product screen ─────────────────────────── */

	public static function meta_box() {
		add_meta_box(
			'whd-product-story',
			__( 'Product story', 'whd' ),
			[ __CLASS__, 'box' ],
			'product',
			'side',
			'default'
		);
	}

	public static function box( $post ) {
		$has   = self::has( $post->ID );
		$on    = self::enabled( $post->ID );
		$count = $has ? count( self::get( $post->ID )['blocks'] ) : 0;
		$edit  = admin_url( 'admin.php?page=whd-editor&type=product&id=' . (int) $post->ID );
		wp_nonce_field( 'whd_story_toggle', 'whd_story_nonce' );
		?>
		<p class="description">
			<?php esc_html_e( 'The written part of the page: how the piece wears, what it goes with, when to size up. It shows below the gallery, above “You might also like”.', 'whd' ); ?>
		</p>
		<?php if ( $has ) : ?>
			<p><strong><?php
				/* translators: %d: number of blocks */
				printf( esc_html( _n( '%d block', '%d blocks', $count, 'whd' ) ), (int) $count );
			?></strong></p>
			<p>
				<label>
					<input type="checkbox" name="whd_story_on" value="1" <?php checked( $on ); ?>>
					<?php esc_html_e( 'Show on the product page', 'whd' ); ?>
				</label>
			</p>
		<?php else : ?>
			<p><em><?php esc_html_e( 'No story yet.', 'whd' ); ?></em></p>
		<?php endif; ?>
		<p>
			<a href="<?php echo esc_url( $edit ); ?>" class="button button-primary">
				<?php echo $has ? esc_html__( 'Edit the story', 'whd' ) : esc_html__( 'Write the story', 'whd' ); ?>
			</a>
		</p>
		<?php if ( 'auto-draft' === $post->post_status ) : ?>
			<p class="description"><?php esc_html_e( 'Save the product first — the builder needs somewhere to put it.', 'whd' ); ?></p>
		<?php endif; ?>
		<?php
	}

	public static function save_toggle( $post_id, $post ) {
		if ( ! isset( $_POST['whd_story_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['whd_story_nonce'] ) ), 'whd_story_toggle' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// Only meaningful once a story exists; the box only shows the checkbox then.
		if ( self::has( $post_id ) ) {
			update_post_meta( $post_id, self::META . '_off', isset( $_POST['whd_story_on'] ) ? '1' : '0' );
		}
	}

	/* ─────────────────────────── Product page ─────────────────────────── */

	public static function assets() {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! self::enabled( get_the_ID() ) ) {
			return;
		}
		wp_enqueue_style( 'whd-story', WHD_URL . 'assets/story.css', [], WHD_VERSION );
	}

	public static function render() {
		$id = get_the_ID();
		if ( ! self::enabled( $id ) || ! apply_filters( 'whd_product_story_render', true, $id ) ) {
			return;
		}
		$design = self::get( $id );
		$ctx    = self::context( $id );

		/*
		 * Split the blocks into a copy column and a media column before rendering, rather than
		 * asking CSS grid to do it. Leaving them as one flat list meant the image defined the
		 * height of the row it sat in, and every paragraph beside it floated in its own stretched
		 * row — a spread with holes in it. Two containers, two columns, no gymnastics.
		 */
		$copy  = '';
		$media = '';
		foreach ( $design['blocks'] as $i => $block ) {
			$html = WHD_Blocks::render_block( $block, 'popup', $ctx, $i );
			if ( 'image' === ( $block['type'] ?? '' ) ) {
				$media .= $html;
			} else {
				$copy .= $html;
			}
		}
		$body = $copy . $media;
		if ( ! trim( wp_strip_all_tags( $body ) ) ) {
			return;
		}
		/*
		 * No separate eyebrow field. A popup design has no free-text setting to keep one in, and
		 * inventing one would put a piece of the story outside the builder — the owner should be
		 * able to change every word of this from the drag-and-drop canvas.
		 */
		?>
		<section class="whd-story<?php echo $media ? ' whd-story--split' : ''; ?>" aria-label="<?php esc_attr_e( 'About this piece', 'whd' ); ?>">
			<div class="whd-story__inner">
				<div class="whd-story__copy">
					<?php echo $copy; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WHD_Blocks sanitises every block on save and on render. ?>
				</div>
				<?php if ( $media ) : ?>
					<div class="whd-story__media">
						<?php echo $media; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitised the same way. ?>
					</div>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Merge tags a story can use, so copy can name the piece without hard-coding it.
	 *
	 * @param int $id Product id.
	 */
	public static function context( $id ) {
		$product = wc_get_product( $id );
		if ( ! $product ) {
			return [ 'data' => [] ];
		}
		return [
			'data' => [
				'product_name'  => $product->get_name(),
				'product_price' => wp_strip_all_tags( $product->get_price_html() ),
				'product_url'   => get_permalink( $id ),
				'shop_name'     => get_bloginfo( 'name' ),
			],
		];
	}
}
