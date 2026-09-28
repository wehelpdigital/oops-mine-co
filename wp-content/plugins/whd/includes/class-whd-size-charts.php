<?php
/**
 * Size charts.
 *
 * A size chart is a small post type: a title, a description and one image. The owner builds each
 * chart once under its own "Size Chart" menu, then picks one from a dropdown on any product. The
 * product page shows a "Size chart" link that opens the image full size.
 *
 * It lives in the WHD plugin but deliberately keeps its own top-level menu rather than sitting
 * under WHD — it is a catalogue tool the owner reaches for while adding products, not a marketing
 * setting she visits once a month.
 *
 * @package WHD
 */

defined( 'ABSPATH' ) || exit;

class WHD_Size_Charts {

	const CPT  = 'whd_size_chart';
	const META = '_whd_size_chart';

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );

		// Admin: the chart screen and the picker on the product screen.
		add_action( 'add_meta_boxes', [ __CLASS__, 'meta_boxes' ] );
		add_action( 'save_post_product', [ __CLASS__, 'save_product' ], 10, 2 );
		add_filter( 'manage_' . self::CPT . '_posts_columns', [ __CLASS__, 'columns' ] );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', [ __CLASS__, 'column' ], 10, 2 );
		add_filter( 'enter_title_here', [ __CLASS__, 'title_placeholder' ], 10, 2 );
		add_action( 'admin_head', [ __CLASS__, 'relabel_featured_image' ] );

		// Front end.
		add_action( 'woocommerce_single_product_summary', [ __CLASS__, 'render_link' ], 25 );
		add_action( 'wp_footer', [ __CLASS__, 'render_dialog' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
	}

	/* ─────────────────────────── The post type ─────────────────────────── */

	public static function register() {
		register_post_type( self::CPT, [
			'labels'          => [
				'name'               => __( 'Size Charts', 'whd' ),
				'singular_name'      => __( 'Size Chart', 'whd' ),
				'menu_name'          => __( 'Size Chart', 'whd' ),
				'add_new'            => __( 'Add Size Chart', 'whd' ),
				'add_new_item'       => __( 'Add Size Chart', 'whd' ),
				'edit_item'          => __( 'Edit Size Chart', 'whd' ),
				'new_item'           => __( 'New Size Chart', 'whd' ),
				'view_item'          => __( 'View Size Chart', 'whd' ),
				'search_items'       => __( 'Search size charts', 'whd' ),
				'not_found'          => __( 'No size charts yet. Add one and it becomes pickable on every product.', 'whd' ),
				'not_found_in_trash' => __( 'No size charts in the trash.', 'whd' ),
			],
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => true,   // its own top-level menu, not a WHD submenu
			'menu_position'   => 26,     // just under Products
			'menu_icon'       => 'dashicons-editor-table',
			'supports'        => [ 'title', 'editor', 'thumbnail' ],
			'capability_type' => 'page',
			'map_meta_cap'    => true,
			'has_archive'     => false,
			'rewrite'         => false,
			'query_var'       => false,
		] );
	}

	public static function title_placeholder( $text, $post ) {
		return ( $post && self::CPT === $post->post_type )
			? __( 'Chart name — e.g. Tops & Dresses (US)', 'whd' )
			: $text;
	}

	/** "Featured image" means nothing here; the image IS the chart. */
	public static function relabel_featured_image() {
		$screen = get_current_screen();
		if ( ! $screen || self::CPT !== $screen->post_type ) {
			return;
		}
		?>
		<style>
			#postimagediv .hndle span::after { content: " \2014 <?php echo esc_html__( 'this is the chart shoppers see', 'whd' ); ?>"; font-weight: 400; opacity: .7; }
			#postdivrich .wp-editor-tools + .wp-editor-container::before { content: ""; }
		</style>
		<?php
	}

	public static function columns( $columns ) {
		$out = [];
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['whd_image'] = __( 'Chart', 'whd' );
				$out['whd_used']  = __( 'Used on', 'whd' );
			}
		}
		return $out;
	}

	public static function column( $column, $post_id ) {
		if ( 'whd_image' === $column ) {
			$thumb = get_the_post_thumbnail( $post_id, [ 90, 90 ], [ 'style' => 'height:auto;border:1px solid #dcdcde' ] );
			echo $thumb ? wp_kses_post( $thumb ) : '<span style="color:#b32d2e">' . esc_html__( 'No image yet', 'whd' ) . '</span>';
		}
		if ( 'whd_used' === $column ) {
			$count = self::product_count( $post_id );
			printf(
				/* translators: %d: number of products */
				esc_html( _n( '%d product', '%d products', $count, 'whd' ) ),
				(int) $count
			);
		}
	}

	/** How many products point at this chart. */
	public static function product_count( $chart_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'product' AND p.post_status != 'trash'
			 WHERE pm.meta_key = %s AND pm.meta_value = %d",
			self::META,
			(int) $chart_id
		) );
	}

	/* ─────────────────────────── Picking one on a product ─────────────────────────── */

	public static function meta_boxes() {
		add_meta_box(
			'whd-size-chart',
			__( 'Size chart', 'whd' ),
			[ __CLASS__, 'product_box' ],
			'product',
			'side',
			'default'
		);
	}

	public static function product_box( $post ) {
		$charts   = self::all();
		$selected = (int) get_post_meta( $post->ID, self::META, true );
		wp_nonce_field( 'whd_size_chart', 'whd_size_chart_nonce' );

		if ( ! $charts ) {
			printf(
				'<p>%s</p><p><a href="%s" class="button">%s</a></p>',
				esc_html__( 'No size charts yet.', 'whd' ),
				esc_url( admin_url( 'post-new.php?post_type=' . self::CPT ) ),
				esc_html__( 'Add your first one', 'whd' )
			);
			return;
		}
		echo '<select name="whd_size_chart" style="width:100%">';
		echo '<option value="0">' . esc_html__( '— none —', 'whd' ) . '</option>';
		foreach ( $charts as $chart ) {
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $chart->ID,
				selected( $selected, $chart->ID, false ),
				esc_html( $chart->post_title )
			);
		}
		echo '</select>';
		printf(
			'<p class="description">%s</p><p><a href="%s">%s</a></p>',
			esc_html__( 'Shown as a “Size chart” link on the product page.', 'whd' ),
			esc_url( admin_url( 'edit.php?post_type=' . self::CPT ) ),
			esc_html__( 'Manage size charts', 'whd' )
		);
	}

	public static function save_product( $post_id, $post ) {
		if ( ! isset( $_POST['whd_size_chart_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['whd_size_chart_nonce'] ) ), 'whd_size_chart' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$chart = isset( $_POST['whd_size_chart'] ) ? absint( wp_unslash( $_POST['whd_size_chart'] ) ) : 0;
		if ( $chart ) {
			update_post_meta( $post_id, self::META, $chart );
		} else {
			delete_post_meta( $post_id, self::META );
		}
	}

	/** Every published chart, newest first. */
	public static function all() {
		return get_posts( [
			'post_type'   => self::CPT,
			'numberposts' => -1,
			'post_status' => 'publish',
			'orderby'     => 'title',
			'order'       => 'ASC',
		] );
	}

	/**
	 * The chart attached to a product, as [ id, title, description, image, alt ], or null.
	 *
	 * Filterable so a theme can attach a chart by category rather than per product.
	 *
	 * @param int $product_id Product to look up. Defaults to the current post.
	 */
	public static function for_product( $product_id = 0 ) {
		$product_id = $product_id ? (int) $product_id : (int) get_the_ID();
		$chart_id   = (int) apply_filters( 'whd_size_chart_id', (int) get_post_meta( $product_id, self::META, true ), $product_id );
		if ( ! $chart_id || 'publish' !== get_post_status( $chart_id ) ) {
			return null;
		}
		$image_id = (int) get_post_thumbnail_id( $chart_id );
		return [
			'id'          => $chart_id,
			'title'       => get_the_title( $chart_id ),
			'description' => apply_filters( 'the_content', get_post_field( 'post_content', $chart_id ) ),
			'image'       => $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '',
			'image_tag'   => $image_id ? wp_get_attachment_image( $image_id, 'large', false, [ 'class' => 'whd-sc__img', 'loading' => 'lazy' ] ) : '',
		];
	}

	/* ─────────────────────────── Product page ─────────────────────────── */

	/** True when this product page should print the chart. */
	protected static function active() {
		return function_exists( 'is_product' ) && is_product() && self::for_product();
	}

	/** Only on a product page that actually has a chart attached. */
	public static function assets() {
		if ( ! self::active() || ! apply_filters( 'whd_size_chart_render', true ) ) {
			return;
		}
		wp_enqueue_style( 'whd-size-chart', WHD_URL . 'assets/size-chart.css', [], WHD_VERSION );
		wp_enqueue_script( 'whd-size-chart', WHD_URL . 'assets/size-chart.js', [], WHD_VERSION, true );
	}

	public static function render_link() {
		if ( ! self::active() || ! apply_filters( 'whd_size_chart_render', true ) ) {
			return;
		}
		?>
		<button type="button" class="whd-sc__open" data-whd-size-chart aria-haspopup="dialog" aria-controls="whd-size-chart">
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="7" width="18" height="10" rx="1.5"/><path d="M7 7v3M11 7v5M15 7v3M19 7v5"/></svg>
			<span><?php esc_html_e( 'Size chart', 'whd' ); ?></span>
		</button>
		<?php
	}

	public static function render_dialog() {
		if ( ! self::active() || ! apply_filters( 'whd_size_chart_render', true ) ) {
			return;
		}
		$chart = self::for_product();
		?>
		<div class="whd-sc" id="whd-size-chart" role="dialog" aria-modal="true" aria-labelledby="whd-sc-title" hidden>
			<div class="whd-sc__backdrop" data-whd-size-chart-close></div>
			<div class="whd-sc__panel" role="document">
				<button type="button" class="whd-sc__close" data-whd-size-chart-close aria-label="<?php esc_attr_e( 'Close', 'whd' ); ?>">&times;</button>
				<h2 class="whd-sc__title" id="whd-sc-title"><?php echo esc_html( $chart['title'] ); ?></h2>
				<?php if ( $chart['description'] ) : ?>
					<div class="whd-sc__text"><?php echo wp_kses_post( $chart['description'] ); ?></div>
				<?php endif; ?>
				<?php if ( $chart['image_tag'] ) : ?>
					<figure class="whd-sc__figure">
						<?php echo wp_kses_post( $chart['image_tag'] ); ?>
						<figcaption><a href="<?php echo esc_url( $chart['image'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the full-size chart', 'whd' ); ?></a></figcaption>
					</figure>
				<?php else : ?>
					<p class="whd-sc__text"><?php esc_html_e( 'This chart has no image yet.', 'whd' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
