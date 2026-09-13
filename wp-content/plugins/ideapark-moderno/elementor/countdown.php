<?php

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Elementor countdown widget.
 *
 * Elementor widget that displays an eye-catching headlines.
 *
 * @since 1.0.0
 */
class Ideapark_Elementor_Countdown extends Widget_Base {

	/**
	 * Get widget name.
	 *
	 * Retrieve countdown widget name.
	 *
	 * @return string Widget name.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_name() {
		return 'ideapark-countdown';
	}

	/**
	 * Get widget title.
	 *
	 * Retrieve countdown widget title.
	 *
	 * @return string Widget title.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_title() {
		return __( 'Moderno Countdown', 'ideapark-moderno' );
	}

	/**
	 * Get widget icon.
	 *
	 * Retrieve countdown widget icon.
	 *
	 * @return string Widget icon.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_icon() {
		return 'eicon-countdown';
	}

	/**
	 * Get widget categories.
	 *
	 * Retrieve the list of categories the countdown widget belongs to.
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
		return [ 'countdown', 'maintenance', 'coming soon' ];
	}

	/**
	 * Register countdown widget controls.
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
				'label' => __( 'Countdown', 'ideapark-moderno' ),
			]
		);

		$this->add_control(
			'date',
			[
				'label'          => __( 'Event date', 'ideapark-moderno' ),
				'type'           => Controls_Manager::DATE_TIME,
				'picker_options' => [ 'enableTime' => true ],
			]
		);

		$this->add_control(
			'weeks',
			[
				'label'        => __( 'Show weeks', 'ideapark-moderno' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'ideapark-moderno' ),
				'label_off'    => __( 'Hide', 'ideapark-moderno' ),
				'return_value' => 'yes',
				'default'      => 'no',
			]
		);

		$this->add_control(
			'months',
			[
				'label'        => __( 'Show months', 'ideapark-moderno' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'ideapark-moderno' ),
				'label_off'    => __( 'Hide', 'ideapark-moderno' ),
				'return_value' => 'yes',
				'default'      => 'no',
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_settings',
			[
				'label' => __( 'Widget Settings', 'ideapark-moderno' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'text_color',
			[
				'label'     => __( 'Text Color', 'ideapark-moderno' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .c-ip-countdown' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_responsive_control(
			'space',
			[
				'label'      => __( 'Margins', 'ideapark-moderno' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'default'    => [
					'size' => 25,
				],
				'range'      => [
					'px' => [
						'min' => 5,
						'max' => 30,
					]
				],
				'devices'    => [ 'desktop', 'tablet', 'mobile' ],

				'selectors' => [
					'{{WRAPPER}} .c-ip-countdown__item' => 'margin-left: {{SIZE}}{{UNIT}}; margin-right: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .c-ip-countdown__wrap' => 'margin-left: -{{SIZE}}{{UNIT}}; margin-right: -{{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'align',
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
					'{{WRAPPER}} .c-ip-countdown' => 'justify-content: {{VALUE}};',
				],
			]
		);


		$this->end_controls_section();

	}

	/**
	 * Render countdown widget output on the frontend.
	 *
	 * Written in PHP and used to generate the final HTML.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function render() {

		$settings = $this->get_settings_for_display();

		if ( '' === $settings['date'] ) {
			return;
		}
		$settings['date'] = apply_filters( 'ideapark_countdown_date', $settings['date'] );

		$start      = new DateTime();
		$end        = new DateTime( $settings['date'] );
		$interval   = $start->diff( $end );//->format( '%a' )
		$days       = $interval->format( '%a' );
		$has_weeks  = $days >= 7 && $settings['weeks'] == 'yes';
		$has_days   = $interval->d > 0;
		$has_months = $interval->m > 0 && $settings['months'] == 'yes';
		?>
		<script>
			var
				ideapark_countdown_months = '<?php echo esc_attr__( 'Months', 'ideapark-moderno' ); ?>',
				ideapark_countdown_weeks = '<?php echo esc_attr__( 'Weeks', 'ideapark-moderno' ); ?>',
				ideapark_countdown_days = '<?php echo esc_attr__( 'Days', 'ideapark-moderno' ); ?>',
				ideapark_countdown_hours = '<?php echo esc_attr__( 'Hours', 'ideapark-moderno' ); ?>',
				ideapark_countdown_minutes = '<?php echo esc_attr__( 'Minutes', 'ideapark-moderno' ); ?>',
				ideapark_countdown_seconds = '<?php echo esc_attr__( 'Seconds', 'ideapark-moderno' ); ?>';
		</script>
		<div class="c-ip-countdown">
			<div
				class="c-ip-countdown__wrap js-countdown"
				data-date="<?php echo esc_attr( $settings['date'] ); ?>"
				<?php if ( ! $has_months ) { ?>data-month="no"<?php } ?>
				<?php if ( ! $has_weeks ) { ?>data-week="no"<?php } ?>
				<?php if ( ! $has_days ) { ?>data-day="no"<?php } ?>>
			</div>
		</div>
		<?php
	}

	/**
	 * Render countdown widget output in the editor.
	 *
	 * Written as a Backbone JavaScript template and used to generate the live preview.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function content_template() {
	}
}
