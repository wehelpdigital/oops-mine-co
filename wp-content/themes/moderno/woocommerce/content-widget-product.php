<?php
/**
 * The template for displaying product widget entries.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/content-widget-product.php.
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see      https://docs.woocommerce.com/document/template-structure/
 * @package  WooCommerce/Templates
 * @version   11.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $product;

if ( ! is_a( $product, 'WC_Product' ) ) {
	return;
}

/**
 * @var $product WC_Product
 */
?>
<li class="c-product-list-widget__item">
	<?php do_action( 'woocommerce_widget_product_item_start', $args ); ?>

	<div class="c-product-list-widget__wrap">
		<div class="c-product-list-widget__thumb-col">
			<a href="<?php echo esc_url( $product->get_permalink() ); ?>">
				<?php echo ideapark_wrap( $product->get_image( ideapark_mod( 'thumb_aspect_ratio' ) == 'grid' ? 'medium' : 'woocommerce_gallery_thumbnail', [ 'class' => 'c-product-list-widget__thumb' . ( ideapark_mod( 'thumb_aspect_ratio' ) == 'grid' ? ' c-product-list-widget__thumb--grid' : ' c-product-list-widget__thumb--crop' ) ] ) ); ?>
			</a>
		</div>
		<div class="c-product-list-widget__title-col">

			<div class="c-product-list-widget__title">
				<a class="c-product-list-widget__title-link"
				   href="<?php echo esc_url( $product->get_permalink() ); ?>"><?php echo esc_html( strip_tags( $product->get_name() ) ); ?></a>
			</div>

			<?php if ( ! empty( $show_rating ) ) : ?>
				<?php echo ideapark_wrap( wc_get_rating_html( $product->get_average_rating() ), '<div class="c-product-list-widget__star-rating">', '</div>' ); ?>
			<?php endif; ?>
			<?php echo ideapark_wrap( $product->get_price_html(), '<div class="c-product-list-widget__price">', '</div>' ); ?>
		</div>
	</div>

	<?php do_action( 'woocommerce_widget_product_item_end', $args ); ?>
</li>