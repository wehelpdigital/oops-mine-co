<?php


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
$tab_counter  = ideapark_mod( '_product_tabs_counter' ) + 1;
ideapark_mod_set_temp( '_product_tabs_counter', $tab_counter );
$titles = [];

if ( ! empty( $product_tabs ) ) {
	?>
	<div
		class="c-product__tabs-fullwidth<?php if ( ideapark_mod( 'expand_tab_mobile' ) ) { ?> c-product__tabs-fullwidth--expand-mobile<?php } ?> c-ip-product-tabs js-ip-tabs">
		<?php

		$tab_content = [];
		foreach ( $product_tabs as $key => $product_tab ) {
			if ( isset( $product_tab['callback'] ) ) {
				ob_start();
				call_user_func( $product_tab['callback'], $key, $product_tab );
				if ( $_tab_content = trim( ob_get_clean() ) ) {
					$tab_content[ $key ] = $_tab_content;
				}
			}
		}

		?>
		<?php if ( sizeof( $product_tabs ) > 1 ) { ?>
			<div class="c-ip-product-tabs__wrap js-ip-tabs-wrap">
				<div
					class="c-ip-product-tabs__menu js-ip-tabs-list h-carousel h-carousel--small h-carousel--hover h-carousel--mobile-arrows h-carousel--dots-hide">
					<?php
					$index = 0;
					foreach ( $product_tabs as $key => $product_tab ) {
						if ( ! array_key_exists( $key, $tab_content ) ) {
							continue;
						}
						$titles[ $key ] = ideapark_wp_kses( apply_filters( 'woocommerce_product_' . $key . '_tab_title', $product_tab['title'], $key ), [ 'sup' => [ 'class' => true ] ] );
						?>
						<div
							class="c-ip-product-tabs__menu-item js-ip-tabs-menu-item <?php echo esc_attr( $key ); ?>_tab <?php if ( ! $index ) { ?>active<?php } ?>">
							<a class="c-ip-product-tabs__menu-link js-ip-tabs-link"
							   href="#tab-<?php echo esc_attr( $key . '-' . $tab_counter ); ?>"
							   data-index="<?php echo esc_attr( $index ); ?>"
							   onclick="return false;"><?php echo ideapark_wrap( $titles[ $key ] ); ?></a>
						</div>
						<?php
						$index ++;
					} ?>
				</div>
			</div>
		<?php } ?>
		<div class="c-ip-product-tabs__list">
			<?php
			$index = 0;
			foreach ( $product_tabs as $key => $product_tab ) {
				if ( ! array_key_exists( $key, $tab_content ) ) {
					continue;
				}
				$is_elementor_page = false;
				$is_html_block     = false;
				if ( $key == 'description' ) {
					$is_elementor_page = ideapark_is_elementor_page();
					$is_html_block     = true;
				} elseif ( $key == 'html_block' ) {
					global $product;
					if ( isset( $product ) && $product instanceof WC_Product ) {
						$product_id        = $product->get_id();
						$_html_block_id    = get_post_meta( $product_id, 'ideapark_html_block_tab_id', true ) | 0;
						$is_elementor_page = $_html_block_id ? ideapark_is_elementor_page( $_html_block_id ) : false;
					}
					$is_html_block = true;
				}
				?>
				<div class="c-ip-product-tabs__item <?php if ( ! $index ) { ?>visible active<?php } ?>"
					 id="tab-<?php echo esc_attr( $key . '-' . $tab_counter ); ?>">
					<div
						class="c-product__tabs-fullwidth-content <?php if ( $is_elementor_page ) { ?> c-product__tabs-fullwidth-content--elementor<?php } elseif ( $is_html_block ) { ?> entry-content<?php } ?> c-product__tabs-fullwidth-content--<?php echo esc_attr( $key ); ?>">
						<?php if ( ideapark_mod( 'expand_tab_mobile' ) ) { ?>
							<div class="c-ip-product-tabs__header"><?php echo ideapark_wrap( $titles[ $key ] ); ?></div>
						<?php } ?>
						<?php echo ideapark_wrap( $tab_content[ $key ] ); ?>
					</div>
				</div>
				<?php
				$index ++;
			} ?>
		</div>
	</div>
	<?php
}

