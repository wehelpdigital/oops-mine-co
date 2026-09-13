<?php

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Control_Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class Ideapark_Elementor_Instagram extends Widget_Base {
	/**
	 * Retrieve the widget name.
	 */
	public function get_name() {
		return 'ideapark-instagram';
	}

	/**
	 * Retrieve the widget title.
	 */
	public function get_title() {
		return esc_html__( 'Instagram Photo Gallery', 'ideapark-moderno' );
	}

	/**
	 * Retrieve the widget icon.
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
	 * Register the widget controls.
	 *
	 * Adds different input fields to allow the user to change and customize the widget settings.
	 */
	protected function register_controls() {

		$this->start_controls_section(
			'section_instagram',
			[
				'label' => __( 'Image gallery', 'ideapark-moderno' ),
			]
		);

		$this->add_control(
			'title',
			[
				'label'       => __( 'Account Title', 'ideapark-moderno' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'placeholder' => __( 'Enter title', 'ideapark-moderno' ),
			]
		);


		$this->add_control(
			'link',
			[
				'label'       => __( 'Account Link', 'ideapark-moderno' ),
				'type'        => Controls_Manager::URL,
				'label_block' => true,
				'placeholder' => __( 'https://your-link.com', 'ideapark-moderno' ),
			]
		);

		$this->add_control(
			'random_sorting',
			[
				'label'        => __( 'Random sorting', 'ideapark-moderno' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'no',
				'label_on'     => __( 'Yes', 'ideapark-moderno' ),
				'label_off'    => __( 'No', 'ideapark-moderno' ),
				'return_value' => 'yes',
			]
		);

		$this->add_control(
			'gallery',
			[
				'label'      => __( 'Add Images', 'ideapark-moderno' ),
				'type'       => Controls_Manager::GALLERY,
				'show_label' => false,
				'dynamic'    => [
					'active' => true,
				],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Render the widget output on the frontend.
	 *
	 * Written in PHP and used to generate the final HTML.
	 */
	protected function render() {
		$settings = $this->get_settings();
		if ( $settings['random_sorting'] == 'yes' && is_array( $settings['gallery'] ) ) {
			shuffle( $settings['gallery'] );
		}
		$ids = wp_list_pluck( $settings['gallery'], 'id' );
		if ( $ids ) {
			$content = '';
			$count   = sizeof( $ids );
			if ( $count > 6 ) {
				$count = 6;
			}
			$classes = [
				1 => [ 'left-1' ],
				2 => [ 'left-1', 'right-1' ],
				3 => [ 'left-2', 'left-1', 'right-1' ],
				4 => [ 'left-2', 'left-1', 'right-1', 'right-2' ],
				5 => [ 'left-3', 'left-2', 'left-1', 'right-1', 'right-2' ],
				6 => [ 'left-3', 'left-2', 'left-1', 'right-1', 'right-2', 'right-3' ],
			];
			foreach ( $ids as $index => $id ) {
				if ( $index > 5 ) {
					break;
				}
				if ( $image_meta = ideapark_image_meta( $id, 'medium' ) ) {
					$content .= '<div class="c-ip-instagram__item c-ip-instagram__item--' . esc_attr( $classes[ $count ][ $index ] ) . '"><div class="c-ip-instagram__item_wrap">' . ideapark_img( $image_meta ) . '</div></div>';
				}
				?>
			<?php }
			if ( $settings['title'] ) {
				$info = '<div class="c-ip-instagram__info"><div class="c-ip-instagram__title-wrap"><i class="ip-instagram c-ip-instagram__logo"></i><div class="c-ip-instagram__title">' . esc_html( $settings['title'] ) . '</div></div><div class="c-ip-instagram__button">' . esc_html__( 'Follow us', 'ideapark-moderno' ) . '</div></div>';
				if ( ! empty( $settings['link']['url'] ) ) {
					$link_key = 'link';
					$this->add_link_attributes( $link_key, $settings['link'] );
					$info = ideapark_wrap( $info, '<a ' . $this->get_render_attribute_string( $link_key ) . '>', '</a>' );
				}
			} else {
				$info = '';
			}
			echo ideapark_wrap( $content, '<div class="c-ip-instagram js-instagram"><div class="c-ip-instagram__wrap">', '</div>' . $info . '</div>' );
		}
	}

	/**
	 * Render the widget output in the editor.
	 *
	 * Written as a Backbone JavaScript template and used to generate the live preview.
	 */
	protected function content_template() {

	}
}
