<?php
if ( ideapark_woocommerce_on() && ideapark_mod( 'popup_cart_layout' ) != 'disable' ) { ?>
	<div
		class="c-shop-sidebar <?php ideapark_class( ideapark_mod( 'popup_cart_layout' ) == 'sidebar', 'c-shop-sidebar--desktop-filter', 'c-shop-sidebar--mobile-only' ); ?> js-cart-sidebar">
		<div class="c-shop-sidebar__wrap js-cart-sidebar-wrap">
			<?php ideapark_close_button( 'js-cart-sidebar-close' ); ?>
			<div class="c-shop-sidebar__content c-shop-sidebar__content--popup">
				<div class="widget_shopping_cart_content"></div>
			</div>
		</div>
	</div>
	<?php if ( ideapark_mod( 'popup_cart_modal' ) ) { ?>
		<div class="c-shop-sidebar__shadow js-cart-sidebar-shadow"></div>
	<?php } ?>
<?php } ?>
