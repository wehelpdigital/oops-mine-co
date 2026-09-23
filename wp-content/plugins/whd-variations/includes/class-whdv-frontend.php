<?php
/**
 * Front end: progressive, one-level-at-a-time variation picking on top of WooCommerce's variation form
 * (and the theme's / Variation Swatches plugin's swatches). No markup changes — a small script drives the
 * native selects, so add-to-cart, price and stock updates stay WooCommerce's own.
 */

defined( 'ABSPATH' ) || exit;

final class WHDV_Frontend {

	public static function init() {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
	}

	public static function assets() {
		if ( is_admin() ) {
			return;
		}
		// Variation forms also appear in quick-view modals on archives and the home page, so load site-wide (both files are tiny).
		wp_enqueue_style( 'whdv-front', WHDV_URL . 'assets/front.css', [], WHDV_VERSION );
		wp_enqueue_script( 'whdv-front', WHDV_URL . 'assets/front.js', [ 'jquery' ], WHDV_VERSION, true );
		wp_localize_script( 'whdv-front', 'WHDV_FRONT', [
			'settings' => WHDV_Plugin::settings(),
			'i18n'     => [
				/* translators: %s: attribute label */
				'choose' => __( 'Choose %s', 'whd-variations' ),
			],
		] );
	}
}
