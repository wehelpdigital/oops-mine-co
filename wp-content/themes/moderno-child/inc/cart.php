<?php
/**
 * Oops, Mine Co. — cart persuasion layer.
 *
 * Three small pieces that sit on top of WooCommerce and the parent theme's
 * slide-out cart (templates/header-desktop-cart.php + header-mobile-cart.php,
 * both printing an empty `.widget_shopping_cart_content` that WooCommerce fills
 * with fragments):
 *
 *   1. a free-shipping progress bar at the top of the mini cart and above the
 *      cart table, kept live by WooCommerce's own fragment refresh;
 *   2. drawer footer extras — a quiet "Continue shopping" link that closes the
 *      drawer plus a one-line trust note;
 *   3. a warm line under the theme's empty mini-cart message.
 *
 * Everything renders through WooCommerce hooks, so no template is overridden.
 * Assets (assets/css/omc-cart.css, assets/js/omc-cart.js) are enqueued from
 * functions.php.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

/* ───────────────────────── Free-shipping threshold ───────────────────────── */

/**
 * Resolve the free-shipping rule the bar measures against.
 *
 * Order: the first enabled `free_shipping` method that asks for a minimum order
 * amount (every shipping zone, then the catch-all zone 0), else the
 * WHD plugin setting `free_shipping_threshold`, else 75. The final number always
 * runs through the `omc_free_shipping_threshold` filter, so a site can override
 * it (return 0 or false to switch the bar off).
 *
 * @return array{amount:float,ignore_discounts:bool,source:string}
 */
function omc_free_shipping_rule() {
	static $rule = null;

	if ( null !== $rule ) {
		return $rule;
	}

	$amount = 0.0;
	$ignore = false;
	$source = 'default';

	if ( class_exists( 'WC_Shipping_Zones' ) ) {
		$methods = [];

		foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
			if ( ! empty( $zone['shipping_methods'] ) && is_array( $zone['shipping_methods'] ) ) {
				$methods = array_merge( $methods, array_values( $zone['shipping_methods'] ) );
			}
		}

		if ( class_exists( 'WC_Shipping_Zone' ) ) {
			$fallback_zone = new WC_Shipping_Zone( 0 );
			$methods       = array_merge( $methods, array_values( $fallback_zone->get_shipping_methods( false ) ) );
		}

		foreach ( $methods as $method ) {
			if ( ! is_object( $method ) || ! isset( $method->id ) || 'free_shipping' !== $method->id ) {
				continue;
			}
			if ( isset( $method->enabled ) && 'yes' !== $method->enabled ) {
				continue;
			}
			// A method set to "a valid free shipping coupon" only ignores its minimum.
			if ( isset( $method->requires ) && ! in_array( $method->requires, [ 'min_amount', 'either', 'both' ], true ) ) {
				continue;
			}

			$min = isset( $method->min_amount ) ? (float) wc_format_decimal( $method->min_amount ) : 0.0;

			if ( $min <= 0 ) {
				continue;
			}

			$amount = $min;
			$ignore = isset( $method->ignore_discounts ) && 'yes' === $method->ignore_discounts;
			$source = 'zone';
			break;
		}
	}

	if ( $amount <= 0 && class_exists( 'WHD_Plugin' ) && method_exists( 'WHD_Plugin', 'settings' ) ) {
		$settings = WHD_Plugin::settings();

		// A stored 0 is a decision, not an absence: the WHD settings screen says "0 hides the bar".
		if ( is_array( $settings ) && array_key_exists( 'free_shipping_threshold', $settings ) ) {
			$amount = (float) $settings['free_shipping_threshold'];
			$source = $amount > 0 ? 'whd' : 'whd-off';
		}
	}

	/*
	 * No fallback number here on purpose. The bar makes a commercial promise, and this store
	 * currently has no free-shipping method at all, so inventing a threshold would tell every
	 * shopper they are a few dollars from something checkout cannot honour. The bar appears once a
	 * real free-shipping zone exists, or once the owner sets a threshold in WHD → Settings.
	 */

	/**
	 * Filter the free-shipping threshold the progress bar uses.
	 *
	 * @param float  $amount Resolved threshold in store currency.
	 * @param string $source Where it came from: zone | whd | default.
	 */
	$amount = (float) apply_filters( 'omc_free_shipping_threshold', $amount, $source );

	$rule = [
		'amount'           => max( 0.0, $amount ),
		'ignore_discounts' => $ignore,
		'source'           => $source,
	];

	return $rule;
}

/**
 * The threshold on its own.
 *
 * @return float 0 when the bar is switched off.
 */
function omc_free_shipping_threshold() {
	$rule = omc_free_shipping_rule();

	return (float) $rule['amount'];
}

