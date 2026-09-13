<div class="c-header-search disabled js-ajax-search">
	<div class="c-header-search__wrap">
		<div class="c-header-search__shadow js-search-close"></div>
		<div class="c-header-search__form">
			<div class="c-header-search__tip"><?php esc_html_e( 'What are you looking for?', 'moderno' ); ?></div>
			<?php ideapark_af( 'get_search_form', 'ideapark_search_form_ajax', 90 ); ?>
			<?php get_search_form(); ?>
			<?php ideapark_rf( 'get_search_form', 'ideapark_search_form_ajax', 90 ); ?>
		</div>
		<div class="l-section l-section--container c-header-search__result js-ajax-search-result">

		</div>
		<?php ideapark_close_button('js-search-close'); ?>
	</div>
</div>
