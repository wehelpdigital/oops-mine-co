<?php

namespace Ideapark;

use Elementor\Controls_Manager;

class MaxWidth {
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
		$is_section = false;
		$is_column = false;
		if ( ( $is_section = ( 'section' === $element->get_name() && 'section_background' === $section_id ) ) || ( $is_column = ( 'column' === $element->get_name() && 'section_style' === $section_id ) ) ) {

			if ( $is_column ) {
				$element->start_controls_section(
					'max_width_section',
					[
						'tab'   => Controls_Manager::TAB_STYLE,
						'label' => __( 'Max Width', 'ideapark-moderno' ),
					]
				);

				$element->add_responsive_control(
					'max_width',
					[
						'label'      => __( 'Max width of the block', 'ideapark-moderno' ),
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
							'{{WRAPPER}}' => 'max-width: {{SIZE}}{{UNIT}};',//margin-left:auto;margin-right:auto
						],
					]
				);

				$element->end_controls_section();
			}

			if ( $is_section ) {

				$element->start_controls_section(
					'columns_align_section',
					[
						'tab'   => Controls_Manager::TAB_STYLE,
						'label' => __( 'Columns Align', 'ideapark-moderno' ),
					]
				);

				$element->add_responsive_control(
					'max_width_block_align',
					[
						'label'     => __( 'Column alignment', 'ideapark-moderno' ),
						'type'      => \Elementor\Controls_Manager::CHOOSE,
						'options'   => [
							'flex-start'    => [
								'title' => __( 'Left', 'ideapark-moderno' ),
								'icon'  => 'eicon-text-align-left',
							],
							'center'        => [
								'title' => __( 'Center', 'ideapark-moderno' ),
								'icon'  => 'eicon-text-align-center',
							],
							'flex-end'      => [
								'title' => __( 'Right', 'ideapark-moderno' ),
								'icon'  => 'eicon-text-align-right',
							],
							'space-between' => [
								'title' => __( 'Space between', 'ideapark-moderno' ),
								'icon'  => 'eicon-text-align-justify',
							],
						],
						'default'   => '',
						'selectors' => [
							'{{WRAPPER}} > .elementor-container > .elementor-row' => 'justify-content: {{VALUE}};',
							'{{WRAPPER}} > .elementor-container' => 'justify-content: {{VALUE}};',
						],
					]
				);

				$element->end_controls_section();
			}
		}
	}

	function before_section_render( $element ) {
		if ( $element->get_settings( 'enable_max_width' ) == 'yes' ) {
			$settings  = $element->get_settings_for_display();
			$max_width = $settings['max_width'];
		}
	}
}