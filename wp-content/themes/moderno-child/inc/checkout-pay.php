<?php
/**
 * The checkout's payment section: a choice first, then the form or button for that choice.
 *
 * On live several plugins fill this section (Stripe's card, Afterpay; WooPayments' card; PayPal; and the
 * wallets, printed apart from the list and above the shopper's details). Each brought its own icons, and
 * the first was always open, so a shopper met a form they had not asked for.
 *
 * Every option is now a row of the same shape, with the marks in the shop's own quiet tone until chosen.
 * Nothing is open until one is pressed, and the wallets become a row too. The order is placed exactly as
 * before: WooCommerce still has its own radio checked underneath, and the plugins see nothing different,
 * because "nothing chosen yet" is only a class on the body that hides things (assets/js/omc-checkout-pay.js).
 * So if the script does not run, the shopper gets WooCommerce's ordinary list, which works. Filter
 * `omc_checkout_pay_choice` returning false turns the whole of it off.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

function omc_checkout_pay_on() {
	return function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() && ! is_wc_endpoint_url( 'order-pay' ) && apply_filters( 'omc_checkout_pay_choice', true );
}

/**
 * What a row says and shows, by the kind of gateway. Its title stays the plugin's own, since that is
 * what the receipts and the admin call it.
 *
 * The column is narrow and the marks say most of it, so a note is only for what they cannot: how a thing works.
 *
 * @return array [ note, marks[] ]. A mark with a file in assets/img/marks is drawn; any other is its name, set as type.
 */
function omc_checkout_pay_row( $gateway_id ) {
	$cards = [ 'visa', 'mastercard', 'americanexpress' ];
	$id    = (string) $gateway_id;
	if ( 'ppcp-gateway' === $id || 'paypal' === $id ) {
		$row = [ '', [ 'paypal' ] ];
	} elseif ( false !== strpos( $id, 'venmo' ) ) {
		$row = [ '', [ 'venmo' ] ];
	} elseif ( false !== strpos( $id, 'afterpay' ) ) {
		$row = [ __( 'Pay over time', 'moderno-child' ), [ 'afterpay' ] ];
	} elseif ( false !== strpos( $id, 'klarna' ) ) {
		$row = [ __( 'Pay over time', 'moderno-child' ), [ 'klarna' ] ];
	} elseif ( false !== strpos( $id, 'affirm' ) ) {
		$row = [ __( 'Pay over time', 'moderno-child' ), [ 'Affirm' ] ];
	} elseif ( false !== strpos( $id, 'link' ) ) {
		$row = [ __( 'Your saved details', 'moderno-child' ), [ 'Link' ] ];
	} elseif ( in_array( $id, [ 'stripe', 'woocommerce_payments', 'ppcp-credit-card-gateway', 'ppcp-card-button-gateway' ], true ) || false !== strpos( $id, 'card' ) ) {
		$row = [ '', $cards ];
	} else {
		$row = [ '', [] ];
	}
	/**
	 * What a payment row says and shows.
	 *
	 * @param array  $row        [ note, marks ].
	 * @param string $gateway_id The gateway.
	 */
	return apply_filters( 'omc_checkout_pay_row', $row, $id );
}

/** The marks of a row: all one height on one kind of tile, however different the artwork. */
function omc_checkout_pay_marks( array $marks ) {
	if ( ! $marks ) {
		return '';
	}
	$out = '<span class="omc-pay__marks" aria-hidden="true">';
	foreach ( $marks as $mark ) {
		$drawn = function_exists( 'omc_brand_mark' ) ? omc_brand_mark( $mark, 20 ) : '';
		$out  .= '<span class="omc-pay__mark' . ( $drawn ? ' is-' . esc_attr( sanitize_key( $mark ) ) : ' is-word' ) . '">' . ( $drawn ?: esc_html( $mark ) ) . '</span>';
	}
	return $out . '</span>';
}

/* ─────────────────────────── the wallets become a row ─────────────────────────── */

/*
 * Stripe and WooPayments print their wallet buttons above the shopper's details. They are called
 * again from the top of the payment section instead, by the same callbacks, so the plugins never know.
 * That spot is outside what WooCommerce redraws when the totals change, which the buttons would not survive.
 */
add_action( 'template_redirect', static function () {
	global $wp_filter;
	$from = 'woocommerce_checkout_before_customer_details';
	if ( ! omc_checkout_pay_on() || empty( $wp_filter[ $from ] ) ) {
		return;
	}
	$moved = false;
	foreach ( $wp_filter[ $from ]->callbacks as $priority => $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$function = $callback['function'];
			if ( is_array( $function ) && in_array( $function[1], [ 'display_express_checkout_button_html', 'display_express_checkout_buttons' ], true ) ) {
				remove_action( $from, $function, $priority );
				add_action( 'woocommerce_review_order_before_payment', $function, 5 );
				$moved = true;
			}
		}
	}
	if ( $moved ) {
		add_action( 'woocommerce_review_order_before_payment', 'omc_checkout_pay_wallets_open', 4 );
		add_action( 'woocommerce_review_order_before_payment', 'omc_checkout_pay_wallets_close', 6 );
	}
}, 99 );

function omc_checkout_pay_wallets_open() {
	?>
	<div class="omc-pay-wallets" id="omc-pay-wallets">
		<button type="button" class="omc-pay__row omc-pay-wallets__row" aria-expanded="false" aria-controls="omc-pay-wallets-box">
			<span class="omc-pay__text">
				<span class="omc-pay__name"><?php esc_html_e( 'Apple Pay or Google Pay', 'moderno-child' ); ?></span>
			</span>
			<?php echo omc_checkout_pay_marks( [ 'applepay', 'googlepay' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from the shop's own files ?>
		</button>
		<div class="omc-pay-wallets__box" id="omc-pay-wallets-box">
	<?php
}

function omc_checkout_pay_wallets_close() {
	echo '</div></div>';
}

/* The heading the row and the list share, above both: the theme's own is inside what gets redrawn. */
add_action( 'woocommerce_review_order_before_payment', static function () {
	if ( omc_checkout_pay_on() ) {
		echo '<div class="c-cart__sub-header omc-pay-head">' . esc_html__( 'How would you like to pay?', 'moderno-child' ) . '</div>';
	}
}, 3 );

/* Where the button will be, while there is nothing to press yet. */
add_action( 'woocommerce_review_order_before_submit', static function () {
	if ( omc_checkout_pay_on() ) {
		echo '<p class="omc-pay-prompt is-none">' . esc_html__( 'Choose how you’d like to pay, and the next step opens here.', 'moderno-child' ) . '</p>';
		echo '<p class="omc-pay-prompt is-wallet">' . esc_html__( 'Press the button above to pay with your device.', 'moderno-child' ) . '</p>';
	}
} );

add_action( 'wp_enqueue_scripts', static function () {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return;
	}
	$ver = static fn( $rel ) => file_exists( OMC_DIR . $rel ) ? (string) filemtime( OMC_DIR . $rel ) : OMC_VERSION;
	wp_enqueue_style( 'omc-checkout-pay', OMC_URI . '/assets/css/omc-checkout-pay.css', [ 'omc-pages' ], $ver( '/assets/css/omc-checkout-pay.css' ) );
	if ( omc_checkout_pay_on() ) {
		wp_enqueue_script( 'omc-checkout-pay', OMC_URI . '/assets/js/omc-checkout-pay.js', [ 'jquery', 'wc-checkout' ], $ver( '/assets/js/omc-checkout-pay.js' ), true );
	}
}, 1001 );
