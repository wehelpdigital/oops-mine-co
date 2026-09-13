<?php

use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Repeater;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Elementor Accordion widget.
 *
 * Elementor widget that displays a bullet list with any chosen icons and texts.
 *
 * @since 1.0.0
 */
class Ideapark_Elementor_Accordion extends Widget_Base {

	/**
	 * Get widget name.
	 *
	 * Retrieve Accordion widget name.
	 *
	 * @return string Widget name.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_name() {
		return 'ideapark-accordion';
	}

	/**
	 * Get widget title.
	 *
	 * Retrieve Accordion widget title.
	 *
	 * @return string Widget title.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_title() {
		return __( 'Moderno Accordion', 'ideapark-moderno' );
	}

	/**
	 * Get widget icon.
	 *
	 * Retrieve Accordion widget icon.
	 *
	 * @return string Widget icon.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function get_icon() {
		return 'eicon-accordion';
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
		return [ 'accordion', 'icon', 'list', 'FAQ' ];
	}

	/**
	 * Register Accordion widget controls.
	 *
	 * Adds different input fields to allow the user to change and customize the widget settings.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function register_controls() {

		$this->start_controls_section(
			'section_list',
			[
				'label' => __( 'Accordion Items', 'ideapark-moderno' ),
			]
		);

		$repeater = new Repeater();

		$repeater->add_control(
			'title',
			[
				'label'       => __( 'Title', 'ideapark-moderno' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'placeholder' => __( 'Enter title', 'ideapark-moderno' ),
				'dynamic'     => [
					'active' => true,
				],
			]
		);

		$repeater->add_control(
			'content',
			[
				'label'       => __( 'Content', 'ideapark-moderno' ),
				'type'        => \Elementor\Controls_Manager::WYSIWYG,
				'label_block' => true
			]
		);

		$this->add_control(
			'accordion',
			[
				'label'       => '',
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => [
					[
						'title'   => __( 'Title #1', 'ideapark-moderno' ),
						'content' => __( 'Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit.', 'ideapark-moderno' ),
					],
					[
						'title'   => __( 'Title #2', 'ideapark-moderno' ),
						'content' => __( 'Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit.', 'ideapark-moderno' ),
					],
					[
						'title'   => __( 'Title #3', 'ideapark-moderno' ),
						'content' => __( 'Click edit button to change this text. Lorem ipsum dolor sit amet, consectetur adipiscing elit.', 'ideapark-moderno' ),
					],
				],
				'title_field' => '{{{ title }}}',
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_toggle_style_title',
			[
				'label' => esc_html__( 'Title', 'ideapark-moderno' ),
				'tab' => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name' => 'title_typography',
				'selector' => '{{WRAPPER}} .c-ip-accordion__header',
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_toggle_style_content',
			[
				'label' => esc_html__( 'Content', 'ideapark-moderno' ),
				'tab' => Controls_Manager::TAB_STYLE,
			]
		);
		

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name' => 'content_typography',
				'selector' => '{{WRAPPER}} .c-ip-accordion__content',
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Render Accordion widget output on the frontend.
	 *
	 * Written in PHP and used to generate the final HTML.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		?>
		<div class="c-ip-accordion">
			<div class="c-ip-accordion__wrap">
				<div class="c-ip-accordion__list">
					<?php
					foreach ( $settings['accordion'] as $index => $item ) { ?>
					<div class="c-ip-accordion__item">
						<?php echo ideapark_wrap( esc_html( $item['title'] ), '<a class="c-ip-accordion__link js-accordion-title" href="" onclick="return false;"><div class="c-ip-accordion__header">', '<i class="ip-plus_big c-ip-accordion__arrow"></i></div></a>' ) ?>
						<?php echo ideapark_wrap( do_shortcode( $item['content'] ), '<div class="c-ip-accordion__content entry-content">', '</div>' ); ?>
					</div>
					<?php } ?>
				</div>
			</div>
		</div>
		<?php
		$structured_data = ideapark_mod( 'wc_structured_data' ) ?: [];
		if ( ! array_key_exists( 'FAQPage', $structured_data ) ) {
			$markup               = [];
			$markup['@type']      = 'FAQPage';
			$markup['mainEntity'] = [];
		} else {
			$markup = $structured_data['FAQPage'];
		}

		foreach ( $settings['accordion'] as $item ) {
			$markup['mainEntity'][] = [
				'@type'          => 'Question',
				'name'           => $item['title'],
				"acceptedAnswer" => [
					"@type" => "Answer",
					"text " => $item['content'],
				],
			];
		}

		$structured_data['FAQPage'] = $markup;
		ideapark_mod_set_temp('wc_structured_data', $structured_data );
	}

	/**
	 * Render Accordion widget output in the editor.
	 *
	 * Written as a Backbone JavaScript template and used to generate the live preview.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	protected function content_template() {
	}
}
