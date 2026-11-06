<?php
/**
 * Output a single payment method
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/checkout/payment-method.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * Moderno's template, with the option as a row of the same shape as every other: the plugin's title, a line
 * saying what it is, and the marks in the shop's own style rather than whatever each plugin brings
 * (inc/checkout-pay.php). The radio, its ids and the box under it are as WooCommerce and the plugins expect.
 *
 * @see 	    https://docs.woocommerce.com/document/template-structure/
 * @package 	WooCommerce/Templates
 * @version       11.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php list( $omc_note, $omc_marks ) = omc_checkout_pay_row( $gateway->id ); ?>
<li class="wc_payment_method payment_method_<?php echo esc_attr( $gateway->id ); ?>">
	<input id="payment_method_<?php echo esc_attr( $gateway->id ); ?>" type="radio" class="c-cart__payment-method-radio input-radio" name="payment_method" value="<?php echo esc_attr( $gateway->id ); ?>" <?php checked( $gateway->chosen, true ); ?> data-order_button_text="<?php echo esc_attr( $gateway->order_button_text ); ?>" />

	<label class="c-cart__payment-methods-title omc-pay__row" for="payment_method_<?php echo esc_attr( $gateway->id ); ?>">
		<span class="omc-pay__dot" aria-hidden="true"></span>
		<span class="omc-pay__text">
			<span class="omc-pay__name"><?php echo wp_kses_post( $gateway->get_title() ); ?></span>
			<?php if ( $omc_note ) : ?>
				<span class="omc-pay__note"><?php echo esc_html( $omc_note ); ?></span>
			<?php endif; ?>
		</span>
		<?php echo omc_checkout_pay_marks( $omc_marks ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from the shop's own files ?>
	</label>
	<?php if ( $gateway->has_fields() || $gateway->get_description() ) : ?>
		<div class="c-cart__payment-methods-box payment_box payment_method_<?php echo esc_attr( $gateway->id ); ?>" <?php if ( ! $gateway->chosen ) : /* phpcs:ignore Squiz.ControlStructures.ControlSignature.NewlineAfterOpenBrace */ ?><?php ideapark_style( 'display:none;' ) ?><?php endif; /* phpcs:ignore Squiz.ControlStructures.ControlSignature.NewlineAfterOpenBrace */ ?>>
			<?php $gateway->payment_fields(); ?>
		</div>
	<?php endif; ?>
</li>
