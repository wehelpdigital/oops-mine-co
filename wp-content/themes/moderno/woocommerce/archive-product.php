<?php
/**
 * The Template for displaying product archives, including the main shop page which is a post type archive
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/archive-product.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see            https://docs.woocommerce.com/document/template-structure/
 * @package        WooCommerce/Templates
 * @version         11.0.0
 */

defined( 'ABSPATH' ) || exit;

get_header( 'shop' );

extract( ideapark_init_archive_layout() );

if ( ideapark_mod( 'product_grid_layout' ) == '5-per-row' && ideapark_mod( 'shop_sidebar' ) ) {
	ideapark_mod_set_temp( 'product_grid_layout', '4-per-row' );
}

$ideapark_category_html_top       = ideapark_mod('_category_html_top');
$ideapark_category_html_bottom    = ideapark_mod('_category_html_bottom');
$ideapark_category_html_top_above = ideapark_mod('_category_html_top_above');
?>

<?php if ( $ideapark_category_html_top_above ) { ?>
	<?php echo ideapark_wrap( $ideapark_category_html_top, '<div class="c-category-html c-category-html--top">', '</div>' ); ?>
<?php } ?>

<?php
/**
 * woocommerce_before_main_content hook.
 *
 * @hooked woocommerce_output_content_wrapper - 10 (outputs opening divs for the content)
 */
do_action( 'woocommerce_before_main_content' );
?>

<div
	class="l-section<?php if ( ideapark_mod( 'product_grid_width' ) == 'boxed' ) { ?> l-section--container <?php } else { ?><?php } ?> l-section--bottom-margin<?php if ( $with_sidebar ) { ?> l-section--with-sidebar<?php } ?>">
	<?php if ( $with_sidebar || $with_filter_mobile || $with_filter_desktop ) { ?>
		<div
			class="l-section__sidebar l-section__sidebar--<?php echo ideapark_mod( 'product_grid_layout' ); ?> l-section__sidebar--<?php echo ideapark_mod( 'product_grid_width' ); ?> ">
			<?php
			/**
			 * woocommerce_sidebar hook.
			 *
			 * @hooked woocommerce_get_sidebar - 10
			 */
			do_action( 'woocommerce_sidebar' );
			?>
		</div>
	<?php } ?>


	<div
		class="l-section__content<?php if ( $with_sidebar ) { ?> l-section__content--with-sidebar<?php } ?>">
		<div
			class="<?php ideapark_class( $with_sidebar && ideapark_mod( 'sticky_sidebar' ), 'js-sticky-sidebar-nearby' ); ?>">

			<?php if ( ! $ideapark_category_html_top_above ) { ?>
				<?php echo ideapark_wrap( $ideapark_category_html_top, '<div class="c-category-html c-category-html--top">', '</div>' ); ?>
			<?php } ?>

			<?php
			/**
			 * woocommerce_archive_description hook.
			 *
			 * @hooked woocommerce_taxonomy_archive_description - 10
			 * @hooked woocommerce_product_archive_description - 10
			 */
			if ( ideapark_mod( 'category_description_position' ) == 'above' ) {
				do_action( 'woocommerce_archive_description' );
			}
			?>
			<?php

			if ( woocommerce_product_loop() ) {

				/**
				 * Hook: woocommerce_before_shop_loop.
				 *
				 * @hooked wc_print_notices - 10
				 * @hooked woocommerce_result_count - 20
				 * @hooked woocommerce_catalog_ordering - 30
				 */
				do_action( 'woocommerce_before_shop_loop' );

				?>
				<div class="c-product-grid"><?php
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
					?>
				</div>
				<?php
				/**
				 * Hook: woocommerce_after_shop_loop.
				 *
				 * @hooked woocommerce_pagination - 10
				 */
				do_action( 'woocommerce_after_shop_loop' );
			} else {
				/**
				 * Hook: woocommerce_no_products_found.
				 *
				 * @hooked wc_no_products_found - 10
				 */
				if ( ! $with_sidebar ) {
					if ( ideapark_mod( 'product_grid_width' ) == 'boxed' ) {
						echo '<div class="l-section__container">';
					} else {
						echo '<div class="l-section__container-wide">';
					}
				}
				do_action( 'woocommerce_no_products_found' );
				if ( ! $with_sidebar ) {
					echo '</div>';
				}
			}
			?>

			<?php
			/**
			 * woocommerce_archive_description hook.
			 *
			 * @hooked woocommerce_taxonomy_archive_description - 10
			 * @hooked woocommerce_product_archive_description - 10
			 */
			if ( ideapark_mod( 'category_description_position' ) == 'below' ) {
				do_action( 'woocommerce_archive_description' );
			}
			?>
			<?php echo ideapark_wrap( $ideapark_category_html_bottom, '<div class="c-category-html c-category-html--bottom">', '</div>' ); ?>
		</div>
	</div>
</div>

<?php
/**
 * Hook: woocommerce_after_main_content.
 *
 * @hooked woocommerce_output_content_wrapper_end - 10 (outputs closing divs for the content)
 */
do_action( 'woocommerce_after_main_content' );
?>

<?php get_footer( 'shop' ); ?>
