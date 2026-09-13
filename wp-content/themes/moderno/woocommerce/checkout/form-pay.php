<?php
/**
 * Pay for order form
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/checkout/form-pay.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version   11.0.0
 */

defined( 'ABSPATH' ) || exit;

$totals = $order->get_order_item_totals(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
?>
<form id="order_review" method="post" class="c-cart c-cart--checkout">

	<div class="c-cart__wrap">
		<div class="c-cart__col-1 c-cart__col-1--checkout">
			<div
				class="<?php ideapark_class( ideapark_mod( 'sticky_sidebar' ), 'js-sticky-sidebar-nearby' ); ?>">
				<table class="shop_table c-cart__shop-table">
					<thead class="c-cart__shop-thead">
					<tr>
						<th class="c-cart__shop-th c-cart__shop-th--product-name"><?php esc_html_e( 'Product', 'woocommerce' ); ?></th>
						<th class="c-cart__shop-th c-cart__shop-th--product-quantity"><?php esc_html_e( 'Qty', 'woocommerce' ); ?></th>
						<th class="c-cart__shop-th c-cart__shop-th--product-total"><?php esc_html_e( 'Totals', 'woocommerce' ); ?></th>
					</tr>
					</thead>
					<tbody class="c-cart__shop-tbody">
					<?php if ( count( $order->get_items() ) > 0 ) : ?>
						<?php foreach ( $order->get_items() as $item_id => $item ) : ?>
							<?php
							if ( ! apply_filters( 'woocommerce_order_item_visible', true, $item ) ) {
								continue;
							}
							?>
							<tr class="c-cart__shop-tr <?php echo esc_attr( apply_filters( 'woocommerce_order_item_class', 'order_item', $item, $order ) ); ?>">
								<td class="c-cart__shop-td c-cart__shop-td--product-name">
									<?php
									echo wp_kses_post( apply_filters( 'woocommerce_order_item_name', $item->get_name(), $item, false ) );

									do_action( 'woocommerce_order_item_meta_start', $item_id, $item, $order, false );

									wc_display_item_meta( $item );

									do_action( 'woocommerce_order_item_meta_end', $item_id, $item, $order, false );
									?>
								</td>
								<td class="c-cart__shop-td c-cart__shop-td--product-quantity"><?php echo apply_filters( 'woocommerce_order_item_quantity_html', ' <strong class="product-quantity">' . sprintf( '&times;&nbsp;%s', esc_html( $item->get_quantity() ) ) . '</strong>', $item ); ?></td><?php // @codingStandardsIgnoreLine ?>
								<td class="c-cart__shop-td c-cart__shop-td--product-subtotal"><?php echo ideapark_wrap( $order->get_formatted_line_subtotal( $item ) ); ?></td><?php // @codingStandardsIgnoreLine ?>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
					<tfoot class="c-cart__shop-tfoot">
					<tr><td colspan="3" ">&nbsp;</td></tr>
					<?php if ( $totals ) : ?>
						<?php foreach ( $totals as $total ) : ?>
							<tr class="c-cart__shop-tr c-cart__shop-tr--border">
								<td class="c-cart__sub-sub-header" scope="row"
									colspan="2"><?php echo ideapark_wrap( $total['label'] ); ?></td><?php // @codingStandardsIgnoreLine ?>
								<td class="c-cart__shop-td c-cart__shop-td--product-subtotal"><?php echo ideapark_wrap( $total['value'] ); ?></td><?php // @codingStandardsIgnoreLine ?>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tfoot>
				</table>
			</div>
		</div>

		<div class="c-cart__col-2">
			<div
				class="c-cart__collaterals collaterals checkout-collaterals<?php ideapark_class( ideapark_mod( 'sticky_sidebar' ), 'js-sticky-sidebar' ); ?>">
				<div id="payment">
					<?php if ( $order->needs_payment() ) : ?>
						<ul class="c-cart__payment-methods wc_payment_methods payment_methods methods">
							<?php
							if ( ! empty( $available_gateways ) ) {
								foreach ( $available_gateways as $gateway ) {
									wc_get_template( 'checkout/payment-method.php', [ 'gateway' => $gateway ] );
								}
							} else {
								echo '<li class="woocommerce-notice woocommerce-notice--info woocommerce-info">' . apply_filters( 'woocommerce_no_available_payment_methods_message', esc_html__( 'Sorry, it seems that there are no available payment methods for your location. Please contact us if you require assistance or wish to make alternate arrangements.', 'woocommerce' ) ) . '</li>'; // @codingStandardsIgnoreLine
							}
							?>
						</ul>
					<?php endif; ?>
					<div class="c-cart__place-order form-row">
						<input type="hidden" name="woocommerce_pay" value="1"/>

						<?php wc_get_template( 'checkout/terms.php' ); ?>

						<?php do_action( 'woocommerce_pay_order_before_submit' ); ?>

						<?php echo apply_filters( 'woocommerce_pay_order_button_html', '<button type="submit" class="c-button c-button--full  c-cart__place-order-btn button alt" id="place_order" value="' . esc_attr( $order_button_text ) . '" data-value="' . esc_attr( $order_button_text ) . '">' . esc_html( $order_button_text ) . '</button>' ); // @codingStandardsIgnoreLine ?>

						<?php do_action( 'woocommerce_pay_order_after_submit' ); ?>

						<?php wp_nonce_field( 'woocommerce-pay', 'woocommerce-pay-nonce' ); ?>
					</div>
				</div>
			</div>
		</div>
	</div>
</form>
