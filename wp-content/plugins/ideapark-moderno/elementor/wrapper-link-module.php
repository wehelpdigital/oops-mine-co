<?php

namespace Ideapark;

use Elementor\Controls_Manager;

class WrapperLinks {
	private static $_instance = null;

	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	private function __construct() {
		add_action( 'elementor/element/before_section_start', [ $this, 'add_fields' ], 10, 3 );
		add_action( 'elementor/frontend/element/before_render', [ $this, 'before_section_render' ], 10, 1 );

		add_action( 'elementor/frontend/section/before_render', [ $this, 'before_section_render' ], 10, 1 );
		add_action( 'elementor/frontend/column/before_render', [ $this, 'before_section_render' ], 10, 1 );

	}

	public function add_fields( $element, $section_id, $args ) {

		if ( ( 'section' === $element->get_name() && 'section_background' === $section_id ) || ( 'column' === $element->get_name() && 'section_style' === $section_id ) ) {

			$element->start_controls_section(
				'wrapper_link_section',
				[
					'tab'   => Controls_Manager::TAB_STYLE,
					'label' => __( 'Wrapper Link', 'ideapark-moderno' ),
				]
			);

			$element->add_control(
				'enable_wrapper_link',
				[
					'label'        => __( 'Enable Link', 'ideapark-moderno' ),
					'type'         => Controls_Manager::SWITCHER,
					'default'      => '',
					'label_on'     => __( 'Yes', 'ideapark-moderno' ),
					'label_off'    => __( 'No', 'ideapark-moderno' ),
					'return_value' => 'yes',
					'prefix_class' => 'h-link-',
				]
			);

			$element->add_control(
				'wrapper_link',
				[
					'label'         => __( 'Link', 'ideapark-moderno' ),
					'type'          => Controls_Manager::URL,
					'label_block'   => true,
					'show_external' => false,
					'dynamic'       => [
						'active' => true,
					],
					'placeholder'   => __( 'https://your-link.com', 'ideapark-moderno' ),
					'condition'     => [
						'enable_wrapper_link' => 'yes',
					]
				]
			);

			$element->add_control(
				'enable_wrapper_open_in_new_window',
				[
					'label'        => __( 'Enable Open In New Window', 'ideapark-moderno' ),
					'type'         => Controls_Manager::SWITCHER,
					'default'      => '',
					'label_on'     => __( 'Yes', 'ideapark-moderno' ),
					'label_off'    => __( 'No', 'ideapark-moderno' ),
					'return_value' => 'yes',
					'condition'    => [
						'enable_wrapper_link' => 'yes'
					]
				]
			);

			$element->end_controls_section();
		}
	}

	function before_section_render( $element ) {
		if ( $element->get_settings( 'enable_wrapper_link' ) == 'yes' ) {
			$settings = $element->get_settings_for_display();
			$link     = $settings['wrapper_link'];

			$element->add_render_attribute( '_wrapper', [
				'data-ip-url'        => $link['url'],
				'data-ip-link'       => $element->get_settings( 'enable_wrapper_link' ),
				'data-ip-new-window' => $settings['enable_wrapper_open_in_new_window'],
			] );

		}
	}
}