<?php

use Elementor\Group_Control_Typography;
use Elementor\Utils;
use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Repeater;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Elementor hotspot Carousel widget.
 *
 * Elementor widget that displays a bullet list with any chosen icons and texts.
 *
 * @since 1.0.0
 */
class Ideapark_Elementor_Hotspot_Carousel extends Widget_Base {

	/**
	 * Get widget name.
	 *
	 * Retrieve hotspot Carousel widget name.
	 *
	 * @return string Widget name.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_name() {
		return 'ideapark-hotspot-carousel';
	}

	/**
	 * Get widget title.
	 *
	 * Retrieve hotspot Carousel widget title.
	 *
	 * @return string Widget title.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_title() {
		return __( 'Hotspot Gallery', 'ideapark-moderno' );
	}

	/**
	 * Get widget icon.
	 *
	 * Retrieve hotspot Carousel widget icon.
	 *
	 * @return string Widget icon.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_icon() {
		return 'eicon-image-rollover';
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
		return [ 'hotspot', 'hotspot Carousel', 'image', 'list' ];
	}

	/**
	 * Register hotspot Carousel widget controls.
	 *
	 * Adds different input fields to allow the user to change and customize the widget settings.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function register_controls() {

		$this->start_controls_section(
			'section_slider_list',
			[
				'label' => __( 'Item List', 'ideapark-moderno' ),
			]
		);

		$repeater = new Repeater();

		$repeater->add_control(
			'image',
			[
				'label'   => __( 'Choose Image', 'ideapark-moderno' ),
				'type'    => Controls_Manager::MEDIA,
				'default' => [
					'url' => Utils::get_placeholder_image_src(),
				],
			]
		);

		$repeater->add_control(
			'hotspots',
			[
				'label' => __( 'Add / Edit Hotspots', 'ideapark-moderno' ),
				'image' => 'image',
				'type'  => 'ideapark-hotspot'
			]
		);


		$repeater->add_control(
			'title_text',
			[
				'label'       => __( 'Header', 'ideapark-moderno' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => __( 'Enter text', 'ideapark-moderno' ),
				'label_block' => true,
			]
		);

		$repeater->add_control(
			'subheader',
			[
				'label'       => __( 'Subheader', 'ideapark-moderno' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => __( 'Enter text', 'ideapark-moderno' ),
				'label_block' => true,
			]
		);

		$repeater->add_control(
			'link',
			[
				'label'       => __( 'Link', 'ideapark-moderno' ),
				'type'        => Controls_Manager::URL,
				'label_block' => true,
				'placeholder' => __( 'https://your-link.com', 'ideapark-moderno' ),
			]
		);

		$this->add_control(
			'image_list',
			[
				'label'       => '',
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'title_field' => '{{{ title_text }}}',
			]
		);


		$this->end_controls_section();

		$this->start_controls_section(
			'section_slider_settings',
			[
				'label' => __( 'Hotspot Settings', 'ideapark-moderno' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'items_per_row',
			[
				'label'     => __( 'Items per row (Desktop)', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '2',
				'options'   => [
					1 => __( '1', 'ideapark-moderno' ),
					2 => __( '2', 'ideapark-moderno' ),
					3 => __( '3', 'ideapark-moderno' ),
				],
				'selectors' => [
					'{{WRAPPER}} .c-ip-hotspot__list' => '--items-per-row: {{value}};',
				],
			]
		);

		$this->add_control(
			'point_color',
			[
				'label'     => __( 'Point Color', 'ideapark-moderno' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'selectors' => [
					'{{WRAPPER}} .c-ip-hotspot__point' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_responsive_control(
			'text_max_width',
			[
				'label'      => __( 'Header width', 'ideapark-moderno' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px', '%' ],
				'range'      => [
					'px' => [
						'min' => 400,
						'max' => 1160,
					],
					'%'  => [
						'min' => 0,
						'max' => 100,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .c-ip-hotspot__content' => 'width: {{SIZE}}{{UNIT}};',
				],
			]
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_toggle_style_title',
			[
				'label' => esc_html__( 'Title', 'ideapark-moderno' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'text_color',
			[
				'label'     => __( 'Text Color', 'ideapark-moderno' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'selectors' => [
					'{{WRAPPER}} .c-ip-hotspot__title' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'title_typography',
				'selector' => '{{WRAPPER}} .c-ip-hotspot__title',
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Render hotspot Carousel widget output on the frontend.
	 *
	 * Written in PHP and used to generate the final HTML.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function render() {
		global $product;
		$settings = $this->get_settings_for_display();
		?>
		<div
			class="c-ip-hotspot">
			<div class="c-ip-hotspot__list-wrap">
				<div class="c-ip-hotspot__list js-hotspot-carousel">

					<?php
					foreach ( $settings['image_list'] as $index => $item ) { ?>
						<?php $hotspots = ! empty( $item['hotspots'] ) ? json_decode( $item['hotspots'], true ) : []; ?>
						<div
							class="c-ip-hotspot__item elementor-repeater-item-<?php echo esc_attr( $item['_id'] ); ?>">
							<?php
							if ( ! empty( $item['link']['url'] ) ) {
								$is_link  = true;
								$link_key = 'link_' . $index;

								$this->add_link_attributes( $link_key, $item['link'] );
								$this->add_render_attribute( $link_key, 'class', 'c-ip-hotspot__link' );
							} else {
								$is_link = false;
							}
							?>

							<?php if ( ! empty( $item['image']['id'] ) ) { ?>
								<div class="c-ip-hotspot__image-wrap">
									<?php
									if ( ! empty( $item['image']['id'] ) && ( $type = get_post_mime_type( $item['image']['id'] ) ) ) {
										if ( $type == 'image/svg+xml' ) {
											echo ideapark_get_inline_svg( $item['image']['id'], 'c-ip-hotspot__image c-ip-hotspot__image--svg' );
										} else {
											if ( $image_meta = ideapark_image_meta( $item['image']['id'], 'full' ) ) {
												echo ideapark_img( $image_meta, 'c-ip-hotspot__image' );
											}
										}
									} ?>
									<?php if ( is_array( $hotspots ) ) { ?>
										<?php foreach ( $hotspots as $point ) { ?>
											<?php if ( ! empty( $point['product_id'] ) && ( ideapark_woocommerce_on() ) && ( $product = wc_get_product( apply_filters( 'wpml_object_id', (int) $point['product_id'], 'product', true ) ) ) ) { ?>
												<?php
												/**
												 * @var $product WC_Product
												 **/

