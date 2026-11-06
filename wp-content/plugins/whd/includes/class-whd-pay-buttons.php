<?php
/**
 * Where the fast-pay buttons appear: not on a product page, and in the cart. The owner's call.
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

	/** WooPayments lists its methods by place: what the product page had moves to the cart. */
	public static function woopayments( $settings ) {
		if ( is_admin() || ! is_array( $settings ) ) {
			return $settings;
		}
		$product = (array) ( $settings['express_checkout_product_methods'] ?? [] );
		$cart    = (array) ( $settings['express_checkout_cart_methods'] ?? [] );

		$settings['express_checkout_product_methods'] = [];
		$settings['express_checkout_cart_methods']    = array_values( array_unique( array_merge( $cart, $product ) ) );
		return $settings;
	}

	/**
	 * PayPal keeps one filter over its lists of places for buttons, Pay Later and messages.
	 *
	 * The slide-out cart ("mini-cart") is the one place that loads PayPal's script on any page it is on,
	 * so it is only asked for where there can be something in it: a store page, or a cart with something
	 * in it. The home page does not need to fetch PayPal for somebody just looking.
	 */
	public static function paypal( $places ) {
		$places = self::places( $places );
		$cart   = function_exists( 'WC' ) && WC()->cart ? WC()->cart : null;
		$store  = function_exists( 'is_woocommerce' ) && did_action( 'wp' ) && is_woocommerce();
		if ( $store || ( $cart && ! $cart->is_empty() ) ) {
			$places[] = 'mini-cart';
		}
		return array_values( array_unique( $places ) );
	}
}
