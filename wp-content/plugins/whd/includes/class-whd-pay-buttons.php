<?php
/**
 * Where the fast-pay buttons appear: Stripe's and WooPayments' wallets on the cart page and at checkout, PayPal's only in
 * its own row at checkout; none on a product page or in the slide-out cart. The owner's calls.
 *
 * Three plugins draw them, none of which is in this repository (they are installed on live only):
 * Stripe (Apple Pay, Google Pay, Link), WooPayments and PayPal (PayPal, Venmo, Pay Later). Each has a
 * setting for this, but a setting would be a production database write and a later change on its screen
 * would quietly undo the decision, so it is said here instead, through the plugins' own filters.
 *
 * This is in the plugin and not the theme because of when it runs: Stripe and WooPayments read their
 * settings on plugins_loaded, before any theme has loaded. It has to be hooked as this file is included.
 *
 * Checked against Stripe 11.1.1, WooPayments 11.2.0 and PayPal Payments 4.2.0. A filter that a later
 * version drops simply stops doing anything, and the buttons would come back: nothing breaks.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Pay_Buttons {

	public static function init() {
		add_filter( 'option_woocommerce_stripe_settings', [ __CLASS__, 'stripe' ] );
		add_filter( 'wc_stripe_hide_payment_request_on_product_page', '__return_true' );

		add_filter( 'option_woocommerce_woocommerce_payments_settings', [ __CLASS__, 'woopayments' ] );
		add_filter( 'wcpay_payment_request_is_product_supported', '__return_false' );

		add_filter( 'woocommerce_paypal_payments_selected_button_locations', [ __CLASS__, 'paypal' ] );
		add_filter( 'woocommerce_paypal_payments_product_buttons_paylater_disabled', '__return_true' ); // its "pay later" line above the buttons
	}

	/** A list of places, without the product page and with the cart. */
	private static function places( $list ): array {
		$list = is_array( $list ) ? $list : [];
		return array_values( array_unique( array_merge( array_diff( $list, [ 'product' ] ), [ 'cart' ] ) ) );
	}

	/** Stripe's three lists. Left unset it means product and cart, so unset gets the same treatment. */
	public static function stripe( $settings ) {
		if ( is_admin() || ! is_array( $settings ) ) {
			return $settings; // its own settings screen shows what is stored
		}
		foreach ( [ 'express_checkout_button_locations', 'link_button_locations' ] as $key ) {
			$settings[ $key ] = self::places( $settings[ $key ] ?? [] );
		}
		if ( isset( $settings['amazon_pay_button_locations'] ) ) {
			$settings['amazon_pay_button_locations'] = self::places( $settings['amazon_pay_button_locations'] );
		}
		return $settings;
	}

	/**
	 * WooPayments lists its methods by place: what the product page had moves to the cart.
	 *
	 * Its Apple Pay and Google Pay stay off the cart and the checkout while Stripe is on, the owner's call. Both plugins
	 * draw those buttons, so a shopper saw Google Pay twice, and Stripe is where the card money goes. With nothing left on
	 * its list for a page, WooPayments prints no button and loads no script there.
	 */
	public static function woopayments( $settings ) {
		if ( is_admin() || ! is_array( $settings ) ) {
			return $settings;
		}
		$product  = (array) ( $settings['express_checkout_product_methods'] ?? [] );
		$cart     = (array) ( $settings['express_checkout_cart_methods'] ?? [] );
		$checkout = (array) ( $settings['express_checkout_checkout_methods'] ?? [] );
		$cart     = array_unique( array_merge( $cart, $product ) );

		if ( self::stripe_on() ) {
			$cart     = array_diff( $cart, [ 'payment_request' ] );
			$checkout = array_diff( $checkout, [ 'payment_request' ] );
		}

		$settings['express_checkout_product_methods']  = [];
		$settings['express_checkout_cart_methods']     = array_values( $cart );
		$settings['express_checkout_checkout_methods'] = array_values( $checkout );
		return $settings;
	}

	private static function stripe_on(): bool {
		$stripe = get_option( 'woocommerce_stripe_settings' );
		return is_array( $stripe ) && 'yes' === ( $stripe['enabled'] ?? '' );
	}

	/**
	 * PayPal keeps one filter over its lists of places for buttons, Pay Later and messages.
	 *
	 * Only the checkout, where its buttons sit inside its own payment row, the owner's calls: never the product page,
	 * never the slide-out cart ("mini-cart", where its Venmo button is drawn too), and — since 2026-11-23 — not under
	 * "Proceed to checkout" on the cart page ("cart", and "cart-block" for a block cart), even if PayPal's own settings
	 * ask for any of them. Its Pay Later messages leave those places with the buttons.
	 */
	public static function paypal( $places ) {
		$places = is_array( $places ) ? $places : [];
		return array_values( array_diff( $places, [ 'product', 'cart', 'cart-block', 'mini-cart' ] ) );
	}
}
