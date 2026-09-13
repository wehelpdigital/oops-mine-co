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
class Ideapark_Elementor_Image_List_2 extends Widget_Base {

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
		return 'ideapark-image-list-2';
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
		return __( 'Logo List', 'ideapark-moderno' );
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
		return [ 'image list', 'image', 'list', 'logo' ];
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
				'label' => __( 'Image List', 'ideapark-moderno' ),
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

		$repeater->add_responsive_control(
			'item_width',
			[
				'label'      => __( 'Image width', 'ideapark-moderno' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 25,
						'max' => 200,
					]
				],
				'devices'    => [ 'desktop', 'tablet', 'mobile' ],

				'selectors' => [
					'{{WRAPPER}} {{CURRENT_ITEM}} .c-ip-image-list-2__thumb' => 'width: {{SIZE}}{{UNIT}};',
				],
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
				'label'  => '',
				'type'   => Controls_Manager::REPEATER,
				'fields' => $repeater->get_controls(),
//				'title_field' => '{{{ title_text }}}',
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
					'combined' => __( 'Grid on Desktop / Carousel on Tablet and Mobile', 'ideapark-moderno' ),
				]
			]
		);

		$this->add_control(
			'arrows',
			[
				'label'     => __( 'Arrows', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'yes',
				'label_on'  => __( 'Show', 'ideapark-moderno' ),
				'label_off' => __( 'Hide', 'ideapark-moderno' ),
				'condition' => [
					'layout' => [ 'carousel', 'combined' ],
				],
			]
		);

		$this->add_control(
			'autoplay',
			[
				'label'     => __( 'Autoplay', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'no',
				'label_on'  => esc_html__( 'Yes', 'ideapark-moderno' ),
				'label_off' => esc_html__( 'No', 'ideapark-moderno' ),
				'condition' => [
					'layout' => [ 'carousel', 'combined' ],
				],
			]
		);

		$this->add_control(
			'animation_timeout',
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
					'autoplay' => 'yes',
					'layout'   => [ 'carousel', 'combined' ],
				],
			]
		);

		$this->add_responsive_control(
			'space',
			[
				'label'      => __( 'Space between items', 'ideapark-moderno' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'default'    => [
					'size' => 100,
				],
				'range'      => [
					'px' => [
						'min' => 15,
						'max' => 100,
					]
				],
				'devices'    => [ 'desktop', 'tablet', 'mobile' ],

				'selectors' => [
					'{{WRAPPER}} .c-ip-image-list-2'       => ' --space: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .c-ip-image-list-2__item' => 'margin-left: calc({{SIZE}}{{UNIT}} / 2);margin-right: calc({{SIZE}}{{UNIT}} / 2);',
					'{{WRAPPER}} .c-ip-image-list-2__list' => 'margin-left: calc(-{{SIZE}}{{UNIT}} / 2);margin-right: calc(-{{SIZE}}{{UNIT}} / 2);',
				],
			]
		);

		$this->add_responsive_control(
			'max_item_width',
			[
				'label'      => __( 'Max item width', 'ideapark-moderno' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'default'    => [
					'size' => 130,
				],
				'range'      => [
					'px' => [
						'min' => 50,
						'max' => 200,
					]
				],
				'devices'    => [ 'desktop', 'tablet', 'mobile' ],

				'selectors' => [
					'{{WRAPPER}} .c-ip-image-list-2__image' => 'max-width:{{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .c-ip-image-list-2__svg'   => 'max-width:{{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'max_item_height',
			[
				'label'      => __( 'Max item height', 'ideapark-moderno' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'default'    => [
					'size' => 130,
				],
				'range'      => [
					'px' => [
						'min' => 50,
						'max' => 200,
					]
				],
				'devices'    => [ 'desktop', 'tablet', 'mobile' ],

				'selectors' => [
					'{{WRAPPER}} .c-ip-image-list-2__image' => 'max-height:{{SIZE}}{{UNIT}}',
					'{{WRAPPER}} .c-ip-image-list-2__svg'   => 'max-height:{{SIZE}}{{UNIT}}',
				],
			]
		);


		$this->add_control(
			'on_blur_opacity',
			[
				'label'     => __( 'On blur opacity', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SLIDER,
				'default'   => [
					'size' => 1,
				],
				'range'     => [
					'px' => [
						'max'  => 1,
						'min'  => 0.10,
						'step' => 0.01,
					],
				],
				'selectors' => [
					'{{WRAPPER}} .c-ip-image-list-2__thumb' => 'opacity: {{SIZE}};',
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
		?>
		<div
			class="c-ip-image-list-2 c-ip-image-list-2--<?php echo $settings['layout']; ?><?php if ( in_array( $settings['layout'], [
					'carousel',
					'combined'
				] ) && $settings['arrows'] == 'yes' ) { ?> c-ip-image-list-2--nav<?php } ?>">
			<div class="c-ip-image-list-2__wrap c-ip-image-list-2__wrap--<?php echo $settings['layout']; ?>">
				<div
					<?php if ( in_array( $settings['layout'], [ 'carousel', 'combined' ] ) ) { ?>
						data-autoplay="<?php echo esc_attr( $settings['autoplay'] ); ?>"
						<?php if ( ! empty( $settings['animation_timeout']['size'] ) ) { ?>
							data-animation-timeout="<?php echo esc_attr( abs( $settings['animation_timeout']['size'] * 1000 ) ); ?>"
						<?php } ?>
					<?php } ?>
					class="c-ip-image-list-2__list c-ip-image-list-2__list--<?php echo $settings['layout']; ?> <?php if ( in_array( $settings['layout'], [
						'carousel',
						'combined'
					] ) ) { ?> js-image-list-2 h-carousel h-carousel--flex h-carousel--dots-hide <?php if ( $settings['arrows'] != 'yes' ) { ?>h-carousel--nav-hide<?php } else { ?>h-carousel--hover h-carousel--mobile-arrows h-carousel--small<?php } ?><?php } ?>">
					<?php
					foreach ( $settings['icon_list'] as $index => $item ) : ?>
						<div
							class="c-ip-image-list-2__item c-ip-image-list-2__item--<?php echo $settings['layout']; ?> elementor-repeater-item-<?php echo esc_attr( $item['_id'] ); ?>">
							<?php
							if ( ! empty( $item['link']['url'] ) ) {
								$is_link  = true;
								$link_key = 'link_' . $index;

								$this->add_link_attributes( $link_key, $item['link'] );
								$this->add_render_attribute( $link_key, 'class', 'c-ip-image-list-2__link' );
							} else {
								$is_link = false;
							} ?>
							<?php if ( $is_link ) { ?>
							<a <?php echo $this->get_render_attribute_string( $link_key ); ?>>
								<?php } ?>
								<div class="c-ip-image-list-2__thumb">
									<?php if ( ! empty( $item['image']['id'] ) && ( $type = get_post_mime_type( $item['image']['id'] ) ) ) {
										if ( $type == 'image/svg+xml' ) {
											echo ideapark_get_inline_svg( $item['image']['id'], 'c-ip-image-list-2__svg' );
										} else {
											echo ideapark_img( ideapark_image_meta( $item['image']['id'], 'medium' ), 'c-ip-image-list-2__image' );
										}
									}
									?>
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
