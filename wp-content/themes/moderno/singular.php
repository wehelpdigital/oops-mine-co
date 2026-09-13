<?php global $post;
$with_sidebar = ! is_page() && ideapark_mod( 'sidebar_post' ) && is_active_sidebar( 'post-sidebar' ) && ! ( ideapark_woocommerce_on() && ( is_cart() || is_checkout() || is_account_page() ) );
$class        = [ 'c-post__container', 'l-section', 'l-section--container' ];
$hide_title   = false;
if ( $with_sidebar ) {
	$class[] = 'l-section--with-sidebar';
} else {
	$class[] = 'l-section--no-sidebar';
}

if ( ideapark_is_wishlist_page() ) {
	if ( ideapark_wishlist()->ids() ) {
		$class[] = 'c-post__container--wishlist';
	} else {
		$class[] = 'c-post__container--wishlist';
	}
} elseif ( ideapark_woocommerce_on() && is_account_page() ) {
	$class[] = 'c-post__container--account';
} elseif ( ideapark_woocommerce_on() && is_checkout() ) {
	$class[] = 'c-post__container--checkout';
} elseif ( ideapark_woocommerce_on() && is_cart() ) {
	if ( is_cart() && WC()->cart->is_empty() ) {
		$class[] = 'c-post__container--cart';
	} else {
		$class[] = 'c-post__container--cart';
	}
} elseif ( is_page() ) {
	$class[] = 'c-post__container--page';
} else {
	$class[] = 'c-post__container--post';
}
?>
<?php get_header(); ?>
<?php if ( have_posts() ): ?>
	<?php the_post(); ?>
	<div class="<?php echo implode( ' ', $class ); ?>">
		<div
			class="l-section__content<?php if ( $with_sidebar ) { ?> l-section__content--with-sidebar<?php } ?>">
			<?php if ( $with_sidebar && ideapark_mod( 'sticky_sidebar' ) ) { ?>
			<div class="js-sticky-sidebar-nearby"><?php } ?>
				<?php
				if ( ideapark_is_wishlist_page() ) {
					ideapark_get_template_part( 'woocommerce/wishlist' );
				} elseif ( ideapark_woocommerce_on() && ( is_cart() || is_checkout() || is_account_page() ) ) {
					the_content();
				} else {
					ideapark_get_template_part( 'templates/content', [ 'with_sidebar' => $with_sidebar ] );
				}
				?>
				<?php if ( $with_sidebar && ideapark_mod( 'sticky_sidebar' ) ) { ?>
			</div><?php } ?>
		</div>
		<?php if ( $with_sidebar ) { ?>
			<div class="l-section__sidebar l-section__sidebar--right">
				<?php get_sidebar( 'post' ); ?>
			</div>
		<?php } ?>
	</div>
<?php endif; ?>

<?php get_footer(); ?>











