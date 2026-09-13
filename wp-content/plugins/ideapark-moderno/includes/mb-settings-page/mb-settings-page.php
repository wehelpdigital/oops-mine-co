<?php
/**
 * Plugin Name: MB Settings Page
 * Plugin URI:  https://metabox.io/plugins/mb-settings-page/
 * Description: Add-on for meta box plugin which helps you create settings pages easily.
 * Version:     2.1.3
 * Author:      MetaBox.io
 * Author URI:  https://metabox.io
 * License:     GPL2+
 * Text Domain: mb-settings-page
 * Domain Path: /languages/
 *
 * @package    Meta Box
 * @subpackage MB Settings Page
 */

// Prevent loading this file directly.
defined( 'ABSPATH' ) || die;

if ( ! function_exists( 'mb_settings_page_load' ) ) {

	/**
	 * Hook to 'init' with priority 5 to make sure all actions are registered before Meta Box 4.9.0 runs
	 */
	add_action( 'init', 'mb_settings_page_load', 5 );

	/**
	 * Load plugin files after Meta Box is loaded
	 */
	function mb_settings_page_load() {
		if ( ! defined( 'RWMB_VER' ) ) {
			return;
		}

		list( , $url ) = \RWMB_Loader::get_path( __DIR__ );
		define( 'MBSP_URL', $url );

		spl_autoload_register( function ( $class_name ) {
			if ( preg_match( '~^MBSP(.+)$~', $class_name, $match ) ) {
				$fn = __DIR__ . '/src' . str_replace( '\\', '/', $match[1] ) . '.php';
				require_once( $fn );
			}
		} );

		new MBSP\Loader;
		new MBSP\Customizer\Manager;
	}
}