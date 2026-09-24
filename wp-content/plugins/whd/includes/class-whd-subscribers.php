<?php
/**
 * Newsletter subscribers: captured from the theme's newsletter form (hook
 * `omc_newsletter_subscribed`) or the plugin's own AJAX endpoint, stored in a
 * table with their phone number and SMS consent, optionally greeted with the
 * "welcome_subscriber" email, pushed to the email platform set up in WHD →
 * Integrations, exportable as CSV.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Subscribers {

	/** Bump when the table changes; `whd_db_version` upgrades old installs on init. */
	const DB_VERSION = 2;

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
			phone varchar(32) NOT NULL DEFAULT '',
			sms_consent tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			synced_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email)
		) " . $wpdb->get_charset_collate() . ';' );
		update_option( 'whd_db_version', self::DB_VERSION, false );
	}

	/** Existing installs get the newer columns without being deactivated first. */
	public static function maybe_upgrade() {
		if ( (int) get_option( 'whd_db_version', 0 ) >= self::DB_VERSION ) {
			return;
		}
		self::install();
	}

	public static function init() {
		add_action( 'init', [ __CLASS__, 'maybe_upgrade' ], 5 );
		add_action( 'omc_newsletter_subscribed', [ __CLASS__, 'on_theme_subscribe' ], 10, 2 );
		add_action( 'wp_ajax_whd_subscribe', [ __CLASS__, 'ajax' ] );
		add_action( 'wp_ajax_nopriv_whd_subscribe', [ __CLASS__, 'ajax' ] );
		add_action( 'admin_post_whd_export_subscribers', [ __CLASS__, 'export' ] );
	}

	public static function on_theme_subscribe( $email, $source = 'website' ) {
		self::add( $email, '', $source ? sanitize_key( $source ) : 'website' );
	}

	/**
	 * Sign-up endpoint for the popup and any other form on the site.
	 * Accepts email (required), name, phone, sms_consent and source.
	 *
	 * The answer carries `message` both at the top level and inside `data`, so a
	 * script reading either shape shows the right words.
	 */
	public static function ajax() {
		check_ajax_referer( 'whd_subscribe', 'nonce' );
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			self::answer( false, __( 'Please enter a valid email address.', 'whd' ), 400 );
		}
		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$phone   = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$source  = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$consent = ! empty( $_POST['sms_consent'] ) && 'false' !== $_POST['sms_consent'] && '0' !== (string) $_POST['sms_consent'] ? 1 : 0;
		$id      = self::add( $email, $name, $source ?: 'popup', $phone, $consent );
		if ( ! $id ) {
			self::answer( false, __( 'That address could not be saved. Please try again.', 'whd' ), 400 );
		}
		self::answer( true, __( 'You’re on the list.', 'whd' ) );
	}

	private static function answer( $ok, $message, $status = 200 ) {
		wp_send_json( [ 'success' => (bool) $ok, 'data' => [ 'message' => $message ], 'message' => $message ], $status );
	}

	/**
	 * Insert or top up one subscriber.
	 *
	 * A brand-new address is inserted and greeted with the welcome email; a
	 * known address keeps its row and picks up a phone number, SMS consent or
	 * name it did not have. Either way `whd_subscriber_added` fires so the
	 * integrations layer can push the row to the email platform.
	 *
	 * @return int|false Row id, or false when the address is unusable.
	 */
	public static function add( $email, $name = '', $source = '', $phone = '', $sms_consent = 0 ) {
		global $wpdb;
		$email = strtolower( sanitize_email( $email ) );
		if ( ! is_email( $email ) ) {
			return false;
		}
		$name    = sanitize_text_field( $name );
		$source  = $source ? sanitize_key( $source ) : '';
		$phone   = self::phone( $phone );
		$consent = ( $sms_consent && '' !== $phone ) ? 1 : 0;
		$row     = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE email = %s', $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( $row ) {
			$id     = (int) $row->id;
			$update = [];
			if ( '' !== $phone && $phone !== (string) $row->phone ) {
				$update['phone'] = $phone;
			}
			if ( $consent && ! (int) $row->sms_consent ) {
				$update['sms_consent'] = 1;
			}
			if ( '' !== $name && '' === (string) $row->name ) {
				$update['name'] = $name;
			}
			if ( $update ) {
				$update['synced_at'] = null; // new details → send it to the platform again
				$wpdb->update( self::table(), $update, [ 'id' => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			if ( $update || empty( $row->synced_at ) ) {
				do_action( 'whd_subscriber_added', $id, $email, $name ?: (string) $row->name, $source ?: (string) $row->source, $phone ?: (string) $row->phone, $consent ?: (int) $row->sms_consent );
			}
			return $id;
		}

		$wpdb->insert( self::table(), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'email'       => $email,
			'name'        => $name,
			'source'      => $source,
			'status'      => 'subscribed',
			'phone'       => $phone,
			'sms_consent' => $consent,
			'created_at'  => current_time( 'mysql' ),
		] );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return false;
		}
		if ( class_exists( 'WHD_Emails' ) ) {
			$ctx = WHD_Emails::context( [ 'data' => [ 'email' => $email, 'first_name' => $name ?: __( 'there', 'whd' ), 'customer_name' => $name, 'unsubscribe_url' => class_exists( 'WHD_Cart' ) ? WHD_Cart::unsubscribe_url( $email ) : '' ] ] );
			WHD_Emails::send_custom( 'welcome_subscriber', $ctx, $email );
		}
		do_action( 'whd_subscriber_added', $id, $email, $name, $source, $phone, $consent );
		return $id;
	}

	/** Store numbers in one shape (E.164) so the SMS gateway accepts them. */
	public static function phone( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( class_exists( 'WHD_Integrations' ) ) {
			return WHD_Integrations::normalize_phone( $raw );
		}
		$digits = preg_replace( '/\D+/', '', $raw );
		if ( '' === $digits ) {
			return '';
		}
		if ( 10 === strlen( $digits ) ) {
			return '+1' . $digits;
		}
		return '+' . substr( $digits, 0, 15 );
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function mark_synced( $id ) {
		global $wpdb;
		return (bool) $wpdb->update( self::table(), [ 'synced_at' => current_time( 'mysql' ) ], [ 'id' => (int) $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** How many rows carry a usable mobile number with consent. */
	public static function sms_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE sms_consent = 1 AND phone <> ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function recent( $limit = 100 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY created_at DESC LIMIT %d', $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function export() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'whd_export' ) ) {
			wp_die( esc_html__( 'Not allowed', 'whd' ) );
		}
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT email, name, phone, sms_consent, source, status, created_at, synced_at FROM ' . self::table() . ' ORDER BY created_at DESC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=subscribers-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'email', 'name', 'phone', 'sms_consent', 'source', 'status', 'subscribed_at', 'synced_at' ] );
		foreach ( $rows as $r ) {
			fputcsv( $out, $r );
		}
		fclose( $out );
		exit;
	}
}
