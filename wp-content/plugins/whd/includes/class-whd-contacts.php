<?php
/**
 * The addresses the shop collects that are not newsletter sign-ups.
 *
 * Six places take an email without anybody asking to be marketed to: the checkout, the box that
 * captures a guest address part way through it, the "tell me when my size is back" form, the
 * contact form, a new account, and a review or a comment. The first three stopped at a table in
 * this database; the last three stopped nowhere at all.
 *
 * All six now go across. By default they go as profiles and nothing more — no list, no marketing
 * consent — which is what a cart flow or an order-based segment needs and what the law allows
 * without a tick. The tick is at the checkout: tick it and the ordinary subscriber path runs
 * instead, list and welcome and all.
 *
 * Whether that default holds is the shop's call: WHD → Integrations → "Who joins the list" sends
 * every one of them to the list instead. It starts off, because mailing people who did not ask is
 * what gets a sending account closed.
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

		// A question through the contact form (the theme hands it over).
		add_action( 'omc_contact_message', [ __CLASS__, 'on_contact_message' ] );

		// Somebody who opened an account.
		add_action( 'user_register', [ __CLASS__, 'on_register' ] );

		// A review on a product, or a comment under a story.
		add_action( 'comment_post', [ __CLASS__, 'on_comment' ], 10, 3 );

		// The tick box itself.
		add_filter( 'woocommerce_checkout_fields', [ __CLASS__, 'checkout_field' ] );
		add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'save_optin' ], 10, 2 );
	}

	/* ─────────────────────────── where an address goes ─────────────────────────── */

	/**
	 * Hand one person over, as whatever the shop has decided they are.
	 *
	 * Everything in this file arrives without a tick. The setting says whether that still puts
	 * them on the mailing list; by default it does not, and they become a profile that flows and
	 * segments can see but a campaign cannot reach.
	 *
	 * @param array $contact email, first_name, last_name, phone, source, properties[].
	 */
	private static function hand_over( array $contact ) {
		$email = sanitize_email( $contact['email'] ?? '' );
		if ( ! is_email( $email ) ) {
			return;
		}
		$contact['email'] = $email;

		if ( WHD_Integrations::list_everyone() && class_exists( 'WHD_Subscribers' ) ) {
			WHD_Subscribers::add(
				$email,
				trim( ( $contact['first_name'] ?? '' ) . ' ' . ( $contact['last_name'] ?? '' ) ),
				(string) ( $contact['source'] ?? '' ),
				(string) ( $contact['phone'] ?? '' )
			);
			return;
		}

		WHD_Integrations::queue_contact( $contact );
	}

	/* ─────────────────────────── the inputs ─────────────────────────── */

	/** The contact form: a question, and the address it should be answered at. */
	public static function on_contact_message( $message ) {
		$message = is_array( $message ) ? $message : [];
		self::hand_over( [
			'email'      => (string) ( $message['email'] ?? '' ),
			'first_name' => (string) ( $message['name'] ?? '' ),
			'source'     => 'contact_form',
			'properties' => [
				'Last Question'      => mb_substr( wp_strip_all_tags( (string) ( $message['subject'] ?? '' ) ), 0, 120 ),
				'Last Question Date' => current_time( 'c' ),
				'Accepts Marketing'  => WHD_Integrations::list_everyone(),
			],
		] );
	}

	/** A new account. Registering is not asking for a newsletter, but the shop knows them now. */
	public static function on_register( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return;
		}
		self::hand_over( [
			'email'      => $user->user_email,
			'first_name' => $user->first_name,
			'last_name'  => $user->last_name,
			'source'     => 'account',
			'properties' => [
				'Account Created'   => current_time( 'c' ),
				'Accepts Marketing' => WHD_Integrations::list_everyone(),
			],
		] );
	}

	/** A review on a product, or a comment under a story. */
	public static function on_comment( $comment_id, $approved, $data = [] ) {
		$data  = is_array( $data ) ? $data : [];
		$email = (string) ( $data['comment_author_email'] ?? '' );
		if ( ! is_email( $email ) ) {
			return; // a logged-in comment that carried no address
		}
		if ( in_array( (string) $approved, [ 'spam', 'trash' ], true ) ) {
			return; // spam carries an address too, and it is never a customer
		}
		$review = 'review' === (string) ( $data['comment_type'] ?? '' );
		$about  = get_the_title( (int) ( $data['comment_post_ID'] ?? 0 ) );

		self::hand_over( [
			'email'      => $email,
			'first_name' => (string) ( $data['comment_author'] ?? '' ),
			'source'     => $review ? 'review' : 'comment',
			'properties' => [
				( $review ? 'Last Review Of' : 'Last Comment On' ) => $about,
				'Last Said Something' => current_time( 'c' ),
				'Accepts Marketing'   => WHD_Integrations::list_everyone(),
			],
		] );
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

		self::hand_over( [
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
				'Accepts Marketing'   => WHD_Integrations::list_everyone(),
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
		self::hand_over( [
			'email'      => $email,
			'first_name' => (string) ( $data['first_name'] ?? '' ),
			'phone'      => (string) ( $data['phone'] ?? '' ),
			'source'     => 'checkout_started',
			'properties' => [
				'Cart Items'       => (int) ( $data['item_count'] ?? 0 ),
				'Cart Total'       => (float) ( $data['total'] ?? 0 ),
				'Cart Updated'     => current_time( 'c' ),
				'Accepts Marketing' => WHD_Integrations::list_everyone(),
			],
		] );
	}

	/** Somebody waiting on a size — the one thing they did ask to hear about. */
	public static function on_stock_alert( $email, $product_id, $variation_id = 0 ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product_id ) : null;
		$item    = $variation_id && function_exists( 'wc_get_product' ) ? wc_get_product( (int) $variation_id ) : null;

		self::hand_over( [
			'email'      => $email,
			'source'     => 'back_in_stock',
			'properties' => [
				'Waiting For'       => $product ? $product->get_name() : '',
				'Waiting For Size'  => $item ? implode( ', ', array_filter( $item->get_variation_attributes() ) ) : '',
				'Waiting Since'     => current_time( 'c' ),
				'Accepts Marketing' => WHD_Integrations::list_everyone(),
			],
		] );
	}
}
