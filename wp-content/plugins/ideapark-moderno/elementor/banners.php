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
 * Elementor banners widget.
 *
 * Elementor widget that displays a bullet list with any chosen icons and texts.
 *
 * @since 1.0.0
 */
class Ideapark_Elementor_Banners extends Widget_Base {

	/**
	 * Get widget name.
	 *
	 * Retrieve banners widget name.
	 *
	 * @return string Widget name.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_name() {
		return 'ideapark-banners';
	}

	/**
	 * Get widget title.
	 *
	 * Retrieve banners widget title.
	 *
	 * @return string Widget title.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_title() {
		return __( 'Banners', 'ideapark-moderno' );
	}

	/**
	 * Get widget icon.
	 *
	 * Retrieve banners widget icon.
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
		return [ 'banners', 'image', 'list' ];
	}

	/**
	 * Register banners widget controls.
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
				'label' => __( 'Banners', 'ideapark-moderno' ),
			]
		);

		$repeater = new Repeater();

		$repeater->add_control(
			'image',
			[
				'label'   => __( 'Choose image', 'ideapark-moderno' ),
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
			'text_above',
			[
				'label'       => __( 'Text above the title', 'ideapark-moderno' ),
				'type'        => Controls_Manager::TEXTAREA,
				'default'     => '',
				'placeholder' => __( 'Enter text', 'ideapark-moderno' ),
				'label_block' => true,
			]
		);


		$repeater->add_control(
			'header',
			[
				'label'       => __( 'Title', 'ideapark-moderno' ),
				'type'        => Controls_Manager::TEXTAREA,
				'default'     => __( 'Banner title', 'ideapark-moderno' ),
				'placeholder' => __( 'Enter banner title', 'ideapark-moderno' ),
				'label_block' => true,
			]
		);

		$repeater->add_control(
			'text_below',
			[
				'label'       => __( 'Text below the title', 'ideapark-moderno' ),
				'type'        => Controls_Manager::TEXTAREA,
				'label_block' => true,
				'placeholder' => __( 'Enter text', 'ideapark-moderno' ),
				'default'     => __( 'Shop now', 'ideapark-moderno' ),
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

		$repeater->add_control(
			'background_color',
			[
				'label'     => __( 'Background color', 'ideapark-moderno' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'selectors' => [
					'{{WRAPPER}} {{CURRENT_ITEM}}.c-ip-banners__item' => 'background-color: {{VALUE}};',
				],
			]
		);

		$repeater->add_control(
			'text_color',
			[
				'label'     => __( 'Text color', 'ideapark-moderno' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'selectors' => [
					'{{WRAPPER}} {{CURRENT_ITEM}}' => 'color: {{VALUE}};',
				],
			]
		);


		$this->add_control(
			'banner_list',
			[
				'label'       => '',
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'title_field' => '{{{ header }}}',
			]
		);


		$this->end_controls_section();

		$this->start_controls_section(
			'section_banners_settings',
			[
				'label' => __( 'Banners Settings', 'ideapark-moderno' ),
				'tab'   => Controls_Manager::TAB_STYLE,
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

		$this->add_control(
			'align',
			[
				'label'   => __( 'Alignment', 'ideapark-moderno' ),
				'type'    => Controls_Manager::CHOOSE,
				'options' => [
					'left'   => [
						'title' => __( 'Start', 'ideapark-moderno' ),
						'icon'  => 'eicon-text-align-left',
					],
					'center' => [
						'title' => __( 'Center', 'ideapark-moderno' ),
						'icon'  => 'eicon-text-align-center',
					],
					'right'  => [
						'title' => __( 'End', 'ideapark-moderno' ),
						'icon'  => 'eicon-text-align-right',
					],
				],
				'default' => 'center',
			]
		);

		$this->add_control(
			'text_color',
			[
				'label'     => __( 'Text color', 'ideapark-moderno' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'selectors' => [
					'{{WRAPPER}} .c-ip-banners__item' => 'color: {{VALUE}};',
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
					'{{WRAPPER}} .c-ip-banners__item' => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'border_color',
			[
				'label'     => __( 'Border color', 'ideapark-moderno' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'selectors' => [
					'{{WRAPPER}} .c-ip-banners__item:after' => 'border-color: {{VALUE}};',
				]
			]
		);

		$this->add_control(
			'hover_color',
			[
				'label'     => __( 'Accent color', 'ideapark-moderno' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'selectors' => [
					'(desktop) {{WRAPPER}} .c-ip-banners__item--link:hover .c-ip-banners__title' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'darkening',
			[
				'label'     => __( 'Darkening', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SLIDER,
				'default'   => [
					'size' => 0.2,
				],
				'range'     => [
					'px' => [
						'max'  => 1,
						'min'  => 0,
						'step' => 0.01,
					],
				],
				'selectors' => [
					'{{WRAPPER}} .c-ip-banners__shadow' => 'opacity: {{SIZE}};',
				],
			]
		);

		$this->add_responsive_control(
			'max_width',
			[
				'label'      => __( 'Max width of the text block', 'ideapark-moderno' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px', '%' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 600,
					],
					'%'  => [
						'min' => 0,
						'max' => 100,
					],
				],
				'devices'    => [ 'desktop', 'tablet', 'mobile' ],

				'selectors' => [
					'{{WRAPPER}} .c-ip-banners__wrap' => 'max-width: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'min_height',
			[
				'label'      => __( 'Min banner height', 'ideapark-moderno' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 50,
						'max' => 600,
					]
				],
				'devices'    => [ 'desktop', 'tablet', 'mobile' ],

				'selectors' => [
					'{{WRAPPER}} .c-ip-banners__item' => 'min-height: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_control(
			'random_sorting',
			[
				'label'        => __( 'Random sorting', 'ideapark-moderno' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'label_on'     => __( 'Yes', 'ideapark-moderno' ),
				'label_off'    => __( 'No', 'ideapark-moderno' ),
				'return_value' => 'yes',
			]
		);


		$this->add_control(
			'banner_mode',
			[
				'label'   => __( 'Banner mode', 'ideapark-moderno' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'static',
				'options' => [
					'static'   => __( 'Static', 'ideapark-moderno' ),
					'changing' => __( 'Random changing', 'ideapark-moderno' ),
					'carousel' => __( 'Carousel', 'ideapark-moderno' ),
				]
			]
		);

		$this->add_control(
			'changing_animation',
			[
				'label'     => __( 'Animation', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'banners-fade',
				'options'   => [
					'banners-fade'       => __( 'Fade', 'ideapark-moderno' ),
					'banners-fade-scale' => __( 'Fade and Scale', 'ideapark-moderno' ),
					'banners-slide-up'   => __( 'Slide Up', 'ideapark-moderno' ),
				],
				'condition' => [
					'banner_mode' => 'changing',
				],
			]
		);

		$this->add_control(
			'changing_animation_timeout',
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
					'banner_mode' => 'changing',
				],
			]
		);

		$this->add_control(
			'carousel_animation',
			[
				'label'     => __( 'Animation', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '',
				'options'   => [
					''               => __( 'Default', 'ideapark-moderno' ),
					'banners-fade'   => __( 'Fade', 'ideapark-moderno' ),
					'owl-fade-scale' => __( 'Fade and Scale', 'ideapark-moderno' ),
				],
				'condition' => [
					'banner_mode' => 'carousel',
				],
			]
		);

		$this->add_control(
			'carousel_autoplay',
			[
				'label'     => __( 'Autoplay', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'yes',
				'label_on'  => esc_html__( 'Yes', 'ideapark-moderno' ),
				'label_off' => esc_html__( 'No', 'ideapark-moderno' ),
				'condition' => [
					'banner_mode' => 'carousel',
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
					'banner_mode'       => 'carousel',
					'carousel_autoplay' => 'yes',
				],
			]
		);

		$this->add_control(
			'carousel_dots',
			[
				'label'     => __( 'Navigation dots', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'yes',
				'label_on'  => esc_html__( 'Show', 'ideapark-moderno' ),
				'label_off' => esc_html__( 'Hide', 'ideapark-moderno' ),
				'condition' => [
					'banner_mode' => 'carousel',
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
				'selector' => '{{WRAPPER}} .c-ip-banners__title',
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Render banners widget output on the frontend.
	 *
	 * Written in PHP and used to generate the final HTML.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$class    = [];

		?>
		<div class="c-ip-banners">
			<div class="c-ip-banners__list-wrap">
				<div
					<?php if ( $settings['banner_mode'] == 'changing' ) {
						$class[] = 'js-ip-banners-changing';
						?>
						data-count="<?php echo sizeof( $settings['banner_list'] ); ?>"
						data-animation="<?php echo esc_attr( $settings['changing_animation'] ); ?>"
						<?php if ( ! empty( $settings['changing_animation_timeout']['size'] ) ) { ?>
							data-animation-timeout="<?php echo esc_attr( abs( $settings['changing_animation_timeout']['size'] * 1000 ) ); ?>"
						<?php } ?>
					<?php } elseif ( $settings['banner_mode'] == 'carousel' ) {
						$class[] = 'js-ip-banners-carousel h-carousel h-carousel--hover h-carousel--loop h-carousel--flex h-carousel--round h-carousel--border';
						$class[] = ( $settings['carousel_dots'] == 'yes' ? 'c-ip-banners__list--dots h-carousel--default-dots' : 'h-carousel--dots-hide' );
						$class[] = 'h-carousel--nav-hide';
						?>
						data-count="<?php echo sizeof( $settings['banner_list'] ); ?>"
						data-autoplay="<?php echo esc_attr( $settings['carousel_autoplay'] ); ?>"
						data-animation="<?php echo esc_attr( $settings['carousel_animation'] ); ?>"
						<?php if ( ! empty( $settings['carousel_animation_timeout']['size'] ) ) { ?>
							data-animation-timeout="<?php echo esc_attr( abs( $settings['carousel_animation_timeout']['size'] * 1000 ) ); ?>"
						<?php } ?>
					<?php } ?>
					class="c-ip-banners__list c-ip-banners__list--<?php echo sizeof( $settings['banner_list'] ); ?> c-ip-banners__list--<?php echo $settings['banner_mode']; ?> <?php echo implode( ' ', array_filter( $class ) ); ?>">

					<?php
					if ( $settings['random_sorting'] ) {
						shuffle( $settings['banner_list'] );
					}
					foreach ( $settings['banner_list'] as $index => $item ) : ?>
						<?php
						if ( ! empty( $item['link']['url'] ) ) {
							$is_link  = true;
							$link_key = 'link_' . $index;

							$this->add_link_attributes( $link_key, $item['link'] );
							$this->add_render_attribute( $link_key, 'class', 'c-ip-banners__link' );
							$this->add_render_attribute( $link_key, 'aria-label', $item['header'] );
						} else {
							$is_link = false;
						}
						$item_id = ( ! empty( $item['image']['id'] ) ? $item['image']['id'] . '-' : '' ) . substr( md5( $item['header'] . ( $is_link ? $item['link']['url'] : '' ) ), 0, 8 );
						?>
						<div
							data-id="<?php echo esc_attr( $item_id ); ?>"
							class="c-ip-banners__item <?php if ( $is_link ) { ?> c-ip-banners__item--link c-ip-banners__item--hover-<?php echo ! empty( $settings['on_hover'] ) ? $settings['on_hover'] : 'brightening'; ?><?php } ?> elementor-repeater-item-<?php echo esc_attr( $item['_id'] ); ?>">

							<div class="c-ip-banners__item-image-wrap">
								<?php if ( ! empty( $item['image']['id'] ) && ( $type = get_post_mime_type( $item['image']['id'] ) ) ) {
									if ( $type == 'image/svg+xml' ) {
										echo ideapark_get_inline_svg( $item['image']['id'], 'c-ip-banners__svg' );
									} else {
										echo ideapark_img( ideapark_image_meta( $item['image']['id'], 'full', '(min-width: 768px) 33vw, (min-width: 415px) 415px, 100vw' ), 'c-ip-banners__image' );
									}
								}
								?>
							</div>
							<div class="c-ip-banners__shadow"></div>
							<div
								class="c-ip-banners__wrap c-ip-banners__wrap--<?php echo $settings['align']; ?>">
								<?php if ( ! empty( $item['text_above'] ) ) { ?>
									<div
										class="c-ip-banners__text-above"><?php echo nl2br( esc_html( $item['text_above'] ) ); ?></div>
								<?php } ?>
								<?php if ( ! empty( $item['header'] ) ) { ?>
									<div class="c-ip-banners__title"><span
											class="c-ip-banners__title-size"><?php echo nl2br( esc_html( $item['header'] ) ); ?></span>
									</div>
								<?php } ?>
								<?php if ( $is_link && $item['text_below'] ) { ?>
									<span
										class="c-ip-banners__text-below"><?php echo nl2br( esc_html( $item['text_below'] ) ); ?></span>
								<?php } ?>
							</div>
							<?php if ( $is_link ) { ?>
								<a <?php echo $this->get_render_attribute_string( $link_key ); ?>></a>
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
	 * Render banners widget output in the editor.
	 *
	 * Written as a Backbone JavaScript template and used to generate the live preview.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function content_template() {
	}
}
