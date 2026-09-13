<?php
if ( ideapark_woocommerce_on() && ideapark_mod( 'popup_cart_layout' ) == 'sidebar' ) { ?>
	<div class="c-shop-sidebar js-cart-sidebar">
		<div class="c-shop-sidebar__wrap js-cart-sidebar-wrap">
			<?php ideapark_close_button('js-cart-sidebar-close'); ?>
			<div class="c-shop-sidebar__content c-shop-sidebar__content--mobile">
				<div class="widget_shopping_cart_content"></div>
			</div>
		</div>
	</div>
	<?php if (ideapark_mod( 'popup_cart_modal' )) { ?>
		<div class="c-shop-sidebar__shadow js-cart-sidebar-shadow"></div>
	<?php } ?>
<?php } ?>
