<?php
/**
 * Marketing integrations: push new subscribers to an email platform
 * (Mailchimp, Klaviyo or a plain webhook for Zapier/Make) and send SMS through
 * Twilio.
 *
 *  settings → WHD → Integrations, stored in the option `whd_integrations`
 *  sync     → `whd_subscriber_added` schedules a one-off cron event so the
 *             visitor's own request never waits for a third-party API
 *  log      → the last 20 API results live in `whd_integrations_log`
 *
 * Keys are kept in the options table as plain text, exactly like every other
 * plugin setting here: WordPress has no secret store, so anyone with database
 * or administrator access can read them. Rotate a key at the provider if you
 * think it leaked.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Integrations {

	const OPTION      = 'whd_integrations';
	const LOG_OPTION  = 'whd_integrations_log';
	const LOG_MAX     = 20;
	const SYNC_HOOK   = 'whd_sync_subscriber';
	const KLAVIYO_REV = '2024-10-15';

	/** Field names that hold a credential (password input, blank = keep). */
	public static function secret_fields() {
		return [ 'mailchimp_api_key', 'klaviyo_api_key', 'twilio_token' ];
	}

	public static function defaults() {
		return [
			'email_provider'         => 'none',
			'mailchimp_api_key'      => '',
			'mailchimp_list_id'      => '',
			'mailchimp_double_optin' => 0,
			'mailchimp_tags'         => '',
			'klaviyo_api_key'        => '',
			'klaviyo_list_id'        => '',
			'webhook_url'            => '',
			'sms_provider'           => 'none',
			'twilio_sid'             => '',
			'twilio_token'           => '',
			'twilio_from'            => '',
			'sms_consent_text'       => '',
		];
	}

	public static function get() {
		return wp_parse_args( get_option( self::OPTION, [] ), self::defaults() );
	}

	public static function email_providers() {
		return [
			'none'      => __( 'None — keep subscribers in WordPress only', 'whd' ),
			'mailchimp' => __( 'Mailchimp', 'whd' ),
			'klaviyo'   => __( 'Klaviyo', 'whd' ),
			'webhook'   => __( 'Webhook (Zapier, Make, anything else)', 'whd' ),
		];
	}

	public static function sms_providers() {
		return [
			'none'   => __( 'None — no text messages', 'whd' ),
			'twilio' => __( 'Twilio', 'whd' ),
		];
	}

	public static function init() {
		add_action( 'whd_subscriber_added', [ __CLASS__, 'on_subscriber_added' ], 10, 6 );
		add_action( self::SYNC_HOOK, [ __CLASS__, 'sync_subscriber' ] );
		add_action( 'admin_post_whd_test_email_provider', [ __CLASS__, 'handle_test_email' ] );
		add_action( 'admin_post_whd_test_sms', [ __CLASS__, 'handle_test_sms' ] );
	}

	/* ─────────────────────────── settings ─────────────────────────── */

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : [];
		$old   = self::get();
		$out   = self::defaults();
		$clear = isset( $input['clear'] ) && is_array( $input['clear'] ) ? $input['clear'] : [];

		$email_provider           = sanitize_key( $input['email_provider'] ?? 'none' );
		$out['email_provider']    = isset( self::email_providers()[ $email_provider ] ) ? $email_provider : 'none';
		$sms_provider             = sanitize_key( $input['sms_provider'] ?? 'none' );
		$out['sms_provider']      = isset( self::sms_providers()[ $sms_provider ] ) ? $sms_provider : 'none';

		foreach ( self::secret_fields() as $key ) {
			$out[ $key ] = empty( $clear[ $key ] ) ? self::keep_secret( $input[ $key ] ?? '', $old[ $key ] ) : '';
		}

		$out['mailchimp_list_id']      = sanitize_text_field( $input['mailchimp_list_id'] ?? '' );
		$out['mailchimp_double_optin'] = empty( $input['mailchimp_double_optin'] ) ? 0 : 1;
		$out['mailchimp_tags']         = implode( ', ', array_filter( array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', (string) ( $input['mailchimp_tags'] ?? '' ) ) ) ) ) );
		$out['klaviyo_list_id']        = sanitize_text_field( $input['klaviyo_list_id'] ?? '' );
		$out['webhook_url']            = esc_url_raw( trim( (string) ( $input['webhook_url'] ?? '' ) ), [ 'https', 'http' ] );
		$out['twilio_sid']             = preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $input['twilio_sid'] ?? '' ) );
		$out['twilio_from']            = sanitize_text_field( $input['twilio_from'] ?? '' );
		$out['sms_consent_text']       = sanitize_textarea_field( $input['sms_consent_text'] ?? '' );

		return $out;
	}

	/** A blank password box means "keep what is stored". */
	private static function keep_secret( $new, $old ) {
		$new = trim( (string) $new );
		return '' === $new ? (string) $old : sanitize_text_field( $new );
	}

	/** Consent sentence shown next to phone fields on the site. */
	public static function consent_text(): string {
		$o    = self::get();
		$text = trim( (string) $o['sms_consent_text'] );
		return (string) apply_filters( 'whd_sms_consent_text', '' !== $text ? $text : self::default_consent_text() );
	}

	public static function default_consent_text() {
		return sprintf(
			/* translators: %s: store name. */
			__( 'Yes, text me first looks and offers from %s. Up to 6 messages a month, message and data rates may apply. Reply STOP to stop, HELP for help. Saying yes is not a condition of any purchase.', 'whd' ),
			rtrim( wp_strip_all_tags( get_bloginfo( 'name' ) ), ' .' )
		);
	}

	/* ─────────────────────────── status ─────────────────────────── */

	public static function email_enabled(): bool {
		$o = self::get();
		switch ( $o['email_provider'] ) {
			case 'mailchimp':
				return '' !== $o['mailchimp_api_key'] && '' !== $o['mailchimp_list_id'];
			case 'klaviyo':
				return '' !== $o['klaviyo_api_key'] && '' !== $o['klaviyo_list_id'];
			case 'webhook':
				return '' !== $o['webhook_url'];
		}
		return false;
	}

	public static function sms_enabled(): bool {
		$o = self::get();
		return 'twilio' === $o['sms_provider'] && '' !== $o['twilio_sid'] && '' !== $o['twilio_token'] && '' !== $o['twilio_from'];
	}

	/** One short line per channel for the Overview page. */
	public static function status_lines() {
		$o     = self::get();
		$email = 'none' === $o['email_provider']
			? __( 'Email: not connected', 'whd' )
			: sprintf(
				/* translators: 1: provider name, 2: configured / needs keys. */
				__( 'Email: %1$s — %2$s', 'whd' ),
				self::email_providers()[ $o['email_provider'] ] ?? $o['email_provider'],
				self::email_enabled() ? __( 'connected', 'whd' ) : __( 'keys missing', 'whd' )
			);
		$sms = 'none' === $o['sms_provider']
			? __( 'SMS: not connected', 'whd' )
			: sprintf(
				/* translators: 1: provider name, 2: configured / needs keys. */
				__( 'SMS: %1$s — %2$s', 'whd' ),
				self::sms_providers()[ $o['sms_provider'] ] ?? $o['sms_provider'],
				self::sms_enabled() ? __( 'connected', 'whd' ) : __( 'keys missing', 'whd' )
			);
		return [ $email, $sms ];
	}

	/* ─────────────────────────── subscriber sync ─────────────────────────── */

	/**
	 * Queue the push. Never call the provider inside the visitor's request:
	 * a slow API would hold the newsletter form open.
	 */
	public static function on_subscriber_added( $id, $email = '', $name = '', $source = '', $phone = '', $sms_consent = 0 ) {
		$id = (int) $id;
		if ( ! $id || ! self::email_enabled() ) {
			return;
		}
		$delay = (int) apply_filters( 'whd_sync_delay', 10, $id );
		if ( ! wp_next_scheduled( self::SYNC_HOOK, [ $id ] ) ) {
			wp_schedule_single_event( time() + max( 0, $delay ), self::SYNC_HOOK, [ $id ] );
		}
	}

	/** Push one subscriber row to the configured provider. */
	public static function sync_subscriber( int $id ): bool {
		if ( ! class_exists( 'WHD_Subscribers' ) ) {
			return false;
		}
		$row = WHD_Subscribers::get( $id );
		if ( ! $row || ! self::email_enabled() ) {
			return false;
		}
		$o  = self::get();
		$ok = false;
		switch ( $o['email_provider'] ) {
			case 'mailchimp':
				$ok = self::push_mailchimp( $row, $o );
				break;
			case 'klaviyo':
				$ok = self::push_klaviyo( $row, $o );
				break;
			case 'webhook':
				$ok = self::push_webhook( $row, $o );
				break;
		}
		if ( $ok ) {
			WHD_Subscribers::mark_synced( $id );
		}
		do_action( 'whd_subscriber_synced', $id, $ok, $o['email_provider'] );
		return $ok;
	}

	/** The shape a webhook receives, and the base data for every provider. */
	public static function payload( $row ) {
		return [
			'email'       => (string) $row->email,
			'name'        => (string) $row->name,
			'phone'       => (string) ( $row->phone ?? '' ),
			'sms_consent' => empty( $row->sms_consent ) ? 0 : 1,
			'source'      => (string) $row->source,
			'site'        => home_url( '/' ),
		];
	}

	private static function first_name( $row ) {
		$name = trim( (string) $row->name );
		if ( '' === $name ) {
			return '';
		}
		$parts = preg_split( '/\s+/', $name );
		return (string) $parts[0];
	}

	/* ── Mailchimp ── */

	/** Data centre lives in the key suffix, e.g. "…-us21" → us21. */
	public static function mailchimp_dc( $key ) {
		$key = (string) $key;
		$pos = strrpos( $key, '-' );
		if ( false === $pos ) {
			return '';
		}
		$dc = strtolower( substr( $key, $pos + 1 ) );
		return preg_match( '/^[a-z]{2}\d{1,3}$/', $dc ) ? $dc : '';
	}

	private static function mailchimp_request( $o, $path, $method = 'GET', $body = null ) {
		$dc = self::mailchimp_dc( $o['mailchimp_api_key'] );
		if ( '' === $dc ) {
			return new WP_Error( 'whd_mailchimp_key', __( 'That Mailchimp key has no data-centre suffix (it should end in something like -us21).', 'whd' ) );
		}
		$args = [
			'method'  => $method,
			'timeout' => 15,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode( 'whd:' . $o['mailchimp_api_key'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'Content-Type'  => 'application/json',
			],
		];
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		return wp_remote_request( 'https://' . $dc . '.api.mailchimp.com/3.0/' . ltrim( $path, '/' ), $args );
	}

	private static function push_mailchimp( $row, $o ) {
		$hash  = md5( strtolower( $row->email ) );
		$path  = 'lists/' . rawurlencode( $o['mailchimp_list_id'] ) . '/members/' . $hash;
		$body  = [
			'email_address' => $row->email,
			'status_if_new' => $o['mailchimp_double_optin'] ? 'pending' : 'subscribed',
		];
		$merge = [];
		if ( '' !== self::first_name( $row ) ) {
			$merge['FNAME'] = self::first_name( $row );
		}
		if ( ! empty( $row->phone ) ) {
			$merge['PHONE'] = (string) $row->phone;
		}
		if ( $merge ) {
			$body['merge_fields'] = $merge;
		}
		$res = self::mailchimp_request( $o, $path, 'PUT', $body );
		$ok  = self::finish( 'mailchimp', 'subscriber #' . (int) $row->id, $res );
		if ( ! $ok && $merge && 400 === (int) wp_remote_retrieve_response_code( $res ) ) {
			// Audience without FNAME/PHONE merge fields: send the address on its own.
			unset( $body['merge_fields'] );
			$res = self::mailchimp_request( $o, $path, 'PUT', $body );
			$ok  = self::finish( 'mailchimp', 'subscriber #' . (int) $row->id . ' (retry without merge fields)', $res );
		}
		if ( $ok && '' !== trim( (string) $o['mailchimp_tags'] ) ) {
			$tags = [];
			foreach ( array_filter( array_map( 'trim', explode( ',', $o['mailchimp_tags'] ) ) ) as $tag ) {
				$tags[] = [ 'name' => $tag, 'status' => 'active' ];
			}
			if ( $tags ) {
				self::finish( 'mailchimp', 'tags for #' . (int) $row->id, self::mailchimp_request( $o, $path . '/tags', 'POST', [ 'tags' => $tags ] ) );
			}
		}
		return $ok;
	}

	/* ── Klaviyo ── */

	private static function klaviyo_request( $o, $path, $method = 'GET', $body = null ) {
		$args = [
			'method'  => $method,
			'timeout' => 15,
			'headers' => [
				'Authorization' => 'Klaviyo-API-Key ' . $o['klaviyo_api_key'],
				'revision'      => self::KLAVIYO_REV,
				'accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			],
		];
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		return wp_remote_request( 'https://a.klaviyo.com/api/' . ltrim( $path, '/' ), $args );
	}

	private static function push_klaviyo( $row, $o ) {
		$attributes = [ 'email' => $row->email ];
		if ( '' !== self::first_name( $row ) ) {
			$attributes['first_name'] = self::first_name( $row );
		}
		$subscriptions = [ 'email' => [ 'marketing' => [ 'consent' => 'SUBSCRIBED' ] ] ];
		$phone         = self::normalize_phone( $row->phone ?? '' );
		if ( '' !== $phone && ! empty( $row->sms_consent ) ) {
			$attributes['phone_number'] = $phone;
			$subscriptions['sms']       = [ 'marketing' => [ 'consent' => 'SUBSCRIBED' ] ];
		}
		$attributes['subscriptions'] = $subscriptions;
		$body = [
			'data' => [
				'type'       => 'profile-subscription-bulk-create-job',
				'attributes' => [
					'profiles' => [ 'data' => [ [ 'type' => 'profile', 'attributes' => $attributes ] ] ],
				],
				'relationships' => [
					'list' => [ 'data' => [ 'type' => 'list', 'id' => $o['klaviyo_list_id'] ] ],
				],
			],
		];
		return self::finish( 'klaviyo', 'subscriber #' . (int) $row->id, self::klaviyo_request( $o, 'profile-subscription-bulk-create-jobs/', 'POST', $body ) );
	}

	/* ── Webhook ── */

	private static function push_webhook( $row, $o, $extra = [] ) {
		$body = array_merge( self::payload( $row ), $extra );
		$res  = wp_remote_post( $o['webhook_url'], [
			'timeout' => 15,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $body ),
		] );
		return self::finish( 'webhook', isset( $extra['test'] ) ? 'test payload' : 'subscriber #' . (int) $row->id, $res );
	}

	/* ─────────────────────────── SMS ─────────────────────────── */

	/**
	 * E.164, the format every gateway wants: 10 digits are treated as US,
	 * 11 digits starting with 1 get the plus, anything else keeps its country code.
	 */
	public static function normalize_phone( $raw ): string {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		$plus   = 0 === strpos( $raw, '+' );
		$digits = preg_replace( '/\D+/', '', $raw );
		if ( '' === $digits ) {
			return '';
		}
		if ( $plus ) {
			return strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ? '+' . $digits : '';
		}
		if ( 10 === strlen( $digits ) ) {
			return '+1' . $digits;
		}
		if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
			return '+' . $digits;
		}
		return strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ? '+' . $digits : '';
	}

	/** Send one text message. Returns false (and logs) when SMS is off or the API refuses. */
	public static function send_sms( string $to, string $text ): bool {
		if ( ! self::sms_enabled() ) {
			self::log( 'sms', 0, __( 'SMS is not connected — message not sent.', 'whd' ) );
			return false;
		}
		$number = self::normalize_phone( $to );
		if ( '' === $number ) {
			self::log( 'sms', 0, __( 'That number could not be read as a phone number.', 'whd' ) );
			return false;
		}
		$text = trim( wp_strip_all_tags( $text ) );
		if ( '' === $text ) {
			return false;
		}
		$o    = self::get();
		$body = [ 'To' => $number, 'Body' => $text ];
		$from = trim( $o['twilio_from'] );
		if ( preg_match( '/^MG[0-9a-f]{32}$/i', $from ) ) {
			$body['MessagingServiceSid'] = $from;
		} else {
			$body['From'] = preg_match( '/[A-Za-z]/', $from ) ? $from : ( self::normalize_phone( $from ) ?: $from );
		}
		$res = wp_remote_post(
			'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $o['twilio_sid'] ) . '/Messages.json',
			[
				'timeout' => 20,
				'headers' => [
					'Authorization' => 'Basic ' . base64_encode( $o['twilio_sid'] . ':' . $o['twilio_token'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Content-Type'  => 'application/x-www-form-urlencoded',
				],
				'body'    => $body,
			]
		);
		return self::finish( 'sms', 'to ' . self::mask_number( $number ), $res );
	}

	/** Never write a whole customer number into a log line. */
	public static function mask_number( $number ) {
		$number = (string) $number;
		return strlen( $number ) > 4 ? str_repeat( '•', strlen( $number ) - 4 ) . substr( $number, -4 ) : $number;
	}

	/* ─────────────────────────── log ─────────────────────────── */

	/** Shorten a log line without cutting a character in half (hosts without mbstring included). */
	private static function clip( $text, $length ) {
		$text = (string) $text;
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length ) : substr( $text, 0, $length );
	}

	public static function log( $channel, $code, $message ) {
		$log   = self::logs();
		$log[] = [
			'time'    => current_time( 'mysql' ),
			'channel' => sanitize_key( $channel ),
			'code'    => (int) $code,
			'message' => self::clip( sanitize_text_field( (string) $message ), 300 ),
		];
		update_option( self::LOG_OPTION, array_slice( $log, -self::LOG_MAX ), false );
	}

	public static function logs() {
		$log = get_option( self::LOG_OPTION, [] );
		return is_array( $log ) ? $log : [];
	}

	/** Log the outcome of one HTTP call and say whether it worked. */
	private static function finish( $channel, $context, $res ) {
		if ( is_wp_error( $res ) ) {
			self::log( $channel, 0, $context . ' — ' . $res->get_error_message() );
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$ok   = $code >= 200 && $code < 300;
		self::log( $channel, $code, $ok ? $context . ' — ' . __( 'sent', 'whd' ) : $context . ' — ' . self::short_error( wp_remote_retrieve_body( $res ) ) );
		return $ok;
	}

	/** Pull the human part out of a provider error body. */
	public static function short_error( $body ) {
		$data = json_decode( (string) $body, true );
		if ( is_array( $data ) ) {
			foreach ( [ 'detail', 'title', 'message', 'error_message' ] as $key ) {
				if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
					return $data[ $key ];
				}
			}
			if ( ! empty( $data['errors'][0]['detail'] ) && is_string( $data['errors'][0]['detail'] ) ) {
				return $data['errors'][0]['detail'];
			}
		}
		$body = trim( wp_strip_all_tags( (string) $body ) );
		return '' === $body ? __( 'no answer from the API', 'whd' ) : self::clip( $body, 200 );
	}

	/* ─────────────────────────── tests ─────────────────────────── */

	/** @return array [ bool $ok, string $message ] — never contains a key. */
	public static function test_email_connection() {
		$o = self::get();
		switch ( $o['email_provider'] ) {
			case 'mailchimp':
				if ( '' === $o['mailchimp_api_key'] ) {
					return [ false, __( 'Add your Mailchimp API key first.', 'whd' ) ];
				}
				$res = self::mailchimp_request( $o, 'ping' );
				if ( ! self::finish( 'mailchimp', 'connection test', $res ) ) {
					return [ false, self::error_message( $res ) ];
				}
				if ( '' === $o['mailchimp_list_id'] ) {
					return [ true, __( 'Mailchimp answered. Add an Audience ID to start sending subscribers.', 'whd' ) ];
				}
				$list = self::mailchimp_request( $o, 'lists/' . rawurlencode( $o['mailchimp_list_id'] ) );
				if ( ! self::finish( 'mailchimp', 'audience test', $list ) ) {
					return [ false, __( 'The key works but that Audience ID does not: ', 'whd' ) . self::error_message( $list ) ];
				}
				$data = json_decode( wp_remote_retrieve_body( $list ), true );
				return [ true, sprintf(
					/* translators: %s: Mailchimp audience name. */
					__( 'Mailchimp is connected. Audience: %s.', 'whd' ),
					is_array( $data ) && ! empty( $data['name'] ) ? $data['name'] : $o['mailchimp_list_id']
				) ];

			case 'klaviyo':
				if ( '' === $o['klaviyo_api_key'] ) {
					return [ false, __( 'Add your Klaviyo private API key first.', 'whd' ) ];
				}
				$path = '' !== $o['klaviyo_list_id'] ? 'lists/' . rawurlencode( $o['klaviyo_list_id'] ) . '/' : 'lists/';
				$res  = self::klaviyo_request( $o, $path );
				if ( ! self::finish( 'klaviyo', 'connection test', $res ) ) {
					return [ false, self::error_message( $res ) ];
				}
				if ( '' === $o['klaviyo_list_id'] ) {
					return [ true, __( 'Klaviyo answered. Add a List ID to start sending subscribers.', 'whd' ) ];
				}
				$data = json_decode( wp_remote_retrieve_body( $res ), true );
				$name = $data['data']['attributes']['name'] ?? '';
				return [ true, '' !== $name ? sprintf(
					/* translators: %s: Klaviyo list name. */
					__( 'Klaviyo is connected. List: %s.', 'whd' ),
					$name
				) : __( 'Klaviyo is connected.', 'whd' ) ];

			case 'webhook':
				if ( '' === $o['webhook_url'] ) {
					return [ false, __( 'Add the webhook URL first.', 'whd' ) ];
				}
				$row = (object) [
					'id'          => 0,
					'email'       => 'test@' . wp_parse_url( home_url(), PHP_URL_HOST ),
					'name'        => __( 'Test subscriber', 'whd' ),
					'phone'       => '+15555550123',
					'sms_consent' => 0,
					'source'      => 'test',
				];
				$ok = self::push_webhook( $row, $o, [ 'test' => true ] );
				return [ $ok, $ok ? __( 'The webhook accepted a test payload.', 'whd' ) : __( 'The webhook did not accept the test payload — see the log below.', 'whd' ) ];
		}
		return [ false, __( 'Pick an email platform first.', 'whd' ) ];
	}

	private static function error_message( $res ) {
		if ( is_wp_error( $res ) ) {
			return $res->get_error_message();
		}
		return sprintf(
			/* translators: 1: HTTP status code, 2: message from the API. */
			__( 'HTTP %1$d — %2$s', 'whd' ),
			(int) wp_remote_retrieve_response_code( $res ),
			self::short_error( wp_remote_retrieve_body( $res ) )
		);
	}

	public static function handle_test_email() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'whd_test_email' ) ) {
			wp_die( esc_html__( 'Not allowed', 'whd' ) );
		}
		list( $ok, $message ) = self::test_email_connection();
		self::notice( $ok, $message );
		self::back();
	}

	public static function handle_test_sms() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'whd_test_sms' ) ) {
			wp_die( esc_html__( 'Not allowed', 'whd' ) );
		}
		$to = isset( $_POST['whd_test_number'] ) ? sanitize_text_field( wp_unslash( $_POST['whd_test_number'] ) ) : '';
		if ( ! self::sms_enabled() ) {
			self::notice( false, __( 'Save your Twilio details first.', 'whd' ) );
			self::back();
		}
		$number = self::normalize_phone( $to );
		if ( '' === $number ) {
			self::notice( false, __( 'Enter a mobile number — 10 digits for a US number, or the full number with its country code.', 'whd' ) );
			self::back();
		}
		$ok = self::send_sms( $number, sprintf(
			/* translators: %s: store name. */
			__( 'Test message from %s. Your SMS setup works.', 'whd' ),
			get_bloginfo( 'name' )
		) );
		self::notice( $ok, $ok
			? sprintf(
				/* translators: %s: masked phone number. */
				__( 'Text sent to %s.', 'whd' ),
				self::mask_number( $number )
			)
			: __( 'Twilio did not accept the message — see the log below.', 'whd' ) );
		self::back();
	}

	private static function notice( $ok, $message ) {
		set_transient( 'whd_integrations_notice_' . get_current_user_id(), [ 'ok' => (bool) $ok, 'message' => (string) $message ], 2 * MINUTE_IN_SECONDS );
	}

	private static function back() {
		wp_safe_redirect( admin_url( 'admin.php?page=whd-integrations' ) );
		exit;
	}

	/* ─────────────────────────── admin page ─────────────────────────── */

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o = self::get();
		echo '<div class="wrap whd-wrap"><h1 class="whd-title">' . esc_html__( 'Integrations', 'whd' ) . '</h1>';
		echo '<p class="whd-intro">' . esc_html__( 'Send every new subscriber straight to your email platform, and text customers through Twilio. Keys are saved with the other plugin settings in the database, so treat this screen as you would any other password box.', 'whd' ) . '</p>';

		$notice = get_transient( 'whd_integrations_notice_' . get_current_user_id() );
		if ( is_array( $notice ) ) {
			delete_transient( 'whd_integrations_notice_' . get_current_user_id() );
			echo '<div class="notice notice-' . ( $notice['ok'] ? 'success' : 'warning' ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}

		echo '<form method="post" action="options.php" class="whd-form">';
		settings_fields( 'whd_integrations_group' );
		self::email_section( $o );
		self::sms_section( $o );
		submit_button( __( 'Save integrations', 'whd' ) );
		echo '</form>';
		self::tests_section( $o );
		self::log_section();
		echo '</div>';
		self::toggle_script();
	}

	private static function field( $key ) {
		return self::OPTION . '[' . $key . ']';
	}

	private static function status_pill( $on ) {
		return '<span class="whd-pill ' . ( $on ? 'whd-pill--on' : 'whd-pill--off' ) . '">' . ( $on ? esc_html__( 'Connected', 'whd' ) : esc_html__( 'Not connected', 'whd' ) ) . '</span>';
	}

	/** Password box that never prints the stored value back to the browser. */
	private static function secret_row( $key, $label, $stored, $help = '' ) {
		echo '<tr><th><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input class="regular-text" type="password" autocomplete="new-password" id="' . esc_attr( $key ) . '" name="' . esc_attr( self::field( $key ) ) . '" value="" placeholder="' . esc_attr( '' !== $stored ? __( 'Saved — leave blank to keep it', 'whd' ) : __( 'Paste your key', 'whd' ) ) . '">';
		if ( '' !== $stored ) {
			echo ' <label class="whd-clear"><input type="checkbox" name="' . esc_attr( self::OPTION . '[clear][' . $key . ']' ) . '" value="1"> ' . esc_html__( 'Remove the saved key', 'whd' ) . '</label>';
		}
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	private static function email_section( $o ) {
		echo '<h2 class="whd-h2">' . esc_html__( 'Email marketing', 'whd' ) . ' ' . self::status_pill( self::email_enabled() ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<table class="form-table whd-table-settings"><tbody>';
		echo '<tr><th><label for="whd-email-provider">' . esc_html__( 'Platform', 'whd' ) . '</label></th><td><select id="whd-email-provider" name="' . esc_attr( self::field( 'email_provider' ) ) . '">';
		foreach ( self::email_providers() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $o['email_provider'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'New subscribers are pushed a few seconds after they sign up, in the background.', 'whd' ) . '</p></td></tr>';
		echo '</tbody></table>';

		// Mailchimp
		$hidden = 'mailchimp' === $o['email_provider'] ? '' : ' hidden';
		echo '<table class="form-table whd-table-settings" data-whd-email="mailchimp"' . $hidden . '><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::secret_row( 'mailchimp_api_key', __( 'Mailchimp API key', 'whd' ), $o['mailchimp_api_key'], __( 'Account → Extras → API keys. The bit after the last dash (us21, us14…) tells the plugin which server to call.', 'whd' ) );
		echo '<tr><th><label for="mailchimp_list_id">' . esc_html__( 'Audience ID', 'whd' ) . '</label></th><td><input class="regular-text" id="mailchimp_list_id" name="' . esc_attr( self::field( 'mailchimp_list_id' ) ) . '" value="' . esc_attr( $o['mailchimp_list_id'] ) . '" placeholder="a1b2c3d4e5"><p class="description">' . esc_html__( 'Audience → Settings → Audience name and defaults.', 'whd' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Double opt-in', 'whd' ) . '</th><td><label><input type="checkbox" name="' . esc_attr( self::field( 'mailchimp_double_optin' ) ) . '" value="1" ' . checked( $o['mailchimp_double_optin'], 1, false ) . '> ' . esc_html__( 'Ask new contacts to confirm by email before they join the audience.', 'whd' ) . '</label></td></tr>';
		echo '<tr><th><label for="mailchimp_tags">' . esc_html__( 'Tags', 'whd' ) . '</label></th><td><input class="regular-text" id="mailchimp_tags" name="' . esc_attr( self::field( 'mailchimp_tags' ) ) . '" value="' . esc_attr( $o['mailchimp_tags'] ) . '" placeholder="website, popup"><p class="description">' . esc_html__( 'Comma separated. Added to every contact this plugin sends.', 'whd' ) . '</p></td></tr>';
		echo '</tbody></table>';

		// Klaviyo
		$hidden = 'klaviyo' === $o['email_provider'] ? '' : ' hidden';
		echo '<table class="form-table whd-table-settings" data-whd-email="klaviyo"' . $hidden . '><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::secret_row( 'klaviyo_api_key', __( 'Klaviyo private API key', 'whd' ), $o['klaviyo_api_key'], __( 'Settings → API keys → Private API key, with list and profile permissions.', 'whd' ) );
		echo '<tr><th><label for="klaviyo_list_id">' . esc_html__( 'List ID', 'whd' ) . '</label></th><td><input class="regular-text" id="klaviyo_list_id" name="' . esc_attr( self::field( 'klaviyo_list_id' ) ) . '" value="' . esc_attr( $o['klaviyo_list_id'] ) . '" placeholder="XxXxXx"><p class="description">' . esc_html__( 'Audience → Lists & segments → open the list; the ID sits in its URL.', 'whd' ) . '</p></td></tr>';
		echo '</tbody></table>';

		// Webhook
		$hidden = 'webhook' === $o['email_provider'] ? '' : ' hidden';
		echo '<table class="form-table whd-table-settings" data-whd-email="webhook"' . $hidden . '><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th><label for="webhook_url">' . esc_html__( 'Webhook URL', 'whd' ) . '</label></th><td><input class="large-text code" type="url" id="webhook_url" name="' . esc_attr( self::field( 'webhook_url' ) ) . '" value="' . esc_attr( $o['webhook_url'] ) . '" placeholder="https://hooks.zapier.com/hooks/catch/…"><p class="description">' . esc_html__( 'Each subscriber is posted as JSON: email, name, phone, sms_consent, source, site.', 'whd' ) . '</p></td></tr>';
		echo '</tbody></table>';
	}

	private static function sms_section( $o ) {
		echo '<h2 class="whd-h2">' . esc_html__( 'SMS', 'whd' ) . ' ' . self::status_pill( self::sms_enabled() ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<table class="form-table whd-table-settings"><tbody>';
		echo '<tr><th><label for="whd-sms-provider">' . esc_html__( 'Gateway', 'whd' ) . '</label></th><td><select id="whd-sms-provider" name="' . esc_attr( self::field( 'sms_provider' ) ) . '">';
		foreach ( self::sms_providers() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $o['sms_provider'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '</tbody></table>';

		$hidden = 'twilio' === $o['sms_provider'] ? '' : ' hidden';
		echo '<table class="form-table whd-table-settings" data-whd-sms="twilio"' . $hidden . '><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th><label for="twilio_sid">' . esc_html__( 'Account SID', 'whd' ) . '</label></th><td><input class="regular-text" id="twilio_sid" name="' . esc_attr( self::field( 'twilio_sid' ) ) . '" value="' . esc_attr( $o['twilio_sid'] ) . '" placeholder="AC…"></td></tr>';
		self::secret_row( 'twilio_token', __( 'Auth token', 'whd' ), $o['twilio_token'], __( 'Twilio console → Account info.', 'whd' ) );
		echo '<tr><th><label for="twilio_from">' . esc_html__( 'From number or Messaging Service SID', 'whd' ) . '</label></th><td><input class="regular-text" id="twilio_from" name="' . esc_attr( self::field( 'twilio_from' ) ) . '" value="' . esc_attr( $o['twilio_from'] ) . '" placeholder="+13465551234"><p class="description">' . esc_html__( 'A Twilio number in +1 format, or an MG… Messaging Service SID.', 'whd' ) . '</p></td></tr>';
		echo '</tbody></table>';

		echo '<table class="form-table whd-table-settings"><tbody>';
		echo '<tr><th><label for="sms_consent_text">' . esc_html__( 'SMS consent text', 'whd' ) . '</label></th><td><textarea class="large-text whd-consent" rows="3" id="sms_consent_text" name="' . esc_attr( self::field( 'sms_consent_text' ) ) . '" placeholder="' . esc_attr( self::default_consent_text() ) . '">' . esc_textarea( $o['sms_consent_text'] ) . '</textarea><p class="description">' . esc_html__( 'Shown beside every phone field on the site, next to the tick box. Leave blank to use the default wording shown in the box.', 'whd' ) . '</p></td></tr>';
		echo '</tbody></table>';
	}

	private static function tests_section( $o ) {
		echo '<h2 class="whd-h2">' . esc_html__( 'Test your connections', 'whd' ) . '</h2>';
		echo '<div class="whd-note">' . esc_html__( 'Save the settings above first — the tests use what is stored.', 'whd' ) . '</div>';
		echo '<div class="whd-tests">';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="whd-test">';
		wp_nonce_field( 'whd_test_email' );
		echo '<input type="hidden" name="action" value="whd_test_email_provider">';
		echo '<p class="whd-test__label">' . esc_html__( 'Email platform', 'whd' ) . '</p>';
		echo '<p class="whd-muted">' . esc_html( 'none' === $o['email_provider'] ? __( 'Pick a platform above to test it.', 'whd' ) : ( self::email_providers()[ $o['email_provider'] ] ?? $o['email_provider'] ) ) . '</p>';
		submit_button( __( 'Test connection', 'whd' ), 'secondary', 'whd_test_email_submit', false, 'none' === $o['email_provider'] ? [ 'disabled' => 'disabled' ] : [] );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="whd-test">';
		wp_nonce_field( 'whd_test_sms' );
		echo '<input type="hidden" name="action" value="whd_test_sms">';
		echo '<p class="whd-test__label"><label for="whd_test_number">' . esc_html__( 'Send a test text', 'whd' ) . '</label></p>';
		echo '<p><input class="regular-text" type="tel" id="whd_test_number" name="whd_test_number" placeholder="(346) 555-1234"></p>';
		submit_button( __( 'Send test SMS', 'whd' ), 'secondary', 'whd_test_sms_submit', false, self::sms_enabled() ? [] : [ 'disabled' => 'disabled' ] );
		echo '</form>';

		echo '</div>';
	}

	private static function log_section() {
		$log = array_reverse( self::logs() );
		echo '<h2 class="whd-h2">' . esc_html__( 'Last API calls', 'whd' ) . '</h2>';
		echo '<table class="widefat striped whd-table whd-log"><thead><tr><th>' . esc_html__( 'When', 'whd' ) . '</th><th>' . esc_html__( 'Channel', 'whd' ) . '</th><th>' . esc_html__( 'Code', 'whd' ) . '</th><th>' . esc_html__( 'Result', 'whd' ) . '</th></tr></thead><tbody>';
		if ( ! $log ) {
			echo '<tr><td colspan="4">' . esc_html__( 'Nothing sent yet.', 'whd' ) . '</td></tr>';
		}
		foreach ( $log as $entry ) {
			$code = (int) ( $entry['code'] ?? 0 );
			$ok   = $code >= 200 && $code < 300;
			echo '<tr><td class="whd-muted">' . esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $entry['time'] ?? '' ) ) . '</td><td>' . esc_html( $entry['channel'] ?? '' ) . '</td><td><span class="whd-pill ' . ( $ok ? 'whd-pill--on' : 'whd-pill--off' ) . '">' . esc_html( $code ? (string) $code : '—' ) . '</span></td><td>' . esc_html( $entry['message'] ?? '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Show only the boxes for the chosen providers. */
	private static function toggle_script() {
		echo '<script>(function(){function bind(id,attr){var s=document.getElementById(id);if(!s){return;}function paint(){var els=document.querySelectorAll("["+attr+"]");for(var i=0;i<els.length;i++){els[i].hidden=els[i].getAttribute(attr)!==s.value;}}s.addEventListener("change",paint);paint();}bind("whd-email-provider","data-whd-email");bind("whd-sms-provider","data-whd-sms");})();</script>';
	}
}
