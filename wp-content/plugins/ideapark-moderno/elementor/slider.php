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
 * Elementor slider widget.
 *
 * Elementor widget that displays a bullet list with any chosen icons and texts.
 *
 * @since 1.0.0
 */
class Ideapark_Elementor_Slider extends Widget_Base {

	/**
	 * Get widget name.
	 *
	 * Retrieve slider widget name.
	 *
	 * @return string Widget name.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_name() {
		return 'ideapark-slider';
	}

	/**
	 * Get widget title.
	 *
	 * Retrieve slider widget title.
	 *
	 * @return string Widget title.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_title() {
		return __( 'Slider', 'ideapark-moderno' );
	}

	/**
	 * Get widget icon.
	 *
	 * Retrieve slider widget icon.
	 *
	 * @return string Widget icon.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_icon() {
		return 'eicon-slides';
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
		return [ 'carousel', 'slider' ];
	}

	/**
	 * Register slider widget controls.
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
				'label' => __( 'Slides', 'ideapark-moderno' ),
			]
		);


		$repeater = new Repeater();

		$repeater->add_control(
			'image_desktop',
			[
				'label'   => __( 'Image (Desktop)', 'ideapark-moderno' ),
				'type'    => Controls_Manager::MEDIA,
				'default' => [
					'url' => Utils::get_placeholder_image_src(),
				],
			]
		);

		$repeater->add_control(
			'image_mobile',
			[
				'label'   => __( 'Image (Mobile)', 'ideapark-moderno' ),
				'type'    => Controls_Manager::MEDIA,
				'default' => [
					'url' => Utils::get_placeholder_image_src(),
				],
			]
		);

		$repeater->add_control(
			'title',
			[
				'label'       => __( 'Title', 'ideapark-moderno' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => __( 'Enter title', 'ideapark-moderno' ),
				'label_block' => true,
			]
		);

		$repeater->add_control(
			'subtitle',
			[
				'label'       => __( 'Subtitle', 'ideapark-moderno' ),
				'type'        => Controls_Manager::TEXTAREA,
				'placeholder' => __( 'Enter text', 'ideapark-moderno' ),
				'label_block' => true,
			]
		);

		$repeater->add_control(
			'button_text',
			[
				'label'       => __( 'Button text', 'ideapark-moderno' ),
				'default'     => __( 'Read more', 'ideapark-moderno' ),
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
			]
		);

		$this->add_control(
			'slider_list',
			[
				'label'       => '',
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'title_field' => '{{{ title }}}',
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_slider_settings',
			[
				'label' => __( 'Slider Settings', 'ideapark-moderno' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_responsive_control(
			'height',
			[
				'label'      => __( 'Image aspect ratio (height / width)', 'ideapark-moderno' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min'  => 0.1,
						'max'  => 2,
						'step' => 0.01,
					],
				],
				'default'    => [
					'size' => 0.6,
				],
				'devices'    => [ 'desktop', 'tablet', 'mobile' ],

				'selectors' => [
					'{{WRAPPER}} .c-ip-slider__image-wrap' => 'padding-bottom: calc({{size}} * 100%)',
				],
			]
		);

		$this->add_control(
			'layout',
			[
				'label'   => __( 'Layout', 'ideapark-moderno' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'left',
				'options' => [
					'left'  => __( 'Text first', 'ideapark-moderno' ),
					'right' => __( 'Image first', 'ideapark-moderno' ),
				],
			]
		);

		$this->add_control(
			'button_type',
			[
				'label'   => __( 'Button Type', 'ideapark-moderno' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'default',
				'options' => [
					'default' => __( 'Default', 'ideapark-moderno' ),
					'outline' => __( 'Outline', 'ideapark-moderno' ),
				]
			]
		);

		$this->add_control(
			'slider_animation',
			[
				'label'   => __( 'Animation', 'ideapark-moderno' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '',
				'options' => [
					''               => __( 'Default', 'ideapark-moderno' ),
					'banners-fade'   => __( 'Fade', 'ideapark-moderno' ),
					'owl-fade-scale' => __( 'Fade and Scale', 'ideapark-moderno' ),
				]
			]
		);

		$this->add_control(
			'slider_autoplay',
			[
				'label'     => __( 'Autoplay', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'yes',
				'label_on'  => __( 'Yes', 'ideapark-moderno' ),
				'label_off' => __( 'No', 'ideapark-moderno' ),
			]
		);

		$this->add_control(
			'slider_animation_timeout',
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
					'slider_autoplay' => 'yes',
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
			]
		);


		$this->add_control(
			'random',
			[
				'label'     => __( 'Random sorting', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'no',
				'label_on'  => __( 'Yes', 'ideapark-moderno' ),
				'label_off' => __( 'No', 'ideapark-moderno' ),
			]
		);

		$this->add_control(
			'text_color',
			[
				'label'     => __( 'Text Color', 'ideapark-moderno' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'selectors' => [
					'{{WRAPPER}} .c-ip-slider' => 'color: {{VALUE}};',
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
				'selector' => '{{WRAPPER}} .c-ip-slider__title',
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_toggle_style_content',
			[
				'label' => esc_html__( 'Subtitle', 'ideapark-moderno' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);


		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'content_typography',
				'selector' => '{{WRAPPER}} .c-ip-slider__subtitle',
			]
		);

		$this->end_controls_section();

	}

	/**
	 * Render slider widget output on the frontend.
	 *
	 * Written in PHP and used to generate the final HTML.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		?>
		<div
			class="c-ip-slider c-ip-slider--<?php echo $settings['layout']; ?> js-slider">
			<div
				class="c-ip-slider__list c-ip-slider__list--<?php echo $settings['layout']; ?> js-slider-carousel h-carousel h-carousel--flex h-carousel--big-dots <?php if ( $settings['dots'] != 'yes' ) { ?> h-carousel--dots-hide<?php } else { ?> c-ip-slider__list--dots c-ip-slider__list--dotted <?php if ( $settings['slider_autoplay'] == 'yes' ) { ?> h-carousel--dot-animated <?php } ?><?php } ?> h-carousel--nav-hide"
				data-autoplay="<?php echo esc_attr( $settings['slider_autoplay'] ); ?>"
				data-animation="<?php echo esc_attr( $settings['slider_animation'] ); ?>"
				<?php if ( ! empty( $settings['slider_animation_timeout']['size'] ) ) { ?>
					data-animation-timeout="<?php echo esc_attr( abs( $settings['slider_animation_timeout']['size'] * 1000 ) ); ?>"
				<?php } ?>
				data-widget-id="<?php echo esc_attr( $this->get_id() ); ?>">
				<?php
				if ( $settings['random'] == 'yes' ) {
					shuffle( $settings['slider_list'] );
				}
				?>
				<?php foreach ( $settings['slider_list'] as $index => $item ) { ?>
					<?php $dot = $settings['slider_autoplay'] == 'yes' ? '<svg role="button" data-index="' . $index . '" class="c-ip-slider__circle" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid"><path d="M0,0 " id="arc-' . $this->get_id() . '-' . $index . '" fill="none" stroke="inherit" stroke-width="1"/></svg><button role="button" class="h-cb c-ip-slider__dot" ></button>' : '<button role="button" class="h-cb c-ip-slider__dot" ></button>'; ?>
					<div
						class="c-ip-slider__item c-ip-slider__item--<?php echo $settings['layout']; ?> elementor-repeater-item-<?php echo esc_attr( $item['_id'] ); ?>"
						data-dot="<?php echo esc_attr( $dot ); ?>"
						data-index="<?php echo esc_attr( $index ); ?>">
						<div class="c-ip-slider__image-wrap c-ip-slider__image-wrap--<?php echo $settings['layout']; ?>">
							<?php
							$has_desktop_image = ! empty( $item['image_desktop']['id'] );
							$has_mobile_image  = ! empty( $item['image_mobile']['id'] );
							$attr              = [ 'data-index' => $index ];
							if ( ! $index ) {
								$attr['fetchpriority'] = 'high';
							}
							echo '<picture>';
							if ( $has_mobile_image ) {
								echo ideapark_source( ideapark_image_meta( $item['image_mobile']['id'] ) );
							}
							if ( $has_desktop_image ) {
								echo ideapark_img( ideapark_image_meta( $item['image_desktop']['id'] ), 'c-ip-slider__image c-ip-slider__image--' . $settings['layout'] , $index && ideapark_mod( 'lazyload' ) ?: 'eager', $attr );
							}
							echo '</picture>';
							if ( ! empty( $item['button_link']['url'] ) ) {
								$link_key = 'link_' . $index;
								$this->add_link_attributes( $link_key, $item['button_link'] );
								if ( $item['button_text'] ) {
									$this->add_render_attribute( $link_key, 'class', 'c-button c-button--' . $settings['button_type'] . ' c-ip-slider__button c-ip-slider__button--' . $settings['layout'] );
									$this->add_render_attribute( $link_key, 'aria-label', $item['button_text'] . ': ' . $item['title'] ?: $item['subtitle'] );
								} else {
									$this->add_render_attribute( $link_key, 'class', 'c-ip-slider__link' );
								}
							} else {
								$link_key = '';
							}
							?>
						</div>
						<div
							class="c-ip-slider__wrap c-ip-slider__wrap--<?php echo $settings['layout']; ?>">
							<?php echo ideapark_wrap( $item['title'], '<div class="c-ip-slider__title c-ip-slider__title--' . $settings['layout'] . '"><span class="c-ip-slider__title-inner">', '</span></div>' ); ?>
							<?php echo ideapark_wrap( $item['subtitle'], '<div class="c-ip-slider__subtitle c-ip-slider__subtitle--' . $settings['layout'] . '"><span class="c-ip-slider__subtitle-inner">', '</span></div>' ); ?>
							<?php if ( $link_key && $item['button_text'] ) { ?>
								<a <?php echo $this->get_render_attribute_string( $link_key ); ?>><?php echo esc_html( $item['button_text'] ); ?></a>
							<?php } ?>
						</div>
						<?php if ( $link_key && ! $item['button_text'] ) { ?>
							<a <?php echo $this->get_render_attribute_string( $link_key ); ?>></a>
						<?php } ?>
					</div>
				<?php } ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render slider widget output in the editor.
	 *
	 * Written as a Backbone JavaScript template and used to generate the live preview.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected
	function content_template() {
	}
}
