<?php
/**
 * Elementor emoji one area control.
 *
 * A control for displaying a textarea with the ability to add emojis.
 *
 * @since 1.0.0
 */
class Ideapark_Hotspot_Control extends \Elementor\Base_Data_Control {

	/**
	 * Get emoji one area control type.
	 *
	 * Retrieve the control type, in this case `hotspot`.
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @return string Control type.
	 */
	public function get_type() {
		return 'ideapark-hotspot';
	}

	/**
	 * Enqueue emoji one area control scripts and styles.
	 *
	 * Used to register and enqueue custom scripts and styles used by the emoji one
	 * area control.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function enqueue() {

		$assets_url  = esc_url( plugins_url( '/../assets/', __FILE__ ) );
		
		wp_register_style( 'ideapark-hotspot', $assets_url . 'css/hotspot.css', [], IDEAPARK_MODERNO_FUNC_VERSION );
		wp_enqueue_style( 'ideapark-hotspot' );

		wp_register_script( 'ideapark-hotspot', $assets_url . 'js/hotspot.js', [ 'jquery' ], IDEAPARK_MODERNO_FUNC_VERSION );
		wp_enqueue_script( 'ideapark-hotspot' );
	}

	/**
	 * Get emoji one area control default settings.
	 *
	 * Retrieve the default settings of the emoji one area control. Used to return
	 * the default settings while initializing the emoji one area control.
	 *
	 * @since 1.0.0
	 * @access protected
	 *
	 * @return array Control default settings.
	 */
	protected function get_default_settings() {
		return [
			'label_block' => true,
			'rows' => 3,
			'hotspot_options' => [],
		];
	}

	/**
	 * Render emoji one area control output in the editor.
	 *
	 * Used to generate the control HTML in the editor using Underscore JS
	 * template. The variables for the class are available using `data` JS
	 * object.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function content_template() {
		$control_uid = $this->get_control_uid();
		?>
		<div class="elementor-control-field">
			<label for="<?php echo $control_uid; ?>" class="elementor-control-title"></label>
			<div class="elementor-control-input-wrapper">
				<input type="hidden" id="<?php echo $control_uid; ?>" class="elementor-control-tag-area js-ideapark-hotspot-data" data-setting="{{ data.name }}" >
				<button type="button" data-control="{{ data.image }}" class="elementor-button elementor-button-default js-ideapark-edit-hotspot">{{{ data.label }}}</button>
			</div>
		</div>
		<# if ( data.description ) { #>
		<div class="elementor-control-field-description">{{{ data.description }}}</div>
		<# } #>

		<?php
	}

}
