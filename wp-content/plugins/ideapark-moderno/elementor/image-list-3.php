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
 * Elementor image list widget.
 *
 * Elementor widget that displays a bullet list with any chosen icons and texts.
 *
 * @since 1.0.0
 */
class Ideapark_Elementor_Image_List_3 extends Widget_Base {

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
		return 'ideapark-image-list-3';
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
		return __( 'Categories', 'ideapark-moderno' );
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
		return 'eicon-gallery-grid';
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
		return [ 'category', 'image list', 'image', 'list' ];
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
			'section_image',
			[
				'label' => __( 'Category list', 'ideapark-moderno' ),
			]
		);

		$repeater = new Repeater();

		$repeater->add_control(
			'image',
			[
				'label'   => __( 'Choose Image', 'ideapark-moderno' ),
				'type'    => Controls_Manager::MEDIA,
				'dynamic' => [
					'active' => true,
				],
				'default' => [
					'url' => Utils::get_placeholder_image_src(),
				],
			]
		);

		$repeater->add_control(
			'title_text',
			[
				'label'       => __( 'Title', 'ideapark-moderno' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'This is the heading', 'ideapark-moderno' ),
				'placeholder' => __( 'Enter your title', 'ideapark-moderno' ),
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
			'icon_list',
			[
				'label'       => '',
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'title_field' => '{{{ title_text }}}',
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_image_settings',
			[
				'label' => __( 'List Settings', 'ideapark-moderno' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);
		$this->add_control(
			'layout',
			[
				'label'   => __( 'Layout', 'ideapark-moderno' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'grid',
				'options' => [
					'grid'     => __( 'Grid', 'ideapark-moderno' ),
					'carousel' => __( 'Carousel', 'ideapark-moderno' ),
				]
			]
		);
		$this->add_control(
			'on_hover',
			[
				'label'   => __( 'Hover', 'ideapark-moderno' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'brightening',
				'options' => [
					'none'        => __( 'None', 'ideapark-moderno' ),
					'brightening' => __( 'Image Brightening', 'ideapark-moderno' ),
					'zoom'        => __( 'Image Zoom', 'ideapark-moderno' ),
				]
			]
		);

		$this->add_responsive_control(
			'items_per_row',
			[
				'label'     => __( 'Items per row', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '',
				'options'   => [
					0 => __( 'Default', 'ideapark-moderno' ),
					1 => __( '1', 'ideapark-moderno' ),
					2 => __( '2', 'ideapark-moderno' ),
					3 => __( '3', 'ideapark-moderno' ),
					4 => __( '4', 'ideapark-moderno' ),
					5 => __( '5', 'ideapark-moderno' ),
				],
				'devices'   => [ 'desktop', 'tablet' ],
				'selectors' => [
					'{{WRAPPER}} .c-ip-image-list-3' => '--items-per-row: {{value}};',
				],
			]
		);

		$this->add_responsive_control(
			'item_height',
			[
				'label'      => __( 'Item height', 'ideapark-moderno' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px', '%' ],
				'default'    => [
					'size' => 300,
					'unit' => 'px',
				],
				'range'      => [
					'px' => [
						'min' => 100,
						'max' => 1000,
					],
					'%'  => [
						'min' => 0,
						'max' => 100,
					]
				],
				'devices'    => [ 'desktop', 'tablet', 'mobile' ],

				'selectors' => [
					'{{WRAPPER}} .c-ip-image-list-3__wrap' => 'height: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_control(
			'carousel_on_mobile',
			[
				'label'     => __( 'Carousel on mobile', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'no',
				'label_on'  => __( 'Yes', 'ideapark-moderno' ),
				'label_off' => __( 'No', 'ideapark-moderno' ),
				'condition' => [
					'layout' => 'grid',
				],
			]
		);

		$this->add_control(
			'arrows',
			[
				'label'      => __( 'Arrows', 'ideapark-moderno' ),
				'type'       => Controls_Manager::SWITCHER,
				'default'    => 'yes',
				'label_on'   => __( 'Show', 'ideapark-moderno' ),
				'label_off'  => __( 'Hide', 'ideapark-moderno' ),
				'conditions' => [
					'relation' => 'or',
					'terms'    => [
						[
							'name'     => 'layout',
							'operator' => '==',
							'value'    => 'carousel'
						],
						[
							'name'     => 'carousel_on_mobile',
							'operator' => '==',
							'value'    => 'yes'
						]
					]
				],
			]
		);

		$this->add_control(
			'dots',
			[
				'label'      => __( 'Navigation dots', 'ideapark-moderno' ),
				'type'       => Controls_Manager::SWITCHER,
				'default'    => 'no',
				'label_on'   => __( 'Show', 'ideapark-moderno' ),
				'label_off'  => __( 'Hide', 'ideapark-moderno' ),
				'conditions' => [
					'relation' => 'or',
					'terms'    => [
						[
							'name'     => 'layout',
							'operator' => '==',
							'value'    => 'carousel'
						],
						[
							'name'     => 'carousel_on_mobile',
							'operator' => '==',
							'value'    => 'yes'
						]
					]
				],
			]
		);

		$this->add_responsive_control(
			'title_max_width',
			[
				'label'      => __( 'Title Max Width', 'ideapark-moderno' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px', '%' ],
				'default'    => [
					'size' => 65,
					'unit' => '%',
				],
				'range'      => [
					'px' => [
						'min' => 90,
						'max' => 500,
					],
					'%'  => [
						'min' => 0,
						'max' => 100,
					]
				],
				'devices'    => [ 'desktop', 'tablet', 'mobile' ],

				'selectors' => [
					'{{WRAPPER}} .c-ip-image-list-3__title' => 'max-width: {{SIZE}}{{UNIT}};',
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

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'title_typography',
				'selector' => '{{WRAPPER}} .c-ip-image-list-3__title',
			]
		);

		$this->add_control(
			'text_color',
			[
				'label'     => __( 'Text color', 'ideapark-moderno' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'selectors' => [
					'{{WRAPPER}} .c-ip-image-list-3__title' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'background_color',
			[
				'label'     => __( 'Background color', 'ideapark-moderno' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'selectors' => [
					'{{WRAPPER}} .c-ip-image-list-3__title' => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'accent_color',
			[
				'label'     => __( 'Accent color', 'ideapark-moderno' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'selectors' => [
					'(desktop){{WRAPPER}} .c-ip-image-list-3__item:hover .c-ip-image-list-3__title' => 'color: {{VALUE}} !important;',
				],
			]
		);

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
		$class    = "js-image-list-3 h-carousel h-carousel--flex "
		            . ( $settings['dots'] == 'yes' ? ' h-carousel--default-dots' : ' h-carousel--dots-hide' )
		            . ( $settings['arrows'] == 'yes' ? ' h-carousel--inner h-carousel--round h-carousel--hover' : ' h-carousel--nav-hide' );

		$count = sizeof( $settings['icon_list'] );

		$items_per_row        = (int) ( ! empty( $settings['items_per_row'] ) ? $settings['items_per_row'] : 4 );
		$items_per_row_tablet = (int) ( ! empty( $settings['items_per_row_tablet'] ) ? $settings['items_per_row_tablet'] : $items_per_row );

		$sizes = '(min-width: 1190px) calc(100vw / ' . $items_per_row . '), (min-width: 768) calc(100vw / ' . $items_per_row_tablet . '), 100vw';

		?>
		<div
			class="c-ip-image-list-3 c-ip-image-list-3--<?php echo $settings['layout']; ?>"
			style="--count: <?php echo $count; ?>">
			<div class="c-ip-image-list-3__list-wrap">
				<div
					class="c-ip-image-list-3__list c-ip-image-list-3__list--<?php echo $count; ?> c-ip-image-list-3__list--<?php echo $settings['layout']; ?> <?php if ( $settings['layout'] == 'carousel' ) {
						echo $class;
					} ?> <?php if ( $settings['layout'] == 'grid' && $settings['carousel_on_mobile'] == 'yes' ) { ?> c-ip-image-list-3__list--combined js-image-list-3-combined<?php } ?>"
					data-layout="<?php echo esc_attr( $settings['layout'] ); ?>"
					data-count="<?php echo $count; ?>"
					data-items-desktop="<?php echo $items_per_row; ?>"
					data-items-tablet="<?php echo $items_per_row_tablet; ?>"
					<?php if ( $settings['layout'] == 'grid' && $settings['carousel_on_mobile'] == 'yes' ) { ?>data-combined="<?php echo esc_attr( $class ); ?>"<?php } ?>>
					<?php
					foreach ( $settings['icon_list'] as $index => $item ) : ?>
						<div
							class="c-ip-image-list-3__item c-ip-image-list-3__item--<?php echo $count; ?> c-ip-image-list-3__item--<?php echo $settings['layout']; ?> c-ip-image-list-3__item--hover-<?php echo ! empty( $settings['on_hover'] ) ? $settings['on_hover'] : 'brightening'; ?>">
							<?php
							if ( ! empty( $item['link']['url'] ) ) {
								$is_link  = true;
								$link_key = 'link_' . $index;

								$this->add_link_attributes( $link_key, $item['link'] );
								$this->add_render_attribute( $link_key, 'class', 'c-ip-image-list-3__link' );
							} else {
								$is_link = false;
							} ?>
							<?php if ( $is_link ) { ?>
							<a <?php echo $this->get_render_attribute_string( $link_key ); ?>>
								<?php } ?>
								<div class="c-ip-image-list-3__item-image-wrap">
									<?php if ( ! empty( $item['image']['id'] ) && ( $type = get_post_mime_type( $item['image']['id'] ) ) ) {
										if ( $type == 'image/svg+xml' ) {
											echo ideapark_get_inline_svg( $item['image']['id'], 'c-ip-image-list-3__svg' );
										} else {
											echo ideapark_img( ideapark_image_meta( $item['image']['id'], 'full', $sizes ), 'c-ip-image-list-3__image' );
										}
									}
									?>
								</div>
								<div class="c-ip-image-list-3__shadow"></div>
								<div
									class="c-ip-image-list-3__wrap c-ip-image-list-3__wrap--<?php echo $settings['layout']; ?>">
									<?php if ( ! empty( $item['title_text'] ) ) { ?>
										<div class="c-ip-image-list-3__title"><?php echo $item['title_text']; ?></div>
									<?php } ?>
								</div>
								<?php if ( $is_link ) { ?>
							</a>
						<?php } ?>
						</div>
					<?php
					endforeach;
					?>
				</div>
			</div>
		</div>
		<?php
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
