<?php
/**
 * Plugin Name: WHD — Popups, Emails & Tracking
 * Plugin URI:  https://wehelpdigital.com
 * Description: Marketing toolkit for Oops, Mine Co.: exit-intent and welcome popups with a drag-and-drop editor and cookie-based countdowns, a drag-and-drop email builder for every WooCommerce trigger (including abandoned-cart recovery), a newsletter list with phone capture, integrations that push subscribers to Mailchimp, Klaviyo or a webhook and send SMS through Twilio, a tracking-scripts module (GA4, Search Console, Meta Pixel), size charts, product stories, and an AI copywriter that writes against your own brief, rules and keyword list.
 * Version:     1.5.1
 * Author:      We Help Digital
 * Text Domain: whd
 * Requires at least: 6.4
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'WHD_VERSION', '1.5.1' );
define( 'WHD_FILE', __FILE__ );
define( 'WHD_DIR', plugin_dir_path( __FILE__ ) );
define( 'WHD_URL', plugin_dir_url( __FILE__ ) );

require_once WHD_DIR . 'includes/class-whd-blocks.php';
require_once WHD_DIR . 'includes/class-whd-popups.php';
require_once WHD_DIR . 'includes/class-whd-emails.php';
require_once WHD_DIR . 'includes/class-whd-cart.php';
require_once WHD_DIR . 'includes/class-whd-scripts.php';
require_once WHD_DIR . 'includes/class-whd-subscribers.php';
require_once WHD_DIR . 'includes/class-whd-integrations.php';
require_once WHD_DIR . 'includes/class-whd-size-charts.php';
require_once WHD_DIR . 'includes/class-whd-product-story.php';
require_once WHD_DIR . 'includes/class-whd-story-comments.php';
require_once WHD_DIR . 'includes/class-whd-stock-alerts.php';
require_once WHD_DIR . 'includes/class-whd-stylist.php';
require_once WHD_DIR . 'includes/class-whd-ai.php';
require_once WHD_DIR . 'includes/class-whd-ai-keywords.php';
require_once WHD_DIR . 'includes/class-whd-ai-admin.php';
require_once WHD_DIR . 'includes/class-whd-product-admin.php';
require_once WHD_DIR . 'includes/class-whd-admin.php';

final class WHD_Plugin {

	/** Global plugin settings with defaults. */
	public static function settings() {
		return wp_parse_args( get_option( 'whd_settings', [] ), [
			'exclude_admins'          => 1,        // don't show popups / tracking to logged-in admins
			'cart_delay_hours'        => 1,        // abandoned-cart email delay
			'cart_coupon'             => '',       // coupon merged into {coupon_code}
			'exit_coupon'             => 'STAY15', // code offered by the exit-intent popup
			'free_shipping_threshold' => 0,        // cart progress bar target; 0 hides it. No default promise.
			'from_name'               => get_bloginfo( 'name' ),
			'from_email'              => get_option( 'admin_email' ),
		] );
	}

	public static function init() {
		/*
		 * Both of these on `init`, not here. This runs on plugins_loaded, and since WordPress 6.7
		 * a translation used before `init` is a doing-it-wrong notice — which the upgrade routine
		 * triggers, because the default email designs it seeds are written with __().
		 */
		add_action( 'init', [ __CLASS__, 'load_textdomain' ], 1 );
		add_action( 'init', [ __CLASS__, 'maybe_upgrade' ], 5 );

		WHD_Scripts::init();
		WHD_Popups::init();
		WHD_Subscribers::init();
		WHD_Integrations::init();
		WHD_Size_Charts::init();
		WHD_Product_Story::init();
		WHD_Story_Comments::init();
		if ( class_exists( 'WooCommerce' ) ) {
			WHD_Stock_Alerts::init();
			WHD_Stylist::init();
		}
		WHD_Emails::init();
		if ( class_exists( 'WooCommerce' ) ) {
			WHD_Cart::init();
		}
		WHD_Admin::init();
		WHD_AI_Admin::init();
		if ( class_exists( 'WooCommerce' ) ) {
			WHD_Product_Admin::init();
		}
	}

	public static function load_textdomain() {
		load_plugin_textdomain( 'whd', false, dirname( plugin_basename( WHD_FILE ) ) . '/languages' );
	}

	/**
	 * Catch an install up after an update.
	 *
	 * register_activation_hook does not fire when a plugin is updated in place, so anything added
	 * to activate() only ever reached installs that were switched on afterwards. One option
	 * comparison per request, and the work itself only when the version has moved.
	 *
	 * Everything it calls is safe to repeat: dbDelta alters rather than replaces, and the default
	 * designs only fill in triggers that are missing, so an edited email is never overwritten.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'whd_version' ) === WHD_VERSION ) {
			return;
		}
		self::activate();
		update_option( 'whd_version', WHD_VERSION, false );
	}

	public static function activate() {
		WHD_Cart::install();
		WHD_Subscribers::install();
		WHD_AI_Keywords::install();
		if ( class_exists( 'WHD_Stock_Alerts' ) ) {
			WHD_Stock_Alerts::install();
		}
		if ( class_exists( 'WHD_Stylist' ) ) {
			WHD_Stylist::install();
		}
		if ( class_exists( 'WHD_Size_Charts' ) ) {
			WHD_Size_Charts::maybe_seed();
		}
		WHD_Popups::ensure_defaults();
		WHD_Emails::ensure_defaults();
		if ( ! wp_next_scheduled( 'whd_cart_cron' ) ) {
			wp_schedule_event( time() + 300, 'whd_15min', 'whd_cart_cron' );
		}
		if ( class_exists( 'WHD_Stock_Alerts' ) && ! wp_next_scheduled( WHD_Stock_Alerts::CRON ) ) {
			wp_schedule_event( time() + 360, 'whd_15min', WHD_Stock_Alerts::CRON );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'whd_cart_cron' );
		if ( class_exists( 'WHD_Stock_Alerts' ) ) {
			wp_clear_scheduled_hook( WHD_Stock_Alerts::CRON );
			wp_clear_scheduled_hook( WHD_Stock_Alerts::CRON_SOON );
		}
		wp_unschedule_hook( 'whd_sync_subscriber' ); // per-subscriber events carry an argument, so clear the whole hook
	}
}

add_filter( 'cron_schedules', function ( $schedules ) {
	$schedules['whd_15min'] = [ 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => __( 'Every 15 minutes (WHD)', 'whd' ) ];
	return $schedules;
} );

register_activation_hook( WHD_FILE, [ 'WHD_Plugin', 'activate' ] );
register_deactivation_hook( WHD_FILE, [ 'WHD_Plugin', 'deactivate' ] );
add_action( 'plugins_loaded', [ 'WHD_Plugin', 'init' ] );
