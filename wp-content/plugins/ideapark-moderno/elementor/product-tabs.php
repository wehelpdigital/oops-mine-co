<?php

use Elementor\Control_Media;
use Elementor\Plugin;
use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Repeater;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Elementor icon list widget.
 *
 * Elementor widget that displays a bullet list with any chosen icons and texts.
 *
 * @since 1.0.0
 */
class Ideapark_Elementor_Product_Tabs extends Widget_Base {

	private $theme_controls = [
		'product_grid_width',
		'product_grid_layout',
		'product_grid_layout_mobile',
		'product_short_description',
		'product_white_background'
	];

	/**
	 * Get widget name.
	 *
	 * Retrieve icon list widget name.
	 *
	 * @return string Widget name.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_name() {
		return 'ideapark-product-tabs';
	}

	/**
	 * Get widget title.
	 *
	 * Retrieve icon list widget title.
	 *
	 * @return string Widget title.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_title() {
		return __( 'Product Tabs', 'ideapark-moderno' );
	}

	/**
	 * Get widget icon.
	 *
	 * Retrieve icon list widget icon.
	 *
	 * @return string Widget icon.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_icon() {
		return 'eicon-tabs';
	}

	/**
	 * Retrieve the list of categories the widget belongs to.
	 *
	 * Used to determine where to display the widget in the editor.
	 *
	 * Note that currently Elementor supports only one category.
	 * When multiple categories passed, Elementor uses the first one.
	 *
	 */
	public function get_categories() {
		return [ 'ideapark-elements' ];
	}

	/**
	 * Get widget keywords.
	 *
	 * Retrieve the list of keywords the widget belongs to.
	 *
	 * @return array Widget keywords.
	 * @since  2.1.0
	 * @access public
	 *
	 */
	public function get_keywords() {
		return [ 'carousel', 'woocommerce', 'list' ];
	}

