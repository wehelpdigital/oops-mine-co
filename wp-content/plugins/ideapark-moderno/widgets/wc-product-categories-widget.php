<?php
/**
 * Product Categories Widget
 *
 * @package WooCommerce\Widgets
 * @version 2.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Product categories widget class.
 *
 * @extends WC_Widget
 */
class Ideapark_WC_Product_Categories_Widget extends WC_Widget {

	/**
	 * Category ancestors.
	 *
	 * @var array
	 */
	public $cat_ancestors;

	/**
	 * Current Category.
	 *
	 * @var bool
	 */
	public $current_cat;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->widget_cssclass    = 'woocommerce widget_product_categories';
		$this->widget_description = __( 'A list of product categories with subcategories collapsing/expanding.', 'ideapark-moderno' );
		$this->widget_id          = 'ip_product_categories';
		$this->widget_name        = __( 'Moderno Product Categories', 'ideapark-moderno' );
		$this->settings           = [
			'title'              => [
				'type'  => 'text',
				'std'   => __( 'Product categories', 'ideapark-moderno' ),
				'label' => __( 'Title', 'ideapark-moderno' ),
			],
			'orderby'            => [
				'type'    => 'select',
				'std'     => 'name',
				'label'   => __( 'Order by', 'ideapark-moderno' ),
				'options' => [
					'order' => __( 'Category order', 'ideapark-moderno' ),
					'name'  => __( 'Name', 'ideapark-moderno' ),
				],
			],
			'count'              => [
				'type'  => 'checkbox',
				'std'   => 0,
				'label' => __( 'Show product counts', 'ideapark-moderno' ),
			],
			'hide_empty'         => [
				'type'  => 'checkbox',
				'std'   => 0,
				'label' => __( 'Hide empty categories', 'ideapark-moderno' ),
			],
			'max_depth'          => [
				'type'  => 'text',
				'std'   => '',
				'label' => __( 'Maximum depth', 'ideapark-moderno' ),
			],
		];

		parent::__construct();
	}

	/**
	 * Output widget.
	 *
	 * @param array $args     Widget arguments.
	 * @param array $instance Widget instance.
	 *
	 * @see WP_Widget
	 */
	public function widget( $args, $instance ) {
		global $wp_query, $post;

		$count              = isset( $instance['count'] ) ? $instance['count'] : $this->settings['count']['std'];
		$orderby            = isset( $instance['orderby'] ) ? $instance['orderby'] : $this->settings['orderby']['std'];
		$hide_empty         = isset( $instance['hide_empty'] ) ? $instance['hide_empty'] : $this->settings['hide_empty']['std'];
		$list_args          = array(
			'show_count' => $count,
			'taxonomy'   => 'product_cat',
			'hide_empty' => $hide_empty,
		);
		$max_depth          = absint( isset( $instance['max_depth'] ) ? $instance['max_depth'] : $this->settings['max_depth']['std'] );

		$list_args['menu_order'] = false;
		$list_args['depth']      = $max_depth;

		if ( 'order' === $orderby ) {
			$list_args['orderby']  = 'meta_value_num';
			$list_args['meta_key'] = 'order';
		}

		$this->current_cat   = false;
		$this->cat_ancestors = array();

		if ( is_tax( 'product_cat' ) ) {
			$this->current_cat   = $wp_query->queried_object;
			$this->cat_ancestors = get_ancestors( $this->current_cat->term_id, 'product_cat' );

		} elseif ( is_singular( 'product' ) ) {
			$terms = wc_get_product_terms(
				$post->ID,
				'product_cat',
				apply_filters(
					'woocommerce_product_categories_widget_product_terms_args',
					array(
						'orderby' => 'parent',
						'order'   => 'DESC',
					)
				)
			);

			if ( $terms ) {
				$main_term           = apply_filters( 'woocommerce_product_categories_widget_main_term', $terms[0], $terms );
				$this->current_cat   = $main_term;
				$this->cat_ancestors = get_ancestors( $main_term->term_id, 'product_cat' );
			}
		}

		$this->widget_start( $args, $instance );


		include_once WC()->plugin_path() . '/includes/walkers/class-wc-product-cat-list-walker.php';

		$list_args['walker']                     = new WC_Product_Cat_List_Walker();
		$list_args['title_li']                   = '';
		$list_args['pad_counts']                 = 1;
		$list_args['show_option_none']           = __( 'No product categories exist.', 'ideapark-moderno' );
		$list_args['current_category']           = ( $this->current_cat ) ? $this->current_cat->term_id : '';
		$list_args['current_category_ancestors'] = $this->cat_ancestors;
		$list_args['max_depth']                  = $max_depth;

		echo '<ul class="product-categories c-ip-product-categories-widget js-product-categories-widget">';

		wp_list_categories( apply_filters( 'woocommerce_product_categories_widget_args', $list_args ) );

		echo '</ul>';


		$this->widget_end( $args );
	}
}

register_widget( 'Ideapark_WC_Product_Categories_Widget' );
