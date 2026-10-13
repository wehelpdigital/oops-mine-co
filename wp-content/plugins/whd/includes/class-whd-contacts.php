<?php
/**
 * The addresses the shop collects that are not newsletter sign-ups.
 *
 * Three places take an email without anybody asking to be marketed to: the checkout, the box that
 * captures a guest address part way through it, and the "tell me when my size is back" form. Until
 * now all three stopped at a table in this database, so the email platform never heard of a single
 * paying customer.
 *
 * They go across as profiles and nothing more — no list, no marketing consent — which is what a
 * cart flow or an order-based segment needs and what the law allows without a tick. The tick is at
 * the checkout: tick it and the ordinary subscriber path runs instead, list and welcome and all.
 *
 * Reviews and the contact form are deliberately not here. Somebody asking a question has not asked
 * to hear from the shop again.
 *
 * @package WHD
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Contacts {

	/** The checkbox at checkout, and the order meta it leaves behind. */
	const FIELD = 'whd_marketing_optin';
	const META  = '_whd_marketing_optin';

	public static function init() {
		if ( ! class_exists( 'WHD_Integrations' ) ) {
			return;
		}

		// The order: a customer, with what they spent.
		add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'on_order' ], 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'on_store_api_order' ], 20 );

		// The address typed into the checkout before it was finished.
		add_action( 'whd_cart_captured', [ __CLASS__, 'on_cart' ], 10, 2 );

		// Somebody waiting for a size.
		add_action( 'whd_stock_alert_requested', [ __CLASS__, 'on_stock_alert' ], 10, 3 );

		// The tick box itself.
		add_filter( 'woocommerce_checkout_fields', [ __CLASS__, 'checkout_field' ] );
		add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'save_optin' ], 10, 2 );
	}

	/* ─────────────────────────── the checkout tick ─────────────────────────── */

	/** Ask once, in plain words, under the order notes. */
	public static function checkout_field( $fields ) {
		if ( ! WHD_Integrations::email_enabled() ) {
			return $fields;
		}
		$fields['order'][ self::FIELD ] = [
			'type'        => 'checkbox',
			'label'       => apply_filters(
				'whd_checkout_optin_label',
				__( 'Email me now and then about new pieces and what is back in stock.', 'whd' )
			),
			'required'    => false,
			'class'       => [ 'form-row-wide', 'whd-optin' ],
			'priority'    => 110,
			// Never pre-ticked: a pre-ticked box is not consent in any country that has rules.
			'default'     => 0,
		];

		return $fields;
	}

	/** Keep the answer on the order, so it can be shown and audited later. */
	public static function save_optin( $order, $data ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce.
		$ticked = ! empty( $_POST[ self::FIELD ] );
		$order->update_meta_data( self::META, $ticked ? 'yes' : 'no' );
	}

	/* ─────────────────────────── what each one sends ─────────────────────────── */

	public static function on_order( $order_id, $posted = [], $order = null ) {
		self::push_order( $order instanceof WC_Order ? $order : wc_get_order( $order_id ) );
	}

	/** The block checkout hands over the order itself. */
	public static function on_store_api_order( $order ) {
		self::push_order( $order instanceof WC_Order ? $order : wc_get_order( $order ) );
	}

	/**
	 * A customer, with the figures a segment would want.
	 *
	 * Ticked the box: they go through WHD_Subscribers like any sign-up, which puts them on the list
	 * and sends the welcome. Did not: a profile, so the shop knows them without mailing them.
	 */
	private static function push_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$email = $order->get_billing_email();
		if ( ! is_email( $email ) ) {
			return;
		}
		$first = $order->get_billing_first_name();
		$last  = $order->get_billing_last_name();

		if ( 'yes' === $order->get_meta( self::META ) && class_exists( 'WHD_Subscribers' ) ) {
			WHD_Subscribers::add( $email, trim( $first . ' ' . $last ), 'checkout', $order->get_billing_phone() );
			return;
		}

		WHD_Integrations::queue_contact( [
			'email'      => $email,
			'first_name' => $first,
			'last_name'  => $last,
			'phone'      => $order->get_billing_phone(),
			'source'     => 'checkout',
			'properties' => [
				'Last Order Number'   => $order->get_order_number(),
				'Last Order Total'    => (float) $order->get_total(),
				'Last Order Currency' => $order->get_currency(),
				'Last Order Date'     => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : '',
				'Orders Placed'       => self::order_count( $email ),
				'Accepts Marketing'   => false,
			],
		] );
	}

	/** How many orders this address has, so a second purchase is visible as one. */
	private static function order_count( $email ): int {
		$orders = wc_get_orders( [
			'billing_email' => $email,
			'limit'         => 20,
			'status'        => [ 'wc-processing', 'wc-completed', 'wc-on-hold' ],
			'return'        => 'ids',
		] );

		return is_array( $orders ) ? count( $orders ) : 0;
	}

	/** An address typed at the checkout and left there. */
	public static function on_cart( $email, $data = [] ) {
		WHD_Integrations::queue_contact( [
			'email'      => $email,
			'first_name' => (string) ( $data['first_name'] ?? '' ),
			'phone'      => (string) ( $data['phone'] ?? '' ),
			'source'     => 'checkout_started',
			'properties' => [
				'Cart Items'       => (int) ( $data['item_count'] ?? 0 ),
				'Cart Total'       => (float) ( $data['total'] ?? 0 ),
				'Cart Updated'     => current_time( 'c' ),
				'Accepts Marketing' => false,
			],
		] );
	}

	/** Somebody waiting on a size — the one thing they did ask to hear about. */
	public static function on_stock_alert( $email, $product_id, $variation_id = 0 ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product_id ) : null;
		$item    = $variation_id && function_exists( 'wc_get_product' ) ? wc_get_product( (int) $variation_id ) : null;

		WHD_Integrations::queue_contact( [
			'email'      => $email,
			'source'     => 'back_in_stock',
			'properties' => [
				'Waiting For'       => $product ? $product->get_name() : '',
				'Waiting For Size'  => $item ? implode( ', ', array_filter( $item->get_variation_attributes() ) ) : '',
				'Waiting Since'     => current_time( 'c' ),
				'Accepts Marketing' => false,
			],
		] );
	}
}
