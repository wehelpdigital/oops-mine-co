<?php
/**
 * Tracking & verification scripts: GA4, Google Search Console verification,
 * Meta (Facebook) Pixel, plus raw head/body/footer snippets. Purchase events
 * are pushed to GA4 and the Pixel on the order-received page.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Scripts {

	const OPTION = 'whd_scripts';

	/**
	 * The Meta standard events this shop is able to report, and what each one means here.
	 *
	 * Only events the site can actually observe are listed. There is no Donate, no Schedule and
	 * no StartTrial, because nothing on the site does those things and an event that never fires
	 * is worse than one that is absent — it looks like a tracking fault.
	 *
	 * @return array<string,array{0:string,1:string}> key => [ Meta event name, what fires it ]
	 */
	public static function events() {
		return [
			'view_content'          => [ 'ViewContent', __( 'Somebody opens a product page', 'whd' ) ],
			'view_category'         => [ 'ViewCategory', __( 'Somebody opens the shop or a category', 'whd' ) ],
			'search'                => [ 'Search', __( 'Somebody searches the site', 'whd' ) ],
			'add_to_cart'           => [ 'AddToCart', __( 'Something goes into the basket', 'whd' ) ],
			'add_to_wishlist'       => [ 'AddToWishlist', __( 'Something is saved to a wishlist', 'whd' ) ],
			'initiate_checkout'     => [ 'InitiateCheckout', __( 'The checkout page is opened', 'whd' ) ],
			'add_payment_info'      => [ 'AddPaymentInfo', __( 'The order is placed, as payment details are sent', 'whd' ) ],
			'purchase'              => [ 'Purchase', __( 'An order is paid for', 'whd' ) ],
			'lead'                  => [ 'Lead', __( 'An email address is left — the list, the discount, a back-in-stock alert', 'whd' ) ],
			'complete_registration' => [ 'CompleteRegistration', __( 'An account is created', 'whd' ) ],
		];
	}

	/** Is this event switched on, and is there a pixel to send it to? */
	public static function sends( $key ) {
		$s = self::get();

		return $s['fb_pixel_id'] && in_array( $key, (array) $s['fb_events'], true );
	}

	public static function defaults() {
		return [
			'ga4_id'           => '',
			'gsc_verification' => '',
			'fb_pixel_id'      => '',
			// Every standard event the shop can honestly report, all on once a pixel id is set.
			'fb_events'        => array_keys( self::events() ),
			'head_extra'       => '',
			'body_extra'       => '',
			'footer_extra'     => '',
			'exclude_admins'   => 1,
		];
	}

	public static function get() {
		return wp_parse_args( get_option( self::OPTION, [] ), self::defaults() );
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : [];
		$out   = self::defaults();
		$out['ga4_id']           = preg_match( '/^G-[A-Z0-9]{4,20}$/i', trim( $input['ga4_id'] ?? '' ) ) ? strtoupper( trim( $input['ga4_id'] ) ) : '';
		$out['fb_pixel_id']      = preg_replace( '/\D+/', '', $input['fb_pixel_id'] ?? '' );

		/*
		 * Unticked boxes are not posted, so an empty array means every event was switched off —
		 * which has to be told apart from "this form did not carry the events at all".
		 */
		if ( array_key_exists( 'fb_events_present', $input ) ) {
			$allowed = array_keys( self::events() );
			$out['fb_events'] = array_values( array_intersect( $allowed, (array) ( $input['fb_events'] ?? [] ) ) );
		} else {
			$stored = get_option( self::OPTION, [] );
			$out['fb_events'] = isset( $stored['fb_events'] ) ? (array) $stored['fb_events'] : array_keys( self::events() );
		}
		$gsc                     = trim( $input['gsc_verification'] ?? '' );
		if ( preg_match( '/content=["\']([^"\']+)["\']/', $gsc, $m ) ) {
			$gsc = $m[1]; // whole <meta> tag pasted — keep just the token
		}
		$out['gsc_verification'] = sanitize_text_field( $gsc );
		$out['exclude_admins']   = empty( $input['exclude_admins'] ) ? 0 : 1;
		// Raw snippets: only users allowed to publish unfiltered HTML may store script tags.
		foreach ( [ 'head_extra', 'body_extra', 'footer_extra' ] as $k ) {
			$raw       = (string) ( $input[ $k ] ?? '' );
			$out[ $k ] = current_user_can( 'unfiltered_html' ) ? $raw : wp_kses_post( $raw );
		}
		return $out;
	}

	public static function init() {
		add_action( 'wp_head', [ __CLASS__, 'head' ], 2 );
		add_action( 'wp_body_open', [ __CLASS__, 'body' ], 1 );
		add_action( 'wp_footer', [ __CLASS__, 'footer' ], 99 );
		add_action( 'woocommerce_thankyou', [ __CLASS__, 'purchase_event' ], 20 );

		// Everything else the funnel is made of.
		add_action( 'wp_footer', [ __CLASS__, 'page_events' ], 98 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'pixel_assets' ] );
		add_action( 'woocommerce_add_to_cart', [ __CLASS__, 'note_add_to_cart' ], 10, 6 );
		add_action( 'woocommerce_created_customer', [ __CLASS__, 'note_registration' ] );
		add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'note_payment_info' ] );
	}

	private static function tracking_off() {
		$s = self::get();
		return is_admin() || is_customize_preview() || ( $s['exclude_admins'] && current_user_can( 'manage_options' ) );
	}

	public static function head() {
		$s = self::get();
		// Verification tag prints for everyone: Google fetches the page anonymously.
		if ( $s['gsc_verification'] ) {
			echo '<meta name="google-site-verification" content="' . esc_attr( $s['gsc_verification'] ) . '">' . "\n";
		}
		if ( self::tracking_off() ) {
			return;
		}
		if ( $s['ga4_id'] ) {
			$id = esc_js( $s['ga4_id'] );
			echo "<!-- WHD: GA4 -->\n<script async src=\"https://www.googletagmanager.com/gtag/js?id={$id}\"></script>\n<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{$id}');</script>\n";
		}
		if ( $s['fb_pixel_id'] ) {
			$id = esc_js( $s['fb_pixel_id'] );
			echo "<!-- WHD: Meta Pixel -->\n<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','{$id}');fbq('track','PageView');</script>\n";
		}
		if ( $s['head_extra'] ) {
			echo "<!-- WHD: custom head -->\n" . $s['head_extra'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- admin-provided script
		}
	}

	public static function body() {
		if ( self::tracking_off() ) {
			return;
		}
		$s = self::get();
		if ( $s['fb_pixel_id'] ) {
			echo '<noscript><img height="1" width="1" style="display:none" alt="" src="https://www.facebook.com/tr?id=' . esc_attr( $s['fb_pixel_id'] ) . '&ev=PageView&noscript=1"></noscript>' . "\n";
		}
		if ( $s['body_extra'] ) {
			echo $s['body_extra'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	public static function footer() {
		if ( self::tracking_off() ) {
			return;
		}
		$s = self::get();
		if ( $s['footer_extra'] ) {
			echo "<!-- WHD: custom footer -->\n" . $s['footer_extra'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/** GA4 + Pixel purchase events on the thank-you page (once per order). */
	/**
	 * One fbq call, written safely.
	 *
	 * @param string $event Meta's name for it.
	 * @param array  $params What to send with it.
	 */
	private static function fire( $event, array $params = [] ) {
		echo "<script>if(window.fbq){fbq('track'," . wp_json_encode( $event )
			. ( $params ? ',' . wp_json_encode( $params ) : '' ) . ");}</script>\n";
	}

	/** A product as Meta wants to hear about it. */
	private static function product_params( $product ) {
		if ( ! $product ) {
			return [];
		}

		return [
			'content_type' => 'product',
			'content_ids'  => [ (string) $product->get_id() ],
			'content_name' => $product->get_name(),
			'value'        => (float) $product->get_price(),
			'currency'     => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
		];
	}

	/**
	 * The events that belong to a page rather than to an action.
	 *
	 * Printed in the footer so the pixel in the head is certainly loaded by the time they run.
	 */
	public static function page_events() {
		if ( self::tracking_off() || ! self::get()['fb_pixel_id'] ) {
			return;
		}

		if ( self::sends( 'view_content' ) && function_exists( 'is_product' ) && is_product() ) {
			self::fire( 'ViewContent', self::product_params( wc_get_product( get_queried_object_id() ) ) );
		}

		if ( self::sends( 'view_category' ) && function_exists( 'is_shop' ) && ( is_shop() || is_product_category() || is_product_tag() ) ) {
			$term = is_shop() ? null : get_queried_object();
			self::fire( 'ViewCategory', [
				'content_type'     => 'product_group',
				'content_category' => $term && ! is_wp_error( $term ) ? $term->name : __( 'Shop', 'whd' ),
			] );
		}

		if ( self::sends( 'search' ) && is_search() ) {
			self::fire( 'Search', [ 'search_string' => (string) get_search_query() ] );
		}

		if ( self::sends( 'initiate_checkout' ) && function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() && WC()->cart && ! WC()->cart->is_empty() ) {
			$ids = [];
			foreach ( WC()->cart->get_cart() as $line ) {
				$ids[] = (string) $line['product_id'];
			}
			self::fire( 'InitiateCheckout', [
				'content_type' => 'product',
				'content_ids'  => $ids,
				'num_items'    => WC()->cart->get_cart_contents_count(),
				'value'        => (float) WC()->cart->get_total( 'edit' ),
				'currency'     => get_woocommerce_currency(),
			] );
		}

		/* Things that happened on the request before this one: an add to cart without AJAX, an
		   account just created, an order just placed. Each is read once and cleared. */
		foreach ( [ 'whd_px_cart' => 'AddToCart', 'whd_px_reg' => 'CompleteRegistration', 'whd_px_pay' => 'AddPaymentInfo' ] as $key => $event ) {
			$waiting = self::take( $key );
			if ( $waiting ) {
				self::fire( $event, is_array( $waiting ) ? $waiting : [] );
			}
		}
	}

	/** Park an event for the next page view, since this one is a redirect. */
	private static function park( $key, $params = true ) {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( $key, $params );
			return;
		}
		setcookie( $key, wp_json_encode( $params ), time() + 300, COOKIEPATH ?: '/', COOKIE_DOMAIN );
	}

	/** Read a parked event and forget it. */
	private static function take( $key ) {
		if ( function_exists( 'WC' ) && WC()->session ) {
			$v = WC()->session->get( $key );
			if ( $v ) {
				WC()->session->set( $key, null );
			}
			return $v;
		}
		if ( ! empty( $_COOKIE[ $key ] ) ) {
			$v = json_decode( wp_unslash( $_COOKIE[ $key ] ), true );
			setcookie( $key, '', time() - 3600, COOKIEPATH ?: '/', COOKIE_DOMAIN );
			return $v;
		}

		return null;
	}

	/**
	 * Something went into the basket.
	 *
	 * The AJAX button is handled in the browser, where the event can fire the moment it lands.
	 * This covers the other way in — a plain form post, or a ?add-to-cart= link — which ends in
	 * a redirect, so the event waits for the page that follows.
	 */
	public static function note_add_to_cart( $key, $product_id, $quantity, $variation_id = 0, $variation = [], $cart_item_data = [] ) {
		if ( ! self::sends( 'add_to_cart' ) || wp_doing_ajax() ) {
			return;
		}
		$product = wc_get_product( $variation_id ?: $product_id );
		$params  = self::product_params( $product );
		if ( $params ) {
			$params['value'] = (float) $params['value'] * max( 1, (int) $quantity );
			$params['contents'] = [ [ 'id' => (string) ( $variation_id ?: $product_id ), 'quantity' => (int) $quantity ] ];
		}
		self::park( 'whd_px_cart', $params ?: true );
	}

	public static function note_registration( $customer_id ) {
		if ( self::sends( 'complete_registration' ) ) {
			self::park( 'whd_px_reg', [ 'status' => 'customer' ] );
		}
	}

	/** The order was placed, which is the moment payment details were handed over. */
	public static function note_payment_info( $order_id ) {
		if ( ! self::sends( 'add_payment_info' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		self::park( 'whd_px_pay', $order ? [
			'value'    => (float) $order->get_total(),
			'currency' => $order->get_currency(),
		] : true );
	}

	/**
	 * The two events that happen without a page load.
	 *
	 * AddToCart from WooCommerce's AJAX button, and Lead when a newsletter form reports back —
	 * the theme announces that on the document rather than leaving it to be guessed at.
	 */
	public static function pixel_assets() {
		if ( self::tracking_off() || ! self::get()['fb_pixel_id'] ) {
			return;
		}
		if ( ! self::sends( 'add_to_cart' ) && ! self::sends( 'lead' ) && ! self::sends( 'add_to_wishlist' ) ) {
			return;
		}
		wp_enqueue_script( 'whd-pixel', WHD_URL . 'assets/pixel.js', [], WHD_VERSION, true );
		wp_localize_script( 'whd-pixel', 'WHD_PIXEL', [
			'cart'     => self::sends( 'add_to_cart' ),
			'lead'     => self::sends( 'lead' ),
			'wishlist' => self::sends( 'add_to_wishlist' ),
			'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
		] );
	}

	public static function purchase_event( $order_id ) {
		if ( self::tracking_off() ) {
			return;
		}
		$s     = self::get();
		$order = wc_get_order( $order_id );
		if ( ! $order || ( ! $s['ga4_id'] && ! $s['fb_pixel_id'] ) || $order->get_meta( '_whd_tracked' ) ) {
			return;
		}
		$order->update_meta_data( '_whd_tracked', 1 );
		$order->save();
		$items = [];
		foreach ( $order->get_items() as $item ) {
			$p       = $item->get_product();
			$items[] = [ 'item_id' => $p ? $p->get_sku() ?: (string) $p->get_id() : (string) $item->get_product_id(), 'item_name' => $item->get_name(), 'quantity' => $item->get_quantity(), 'price' => round( (float) $item->get_total() / max( 1, $item->get_quantity() ), 2 ) ];
		}
		$payload = [ 'transaction_id' => (string) $order->get_order_number(), 'value' => (float) $order->get_total(), 'currency' => $order->get_currency(), 'tax' => (float) $order->get_total_tax(), 'shipping' => (float) $order->get_shipping_total(), 'items' => $items ];
		echo '<script>';
		if ( $s['ga4_id'] ) {
			echo "if(window.gtag){gtag('event','purchase'," . wp_json_encode( $payload ) . ');}';
		}
		if ( $s['fb_pixel_id'] ) {
			echo "if(window.fbq){fbq('track','Purchase',{value:" . (float) $order->get_total() . ",currency:'" . esc_js( $order->get_currency() ) . "',content_type:'product',content_ids:" . wp_json_encode( array_column( $items, 'item_id' ) ) . '});}';
		}
		echo '</script>';
	}
}