												$permalink = $product->get_permalink();
												$thumbnail = $product->get_image( 'ideapark-compact' );
												$title     = $product->get_title();
												?>
												<div class="c-ip-hotspot__point js-carousel-point"
													 data-left="<?php echo esc_attr( $point['x'] ); ?>"
													 data-top="<?php echo esc_attr( $point['y'] ); ?>">
													<div class="c-ip-hotspot__point-popup">
														<div class="c-ip-hotspot__product-thumb">
															<?php
															if ( ! $permalink ) {
																echo ideapark_wrap( $thumbnail );
															} else {
																printf( '<a href="%s">%s</a>', esc_url( $permalink ), $thumbnail );
															}
															?>
														</div>
														<div class="c-ip-hotspot__col">
															<div class="c-ip-hotspot__product-categories">
																<?php ideapark_cut_product_categories(); ?>
															</div>
															<div class="c-ip-hotspot__product-title">
																<a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a>
															</div>
															<div class="c-ip-hotspot__product-price">
																<?php woocommerce_template_loop_price(); ?>
															</div>
														</div>
													</div>
												</div>
											<?php } ?>
										<?php } ?>
									<?php } ?>
								</div>
							<?php } ?>
							<?php if ( ! empty( $item['title_text'] ) || ! empty( $item['subheader'] ) ) { ?>
								<div class="c-ip-hotspot__content">
									<?php if ( $is_link ) { ?>
									<a <?php echo $this->get_render_attribute_string( $link_key ); ?>><?php } ?>
										<?php if ( ! empty( $item['title_text'] ) ) { ?>
											<div class="c-ip-hotspot__title"><?php echo $item['title_text']; ?></div>
										<?php } ?>
										<?php if ( ! empty( $item['subheader'] ) ) { ?>
											<div class="c-ip-hotspot__subheader"><?php echo $item['subheader']; ?></div>
										<?php } ?>
										<?php if ( $is_link ) { ?></a><?php } ?>
								</div>
							<?php } ?>
						</div>
						<?php
					}
					?>
				</div>
			</div>
			<div
				class="c-ip-hotspot__modal c-header__callback-popup c-header__callback-popup--disabled js-callback-popup js-hotspot-popup">
				<div class="c-header__callback-bg js-callback-close"></div>
				<div class="c-header__callback-wrap c-header__callback-wrap--quickview">
					<div class="js-hotspot-container"></div>
				</div>
				<button type="button" class="h-cb h-cb--svg c-header__callback-close js-callback-close"><i
						class="ip-close-rect"></i></button>
			</div>
		</div>
		<?php
	}
}
