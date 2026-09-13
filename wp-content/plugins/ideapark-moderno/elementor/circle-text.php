<?php

use Elementor\Core\Settings\Page\Manager as PageManager;
use Elementor\Plugin;
use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Text_Shadow;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Elementor circle text widget.
 *
 * Elementor widget that displays an eye-catching headlines.
 *
 * @since 1.0.0
 */
class Ideapark_Elementor_Circle_Text extends Widget_Base {

	/**
	 * Get widget name.
	 *
	 * Retrieve circle text widget name.
	 *
	 * @return string Widget name.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_name() {
		return 'ideapark-circle-text';
	}

	/**
	 * Get widget title.
	 *
	 * Retrieve circle text widget title.
	 *
	 * @return string Widget title.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_title() {
		return __( 'Circle Text', 'ideapark-moderno' );
	}

	/**
	 * Get widget icon.
	 *
	 * Retrieve circle text widget icon.
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
	 * Retrieve the list of categories the circle text widget belongs to.
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
		return [ 'circle text', 'circle', 'text' ];
	}

	/**
	 * Register circle text widget controls.
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
				'label' => __( 'Circle Text', 'ideapark-moderno' ),
			]
		);

		$this->add_control(
			'title',
			[
				'label'       => __( 'Title', 'ideapark-moderno' ),
				'type'        => Controls_Manager::TEXTAREA,
				'placeholder' => __( 'Enter your title', 'ideapark-moderno' ),
				'default'     => __( 'Add Your Text Here', 'ideapark-moderno' ),
			]
		);

		$this->add_control(
			'link',
			[
				'label'     => __( 'Link', 'ideapark-moderno' ),
				'type'      => Controls_Manager::URL,
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_settings',
			[
				'label' => __( 'Circle Text Settings', 'ideapark-moderno' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'size',
			[
				'label'      => __( 'Size', 'ideapark-moderno' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'default'    => [
					'size' => 150,
				],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 1160,
					],
				],
			]
		);

		$this->add_control(
			'circle_animation',
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
						'max' => 100,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .c-ip-circle-text__svg' => 'animation-duration: {{SIZE}}s',
				],
			]
		);

		$this->add_control(
			'alignment',
			[
				'label'     => __( 'Alignment', 'ideapark-moderno' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => [
					'flex-start' => [
						'title' => __( 'Left', 'ideapark-moderno' ),
						'icon'  => 'eicon-text-align-left',
					],
					'center'     => [
						'title' => __( 'Center', 'ideapark-moderno' ),
						'icon'  => 'eicon-text-align-center',
					],
					'flex-end'   => [
						'title' => __( 'Right', 'ideapark-moderno' ),
						'icon'  => 'eicon-text-align-right',
					],
				],
				'default'   => 'center',
				'selectors' => [
					'{{WRAPPER}} .c-ip-circle-text' => 'justify-content: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'title_color',
			[
				'label'     => __( 'Text Color', 'ideapark-moderno' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'selectors' => [
					'{{WRAPPER}} .c-ip-circle-text__svg' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'background_color',
			[
				'label'     => __( 'Background Color', 'ideapark-moderno' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'selectors' => [
					'{{WRAPPER}} .c-ip-circle-text__svg' => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'padding',
			[
				'label'      => __( 'Padding', 'ideapark-moderno' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'default'    => [
					'size' => 0,
				],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 50,
					],
				],
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_title_style',
			[
				'label' => __( 'Typography', 'ideapark-moderno' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$kit = Plugin::$instance->kits_manager->get_active_kit_for_frontend();

		/**
		 * Retrieve the settings directly from DB, because of an open issue when a controls group is being initialized
		 * from within another group
		 */
		$kit_settings = $kit->get_meta( PageManager::META_KEY );

		$default_fonts = isset( $kit_settings['default_generic_fonts'] ) ? $kit_settings['default_generic_fonts'] : 'Sans-serif';

		if ( $default_fonts ) {
			$default_fonts = ', ' . $default_fonts;
		}

		$this->add_control(
			'font_family',
			[
				'label'     => esc_html__( 'Family', 'ideapark-moderno' ),
				'type'      => Controls_Manager::FONT,
				'default'   => '',
				'selectors' => [
					'{{WRAPPER}} .c-ip-circle-text__title' => 'font-family: "{{VALUE}}"' . $default_fonts . ';',
				],

			]
		);

		$this->add_control(
			'font_size',
			[
				'label'      => esc_html__( 'Size', 'ideapark-moderno' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'rem', 'vw', 'custom' ],
				'range'      => [
					'px' => [
						'min' => 1,
						'max' => 200,
					],
					'vw' => [
						'min'  => 0.1,
						'max'  => 10,
						'step' => 0.1,
					],
				],

			]
		);

