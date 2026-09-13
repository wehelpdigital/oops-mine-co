<?php
ideapark_mod_set_temp( '_header_hide_breadcrumbs', true );
ideapark_mod_set_temp( '_header_hide_title', true );
get_header(); ?>
	<section
		class="l-section l-section--container l-section--bottom-margin">

		<div class="c-cart-empty c-cart-empty--404">
			<div class="c-cart-empty__image-wrap">
				<?php if ( ( $image_id = ideapark_mod( '404_empty_image__attachment_id' ) ) && ( $image_meta = ideapark_image_meta( $image_id ) ) ) { ?>
					<?php echo ideapark_img( $image_meta, 'c-cart-empty__image' ); ?>
				<?php } else { ?>
					<i class="ip-404 c-cart-empty__icon c-cart-empty__icon--failed"></i>
				<?php } ?>
			</div>
			<h1 class="c-cart-empty__header"><?php esc_html_e( 'Oops! That page can’t be found', 'moderno' ); ?></h1>
			<p class="c-cart-empty__try"><?php esc_html_e( 'The page you are trying to reach is not available. Maybe try a search?', 'moderno' ); ?></p>
			<div class="c-cart-empty__search">
				<?php get_search_form(); ?>
			</div>

		</div>

	</section>

<?php get_footer(); ?>