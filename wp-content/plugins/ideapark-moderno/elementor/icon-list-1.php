<?php

use Elementor\Group_Control_Typography;
use Elementor\Icons_Manager;
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
class Ideapark_Elementor_Icon_List_1 extends Widget_Base {

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
		return 'ideapark-icon-list-1';
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
		return __( 'Advantages', 'ideapark-moderno' );
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
		return [ 'icon list', 'icon', 'list', 'advantages' ];
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
			'section_icon_list',
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
			'icon_list',
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

		$this->end_controls_section();

		$this->start_controls_section(
			'section_icon_settings',
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
				'default' => 'layout-1',
				'options' => [
					'layout-1' => __( 'Layout 1', 'ideapark-moderno' ),
					'layout-2' => __( 'Layout 2', 'ideapark-moderno' ),
				]
			]
		);

		$this->add_responsive_control(
			'max_width',
			[
				'label'      => __( 'Item Width', 'ideapark-moderno' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px', '%' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 1160,
					],
					'%'  => [
						'min' => 0,
						'max' => 100,
					],
				],
				'devices'    => [ 'desktop', 'tablet', 'mobile' ],

				'selectors' => [
					'{{WRAPPER}} .c-ip-icon-list-1__item' => 'width: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'icon_size',
			[
				'label'      => __( 'Icon Size', 'ideapark-moderno' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 1,
						'max' => 100,
					],
				],
				'devices'    => [ 'desktop', 'tablet', 'mobile' ],

				'selectors' => [
					'{{WRAPPER}} .c-ip-icon-list-1__icon' => 'font-size: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .c-ip-icon-list-1 svg'   => 'height: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'item_space',
			[
				'label'     => __( 'Item Space', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'space-evenly',
				'options'   => [
					'space-evenly'  => __( 'Evenly', 'ideapark-moderno' ),
					'space-around'  => __( 'Around', 'ideapark-moderno' ),
					'space-between' => __( 'Between', 'ideapark-moderno' ),
				],
				'selectors' => [
					'{{WRAPPER}} .c-ip-icon-list-1__list' => 'justify-content: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'icon_color',
			[
				'label'     => __( 'Icon Color', 'ideapark-moderno' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'selectors' => [
					'{{WRAPPER}} .c-ip-icon-list-1__icon'     => 'color: {{VALUE}};',
					'{{WRAPPER}} .c-ip-icon-list-1__item svg' => 'fill: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'text_color',
			[
				'label'     => __( 'Text Color', 'ideapark-moderno' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'selectors' => [
					'{{WRAPPER}} .c-ip-icon-list-1__item' => 'color: {{VALUE}};',
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
				'selector' => '{{WRAPPER}} .c-ip-icon-list-1__title',
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
		?>
		<div class="c-ip-icon-list-1 l-section">
			<ul class="c-ip-icon-list-1__list c-ip-icon-list-1__list--<?php echo $settings['layout']; ?>">
				<?php
				foreach ( $settings['icon_list'] as $index => $item ) { ?>
					<?php
					if ( ! empty( $item['link']['url'] ) ) {
						$link_key = 'link_' . $index;
						$this->add_link_attributes( $link_key, $item['link'] );
						$this->add_render_attribute( $link_key, 'class', 'c-ip-icon-list-1__link' );
						$is_link = true;
					} else {
						$is_link = false;
					}
					?>
					<li class="c-ip-icon-list-1__item c-ip-icon-list-1__item--<?php echo $settings['layout']; ?>">
						<?php
						if ( $is_link ) {
							echo '<a ' . $this->get_render_attribute_string( $link_key ) . '>';
						} ?>
						<div
							class="c-ip-icon-list-1__item-wrap c-ip-icon-list-1__item-wrap--<?php echo $settings['layout']; ?>">
							<?php if ( ! empty( $item['icon_svg'] ) ) { ?>
								<?php Icons_Manager::render_icon( $item['icon_svg'], [
									'aria-hidden' => 'true',
									'class'       => 'c-ip-icon-list-1__icon'
								] ); ?>
							<?php } ?>

							<?php if ( $item['title'] ) { ?>
								<div class="c-ip-icon-list-1__title"><?php echo $item['title']; ?></div>
							<?php } ?>
						</div>
						<?php if ( $is_link ) { ?></a><?php } ?>
					</li>
				<?php } ?>
			</ul>
		</div>
		<?php
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
}