	/**
	 * Register icon list widget controls.
	 *
	 * Adds different input fields to allow the user to change and customize the widget settings.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function register_controls() {

		$this->start_controls_section(
			'section_layout',
			[
				'label' => __( 'Product Tabs', 'ideapark-moderno' ),
			]
		);

		$repeater = new Repeater();

		$repeater->add_control(
			'title',
			[
				'label'       => __( 'Title', 'ideapark-moderno' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( 'Tab Title', 'ideapark-moderno' ),
				'placeholder' => __( 'Enter title', 'ideapark-moderno' ),
				'dynamic'     => [
					'active' => true,
				],
			]
		);

		$repeater->add_control(
			'type',
			[
				'label'   => __( 'Type', 'ideapark-moderno' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'recent_products',
				'options' => $this->type_list()
			]
		);

		$repeater->add_control(
			'sale_only',
			[
				'label'        => __( 'On Sale', 'ideapark-moderno' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'no',
				'label_on'     => __( 'Yes', 'ideapark-moderno' ),
				'label_off'    => __( 'No', 'ideapark-moderno' ),
				'return_value' => 'yes',
				'condition'    => [
					'type!' => [
						'custom',
						'recently_viewed_products',
						'top_rated_products',
						'best_selling_products',
						'sale_products',
						'featured_products',
						'recent_products'
					]
				],
			]
		);


		$repeater->add_control(
			'shortcode',
			[
				'label'       => __( 'Enter Woocommerce shortcode', 'ideapark-moderno' ),
				'type'        => Controls_Manager::TEXTAREA,
				'placeholder' => '[product_attribute attribute="color" filter="black"]',
				'default'     => '',
				'condition'   => [
					'type' => 'custom',
				],
			]
		);


		$repeater->add_control(
			'orderby',
			[
				'label'     => __( 'Sort', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'menu_order',
				'options'   => [
					''           => __( 'Default sorting', 'ideapark-moderno' ),
					'rand'       => __( 'Random sorting', 'ideapark-moderno' ),
					'date'       => __( 'Sort by date the product was published', 'ideapark-moderno' ),
					'id'         => __( 'Sort by post ID of the product', 'ideapark-moderno' ),
					'menu_order' => __( 'Sort by menu order', 'ideapark-moderno' ),
					'popularity' => __( 'Sort by number of purchases', 'ideapark-moderno' ),
					'rating'     => __( 'Sort by average product rating', 'ideapark-moderno' ),
					'title'      => __( 'Sort by product title', 'ideapark-moderno' ),
				],
				'condition' => [
					'type!' => [
						'recent_products',
						'recently_viewed_products',
					]
				],
			]
		);

		$repeater->add_control(
			'order',
			[
				'label'     => __( 'Order', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'ASC',
				'options'   => [
					'ASC'  => 'ASC',
					'DESC' => 'DESC',
				],
				'condition' => [
					'type!' => [
						'recent_products',
						'recently_viewed_products',
					]
				],
			]
		);

		$repeater->add_control(
			'button_text',
			[
				'label'       => __( 'Button text', 'ideapark-moderno' ),
				'default'     => '',
				'type'        => Controls_Manager::TEXT,
				'placeholder' => __( 'Enter title', 'ideapark-moderno' ),
				'label_block' => true,
			]
		);

		$repeater->add_control(
			'button_link',
			[
				'label'     => __( 'Button link', 'ideapark-moderno' ),
				'type'      => Controls_Manager::URL,
				'default'   => [
					'url' => '#',
				],
				'separator' => 'before',
				'condition' => [
					'button_text!' => '',
				],
			]
		);

		$this->add_control(
			'tabs',
			[
				'label'       => '',
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'title_field' => '{{{ title }}}',
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_settings',
			[
				'label' => __( 'Tabs Settings', 'ideapark-moderno' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'limit',
			[
				'label'   => __( 'Products in tab', 'ideapark-moderno' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'min'     => 1,
				'max'     => 100,
				'step'    => 1,
				'default' => 8,
			]
		);

		$this->add_control(
			'tabs_type',
			[
				'label'   => __( 'Type', 'ideapark-moderno' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'grid',
				'options' => [
					'grid'     => __( 'Grid', 'ideapark-moderno' ),
					'carousel' => __( 'Carousel', 'ideapark-moderno' ),
					'combined' => __( 'Grid on Desktop / Carousel on Tablet and Mobile', 'ideapark-moderno' ),
				]
			]
		);

		global $ideapark_customize;

		if ( ! empty( $ideapark_customize ) ) {
			$section = false;
			foreach ( $ideapark_customize as $item ) {
				if ( ! empty( $item['panel'] ) && $item['panel'] == 'woocommerce' && isset( $item['controls']['is_woocommerce_on'] ) ) {
					$section = $item;
					break;
				}
			}

			foreach (
				$this->theme_controls as $control_name
			) {
				if ( isset( $section['controls'][ $control_name ] ) ) {
					$control = $section['controls'][ $control_name ];
					if ( $control['type'] == 'checkbox' ) {
						$this->add_control(
							$control_name,
							[
								'label'     => $control['label'],
								'type'      => Controls_Manager::SWITCHER,
								'default'   => $control['default'] ? 'yes' : '',
								'label_on'  => __( 'Show', 'ideapark-moderno' ),
								'label_off' => __( 'Hide', 'ideapark-moderno' ),
							]
						);

					} else {
						$this->add_control(
							$control_name,
							[
								'label'   => $control['label'],
								'type'    => Controls_Manager::SELECT,
								'default' => $control['default'],
								'options' => $control['choices'],
							]
						);
					}
				}
			}
		}

		$this->add_control(
			'arrows',
			[
				'label'     => __( 'Navigation arrows', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'yes',
				'label_on'  => __( 'Show', 'ideapark-moderno' ),
				'label_off' => __( 'Hide', 'ideapark-moderno' ),
				'condition' => [
					'tabs_type' => [ 'carousel', 'combined' ],
				],
			]
		);

		$this->add_control(
			'dots',
			[
				'label'     => __( 'Navigation dots', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'yes',
				'label_on'  => __( 'Show', 'ideapark-moderno' ),
				'label_off' => __( 'Hide', 'ideapark-moderno' ),
				'condition' => [
					'tabs_type' => [ 'carousel', 'combined' ],
				],
			]
		);

		$this->add_control(
			'loop',
			[
				'label'        => __( 'Enable Loop', 'ideapark-moderno' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'label_on'     => __( 'Yes', 'ideapark-moderno' ),
				'label_off'    => __( 'No', 'ideapark-moderno' ),
				'return_value' => 'yes',
				'condition'    => [
					'tabs_type' => [ 'carousel', 'combined' ],
				],
			]
		);

		$this->add_control(
			'carousel_autoplay',
			[
				'label'     => __( 'Autoplay', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'no',
				'label_on'  => __( 'Yes', 'ideapark-moderno' ),
				'label_off' => __( 'No', 'ideapark-moderno' ),
				'condition' => [
					'tabs_type' => [ 'carousel', 'combined' ],
				],
			]
		);

		$this->add_control(
			'carousel_animation_timeout',
			[
				'label'      => __( 'Autoplay Timeout (sec)', 'ideapark-moderno' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'default'    => [
					'size' => 5,
				],
				'range'      => [
					'px' => [
						'min' => 1,
						'max' => 10,
					],
				],
				'condition'  => [
					'tabs_type'         => [ 'carousel', 'combined' ],
					'carousel_autoplay' => 'yes',
				],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Render icon list widget output on the frontend.
	 *
	 * Written in PHP and used to generate the final HTML.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		if ( ideapark_mod( '_section_layout' ) == 'boxed' && $settings['product_grid_width'] == 'fullwidth' ) {
			$settings['product_grid_width'] = 'boxed';
		}

		ideapark_mod_set_temp( '_product_tabs_area', true );

		$tab_counter = ideapark_mod( '_product_tabs_counter' ) + 1;
		ideapark_mod_set_temp( '_product_tabs_counter', $tab_counter );
		$recently_enabled = ideapark_mod( 'recently_enabled' ) && ( $recently_viewed_ids = ideapark_recently_get() );
		if ( ! $recently_enabled ) {
			foreach ( $settings['tabs'] as $index => $item ) {
				if ( $item['type'] == 'recently_viewed_products' ) {
					unset( $settings['tabs'][ $index ] );
				}
			}
			$settings['tabs'] = array_values( $settings['tabs'] );
		}
		?>
		<div class="c-ip-product-tabs js-ip-tabs">
			<?php if ( sizeof( $settings['tabs'] ) > 1 ) { ?>
				<div class="c-ip-product-tabs__wrap js-ip-tabs-wrap">
					<div
						class="c-ip-product-tabs__menu js-ip-tabs-list h-carousel h-carousel--small h-carousel--hover h-carousel--mobile-arrows h-carousel--dots-hide">
						<?php
						foreach ( $settings['tabs'] as $index => $item ) { ?>
							<div
								class="c-ip-product-tabs__menu-item js-ip-tabs-menu-item <?php if ( ! $index ) { ?>active<?php } ?>">
								<a class="c-ip-product-tabs__menu-link js-ip-tabs-link"
								   href="#tab-<?php echo esc_attr( $this->get_id() . '-' . $tab_counter . '-' . ( $index + 1 ) ); ?>"
								   data-index="<?php echo esc_attr( $index ); ?>"
								   onclick="return false;"><?php echo $item['title']; ?></a>
							</div>
						<?php } ?>
					</div>
				</div>
			<?php } ?>

			<div class="c-ip-product-tabs__list">
				<?php foreach ( $settings['tabs'] as $index => $item ) { ?>
				<div class="c-ip-product-tabs__item <?php if ( ! $index ) { ?>visible active<?php } ?>"
				     id="tab-<?php echo esc_attr( $this->get_id() . '-' . $tab_counter . '-' . ( $index + 1 ) ); ?>">
					<?php

					$button = '';
					if ( ! empty( $item['button_link']['url'] ) && $item['button_text'] ) { ?>
						<?php
						$link_key = 'link_' . $index;
						$this->add_link_attributes( $link_key, $item['button_link'] );
						$this->add_render_attribute( $link_key, 'class', 'c-button c-button--outline c-button--medium c-ip-product-tabs__button' );
						$button = '<a ' . $this->get_render_attribute_string( $link_key ) . '>' . esc_html( $item['button_text'] ) . '</a>';
						ideapark_mod_set_temp( '_extra_items_count', 1 );
						?>
					<?php }

					$button_out = $button ? function () use ( $item, $button ) {
						echo '<div class="c-product-grid__item c-product-grid__item--view-more ' . ideapark_mod( '_product_layout_class' ) . '">' . $button . '</div>';
					} : null;

					$cat_id = preg_match( '~^-\d+$~', $item['type'] ) ? absint( $item['type'] ) : 0;

					if ( $button_out ) {
						add_action( 'ideapark_products_loop_end', $button_out );
					}

					if ( $settings['tabs_type'] == 'carousel' || $settings['tabs_type'] == 'combined' ) {
						$_data = '';

						if ( $settings['carousel_autoplay'] ) {
							$_data .= 'data-autoplay="' . esc_attr( $settings['carousel_autoplay'] ) . '" ';
							if ( ! empty( $settings['carousel_animation_timeout']['size'] ) ) {
								$_data .= 'data-animation-timeout="' . esc_attr( abs( $settings['carousel_animation_timeout']['size'] * 1000 ) ) . '" ';
							}
						}
						$class = 'c-product-grid__list--carousel js-product-grid-carousel h-carousel  h-carousel--flex' . ' ' . ( $settings['arrows'] != 'yes' ? 'h-carousel--nav-hide' : 'h-carousel--border h-carousel--round h-carousel--hover' ) . ' ' . ( $settings['dots'] != 'yes' ? 'h-carousel--dots-hide' : 'h-carousel--default-dots' ) . ' ' . ( $settings['loop'] == 'yes' ? 'h-carousel--loop' : '' );
						if ( $settings['tabs_type'] == 'carousel' ) {
							ideapark_mod_set_temp( '_product_carousel_class', $class );
						} else {
							$_data .= 'data-combined="' . esc_attr( $class ) . '"';
							ideapark_mod_set_temp( '_product_carousel_class', 'js-product-combined' );
						}
						ideapark_mod_set_temp( '_product_carousel_data', $_data );
					} else {
						ideapark_mod_set_temp( '_product_carousel_class', '' );
						ideapark_mod_set_temp( '_product_carousel_data', '' );
					}

					$backup_mods = [];

					foreach ( $this->theme_controls as $control_name ) {
						$backup_mods[ $control_name ] = ideapark_mod( $control_name );
						ideapark_mod_set_temp( $control_name, $settings[ $control_name ] );
					}

					ob_start();
					if ( $cat_id ) {
						$cat_id = apply_filters( 'wpml_object_id', $cat_id, 'product_cat', true );
						echo do_shortcode( '[products category="' . $cat_id . '" limit="' . $settings['limit'] . '"' . ( $item['orderby'] ? ' orderby="' . $item['orderby'] . '" order="' . $item['order'] . '"' : '' ) . ( ! empty( $item['sale_only'] ) && $item['sale_only'] === 'yes' ? ' on_sale="yes"' : '' ) . ']' );
					} elseif ( $item['type'] == 'custom' && ( $item['shortcode'] = trim( $item['shortcode'] ) ) && preg_match( '~\[([^\] ]+)~', $item['shortcode'], $match ) && shortcode_exists( $match[1] ) ) {
						$item['shortcode'] = preg_replace( '~(limit|order|orderby)\s*=\s*["\'][\s\S]*["\']~uUi', '', $item['shortcode'] );
						$item['shortcode'] = preg_replace( '~\]~', ' limit="' . $settings['limit'] . '"' . ( $item['orderby'] ? ' orderby="' . $item['orderby'] . '" order="' . $item['order'] . '"' : '' ) . ']', $item['shortcode'] );
						echo do_shortcode( $item['shortcode'] );
					} elseif ( $item['type'] == 'recently_viewed_products' ) {
						echo ( ! empty( $recently_viewed_ids ) ) ? do_shortcode( '[products ids="' . implode( ',', $recently_viewed_ids ) . '"]' ) : '';
					} elseif ( $item['type'] != 'custom' ) {
						echo do_shortcode( '[' . $item['type'] . ' limit="' . $settings['limit'] . '"' . ( $item['orderby'] && $item['type'] != 'recent_products' ? ' orderby="' . $item['orderby'] . '" order="' . $item['order'] . '"' : '' ) . ']' );
					}
					$content = ob_get_clean();

					foreach ( $this->theme_controls as $control_name ) {
						ideapark_mod_set_temp( $control_name, $backup_mods[ $control_name ] );
					}

					if ( $button_out ) {
						remove_action( 'ideapark_products_loop_end', $button_out );
					}
					echo ideapark_wrap( $content, '<div class="l-section ' . ( $settings['product_grid_width'] == 'boxed' ? 'l-section--container ' : '' ) . '">', '</div>' );
					?>
					</div><?php
				} ?>
			</div>
		</div>
		<?php
		ideapark_mod_set_temp( '_product_tabs_area', false );
	}

	/**
	 * Render icon list widget output in the editor.
	 *
	 * Written as a Backbone JavaScript template and used to generate the live preview.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function content_template() {
	}

	function type_list() {
		$list = [
			'recent_products'       => esc_html__( 'Recent Products', 'ideapark-moderno' ),
			'featured_products'     => esc_html__( 'Featured Products', 'ideapark-moderno' ),
			'sale_products'         => esc_html__( 'Sale Products', 'ideapark-moderno' ),
			'best_selling_products' => esc_html__( 'Best-Selling Products', 'ideapark-moderno' ),
			'top_rated_products'    => esc_html__( 'Top Rated Products', 'ideapark-moderno' ),
		];

		if ( ideapark_mod( 'recently_enabled' ) ) {
			$list['recently_viewed_products'] = esc_html__( 'Recently Viewed Products', 'ideapark-moderno' );
		}

		$list['custom'] = esc_html__( 'Custom Woocommerce Shortcode', 'ideapark-moderno' );

		$args = [
			'taxonomy'     => 'product_cat',
			'orderby'      => 'term_group',
			'show_count'   => 0,
			'pad_counts'   => 0,
			'hierarchical' => 1,
			'title_li'     => '',
			'hide_empty'   => 0,
			'exclude'      => ideapark_hidden_category_ids()? : null,
		];
		if ( $all_categories = get_categories( $args ) ) {

			$category_name   = [];
			$category_parent = [];
			foreach ( $all_categories as $cat ) {
				$category_name[ $cat->term_id ]    = esc_html( $cat->name );
				$category_parent[ $cat->parent ][] = $cat->term_id;
			}

			$get_category = function ( $parent = 0, $prefix = ' - ' ) use ( &$list, &$category_parent, &$category_name, &$get_category ) {
				if ( array_key_exists( $parent, $category_parent ) ) {
					$categories = $category_parent[ $parent ];
					foreach ( $categories as $category_id ) {
						$list[ '-' . $category_id ] = $prefix . $category_name[ $category_id ];
						$get_category( $category_id, $prefix . ' - ' );
					}
				}
			};

			$get_category();
		}

		return $list;
	}

	function wc_thumbnail_size( $size ) {
		return 'medium';
	}
}
