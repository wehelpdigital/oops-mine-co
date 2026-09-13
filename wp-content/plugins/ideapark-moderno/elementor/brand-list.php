<?php

use Elementor\Control_Media;
use Elementor\Group_Control_Image_Size;
use Elementor\Utils;
use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Repeater;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Elementor image list widget.
 *
 * Elementor widget that displays a bullet list with any chosen icons and texts.
 *
 * @since 1.0.0
 */
class Ideapark_Elementor_Brand_List extends Widget_Base {

	/**
	 * Get widget name.
	 *
	 * Retrieve image list widget name.
	 *
	 * @return string Widget name.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_name() {
		return 'ideapark-brand-list';
	}

	/**
	 * Get widget title.
	 *
	 * Retrieve image list widget title.
	 *
	 * @return string Widget title.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_title() {
		return __( 'Brand List', 'ideapark-moderno' );
	}

	/**
	 * Get widget icon.
	 *
	 * Retrieve image list widget icon.
	 *
	 * @return string Widget icon.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_icon() {
		return 'ip-icon-list';
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
		return [ 'brand list', 'brand', 'list', 'logo' ];
	}

	/**
	 * Register image list widget controls.
	 *
	 * Adds different input fields to allow the user to change and customize the widget settings.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_image_settings',
			[
				'label' => __( 'Brand list settings', 'ideapark-moderno' ),
			]
		);
		if ( ideapark_woocommerce_on() && ideapark_mod( 'product_brand_attribute' ) ) {
			$is_archive   = false;
			$attribute_id = 0;
			$list         = wc_get_attribute_taxonomies();

			$name = preg_replace( '~^pa_~', '', ideapark_mod( 'product_brand_attribute' ) );
			foreach ( $list as $attribute ) {
				if ( $attribute->attribute_name == $name ) {
					$attribute_id = $attribute->attribute_id;
					if ( $attribute->attribute_public ) {
						$is_archive = true;
					}
					break;
				}
			}

			if ( $is_archive && ! taxonomy_exists( ideapark_mod( 'product_brand_attribute' ) ) ) {
				$is_archive = false;
			}

			if ( $is_archive ) {
				$this->add_control(
					'layout',
					[
						'label'   => __( 'Layout', 'ideapark-moderno' ),
						'type'    => Controls_Manager::SELECT,
						'default' => 'logos',
						'options' => [
							'logos' => __( 'Logos', 'ideapark-moderno' ),
							'alpha' => __( 'Alphabetically', 'ideapark-moderno' ),
						]
					]
				);
			} else {
				$this->add_control(
					'brand_configure',
					[
						'label' => '',
						'type'  => \Elementor\Controls_Manager::RAW_HTML,
						'raw'   => '<a target="_blank" href="' . esc_url( admin_url( 'edit.php?post_type=product&page=product_attributes&edit=' . $attribute_id ) ) . '">' . __( 'Enable archives for brand attribute', 'ideapark-moderno' ) . '</a>',
					]
				);
			}
		} else {
			$this->add_control(
				'brand_configure',
				[
					'label' => '',
					'type'  => \Elementor\Controls_Manager::RAW_HTML,
					'raw'   => '<a target="_blank" href="' . esc_url( admin_url( 'customize.php?autofocus[control]=product_brand_attribute' ) ) . '">' . __( 'Set up brands to activate the widget', 'ideapark-moderno' ) . '</a>',
				]
			);
		}
		$this->end_controls_section();
	}

	/**
	 * Render image list widget output on the frontend.
	 *
	 * Written in PHP and used to generate the final HTML.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		if ( $brand_taxonomy = ideapark_mod( 'product_brand_attribute' ) ) {
			$args  = [
				'taxonomy'     => $brand_taxonomy,
				'orderby'      => 'name',
				'order'        => 'ASC',
				'show_count'   => 0,
				'pad_counts'   => 0,
				'hierarchical' => 0,
				'title_li'     => '',
				'hide_empty'   => 1,
			];
			$index = 0;
			if ( $all_brands = apply_filters( 'ideapark_brand_list', get_categories( $args ) ) ) { ?>
				<div class="c-ip-brand-list c-ip-brand-list--<?php echo $settings['layout']; ?>">
					<ul class="c-ip-brand-list__list c-ip-brand-list__list--<?php echo $settings['layout']; ?>">
						<?php
						$letter = '';
						global $wp_query;
						WC()->query->product_query( $wp_query );
						$term_counts = ideapark_woocommerce_on() ? wc_get_container()->get( Automattic\WooCommerce\Internal\ProductAttributesLookup\Filterer::class )->get_filtered_term_product_counts( wp_list_pluck( $all_brands, 'term_id' ), ideapark_mod( 'product_brand_attribute' ), 'or' ) : [];
						foreach ( $all_brands as $brand ) {
						if ( empty( $term_counts[ $brand->term_id ] ) ) {
							continue;
						}
						?>
						<?php if ( $settings['layout'] == 'alpha' && ( $_letter = ucfirst( $brand->name[0] ) ) && ( $_letter != $letter ) ) {
						$letter = $_letter;
						?>
						<?php if ( $index ) { ?></ul>
					</li><?php } ?>
					<li class="c-ip-brand-list__item-parent">
						<ul class="c-ip-brand-list__list-inner">
							<li class="c-ip-brand-list__item--letter"><?php echo $letter; ?></li>
							<?php } ?>
							<li class="c-ip-brand-list__item c-ip-brand-list__item--<?php echo $settings['layout']; ?>">
								<a class="c-ip-brand-list__link c-ip-brand-list__link--<?php echo $settings['layout']; ?>"
								   href="<?php echo esc_url( get_term_link( $brand ) ); ?>">
									<?php if ( $settings['layout'] == 'logos' ) { ?>
										<div class="c-ip-brand-list__thumb">
											<?php if ( ( $image_id = get_term_meta( $brand->term_id, 'brand_logo', true ) ) && ( $type = get_post_mime_type( $image_id ) ) ) {
												if ( $type == 'image/svg+xml' ) {
													echo ideapark_get_inline_svg( $image_id, 'c-ip-brand-list__svg' );
												} else {
													echo ideapark_img( ideapark_image_meta( $image_id, 'full' ), 'c-ip-brand-list__image' );
												}
											}
											?>
										</div>
									<?php } ?>
									<div
										class="c-ip-brand-list__title c-ip-brand-list__title--<?php echo $settings['layout']; ?>">
										<?php echo esc_html( $brand->name ); ?>
									</div>
								</a>
							</li>
							<?php $index ++; ?>
							<?php } ?>
							<?php if ( $settings['layout'] == 'alpha' ) { ?>
						</ul>
					</li>
				<?php } ?>
					</ul>
				</div>
			<?php }
		}
	}

	/**
	 * Render image list widget output in the editor.
	 *
	 * Written as a Backbone JavaScript template and used to generate the live preview.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function content_template() {
	}
}
