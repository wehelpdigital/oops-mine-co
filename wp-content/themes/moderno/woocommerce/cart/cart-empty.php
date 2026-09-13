<?php
/**
 * Empty cart page
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/cart/cart-empty.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see        https://docs.woocommerce.com/document/template-structure/
 * @package    WooCommerce/Templates
 * @version   11.0.0
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="c-cart-empty c-cart-empty--cart">
	<div class="c-cart-empty__image-wrap">
		<?php if ( ( $image_id = ideapark_mod( 'cart_empty_image__attachment_id' ) ) && ( $image_meta = ideapark_image_meta( $image_id ) ) ) { ?>
			<?php echo ideapark_img( $image_meta, 'c-cart-empty__image' ); ?>
		<?php } else { ?>
			<i class="ip-cart-empty c-cart-empty__icon c-cart-empty__icon--failed"></i>
		<?php } ?>
	</div>
	<h2 class="c-cart-empty__header"><?php esc_html_e( 'Your cart is currently empty', 'moderno' ); ?></h2>
	<?php if ( wc_get_page_id( 'shop' ) > 0 ) { ?>
		<a class="c-button c-button--outline c-cart-empty__backward"
		   href="<?php echo esc_url( apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ); ?>">
			<?php esc_html_e( 'Return to shop', 'woocommerce' ) ?>
		</a>
	<?php } ?>
</div>