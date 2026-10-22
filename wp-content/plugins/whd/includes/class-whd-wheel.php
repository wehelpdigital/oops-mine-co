<?php
/**
 * Spin to win: a wheel in a popup that gives away one of the shop's real coupons.
 *
 * The wheel is a block in the popup editor (WHD_Blocks, type `wheel`); the prizes are set in
 * WHD → Spin to win. Three rules hold it together:
 *
 *   - every prize is a coupon WooCommerce actually has. Saving a prize whose code does not exist
 *     yet creates it; a prize whose coupon has since been deleted, has expired or is used up is
 *     taken off the wheel, so the wheel can never land on something the checkout will refuse;
 *   - the server decides where it lands, by the chances set on the screen. The browser is told
 *     which segment to stop on and animates to it — it is never asked;
 *   - the code goes by email and only by email (the `wheel_prize` design). The popup says what it
 *     landed on and to look in the inbox, which is the rule every discount modal on the site keeps.
 *
 * One spin per address: a second spin with the same email lands on the same prize and sends the
 * email again, so filling the form in twice is neither a way to a better code nor a way to lose one.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Wheel {

	const OPTION     = 'whd_wheel';
	const DB_VERSION = '1';
	const SOURCE     = 'wheel';
	const TRIGGER    = 'wheel_prize';
	const PAGE       = 'whd-wheel';

	/** A wheel with fewer segments than this looks like a coin toss, so short lists repeat round it. */
	const MIN_SEGMENTS = 6;
	const MAX_PRIZES   = 12;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'whd_spins';
	}

	public static function init() {
		add_action( 'admin_init', [ __CLASS__, 'maybe_install' ] );
		add_action( 'wp_ajax_whd_wheel_spin', [ __CLASS__, 'ajax_spin' ] );
		add_action( 'wp_ajax_nopriv_whd_wheel_spin', [ __CLASS__, 'ajax_spin' ] );
		add_action( 'admin_post_whd_wheel_save', [ __CLASS__, 'save' ] );
	}

	/* ─────────────────────────── storage ─────────────────────────── */

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(190) NOT NULL,
			name varchar(120) NOT NULL DEFAULT '',
			label varchar(120) NOT NULL DEFAULT '',
			code varchar(64) NOT NULL DEFAULT '',
			created datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email)
		) " . $wpdb->get_charset_collate() . ';' );
		update_option( 'whd_wheel_db', self::DB_VERSION, false );
	}

	public static function maybe_install() {
		if ( get_option( 'whd_wheel_db' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * The prizes as saved: [ label, code, weight, colour ].
	 *
	 * Until the owner saves the screen, the wheel offers the two codes the shop already hands out
	 * (WHD → Settings), each labelled with what WooCommerce says it is worth.
	 */
	public static function saved_prizes(): array {
		$saved = get_option( self::OPTION, null );
		if ( is_array( $saved ) && isset( $saved['prizes'] ) ) {
			return (array) $saved['prizes'];
		}
		$settings = WHD_Plugin::settings();
		$out      = [];
		$colours  = [ '#f3dcd6', '#b98b7e', '#efe6de', '#9c6f63' ];
		foreach ( array_unique( array_filter( [ $settings['cart_coupon'] ?? '', $settings['exit_coupon'] ?? '' ] ) ) as $i => $code ) {
			$label = self::coupon_label( $code );
			if ( $label ) {
				$out[] = [ 'label' => $label, 'code' => $code, 'weight' => 0 === $i ? 70 : 30, 'colour' => $colours[ $i % 4 ] ];
			}
		}
		return $out;
	}

	/** A coupon WooCommerce will still take: exists, not expired, not used up. */
	public static function usable( string $code ): ?WC_Coupon {
		if ( '' === $code || ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
			return null;
		}
		$id = wc_get_coupon_id_by_code( $code );
		if ( ! $id ) {
			return null;
		}
		$coupon  = new WC_Coupon( $id );
		$expires = $coupon->get_date_expires();
		if ( $expires && $expires->getTimestamp() < time() ) {
			return null;
		}
		if ( $coupon->get_usage_limit() && $coupon->get_usage_count() >= $coupon->get_usage_limit() ) {
			return null;
		}
		return $coupon;
	}

	/** "10% off", "$5 off" — read off the coupon, never typed. Empty when there is no such coupon. */
	public static function coupon_label( string $code ): string {
		$coupon = self::usable( $code );
		if ( ! $coupon ) {
			return '';
		}
		// Trailing zeros only after a decimal point: "10" must stay 10, "12.50" may become 12.5.
		$value  = (float) $coupon->get_amount();
		$amount = floor( $value ) === $value ? (string) (int) $value : rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
		if ( 'percent' === $coupon->get_discount_type() ) {
			/* translators: %s: a percentage, e.g. 10% */
			return sprintf( __( '%s off', 'whd' ), $amount . '%' );
		}
		if ( (float) $amount > 0 ) {
			/* translators: %s: an amount of money */
			return sprintf( __( '%s off', 'whd' ), html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount, [ 'decimals' => 0 ] ) ), ENT_QUOTES, 'UTF-8' ) );
		}
		return $coupon->get_free_shipping() ? __( 'Free shipping', 'whd' ) : '';
	}

	/** What the wheel can land on right now: saved prizes with a usable coupon and a chance above nothing. */
	public static function prizes(): array {
		$out = [];
		foreach ( self::saved_prizes() as $p ) {
			$code = (string) ( $p['code'] ?? '' );
			if ( (int) ( $p['weight'] ?? 0 ) > 0 && self::usable( $code ) ) {
				$out[] = [
					'label'  => (string) ( $p['label'] ?? '' ) ?: self::coupon_label( $code ),
					'code'   => $code,
					'weight' => (int) $p['weight'],
					'colour' => sanitize_hex_color( (string) ( $p['colour'] ?? '' ) ) ?: '#f3dcd6',
				];
			}
		}
		return array_slice( $out, 0, self::MAX_PRIZES );
	}

	/**
	 * The wheel's segments, each pointing at a prize: [ [ prize, label, colour ] ].
	 * A short list repeats round the wheel so it still looks like a wheel.
	 */
	public static function segments(): array {
		$prizes = self::prizes();
		if ( ! $prizes ) {
			return [];
		}
		$segments = [];
		$i        = 0;
		while ( count( $segments ) < max( self::MIN_SEGMENTS, count( $prizes ) ) ) {
			$p          = $i % count( $prizes );
			$segments[] = [ 'prize' => $p, 'label' => $prizes[ $p ]['label'], 'colour' => $prizes[ $p ]['colour'] ];
			$i++;
		}
		// An even count with two prizes alternates nicely; with an odd leftover, two neighbours would match.
		return $segments;
	}

	/** Weighted pick: the chances on the screen are relative, not percentages that must add up. */
	private static function pick( array $prizes ): int {
		$total = array_sum( array_column( $prizes, 'weight' ) );
		$roll  = wp_rand( 1, max( 1, $total ) );
		foreach ( $prizes as $i => $p ) {
			$roll -= $p['weight'];
			if ( $roll <= 0 ) {
				return $i;
			}
		}
		return 0;
	}

	/* ─────────────────────────── the block ─────────────────────────── */

	/** Dark text on a light segment, cream on a dark one. */
	private static function ink( string $hex ): string {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		return ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) > 150 ? '#3a2b26' : '#fffaf6';
	}

	/** The disc as SVG, drawn here so the editor's preview and the site show the same wheel. */
	private static function disc( array $segments ): string {
		$n   = count( $segments );
		$a   = 360 / $n;
		$r   = 96;
		$pt  = static function ( $deg ) use ( $r ) {
			$rad = deg2rad( $deg );
			return round( $r * sin( $rad ), 3 ) . ' ' . round( -$r * cos( $rad ), 3 );
		};
		$out = '<svg class="whd-wheel__disc" viewBox="-100 -100 200 200" aria-hidden="true" focusable="false">';
		foreach ( $segments as $i => $s ) {
			$start = $i * $a;
			$end   = $start + $a;
			$mid   = $start + $a / 2;
			$out  .= '<path d="M0 0 L' . $pt( $start ) . ' A' . $r . ' ' . $r . ' 0 0 1 ' . $pt( $end ) . ' Z" fill="' . esc_attr( $s['colour'] ) . '" stroke="#fffaf6" stroke-width="1.2"/>';
			// Along the radius, the way the labels on a prize wheel run — turned over on the left half,
			// so no label is ever upside down while the wheel stands still.
			$label = mb_strlen( $s['label'] ) > 14 ? mb_substr( $s['label'], 0, 13 ) . '…' : $s['label'];
			$turn  = $mid > 180 ? 'rotate(' . round( $mid + 90, 3 ) . ') translate(-60 0)' : 'rotate(' . round( $mid - 90, 3 ) . ') translate(60 0)';
			$out  .= '<text transform="' . $turn . '" text-anchor="middle" dominant-baseline="central" fill="' . esc_attr( self::ink( $s['colour'] ) ) . '">' . esc_html( $label ) . '</text>';
		}
		$out .= '<circle r="15" fill="#fffaf6" stroke="#e9e1da" stroke-width="1.5"/><circle r="5" fill="#b98b7e"/></svg>';
		return $out;
	}

	/** Popup markup for one wheel block (WHD_Blocks::render_block → here). */
	public static function render_block( array $p, array $ctx ): string {
		$segments = self::segments();
		if ( ! $segments ) {
			// Nothing to win: the block says so in the editor's preview and prints nothing on the site.
			return defined( 'REST_REQUEST' ) && REST_REQUEST
				? '<div class="whd-b whd-b-wheel whd-wheel whd-wheel--empty"><p>' . esc_html__( 'No prizes yet — add them in WHD → Spin to win. Until then the wheel stays off the site.', 'whd' ) . '</p></div>'
				: '';
		}
		$fields = '<p class="whd-wheel__hp" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></p>';
		if ( ! empty( $p['show_name'] ) ) {
			$fields .= '<input class="whd-form__input" type="text" name="name" autocomplete="given-name" placeholder="' . esc_attr( $p['name_placeholder'] ) . '" aria-label="' . esc_attr( $p['name_placeholder'] ) . '">';
		}
		$fields .= '<input class="whd-form__input" type="email" name="email" required autocomplete="email" placeholder="' . esc_attr( $p['email_placeholder'] ) . '" aria-label="' . esc_attr( $p['email_placeholder'] ) . '">';

		$size = max( 200, min( 440, (int) $p['size'] ) );
		return '<div class="whd-b whd-b-wheel whd-wheel" style="--whd-wheel:' . $size . 'px" data-segments="' . count( $segments ) . '">'
			. '<div class="whd-wheel__stage"><span class="whd-wheel__pin" aria-hidden="true"></span>' . self::disc( $segments ) . '</div>'
			. '<form class="whd-form whd-wheel__form" method="post" action="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" novalidate'
				. ' data-result="' . esc_attr( wp_kses_post( WHD_Blocks::merge( $p['result_text'], $ctx ) ) ) . '"'
				. ' data-done="' . esc_attr( wp_kses_post( WHD_Blocks::merge( $p['done_text'], $ctx ) ) ) . '"'
				. ' data-invalid="' . esc_attr__( 'Please enter a valid email address.', 'whd' ) . '"'
				. ' data-error="' . esc_attr__( 'Something went wrong. Please try again.', 'whd' ) . '"'
				. ' data-preview="' . esc_attr__( 'Preview only — nothing was sent.', 'whd' ) . '">'
			. '<input type="hidden" name="action" value="whd_wheel_spin">'
			. '<input type="hidden" name="nonce" value="' . esc_attr( wp_create_nonce( 'whd_wheel' ) ) . '">'
			. $fields
			. '<button type="submit" class="whd-form__btn" style="background:' . esc_attr( $p['bg'] ) . ';color:' . esc_attr( $p['color'] ) . ';border-radius:' . (int) $p['radius'] . 'px">' . esc_html( WHD_Blocks::merge( $p['button_text'], $ctx ) ) . '</button>'
			. '<p class="whd-form__status" role="status" aria-live="polite"></p>'
			. ( '' !== trim( (string) $p['note'] ) ? '<p class="whd-wheel__note">' . esc_html( $p['note'] ) . '</p>' : '' )
			. '</form></div>';
	}

	/* ─────────────────────────── the spin ─────────────────────────── */

	public static function ajax_spin() {
		check_ajax_referer( 'whd_wheel', 'nonce' );
		self::maybe_install();

		if ( ! empty( $_POST['website'] ) ) { // a field a person never sees
			wp_send_json_error( [ 'message' => __( 'Something went wrong. Please try again.', 'whd' ) ], 400 );
		}
		$email = isset( $_POST['email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : '';
		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'Please enter a valid email address.', 'whd' ) ], 400 );
		}
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'whd_spin_' . md5( $ip );
		if ( $ip && (int) get_transient( $key ) >= 8 ) {
			wp_send_json_error( [ 'message' => __( 'That is a lot of spinning. Try again in an hour.', 'whd' ) ], 429 );
		}

		$prizes   = self::prizes();
		$segments = self::segments();
		if ( ! $prizes ) {
			wp_send_json_error( [ 'message' => __( 'The wheel is resting just now. Try again later.', 'whd' ) ], 400 );
		}

		global $wpdb;
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE email = %s', $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$index = null;
		if ( $row ) {
			// Their spin is their spin. Only a prize that has since gone off the wheel is drawn again.
			foreach ( $prizes as $i => $p ) {
				if ( strtolower( $p['code'] ) === strtolower( (string) $row->code ) ) {
					$index = $i;
				}
			}
		}
		if ( null === $index ) {
			$index = self::pick( $prizes );
			$prize = $prizes[ $index ];
			if ( $row ) {
				$wpdb->update( self::table(), [ 'label' => $prize['label'], 'code' => $prize['code'] ], [ 'id' => (int) $row->id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			} else {
				$wpdb->insert( self::table(), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					'email'   => $email,
					'name'    => $name,
					'label'   => $prize['label'],
					'code'    => $prize['code'],
					'created' => current_time( 'mysql' ),
				] );
			}
		}
		$prize = $prizes[ $index ];

		// The list first (the sign-up is the consent), then the code.
		WHD_Subscribers::add( $email, $name, self::SOURCE );
		$mailed   = self::send( $email, $name ?: (string) ( $row->name ?? '' ), $prize );
		$event_id = class_exists( 'WHD_Capi' ) ? WHD_Capi::lead( $email, self::SOURCE, $name ) : '';

		if ( $ip ) {
			set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
		}

		// Any segment that carries this prize will do; a random one, so it does not always stop in one place.
		$choices = array_keys( array_filter( $segments, static fn( $s ) => $s['prize'] === $index ) );
		wp_send_json_success( [
			'segment'  => $choices ? $choices[ array_rand( $choices ) ] : 0,
			'label'    => $prize['label'],
			'again'    => (bool) $row,
			'mailed'   => $mailed,
			'message'  => $mailed ? '' : __( 'The email did not go out just now. Spin again in a minute — it lands on the same prize and sends it again.', 'whd' ),
			'event_id' => $event_id,
		] );
	}

	/** The prize email. Its design is WHD → Emails → "Spin to win — the prize code". */
	public static function send( string $email, string $name, array $prize ): bool {
		$ctx = WHD_Emails::context( [
			'data' => [
				'email'           => $email,
				'first_name'      => $name ?: __( 'there', 'whd' ),
				'customer_name'   => $name,
				'prize_label'     => $prize['label'],
				'prize_code'      => $prize['code'],
				'unsubscribe_url' => class_exists( 'WHD_Cart' ) ? WHD_Cart::unsubscribe_url( $email ) : '',
			],
		] );
		return (bool) WHD_Emails::send_custom( self::TRIGGER, $ctx, $email );
	}

	/* ─────────────────────────── admin ─────────────────────────── */

	public static function spins( $limit = 50 ) {
		global $wpdb;
		self::maybe_install();
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY created DESC LIMIT %d', (int) $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function count() {
		global $wpdb;
		self::maybe_install();
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function page() {
		$prizes = self::saved_prizes();
		$notice = get_transient( 'whd_wheel_notice_' . get_current_user_id() );
		delete_transient( 'whd_wheel_notice_' . get_current_user_id() );

		echo '<div class="wrap whd-wrap"><h1 class="whd-title">' . esc_html__( 'Spin to win', 'whd' ) . '</h1>';
		echo '<p class="whd-intro">' . wp_kses_post( sprintf(
			/* translators: %s: link to the popup editor */
			__( 'The prizes the wheel can land on, and how likely each one is. Every prize is a real WooCommerce coupon: type a code that does not exist yet and it is created when you save, as the discount you set beside it. The code is only ever emailed — the popup says what it landed on and to check the inbox. The wheel itself is a block in the popup editor; a ready-made <a href="%s">Spin-to-win popup</a> is there to switch on.', 'whd' ),
			esc_url( admin_url( 'admin.php?page=whd-editor&type=popup&id=spin_wheel' ) )
		) ) . '</p>';
		if ( $notice ) {
			echo '<div class="notice notice-' . esc_attr( $notice['ok'] ? 'success' : 'warning' ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}

		echo '<div class="whd-grid">';
		echo '<a class="whd-card" href="#whd-spins"><span class="whd-card__title">' . esc_html__( 'Spins', 'whd' ) . '</span><span class="whd-card__value">' . esc_html( number_format_i18n( self::count() ) ) . '</span></a>';
		echo '<a class="whd-card" href="#whd-prizes"><span class="whd-card__title">' . esc_html__( 'On the wheel now', 'whd' ) . '</span><span class="whd-card__value">' . esc_html( sprintf( _n( '%d prize', '%d prizes', count( self::prizes() ), 'whd' ), count( self::prizes() ) ) ) . '</span></a>';
		echo '<a class="whd-card" href="' . esc_url( admin_url( 'admin.php?page=whd-editor&type=email&id=' . self::TRIGGER ) ) . '"><span class="whd-card__title">' . esc_html__( 'Prize email', 'whd' ) . '</span><span class="whd-card__value">' . esc_html__( 'Edit the design', 'whd' ) . '</span></a>';
		echo '</div>';

		echo '<h2 class="whd-h2" id="whd-prizes">' . esc_html__( 'Prizes', 'whd' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'whd_wheel_save' );
		echo '<input type="hidden" name="action" value="whd_wheel_save">';
		echo '<table class="widefat striped whd-table whd-wheel-prizes"><thead><tr>'
			. '<th>' . esc_html__( 'Label on the wheel', 'whd' ) . '</th>'
			. '<th>' . esc_html__( 'Coupon code', 'whd' ) . '</th>'
			. '<th>' . esc_html__( 'Chance', 'whd' ) . '</th>'
			. '<th>' . esc_html__( 'Colour', 'whd' ) . '</th>'
			. '<th>' . esc_html__( 'In WooCommerce', 'whd' ) . '</th>'
			. '<th>' . esc_html__( 'Remove', 'whd' ) . '</th></tr></thead><tbody>';

		$total = array_sum( array_map( static fn( $p ) => self::usable( (string) ( $p['code'] ?? '' ) ) ? max( 0, (int) ( $p['weight'] ?? 0 ) ) : 0, $prizes ) );
		$rows  = array_merge( $prizes, array_fill( 0, 3, [] ) );
		foreach ( array_slice( $rows, 0, self::MAX_PRIZES ) as $i => $p ) {
			$code   = (string) ( $p['code'] ?? '' );
			$coupon = $code ? self::usable( $code ) : null;
			$weight = (int) ( $p['weight'] ?? 0 );
			$name   = static fn( $k ) => 'prizes[' . $i . '][' . $k . ']';
			echo '<tr>';
			echo '<td><input type="text" class="regular-text" maxlength="40" name="' . esc_attr( $name( 'label' ) ) . '" value="' . esc_attr( (string) ( $p['label'] ?? '' ) ) . '" placeholder="' . esc_attr__( '10% off', 'whd' ) . '"></td>';
			echo '<td><input type="text" class="code" style="width:130px;text-transform:uppercase" name="' . esc_attr( $name( 'code' ) ) . '" value="' . esc_attr( $code ) . '" placeholder="SPIN10"></td>';
			echo '<td><input type="number" min="0" max="1000" style="width:80px" name="' . esc_attr( $name( 'weight' ) ) . '" value="' . esc_attr( (string) ( $weight ?: '' ) ) . '">'
				. ( $coupon && $total && $weight ? ' <span class="whd-muted">' . esc_html( round( $weight / $total * 100 ) . '%' ) . '</span>' : '' ) . '</td>';
			echo '<td><input type="color" name="' . esc_attr( $name( 'colour' ) ) . '" value="' . esc_attr( sanitize_hex_color( (string) ( $p['colour'] ?? '' ) ) ?: ( [ '#f3dcd6', '#b98b7e', '#efe6de', '#9c6f63' ][ $i % 4 ] ) ) . '"></td>';
			echo '<td>';
			if ( $coupon ) {
				echo '<span class="whd-pill whd-pill--on">' . esc_html( self::coupon_label( $code ) ) . '</span>';
				$expires = $coupon->get_date_expires();
				echo ' <span class="whd-muted">' . esc_html( $expires ? sprintf( __( 'until %s', 'whd' ), wc_format_datetime( $expires ) ) : __( 'no end date', 'whd' ) ) . '</span>';
			} else {
				echo '<span class="whd-muted">' . esc_html( $code ? __( 'Not usable yet — will be created as:', 'whd' ) : __( 'If the code is new, create it as:', 'whd' ) ) . '</span><br>';
				echo '<select name="' . esc_attr( $name( 'type' ) ) . '"><option value="percent">' . esc_html__( '% off the order', 'whd' ) . '</option><option value="fixed_cart">' . esc_html__( 'Fixed amount off', 'whd' ) . '</option></select> ';
				echo '<input type="number" min="1" max="100000" step="1" style="width:80px" name="' . esc_attr( $name( 'amount' ) ) . '" placeholder="10">';
			}
			echo '</td>';
			echo '<td>' . ( $code ? '<input type="checkbox" name="' . esc_attr( $name( 'remove' ) ) . '" value="1" aria-label="' . esc_attr__( 'Remove this prize', 'whd' ) . '">' : '' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Chances are relative: 3 and 1 means the first lands three times as often as the second. A coupon that is deleted, expires or is used up in WooCommerce leaves the wheel on its own. New coupons are created for one use per customer, not combinable with other codes, with no end date — change any of that in WooCommerce → Marketing → Coupons.', 'whd' ) . '</p>';
		submit_button( __( 'Save prizes', 'whd' ) );
		echo '</form>';

		echo '<h2 class="whd-h2" id="whd-spins">' . esc_html__( 'Latest spins', 'whd' ) . '</h2>';
		echo '<table class="widefat striped whd-table"><thead><tr><th>' . esc_html__( 'When', 'whd' ) . '</th><th>' . esc_html__( 'Who', 'whd' ) . '</th><th>' . esc_html__( 'Landed on', 'whd' ) . '</th><th>' . esc_html__( 'Code sent', 'whd' ) . '</th></tr></thead><tbody>';
		$spins = self::spins( 50 );
		if ( ! $spins ) {
			echo '<tr><td colspan="4">' . esc_html__( 'Nobody has spun yet.', 'whd' ) . '</td></tr>';
		}
		foreach ( $spins as $s ) {
			echo '<tr><td class="whd-muted">' . esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $s->created ) ) . '</td><td>' . esc_html( $s->name ? $s->name . ' · ' . $s->email : $s->email ) . '</td><td>' . esc_html( $s->label ) . '</td><td><code>' . esc_html( $s->code ) . '</code></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/** Save the prizes, creating any coupon that does not exist yet. */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'whd_wheel_save' ) ) {
			wp_die( esc_html__( 'Not allowed', 'whd' ) );
		}
		$rows    = isset( $_POST['prizes'] ) && is_array( $_POST['prizes'] ) ? wp_unslash( $_POST['prizes'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field sanitised below
		$prizes  = [];
		$made    = [];
		$skipped = [];
		foreach ( $rows as $row ) {
			$code = strtoupper( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $row['code'] ?? '' ) ) );
			if ( '' === $code || ! empty( $row['remove'] ) ) {
				continue;
			}
			if ( ! self::usable( $code ) ) {
				$amount = (float) ( $row['amount'] ?? 0 );
				$type   = 'fixed_cart' === ( $row['type'] ?? '' ) ? 'fixed_cart' : 'percent';
				if ( wc_get_coupon_id_by_code( $code ) ) {
					// It exists but is expired or used up: that is the owner's to fix, not ours to overwrite.
					$skipped[] = $code;
				} elseif ( $amount > 0 && ( 'fixed_cart' === $type || $amount <= 100 ) ) {
					$coupon = new WC_Coupon();
					$coupon->set_code( $code );
					$coupon->set_discount_type( $type );
					$coupon->set_amount( $amount );
					$coupon->set_individual_use( true );
					$coupon->set_usage_limit_per_user( 1 );
					$coupon->set_description( __( 'Spin-to-win prize (WHD → Spin to win)', 'whd' ) );
					$coupon->save();
					$made[] = $code;
				} else {
					$skipped[] = $code;
				}
			}
			$prizes[] = [
				'label'  => sanitize_text_field( (string) ( $row['label'] ?? '' ) ) ?: self::coupon_label( $code ),
				'code'   => $code,
				'weight' => max( 0, min( 1000, (int) ( $row['weight'] ?? 0 ) ) ),
				'colour' => sanitize_hex_color( (string) ( $row['colour'] ?? '' ) ) ?: '#f3dcd6',
			];
		}
		update_option( self::OPTION, [ 'prizes' => array_slice( $prizes, 0, self::MAX_PRIZES ) ], false );

		$message = __( 'Prizes saved.', 'whd' );
		if ( $made ) {
			/* translators: %s: coupon codes */
			$message .= ' ' . sprintf( __( 'Created in WooCommerce: %s.', 'whd' ), implode( ', ', $made ) );
		}
		if ( $skipped ) {
			/* translators: %s: coupon codes */
			$message .= ' ' . sprintf( __( 'Not on the wheel until WooCommerce can use them: %s (a new code needs an amount; an existing one may be expired or used up).', 'whd' ), implode( ', ', $skipped ) );
		}
		set_transient( 'whd_wheel_notice_' . get_current_user_id(), [ 'ok' => ! $skipped, 'message' => $message ], 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}
}
