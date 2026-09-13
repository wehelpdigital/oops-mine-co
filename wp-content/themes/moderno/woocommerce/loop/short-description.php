<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
global $post;
global $product;
if ( ! ( $product instanceof WC_Product ) ) {
	return;
}
$link = ideapark_mod( 'short_description_link' ) ? apply_filters( 'woocommerce_loop_product_link', get_the_permalink(), $product ) : '';

if ( ideapark_mod( 'product_short_description' ) && ( $short_description = apply_filters( 'woocommerce_short_description', $post->post_excerpt ) ) ) { ?>
	<div class="c-product-grid__short-desc">
		<?php echo ideapark_wrap( $short_description, $link ? '<a href="' . esc_url( $link ) . '" role="presentation">' : '', $link ? '</a>' : '' ); ?>
	</div>
<?php } ?>