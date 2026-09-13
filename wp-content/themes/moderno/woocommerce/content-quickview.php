<?php
/**
 *    The template for displaying quickview product content
 */

defined( 'ABSPATH' ) || exit;

global $product;

$blocks_place = [];

$blocks = ideapark_parse_checklist( ideapark_mod( 'quickview_page_blocks' ) );
foreach ( $blocks as $block_index => $enabled ) {
	if ( $enabled ) {
		if ( function_exists( $block_index ) ) {
			ob_start();
			call_user_func( $block_index );
			if ( $content = trim( ob_get_clean() ) ) {
				$blocks_place[] = $content;
			}
		}
	}
}
$summary_add_css = 'c-product__summary summary entry-summary';
$ip_classes      = [
	'c-product',
	'c-product--quick-view',
	'c-product__summary',
	'summary',
	'entry-summary'
];
if ( ! $product->is_purchasable() ) {
	$ip_classes[] = 'c-product--not-purchasable';
}

ideapark_mod_set_temp( '_is_quickview', true );
ideapark_mod_set_temp( 'shop_product_modal', false );
do_action( 'woocommerce_before_single_product' );
?>

<div id="product-<?php the_ID(); ?>" <?php wc_product_class( implode( ' ', $ip_classes ), $product ); ?>>
	<div class="c-product__wrap c-product__wrap--quickview">
		<div class="c-product__quick-view-col-1">
			<?php ideapark_product_gallery(); ?>
		</div>
		<div class="c-product__quick-view-col-2">
			<div class="c-product__quick-view-wrap">
				<?php echo implode( '', $blocks_place ); ?>
			</div>
		</div>
	</div>
</div>
