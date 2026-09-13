<?php
defined( 'ABSPATH' ) || exit;
ideapark_mod_set_temp( '_hide_grid_wrapper', true );
if ( is_product_taxonomy() ) {
	ideapark_mod_set_temp( '_archive_attribute_id', get_queried_object_id() );
}
ideapark_init_archive_layout();
ob_start();
if ( woocommerce_product_loop() ) {
	woocommerce_product_loop_start();
	if ( ! function_exists( 'wc_get_loop_prop' ) || wc_get_loop_prop( 'total' ) ) {
		while ( have_posts() ) {
			the_post();

			/**
			 * Hook: woocommerce_shop_loop.
			 *
			 * @hooked WC_Structured_Data::generate_product_data() - 10
			 */
			do_action( 'woocommerce_shop_loop' );

			wc_get_template_part( 'content', 'product' );
		}
	}
	woocommerce_product_loop_end();
}
$products = ob_get_clean();

ideapark_infinity_paging();
wp_send_json( [ 'products' => $products, 'paging' => ideapark_mod( '_infinity_paging' ) ] );
