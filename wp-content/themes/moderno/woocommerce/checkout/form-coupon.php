<?php
/**
 * Checkout coupon form
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/checkout/form-coupon.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce/Templates
 * @version   11.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! wc_coupons_enabled() ) { // @codingStandardsIgnoreLine.
	return;
}

?>

<div class="c-cart__coupon">

	<?php if ( ! ideapark_mod( 'expand_coupon' ) ) { ?>
	<a href="#" class="js-cart-coupon">
		<?php } ?>
		<div class="c-cart__coupon-header">
			<?php esc_html_e( 'Coupon code', 'woocommerce' ); ?>
			<?php if ( ! ideapark_mod( 'expand_coupon' ) ) { ?>
			<span class="c-cart__select-icon"></span>
			<?php } ?>
		</div>
		<?php if ( ! ideapark_mod( 'expand_coupon' ) ) { ?>
	</a>
<?php } ?>

	<?php if ( ! ideapark_mod( 'expand_coupon' ) ) { ?>
	<div class="c-cart__coupon-from-wrap">
		<?php } ?>
		<div class="c-cart__coupon-form">
			<input type="text" name="coupon_code" class="input-text" id="coupon_code" value="" placeholder="<?php esc_attr_e( 'Coupon code', 'woocommerce' ); ?>" />
			<button class="c-button c-button--medium c-button--outline c-cart__coupon-apply" id="ip-checkout-apply-coupon" name="apply_coupon" type="button"><?php esc_html_e( 'Apply', 'moderno' ); ?></button>
		</div>
		<?php if ( ! ideapark_mod( 'expand_coupon' ) ) { ?>
	</div>
<?php } ?>

	<?php do_action( 'woocommerce_cart_coupon' ); ?>
</div>