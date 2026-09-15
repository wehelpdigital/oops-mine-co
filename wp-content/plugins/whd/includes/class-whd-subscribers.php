<?php
/**
 * Newsletter subscribers: captured from the theme's newsletter form (hook
 * `omc_newsletter_subscribed`) or the plugin's own AJAX endpoint, stored in a
 * table, optionally greeted with the "welcome_subscriber" email, exportable
 * as CSV.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Subscribers {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'whd_subscribers';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(190) NOT NULL,
			name varchar(120) NOT NULL DEFAULT '',
			source varchar(60) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'subscribed',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email)
		) " . $wpdb->get_charset_collate() . ';' );
	}

	public static function init() {
		add_action( 'omc_newsletter_subscribed', [ __CLASS__, 'on_theme_subscribe' ] );
		add_action( 'wp_ajax_whd_subscribe', [ __CLASS__, 'ajax' ] );
		add_action( 'wp_ajax_nopriv_whd_subscribe', [ __CLASS__, 'ajax' ] );
		add_action( 'admin_post_whd_export_subscribers', [ __CLASS__, 'export' ] );
	}

	public static function on_theme_subscribe( $email ) {
		self::add( $email, '', 'website' );
	}

	public static function ajax() {
		check_ajax_referer( 'whd_subscribe', 'nonce' );
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'Please enter a valid email address.', 'whd' ) ], 400 );
		}
		self::add( $email, isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '', 'popup' );
		wp_send_json_success( [ 'message' => __( 'You’re on the list.', 'whd' ) ] );
	}

	/** Insert (idempotent) and send the welcome email for brand-new addresses. */
	public static function add( $email, $name = '', $source = '' ) {
		global $wpdb;
		$email = strtolower( sanitize_email( $email ) );
		if ( ! is_email( $email ) ) {
			return false;
		}
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE email = %s', $email ) );
		if ( $exists ) {
			return (int) $exists;
		}
		$wpdb->insert( self::table(), [ 'email' => $email, 'name' => $name, 'source' => $source, 'status' => 'subscribed', 'created_at' => current_time( 'mysql' ) ] );
		$id = (int) $wpdb->insert_id;
		if ( class_exists( 'WHD_Emails' ) ) {
			$ctx = WHD_Emails::context( [ 'data' => [ 'email' => $email, 'first_name' => $name ?: __( 'there', 'whd' ), 'customer_name' => $name, 'unsubscribe_url' => class_exists( 'WHD_Cart' ) ? WHD_Cart::unsubscribe_url( $email ) : '' ] ] );
			WHD_Emails::send_custom( 'welcome_subscriber', $ctx, $email );
		}
		return $id;
	}

	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() );
	}

	public static function recent( $limit = 100 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY created_at DESC LIMIT %d', $limit ) );
	}

	public static function export() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'whd_export' ) ) {
			wp_die( 'Not allowed' );
		}
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT email, name, source, status, created_at FROM ' . self::table() . ' ORDER BY created_at DESC', ARRAY_A );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=subscribers-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'email', 'name', 'source', 'status', 'subscribed_at' ] );
		foreach ( $rows as $r ) {
			fputcsv( $out, $r );
		}
		fclose( $out );
		exit;
	}
}
