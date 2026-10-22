<?php
/**
 * Drop reminders: "Remind me" on a countdown to new arrivals, and the email when the drop opens.
 *
 * The theme owns the drop itself — its date, name, photo and link are set in the Page Editor and
 * handed over through the `whd_drop` filter as [ key, when (timestamp), title, url, image ]. Anyone
 * who signs up from the drop card arrives here with source `drop` (the theme's form, or any WHD form
 * whose source says so) and gets a row. When the drop's time has passed, every waiting row is sent
 * the `drop_open` design, and marked sent.
 *
 * One row per address. A second sign-up for a later drop puts the same row back to waiting, so the
 * person who asked about the March drop and then the May one hears about both, and somebody who only
 * asked once is not written to again for every drop after it.
 *
 * Sending runs on its own event at the drop's time, with the fifteen-minute sweep behind it — WP-Cron
 * only fires on a page view, and nobody should miss an opening because the shop was quiet that minute.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Reminders {

	const DB_VERSION = '1';
	const SOURCE     = 'drop';
	const TOPIC      = 'drop';
	const TRIGGER    = 'drop_open';
	const CRON       = 'whd_reminders_cron';

	/** Sent per run, so a big list never holds one request for minutes. */
	const BATCH = 40;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'whd_reminders';
	}

	public static function init() {
		add_action( 'admin_init', [ __CLASS__, 'maybe_install' ] );
		add_action( 'whd_subscriber_added', [ __CLASS__, 'on_signup' ], 20, 4 );
		add_action( self::CRON, [ __CLASS__, 'run' ] );
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(190) NOT NULL,
			name varchar(120) NOT NULL DEFAULT '',
			topic varchar(60) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'waiting',
			created datetime NOT NULL,
			sent_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email_topic (email, topic),
			KEY status (status)
		) " . $wpdb->get_charset_collate() . ';' );
		update_option( 'whd_reminders_db', self::DB_VERSION, false );
	}

	public static function maybe_install() {
		if ( get_option( 'whd_reminders_db' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/** The drop the theme is counting down to, or null: [ key, when, title, url, image ]. */
	public static function drop(): ?array {
		$drop = apply_filters( 'whd_drop', null );
		if ( ! is_array( $drop ) || empty( $drop['when'] ) ) {
			return null;
		}
		return [
			'key'   => (string) ( $drop['key'] ?? '' ),
			'when'  => (int) $drop['when'],
			'title' => (string) ( $drop['title'] ?? '' ),
			'url'   => (string) ( $drop['url'] ?? home_url( '/' ) ),
			'image' => (string) ( $drop['image'] ?? '' ),
		];
	}

	/** A sign-up from the drop card: keep the address for the opening. */
	public static function on_signup( $id, $email, $name = '', $source = '' ) {
		if ( self::SOURCE !== $source ) {
			return;
		}
		self::maybe_install();
		global $wpdb;
		$email = strtolower( (string) $email );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status FROM ' . self::table() . ' WHERE email = %s AND topic = %s', $email, self::TOPIC ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $row ) {
			if ( 'waiting' !== $row->status ) {
				$wpdb->update( self::table(), [ 'status' => 'waiting', 'sent_at' => null, 'name' => (string) $name ], [ 'id' => (int) $row->id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		} else {
			$wpdb->insert( self::table(), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'email'   => $email,
				'name'    => (string) $name,
				'topic'   => self::TOPIC,
				'status'  => 'waiting',
				'created' => current_time( 'mysql' ),
			] );
		}
		self::schedule();
	}

	/** One event at the moment the drop opens (a minute after, so the page already says it is open). */
	public static function schedule() {
		$drop = self::drop();
		if ( ! $drop ) {
			return;
		}
		$at = max( time() + 30, $drop['when'] + MINUTE_IN_SECONDS );
		if ( ! wp_next_scheduled( self::CRON, [ 'open' ] ) ) {
			wp_schedule_single_event( $at, self::CRON, [ 'open' ] );
		}
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 600, 'whd_15min', self::CRON );
		}
	}

	/** Send to everyone waiting once the drop has opened. */
	public static function run( $why = '' ) {
		$drop = self::drop();
		if ( ! $drop || $drop['when'] > time() ) {
			return;
		}
		self::maybe_install();
		global $wpdb;
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE topic = %s AND status = 'waiting' ORDER BY id ASC LIMIT %d", self::TOPIC, self::BATCH ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$unsub = array_map( 'strtolower', (array) get_option( 'whd_unsubscribed', [] ) );
		foreach ( $rows as $row ) {
			if ( in_array( strtolower( $row->email ), $unsub, true ) ) {
				$wpdb->update( self::table(), [ 'status' => 'unsubscribed' ], [ 'id' => (int) $row->id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				continue;
			}
			$ok = self::send( $row, $drop );
			$wpdb->update( self::table(), [ 'status' => $ok ? 'sent' : 'failed', 'sent_at' => current_time( 'mysql' ) ], [ 'id' => (int) $row->id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		// More than one batch: come back in a minute rather than waiting for the sweep.
		if ( count( $rows ) === self::BATCH && ! wp_next_scheduled( self::CRON, [ 'more' ] ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON, [ 'more' ] );
		}
	}

	private static function send( $row, array $drop ): bool {
		$ctx = WHD_Emails::context( [
			'data' => [
				'email'           => $row->email,
				'first_name'      => $row->name ?: __( 'there', 'whd' ),
				'customer_name'   => $row->name,
				'drop_title'      => $drop['title'] ?: __( 'The new drop', 'whd' ),
				'drop_url'        => $drop['url'],
				'drop_image'      => $drop['image'],
				'unsubscribe_url' => class_exists( 'WHD_Cart' ) ? WHD_Cart::unsubscribe_url( $row->email ) : '',
			],
		] );
		return (bool) WHD_Emails::send_custom( self::TRIGGER, $ctx, $row->email );
	}

	/** [ waiting, sent, failed ] for the overview. */
	public static function counts(): array {
		global $wpdb;
		self::maybe_install();
		$out = [ 'waiting' => 0, 'sent' => 0, 'failed' => 0 ];
		foreach ( (array) $wpdb->get_results( 'SELECT status, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY status' ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$out[ $r->status ] = (int) $r->n;
		}
		return $out;
	}
}
