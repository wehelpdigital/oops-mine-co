<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
global $product;
/**
 * @var $product WC_Product
 **/
if ( ideapark_mod( 'shop_modal' ) || ideapark_mod( 'wishlist_page' ) && ideapark_mod( 'wishlist_grid_button' ) ) { ?>
	<div class="c-product-grid__thumb-button-list">
		<?php if ( ideapark_mod( 'wishlist_page' ) && ideapark_mod( 'wishlist_grid_button' ) ) { ?>
			<?php ideapark_wishlist()->ideapark__button( 'h-cb c-product-grid__thumb-button c-product-grid__thumb-button--wishlist', 'c-product-grid__icon c-product-grid__icon--wishlist', 'c-product-grid__icon-text', __( 'Add to Wishlist', 'moderno' ), __( 'Remove from Wishlist', 'moderno' ) ); ?>
		<?php } ?>
		<?php if ( ideapark_mod( 'shop_modal' ) ) { ?>
			<button class="h-cb c-product-grid__thumb-button c-product-grid__thumb-button--quickview js-grid-zoom"
					type="button" data-lang="<?php echo esc_attr( ideapark_current_language() ); ?>"
					data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
					aria-label="<?php esc_attr_e( 'Quick view', 'moderno' ); ?>">
				<i class="ip-eye c-product-grid__icon c-product-grid__icon--quickview"></i>
				<span class="c-product-grid__icon-text"><?php esc_html_e( 'Quick view', 'moderno' ); ?></span>
			</button>
		<?php } ?>
	</div>
<?php }