/**
 * Cart amount measured against the threshold — the same sum WooCommerce's own
 * free-shipping method compares (displayed subtotal, minus discounts unless the
 * method ignores them).
 *
 * @return float
 */
function omc_cart_free_shipping_total() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return 0.0;
	}

	$cart = WC()->cart;
	$rule = omc_free_shipping_rule();

	$total = (float) $cart->get_displayed_subtotal();

	if ( empty( $rule['ignore_discounts'] ) ) {
		$total -= (float) $cart->get_discount_total();

		if ( $cart->display_prices_including_tax() ) {
			$total -= (float) $cart->get_discount_tax();
		}
	}

	$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;

	/**
	 * Filter the cart amount that counts towards free shipping.
	 *
	 * @param float $total Cart amount.
	 */
	return (float) apply_filters( 'omc_free_shipping_cart_total', round( $total, $decimals ) );
}

/* ─────────────────────────── The progress bar ─────────────────────────── */

/**
 * Build the progress bar markup.
 *
 * @param array $args {
 *     @type string $variant mini (inside the drawer) | standalone (cart page, shortcode).
 *     @type string $class   Extra classes.
 *     @type bool   $shell   Keep an empty (hidden) element when there is nothing
 *                           to show, so the fragment can still find and replace it.
 * }
 * @return string
 */
function omc_free_shipping_bar( $args = [] ) {
	$args = wp_parse_args( $args, [
		'variant' => 'standalone',
		'class'   => '',
		'shell'   => true,
	] );

	$variant = 'mini' === $args['variant'] ? 'mini' : 'standalone';
	$classes = array_filter( array_merge(
		[ 'omc-ship', 'omc-ship--' . $variant ],
		preg_split( '/\s+/', (string) $args['class'], -1, PREG_SPLIT_NO_EMPTY ) ?: []
	) );

	$shell = $args['shell']
		? '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" data-omc-ship="' . esc_attr( $variant ) . '" hidden></div>'
		: '';

	if ( ! function_exists( 'WC' ) || ! WC()->cart || ! function_exists( 'wc_price' ) ) {
		return $shell;
	}

	$threshold = omc_free_shipping_threshold();

	// Switched off: print nothing at all (the fragment is not registered either).
	if ( $threshold <= 0 ) {
		return '';
	}

	// Empty cart: keep the (hidden) element so the fragment can fill it later.
	if ( WC()->cart->is_empty() ) {
		return $shell;
	}

	$decimals  = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
	$total     = omc_cart_free_shipping_total();
	$remaining = max( 0.0, round( $threshold - $total, $decimals ) );
	$complete  = $remaining <= 0;
	$percent   = $complete ? 100.0 : max( 0.0, min( 100.0, ( $total / $threshold ) * 100 ) );

	/**
	 * Filter the progress-bar copy.
	 *
	 * @param array  $strings   away | done | label.
	 * @param float  $remaining Amount still needed.
	 * @param float  $threshold The threshold.
	 * @param string $variant   mini | standalone.
	 */
	$strings = apply_filters( 'omc_free_shipping_bar_strings', [
		/* translators: %s: amount still needed, formatted with wc_price(). */
		'away'  => __( "You're %s away from free shipping", 'moderno-child' ),
		'done'  => __( 'Free shipping unlocked — oops, mine.', 'moderno-child' ),
		'label' => __( 'Free shipping progress', 'moderno-child' ),
	], $remaining, $threshold, $variant );

	$message = $complete
		? esc_html( $strings['done'] )
		: sprintf(
			esc_html( $strings['away'] ),
			// wc_price() is core-generated markup (<bdi>, currency span); kses would strip it.
			'<span class="omc-ship__amount">' . wc_price( $remaining ) . '</span>'
		);

	if ( $complete ) {
		$classes[] = 'is-complete';
	}

	$html  = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"'
		. ' data-omc-ship="' . esc_attr( $variant ) . '"'
		. ' data-pct="' . esc_attr( number_format( $percent, 2, '.', '' ) ) . '"'
		. ' data-complete="' . ( $complete ? '1' : '0' ) . '">';
	$html .= '<p class="omc-ship__label" aria-live="polite">' . $message . '</p>';
	$html .= '<div class="omc-ship__track" role="progressbar" aria-valuemin="0" aria-valuemax="100"'
		. ' aria-valuenow="' . esc_attr( (string) (int) round( $percent ) ) . '"'
		. ' aria-label="' . esc_attr( $strings['label'] ) . '">';
	$html .= '<span class="omc-ship__fill" style="width:' . esc_attr( number_format( $percent, 2, '.', '' ) ) . '%"></span>';
	$html .= '</div></div>';

	return $html;
}

