<?php
/**
 * Returning shoppers: their details filled in at checkout, their size picked on the product page.
 *
 * Somebody who bought once, or who left a bag and came back through the reminder, should not have
 * to type their name, address and phone again, or hunt for their size on every product. So:
 *
 *   details  On the checkout page, the fields still empty are filled from what this browser last
 *            gave the shop — the order it placed, or the bag it left — with a "Not you?" link that
 *            forgets all of it. Logged-in customers get their own last order's details where their
 *            account has none.
 *   sizes    The size (and fit, length, cup…) somebody chose last is picked again on the next
 *            product that comes in it, once the levels above it are chosen. Colours, patterns and
 *            prints are never carried over: a size is a fact about a person, a colour is a choice
 *            about a piece.
 *
 * Whose details these are is decided by this browser, never by an email address. A signed cookie
 * says which order or which bag this browser itself produced; it is set when the order is placed,
 * when an email is typed at the checkout, or when the reminder link from the inbox is followed.
 * Nothing is ever looked up from an address somebody types — typing a stranger's email at the
 * checkout must not show the stranger's address on the next visit. Only the reminder link, which
 * proves the inbox, also brings back the address of that email's last order.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Shopper {

	/** Which order or bag this browser produced: "o123", "c45", or "r45" (a bag reached from its reminder). */
	const COOKIE = 'whd_shopper';

	/** The sizes this browser chose, as JSON { attribute: value }, read by assets/fit.js. */
	const FIT = 'whd_fit';

	/** The same, on a customer's account, so it follows them to another device. */
	const FIT_META = '_whd_fit';

	const DAYS = 180;

	/** The checkout fields that are filled, billing and shipping. */
	const FIELDS = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ];

	public static function init() {
		$s = WHD_Plugin::settings();
		if ( ! empty( $s['remember_details'] ) ) {
			add_action( 'whd_cart_captured', [ __CLASS__, 'on_capture' ], 10, 2 );
			add_action( 'whd_cart_recovered', [ __CLASS__, 'on_recover' ] );
			add_action( 'template_redirect', [ __CLASS__, 'forget' ], 5 );
			add_action( 'template_redirect', [ __CLASS__, 'fill_checkout' ], 20 );
			add_action( 'woocommerce_before_checkout_billing_form', [ __CLASS__, 'welcome_line' ] );
		}
		if ( ! empty( $s['remember_sizes'] ) ) {
			add_action( 'woocommerce_add_to_cart', [ __CLASS__, 'on_add_to_cart' ], 10, 6 );
			add_action( 'wp_login', [ __CLASS__, 'on_login' ], 10, 2 );
			add_action( 'template_redirect', [ __CLASS__, 'sync_fit' ], 30 );
			add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
		}
		if ( ! empty( $s['remember_details'] ) || ! empty( $s['remember_sizes'] ) ) {
			add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'on_order' ], 20, 1 );
			add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'on_order' ], 20, 1 );
		}
	}

	/* ─────────────────────────── which shopper this browser is ─────────────────────────── */

	private static function sign( string $value ): string {
		return substr( hash_hmac( 'sha256', $value, wp_salt( 'auth' ) ), 0, 32 );
	}

	/** The verified reference this browser carries, or '' — a forged or expired cookie is no cookie. */
	public static function ref(): string {
		$raw   = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		$parts = explode( '.', $raw );
		if ( 3 !== count( $parts ) || ! preg_match( '/^[ocr]\d+$/', $parts[0] ) || (int) $parts[1] < time() ) {
			return '';
		}
		return hash_equals( self::sign( $parts[0] . '|' . $parts[1] ), $parts[2] ) ? $parts[0] : '';
	}

	private static function remember( string $ref ) {
		$until = time() + self::DAYS * DAY_IN_SECONDS;
		self::cookie( self::COOKIE, $ref . '.' . $until . '.' . self::sign( $ref . '|' . $until ), $until, true );
		$_COOKIE[ self::COOKIE ] = $ref . '.' . $until . '.' . self::sign( $ref . '|' . $until );
	}

	private static function cookie( string $name, string $value, int $until, bool $http_only ) {
		if ( headers_sent() ) {
			return;
		}
		setcookie( $name, $value, [
			'expires'  => $until,
			'path'     => COOKIEPATH ?: '/',
			'domain'   => COOKIE_DOMAIN ?: '',
			'secure'   => is_ssl(),
			'httponly' => $http_only,
			'samesite' => 'Lax',
		] );
	}

	/* ─────────────────────────── learning ─────────────────────────── */

	/**
	 * An order: this browser placed it, so its details are this browser's to see again, and the
	 * sizes in it are the shopper's sizes.
	 */
	public static function on_order( $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order ) {
			return;
		}
		$s = WHD_Plugin::settings();
		if ( ! empty( $s['remember_details'] ) && ! is_user_logged_in() ) {
			self::remember( 'o' . $order->get_id() );
		}
		if ( ! empty( $s['remember_sizes'] ) ) {
			$fit = [];
			foreach ( $order->get_items() as $item ) {
				$fit = array_merge( $fit, self::fit_of_item( $item ) );
			}
			self::learn( $fit, (int) $order->get_customer_id() );
		}
	}

	/** A bag captured at the checkout: the email, name and phone were typed in this browser. */
	public static function on_capture( $email, $data ) {
		if ( is_user_logged_in() || ! is_email( $email ) ) {
			return;
		}
		$ref = self::ref();
		if ( $ref && 'o' === $ref[0] ) {
			$order = wc_get_order( (int) substr( $ref, 1 ) );
			// The order this browser placed says more than a bag does, for the same person.
			if ( $order && 0 === strcasecmp( $order->get_billing_email(), $email ) ) {
				return;
			}
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one indexed read, on a capture
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . WHD_Cart::table() . ' WHERE email = %s ORDER BY id DESC LIMIT 1', $email ) );
		if ( $id && 'r' . $id !== $ref ) {
			self::remember( 'c' . $id );
		}
	}

	/** Back through the reminder link: the inbox is proven, and the sizes in the bag are learned. */
	public static function on_recover( $row ) {
		if ( ! is_object( $row ) || empty( $row->id ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			self::remember( 'r' . (int) $row->id );
		}
		$fit = [];
		foreach ( (array) json_decode( (string) $row->items, true ) as $item ) {
			$fit = array_merge( $fit, self::keep_personal( (array) ( $item['variation'] ?? [] ) ) );
		}
		self::learn( $fit, (int) $row->user_id );
	}

	/** A variation into the bag: whatever size was chosen is the shopper's size. */
	public static function on_add_to_cart( $key, $product_id, $quantity, $variation_id, $variation, $data = [] ) {
		self::learn( self::keep_personal( (array) $variation ), get_current_user_id() );
	}

	public static function on_login( $login, $user ) {
		$fit = $user instanceof WP_User ? get_user_meta( $user->ID, self::FIT_META, true ) : [];
		if ( is_array( $fit ) && $fit ) {
			self::cookie( self::FIT, (string) wp_json_encode( $fit ), time() + YEAR_IN_SECONDS, false );
		}
	}

	/** A customer signed in before this existed, or on a new device: their sizes come with the account. */
	public static function sync_fit() {
		if ( ! is_user_logged_in() || isset( $_COOKIE[ self::FIT ] ) || ( ! is_product() && ! is_shop() && ! is_product_taxonomy() ) ) {
			return;
		}
		$fit = get_user_meta( get_current_user_id(), self::FIT_META, true );
		if ( is_array( $fit ) && $fit ) {
			self::cookie( self::FIT, (string) wp_json_encode( $fit ), time() + YEAR_IN_SECONDS, false );
		}
	}

	/** Add to what is remembered — the newest choice of each kind wins — in the cookie and the account. */
	private static function learn( array $fit, int $user_id = 0 ) {
		if ( ! $fit ) {
			return;
		}
		$now = self::fit();
		if ( $user_id ) {
			$saved = get_user_meta( $user_id, self::FIT_META, true );
			$now   = array_merge( is_array( $saved ) ? $saved : [], $now );
		}
		$now = array_slice( array_merge( $now, $fit ), -12, null, true );
		// Plain JSON: setcookie() does the encoding, and doing it here as well left the page a value it could not read.
		self::cookie( self::FIT, (string) wp_json_encode( $now ), time() + YEAR_IN_SECONDS, false );
		$_COOKIE[ self::FIT ] = (string) wp_json_encode( $now );
		if ( $user_id ) {
			update_user_meta( $user_id, self::FIT_META, $now );
		}
	}

	/** What this browser remembers now: { "pa_size": "m" }. */
	public static function fit(): array {
		$text = isset( $_COOKIE[ self::FIT ] ) ? (string) wp_unslash( $_COOKIE[ self::FIT ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked key by key below
		$raw  = json_decode( $text, true );
		if ( ! is_array( $raw ) ) {
			$raw = json_decode( rawurldecode( $text ), true ); // one written encoded twice, by the first version
		}
		$out = [];
		foreach ( is_array( $raw ) ? $raw : [] as $key => $value ) {
			$key   = sanitize_key( (string) $key );
			$value = sanitize_title( is_scalar( $value ) ? (string) $value : '' );
			if ( '' !== $key && '' !== $value && self::personal( $key ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/** The personal choices among a variation's: "attribute_pa_size" => "m" becomes "pa_size" => "m". */
	private static function keep_personal( array $variation ): array {
		$out = [];
		foreach ( $variation as $key => $value ) {
			$key   = sanitize_key( preg_replace( '/^attribute_/', '', (string) $key ) );
			$value = sanitize_title( is_scalar( $value ) ? (string) $value : '' );
			if ( '' !== $key && '' !== $value && self::personal( $key ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	private static function fit_of_item( $item ): array {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return [];
		}
		$product = $item->get_product();
		if ( ! $product || ! $product->is_type( 'variation' ) ) {
			return [];
		}
		$attrs = [];
		foreach ( $product->get_variation_attributes() as $key => $value ) {
			// An "any" level keeps what was chosen on the line itself.
			$attrs[ $key ] = '' !== (string) $value ? $value : (string) $item->get_meta( preg_replace( '/^attribute_/', '', $key ) );
		}
		return self::keep_personal( $attrs );
	}

	/**
	 * Whether a kind of choice belongs to the person: size, fit, length, cup… — never colour,
	 * pattern or print, which are about the piece. Filter `whd_shopper_personal` to change it.
	 */
	public static function personal( string $attribute ): bool {
		$name  = strtolower( preg_replace( '/^(attribute_)?(pa_)?/', '', $attribute ) );
		$label = function_exists( 'wc_attribute_label' ) ? strtolower( (string) wc_attribute_label( 0 === strpos( $attribute, 'pa_' ) ? $attribute : 'pa_' . $name ) ) : '';
		$words = $name . ' ' . $label;
		$yes   = ! preg_match( '/colou?r|pattern|print|shade/', $words ) && preg_match( '/size|fit|length|cup|waist|inseam|bust|petite|tall/', $words );
		return (bool) apply_filters( 'whd_shopper_personal', $yes, $attribute );
	}

	/* ─────────────────────────── at the checkout ─────────────────────────── */

	/**
	 * Fill the checkout fields that are still empty, from what this browser gave the shop last.
	 *
	 * Written onto the session's customer rather than into the form, so the totals, the shipping
	 * and the fields all agree, and so a field the shopper has typed in this session is never
	 * overwritten. An address comes with its own country and state: they are only replaced when
	 * the street line is empty, since an empty address with the shop's default country is not a
	 * choice anybody made.
	 */
	public static function fill_checkout() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) || ! WC()->customer ) {
			return;
		}
		$from = self::details();
		if ( ! $from ) {
			return;
		}
		$customer = WC()->customer;
		$filled   = 0;
		foreach ( [ 'billing', 'shipping' ] as $group ) {
			$values = (array) ( $from[ $group ] ?? [] );
			if ( ! $values ) {
				continue;
			}
			$bare = '' === (string) $customer->{"get_{$group}_address_1"}();
			foreach ( array_merge( self::FIELDS, 'billing' === $group ? [ 'email' ] : [] ) as $field ) {
				$value  = (string) ( $values[ $field ] ?? '' );
				$setter = "set_{$group}_{$field}";
				$getter = "get_{$group}_{$field}";
				if ( '' === $value || ! is_callable( [ $customer, $setter ] ) ) {
					continue;
				}
				$now = (string) $customer->$getter();
				if ( '' === $now || ( $bare && in_array( $field, [ 'country', 'state' ], true ) && $now !== $value ) ) {
					$customer->$setter( $value );
					$filled++;
				}
			}
		}
		if ( ! $filled ) {
			return;
		}
		$customer->save();
		if ( ! empty( $from['ship_elsewhere'] ) ) {
			add_filter( 'woocommerce_ship_to_different_address_checked', '__return_true' );
		}

		// Remembered for the session, so the line with the way out stays on every visit to the page.
		if ( ! is_user_logged_in() && WC()->session ) {
			WC()->session->set( 'whd_prefilled', self::ref() );
		}
	}

	/**
	 * "Welcome back — not you? Clear them", inside the form above the fields it filled.
	 *
	 * Not a WooCommerce notice: this theme shows those as a toast that slides away after a few
	 * seconds, and the way out of somebody else's details has to stay where it can be found.
	 */
	public static function welcome_line() {
		if ( is_user_logged_in() || ! WC()->session || '' === self::ref() || WC()->session->get( 'whd_prefilled' ) !== self::ref() ) {
			return;
		}
		printf(
			// Styled inline: the theme draws a notice in a form as plain text, and this link has to look like one.
			'<p class="woocommerce-info whd-welcome-back" style="margin:0 0 18px">%s <a href="%s" style="text-decoration:underline;text-underline-offset:2px;font-weight:600">%s</a></p>',
			esc_html__( 'Welcome back — your details from last time are filled in. Not you?', 'whd' ),
			esc_url( wp_nonce_url( add_query_arg( 'whd_forget', '1', wc_get_checkout_url() ), 'whd_forget' ) ),
			esc_html__( 'Clear them', 'whd' )
		);
	}

	/**
	 * What to fill in: { billing: {…}, shipping: {…}, ship_elsewhere } — from this browser's order
	 * or bag, or a signed-in customer's own last order.
	 */
	private static function details(): array {
		if ( is_user_logged_in() ) {
			$orders = wc_get_orders( [ 'customer_id' => get_current_user_id(), 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects' ] );
			return $orders ? self::from_order( $orders[0] ) : [];
		}
		$ref = self::ref();
		if ( '' === $ref ) {
			return [];
		}
		$id = (int) substr( $ref, 1 );
		if ( 'o' === $ref[0] ) {
			$order = wc_get_order( $id );
			return $order instanceof WC_Order ? self::from_order( $order ) : [];
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one row by primary key
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT email, first_name, phone FROM ' . WHD_Cart::table() . ' WHERE id = %d', $id ) );
		if ( ! $row ) {
			return [];
		}
		$out = [ 'billing' => array_filter( [ 'email' => (string) $row->email, 'first_name' => (string) $row->first_name, 'phone' => (string) $row->phone ] ) ];
		if ( 'r' === $ref[0] && is_email( $row->email ) ) {
			// The reminder link proves the inbox, so that email's last order may fill the rest.
			$orders = wc_get_orders( [ 'billing_email' => $row->email, 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects' ] );
			if ( $orders ) {
				$last           = self::from_order( $orders[0] );
				$out['billing'] = array_merge( $last['billing'], $out['billing'] );
				$out['shipping']       = $last['shipping'];
				$out['ship_elsewhere'] = $last['ship_elsewhere'];
			}
		}
		return $out;
	}

	private static function from_order( WC_Order $order ): array {
		$billing  = [];
		$shipping = [];
		foreach ( array_merge( self::FIELDS, [ 'email' ] ) as $field ) {
			$get = "get_billing_{$field}";
			if ( is_callable( [ $order, $get ] ) ) {
				$billing[ $field ] = (string) $order->$get();
			}
			$get = "get_shipping_{$field}";
			if ( 'email' !== $field && is_callable( [ $order, $get ] ) ) {
				$shipping[ $field ] = (string) $order->$get();
			}
		}
		$billing  = array_filter( $billing, 'strlen' );
		$shipping = array_filter( $shipping, 'strlen' );
		$elsewhere = ! empty( $shipping['address_1'] ) && ( $shipping['address_1'] ?? '' ) !== ( $billing['address_1'] ?? '' );

		return [ 'billing' => $billing, 'shipping' => $shipping, 'ship_elsewhere' => $elsewhere ];
	}

	/** "Not you?": the browser forgets the order or bag, the sizes, and what was filled in. */
	public static function forget() {
		if ( empty( $_GET['whd_forget'] ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'whd_forget' ) ) {
			return;
		}
		self::cookie( self::COOKIE, '', time() - YEAR_IN_SECONDS, true );
		self::cookie( self::FIT, '', time() - YEAR_IN_SECONDS, false );
		unset( $_COOKIE[ self::COOKIE ], $_COOKIE[ self::FIT ] );
		if ( function_exists( 'WC' ) && WC()->customer && ! is_user_logged_in() ) {
			foreach ( [ 'billing', 'shipping' ] as $group ) {
				foreach ( array_merge( self::FIELDS, 'billing' === $group ? [ 'email' ] : [] ) as $field ) {
					$setter = "set_{$group}_{$field}";
					if ( is_callable( [ WC()->customer, $setter ] ) && ! in_array( $field, [ 'country', 'state' ], true ) ) {
						WC()->customer->$setter( '' );
					}
				}
			}
			WC()->customer->save();
		}
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'whd_prefilled', null );
			WC()->session->set( 'whd_guest', null );
		}
		wc_add_notice( __( 'Done — this browser no longer remembers your details or your sizes.', 'whd' ), 'success' );
		wp_safe_redirect( remove_query_arg( [ 'whd_forget', '_wpnonce' ] ) );
		exit;
	}

	/* ─────────────────────────── on the product page ─────────────────────────── */

	/** The size picker. Loaded on every page with a variation form or a quick view of one; it reads the cookie itself. */
	public static function assets() {
		if ( ! function_exists( 'is_product' ) || ( ! is_product() && ! is_shop() && ! is_product_taxonomy() && ! is_front_page() ) ) {
			return;
		}
		wp_enqueue_script( 'whd-fit', WHD_URL . 'assets/fit.js', [ 'jquery' ], WHD_VERSION, true );
	}
}
