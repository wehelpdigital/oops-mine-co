<?php
/**
 * The template for displaying product content in the single-product.php template
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/content-single-product.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see      https://docs.woocommerce.com/document/template-structure/
 * @package  WooCommerce\Templates
 * @version   11.0.0
 */

defined( 'ABSPATH' ) || exit;

global $product;

/**
 * Hook: woocommerce_before_single_product.
 *
 * @hooked woocommerce_output_all_notices - 10
 */
do_action( 'woocommerce_before_single_product' );

if ( post_password_required() ) {
	echo get_the_password_form(); // WPCS: XSS ok.

	return;
}

$layout = ideapark_mod( 'product_page_layout' );

$blocks_place = [];

$badges_shown = false;
$blocks       = ideapark_parse_checklist( ideapark_mod( 'product_page_blocks' ) );
foreach ( $blocks as $block_index => $enabled ) {
	if ( $layout == 'layout-3' && $block_index == 'ideapark_product_atc' ) {
		$blocks_place[] = '</div><div class="c-product__col-inner-2">';
	}
	if ( $enabled ) {
		if ( $layout == 'layout-3' && ! $badges_shown && $block_index != 'ideapark_product_breadcrumbs' ) {
			ob_start();
			ideapark_woocommerce_show_product_loop_badges();
			if ( $content = trim( ob_get_clean() ) ) {
				$blocks_place[] = ideapark_wrap( $content, '<div class="c-product__summary-badges">', '</div>' );
			}
			$badges_shown = true;
		}

		if ( $block_index == 'woocommerce_template_single_price' && ideapark_mod( 'hide_variable_price_range' ) && $product->is_type( 'variable' ) ) {
			continue;
		}

		if ( function_exists( $block_index ) ) {
			ob_start();
			call_user_func( $block_index );
			if ( $content = trim( ob_get_clean() ) ) {
				$blocks_place[] = $content;
			}
		}
	}
}

$ip_classes = [
	'c-product',
	'c-product--' . $layout,
	'l-section',
];

$ip_classes[] = 'c-product--' . ideapark_tabs_layout();

$ip_classes[] = 'c-product--additional-' . ideapark_mod( 'additional_tab_layout' );

if ( ideapark_mod( 'product_bottom_page' ) ) {
	$ip_classes[] = 'c-product--bottom-block';
}

if ( ! $product->is_purchasable() ) {
	$ip_classes[] = 'c-product--not-purchasable';
}

$summary_add_css = 'c-product__summary';

$compact_tabs = in_array( $layout, [
		'layout-1',
		'layout-2'
	] ) || $layout == 'layout-4' && ideapark_mod( 'product_tabs_layout' ) == 'tabs-compact';

$is_boxed = ideapark_mod( 'product_grid_width' ) == 'boxed' || is_product() && ideapark_mod( 'product_page_layout' ) == 'layout-4' && ideapark_mod( 'product_tabs_layout' ) == 'tabs-compact';

?>
<div id="product-<?php the_ID(); ?>" <?php wc_product_class( implode( ' ', $ip_classes ), $product ); ?>>
	<div
		class="c-product__section l-section">
		<div class="c-product__wrap c-product__wrap--<?php echo esc_attr( $layout ); ?>">
			<div class="c-product__col-1">
				<?php if ( $layout != 'layout-3' ) {
					echo '<div class="js-sticky-sidebar-nearby">';
				} ?>
				<?php ideapark_product_gallery(); ?>
				<?php if ( $layout != 'layout-3' ) {
					echo '</div><!-- .js-sticky-sidebar-nearby -->';
				} ?>
			</div><!-- .c-product__col-1 -->

			<div class="c-product__col-2">
				<?php if ( $layout != 'layout-3' ) {
					echo '<div data-no-offset="yes" class="js-sticky-sidebar tablet-sticky">';
				} ?>
				<div class="<?php echo esc_attr( $summary_add_css ); ?>">
					<?php if ( $layout == 'layout-3' ) {
						echo '<div class="c-product__col-inner-1">';
					} ?>
					<?php echo implode( '', $blocks_place ); ?>
					<?php if ( $layout == 'layout-3' ) {
						echo '</div>';
					} ?>
				</div><!-- .c-product__summary -->
				<?php if ( $compact_tabs ) {
					ideapark_tabs();
				} ?>
				<?php if ( $layout != 'layout-3' ) {
					echo '</div><!-- .js-sticky-sidebar -->';
				} ?>
			</div><!-- .c-product__col-2 -->
		</div>
		<?php if ( ! $compact_tabs ) {
			ideapark_tabs();
		} ?>
	</div>
	<div
		class="c-product__after-summary <?php ideapark_class( $is_boxed, 'c-product__after-summary--boxed', 'c-product__after-summary--fullwidth' ); ?>">
		<?php
		/**
		 * Hook: woocommerce_after_single_product_summary.
		 *
		 * @hooked woocommerce_upsell_display - 15
		 * @hooked woocommerce_output_related_products - 20
		 */
		do_action( 'woocommerce_after_single_product_summary' );
		?>
	</div>
</div>

<?php do_action( 'woocommerce_after_single_product' ); ?>
