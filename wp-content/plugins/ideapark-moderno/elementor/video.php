<?php

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}


/**
 * Elementor video widget.
 *
 * Elementor widget that displays an video from over 600+ videos.
 *
 * @since 1.0.0
 */
class Ideapark_Elementor_Video extends Widget_Base {

	/**
	 * Get widget name.
	 *
	 * Retrieve video widget name.
	 *
	 * @return string Widget name.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_name() {
		return 'ideapark-video';
	}

	/**
	 * Get widget title.
	 *
	 * Retrieve video widget title.
	 *
	 * @return string Widget title.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_title() {
		return __( 'Video Button', 'ideapark-moderno' );
	}

	/**
	 * Get widget icon.
	 *
	 * Retrieve icon widget icon.
	 *
	 * @return string Widget icon.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_icon() {
		return 'eicon-youtube';
	}

	/**
	 * Get widget categories.
	 *
	 * Retrieve the list of categories the video widget belongs to.
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
		return [ 'video' ];
	}

	/**
	 * Register video widget controls.
	 *
	 * Adds different input fields to allow the user to change and customize the widget settings.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_video',
			[
				'label' => __( 'Video Button', 'ideapark-moderno' ),
			]
		);

		$this->add_control(
			'video_url',
			[
				'label'       => __( 'Video URL', 'ideapark-moderno' ),
				'description' => __( 'Link to Vimeo or YouTube video.', 'ideapark-moderno' ),
				'type'        => Controls_Manager::TEXT,
				'dynamic'     => [
					'active' => true,
				],
				'placeholder' => __( 'https://link-to-the-video.com', 'ideapark-moderno' ),
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_settings',
			[
				'label' => __( 'Video Button Settings', 'ideapark-moderno' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_responsive_control(
			'size',
			[
				'label'      => __( 'Size', 'ideapark-moderno' ),
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
					'{{WRAPPER}} .c-play' => ' --size: {{SIZE}} !important;',
				],
			]
		);

		$this->add_control(
			'color',
			[
				'label'     => __( 'Button Color', 'ideapark-moderno' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'selectors' => [
					'{{WRAPPER}} .c-play' => 'color: {{VALUE}};border-color: {{VALUE}};',
				],
			]
		);

		$this->add_responsive_control(
			'align',
			[
				'label'     => __( 'Alignment', 'ideapark-moderno' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => [
					'left'   => [
						'title' => __( 'Left', 'ideapark-moderno' ),
						'icon'  => 'eicon-text-align-left',
					],
					'center' => [
						'title' => __( 'Center', 'ideapark-moderno' ),
						'icon'  => 'eicon-text-align-center',
					],
					'right'  => [
						'title' => __( 'Right', 'ideapark-moderno' ),
						'icon'  => 'eicon-text-align-right',
					],
				],
				'default'   => 'center',
				'selectors' => [
					'{{WRAPPER}} .c-ip-video' => 'text-align: {{VALUE}};',
				],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Render video widget output on the frontend.
	 *
	 * Written in PHP and used to generate the final HTML.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		if ( ! empty( $settings['video_url'] ) ) { ?>
			<div class="c-ip-video">
				<a class="js-ip-video" data-vbtype="video" data-autoplay="true"
				   target="_blank" onclick="return false;"
				   href="<?php echo esc_url( $settings['video_url'] ); ?>">
					<i class="c-play c-play--large c-ip-video__play"></i>
				</a>
			</div>
		<?php }
	}

	protected function content_template() {

	}
}
