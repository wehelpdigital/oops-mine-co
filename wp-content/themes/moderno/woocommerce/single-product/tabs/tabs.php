<?php
/**
 * Single Product tabs
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/single-product/tabs/tabs.php.
 *
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

/**
 * Filter tabs and allow third parties to add their own.
 *
 * Each tab is an array containing title, callback and priority.
 *
 * @see woocommerce_default_product_tabs()
 */
$product_tabs = apply_filters( 'woocommerce_product_tabs', [] );

if ( ! empty( $product_tabs ) ) : ?>

	<div class="c-product__tabs">
		<div class="c-product__tabs-list js-tabs-list">
			<?php $is_first = true; ?>
			<?php $index = 0; ?>
			<?php $expand_first_tab = ideapark_mod( 'expand_first_tab' ); ?>
			<?php foreach ( $product_tabs as $key => $product_tab ) { ?>
				<?php
				if ( isset( $product_tab['callback'] ) ) {
					ob_start();
					call_user_func( $product_tab['callback'], $key, $product_tab );
					$tab_content = trim( ob_get_clean() );
					if ( ! $tab_content ) {
						continue;
					}
				} else {
					continue;
				}
				?>
				<div
					class="c-product__tabs-item <?php echo esc_attr( $key ); ?>_tab <?php if ( $expand_first_tab && ! $index ) { ?> active<?php } ?>">
					<a onclick="return false" class="c-product__tabs-item-link js-tabs-item-link"
					   href="#tab-<?php echo esc_attr( $key ); ?>">
						<div class="c-product__tabs-item-header">
							<?php echo wp_kses( apply_filters( 'woocommerce_product_' . $key . '_tab_title', $product_tab['title'], $key ), [ 'sup' => [ 'class' => true ] ] ); ?>
						</div>
						<i class="ip-plus c-product__tabs-item-expand"></i>
					</a>
					<div class="c-product__tabs-panel <?php if ( $key == 'description' ) { ?>entry-content<?php } ?>"
					     id="tab-<?php echo esc_attr( $key ); ?>" <?php if ( $expand_first_tab && ! $index ) { ?> style="display:block;"<?php } ?>>
						<?php echo ideapark_wrap( $tab_content ); ?>
					</div>
				</div>
				<?php $index ++; ?>
			<?php } ?>
		</div>
		<?php do_action( 'woocommerce_product_after_tabs' ); ?>
	</div>

<?php endif; ?>
