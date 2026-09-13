<?php

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Elementor Mega Menu widget.
 *
 * Elementor widget that displays a bullet list with any chosen icons and texts.
 *
 * @since 1.0.0
 */
class Ideapark_Elementor_Mega_Menu extends Widget_Base {

	/**
	 * Get widget name.
	 *
	 * Retrieve Mega Menu widget name.
	 *
	 * @return string Widget name.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_name() {
		return 'ideapark-mega-menu';
	}

	/**
	 * Get widget title.
	 *
	 * Retrieve Mega Menu widget title.
	 *
	 * @return string Widget title.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_title() {
		return __( 'Mega Menu Block', 'ideapark-moderno' );
	}

	/**
	 * Get widget icon.
	 *
	 * Retrieve Mega Menu widget icon.
	 *
	 * @return string Widget icon.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_icon() {
		return 'eicon-nav-menu';
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
		return [ 'mega', 'menu' ];
	}

	/**
	 * Register Mega Menu widget controls.
	 *
	 * Adds different input fields to allow the user to change and customize the widget settings.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_mega_menu',
			[
				'label' => __( 'Mega Menu Block', 'ideapark-moderno' ),
			]
		);

		$this->add_control(
			'title',
			[
				'label'       => __( 'Menu Title', 'ideapark-moderno' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'Enter menu title', 'ideapark-moderno' ),
				'label_block' => true,
			]
		);

		$this->add_control(
			'link',
			[
				'label'       => __( 'Title Link', 'ideapark-moderno' ),
				'type'        => Controls_Manager::URL,
				'label_block' => true,
				'placeholder' => __( 'https://your-link.com', 'ideapark-moderno' ),
				'condition'   => [
					'title!' => '',
				]
			]
		);

		$this->add_control(
			'type',
			[
				'label'   => __( 'Menu Type', 'ideapark-moderno' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'default',
				'options' => [
					'default'          => __( 'Default', 'ideapark-moderno' ),
					'product_category' => __( 'Product subcategories', 'ideapark-moderno' ),
					'product_attr'     => __( 'Product attribute terms', 'ideapark-moderno' ),
				]
			]
		);

		$options = [
			'' => '&mdash; ' . __( 'Select menu', 'ideapark-moderno' ) . ' &mdash;'
		];
		if ( $menus = wp_get_nav_menus() ) {
			foreach ( $menus as $menu ) {
				$options[ $menu->term_id ] = $menu->name;
			}
		}

		$this->add_control(
			'menu',
			[
				'label'     => __( 'Menu', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '',
				'options'   => $options,
				'condition' => [
					'type' => 'default',
				]
			]
		);

		$list = [
			''       => '&mdash; ' . esc_html__( 'Select parent category', 'ideapark-moderno' ) . ' &mdash;',
			'-shop-' => esc_html__( 'Shop', 'ideapark-moderno' ),
		];

		$args = [
			'taxonomy'     => 'product_cat',
			'orderby'      => 'meta_value_num',
			'meta_key'     => 'order',
			'show_count'   => 0,
			'pad_counts'   => 0,
			'hierarchical' => 1,
			'title_li'     => '',
			'hide_empty'   => 0,
			'exclude'      => ideapark_hidden_category_ids() ?: null,
		];
		if ( $all_categories = get_categories( $args ) ) {

			$category_name   = [];
			$category_slug   = [];
			$category_parent = [];
			foreach ( $all_categories as $cat ) {
				$category_name[ $cat->term_id ]    = esc_html( $cat->name );
				$category_slug[ $cat->term_id ]    = $cat->slug;
				$category_parent[ $cat->parent ][] = $cat->term_id;
			}

			$get_category = function ( $parent = 0, $prefix = ' - ' ) use ( &$list, &$category_parent, &$category_name, &$category_slug, &$get_category ) {
				if ( array_key_exists( $parent, $category_parent ) ) {
					$categories = $category_parent[ $parent ];
					foreach ( $categories as $category_id ) {
						$list[ $category_slug[ $category_id ] ] = $prefix . $category_name[ $category_id ];
						$get_category( $category_id, $prefix . ' - ' );
					}
				}
			};

			$get_category();
		}

		$this->add_control(
			'product_category',
			[
				'label'     => __( 'Product Category', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '',
				'options'   => $list,
				'condition' => [
					'type' => 'product_category',
				]
			]
		);

		$list = [
			'' => '&mdash; ' . esc_html__( 'Select attribute', 'ideapark-moderno' ) . ' &mdash;',
		];

		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {

			$attribute_taxonomies = wc_get_attribute_taxonomies();

			if ( ! empty( $attribute_taxonomies ) ) {
				foreach ( $attribute_taxonomies as $tax ) {
					if ( taxonomy_exists( $taxonomy = wc_attribute_taxonomy_name( $tax->attribute_name ) ) ) {
						$list[ $taxonomy ] = $tax->attribute_name;
					}
				}
			}
		}

		$this->add_control(
			'product_attr',
			[
				'label'     => __( 'Product Attribute', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '',
				'options'   => $list,
				'condition' => [
					'type' => 'product_attr',
				]
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Render Mega Menu widget output on the frontend.
	 *
	 * Written in PHP and used to generate the final HTML.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		if ( ! empty( $settings['link']['url'] ) ) {
			$link_key = 'title_link';
			$this->add_link_attributes( $link_key, $settings['link'] );
			$this->add_render_attribute( $link_key, 'class', 'c-ip-mega-menu__title-link' );
			$is_link = true;
		} else {
			$is_link = false;
		}

		$items = [];
		switch ( $settings['type'] ) {
			case 'default':
				if ( $settings['menu'] ) {
					if ( $_items = wp_get_nav_menu_items( apply_filters( 'wpml_object_id', $settings['menu'], 'nav_menu', true ) ) ) {
						foreach ( $_items as $index => $item ) {
							if ( $item->menu_item_parent == 0 ) {
								$items[] = [
									'url'   => $item->url,
									'title' => $item->title
								];
							}
						}
					}
				}
				break;
			case 'product_category':
				if (
					( $settings['product_category'] ) &&
					( $taxonomy = get_taxonomy( 'product_cat' ) )
				) {

					if ( $settings['product_category'] == '-shop-' ) {
						$term_parent_id = 0;
					} else {
						if (
							( $term_parent = get_term_by( 'slug', $settings['product_category'], 'product_cat' ) ) &&
							! is_wp_error( $term_parent )
						) {
							$term_parent_id = apply_filters( 'wpml_object_id', $term_parent->term_id, 'product_cat', true );
						} else {
							$term_parent_id = 0;
						}
					}

					$args = [
						'taxonomy'     => 'product_cat',
						'orderby'      => 'meta_value_num',
						'meta_key'     => 'order',
						'show_count'   => 0,
						'pad_counts'   => 0,
						'hierarchical' => 1,
						'title_li'     => '',
						'hide_empty'   => 1,
						'exclude'      => ideapark_hidden_category_ids() ?: null,
						'parent'       => $term_parent_id,
					];
					if ( $all_categories = get_categories( $args ) ) {
						foreach ( $all_categories as $index => $term ) {
							$items[] = [
								'url'   => get_term_link( (int) $term->term_id, 'product_cat' ),
								'title' => $term->name
							];
						}
					}
				}
				break;

			case 'product_attr':
				if (
					( $settings['product_attr'] ) &&
					( $taxonomy = get_taxonomy( $settings['product_attr'] ) )
				) {
					$args = [
						'show_count'   => 0,
						'pad_counts'   => 0,
						'hierarchical' => 0,
						'title_li'     => '',
						'hide_empty'   => 1,
					];

					if ( ( $all_categories = get_terms( $settings['product_attr'], $args ) ) && ! is_wp_error( $all_categories ) ) {
						foreach ( $all_categories as $index => $term ) {
							$items[] = [
								'url'   => get_term_link( (int) $term->term_id, $settings['product_attr'] ),
								'title' => $term->name
							];
						}
					}
				}
				break;
		}

		if ( $items ) { ?>
			<div class="c-ip-mega-menu">
				<?php if ( $settings['title'] ) { ?>
					<div
						class="c-ip-mega-menu__title <?php if ( $is_link ) { ?>c-ip-mega-menu__title--linked<?php } ?>">
						<?php echo ideapark_wrap( esc_html( $settings['title'] ), $is_link ? '<a ' . $this->get_render_attribute_string( $link_key ) . '>' : '', $is_link ? '</a>' : '' ); ?>
					</div>
				<?php } ?>
				<ul class="c-ip-mega-menu__list">
					<?php
					foreach ( $items as $item ) { ?>
						<li class="c-ip-mega-menu__item">
							<a class="c-ip-mega-menu__item-link" href="<?php echo esc_url( $item['url'] ); ?>">
								<?php echo esc_html( $item['title'] ); ?>
							</a>
						</li>
					<?php } ?>
				</ul>
			</div>
		<?php }
	}

	/**
	 * Render Mega Menu widget output in the editor.
	 *
	 * Written as a Backbone JavaScript template and used to generate the live preview.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function content_template() {
	}
}
