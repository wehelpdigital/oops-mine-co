<?php
/**
 * Meta Conversions API: every event the browser pixel reports, sent again from the server.
 *
 * The pixel on its own misses a share of what happens — ad blockers, Safari's tracking prevention, a
 * visitor who leaves before the script has loaded. The server copy fills those gaps. Every event
 * carries one event_id that the browser's fbq() call and the server's copy share, so Meta counts it
 * once however many copies arrive.
 *
 *  settings → WHD → Tracking scripts (`whd_scripts`: fb_capi_token, fb_test_code)
 *  sending  → one batched request per page view, after the visitor's response has gone
 *             (WHD_Plugin::after_response), so nobody waits on Meta
 *  log      → failures and test sends in WHD → Integrations → Last API calls (channel "meta"); the
 *             last time Meta accepted an event is kept in `whd_capi_last_ok` (written at most hourly)
 *
 * What it says about a person is what the shop already holds: the visitor's IP address and browser,
 * Meta's own _fbp/_fbc cookies, and — when an order, an account or a sign-up form supplies them —
 * email, phone, name and address, normalised and SHA-256 hashed the way Meta's spec asks. Only the IP,
 * the browser string and the two cookies go unhashed, because the spec requires them that way.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Capi {

	/** Graph API version: the newest one when this was written (October 2026). Each lasts about two years. */
	const API = 'v26.0';

	/** @var array[] Events waiting for the end of this request. */
	private static $queue = [];

	/** @var string The Lead this request reported, so the form's answer can hand its id to the browser. */
	private static $lead_id = '';

	public static function init() {
		// Lead from the theme's newsletter forms — after WHD_Subscribers (priority 10) has stored the address.
		add_action( 'omc_newsletter_subscribed', [ __CLASS__, 'on_theme_signup' ], 20, 3 );
		add_filter( 'omc_subscribe_response', [ __CLASS__, 'answer_with_event_id' ] );

		/*
		 * Purchase from whichever comes first: the thank-you page (WHD_Scripts::purchase_event calls
		 * purchase() there) or the payment landing. Somebody who pays and closes the tab before the
		 * thank-you page loads is still a sale.
		 */
		add_action( 'woocommerce_payment_complete', [ __CLASS__, 'purchase' ] );
		add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'purchase' ] );
		add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'purchase' ] );

		// Meta's cookies, kept on the order so a payment confirmed later still matches the visitor.
		add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'remember_browser' ] );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ __CLASS__, 'remember_browser' ] );

		add_action( 'admin_post_whd_capi_test', [ __CLASS__, 'handle_test' ] );
	}

	/** Is there a pixel and a token to send with? */
	public static function enabled(): bool {
		$s = WHD_Scripts::get();
		return '' !== (string) $s['fb_pixel_id'] && '' !== (string) $s['fb_capi_token'];
	}

	/** A fresh event_id, e.g. "lead.3f2a…". The browser's call and the server's copy both carry it. */
	public static function new_id( $kind ) {
		return $kind . '.' . str_replace( '-', '', wp_generate_uuid4() );
	}

	/**
	 * Report one event from the server.
	 *
	 * @param string $name   Meta's event name.
	 * @param string $id     The event_id — the same one the browser's fbq() call carries.
	 * @param array  $custom custom_data: value, currency, content_ids…
	 * @param array  $person What this event knows about who did it: email, phone, first_name, last_name,
	 *                       city, state, zip, country, external_id. The signed-in account fills the gaps.
	 */
	public static function track( $name, $id, array $custom = [], array $person = [] ) {
		if ( ! self::enabled() || WHD_Scripts::tracking_off() || self::is_bot() ) {
			return;
		}
		self::queue( [
			'event_name'       => $name,
			'event_time'       => time(),
			'event_id'         => $id,
			'action_source'    => 'website',
			'event_source_url' => self::page_url(),
			'user_data'        => self::user_data( array_merge( self::account(), array_filter( $person ) ), self::browser() ),
			'custom_data'      => $custom,
		] );
	}

	private static function queue( array $event ) {
		if ( empty( $event['custom_data'] ) ) {
			unset( $event['custom_data'] );
		}
		self::$queue[] = $event;
		if ( 1 === count( self::$queue ) ) {
			WHD_Plugin::after_response( [ __CLASS__, 'flush' ] );
		}
	}

	/** Send everything this request queued, in one call. */
	public static function flush( $closed = false ) {
		$events      = self::$queue;
		self::$queue = [];
		if ( $events ) {
			self::send( $events, $closed ? 10 : 3 ); // a visitor still connected gets the short leash
		}
	}

	/**
	 * POST a batch of events to Meta.
	 *
	 * @param array[]     $events    Server events.
	 * @param int         $timeout   Seconds.
	 * @param string|null $test_code Overrides the saved test event code.
	 * @return array [ bool $ok, string $message ]
	 */
	public static function send( array $events, $timeout = 10, $test_code = null ) {
		$s    = WHD_Scripts::get();
		$code = null === $test_code ? (string) $s['fb_test_code'] : (string) $test_code;
		$body = [
			'data'         => wp_json_encode( array_values( $events ) ),
			'access_token' => $s['fb_capi_token'],
		];
		if ( '' !== $code ) {
			$body['test_event_code'] = $code;
		}
		$res   = wp_remote_post(
			'https://graph.facebook.com/' . self::API . '/' . rawurlencode( (string) $s['fb_pixel_id'] ) . '/events',
			[ 'timeout' => $timeout, 'body' => $body ]
		);
		$names = implode( ', ', array_unique( array_column( $events, 'event_name' ) ) );

		if ( is_wp_error( $res ) ) {
			self::log( 0, $names . ' — ' . $res->get_error_message() );
			return [ false, $res->get_error_message() ];
		}
		$status = (int) wp_remote_retrieve_response_code( $res );
		$data   = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $status || empty( $data['events_received'] ) ) {
			$error = $data['error']['error_user_msg'] ?? $data['error']['message'] ?? WHD_Integrations::short_error( wp_remote_retrieve_body( $res ) );
			self::log( $status, $names . ' — ' . $error );
			return [ false, (string) $error ];
		}

		// Meta answers some mistakes with a 200 and a note — a test code with no Test events session open, for one.
		$notes   = isset( $data['messages'] ) ? implode( ' ', array_map( 'strval', (array) $data['messages'] ) ) : '';
		$message = sprintf( '%s — %d received', $names, (int) $data['events_received'] ) . ( '' !== $notes ? '. ' . $notes : '' );
		if ( '' !== $code ) {
			self::log( $status, $message . ' (test code ' . $code . ')' );
		}
		if ( ! get_transient( 'whd_capi_ok' ) ) { // proof of life for the settings screen, at most one write an hour
			set_transient( 'whd_capi_ok', 1, HOUR_IN_SECONDS );
			update_option( 'whd_capi_last_ok', time(), false );
		}
		return [ true, $message ];
	}

	private static function log( $code, $message ) {
		if ( class_exists( 'WHD_Integrations' ) ) {
			WHD_Integrations::log( 'meta', $code, $message );
		}
	}

	/* ─────────────────────────── events that are not a page ─────────────────────────── */

	public static function on_theme_signup( $email, $source = '', $name = '' ) {
		self::lead( $email, (string) $source, (string) $name );
	}

	/**
	 * Somebody left an email address on a sign-up form.
	 *
	 * @return string The event_id, for the form's answer to hand to the browser's copy ('' when Lead is off).
	 */
	public static function lead( $email, $source = '', $name = '' ) {
		if ( ! WHD_Scripts::sends( 'lead' ) ) {
			return '';
		}
		$first         = (string) strtok( trim( (string) $name ), ' ' );
		self::$lead_id = self::new_id( 'lead' );
		self::track(
			'Lead',
			self::$lead_id,
			[ 'content_name' => $source ?: 'newsletter', 'currency' => self::currency() ],
			[ 'email' => $email, 'first_name' => $first ]
		);
		return self::$lead_id;
	}

	/** The theme's sign-up answer carries the Lead's id back, so the browser's Lead is the same event. */
	public static function answer_with_event_id( $data ) {
		if ( self::$lead_id && is_array( $data ) ) {
			$data['event_id'] = self::$lead_id;
		}
		return $data;
	}

	public static function remember_browser( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}
		$b = self::browser();
		foreach ( [ 'fbp', 'fbc' ] as $key ) {
			if ( ! empty( $b[ $key ] ) ) {
				$order->update_meta_data( '_whd_' . $key, $b[ $key ] );
			}
		}
	}

	/**
	 * A sale, sent once per order.
	 *
	 * The event_id is "order.<id>", the same as the thank-you page's browser event, so the two are
	 * counted once even when this one is sent later from the payment callback.
	 */
	public static function purchase( $order_id ) {
		if ( ! self::enabled() || ! WHD_Scripts::sends( 'purchase' ) || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_whd_capi_purchase' ) ) {
			return;
		}
		if ( ! in_array( $order->get_status(), [ 'processing', 'completed', 'on-hold' ], true ) ) {
			return; // not placed yet, or failed
		}
		// An order an administrator placed for themselves is a test, as it is for the pixel.
		$customer = $order->get_customer_id() ? get_userdata( $order->get_customer_id() ) : null;
		if ( WHD_Scripts::get()['exclude_admins'] && $customer && user_can( $customer, 'manage_options' ) ) {
			return;
		}
		$order->update_meta_data( '_whd_capi_purchase', time() );
		$order->save_meta_data();

		$ids      = [];
		$contents = [];
		foreach ( $order->get_items() as $item ) {
			$product    = $item->get_product();
			// The same id the thank-you page's browser event uses: the SKU when there is one.
			$id         = $product ? (string) ( $product->get_sku() ?: $product->get_id() ) : (string) $item->get_product_id();
			$qty        = max( 1, (int) $item->get_quantity() );
			$ids[]      = $id;
			$contents[] = [ 'id' => $id, 'quantity' => $qty, 'item_price' => round( (float) $item->get_total() / $qty, 2 ) ];
		}

		// What the checkout recorded about the visitor; on the thank-you page they are here, so gaps fill from now.
		$browser = array_filter( [
			'client_ip_address' => $order->get_customer_ip_address(),
			'client_user_agent' => $order->get_customer_user_agent(),
			'fbp'               => $order->get_meta( '_whd_fbp' ),
			'fbc'               => $order->get_meta( '_whd_fbc' ),
		] );
		if ( ! is_admin() && ! wp_doing_cron() && ! wp_doing_ajax() ) {
			$browser = array_merge( self::browser(), $browser );
		}

		self::queue( [
			'event_name'       => 'Purchase',
			'event_time'       => time(),
			'event_id'         => 'order.' . $order->get_id(),
			'action_source'    => 'website',
			'event_source_url' => $order->get_checkout_order_received_url(),
			'user_data'        => self::user_data( self::order_person( $order ), $browser ),
			'custom_data'      => [
				'value'        => (float) $order->get_total(),
				'currency'     => $order->get_currency(),
				'content_type' => 'product',
				'content_ids'  => $ids,
				'contents'     => $contents,
				'num_items'    => array_sum( array_column( $contents, 'quantity' ) ),
				'order_id'     => (string) $order->get_order_number(),
			],
		] );
	}

	/** Who placed an order, in the shape user_data() takes. */
	public static function order_person( $order ): array {
		return array_filter( [
			'email'       => $order->get_billing_email(),
			'phone'       => $order->get_billing_phone(),
			'first_name'  => $order->get_billing_first_name(),
			'last_name'   => $order->get_billing_last_name(),
			'city'        => $order->get_billing_city(),
			'state'       => $order->get_billing_state(),
			'zip'         => $order->get_billing_postcode(),
			'country'     => $order->get_billing_country(),
			'external_id' => $order->get_customer_id() ? 'user:' . $order->get_customer_id() : '',
		] );
	}

	/* ─────────────────────────── who and where ─────────────────────────── */

	/** What the signed-in account knows, or the cart session's id for a guest who has one. */
	private static function account(): array {
		if ( ! is_user_logged_in() ) {
			if ( function_exists( 'WC' ) && WC()->session && WC()->session->has_session() && method_exists( WC()->session, 'get_customer_id' ) ) {
				return [ 'external_id' => 'guest:' . WC()->session->get_customer_id() ];
			}
			return [];
		}
		$user = wp_get_current_user();
		$p    = [
			'email'       => $user->user_email,
			'first_name'  => $user->first_name,
			'last_name'   => $user->last_name,
			'external_id' => 'user:' . $user->ID,
		];
		if ( class_exists( 'WC_Customer' ) ) {
			try {
				$c = new WC_Customer( $user->ID );
				$p = array_merge( $p, array_filter( [
					'email'      => $c->get_billing_email(),
					'phone'      => $c->get_billing_phone(),
					'first_name' => $c->get_billing_first_name(),
					'last_name'  => $c->get_billing_last_name(),
					'city'       => $c->get_billing_city(),
					'state'      => $c->get_billing_state(),
					'zip'        => $c->get_billing_postcode(),
					'country'    => $c->get_billing_country(),
				] ) );
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// no customer record: the user account is enough
			}
		}
		return array_filter( $p );
	}

	/** The visitor's connection and Meta's cookies — the parts the spec wants unhashed. */
	private static function browser(): array {
		$d  = [];
		$ip = class_exists( 'WC_Geolocation' ) ? WC_Geolocation::get_ip_address() : sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		if ( $ip ) {
			$d['client_ip_address'] = $ip;
		}
		$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
		if ( '' !== $ua ) {
			$d['client_user_agent'] = substr( $ua, 0, 512 );
		}
		$fbp = sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ?? '' ) );
		if ( preg_match( '/^fb\.\d\.\d+\.\d+$/', $fbp ) ) {
			$d['fbp'] = $fbp;
		}
		$fbc = sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ?? '' ) );
		if ( '' === $fbc ) {
			// First landing from an ad: the pixel has not written _fbc yet, but the click id is in the URL.
			$url   = self::page_url();
			$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
			parse_str( $query, $args );
			if ( ! empty( $args['fbclid'] ) ) {
				$fbc = 'fb.1.' . (int) round( microtime( true ) * 1000 ) . '.' . sanitize_text_field( (string) $args['fbclid'] );
			}
		}
		if ( 0 === strpos( $fbc, 'fb.' ) ) {
			$d['fbc'] = $fbc;
		}
		return $d;
	}

	/** The page the event happened on. An AJAX call happens on the page that made it. */
	private static function page_url(): string {
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			$ref = wp_get_raw_referer();
			return $ref ? esc_url_raw( $ref ) : home_url( '/' );
		}
		$host = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) );
		$path = wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed through esc_url_raw
		return $host ? esc_url_raw( ( is_ssl() ? 'https://' : 'http://' ) . $host . $path ) : home_url( '/' );
	}

	/** Crawlers and uptime checks are not visitors. */
	private static function is_bot(): bool {
		$ua = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return '' === $ua || (bool) preg_match( '/bot|crawl|spider|slurp|facebookexternalhit|bingpreview|lighthouse|headless|wget|curl\/|python-|uptime/i', $ua );
	}

	private static function currency() {
		return function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
	}

	/* ─────────────────────────── Meta's hashing rules ─────────────────────────── */

	/**
	 * Build user_data: the browser part as it is, everything about the person normalised and hashed.
	 *
	 * Meta matches on the hash, so "Ana@Example.com " and "ana@example.com" have to come out the same —
	 * which is what the normalising is for.
	 */
	private static function user_data( array $p, array $browser ): array {
		$country = strtolower( substr( preg_replace( '/[^a-z]/i', '', (string) ( $p['country'] ?? '' ) ), 0, 2 ) );
		$plain   = [
			'em'          => is_email( trim( (string) ( $p['email'] ?? '' ) ) ) ? strtolower( trim( $p['email'] ) ) : '',
			'ph'          => self::phone( (string) ( $p['phone'] ?? '' ), $country ),
			'fn'          => self::letters( $p['first_name'] ?? '' ),
			'ln'          => self::letters( $p['last_name'] ?? '' ),
			'ct'          => self::letters( $p['city'] ?? '' ),
			'st'          => strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) ( $p['state'] ?? '' ) ) ),
			'zp'          => strtolower( preg_replace( '/[\s-]+/', '', (string) ( $p['zip'] ?? '' ) ) ),
			'country'     => $country,
			'external_id' => (string) ( $p['external_id'] ?? '' ),
		];
		$d = $browser;
		foreach ( $plain as $key => $value ) {
			if ( '' !== $value ) {
				$d[ $key ] = hash( 'sha256', $value );
			}
		}
		return $d;
	}

	/** Lowercase letters only, accents kept: "Mary-Ann " → "maryann". */
	private static function letters( $value ) {
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $value, 'UTF-8' ) : strtolower( (string) $value );
		return (string) preg_replace( '/[^\p{L}\p{M}]+/u', '', $value );
	}

	/** Digits with the country code and no leading zero: "0917 123 4567" in PH → "639171234567". */
	private static function phone( $raw, $country ) {
		$digits = preg_replace( '/\D+/', '', $raw );
		if ( '' === $digits ) {
			return '';
		}
		if ( 0 === strpos( ltrim( $raw ), '+' ) ) {
			return $digits; // already international
		}
		if ( 0 === strpos( $digits, '00' ) ) {
			return ltrim( $digits, '0' ); // 00 = international prefix
		}
		$code = '';
		if ( $country && function_exists( 'WC' ) && WC()->countries ) {
			$code = WC()->countries->get_country_calling_code( strtoupper( $country ) );
			$code = preg_replace( '/\D+/', '', (string) ( is_array( $code ) ? reset( $code ) : $code ) );
		}
		$digits = ltrim( $digits, '0' );
		return ( $code && 0 !== strpos( $digits, $code ) ) ? $code . $digits : $digits;
	}

	/* ─────────────────────────── settings screen ─────────────────────────── */

	/**
	 * "Send a test event" on the Tracking screen.
	 *
	 * It refuses to run without a test event code: without one Meta files the event as a real visit,
	 * and a stranger's PageView from the admin's own browser is not something to put in the shop's data.
	 */
	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'whd_capi_test' ) ) {
			wp_die( esc_html__( 'Not allowed', 'whd' ) );
		}
		$s = WHD_Scripts::get();
		if ( ! self::enabled() ) {
			self::notice( false, __( 'Save the pixel ID and the access token first.', 'whd' ) );
		} elseif ( '' === (string) $s['fb_test_code'] ) {
			self::notice( false, __( 'Paste the code from Events Manager → Test events into “Test event code” and save first, so the test is not counted as a real visit.', 'whd' ) );
		} else {
			list( $ok, $message ) = self::send( [ [
				'event_name'       => 'PageView',
				'event_time'       => time(),
				'event_id'         => self::new_id( 'test' ),
				'action_source'    => 'website',
				'event_source_url' => home_url( '/' ),
				'user_data'        => self::user_data( [], self::browser() ),
			] ] );
			self::notice( $ok, $ok
				/* translators: %s: Meta's answer */
				? sprintf( __( 'Meta accepted the test event (%s). It shows under Test events in Events Manager within a minute.', 'whd' ), $message )
				/* translators: %s: Meta's error */
				: sprintf( __( 'Meta refused the test event: %s', 'whd' ), $message ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=whd-scripts' ) );
		exit;
	}

	private static function notice( $ok, $message ) {
		set_transient( 'whd_capi_notice_' . get_current_user_id(), [ 'ok' => (bool) $ok, 'message' => (string) $message ], 2 * MINUTE_IN_SECONDS );
	}

	/** The notice from the last test, once. */
	public static function take_notice() {
		$key    = 'whd_capi_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) ) {
			delete_transient( $key );
			return $notice;
		}
		return null;
	}
}
