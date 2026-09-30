<?php
/**
 * Making the product screen answerable.
 *
 * WooCommerce's product editor is comprehensive, which is not the same as clear. Adding a piece to
 * this shop means filling in perhaps eight things that matter, spread across a screen that offers
 * eighty — and nothing on it tells you when you are finished. The usual result is a product that
 * looks saved and is missing a price, a category or a photograph, discovered later by a shopper.
 *
 * Three changes, all of them subtractive or informational:
 *
 *   1. A checklist at the top of the sidebar: what this product still needs, each line linking to
 *      the field that fixes it. It is the same set of checks everywhere, so "ready" means one
 *      thing.
 *   2. The panels nobody here uses — custom fields, trackbacks, comment settings, the author box —
 *      are hidden, and WHD's own panels are ordered under Product data instead of below the fold.
 *   3. A "Ready" column and a "Needs attention" filter on the products list, so the same question
 *      can be answered for the whole catalogue without opening anything.
 *
 * Nothing blocks publishing. The checklist is advice, not a gate: a shop owner who wants a product
 * live without a short description knows more about their shop than this file does.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Product_Admin {

	public static function init() {
		add_action( 'add_meta_boxes', [ __CLASS__, 'meta_box' ], 1 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );

		// Fewer panels, in a better order.
		add_filter( 'default_hidden_meta_boxes', [ __CLASS__, 'hide_boxes' ], 10, 2 );
		add_filter( 'get_user_option_meta-box-order_product', [ __CLASS__, 'box_order' ] );

		// The catalogue view.
		add_filter( 'manage_edit-product_columns', [ __CLASS__, 'column' ], 20 );
		add_action( 'manage_product_posts_custom_column', [ __CLASS__, 'column_content' ], 20, 2 );
		add_action( 'restrict_manage_posts', [ __CLASS__, 'filter_control' ] );
		add_filter( 'parse_query', [ __CLASS__, 'filter_query' ] );
	}

	/* ─────────────────────────── the checks ─────────────────────────── */

	/**
	 * What a product still needs.
	 *
	 * Each entry is [ done, label, anchor, optional ] — the anchor being the id of the thing on the
	 * edit screen that fixes it, so the checklist can link straight to it.
	 *
	 * `optional` is the difference between a product that cannot be sold and one that could be
	 * better. Only the required five decide whether something reads as ready; counting the nice
	 * ones would leave every product in the catalogue permanently marked incomplete, which teaches
	 * people to stop reading the column.
	 *
	 * @param int $product_id
	 * @return array
	 */
	public static function checklist( $product_id ) {
		/*
		 * Remembered per request. Drawing one row of the products list asks for the checklist three
		 * times — the score, the missing labels, the suggestions — and each build loads the
		 * product and its terms. On a screen of twenty rows that was sixty of them.
		 */
		static $cache = [];
		$product_id   = (int) $product_id;
		if ( isset( $cache[ $product_id ] ) ) {
			return $cache[ $product_id ];
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			return [];
		}
		$post = get_post( $product_id );

		$cats = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'slugs' ] );
		$cats = is_wp_error( $cats ) ? [] : array_diff( $cats, [ 'uncategorized' ] );

		$priced = '' !== $product->get_price();
		if ( $product->is_type( 'variable' ) ) {
			$prices = $product->get_variation_prices();
			$priced = ! empty( $prices['price'] );
		}

		$list = [
			[
				'done'  => (bool) get_post_thumbnail_id( $product_id ),
				'label' => __( 'A main photograph', 'whd' ),
				'anchor' => 'postimagediv',
			],
			[
				'done'  => $priced,
				'label' => $product->is_type( 'variable' ) ? __( 'A price on every variation', 'whd' ) : __( 'A price', 'whd' ),
				'anchor' => 'woocommerce-product-data',
			],
			[
				'done'  => (bool) $cats,
				'label' => __( 'A category (not Uncategorised)', 'whd' ),
				'anchor' => 'product_catdiv',
			],
			[
				'done'  => '' !== trim( (string) $post->post_excerpt ),
				'label' => __( 'A short description, beside the price', 'whd' ),
				'anchor' => 'postexcerpt',
			],
			[
				'done'  => str_word_count( wp_strip_all_tags( (string) $post->post_content ) ) >= 20,
				'label' => __( 'A description of at least 20 words', 'whd' ),
				'anchor' => 'postdivrich',
			],
		];

		if ( class_exists( 'WHD_Product_Story' ) ) {
			$list[] = [
				'done'     => WHD_Product_Story::has( $product_id ),
				'label'    => __( 'A product story', 'whd' ),
				'anchor'   => 'whd-product-story',
				'optional' => true,
			];
		}
		if ( class_exists( 'WHD_Size_Charts' ) ) {
			$list[] = [
				'done'     => (bool) get_post_meta( $product_id, WHD_Size_Charts::META, true ),
				'label'    => __( 'A size chart', 'whd' ),
				'anchor'   => 'whd-size-chart',
				'optional' => true,
			];
		}

		/**
		 * Filter the product readiness checklist.
		 *
		 * @param array $list       Entries of [ done, label, anchor ].
		 * @param int   $product_id Product being checked.
		 */
		$cache[ $product_id ] = (array) apply_filters( 'whd_product_checklist', $list, $product_id );

		return $cache[ $product_id ];
	}

	/** How many of the required checks a product passes, and how many there are. */
	public static function score( $product_id ) {
		$required = array_filter( self::checklist( $product_id ), static fn( $i ) => empty( $i['optional'] ) );
		$done     = count( array_filter( wp_list_pluck( $required, 'done' ) ) );
		return [ $done, count( $required ) ];
	}

	/** The recommended extras a product has not had yet. */
	public static function suggestions( $product_id ) {
		return array_values( array_filter(
			self::checklist( $product_id ),
			static fn( $i ) => ! empty( $i['optional'] ) && empty( $i['done'] )
		) );
	}

	/* ─────────────────────────── the edit screen ─────────────────────────── */

	public static function meta_box() {
		add_meta_box(
			'whd-product-ready',
			__( 'Ready to publish?', 'whd' ),
			[ __CLASS__, 'box' ],
			'product',
			'side',
			'high'
		);
	}

	public static function box( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p class="description">' . esc_html__( 'Save a draft and this fills in as you go.', 'whd' ) . '</p>';
			return;
		}
		$all_items      = self::checklist( $post->ID );
		$list           = array_filter( $all_items, static fn( $i ) => empty( $i['optional'] ) );
		$extras         = array_filter( $all_items, static fn( $i ) => ! empty( $i['optional'] ) );
		[ $done, $all ] = self::score( $post->ID );
		$pct            = $all ? round( $done / $all * 100 ) : 0;
		?>
		<div class="whd-ready">
			<p class="whd-ready__score">
				<span class="whd-ready__bar" aria-hidden="true"><span style="width:<?php echo (int) $pct; ?>%"></span></span>
				<?php
				printf(
					/* translators: 1: checks passed, 2: checks in total */
					esc_html__( '%1$d of %2$d done', 'whd' ),
					(int) $done,
					(int) $all
				);
				?>
			</p>
			<ul class="whd-ready__list">
				<?php foreach ( $list as $item ) : ?>
					<li class="whd-ready__item <?php echo $item['done'] ? 'is-done' : 'is-todo'; ?>">
						<?php if ( $item['done'] ) : ?>
							<span class="whd-ready__mark" aria-hidden="true">&#10003;</span>
							<span><?php echo esc_html( $item['label'] ); ?></span>
						<?php else : ?>
							<span class="whd-ready__mark" aria-hidden="true">&middot;</span>
							<a href="#<?php echo esc_attr( $item['anchor'] ); ?>" class="whd-ready__jump"><?php echo esc_html( $item['label'] ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<?php if ( $extras ) : ?>
				<p class="whd-ready__heading"><?php esc_html_e( 'Worth adding', 'whd' ); ?></p>
				<ul class="whd-ready__list whd-ready__list--extra">
					<?php foreach ( $extras as $item ) : ?>
						<li class="whd-ready__item <?php echo $item['done'] ? 'is-done' : 'is-extra'; ?>">
							<span class="whd-ready__mark" aria-hidden="true"><?php echo $item['done'] ? '&#10003;' : '+'; ?></span>
							<?php if ( $item['done'] ) : ?>
								<span><?php echo esc_html( $item['label'] ); ?></span>
							<?php else : ?>
								<a href="#<?php echo esc_attr( $item['anchor'] ); ?>" class="whd-ready__jump"><?php echo esc_html( $item['label'] ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Advice, not a gate — you can publish whenever you like.', 'whd' ); ?>
			</p>
		</div>
		<?php
	}

	public static function assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type || ! in_array( $screen->base, [ 'post', 'edit' ], true ) ) {
			return;
		}
		wp_enqueue_style( 'whd-admin', WHD_URL . 'admin/admin.css', [], WHD_VERSION );
		wp_enqueue_style( 'whd-product-admin', WHD_URL . 'admin/product-admin.css', [ 'whd-admin' ], WHD_VERSION );
		if ( 'post' === $screen->base ) {
			wp_enqueue_script( 'whd-product-admin', WHD_URL . 'admin/product-admin.js', [], WHD_VERSION, true );
		}
	}

	/**
	 * Panels this shop does not use.
	 *
	 * Hidden rather than removed: every one of them is a tick away in Screen Options if it turns
	 * out somebody wanted it. This only sets the default for a user who has never chosen.
	 */
	public static function hide_boxes( $hidden, $screen ) {
		if ( ! $screen || 'product' !== $screen->id ) {
			return $hidden;
		}
		return array_unique( array_merge( (array) $hidden, [
			'postcustom',      // custom fields — WooCommerce and WHD write their own
			'trackbacksdiv',
			'commentstatusdiv',
			'commentsdiv',
			'slugdiv',         // the permalink is editable under the title
			'authordiv',
		] ) );
	}

	/** Put the panels that get used under Product data, rather than below everything else. */
	public static function box_order( $order ) {
		if ( $order ) {
			return $order; // the user has dragged them somewhere; leave it alone
		}
		return [
			'normal' => implode( ',', [
				'woocommerce-product-data',
				'postexcerpt',
				'whd-ai-box',
				'postdivrich',
			] ),
			'side'   => implode( ',', [
				'submitdiv',
				'whd-product-ready',
				'postimagediv',
				'product_catdiv',
				'whd-product-story',
				'whd-size-chart',
				'tagsdiv-product_tag',
			] ),
		];
	}

	/* ─────────────────────────── the products list ─────────────────────────── */

	/** A "Ready" column, dropped in just before the date. */
	public static function column( $columns ) {
		$out = [];
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$out['whd_ready'] = __( 'Ready', 'whd' );
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out['whd_ready'] ) ) {
			$out['whd_ready'] = __( 'Ready', 'whd' );
		}
		return $out;
	}

	public static function column_content( $column, $post_id ) {
		if ( 'whd_ready' !== $column ) {
			return;
		}
		[ $done, $all ] = self::score( $post_id );
		if ( ! $all ) {
			echo '—';
			return;
		}
		$missing = $all - $done;
		$gaps    = array_filter( self::checklist( $post_id ), static fn( $i ) => empty( $i['optional'] ) && empty( $i['done'] ) );
		$extras  = self::suggestions( $post_id );

		printf(
			'<span class="whd-pill %1$s" title="%2$s">%3$s</span>',
			$missing ? 'whd-pill--off' : 'whd-pill--on',
			esc_attr( $missing ? implode( ', ', wp_list_pluck( $gaps, 'label' ) ) : __( 'Nothing missing', 'whd' ) ),
			esc_html( $missing ? sprintf( /* translators: %d: number of missing items */ _n( '%d missing', '%d missing', $missing, 'whd' ), $missing ) : __( 'Ready', 'whd' ) )
		);
		if ( ! $missing && $extras ) {
			printf(
				'<span class="whd-ready__extra-note" title="%s">+%d</span>',
				esc_attr( implode( ', ', wp_list_pluck( $extras, 'label' ) ) ),
				count( $extras )
			);
		}
	}

	/** "Needs attention" in the filter row above the list. */
	public static function filter_control( $post_type ) {
		if ( 'product' !== $post_type ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter
		$on = ! empty( $_GET['whd_needs_attention'] );
		printf(
			'<label class="whd-attention"><input type="checkbox" name="whd_needs_attention" value="1" %s onchange="this.form.submit()"> %s</label>',
			checked( $on, true, false ),
			esc_html__( 'Needs attention', 'whd' )
		);
	}

	/**
	 * Narrow the list to products failing a check.
	 *
	 * The checks are not all stored as meta — a word count and a category are not — so this cannot
	 * be a meta_query. It scores the ids for this post type and filters by the result, which is
	 * fine for a catalogue of this size and only runs when the box is ticked.
	 */
	public static function filter_query( $query ) {
		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter
		if ( ! is_admin() || 'edit.php' !== $pagenow || empty( $_GET['whd_needs_attention'] ) ) {
			return $query;
		}
		if ( ! $query->is_main_query() || 'product' !== ( $query->query_vars['post_type'] ?? '' ) ) {
			return $query;
		}

		$ids = get_posts( [
			'post_type'      => 'product',
			'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
			'posts_per_page' => 500,
			'fields'         => 'ids',
		] );

		$needy = [];
		foreach ( $ids as $id ) {
			[ $done, $all ] = self::score( $id );
			if ( $done < $all ) {
				$needy[] = $id;
			}
		}
		// 0 keeps the query valid and correctly empty when every product passes.
		$query->set( 'post__in', $needy ?: [ 0 ] );

		return $query;
	}
}
