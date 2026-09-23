<?php
/**
 * Plugin Name: WHD — Variation Tiers
 * Plugin URI:  https://wehelpdigital.com
 * Description: Multi-level variations for WooCommerce products. Define tiers (e.g. Colour → Size → Length), fill one price/stock/SKU grid, and the plugin creates and keeps the real WooCommerce variations in sync. On the product page shoppers pick one level at a time and only see the combinations that exist.
 * Version:     1.0.0
 * Author:      We Help Digital
 * Text Domain: whd-variations
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'WHDV_VERSION', '1.0.0' );
define( 'WHDV_FILE', __FILE__ );
define( 'WHDV_DIR', plugin_dir_path( __FILE__ ) );
define( 'WHDV_URL', plugin_dir_url( __FILE__ ) );
define( 'WHDV_MAX_LEVELS', 3 );

require_once WHDV_DIR . 'includes/class-whdv-model.php';
require_once WHDV_DIR . 'includes/class-whdv-admin.php';
require_once WHDV_DIR . 'includes/class-whdv-frontend.php';

final class WHDV_Plugin {

	/** Global settings with defaults (option `whdv_settings`). */
	public static function settings() {
		return wp_parse_args( get_option( 'whdv_settings', [] ), [
			'progressive'      => 1, // reveal one level at a time on the product page
			'hide_unavailable' => 1, // hide (instead of greying out) options that don't exist for the chosen higher level
			'auto_select'      => 1, // when a level has a single remaining option, pick it
			'step_labels'      => 1, // "1 · Colour", "2 · Size" numerals + the chosen value next to the label
		] );
	}

	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', function () {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'WHD — Variation Tiers needs WooCommerce to be active.', 'whd-variations' ) . '</p></div>';
			} );
			return;
		}
		load_plugin_textdomain( 'whd-variations', false, dirname( plugin_basename( WHDV_FILE ) ) . '/languages' );
		WHDV_Admin::init();
		WHDV_Frontend::init();
	}
}

add_action( 'plugins_loaded', [ 'WHDV_Plugin', 'init' ] );

// HPOS / product block editor compatibility declarations (we only touch products, not orders).
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WHDV_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'product_block_editor', WHDV_FILE, false );
	}
} );
