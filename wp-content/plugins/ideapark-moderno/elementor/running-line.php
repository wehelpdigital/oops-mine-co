<?php

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Text_Shadow;
use Elementor\Icons_Manager;
use Elementor\Repeater;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Elementor running Line widget.
 *
 * Elementor widget that displays an eye-catching headlines.
 *
 * @since 1.0.0
 */
class Ideapark_Elementor_Running_Line extends Widget_Base {

	/**
	 * Get widget name.
	 *
	 * Retrieve running Line widget name.
	 *
	 * @return string Widget name.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_name() {
		return 'ideapark-running-line';
	}

	/**
	 * Get widget title.
	 *
	 * Retrieve running Line widget title.
	 *
	 * @return string Widget title.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_title() {
		return __( 'Infinite Marquee', 'ideapark-moderno' );
	}

	/**
	 * Get widget icon.
	 *
	 * Retrieve running Line widget icon.
	 *
	 * @return string Widget icon.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_icon() {
		return 'eicon-t-letter';
	}

	/**
	 * Get widget categories.
	 *
	 * Retrieve the list of categories the running Line widget belongs to.
	 *
	 * Used to determine where to display the widget in the editor.
	 *
	 * @return array Widget categories.
	 * @since  2.0.0
	 * @access public
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
		return [ 'running Line', 'running', 'text', 'line' ];
	}

	/**
	 * Register running Line widget controls.
	 *
	 * Adds different input fields to allow the user to change and customize the widget settings.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_title',
			[
				'label' => __( 'Item List', 'ideapark-moderno' ),
			]
		);

		$repeater = new Repeater();

		$repeater->add_control(
			'title',
			[
				'label'       => __( 'Title', 'ideapark-moderno' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => __( 'List Item', 'ideapark-moderno' ),
				'placeholder' => __( 'Enter title', 'ideapark-moderno' ),
				'dynamic'     => [
					'active' => true,
				],
			]
		);

		$repeater->add_control(
			'icon_svg',
			[
				'label'            => __( 'Icon', 'ideapark-moderno' ),
				'type'             => Controls_Manager::ICONS,
				'label_block'      => true,
				'default'          => [
					'value'   => 'fas fa-star',
					'library' => 'fa-solid',
				],
				'fa4compatibility' => 'icon'
			]
		);

		$repeater->add_control(
			'link',
			[
				'label'       => __( 'Link', 'ideapark-moderno' ),
				'type'        => Controls_Manager::URL,
				'dynamic'     => [
					'active' => true,
				],
				'label_block' => true,
				'placeholder' => __( 'https://your-link.com', 'ideapark-moderno' ),
			]
		);

		$this->add_control(
			'item_list',
			[
				'label'       => '',
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => [
					[
						'title'    => __( 'List Item #1', 'ideapark-moderno' ),
						'icon_svg' => 'fa fa-dot-circle-o',
						'link'     => '#'
					],
					[
						'title'    => __( 'List Item #2', 'ideapark-moderno' ),
						'icon_svg' => 'fa fa-dot-circle-o',
						'link'     => '#'
					],
					[
						'title'    => __( 'List Item #3', 'ideapark-moderno' ),
						'icon_svg' => 'fa fa-dot-circle-o',
						'link'     => '#'
					],
				],
				'title_field' => '{{{ elementor.helpers.renderIcon( this, icon_svg, {}, "i", "panel" ) }}} {{{ title }}}',
			]
		);


		$this->add_control(
			'line_animation',
			[
				'label'      => __( 'Animation Duration (sec)', 'ideapark-moderno' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 's' ],
				'default'    => [
					'size' => 10,
				],
				'range'      => [
					'px' => [
						'min' => 1,
						'max' => 20,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .c-ip-running-line__content' => 'animation-duration: {{SIZE}}s',
				],
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_title_style',
			[
				'label' => __( 'Style', 'ideapark-moderno' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'title_color',
			[
				'label'     => __( 'Text Color', 'ideapark-moderno' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'selectors' => [
					'{{WRAPPER}} .c-ip-running-line' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'gap',
			[
				'label'      => __( 'Gap between items', 'ideapark-moderno' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'default'    => [
					'size' => 15,
				],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 100,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .c-ip-running-line' => '--gap: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_control(
			'inner_gap',
			[
				'label'      => __( 'Gap between icon and title', 'ideapark-moderno' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'default'    => [
					'size' => 15,
				],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 100,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .c-ip-running-line__item' => '--inner-gap: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'typography',
				'selector' => '{{WRAPPER}} .c-ip-running-line',
			]
		);

		$this->add_group_control(
			Group_Control_Text_Shadow::get_type(),
			[
				'name'     => 'text_shadow',
				'selector' => '{{WRAPPER}} .c-ip-running-line',
			]
		);

		$this->add_responsive_control(
			'separator_font_size',
			[
				'label'          => __( 'Separator Font Size', 'ideapark-moderno' ),
				'type'           => \Elementor\Controls_Manager::SLIDER,
				'range'          => [
					'px' => [
						'min' => 1,
						'max' => 100,
					],
					'em' => [
						'min' => 0.1,
						'max' => 2,
					],
				],
				'size_units'     => [ 'px', 'em' ],
				'selectors'      => [
					'{{WRAPPER}} .c-ip-running-line__icon'     => 'font-size: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .c-ip-running-line__item svg' => 'width: {{SIZE}}{{UNIT}};height: {{SIZE}}{{UNIT}}',
				],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Render running Line widget output on the frontend.
	 *
	 * Written in PHP and used to generate the final HTML.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function render() {
		$settings = $this->get_settings_for_display(); ?>
		<div class="c-ip-running-line js-ip-running-line">
			<ul class="c-ip-running-line__content js-ip-running-line-content">
				<?php foreach ( $settings['item_list'] as $index => $item ) { ?>
					<?php
					if ( ! empty( $item['link']['url'] ) ) {
						$link_key = 'link_' . $index;
						$this->add_link_attributes( $link_key, $item['link'] );
						$this->add_render_attribute( $link_key, 'class', 'c-ip-running-line__link' );
						$is_link = true;
					} else {
						$is_link = false;
					}
					?>
					<li class="c-ip-running-line__item">
						<?php
						if ( $is_link ) {
							echo '<a ' . $this->get_render_attribute_string( $link_key ) . '>';
						} ?>

						<?php if ( ! empty( $item['icon_svg'] ) ) { ?>
							<?php Icons_Manager::render_icon( $item['icon_svg'], [
								'aria-hidden' => 'true',
								'class'       => 'c-ip-running-line__icon'
							] ); ?>
						<?php } ?>

						<?php if ( $item['title'] ) { ?>
							<div class="c-ip-running-line__title"><?php echo $item['title']; ?></div>
						<?php } ?>
						<?php if ( $is_link ) { ?></a><?php } ?>
					</li>
				<?php } ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render running Line widget output in the editor.
	 *
	 * Written as a Backbone JavaScript template and used to generate the live preview.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function content_template() {

	}
}