		$this->add_control(
			'font_weight',
			[
				'label'     => esc_html__( 'Weight', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '',
				'options'   => [
					'100'    => '100 ' . esc_html__( '(Thin)', 'ideapark-moderno' ),
					'200'    => '200 ' . esc_html__( '(Extra Light)', 'ideapark-moderno' ),
					'300'    => '300 ' . esc_html__( '(Light)', 'ideapark-moderno' ),
					'400'    => '400 ' . esc_html__( '(Normal)', 'ideapark-moderno' ),
					'500'    => '500 ' . esc_html__( '(Medium)', 'ideapark-moderno' ),
					'600'    => '600 ' . esc_html__( '(Semi Bold)', 'ideapark-moderno' ),
					'700'    => '700 ' . esc_html__( '(Bold)', 'ideapark-moderno' ),
					'800'    => '800 ' . esc_html__( '(Extra Bold)', 'ideapark-moderno' ),
					'900'    => '900 ' . esc_html__( '(Black)', 'ideapark-moderno' ),
					''       => esc_html__( 'Default', 'ideapark-moderno' ),
					'normal' => esc_html__( 'Normal', 'ideapark-moderno' ),
					'bold'   => esc_html__( 'Bold', 'ideapark-moderno' ),
				],
				'selectors' => [
					'{{WRAPPER}} .c-ip-circle-text__title' => 'font-weight: {{VALUE}}',
				],

			]
		);

		$this->add_control(
			'text_transform',
			[
				'label'     => esc_html__( 'Transform', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '',
				'options'   => [
					''           => esc_html__( 'Default', 'ideapark-moderno' ),
					'uppercase'  => esc_html__( 'Uppercase', 'ideapark-moderno' ),
					'lowercase'  => esc_html__( 'Lowercase', 'ideapark-moderno' ),
					'capitalize' => esc_html__( 'Capitalize', 'ideapark-moderno' ),
					'none'       => esc_html__( 'Normal', 'ideapark-moderno' ),
				],
				'selectors' => [
					'{{WRAPPER}} .c-ip-circle-text__title' => 'text-transform: {{VALUE}}',
				],

			]
		);

		$this->add_control(
			'font_style',
			[
				'label'     => esc_html__( 'Style', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '',
				'options'   => [
					''        => esc_html__( 'Default', 'ideapark-moderno' ),
					'normal'  => esc_html__( 'Normal', 'ideapark-moderno' ),
					'italic'  => esc_html__( 'Italic', 'ideapark-moderno' ),
					'oblique' => esc_html__( 'Oblique', 'ideapark-moderno' ),
				],
				'selectors' => [
					'{{WRAPPER}} .c-ip-circle-text__title' => 'font-style: {{VALUE}}',
				],

			]
		);

		$this->add_control(
			'letter_spacing',
			[
				'label'      => esc_html__( 'Letter Spacing', 'ideapark-moderno' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'custom' ],
				'range'      => [
					'px' => [
						'min'  => - 5,
						'max'  => 10,
						'step' => 0.1,
					],
					'em' => [
						'step' => 0.1,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .c-ip-circle-text__title' => 'letter-spacing: {{SIZE}}{{UNIT}}',
				],

			]
		);


		$this->add_control(
			'word_spacing',
			[
				'label'      => esc_html__( 'Word Spacing', 'ideapark-moderno' ),
				'type'       => Controls_Manager::SLIDER,
				'default'    => [
					'unit' => 'em',
				],
				'size_units' => [ 'px', 'em', 'custom' ],
				'range'      => [
					'px' => [
						'step' => 1,
					],
					'em' => [
						'step' => 0.1,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .c-ip-circle-text__title' => 'word-spacing: {{SIZE}}{{UNIT}}',
				],

			]
		);

		$this->end_controls_section();
	}

	/**
	 * Render circle text widget output on the frontend.
	 *
	 * Written in PHP and used to generate the final HTML.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function render() {

		$settings = $this->get_settings_for_display();

		if ( '' === $settings['title'] ) {
			return;
		}

		$this->add_render_attribute( 'title', 'class', 'c-ip-circle-text' );

		$size = abs( $settings['size']['size'] );

		$font_size = ( ! empty( $settings['font_size']['size'] ) ? $settings['font_size']['size'] : 16 );

		$padding = ( ! empty( $settings['padding']['size'] ) ? $settings['padding']['size'] : 0 );

		$path_radius = ( $size - $font_size * 1.7 - $padding ) / 2;

		$title = '
<svg viewBox="0 0 ' . $size . ' ' . $size . '" width="' . $size . '" height="' . $size . '" class="c-ip-circle-text__svg">
  <defs>
    <path id="circle-'.$this->get_id().'"
      d="
        M ' . ( $size / 2 ) . ', ' . ( $size / 2 ) . '
        m -' . $path_radius . ', 0
        a ' . $path_radius . ',' . $path_radius . ' 0 1,1 ' . ( $path_radius * 2 ) . ',0
        a ' . $path_radius . ',' . $path_radius . ' 0 1,1 -' . ( $path_radius * 2 ) . ',0"/>
  </defs>
  <text font-size="' . $font_size . '" class="c-ip-circle-text__title">
    <textPath xlink:href="#circle-' . $this->get_id() . '">
	     ' . esc_html( $settings['title'] ) . '
    </textPath>
  </text>
</svg>
';

		if ( ! empty( $settings['link']['url'] ) ) {
			$this->add_link_attributes( 'url', $settings['link'] );

			$title = sprintf( '<a %1$s>%2$s</a>', $this->get_render_attribute_string( 'url' ), $title );
		}

		$title_html = sprintf( '<div %1$s>%2$s</div>', $this->get_render_attribute_string( 'title' ), $title );

		echo $title_html;
	}

	/**
	 * Render circle text widget output in the editor.
	 *
	 * Written as a Backbone JavaScript template and used to generate the live preview.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function content_template() {

	}
}