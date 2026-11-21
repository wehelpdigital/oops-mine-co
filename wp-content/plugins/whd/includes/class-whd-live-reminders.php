<?php
/**
 * Live reminders: the shop's Klaviyo subscribers hear about the next live stream by email and by text.
 *
 * The owner sets when the next live is — a date typed here, or a stream set up in WHD → Live Stream —
 * and up to three reminders (a heads-up, a last call, "we're live"), each by email, text or both.
 * Saving turns every reminder into a **Klaviyo campaign scheduled at its time**: Klaviyo holds the
 * audience and the consent, so it emails the people who can receive email marketing and texts only
 * the ones who agreed to texts, adds the unsubscribe link and the opt-out line, and sends on its own
 * clock. Nothing here waits on WP-Cron at send time, which matters on a shop that sees few visitors.
 *
 * The emails are designed in the WHD drag-and-drop editor (designs `live_heads_up`, `live_last_call`,
 * `live_now`), rendered here with the live's details filled in and Klaviyo's own tags for the
 * subscriber's first name, the unsubscribe link and the organisation's address, and handed over as a
 * code template. A text is a plain message with the same tags.
 *
 * Klaviyo API revision 2026-10-15, where a campaign is campaign → audience → message → variation:
 *   POST campaigns (one call creates all four) → POST templates + campaign-message-assign-template
 *   (a non-reusable copy is assigned, so the template is deleted straight after) → POST
 *   campaign-message-schedule (strategy static). Taking one back: PATCH campaign-message-schedule/{id}
 *   with action "revert", then DELETE campaigns/{id}.
 *
 * What is scheduled is remembered per message (`whd_live_reminders_state`, keyed live time | reminder |
 * channel) with a signature of everything that went into it. Each sync compares the plan with that
 * record: unchanged messages stay, changed or unwanted ones are taken back, missing ones are made. So
 * a new date, an edited email, a different list or switching the whole thing off all reach Klaviyo by
 * saving once. A message less than ten minutes away is left alone either way.
 *
 * The date is the same one the home page's live card counts down to (theme mod `omc_live_datetime`):
 * saving here writes it there, and a date set in the Customizer or the Page Editor is read back.
 *
 * The site side: after somebody leaves an email on the live card or the reminder dialog, a second,
 * optional step asks for a mobile number with Klaviyo's disclosure and links to the Mobile Terms and
 * Privacy Policy. That joins the WHD subscriber row with SMS consent, and the integration hands it to
 * Klaviyo as an SMS subscription.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Live_Reminders {

	const PAGE   = 'whd-live-reminders';
	const OPTION = 'whd_live_reminders';
	const STATE  = 'whd_live_reminders_state';
	const LAST   = 'whd_live_reminders_last';
	const LISTS  = 'whd_live_reminders_lists';
	const CRON   = 'whd_live_reminders_tick';
	const LOCK   = 'whd_live_reminders_lock';
	const ACCOUNT = 'whd_live_reminders_account';

	const REV = '2026-10-15';
	const API = 'https://a.klaviyo.com/api/';

	/** A message is only scheduled, changed or taken back while it is at least this far away. */
	const LEAD = 600;

	/** A live counts as on for two hours after it starts — the theme's `omc_live_window`. */
	const WINDOW = 7200;

	/** Seconds one sync may spend talking to Klaviyo; the rest is finished by the next run. */
	const BUDGET = 20;

	/** How long the sign-up's "add your number" step stays valid. */
	const TOKEN_TTL = 1800;

	/** Placeholders swapped for Klaviyo's tags once the email is rendered (esc_html and esc_url leave them be). */
	const FIRST = 'WHDKLVFIRSTNAME';
	const UNSUB = 'https://whd-klaviyo-unsubscribe.invalid/';

	/** Reminder → its email design (a WHD_Emails trigger). */
	const DESIGNS = [
		'heads_up'  => 'live_heads_up',
		'last_call' => 'live_last_call',
		'now'       => 'live_now',
	];

	/** @var bool True while this class writes the theme's date, so it does not read its own write back. */
	private static $writing = false;

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 12 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'admin_assets' ] );
		add_action( 'admin_post_whd_live_reminders_save', [ __CLASS__, 'handle_save' ] );
		add_action( 'admin_post_whd_live_reminders_sync', [ __CLASS__, 'handle_sync' ] );
		add_action( 'admin_post_whd_live_reminders_preview', [ __CLASS__, 'handle_preview' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'rest' ] );
		add_action( self::CRON, [ __CLASS__, 'tick' ] );
		add_action( 'init', [ __CLASS__, 'schedule_tick' ] );
		add_action( 'whd_live_stream_saved', [ __CLASS__, 'on_stream_change' ] );
		add_action( 'whd_live_stream_deleted', [ __CLASS__, 'on_stream_change' ] );
		add_action( 'update_option_theme_mods_' . get_option( 'stylesheet' ), [ __CLASS__, 'theme_changed' ], 10, 2 );

		// The site: the optional "text me too" step after an email sign-up on the live card.
		add_filter( 'omc_subscribe_response', [ __CLASS__, 'subscribe_response' ], 10, 3 );
		add_action( 'wp_footer', [ __CLASS__, 'sms_step' ], 4 );
		add_action( 'wp_ajax_whd_live_sms', [ __CLASS__, 'ajax_sms' ] );
		add_action( 'wp_ajax_nopriv_whd_live_sms', [ __CLASS__, 'ajax_sms' ] );
	}

	public static function schedule_tick() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 600, 'hourly', self::CRON );
		}
	}

	public static function deactivate() {
		wp_unschedule_hook( self::CRON );
	}

	/* ─────────────────────────── settings ─────────────────────────── */

	public static function defaults(): array {
		return [
			'enabled'     => 0,
			'source'      => 'manual', // manual | stream
			'stream'      => 0,        // a WHD Live stream (source = stream)
			'when'        => '',       // Y-m-d\TH:i in the site's timezone (source = manual)
			'title'       => '',
			'url'         => '',
			'repeat'      => 'none',   // none | weekly
			'email_to'    => [],       // Klaviyo list / segment ids
			'sms_to'      => [],
			'exclude'     => [],
			'from_email'  => '',
			'from_label'  => '',
			'reply_to'    => '',
			'sms_prefix'  => 1,        // Klaviyo puts the organisation's name in front of each text
			'sms_opt_out' => 1,        // and "Reply STOP to opt out" at the end
			'ask_phone'   => 1,        // the "text me too" step after an email sign-up on the site
			'company'     => '',       // who the texts are from, in the consent line
			'reminders'   => [
				'heads_up'  => [ 'on' => 1, 'before' => 1440, 'email' => 1, 'sms' => 0, 'text' => "Tomorrow at {live_time} we go live: {live_title}. Watch here: {live_url}" ],
				'last_call' => [ 'on' => 1, 'before' => 60, 'email' => 1, 'sms' => 1, 'text' => "We go live in an hour, at {live_time}. Grab a seat: {live_url}" ],
				'now'       => [ 'on' => 1, 'before' => 0, 'email' => 0, 'sms' => 1, 'text' => "We're live now! Come in: {live_url}" ],
			],
		];
	}

	public static function get(): array {
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$d      = self::defaults();
		$s      = array_merge( $d, array_intersect_key( $stored, $d ) );
		$rem    = is_array( $stored['reminders'] ?? null ) ? $stored['reminders'] : [];
		foreach ( $d['reminders'] as $id => $r ) {
			$s['reminders'][ $id ] = array_merge( $r, array_intersect_key( is_array( $rem[ $id ] ?? null ) ? $rem[ $id ] : [], $r ) );
		}
		foreach ( [ 'email_to', 'sms_to', 'exclude' ] as $k ) {
			$s[ $k ] = array_values( array_filter( array_map( 'strval', (array) $s[ $k ] ) ) );
		}
		// Until the owner picks, the audience is the list sign-ups already go to (WHD → Integrations).
		if ( ! isset( $stored['email_to'] ) && ! isset( $stored['sms_to'] ) ) {
			$list = (string) WHD_Integrations::get()['klaviyo_list_id'];
			if ( '' !== $list ) {
				$s['email_to'] = [ $list ];
				$s['sms_to']   = [ $list ];
			}
		}
		return $s;
	}

	public static function labels(): array {
		return [
			'heads_up'  => __( 'Heads-up', 'whd' ),
			'last_call' => __( 'Last call', 'whd' ),
			'now'       => __( 'We’re live', 'whd' ),
		];
	}

	/** How long before the live a reminder can go, in minutes. */
	public static function befores(): array {
		return [
			0     => __( 'As it starts', 'whd' ),
			15    => __( '15 minutes before', 'whd' ),
			30    => __( '30 minutes before', 'whd' ),
			60    => __( '1 hour before', 'whd' ),
			120   => __( '2 hours before', 'whd' ),
			180   => __( '3 hours before', 'whd' ),
			360   => __( '6 hours before', 'whd' ),
			720   => __( '12 hours before', 'whd' ),
			1440  => __( '1 day before', 'whd' ),
			2880  => __( '2 days before', 'whd' ),
			4320  => __( '3 days before', 'whd' ),
			10080 => __( '1 week before', 'whd' ),
		];
	}

	public static function sanitize( array $in ): array {
		$d   = self::defaults();
		$ids = static fn( $v ) => array_values( array_unique( array_filter( array_map( static fn( $x ) => preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $x ), (array) $v ) ) ) );
		$out = [
			'enabled'     => empty( $in['enabled'] ) ? 0 : 1,
			'source'      => 'stream' === ( $in['source'] ?? '' ) ? 'stream' : 'manual',
			'stream'      => absint( $in['stream'] ?? 0 ),
			'when'        => self::clean_when( (string) ( $in['when'] ?? '' ) ),
			'title'       => sanitize_text_field( (string) ( $in['title'] ?? '' ) ),
			'url'         => esc_url_raw( trim( (string) ( $in['url'] ?? '' ) ) ),
			'repeat'      => 'weekly' === ( $in['repeat'] ?? '' ) ? 'weekly' : 'none',
			'email_to'    => $ids( $in['email_to'] ?? [] ),
			'sms_to'      => $ids( $in['sms_to'] ?? [] ),
			'exclude'     => $ids( $in['exclude'] ?? [] ),
			'from_email'  => sanitize_email( (string) ( $in['from_email'] ?? '' ) ),
			'from_label'  => sanitize_text_field( (string) ( $in['from_label'] ?? '' ) ),
			'reply_to'    => sanitize_email( (string) ( $in['reply_to'] ?? '' ) ),
			'sms_prefix'  => empty( $in['sms_prefix'] ) ? 0 : 1,
			'sms_opt_out' => empty( $in['sms_opt_out'] ) ? 0 : 1,
			'ask_phone'   => empty( $in['ask_phone'] ) ? 0 : 1,
			'company'     => sanitize_text_field( (string) ( $in['company'] ?? '' ) ),
			'reminders'   => [],
		];
		$befores = self::befores();
		foreach ( $d['reminders'] as $id => $def ) {
			$r      = is_array( $in['reminders'][ $id ] ?? null ) ? $in['reminders'][ $id ] : [];
			$before = (int) ( $r['before'] ?? $def['before'] );
			$text   = trim( sanitize_textarea_field( (string) ( $r['text'] ?? '' ) ) );
			$out['reminders'][ $id ] = [
				'on'     => empty( $r['on'] ) ? 0 : 1,
				'before' => isset( $befores[ $before ] ) ? $before : $def['before'],
				'email'  => empty( $r['email'] ) ? 0 : 1,
				'sms'    => empty( $r['sms'] ) ? 0 : 1,
				'text'   => '' !== $text ? mb_substr( $text, 0, 600 ) : $def['text'],
			];
		}
		return $out;
	}

	private static function clean_when( string $v ): string {
		$v = trim( $v );
		return '' !== $v && DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $v, wp_timezone() ) ? $v : '';
	}

	/* ─────────────────────────── the live ─────────────────────────── */

	/** WHD Live streams that have a date and have not ended, soonest first: [ id => [ title, ts ] ]. */
	public static function streams(): array {
		if ( ! class_exists( 'WHD_Live' ) ) {
			return [];
		}
		$out = [];
		foreach ( get_posts( [ 'post_type' => WHD_Live::CPT, 'post_status' => 'publish', 'numberposts' => 50, 'orderby' => 'date', 'order' => 'DESC' ] ) as $p ) {
			$st = WHD_Live::get( $p->ID );
			if ( 'ended' === $st['stage'] || ! (int) $st['starts_at'] ) {
				continue;
			}
			$out[ $p->ID ] = [ 'title' => get_the_title( $p ), 'ts' => (int) $st['starts_at'] ];
		}
		uasort( $out, static fn( $a, $b ) => $a['ts'] <=> $b['ts'] );
		return $out;
	}

	/**
	 * The lives the reminders are for, soonest first: [ [ ts, title, url ] ].
	 *
	 * One, or with "every week" the next two — so a quiet week in which WP-Cron never fires still finds
	 * the following live's reminders already waiting in Klaviyo.
	 */
	public static function lives( ?array $s = null, ?int $now = null ): array {
		$s   = $s ?? self::get();
		$now = $now ?? time();
		if ( 'stream' === $s['source'] ) {
			$streams = self::streams();
			$st      = $streams[ $s['stream'] ] ?? null;
			if ( ! $st || $st['ts'] + self::WINDOW < $now ) {
				return [];
			}
			return [ [
				'ts'    => $st['ts'],
				'title' => '' !== $s['title'] ? $s['title'] : $st['title'],
				'url'   => '' !== $s['url'] ? $s['url'] : WHD_Live::page_url( $s['stream'] ),
			] ];
		}
		$ts = self::when_ts( $s['when'] );
		if ( ! $ts ) {
			return [];
		}
		if ( 'weekly' === $s['repeat'] ) {
			while ( $ts + self::WINDOW < $now ) {
				$ts += WEEK_IN_SECONDS;
			}
		} elseif ( $ts + self::WINDOW < $now ) {
			return [];
		}
		$one = [ 'ts' => $ts, 'title' => self::title( $s ), 'url' => self::watch_url( $s ) ];
		return 'weekly' === $s['repeat'] ? [ $one, array_merge( $one, [ 'ts' => $ts + WEEK_IN_SECONDS ] ) ] : [ $one ];
	}

	public static function when_ts( string $when ): int {
		if ( '' === $when ) {
			return 0;
		}
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $when, wp_timezone() );
		return $d ? $d->getTimestamp() : 0;
	}

	private static function title( array $s ): string {
		return '' !== $s['title'] ? $s['title'] : sprintf( /* translators: %s: shop name */ __( 'Live shopping with %s', 'whd' ), wp_strip_all_tags( get_bloginfo( 'name' ) ) );
	}

	/** Where people watch: the link typed here, else the home card's link, else the shop's Facebook page. */
	private static function watch_url( array $s ): string {
		if ( '' !== $s['url'] ) {
			return $s['url'];
		}
		if ( function_exists( 'omc_fb_live' ) ) {
			$card = omc_fb_live();
			if ( ! empty( $card['url'] ) ) {
				return (string) $card['url'];
			}
		}
		return home_url( '/' );
	}

	/** The merge tags a live fills in. */
	public static function live_tags( array $live, int $before ): array {
		$ts = (int) $live['ts'];
		return [
			'live_title'     => (string) $live['title'],
			'live_url'       => (string) $live['url'],
			'live_date'      => wp_date( 'l, F j', $ts ),
			'live_time'      => wp_date( 'g:i a T', $ts ),
			'live_when'      => sprintf( /* translators: 1: date, 2: time */ __( '%1$s at %2$s', 'whd' ), wp_date( 'l, F j', $ts ), wp_date( 'g:i a T', $ts ) ),
			'live_starts_in' => self::starts_in( $before ),
		];
	}

	public static function starts_in( int $before ): string {
		if ( $before <= 0 ) {
			return __( 'now', 'whd' );
		}
		if ( $before < 60 ) {
			/* translators: %d: minutes */
			return sprintf( _n( 'in %d minute', 'in %d minutes', $before, 'whd' ), $before );
		}
		if ( 60 === $before ) {
			return __( 'in an hour', 'whd' );
		}
		if ( $before < 1440 ) {
			$h = (int) round( $before / 60 );
			/* translators: %d: hours */
			return sprintf( _n( 'in %d hour', 'in %d hours', $h, 'whd' ), $h );
		}
		if ( 1440 === $before ) {
			return __( 'tomorrow', 'whd' );
		}
		$days = (int) round( $before / 1440 );
		/* translators: %d: days */
		return sprintf( _n( 'in %d day', 'in %d days', $days, 'whd' ), $days );
	}

	/* ─────────────────────────── the theme's date ─────────────────────────── */

	private static function theme_on(): bool {
		return function_exists( 'omc_fb_live' );
	}

	/** Write the next live's date (and, for a live elsewhere, its link) onto the home page's live card. */
	public static function push_to_theme( ?array $s = null ) {
		$s = $s ?? self::get();
		if ( ! self::theme_on() ) {
			return;
		}
		$lives = self::lives( $s );
		if ( ! $lives ) {
			return;
		}
		self::$writing = true;
		$local = wp_date( 'Y-m-d\TH:i', $lives[0]['ts'] );
		if ( get_theme_mod( 'omc_live_datetime', '' ) !== $local ) {
			set_theme_mod( 'omc_live_datetime', $local );
		}
		// A stream's page stays off public pages (its link is private, the owner's rule); a Facebook link does not.
		if ( 'manual' === $s['source'] && '' !== $s['url'] && get_theme_mod( 'omc_live_url', '' ) !== $s['url'] ) {
			set_theme_mod( 'omc_live_url', $s['url'] );
		}
		self::$writing = false;
	}

	/** A date set in the Customizer or the Page Editor becomes the reminders' date too. */
	public static function theme_changed( $old, $new ) {
		if ( self::$writing ) {
			return;
		}
		$before = is_array( $old ) ? (string) ( $old['omc_live_datetime'] ?? '' ) : '';
		$after  = is_array( $new ) ? self::clean_when( (string) ( $new['omc_live_datetime'] ?? '' ) ) : '';
		if ( $before === $after || '' === $after ) {
			return;
		}
		$s = self::get();
		if ( 'manual' !== $s['source'] || $s['when'] === $after ) {
			return;
		}
		$s['when'] = $after;
		update_option( self::OPTION, $s, false );
		if ( $s['enabled'] ) {
			self::sync_soon();
		}
	}

	public static function on_stream_change( $id ) {
		$s = self::get();
		if ( $s['enabled'] && 'stream' === $s['source'] && (int) $id === (int) $s['stream'] ) {
			self::push_to_theme( $s );
			self::sync_soon();
		}
	}

	/** Sync once the visitor has their answer, with a cron event behind it where that is not possible. */
	private static function sync_soon() {
		wp_schedule_single_event( time() + 60, self::CRON );
		WHD_Plugin::after_response( static function ( $closed ) {
			if ( $closed ) {
				self::sync( 'changed' );
			}
		} );
	}

	/* ─────────────────────────── Klaviyo ─────────────────────────── */

	private static function key(): string {
		$o = WHD_Integrations::get();
		return 'klaviyo' === $o['email_provider'] ? trim( (string) $o['klaviyo_api_key'] ) : '';
	}

	public static function connected(): bool {
		return '' !== self::key();
	}

	/**
	 * One call to Klaviyo. Returns [ code, data, error ] — error is '' on a 2xx and never contains the key.
	 * A 429 is waited out once (Retry-After, at most three seconds).
	 */
	public static function api( string $method, string $path, ?array $body = null ): array {
		$key = self::key();
		if ( '' === $key ) {
			return [ 0, null, __( 'Klaviyo is not connected (WHD → Integrations).', 'whd' ) ];
		}
		$args = [
			'method'  => $method,
			'timeout' => 25,
			'headers' => [
				'Authorization' => 'Klaviyo-API-Key ' . $key,
				'revision'      => self::REV,
				'accept'        => 'application/vnd.api+json',
			],
		];
		if ( null !== $body ) {
			$args['headers']['content-type'] = 'application/vnd.api+json';
			$args['body']                    = wp_json_encode( $body );
		}
		$res  = null;
		$code = 0;
		for ( $try = 0; $try < 2; $try++ ) {
			$res = wp_remote_request( self::API . ltrim( $path, '/' ), $args );
			if ( is_wp_error( $res ) ) {
				return [ 0, null, $res->get_error_message() ];
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( 429 !== $code || $try ) {
				break;
			}
			sleep( min( 3, max( 1, (int) wp_remote_retrieve_header( $res, 'retry-after' ) ) ) );
		}
		$raw  = (string) wp_remote_retrieve_body( $res );
		$data = '' === $raw ? null : json_decode( $raw, true );
		$ok   = $code >= 200 && $code < 300;
		return [ $code, is_array( $data ) ? $data : null, $ok ? '' : WHD_Integrations::short_error( $raw ) . ' (HTTP ' . $code . ')' ];
	}

	/**
	 * The account's lists and segments, for the audience pickers: [ lists => [ id => [ name, optin ] ], segments => [ id => name ], error ].
	 * Kept ten minutes; `$fresh` asks again.
	 */
	public static function audiences( bool $fresh = false ): array {
		$cached = get_transient( self::LISTS );
		if ( ! $fresh && is_array( $cached ) ) {
			return $cached;
		}
		$out = [ 'lists' => [], 'segments' => [], 'error' => '' ];
		if ( ! self::connected() ) {
			$out['error'] = __( 'Klaviyo is not connected (WHD → Integrations).', 'whd' );
			return $out;
		}
		foreach ( [ 'lists' => 'lists?fields[list]=name,opt_in_process', 'segments' => 'segments?fields[segment]=name' ] as $kind => $path ) {
			for ( $page = 0; $path && $page < 5; $page++ ) {
				[ $code, $data, $err ] = self::api( 'GET', $path );
				if ( $err ) {
					$out['error'] = $err;
					break;
				}
				foreach ( (array) ( $data['data'] ?? [] ) as $row ) {
					$name = (string) ( $row['attributes']['name'] ?? $row['id'] );
					$out[ $kind ][ (string) $row['id'] ] = 'lists' === $kind ? [ 'name' => $name, 'optin' => (string) ( $row['attributes']['opt_in_process'] ?? '' ) ] : $name;
				}
				$next = (string) ( $data['links']['next'] ?? '' );
				$path = '' !== $next ? (string) preg_replace( '#^https://a\.klaviyo\.com/api/#', '', $next ) : '';
			}
		}
		if ( '' === $out['error'] ) {
			set_transient( self::LISTS, $out, 10 * MINUTE_IN_SECONDS );
		}
		return $out;
	}

	/* ─────────────────────────── what each message says ─────────────────────────── */

	/** The render context for a reminder's email. With $klaviyo, the subscriber's own details are Klaviyo's tags. */
	public static function email_context( string $rid, array $live, array $s, bool $klaviyo ): array {
		$data = self::live_tags( $live, (int) $s['reminders'][ $rid ]['before'] );
		if ( $klaviyo ) {
			$data['first_name']      = self::FIRST;
			$data['customer_name']   = self::FIRST;
			$data['email']           = '{{ email }}';
			$data['unsubscribe_url'] = self::UNSUB;
		}
		$ctx            = WHD_Emails::context( [ 'data' => $data ] );
		$ctx['trigger'] = self::DESIGNS[ $rid ];
		if ( $klaviyo ) {
			// Campaign email needs a postal address; Klaviyo fills in the one on the account.
			$ctx['footer_html'] = '<br>{{ organization.name }} · {{ organization.full_address }}';
		}
		return $ctx;
	}

	/** [ subject, preview, html ] of a reminder's email, ready for Klaviyo. */
	public static function email_parts( string $rid, array $live, array $s ): array {
		$design = WHD_Emails::get( self::DESIGNS[ $rid ] );
		$ctx    = self::email_context( $rid, $live, $s, true );
		return [
			'subject' => self::klaviyo_tags( trim( wp_strip_all_tags( WHD_Blocks::merge( $design['settings']['subject'] ?: get_bloginfo( 'name' ), $ctx ) ) ) ),
			'preview' => self::klaviyo_tags( trim( wp_strip_all_tags( WHD_Blocks::merge( (string) $design['settings']['preheader'], $ctx ) ) ) ),
			'html'    => self::klaviyo_tags( WHD_Blocks::render( $design, 'email', $ctx ) ),
		];
	}

	/** A reminder's text message, ready for Klaviyo. */
	public static function sms_body( string $rid, array $live, array $s ): string {
		$data               = self::live_tags( $live, (int) $s['reminders'][ $rid ]['before'] );
		$data['first_name'] = self::FIRST;
		$data['site_name']  = wp_strip_all_tags( get_bloginfo( 'name' ) );
		return self::klaviyo_tags( trim( wp_strip_all_tags( WHD_Blocks::merge( $s['reminders'][ $rid ]['text'], [ 'data' => $data ] ) ) ) );
	}

	private static function klaviyo_tags( string $text ): string {
		return str_replace(
			[ self::FIRST, esc_url( self::UNSUB ), self::UNSUB ],
			[ "{{ first_name|default:'there' }}", '{% unsubscribe_link %}', '{% unsubscribe_link %}' ],
			$text
		);
	}

	/**
	 * Who the emails are from: what is typed here, else the default sender set up in Klaviyo, else
	 * WooCommerce's address, else the one WHD sends its own mail from.
	 */
	private static function sender( array $s ): array {
		$acct  = self::account();
		$woo   = sanitize_email( (string) get_option( 'woocommerce_email_from_address', '' ) );
		$email = '' !== $s['from_email'] ? $s['from_email'] : ( ! empty( $acct['email'] ) ? $acct['email'] : ( is_email( $woo ) ? $woo : WHD_Emails::sender()[0] ) );
		$label = '' !== $s['from_label'] ? $s['from_label'] : ( ! empty( $acct['name'] ) ? $acct['name'] : wp_strip_all_tags( get_bloginfo( 'name' ) ) );
		return [ 'email' => $email, 'label' => $label ];
	}

	/**
	 * The Klaviyo account's default sender and organisation name (its Settings → Account), kept a day.
	 * A failed look is remembered for the day too, so a key without the accounts scope does not ask
	 * again on every page view.
	 */
	public static function account( bool $fresh = false ): array {
		$saved = get_option( self::ACCOUNT, [] );
		$saved = is_array( $saved ) ? $saved : [];
		if ( ( ! $fresh && ! empty( $saved['at'] ) && (int) $saved['at'] > time() - DAY_IN_SECONDS ) || ! self::connected() ) {
			return $saved;
		}
		[ , $data, $err ] = self::api( 'GET', 'accounts' );
		if ( $err ) {
			$saved['at'] = time();
			update_option( self::ACCOUNT, $saved, false );
			return $saved;
		}
		$ci  = (array) ( $data['data'][0]['attributes']['contact_information'] ?? [] );
		$out = [
			'email' => sanitize_email( (string) ( $ci['default_sender_email'] ?? '' ) ),
			'name'  => sanitize_text_field( (string) ( $ci['default_sender_name'] ?? '' ) ),
			'org'   => sanitize_text_field( (string) ( $ci['organization_name'] ?? '' ) ),
			'at'    => time(),
		];
		update_option( self::ACCOUNT, $out, false );
		return $out;
	}

	/* ─────────────────────────── the plan ─────────────────────────── */

	/**
	 * Every message the settings ask for, keyed "live time|reminder|channel", whether or not it can
	 * still be scheduled (`late` = less than LEAD away, or already gone).
	 */
	public static function plan( ?array $s = null, ?int $now = null ): array {
		$s   = $s ?? self::get();
		$now = $now ?? time();
		$out = [];
		if ( empty( $s['enabled'] ) || ! self::connected() ) {
			return $out;
		}
		$labels = self::labels();
		$from   = self::sender( $s );
		foreach ( self::lives( $s, $now ) as $live ) {
			foreach ( $s['reminders'] as $rid => $r ) {
				if ( empty( $r['on'] ) ) {
					continue;
				}
				$send = (int) $live['ts'] - 60 * (int) $r['before'];
				foreach ( [ 'email', 'sms' ] as $ch ) {
					$to = 'email' === $ch ? $s['email_to'] : $s['sms_to'];
					if ( empty( $r[ $ch ] ) || ! $to ) {
						continue;
					}
					$m = [
						'key'      => $live['ts'] . '|' . $rid . '|' . $ch,
						'live'     => $live,
						'reminder' => $rid,
						'channel'  => $ch,
						'send'     => $send,
						'late'     => $send < $now + self::LEAD,
						'audience' => [ 'included' => array_values( $to ), 'excluded' => array_values( array_diff( $s['exclude'], $to ) ) ],
						'name'     => sprintf( '%1$s · %2$s · %3$s · %4$s', __( 'Live reminder', 'whd' ), wp_date( 'D j M, g:i a', (int) $live['ts'] ), $labels[ $rid ], 'email' === $ch ? __( 'Email', 'whd' ) : __( 'SMS', 'whd' ) ),
					];
					if ( 'email' === $ch ) {
						$m['content'] = self::email_parts( $rid, $live, $s ) + [ 'from_email' => $from['email'], 'from_label' => $from['label'], 'reply_to' => $s['reply_to'] ];
					} else {
						$m['content'] = [ 'body' => self::sms_body( $rid, $live, $s ), 'prefix' => (int) $s['sms_prefix'], 'opt_out' => (int) $s['sms_opt_out'] ];
					}
					$m['sig'] = md5( wp_json_encode( [ $m['send'], $m['channel'], $m['audience'], $m['content'], $m['name'] ] ) );
					$out[ $m['key'] ] = $m;
				}
			}
		}
		return $out;
	}

	public static function state(): array {
		$st = get_option( self::STATE, [] );
		return is_array( $st ) ? $st : [];
	}

	/** True when Klaviyo holds something other than what the settings ask for. */
	public static function needs_sync( ?int $now = null ): bool {
		$now   = $now ?? time();
		$plan  = self::plan( null, $now );
		$state = self::state();
		foreach ( $plan as $key => $m ) {
			if ( $m['late'] ) {
				continue;
			}
			$e = $state[ $key ] ?? null;
			if ( ! $e || ( 'scheduled' === $e['status'] && $e['sig'] !== $m['sig'] ) || 'error' === $e['status'] ) {
				return true;
			}
		}
		foreach ( $state as $key => $e ) {
			if ( in_array( $e['status'], [ 'scheduled', 'stuck' ], true ) && ! isset( $plan[ $key ] ) && (int) $e['send'] > $now + self::LEAD ) {
				return true;
			}
		}
		return false;
	}

	/* ─────────────────────────── sync ─────────────────────────── */

	/**
	 * Make Klaviyo hold exactly what the plan asks for. Returns [ made, removed, errors, pending, busy ].
	 */
	public static function sync( string $why = '' ): array {
		$sum = [ 'made' => 0, 'removed' => 0, 'errors' => 0, 'pending' => false, 'busy' => false, 'why' => $why ];
		if ( get_transient( self::LOCK ) ) {
			$sum['busy'] = true;
			return $sum;
		}
		set_transient( self::LOCK, 1, 120 );
		$now      = time();
		$deadline = microtime( true ) + self::BUDGET;
		$plan     = self::plan( null, $now );
		$state    = self::state();

		// 1. Take back whatever no longer matches.
		foreach ( $state as $key => $e ) {
			if ( ! in_array( $e['status'], [ 'scheduled', 'error', 'stuck' ], true ) ) {
				continue;
			}
			$want = $plan[ $key ] ?? null;
			if ( 'scheduled' === $e['status'] && $want && $want['sig'] === $e['sig'] ) {
				continue;
			}
			if ( 'error' === $e['status'] ) {
				unset( $state[ $key ] ); // never reached Klaviyo whole: made again below if still wanted
				continue;
			}
			if ( (int) $e['send'] <= $now + self::LEAD ) {
				if ( 'scheduled' === $e['status'] ) {
					$state[ $key ]['status'] = 'sending'; // too close to change: it goes as it is
				}
				continue;
			}
			if ( microtime( true ) > $deadline ) {
				$sum['pending'] = true;
				break;
			}
			[ $ok, $err ] = self::withdraw( $e );
			if ( $ok ) {
				unset( $state[ $key ] );
				$sum['removed']++;
			} else {
				$state[ $key ]['status'] = 'stuck';
				$state[ $key ]['error']  = $err;
				$sum['errors']++;
			}
		}

		// 2. Schedule what is missing.
		if ( ! $sum['pending'] ) {
			foreach ( $plan as $key => $m ) {
				if ( isset( $state[ $key ] ) || $m['late'] ) {
					continue;
				}
				if ( microtime( true ) > $deadline ) {
					$sum['pending'] = true;
					break;
				}
				$state[ $key ] = self::schedule( $m );
				if ( 'scheduled' === $state[ $key ]['status'] ) {
					$sum['made']++;
				} else {
					$sum['errors']++;
				}
			}
		}

		// 3. Forget messages that went out more than a month ago.
		foreach ( $state as $key => $e ) {
			if ( (int) $e['send'] < $now - 30 * DAY_IN_SECONDS ) {
				unset( $state[ $key ] );
			}
		}
		update_option( self::STATE, $state, false );
		update_option( self::LAST, $sum + [ 'at' => $now ], false );
		if ( $sum['pending'] ) {
			wp_schedule_single_event( time() + 60, self::CRON );
		}
		if ( $sum['made'] || $sum['removed'] || $sum['errors'] ) {
			WHD_Integrations::log(
				'klaviyo',
				$sum['errors'] ? 400 : 200,
				sprintf( /* translators: 1: scheduled, 2: taken back, 3: failed */ __( 'live reminders — %1$d scheduled, %2$d taken back, %3$d failed', 'whd' ), $sum['made'], $sum['removed'], $sum['errors'] )
			);
		}
		delete_transient( self::LOCK );
		return $sum;
	}

	/** Create one message's campaign in Klaviyo and schedule it. Returns its state entry. */
	private static function schedule( array $m ): array {
		$e = [
			'key'      => $m['key'],
			'live'     => (int) $m['live']['ts'],
			'reminder' => $m['reminder'],
			'channel'  => $m['channel'],
			'send'     => (int) $m['send'],
			'sig'      => $m['sig'],
			'name'     => $m['name'],
			'status'   => 'error',
			'error'    => '',
			'campaign' => '',
			'message'  => '',
			'schedule' => '',
			'at'       => time(),
		];
		$c       = $m['content'];
		$label   = self::labels()[ $m['reminder'] ];
		$details = 'email' === $m['channel']
			? array_filter( [
				'channel'        => 'email',
				'subject'        => $c['subject'],
				'preview_text'   => $c['preview'],
				'from_email'     => $c['from_email'],
				'from_label'     => $c['from_label'],
				'reply_to_email' => $c['reply_to'],
			], static fn( $v ) => '' !== $v )
			: [
				'channel'              => 'sms',
				'body'                 => $c['body'],
				'text_message_type'    => 'SMS',
				'add_org_prefix'       => (bool) $c['prefix'],
				'add_opt_out_language' => (bool) $c['opt_out'],
				'add_info_link'        => false,
				'shorten_links'        => true,
			];
		[ $code, $data, $err ] = self::api( 'POST', 'campaigns', [
			'data' => [
				'type'       => 'campaign',
				'attributes' => [
					'definition' => [ 'name' => $m['name'] ],
					'audiences'  => [ [ 'temporary_id' => 'whd', 'included' => $m['audience']['included'], 'excluded' => $m['audience']['excluded'] ] ],
					'messages'   => [ [
						'audience_temporary_id' => 'whd',
						'name'                  => $label,
						'variations'            => [ [ 'definition' => [ 'name' => $label, 'details' => $details ] ] ],
					] ],
				],
			],
		] );
		if ( $err ) {
			$e['error'] = $err;
			return $e;
		}
		$e['campaign'] = (string) ( $data['data']['id'] ?? '' );
		$e['message']  = (string) ( $data['data']['relationships']['campaign-messages']['data'][0]['id'] ?? '' );
		if ( '' === $e['message'] && '' !== $e['campaign'] ) {
			[ , $msgs ] = self::api( 'GET', 'campaigns/' . rawurlencode( $e['campaign'] ) . '/campaign-messages' );
			$e['message'] = (string) ( $msgs['data'][0]['id'] ?? '' );
		}
		if ( '' === $e['campaign'] || '' === $e['message'] ) {
			$e['error'] = __( 'Klaviyo made the campaign but did not say which message is in it.', 'whd' );
			return self::undo( $e );
		}

		if ( 'email' === $m['channel'] ) {
			[ , $tpl, $err ] = self::api( 'POST', 'templates', [
				'data' => [
					'type'       => 'template',
					'attributes' => [ 'name' => 'WHD · ' . $m['name'], 'editor_type' => 'CODE', 'html' => $c['html'] ],
				],
			] );
			$template = (string) ( $tpl['data']['id'] ?? '' );
			if ( $err || '' === $template ) {
				$e['error'] = $err ?: __( 'Klaviyo did not accept the email’s template.', 'whd' );
				return self::undo( $e );
			}
			[ , , $err ] = self::api( 'POST', 'campaign-message-assign-template', [
				'data' => [
					'type'          => 'campaign-message',
					'id'            => $e['message'],
					'relationships' => [ 'template' => [ 'data' => [ 'type' => 'template', 'id' => $template ] ] ],
				],
			] );
			// The message now holds its own copy; the reusable one would only clutter the library.
			self::api( 'DELETE', 'templates/' . rawurlencode( $template ) );
			if ( $err ) {
				$e['error'] = $err;
				return self::undo( $e );
			}
		}

		[ , $sch, $err ] = self::api( 'POST', 'campaign-message-schedule', [
			'data' => [
				'type'          => 'campaign-message-schedule',
				'attributes'    => [ 'strategy' => 'static', 'send_time' => gmdate( 'Y-m-d\TH:i:s+00:00', (int) $m['send'] ) ],
				'relationships' => [ 'campaign-message' => [ 'data' => [ 'type' => 'campaign-message', 'id' => $e['message'] ] ] ],
			],
		] );
		if ( $err ) {
			$e['error'] = $err;
			return self::undo( $e );
		}
		$e['schedule'] = (string) ( $sch['data']['id'] ?? '' );
		$e['status']   = 'scheduled';
		return $e;
	}

	/** A campaign that could not be finished is deleted, so no half-made draft is left in Klaviyo. */
	private static function undo( array $e ): array {
		if ( '' !== $e['campaign'] ) {
			self::api( 'DELETE', 'campaigns/' . rawurlencode( $e['campaign'] ) );
			$e['campaign'] = '';
			$e['message']  = '';
		}
		$e['status'] = 'error';
		return $e;
	}

	/** Take a scheduled message back: unschedule it, then delete its campaign. Returns [ ok, error ]. */
	private static function withdraw( array $e ): array {
		$reverted = false;
		$last     = '';
		foreach ( array_unique( array_filter( [ (string) $e['schedule'], (string) $e['message'] ] ) ) as $id ) {
			[ $code, , $err ] = self::api( 'PATCH', 'campaign-message-schedule/' . rawurlencode( $id ), [
				'data' => [ 'type' => 'campaign-message-schedule', 'id' => $id, 'attributes' => [ 'action' => 'revert' ] ],
			] );
			if ( ! $err ) {
				$reverted = true;
				break;
			}
			$last = $err;
		}
		if ( '' === (string) $e['campaign'] ) {
			return [ true, '' ];
		}
		[ $code, , $err ] = self::api( 'DELETE', 'campaigns/' . rawurlencode( (string) $e['campaign'] ) );
		if ( ! $err || 404 === $code ) {
			return [ true, '' ];
		}
		return [ false, $err ?: $last ];
	}

	/** Ask Klaviyo how the messages that should have gone by now went. A few per page view. */
	public static function refresh( int $max = 8 ) {
		$state   = self::state();
		$changed = false;
		$now     = time();
		foreach ( $state as $key => $e ) {
			if ( $max <= 0 ) {
				break;
			}
			if ( '' === (string) $e['message'] || ! in_array( $e['status'], [ 'scheduled', 'sending' ], true ) || (int) $e['send'] > $now ) {
				continue;
			}
			$max--;
			[ $code, $data, $err ] = self::api( 'GET', 'campaign-messages/' . rawurlencode( (string) $e['message'] ) . '?fields[campaign-message]=definition' );
			if ( 404 === $code ) {
				$state[ $key ]['status'] = 'gone';
				$changed                 = true;
				continue;
			}
			$st = strtolower( (string) ( $data['data']['attributes']['definition']['status'] ?? $data['data']['attributes']['status'] ?? '' ) );
			if ( in_array( $st, [ 'sent', 'sending', 'cancelled' ], true ) && $st !== $e['status'] ) {
				$state[ $key ]['status'] = $st;
				$changed                 = true;
			}
		}
		if ( $changed ) {
			update_option( self::STATE, $state, false );
		}
	}

	/** Hourly: roll a weekly live forward, and sync when Klaviyo is behind the settings. */
	public static function tick() {
		$s = self::get();
		if ( 'manual' === $s['source'] && 'weekly' === $s['repeat'] && '' !== $s['when'] ) {
			$lives = self::lives( $s );
			if ( $lives ) {
				$next = wp_date( 'Y-m-d\TH:i', $lives[0]['ts'] );
				if ( $next !== $s['when'] ) {
					$s['when'] = $next;
					update_option( self::OPTION, $s, false );
					self::push_to_theme( $s );
				}
			}
		}
		if ( self::needs_sync() ) {
			self::sync( 'tick' );
		}
	}

	/* ─────────────────────────── a test through Klaviyo ─────────────────────────── */

	/**
	 * Send one reminder's email to the signed-in admin through Klaviyo itself (a template preview job),
	 * so what arrives is what Klaviyo will send — its tags filled in, its unsubscribe link, its sender.
	 */
	public static function test_email( string $rid ): array {
		$s = self::get();
		if ( ! isset( self::DESIGNS[ $rid ] ) ) {
			return [ false, __( 'Unknown reminder.', 'whd' ) ];
		}
		$live  = self::lives( $s )[0] ?? [ 'ts' => time() + DAY_IN_SECONDS, 'title' => self::title( $s ), 'url' => self::watch_url( $s ) ];
		$parts = self::email_parts( $rid, $live, $s );
		$user  = wp_get_current_user();
		$name  = 'WHD · Live reminder test';
		$saved = (string) get_option( 'whd_live_reminders_test_tpl', '' );
		$err   = '';
		if ( '' !== $saved ) {
			[ $code, , $err ] = self::api( 'PATCH', 'templates/' . rawurlencode( $saved ), [ 'data' => [ 'type' => 'template', 'id' => $saved, 'attributes' => [ 'name' => $name, 'html' => $parts['html'] ] ] ] );
			if ( $err ) {
				$saved = '';
			}
		}
		if ( '' === $saved ) {
			[ , $tpl, $err ] = self::api( 'POST', 'templates', [ 'data' => [ 'type' => 'template', 'attributes' => [ 'name' => $name, 'editor_type' => 'CODE', 'html' => $parts['html'] ] ] ] );
			$saved           = (string) ( $tpl['data']['id'] ?? '' );
			if ( $err || '' === $saved ) {
				return [ false, $err ?: __( 'Klaviyo did not accept the template.', 'whd' ) ];
			}
			update_option( 'whd_live_reminders_test_tpl', $saved, false );
		}
		[ , , $err ] = self::api( 'POST', 'template-preview-send-jobs', [
			'data' => [
				'type'          => 'template-preview-send-job',
				'attributes'    => [ 'recipients' => [ $user->user_email ], 'context' => [ 'first_name' => $user->first_name ?: $user->display_name ] ],
				'relationships' => [ 'template' => [ 'data' => [ 'type' => 'template', 'id' => $saved ] ] ],
			],
		] );
		if ( $err ) {
			return [ false, $err ];
		}
		/* translators: %s: email address */
		return [ true, sprintf( __( 'Sent through Klaviyo to %s. It can take a minute to arrive.', 'whd' ), $user->user_email ) ];
	}

	/* ─────────────────────────── the site: "text me too" ─────────────────────────── */

	/** Is the optional mobile-number step offered after an email sign-up on the live card? */
	public static function sms_step_ready( ?array $s = null ): bool {
		$s = $s ?? self::get();
		if ( empty( $s['enabled'] ) || empty( $s['ask_phone'] ) || ! $s['sms_to'] || ! self::connected() ) {
			return false;
		}
		foreach ( $s['reminders'] as $r ) {
			if ( ! empty( $r['on'] ) && ! empty( $r['sms'] ) ) {
				return true;
			}
		}
		return false;
	}

	/** The published Mobile Terms page, or ''. */
	public static function terms_url(): string {
		$page = get_page_by_path( 'mobile-terms' );
		return $page && 'publish' === $page->post_status ? (string) get_permalink( $page ) : '';
	}

	public static function privacy_url(): string {
		$url = (string) get_privacy_policy_url();
		if ( '' === $url ) {
			$page = get_page_by_path( 'privacy-policy' );
			$url  = $page && 'publish' === $page->post_status ? (string) get_permalink( $page ) : '';
		}
		return $url;
	}

	/** Who the texts are from: typed here, else the organisation name in Klaviyo, else the shop's name. */
	public static function company( ?array $s = null ): string {
		$s    = $s ?? self::get();
		$acct = self::account();
		return '' !== $s['company'] ? $s['company'] : ( ! empty( $acct['org'] ) ? $acct['org'] : wp_strip_all_tags( get_bloginfo( 'name' ) ) );
	}

	/**
	 * The disclosure shown above the consent box, as Klaviyo and the carriers ask: who, what kind of
	 * messages, autodialer, not a condition of purchase, rates, frequency, HELP/STOP, both links.
	 */
	public static function disclosure( ?array $s = null ): string {
		$terms   = self::terms_url();
		$privacy = self::privacy_url();
		$links   = [];
		if ( '' !== $terms ) {
			$links[] = '<a href="' . esc_url( $terms ) . '" target="_blank" rel="noopener">' . esc_html__( 'Mobile Terms', 'whd' ) . '</a>';
		}
		if ( '' !== $privacy ) {
			$links[] = '<a href="' . esc_url( $privacy ) . '" target="_blank" rel="noopener">' . esc_html__( 'Privacy Policy', 'whd' ) . '</a>';
		}
		$text = sprintf(
			/* translators: %s: company name */
			esc_html__( 'By ticking the box and tapping “Text me”, you agree to receive recurring automated marketing text messages (such as live-stream reminders and offers) from %s at the number above, including messages sent by autodialer. Consent is not a condition of purchase. Msg & data rates may apply. Msg frequency varies. Reply HELP for help or STOP to cancel.', 'whd' ),
			esc_html( self::company( $s ) )
		);
		if ( $links ) {
			/* translators: %s: links to the Mobile Terms and the Privacy Policy */
			$text .= ' ' . sprintf( esc_html__( 'See our %s.', 'whd' ), implode( ' ' . esc_html__( 'and', 'whd' ) . ' ', $links ) );
		}
		return $text;
	}

	/** A short-lived proof that this browser just signed up with this address. */
	private static function token( string $email, ?int $ts = null ): string {
		$ts = $ts ?? time();
		return rtrim( strtr( base64_encode( $email ), '+/', '-_' ), '=' ) . '.' . $ts . '.' . substr( hash_hmac( 'sha256', $email . '|' . $ts, wp_salt( 'nonce' ) ), 0, 24 );
	}

	private static function token_email( string $token ): string {
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			return '';
		}
		$email = (string) base64_decode( strtr( $parts[0], '-_', '+/' ), true );
		$ts    = (int) $parts[1];
		if ( ! is_email( $email ) || $ts < time() - self::TOKEN_TTL || $ts > time() + 60 ) {
			return '';
		}
		return hash_equals( self::token( $email, $ts ), $token ) ? $email : '';
	}

	/** The theme's sign-up answer: a live-card sign-up gets the token the next step needs. */
	public static function subscribe_response( $data, $email = '', $source = '' ) {
		if ( is_array( $data ) && 'fb_live' === $source && is_email( $email ) && self::sms_step_ready() ) {
			$data['whd_sms'] = self::token( strtolower( (string) $email ) );
		}
		return $data;
	}

	/** The step itself, printed once as a <template> that assets/live-sms.js places under the form that succeeded. */
	public static function sms_step() {
		if ( is_admin() || ! self::sms_step_ready() ) {
			return;
		}
		wp_enqueue_style( 'whd-live-sms', WHD_URL . 'assets/live-sms.css', [], WHD_VERSION );
		wp_enqueue_script( 'whd-live-sms', WHD_URL . 'assets/live-sms.js', [], WHD_VERSION, true );
		wp_localize_script( 'whd-live-sms', 'WHD_LIVE_SMS', [
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'i18n'  => [
				'phone'   => __( 'Please enter a mobile number we can text.', 'whd' ),
				'consent' => __( 'Tick the box to say yes to texts.', 'whd' ),
				'wait'    => __( 'One moment…', 'whd' ),
				'error'   => __( 'Something went wrong. Please try again.', 'whd' ),
			],
		] );
		?>
		<template id="whd-live-sms-tpl">
			<form class="whd-live-sms" novalidate>
				<p class="whd-live-sms__head"><?php esc_html_e( 'Want a text when we go live too?', 'whd' ); ?></p>
				<label class="whd-live-sms__label" data-for="phone"><?php esc_html_e( 'Mobile number (optional)', 'whd' ); ?></label>
				<input class="whd-live-sms__input" data-id="phone" type="tel" name="phone" inputmode="tel" autocomplete="tel" placeholder="(555) 555-0123">
				<p class="whd-live-sms__fine"><?php echo wp_kses( self::disclosure(), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ); ?></p>
				<label class="whd-live-sms__consent"><input type="checkbox" name="consent" value="1"> <span><?php esc_html_e( 'Yes, text me', 'whd' ); ?></span></label>
				<div class="whd-live-sms__actions">
					<button type="submit" class="whd-live-sms__go"><?php esc_html_e( 'Text me', 'whd' ); ?></button>
					<button type="button" class="whd-live-sms__no" data-no><?php esc_html_e( 'No thanks', 'whd' ); ?></button>
				</div>
				<p class="whd-live-sms__note" role="status" aria-live="polite"></p>
			</form>
		</template>
		<?php
	}

	/*
	 * No nonce: the token is the proof (an HMAC of this address and the minute of the sign-up, good for
	 * half an hour), and a nonce baked into a cached page would turn away every visitor after it expired.
	 */
	public static function ajax_sms() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised one by one below
		$email   = self::token_email( sanitize_text_field( wp_unslash( (string) ( $_POST['token'] ?? '' ) ) ) );
		$phone   = WHD_Integrations::normalize_phone( sanitize_text_field( wp_unslash( (string) ( $_POST['phone'] ?? '' ) ) ) );
		$consent = ! empty( $_POST['consent'] ) && '0' !== (string) $_POST['consent'];
		// phpcs:enable
		if ( ! self::sms_step_ready() ) {
			wp_send_json_error( [ 'message' => __( 'Texts are not on right now.', 'whd' ) ], 400 );
		}
		if ( '' === $email ) {
			wp_send_json_error( [ 'message' => __( 'That took a while — please leave your email again first.', 'whd' ) ], 400 );
		}
		if ( '' === $phone || strlen( preg_replace( '/\D+/', '', $phone ) ) < 10 ) {
			wp_send_json_error( [ 'message' => __( 'Please enter a mobile number we can text.', 'whd' ) ], 400 );
		}
		if ( ! $consent ) {
			wp_send_json_error( [ 'message' => __( 'Tick the box to say yes to texts.', 'whd' ) ], 400 );
		}
		// A handful per address per quarter hour: a phone field is the kind a script fills in.
		$ip    = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$count = 'whd_lsms_' . md5( $ip );
		$tries = (int) get_transient( $count );
		if ( $tries >= 5 ) {
			wp_send_json_error( [ 'message' => __( 'Too many tries. Please wait a few minutes.', 'whd' ) ], 429 );
		}
		set_transient( $count, $tries + 1, 15 * MINUTE_IN_SECONDS );

		WHD_Subscribers::add( $email, '', 'fb_live_sms', $phone, 1 );
		wp_send_json_success( [ 'message' => __( 'Done — we’ll text you when we go live.', 'whd' ) ] );
	}

	/* ─────────────────────────── admin ─────────────────────────── */

	public static function menu() {
		/*
		 * Right under WHD. Its position number is 58, but WordPress nudges a menu whose number is taken
		 * (WooCommerce's Marketing has 58) by a few hundred-thousandths, so ours is read off WHD's own key
		 * rather than guessed — that touches nobody else's place in the menu.
		 */
		global $menu;
		$position = 58;
		foreach ( (array) $menu as $key => $item ) {
			if ( 'whd' === ( $item[2] ?? '' ) ) {
				$position = (string) round( (float) $key + 0.000001, 6 );
				break;
			}
		}
		add_menu_page(
			__( 'Live reminders', 'whd' ),
			__( 'Live reminders', 'whd' ),
			'manage_options',
			self::PAGE,
			[ __CLASS__, 'page' ],
			'dashicons-bell',
			$position
		);
	}

	private static function on_page(): bool {
		return is_admin() && isset( $_GET['page'] ) && self::PAGE === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public static function admin_assets() {
		if ( ! self::on_page() ) {
			return;
		}
		$s = self::get();
		wp_enqueue_style( 'whd-live-reminders', WHD_URL . 'admin/live-reminders.css', [], WHD_VERSION );
		wp_enqueue_script( 'whd-live-reminders', WHD_URL . 'admin/live-reminders.js', [], WHD_VERSION, true );
		$streams = [];
		foreach ( self::streams() as $id => $st ) {
			$streams[ $id ] = [ 'title' => $st['title'], 'ts' => $st['ts'], 'url' => WHD_Live::page_url( $id ) ];
		}
		wp_localize_script( 'whd-live-reminders', 'WHD_LR', [
			'rest'     => [ 'root' => esc_url_raw( rest_url( 'whd/v1/live-reminders/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ],
			'tzOffset' => (int) ( wp_timezone()->getOffset( new DateTimeImmutable( 'now' ) ) ),
			'tz'       => wp_timezone_string(),
			'streams'  => $streams,
			'company'  => self::company( $s ),
			'sample'   => [ 'live_title' => self::title( $s ), 'live_url' => self::watch_url( $s ), 'site_name' => wp_strip_all_tags( get_bloginfo( 'name' ) ) ],
			'i18n'     => [
				'sends'     => __( 'Sends %s', 'whd' ),
				'past'      => __( 'Already past — this one will not go', 'whd' ),
				'tooSoon'   => __( 'Less than 10 minutes away — too late to schedule', 'whd' ),
				'quiet'     => __( 'Texts before 8 am or after 9 pm can break state rules. Pick another time.', 'whd' ),
				'noDate'    => __( 'Set the date of the live first', 'whd' ),
				'chars'     => __( '%1$d characters · %2$d text message(s)', 'whd' ),
				'unicode'   => __( 'Has a character outside plain text (an emoji or a curly quote), so each message holds 70 characters instead of 160.', 'whd' ),
				'testing'   => __( 'Sending…', 'whd' ),
				'saving'    => __( 'Scheduling in Klaviyo…', 'whd' ),
				'error'     => __( 'Something went wrong', 'whd' ),
			],
		] );
	}

	public static function rest() {
		$perm = static fn() => current_user_can( 'manage_options' );
		register_rest_route( 'whd/v1', '/live-reminders/test', [
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => static function ( WP_REST_Request $r ) {
				[ $ok, $msg ] = self::test_email( sanitize_key( (string) $r->get_param( 'reminder' ) ) );
				return [ 'ok' => $ok, 'message' => $msg ];
			},
		] );
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'whd' ) );
		}
		check_admin_referer( 'whd_live_reminders_save' );
		$in = isset( $_POST['lr'] ) && is_array( $_POST['lr'] ) ? wp_unslash( $_POST['lr'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitize() below
		$s  = self::sanitize( $in );
		update_option( self::OPTION, $s, false );
		self::push_to_theme( $s );
		self::account( true );
		$sum = self::sync( 'saved' );
		set_transient( 'whd_lr_notice_' . get_current_user_id(), $sum, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&saved=1' ) );
		exit;
	}

	public static function handle_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'whd' ) );
		}
		check_admin_referer( 'whd_live_reminders_sync' );
		delete_transient( self::LISTS );
		$sum = self::sync( 'again' ); // failed ones are made again, stuck ones taken back again
		set_transient( 'whd_lr_notice_' . get_current_user_id(), $sum, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&saved=1' ) );
		exit;
	}

	/** A reminder's email as it will look (with "there" for the first name), in a tab of its own. */
	public static function handle_preview() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'whd' ) );
		}
		$rid = sanitize_key( wp_unslash( (string) ( $_GET['r'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview for an admin
		if ( ! isset( self::DESIGNS[ $rid ] ) ) {
			wp_die( esc_html__( 'Unknown reminder.', 'whd' ) );
		}
		$s    = self::get();
		$live = self::lives( $s )[0] ?? [ 'ts' => time() + DAY_IN_SECONDS, 'title' => self::title( $s ), 'url' => self::watch_url( $s ) ];
		header( 'Content-Type: text/html; charset=utf-8' );
		echo WHD_Blocks::render( WHD_Emails::get( self::DESIGNS[ $rid ] ), 'email', self::email_context( $rid, $live, $s, false ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- the email renderer escapes every value
		exit;
	}

	/** Sample values for the editor's live preview and test email of a live design. */
	public static function preview_tags( string $design ): array {
		$rid  = (string) array_search( $design, self::DESIGNS, true );
		$s    = self::get();
		$live = self::lives( $s )[0] ?? [ 'ts' => time() + DAY_IN_SECONDS, 'title' => self::title( $s ), 'url' => self::watch_url( $s ) ];
		return self::live_tags( $live, (int) ( $s['reminders'][ $rid ]['before'] ?? 60 ) );
	}

	/* ─────────────────────────── the screen ─────────────────────────── */

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s         = self::get();
		$connected = self::connected();
		if ( $connected ) {
			self::refresh();
		}
		$aud     = $connected ? self::audiences( isset( $_GET['lists'] ) ) : [ 'lists' => [], 'segments' => [], 'error' => '' ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$lives   = self::lives( $s );
		$plan    = self::plan( $s );
		$state   = self::state();
		$labels  = self::labels();
		$streams = self::streams();
		$f       = static fn( $k ) => 'lr[' . $k . ']';
		$notice  = get_transient( 'whd_lr_notice_' . get_current_user_id() );
		delete_transient( 'whd_lr_notice_' . get_current_user_id() );
		$last    = get_option( self::LAST, [] );

		echo '<div class="wrap whd-wrap whd-lr">';
		echo '<p class="whd-lr__kicker">' . esc_html__( 'WHD · Klaviyo', 'whd' ) . '</p>';
		echo '<h1 class="whd-title">' . esc_html__( 'Live reminders', 'whd' ) . '</h1>';
		echo '<p class="whd-intro">' . esc_html__( 'Tell your Klaviyo subscribers when you go live, by email and by text. Set the date once: each reminder becomes a campaign scheduled in Klaviyo, which sends it on time to the people who agreed to hear from you — emails to your email subscribers, texts only to the ones who said yes to texts.', 'whd' ) . '</p>';

		if ( is_array( $notice ) ) {
			$bad = ! empty( $notice['errors'] ) || ! empty( $notice['busy'] );
			echo '<div class="notice notice-' . ( $bad ? 'warning' : 'success' ) . ' is-dismissible"><p>';
			if ( ! empty( $notice['busy'] ) ) {
				esc_html_e( 'Saved. Another sync was still running; the reminders will catch up in a minute.', 'whd' );
			} else {
				echo esc_html( sprintf( /* translators: 1: scheduled, 2: taken back, 3: failed */ __( 'Saved. In Klaviyo: %1$d scheduled, %2$d taken back, %3$d failed.', 'whd' ), (int) $notice['made'], (int) $notice['removed'], (int) $notice['errors'] ) );
				if ( ! empty( $notice['pending'] ) ) {
					echo ' ' . esc_html__( 'A few more are being finished in the background.', 'whd' );
				}
			}
			echo '</p></div>';
		}

		if ( ! $connected ) {
			echo '<div class="whd-note whd-note--warn"><strong>' . esc_html__( 'Klaviyo is not connected.', 'whd' ) . '</strong> ' . sprintf(
				/* translators: %s: link to WHD → Integrations */
				esc_html__( 'Pick Klaviyo and add its private API key in %s. The key needs these scopes: campaigns (read and write), templates (read and write), lists (read) and segments (read).', 'whd' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=whd-integrations' ) ) . '">' . esc_html__( 'WHD → Integrations', 'whd' ) . '</a>'
			) . '</div>';
		} elseif ( $aud['error'] ) {
			echo '<div class="whd-note whd-note--warn">' . esc_html__( 'Klaviyo did not list your audiences:', 'whd' ) . ' ' . esc_html( $aud['error'] ) . '</div>';
		}

		// ── summary strip
		echo '<div class="whd-lr__strip">';
		echo '<div class="whd-lr__next"><span class="whd-lr__label">' . esc_html__( 'Next live', 'whd' ) . '</span>';
		if ( $lives ) {
			echo '<strong>' . esc_html( wp_date( 'l, F j · g:i a T', $lives[0]['ts'] ) ) . '</strong><span class="whd-lr__sub">' . esc_html( $lives[0]['title'] ) . ' · ' . esc_html( human_time_diff( time(), $lives[0]['ts'] ) ) . '</span>';
		} else {
			echo '<strong>' . esc_html__( 'No date yet', 'whd' ) . '</strong><span class="whd-lr__sub">' . esc_html__( 'Set it below.', 'whd' ) . '</span>';
		}
		echo '</div>';
		$scheduled = count( array_filter( $state, static fn( $e ) => 'scheduled' === $e['status'] ) );
		echo '<div class="whd-lr__count"><span class="whd-lr__label">' . esc_html__( 'Waiting in Klaviyo', 'whd' ) . '</span><strong>' . (int) $scheduled . '</strong><span class="whd-lr__sub">' . esc_html( _n( 'reminder scheduled', 'reminders scheduled', $scheduled, 'whd' ) ) . '</span></div>';
		echo '<div class="whd-lr__state"><span class="whd-lr__label">' . esc_html__( 'Reminders', 'whd' ) . '</span><strong class="' . ( $s['enabled'] ? 'is-on' : 'is-off' ) . '">' . ( $s['enabled'] ? esc_html__( 'On', 'whd' ) : esc_html__( 'Off', 'whd' ) ) . '</strong><span class="whd-lr__sub">' . ( ! empty( $last['at'] ) ? esc_html( sprintf( /* translators: %s: time ago */ __( 'last synced %s ago', 'whd' ), human_time_diff( (int) $last['at'], time() ) ) ) : esc_html__( 'not synced yet', 'whd' ) ) . '</span></div>';
		echo '</div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="whd-lr__form" id="whd-lr-form">';
		wp_nonce_field( 'whd_live_reminders_save' );
		echo '<input type="hidden" name="action" value="whd_live_reminders_save">';

		// ── on / off
		echo '<section class="whd-lr__card whd-lr__card--switch"><label class="whd-lr__switch"><input type="checkbox" name="' . esc_attr( $f( 'enabled' ) ) . '" value="1"' . checked( $s['enabled'], 1, false ) . ' id="whd-lr-enabled"><span class="whd-lr__track" aria-hidden="true"></span><span><strong>' . esc_html__( 'Send live reminders through Klaviyo', 'whd' ) . '</strong><small>' . esc_html__( 'Off takes back every reminder that has not gone out yet.', 'whd' ) . '</small></span></label></section>';

		// ── 1. the live
		echo '<section class="whd-lr__card"><h2 class="whd-lr__h"><span class="whd-lr__n">1</span>' . esc_html__( 'The live', 'whd' ) . '</h2>';
		echo '<div class="whd-lr__seg" role="radiogroup">';
		echo '<label><input type="radio" name="' . esc_attr( $f( 'source' ) ) . '" value="manual"' . checked( $s['source'], 'manual', false ) . '><span>' . esc_html__( 'On Facebook or elsewhere', 'whd' ) . '</span></label>';
		echo '<label><input type="radio" name="' . esc_attr( $f( 'source' ) ) . '" value="stream"' . checked( $s['source'], 'stream', false ) . ( $streams ? '' : ' disabled' ) . '><span>' . esc_html__( 'A stream from WHD → Live Stream', 'whd' ) . '</span></label>';
		echo '</div>';
		echo '<div class="whd-lr__grid" data-source="manual">';
		echo '<label class="whd-lr__field"><span>' . esc_html__( 'Date and time', 'whd' ) . '</span><input type="datetime-local" id="whd-lr-when" name="' . esc_attr( $f( 'when' ) ) . '" value="' . esc_attr( $s['when'] ) . '"><small>' . esc_html( sprintf( /* translators: %s: timezone */ __( 'Shop time: %s. The home page’s live card counts down to the same date.', 'whd' ), wp_timezone_string() ) ) . '</small></label>';
		echo '<label class="whd-lr__field"><span>' . esc_html__( 'Repeats', 'whd' ) . '</span><select id="whd-lr-repeat" name="' . esc_attr( $f( 'repeat' ) ) . '"><option value="none"' . selected( $s['repeat'], 'none', false ) . '>' . esc_html__( 'Just this once', 'whd' ) . '</option><option value="weekly"' . selected( $s['repeat'], 'weekly', false ) . '>' . esc_html__( 'Every week, same day and time', 'whd' ) . '</option></select><small>' . esc_html__( 'Every week keeps the next two lives scheduled and moves on by itself.', 'whd' ) . '</small></label>';
		echo '</div>';
		echo '<div class="whd-lr__grid" data-source="stream">';
		echo '<label class="whd-lr__field"><span>' . esc_html__( 'Stream', 'whd' ) . '</span><select id="whd-lr-stream" name="' . esc_attr( $f( 'stream' ) ) . '">';
		if ( ! $streams ) {
			echo '<option value="0">' . esc_html__( 'No stream with a date yet', 'whd' ) . '</option>';
		}
		foreach ( $streams as $id => $st ) {
			echo '<option value="' . (int) $id . '"' . selected( $s['stream'], $id, false ) . '>' . esc_html( $st['title'] . ' — ' . wp_date( 'D j M, g:i a', $st['ts'] ) ) . '</option>';
		}
		echo '</select><small>' . esc_html__( 'Its date and its page come from the stream; change the date there and the reminders follow.', 'whd' ) . '</small></label>';
		echo '</div>';
		echo '<div class="whd-lr__grid">';
		echo '<label class="whd-lr__field"><span>' . esc_html__( 'Name of the live', 'whd' ) . ' <em>' . esc_html__( 'optional', 'whd' ) . '</em></span><input type="text" id="whd-lr-title" name="' . esc_attr( $f( 'title' ) ) . '" value="' . esc_attr( $s['title'] ) . '" placeholder="' . esc_attr( self::title( array_merge( $s, [ 'title' => '' ] ) ) ) . '"><small>' . esc_html__( 'Fills {live_title} in the emails and texts.', 'whd' ) . '</small></label>';
		echo '<label class="whd-lr__field"><span>' . esc_html__( 'Where people watch', 'whd' ) . ' <em>' . esc_html__( 'optional', 'whd' ) . '</em></span><input type="url" id="whd-lr-url" name="' . esc_attr( $f( 'url' ) ) . '" value="' . esc_attr( $s['url'] ) . '" placeholder="' . esc_attr( self::watch_url( array_merge( $s, [ 'url' => '' ] ) ) ) . '"><small>' . esc_html__( 'Your Facebook page or the live’s own link. Left empty: the home page card’s link, or the stream’s page.', 'whd' ) . '</small></label>';
		echo '</div></section>';

		// ── 2. reminders
		echo '<section class="whd-lr__card"><h2 class="whd-lr__h"><span class="whd-lr__n">2</span>' . esc_html__( 'Reminders', 'whd' ) . '</h2>';
		echo '<p class="whd-lr__lead">' . esc_html__( 'Up to three. Each can go by email, by text, or both. Emails are designed in the drag-and-drop editor; texts are written here.', 'whd' ) . '</p>';
		echo '<div class="whd-lr__reminders">';
		foreach ( $s['reminders'] as $rid => $r ) {
			$design = WHD_Emails::get( self::DESIGNS[ $rid ] );
			$name   = 'reminders][' . $rid;
			echo '<article class="whd-lr__rem' . ( $r['on'] ? '' : ' is-off' ) . '" data-reminder="' . esc_attr( $rid ) . '">';
			echo '<header class="whd-lr__rem-head"><label class="whd-lr__switch whd-lr__switch--small"><input type="checkbox" name="' . esc_attr( $f( $name . '][on' ) ) . '" value="1"' . checked( $r['on'], 1, false ) . ' data-on><span class="whd-lr__track" aria-hidden="true"></span><strong>' . esc_html( $labels[ $rid ] ) . '</strong></label>';
			echo '<select name="' . esc_attr( $f( $name . '][before' ) ) . '" data-before aria-label="' . esc_attr__( 'When it goes', 'whd' ) . '">';
			foreach ( self::befores() as $min => $lbl ) {
				echo '<option value="' . (int) $min . '"' . selected( (int) $r['before'], $min, false ) . '>' . esc_html( $lbl ) . '</option>';
			}
			echo '</select></header>';
			echo '<p class="whd-lr__when" data-when></p>';
			echo '<div class="whd-lr__channels">';
			echo '<label class="whd-lr__chip"><input type="checkbox" name="' . esc_attr( $f( $name . '][email' ) ) . '" value="1"' . checked( $r['email'], 1, false ) . ' data-email><span>' . esc_html__( 'Email', 'whd' ) . '</span></label>';
			echo '<label class="whd-lr__chip"><input type="checkbox" name="' . esc_attr( $f( $name . '][sms' ) ) . '" value="1"' . checked( $r['sms'], 1, false ) . ' data-sms><span>' . esc_html__( 'Text (SMS)', 'whd' ) . '</span></label>';
			echo '</div>';
			echo '<div class="whd-lr__email" data-email-box>';
			echo '<p class="whd-lr__subject"><span>' . esc_html__( 'Subject', 'whd' ) . '</span>' . esc_html( $design['settings']['subject'] ) . '</p>';
			echo '<p class="whd-lr__btns"><a class="button" href="' . esc_url( admin_url( 'admin.php?page=whd-editor&type=email&id=' . self::DESIGNS[ $rid ] ) ) . '">' . esc_html__( 'Design the email', 'whd' ) . '</a> ';
			echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url( admin_url( 'admin-post.php?action=whd_live_reminders_preview&r=' . $rid ) ) . '">' . esc_html__( 'Preview', 'whd' ) . '</a> ';
			echo '<button type="button" class="button" data-test="' . esc_attr( $rid ) . '"' . ( $connected ? '' : ' disabled' ) . '>' . esc_html__( 'Send me a test via Klaviyo', 'whd' ) . '</button></p>';
			echo '<p class="whd-lr__test" data-test-note role="status"></p>';
			echo '</div>';
			echo '<div class="whd-lr__sms" data-sms-box>';
			echo '<label class="whd-lr__field"><span>' . esc_html__( 'Text message', 'whd' ) . '</span><textarea rows="3" name="' . esc_attr( $f( $name . '][text' ) ) . '" data-text>' . esc_textarea( $r['text'] ) . '</textarea></label>';
			echo '<p class="whd-lr__tags">';
			foreach ( [ '{live_time}', '{live_date}', '{live_url}', '{live_title}', '{live_starts_in}', '{first_name}' ] as $tag ) {
				echo '<button type="button" class="whd-lr__tag" data-tag="' . esc_attr( $tag ) . '">' . esc_html( $tag ) . '</button>';
			}
			echo '</p><p class="whd-lr__count" data-count></p>';
			echo '<div class="whd-lr__phone" aria-hidden="true"><div class="whd-lr__bubble" data-bubble></div></div>';
			echo '<p class="whd-lr__warn" data-quiet hidden></p>';
			echo '</div>';
			echo '</article>';
		}
		echo '</div></section>';

		// ── 3. who gets them
		echo '<section class="whd-lr__card"><h2 class="whd-lr__h"><span class="whd-lr__n">3</span>' . esc_html__( 'Who gets them', 'whd' ) . '</h2>';
		echo '<p class="whd-lr__lead">' . esc_html__( 'Your Klaviyo lists and segments. Klaviyo emails only the people on them who can receive email marketing, and texts only the ones who agreed to texts — nobody else, whatever you tick here.', 'whd' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&lists=1' ) ) . '">' . esc_html__( 'Refresh the lists', 'whd' ) . '</a></p>';
		echo '<div class="whd-lr__aud">';
		foreach ( [ 'email_to' => __( 'Emails go to', 'whd' ), 'sms_to' => __( 'Texts go to', 'whd' ), 'exclude' => __( 'Leave out', 'whd' ) ] as $key => $title ) {
			echo '<fieldset class="whd-lr__pick"><legend>' . esc_html( $title ) . '</legend>';
			$any = false;
			foreach ( $aud['lists'] as $id => $l ) {
				$any = true;
				echo '<label><input type="checkbox" name="' . esc_attr( $f( $key ) ) . '[]" value="' . esc_attr( $id ) . '"' . checked( in_array( (string) $id, $s[ $key ], true ), true, false ) . '><span>' . esc_html( $l['name'] ) . '</span><small>' . esc_html( 'double_opt_in' === $l['optin'] ? __( 'list · double opt-in', 'whd' ) : __( 'list', 'whd' ) ) . '</small></label>';
			}
			foreach ( $aud['segments'] as $id => $name ) {
				$any = true;
				echo '<label><input type="checkbox" name="' . esc_attr( $f( $key ) ) . '[]" value="' . esc_attr( $id ) . '"' . checked( in_array( (string) $id, $s[ $key ], true ), true, false ) . '><span>' . esc_html( $name ) . '</span><small>' . esc_html__( 'segment', 'whd' ) . '</small></label>';
			}
			// Keep ids Klaviyo did not list this time, so a hiccup never empties the audience.
			foreach ( $s[ $key ] as $id ) {
				if ( ! isset( $aud['lists'][ $id ] ) && ! isset( $aud['segments'][ $id ] ) ) {
					$any = true;
					echo '<label><input type="checkbox" name="' . esc_attr( $f( $key ) ) . '[]" value="' . esc_attr( $id ) . '" checked><span>' . esc_html( $id ) . '</span><small>' . esc_html__( 'not listed by Klaviyo', 'whd' ) . '</small></label>';
				}
			}
			if ( ! $any ) {
				echo '<p class="whd-muted">' . esc_html__( 'Nothing to pick yet.', 'whd' ) . '</p>';
			}
			echo '</fieldset>';
		}
		echo '</div></section>';

		// ── 4. sender, texts and the site
		$from = self::sender( $s );
		echo '<section class="whd-lr__card"><h2 class="whd-lr__h"><span class="whd-lr__n">4</span>' . esc_html__( 'Sender and texts', 'whd' ) . '</h2>';
		echo '<div class="whd-lr__grid whd-lr__grid--3">';
		echo '<label class="whd-lr__field"><span>' . esc_html__( 'From name', 'whd' ) . '</span><input type="text" name="' . esc_attr( $f( 'from_label' ) ) . '" value="' . esc_attr( $s['from_label'] ) . '" placeholder="' . esc_attr( $from['label'] ) . '"></label>';
		echo '<label class="whd-lr__field"><span>' . esc_html__( 'From email', 'whd' ) . '</span><input type="email" name="' . esc_attr( $f( 'from_email' ) ) . '" value="' . esc_attr( $s['from_email'] ) . '" placeholder="' . esc_attr( $from['email'] ) . '"><small>' . esc_html__( 'Use an address on your own domain, set up for sending in Klaviyo.', 'whd' ) . '</small></label>';
		echo '<label class="whd-lr__field"><span>' . esc_html__( 'Replies go to', 'whd' ) . ' <em>' . esc_html__( 'optional', 'whd' ) . '</em></span><input type="email" name="' . esc_attr( $f( 'reply_to' ) ) . '" value="' . esc_attr( $s['reply_to'] ) . '"></label>';
		echo '</div>';
		echo '<div class="whd-lr__checks">';
		echo '<label><input type="checkbox" name="' . esc_attr( $f( 'sms_prefix' ) ) . '" value="1"' . checked( $s['sms_prefix'], 1, false ) . ' id="whd-lr-prefix"> ' . esc_html__( 'Start every text with your organization’s name (as set in Klaviyo)', 'whd' ) . '</label>';
		echo '<label><input type="checkbox" name="' . esc_attr( $f( 'sms_opt_out' ) ) . '" value="1"' . checked( $s['sms_opt_out'], 1, false ) . ' id="whd-lr-optout"> ' . esc_html__( 'End every text with how to stop them (“Reply STOP to opt out”)', 'whd' ) . '</label>';
		echo '<label><input type="checkbox" name="' . esc_attr( $f( 'ask_phone' ) ) . '" value="1"' . checked( $s['ask_phone'], 1, false ) . '> ' . esc_html__( 'On the site, after somebody leaves their email on the live card, offer “Want a text too?” with a mobile number box', 'whd' ) . '</label>';
		echo '</div>';
		echo '<label class="whd-lr__field whd-lr__field--wide"><span>' . esc_html__( 'Who the texts are from, in the consent line', 'whd' ) . '</span><input type="text" name="' . esc_attr( $f( 'company' ) ) . '" value="' . esc_attr( $s['company'] ) . '" placeholder="' . esc_attr( self::company( array_merge( $s, [ 'company' => '' ] ) ) ) . '"><small>' . esc_html__( 'Your full company name, as on the Mobile Terms page and in Klaviyo. Left empty: the organization name in Klaviyo.', 'whd' ) . '</small></label>';
		$terms = self::terms_url();
		echo '<div class="whd-lr__consent"><p class="whd-lr__label">' . esc_html__( 'What people see next to the box', 'whd' ) . '</p><p>' . wp_kses( self::disclosure( $s ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ) . '</p>';
		if ( '' === $terms ) {
			echo '<p class="whd-lr__warn">' . esc_html__( 'There is no published Mobile Terms page (/mobile-terms/) yet. Klaviyo needs one, public and linked wherever phone numbers are collected, before it will turn texting on.', 'whd' ) . '</p>';
		}
		echo '</div></section>';

		echo '<p class="whd-lr__save"><button type="submit" class="button button-primary button-hero" id="whd-lr-save">' . esc_html__( 'Save and schedule in Klaviyo', 'whd' ) . '</button></p>';
		echo '</form>';

		// ── 5. in Klaviyo
		echo '<section class="whd-lr__card whd-lr__card--table"><h2 class="whd-lr__h"><span class="whd-lr__n">5</span>' . esc_html__( 'In Klaviyo', 'whd' ) . '</h2>';
		$rows = [];
		foreach ( $plan as $key => $m ) {
			$rows[ $key ] = [ 'live' => (int) $m['live']['ts'], 'reminder' => $m['reminder'], 'channel' => $m['channel'], 'send' => (int) $m['send'], 'entry' => $state[ $key ] ?? null, 'late' => $m['late'] ];
		}
		foreach ( $state as $key => $e ) {
			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = [ 'live' => (int) $e['live'], 'reminder' => $e['reminder'], 'channel' => $e['channel'], 'send' => (int) $e['send'], 'entry' => $e, 'late' => false, 'orphan' => true ];
			}
		}
		uasort( $rows, static fn( $a, $b ) => $a['send'] <=> $b['send'] );
		if ( ! $rows ) {
			echo '<p class="whd-muted">' . esc_html__( 'Nothing scheduled. Switch the reminders on, set a date and save.', 'whd' ) . '</p>';
		} else {
			echo '<table class="widefat whd-lr__table"><thead><tr><th>' . esc_html__( 'Sends', 'whd' ) . '</th><th>' . esc_html__( 'Reminder', 'whd' ) . '</th><th>' . esc_html__( 'For the live on', 'whd' ) . '</th><th>' . esc_html__( 'Status', 'whd' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$e = $row['entry'];
				[ $badge, $text ] = self::status_of( $row, $e );
				echo '<tr><td>' . esc_html( wp_date( 'D j M, g:i a', $row['send'] ) ) . '</td><td>' . esc_html( ( $labels[ $row['reminder'] ] ?? $row['reminder'] ) . ' · ' . ( 'email' === $row['channel'] ? __( 'Email', 'whd' ) : __( 'Text', 'whd' ) ) ) . '</td><td>' . esc_html( wp_date( 'D j M, g:i a', $row['live'] ) ) . '</td><td><span class="whd-lr__badge whd-lr__badge--' . esc_attr( $badge ) . '">' . esc_html( $text ) . '</span>' . ( $e && ! empty( $e['error'] ) && in_array( $e['status'], [ 'error', 'stuck' ], true ) ? '<small class="whd-lr__err">' . esc_html( $e['error'] ) . '</small>' : '' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="whd-lr__again">';
		wp_nonce_field( 'whd_live_reminders_sync' );
		echo '<input type="hidden" name="action" value="whd_live_reminders_sync"><button class="button">' . esc_html__( 'Check and schedule again', 'whd' ) . '</button> <a class="button-link" href="https://www.klaviyo.com/campaigns/list" target="_blank" rel="noopener">' . esc_html__( 'Open your campaigns in Klaviyo', 'whd' ) . '</a></form>';
		echo '</section>';
		echo '</div>';
	}

	/** [ badge class, words ] for a row of the "In Klaviyo" table. */
	private static function status_of( array $row, ?array $e ): array {
		$now = time();
		if ( $e ) {
			switch ( $e['status'] ) {
				case 'scheduled':
					return $row['send'] <= $now ? [ 'sent', __( 'Gone out', 'whd' ) ] : [ 'ok', ! empty( $row['orphan'] ) ? __( 'Scheduled — being taken back', 'whd' ) : __( 'Scheduled', 'whd' ) ];
				case 'sending':
				case 'sent':
					return [ 'sent', __( 'Gone out', 'whd' ) ];
				case 'cancelled':
				case 'gone':
					return [ 'off', __( 'Cancelled in Klaviyo', 'whd' ) ];
				case 'stuck':
					return [ 'bad', __( 'Could not be taken back — cancel it in Klaviyo', 'whd' ) ];
				default:
					return [ 'bad', __( 'Not scheduled', 'whd' ) ];
			}
		}
		if ( $row['send'] <= $now ) {
			return [ 'off', __( 'Already past', 'whd' ) ];
		}
		if ( $row['late'] ) {
			return [ 'off', __( 'Too close to schedule', 'whd' ) ];
		}
		return [ 'wait', __( 'Not scheduled yet — save to schedule', 'whd' ) ];
	}
}
