<div class="c-post-sidebar__wrap">
	<aside id="sidebar"
	       class="c-sidebar <?php if ( ideapark_mod( 'collapse_filters' ) ) { ?> c-sidebar--collapse<?php } ?> c-post-sidebar <?php ideapark_class( ideapark_mod( 'sticky_sidebar' ), 'js-sticky-sidebar' ); ?>">
		<div class="c-sidebar__wrap">
			<?php dynamic_sidebar( 'post-sidebar' ); ?>
		</div>
	</aside>
</div>