<?php
/**
 * Related Products
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/single-product/related.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see          https://docs.woocommerce.com/document/template-structure/
 * @package      WooCommerce/Templates
 * @version       11.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_boxed = ideapark_mod( 'product_grid_width' ) == 'boxed' || is_product() && ideapark_mod( 'product_page_layout' ) == 'layout-4' && ideapark_mod( 'product_tabs_layout' ) == 'tabs-compact';

if ( $related_products ) : ?>

	<section
		class="c-product__products c-product__products--related c-product__products--<?php echo esc_attr( $is_boxed ? 'boxed' : 'fullwidth' ); ?> l-section">

		<?php
		$heading = apply_filters( 'woocommerce_product_related_products_heading', __( 'Related products', 'woocommerce' ) );
		if ( $heading ) { ?>
			<div class="c-product__products-title"><?php echo esc_html( $heading ); ?></div>
		<?php } ?>

		<?php woocommerce_product_loop_start(); ?>

		<?php foreach ( $related_products as $related_product ) : ?>

			<?php
			$post_object = get_post( $related_product->get_id() );

			setup_postdata( $GLOBALS['post'] =& $post_object );

			wc_get_template_part( 'content', 'product' ); ?>

		<?php endforeach; ?>

		<?php woocommerce_product_loop_end(); ?>

	</section>

<?php endif;

wp_reset_postdata();