/** Top of the mini cart — inside `.widget_shopping_cart_content`, so fragments keep it current. */
function omc_mini_cart_shipping_bar() {
	echo omc_free_shipping_bar( [ 'variant' => 'mini', 'shell' => false ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in omc_free_shipping_bar().
}
add_action( 'woocommerce_before_mini_cart', 'omc_mini_cart_shipping_bar', 5 );

/** Cart page — above the cart table. */
function omc_cart_page_shipping_bar() {
	echo omc_free_shipping_bar( [ 'class' => 'omc-ship--page' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in omc_free_shipping_bar().
}
add_action( 'woocommerce_before_cart_table', 'omc_cart_page_shipping_bar', 5 );

/**
 * Stand-alone instances (cart page, shortcode, anything with the class) ride
 * along with WooCommerce's fragments, so they update on added_to_cart /
 * wc_fragments_refreshed / updated_wc_div without a reload.
 *
 * @param array $fragments Fragments keyed by selector.
 * @return array
 */
function omc_cart_fragments( $fragments ) {
	if ( omc_free_shipping_threshold() <= 0 ) {
		return $fragments;
	}

	$fragments['div.omc-ship--standalone'] = omc_free_shipping_bar( [ 'class' => 'omc-ship--page' ] );

	return $fragments;
}
add_filter( 'woocommerce_add_to_cart_fragments', 'omc_cart_fragments' );

/** `[omc_free_shipping_bar]` — the same bar anywhere (it updates with the fragments too). */
function omc_free_shipping_bar_shortcode( $atts ) {
	$atts = shortcode_atts( [ 'class' => '' ], $atts, 'omc_free_shipping_bar' );

	return omc_free_shipping_bar( [ 'class' => $atts['class'] ] );
}
add_shortcode( 'omc_free_shipping_bar', 'omc_free_shipping_bar_shortcode' );

/* ─────────────────────────── Drawer footer extras ─────────────────────────── */

/**
 * Under the mini cart's Cart / Checkout buttons: a quiet way back to shopping
 * (it closes the drawer instead of loading a page) and a one-line trust note.
 */
function omc_mini_cart_extras() {
	$shop = function_exists( 'omc_shop_url' ) ? omc_shop_url() : home_url( '/shop/' );

	$strings = apply_filters( 'omc_mini_cart_extras_strings', [
		'back'  => __( 'Continue shopping', 'moderno-child' ),
		// The return window and store-credit rule are the client's own words; the restocking fee and
		// the final-sale list are not, so the line links to the policy instead of implying free returns.
		'trust' => __( 'Secure checkout · 7-day store-credit returns', 'moderno-child' ),
		'terms' => __( 'See the return policy', 'moderno-child' ),
	] );

	$policy = function_exists( 'omc_page_url' ) ? omc_page_url( 'refund_returns', '/refund_returns/' ) : '';

	$icon = function_exists( 'omc_icon' ) ? omc_icon( 'lock' ) : '';

	echo '<div class="omc-cart-extras">';
	echo '<a class="omc-cart-extras__back" href="' . esc_url( $shop ) . '" data-omc-cart-close rel="nofollow">' . esc_html( $strings['back'] ) . '</a>';
	echo '<p class="omc-cart-extras__trust">' . $icon . '<span>' . esc_html( $strings['trust'] ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- omc_icon() returns a static inline SVG.
	if ( $policy ) {
		echo '<a class="omc-cart-extras__terms" href="' . esc_url( $policy ) . '">' . esc_html( $strings['terms'] ) . '</a>';
	}
	echo '</div>';
}
add_action( 'woocommerce_widget_shopping_cart_after_buttons', 'omc_mini_cart_extras' );

/**
 * Empty drawer: the theme prints WooCommerce's "No products in the cart."; this
 * adds a warm line and a way in. `woocommerce_after_mini_cart` is the only hook
 * the empty branch of the mini-cart template reaches.
 */
function omc_mini_cart_empty_note() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->cart->is_empty() ) {
		return;
	}

	$url = function_exists( 'omc_new_arrivals_url' )
		? omc_new_arrivals_url()
		: add_query_arg( 'orderby', 'date', home_url( '/shop/' ) );

	$strings = apply_filters( 'omc_mini_cart_empty_strings', [
		'line' => __( 'Nothing here yet. Find something yours.', 'moderno-child' ),
		'cta'  => __( 'Shop new arrivals', 'moderno-child' ),
	] );

	echo '<div class="omc-cart-empty">';
	echo '<p class="omc-cart-empty__line">' . esc_html( $strings['line'] ) . '</p>';
	echo '<a class="omc-btn omc-btn--outline omc-cart-empty__btn" href="' . esc_url( $url ) . '">' . esc_html( $strings['cta'] ) . '</a>';
	echo '</div>';
}
add_action( 'woocommerce_after_mini_cart', 'omc_mini_cart_empty_note' );
