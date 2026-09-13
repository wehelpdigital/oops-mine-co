<?php

use Elementor\Control_Media;
use Elementor\Group_Control_Image_Size;
use Elementor\Icons_Manager;
use Elementor\Utils;
use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Repeater;


if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Elementor reviews widget.
 *
 * Elementor widget that displays a bullet list with any chosen icons and texts.
 *
 * @since 1.0.0
 */
class Ideapark_Elementor_Reviews extends Widget_Base {

	/**
	 * Get widget name.
	 *
	 * Retrieve reviews widget name.
	 *
	 * @return string Widget name.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_name() {
		return 'ideapark-reviews';
	}

	/**
	 * Get widget title.
	 *
	 * Retrieve reviews widget title.
	 *
	 * @return string Widget title.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_title() {
		return __( 'Reviews Carousel', 'ideapark-moderno' );
	}

	/**
	 * Get widget icon.
	 *
	 * Retrieve reviews widget icon.
	 *
	 * @return string Widget icon.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_icon() {
		return 'ip-pr-carousel';
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
		return [ 'carousel', 'reviews', 'reviews' ];
	}

	/**
	 * Register reviews widget controls.
	 *
	 * Adds different input fields to allow the user to change and customize the widget settings.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_reviews',
			[
				'label' => __( 'Reviews', 'ideapark-moderno' ),
			]
		);

		$repeater = new Repeater();

		$repeater->add_control(
			'image',
			[
				'label'   => __( 'Avatar', 'ideapark-moderno' ),
				'type'    => Controls_Manager::MEDIA,
				'default' => [
					'url' => Utils::get_placeholder_image_src(),
				],

			]
		);

		$repeater->add_control(
			'reviewer_name',
			[
				'label'       => __( 'Reviewer name', 'ideapark-moderno' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => __( 'Enter name', 'ideapark-moderno' ),
				'label_block' => true,
			]
		);

		$repeater->add_control(
			'reviewer_text',
			[
				'label'       => __( 'Review text', 'ideapark-moderno' ),
				'type'        => Controls_Manager::TEXTAREA,
				'default'     => __( 'Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit.', 'ideapark-moderno' ),
				'placeholder' => __( 'Enter review text', 'ideapark-moderno' ),
				'separator'   => 'none',
				'rows'        => 5,
				'show_label'  => false,
			]
		);

		$this->add_control(
			'review_list',
			[
				'label'       => '',
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => [
					[
						'reviewer_name' => __( 'Name #1', 'ideapark-moderno' ),
						'reviewer_text' => __( 'Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit.', 'ideapark-moderno' ),
					],
					[
						'reviewer_name' => __( 'Name #2', 'ideapark-moderno' ),
						'reviewer_text' => __( 'Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit.', 'ideapark-moderno' ),
					],
					[
						'reviewer_name' => __( 'Name #3', 'ideapark-moderno' ),
						'reviewer_text' => __( 'Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit.', 'ideapark-moderno' ),
					],
				],
				'title_field' => '{{{ reviewer_name }}}',
			]
		);


		$this->end_controls_section();

		$this->start_controls_section(
			'section_reviews_settings',
			[
				'label' => __( 'Reviews Settings', 'ideapark-moderno' ),
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

		$this->add_control(
			'arrows',
			[
				'label'     => __( 'Navigation arrows', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'yes',
				'label_on'  => __( 'Show', 'ideapark-moderno' ),
				'label_off' => __( 'Hide', 'ideapark-moderno' ),
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
			'autoplay',
			[
				'label'     => __( 'Autoplay', 'ideapark-moderno' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'yes',
				'label_on'  => __( 'Yes', 'ideapark-moderno' ),
				'label_off' => __( 'No', 'ideapark-moderno' ),
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
				],
			]
		);

		$this->add_control(
			'background_color',
			[
				'label'     => __( 'Item background color', 'ideapark-moderno' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'selectors' => [
					'{{WRAPPER}} .c-ip-reviews__wrap' => 'background-color: {{VALUE}};',
				]
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Render reviews widget output on the frontend.
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
			class="c-ip-reviews c-ip-reviews--<?php echo esc_attr( $settings['layout'] ); ?>">

			<div
				class="c-ip-reviews__list-wrap c-ip-reviews__list-wrap--<?php echo esc_attr( $settings['layout'] ); ?>">
				<div
					data-layout="<?php echo esc_attr( $settings['layout'] ); ?>"
					data-autoplay="<?php echo esc_attr( $settings['autoplay'] ); ?>"
					<?php if ( ! empty( $settings['animation_timeout']['size'] ) ) { ?>
						data-animation-timeout="<?php echo esc_attr( abs( $settings['animation_timeout']['size'] * 1000 ) ); ?>"
					<?php } ?>
					class="c-ip-reviews__list c-ip-reviews__list--<?php echo esc_attr( $settings['layout'] ); ?> js-reviews-carousel h-carousel h-carousel--flex <?php if ( $settings['dots'] != 'yes' ) { ?> h-carousel--dots-hide<?php } else { ?> c-ip-reviews__list--dots h-carousel--default-dots<?php } ?> <?php if ( $settings['arrows'] != 'yes' ) { ?> h-carousel--nav-hide<?php } else { ?> h-carousel--round<?php } ?>">
					<?php foreach ( $settings['review_list'] as $index => $item ) { ?>
						<?php
						$avatar = '';
						if ( ! empty( $item['image']['id'] ) && ( $type = get_post_mime_type( $item['image']['id'] ) ) ) {
							if ( $type == 'image/svg+xml' ) {
								$avatar = ideapark_get_inline_svg( $item['image']['id'], 'c-ip-reviews__svg' );
							} else {
								$avatar = ideapark_img( ideapark_image_meta( $item['image']['id'], 'thumbnail', '110px' ), 'c-ip-reviews__image' );
							}
						}
						?>
						<div
							class="c-ip-reviews__item c-ip-reviews__item--<?php echo esc_attr( $settings['layout'] ); ?>">
							<div
								class="c-ip-reviews__wrap c-ip-reviews__wrap--<?php echo esc_attr( $settings['layout'] ); ?>">
								<?php echo ideapark_wrap( $avatar, '<div class="c-ip-reviews__thumb">', '</div>' ); ?>
								<div class="c-ip-reviews__content">
									<?php echo ideapark_wrap( $item['reviewer_text'], '<div class="c-ip-reviews__text">', '</div>' ); ?>
									<?php echo ideapark_wrap( $item['reviewer_name'], '<div class="c-ip-reviews__name">', '</div>' ); ?>
								</div>
							</div>
						</div>
					<?php } ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render reviews widget output in the editor.
	 *
	 * Written as a Backbone JavaScript template and used to generate the live preview.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function content_template() {
	}
}
