<!-- grid-end -->
<?php
/**
 * Product Loop End
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/loop/loop-end.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see         https://docs.woocommerce.com/document/template-structure/
 * @package     WooCommerce/Templates
 * @version       11.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
do_action( 'ideapark_products_loop_end' );
ideapark_mod_set_temp( '_is_product_loop', false );

ideapark_mod_set_temp( '_products_count', '' );
ideapark_mod_set_temp( '_product_carousel_class', '' );
ideapark_mod_set_temp( '_product_carousel_data', '' );

ideapark_mod_set_temp( '_product_layout', '' );
ideapark_mod_set_temp( '_product_layout_width', '' );
ideapark_mod_set_temp( '_product_layout_mobile', '' );
ideapark_mod_set_temp( '_product_layout_class', '' );

ideapark_mod_set_temp( 'product_short_description', ideapark_mod( '_product_short_description' ) );

?>
<?php if ( ! ideapark_mod( '_hide_grid_wrapper' ) ) { ?>
	</div><!-- .c-product-grid__list -->
	</div><!-- .c-product-grid__wrap -->
<?php } ?>