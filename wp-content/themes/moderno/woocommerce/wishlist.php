<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

global $product;

if ( $ip_wishlist_ids = ideapark_wishlist()->ids() ) {
	$args = [
		'post_type'      => 'product',
		'order'          => 'DESC',
		'orderby'        => 'post__in',
		'posts_per_page' => - 1,
		'post__in'       => $ip_wishlist_ids
	];

	$wishlist_loop = new WP_Query( $args );
} else {
	$wishlist_loop = false;
}

$product_ids = [];

if ( $wishlist_loop && $wishlist_loop->have_posts() ) { ?>

	<div class="c-wishlist js-wishlist">
		<table class="c-wishlist__shop-table c-wishlist__table js-wishlist-table">
			<thead class="c-wishlist__shop-thead">
			<tr>
				<th class="c-wishlist__shop-th c-wishlist__shop-th--product-name" colspan="2">
					<span><?php esc_html_e( 'Product', 'moderno' ); ?></span>
				</th>
				<th class="c-wishlist__shop-th c-wishlist__shop-th--product-price">
					<span><?php esc_html_e( 'Price', 'moderno' ); ?></span>
				</th>
				<th class="c-wishlist__shop-th c-wishlist__shop-th--product-stock">
					<span><?php esc_html_e( 'Stock Status', 'moderno' ); ?></span>
				</th>
				<th class="c-wishlist__shop-th c-wishlist__shop-th--product-actions">
				</th>
			</tr>
			</thead>
			<tbody class="c-wishlist__shop-tbody">
			<tr class="c-wishlist__shop-tr c-wishlist__shop-tr--space">
				<td colspan="5" class="c-wishlist__shop-td-space"></td>
			</tr>
			<?php
			while ( $wishlist_loop->have_posts() ) : $wishlist_loop->the_post();
				global $product;
				$product_ids[] = $product->get_id();
				?>
				<tr class="c-wishlist__shop-tr" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
					<td class="c-wishlist__shop-td c-wishlist__shop-td--product-thumbnail">
						<?php if ( ! ideapark_wishlist()->view_mode() ) { ?>
							<a href="#" onclick="return false;"
							   class="c-wishlist__shop-remove js-wishlist-remove c-wishlist__remove"
							   title="<?php esc_attr_e( 'Remove', 'moderno' ); ?>" type="button">
								<i class="ip-close-small c-wishlist__shop-remove-icon"></i>
							</a>
						<?php } ?>
						<?php
						$thumbnail         = $product->get_image( ideapark_mod( 'thumb_aspect_ratio' ) == 'grid' ? 'medium' : 'woocommerce_gallery_thumbnail', [ 'class' => 'c-wishlist__thumb' . ( ideapark_mod( 'thumb_aspect_ratio' ) == 'grid' ? ' c-wishlist__thumb--grid' : ' c-wishlist__thumb--crop' ) ] );
						$product_permalink = get_the_permalink();

						if ( ! $product_permalink ) {
							echo ideapark_wrap( $thumbnail );
						} else {
							printf( '<a class="c-wishlist__thumbnail-link" href="%s">%s</a>', esc_url( $product_permalink ), $thumbnail );
						}
						?>
					</td>
					<td class="c-wishlist__shop-td c-wishlist__shop-td--product-name c-wishlist__shop-td--product-normal">
						<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
					</td>
					<td class="c-wishlist__shop-td c-wishlist__shop-td--product-price">
						<?php woocommerce_template_loop_price(); ?>
					</td>
					<td class="c-wishlist__shop-td c-wishlist__shop-td--product-stock">
						<?php ideapark_product_availability(); ?>
					</td>
					<td class="c-wishlist__shop-td c-wishlist__shop-td--product-actions">
						<div class="c-wishlist__button-wrap">
							<?php woocommerce_template_loop_add_to_cart(); ?>
							<div class="c-quantity js-product-grid-quantity"></div>
							<span class="added_to_cart h-hidden"></span>
						</div>
					</td>
				</tr>
			<?php endwhile; ?>
			<tr class="c-wishlist__shop-tr c-wishlist__shop-tr--space">
				<td colspan="5" class="c-wishlist__shop-td-space"></td>
			</tr>
			</tbody>
		</table>
		<?php wp_reset_postdata(); ?>
		<?php echo do_shortcode( '[ip-wishlist-share]' ); ?>
	</div>

<?php } ?>

	<div
		class="c-cart-empty c-cart-empty--wishlist js-wishlist-empty <?php if ( $wishlist_loop && $wishlist_loop->have_posts() ) { ?> h-hidden<?php } ?>">
		<div class="c-cart-empty__image-wrap">
			<?php if ( ( $image_id = ideapark_mod( 'wishlist_empty_image__attachment_id' ) ) && ( $image_meta = ideapark_image_meta( $image_id ) ) ) { ?>
				<?php echo ideapark_img( $image_meta, 'c-cart-empty__image' ); ?>
			<?php } else { ?>
				<i class="ip-wishlist-empty c-cart-empty__icon c-cart-empty__icon--failed"></i>
			<?php } ?>
		</div>
		<h2 class="c-cart-empty__header"><?php esc_html_e( 'Your wishlist is currently empty', 'moderno' ); ?></h2>
		<p class="c-cart-empty__note"><?php printf( esc_html__( 'Click the %s icons to add products', 'moderno' ), '<i class="ip-wishlist c-cart-empty__wishlist"></i>' ); ?></p>
		<?php if ( wc_get_page_id( 'shop' ) > 0 ) { ?>
			<a class="c-button c-button--outline c-cart-empty__backward"
			   href="<?php echo esc_url( apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ); ?>">
				<?php esc_html_e( 'Return to shop', 'woocommerce' ) ?>
			</a>
		<?php } ?>
	</div>
<?php wp_reset_postdata();

if ( $ip_wishlist_ids ) {
	foreach ( ideapark_wishlist()->fix( $product_ids ) as $product_id ) { ?>
		<input type="hidden" class="js-wishlist-fix" value="<?php echo esc_attr( $product_id ); ?>"/>
	<?php }
}