<?php
/**
 * Cart Page
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/cart/cart.php.
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

do_action( 'woocommerce_before_cart' ); ?>

	<div class="c-cart">
		<div class="c-cart__wrap">
			<div class="c-cart__col-1">
				<div
					class="<?php ideapark_class( ideapark_mod( 'sticky_sidebar' ), 'js-sticky-sidebar-nearby' ); ?>">

					<form class="woocommerce-cart-form" action="<?php echo esc_url( wc_get_cart_url() ); ?>"
						  method="post">
						<?php do_action( 'woocommerce_before_cart_table' ); ?>
						<table
							class="shop_table shop_table_responsive cart woocommerce-cart-form__contents c-cart__shop-table"
							cellspacing="0">
							<thead class="c-cart__shop-thead">
							<tr>
								<th class="c-cart__shop-th c-cart__shop-th--product-name"
									colspan="2"><?php esc_html_e( 'Product', 'woocommerce' ); ?></th>
								<th class="c-cart__shop-th c-cart__shop-th--product-quantity"><?php esc_html_e( 'Quantity', 'woocommerce' ); ?></th>
								<th class="c-cart__shop-th c-cart__shop-th--product-subtotal"><?php esc_html_e( 'Subtotal', 'woocommerce' ); ?></th>
							</tr>
							</thead>
							<tbody class="c-cart__shop-tbody">
							<tr class="c-cart__shop-tr c-cart__shop-tr--space">
								<td colspan="4" class="c-cart__shop-td-space"></td>
							</tr>
							<?php do_action( 'woocommerce_before_cart_contents' ); ?>

							<?php
							foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
								$_product   = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
								$product_id = apply_filters( 'woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key );
								/**
								 * Filter the product name.
								 *
								 * @since 7.8.0
								 * @param string $product_name Name of the product in the cart.
								 */
								$product_name = apply_filters( 'woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key );

								if ( $_product && $_product->exists() && $cart_item['quantity'] > 0 && apply_filters( 'woocommerce_cart_item_visible', true, $cart_item, $cart_item_key ) ) {
									$product_permalink = apply_filters( 'woocommerce_cart_item_permalink', $_product->is_visible() ? $_product->get_permalink( $cart_item ) : '', $cart_item, $cart_item_key );
									?>
									<tr class="c-cart__shop-tr <?php echo esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key ) ); ?>">

										<td class="c-cart__shop-td c-cart__shop-td--product-thumbnail">

											<?php
											echo apply_filters( 'woocommerce_cart_item_remove_link', sprintf(
												'<a href="%s" class="c-cart__shop-remove remove" aria-label="%s"  data-product_id="%s" data-product_sku="%s"><i class="ip-close-small c-cart__shop-remove-icon"></i></a>',
												esc_url( wc_get_cart_remove_url( $cart_item_key ) ),
												esc_attr( sprintf( __( 'Remove %s from cart', 'woocommerce' ), $product_name ) ),
												esc_attr( $product_id ),
												esc_attr( $_product->get_sku() )
											), $cart_item_key );
											?>

											<?php
											$thumbnail = apply_filters( 'woocommerce_cart_item_thumbnail', $_product->get_image( ideapark_mod( 'thumb_aspect_ratio' ) == 'grid' ? 'medium' : 'woocommerce_gallery_thumbnail', [ 'class' => 'c-cart__thumbnail' . (ideapark_mod( 'thumb_aspect_ratio' ) == 'grid' ? ' c-cart__thumbnail--grid' : ' c-cart__thumbnail--crop') ] ), $cart_item, $cart_item_key );

											if ( ! $product_permalink ) {
												echo ideapark_wrap( $thumbnail );
											} else {
												printf( '<a class="c-cart__thumbnail-link" href="%s">%s</a>', esc_url( $product_permalink ), $thumbnail );
											}
											?>
										</td>

										<td class="c-cart__shop-td c-cart__shop-td--product-name"
											data-title="<?php esc_attr_e( 'Product', 'woocommerce' ); ?>">
											<?php
											if ( $_product->get_type() == 'variation' && ! method_exists( $_product, 'get_name' ) ) {
												$variation_attributes = $_product->get_attributes();
												$name                 = $_product->get_title() . ( $variation_attributes ? ideapark_wrap( implode( ', ', $variation_attributes ), '<span class="c-cart__shop-variation">', '</span>' ) : '' );
											} else {
												$name = method_exists( $_product, 'get_name' ) ? $_product->get_name() : $_product->get_title();
											}

											$name = ideapark_shy( $name );
											if ( ! $product_permalink ) {
												echo wp_kses_post( apply_filters( 'woocommerce_cart_item_name', $name, $cart_item, $cart_item_key ) . '&nbsp;' );
											} else {
												echo wp_kses_post( apply_filters( 'woocommerce_cart_item_name', sprintf( '<a href="%s">%s</a>', esc_url( $product_permalink ), $name ), $cart_item, $cart_item_key ) );
											}

											do_action( 'woocommerce_after_cart_item_name', $cart_item, $cart_item_key );

											// Meta data
											if ( function_exists( 'wc_get_formatted_cart_item_data' ) ) {
												echo wc_get_formatted_cart_item_data( $cart_item );
											} else {
												echo WC()->cart->get_item_data( $cart_item );
											}

											// Backorder notification.
											if ( $_product->backorders_require_notification() && $_product->is_on_backorder( $cart_item['quantity'] ) ) {
												echo wp_kses_post( apply_filters( 'woocommerce_cart_item_backorder_notification', '<p class="backorder_notification">' . esc_html__( 'Available on backorder', 'woocommerce' ) . '</p>', $product_id ) );
											}
											?>
											<span class="c-cart__item-price">
										<?php echo apply_filters( 'woocommerce_cart_item_price', esc_html( $cart_item['quantity'] ) . ' x ' . WC()->cart->get_product_price( $_product ), $cart_item, $cart_item_key ); // PHPCS: XSS ok. ?>
										</span>
											<?php if ( ! ( $_product->is_in_stock() || $_product->is_on_backorder() ) ) { ?>
												<span
													class="c-cart__item-out-of-stock"><?php echo esc_html( ideapark_mod( 'outofstock_badge_text' ) ); ?></span>
											<?php } ?>
										</td>

										<td class="c-cart__shop-td c-cart__shop-td--product-quantity"
											data-title="<?php esc_attr_e( 'Quantity', 'woocommerce' ); ?>">
											<?php
											if ( $_product->is_sold_individually() ) {
												$product_quantity = sprintf( '1 <input type="hidden" name="cart[%s][qty]" value="1" />', $cart_item_key );
											} else {
												$product_quantity = woocommerce_quantity_input( [
													'input_name'   => "cart[{$cart_item_key}][qty]",
													'input_value'  => $cart_item['quantity'],
													'max_value'    => method_exists( $_product, 'get_max_purchase_quantity' ) ? $_product->get_max_purchase_quantity() : ( $_product->backorders_allowed() ? '' : $_product->get_stock_quantity() ),
													'min_value'    => '0',
													'product_name' => method_exists( $_product, 'get_name' ) ? $_product->get_name() : $_product->get_title(),
												], $_product, false );
											}
											echo apply_filters( 'woocommerce_cart_item_quantity', $product_quantity, $cart_item_key, $cart_item );
											?>
										</td>

										<td class="c-cart__shop-td c-cart__shop-td--product-price c-cart__shop-td--product-subtotal"
											data-title="<?php esc_attr_e( 'Subtotal', 'woocommerce' ); ?>">
											<?php
											echo apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $_product, $cart_item['quantity'] ), $cart_item, $cart_item_key );
											?>
										</td>
									</tr>
									<?php
								}
							}
							?>

							<?php do_action( 'woocommerce_cart_contents' ); ?>
							<tr class="c-cart__shop-tr c-cart__shop-tr--space">
								<td colspan="4" class="c-cart__shop-td-space"></td>
							</tr>
							<tr class="c-cart__shop-tr c-cart__shop-tr--actions">
								<td colspan="5" class="c-cart__shop-td c-cart__shop-td--actions">
						<span class="c-cart__shop-update">
							<input type="submit" class="c-button c-button--medium c-button--outline c-cart__shop-update-button<?php if (ideapark_mod( 'cart_auto_update' )) { ?> c-cart__shop-update-button--auto<?php } ?> button"
								   name="update_cart"
								   value="<?php esc_attr_e( 'Update cart', 'woocommerce' ); ?>"/>
						</span>
									<?php wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce' ); ?>
									<?php do_action( 'woocommerce_cart_actions' ); ?>
								</td>
							</tr>
							<?php do_action( 'woocommerce_after_cart_contents' ); ?>
							</tbody>
						</table>
						<?php do_action( 'woocommerce_after_cart_table' ); ?>
					</form>
				</div>
			</div>
			<div class="c-cart__col-2">

				<?php do_action( 'woocommerce_before_cart_collaterals' ); ?>

				<div
					class="cart-collaterals c-cart__collaterals<?php ideapark_class( ideapark_mod( 'sticky_sidebar' ), 'js-sticky-sidebar' ); ?>">
					<?php
					/**
					 * Cart collaterals hook.
					 *
					 * @hooked woocommerce_cross_sell_display
					 * @hooked woocommerce_cart_totals - 10
					 */
					do_action( 'woocommerce_cart_collaterals' );
					?>
				</div>
			</div>
		</div>

	</div>

<?php do_action( 'woocommerce_after_cart' ); ?>