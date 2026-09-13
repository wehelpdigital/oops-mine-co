<?php
$position = ! empty( $ideapark_var['position'] ) ? trim( $ideapark_var['position'] ) : 'default';
if ( function_exists( 'rank_math_the_breadcrumbs' ) ) {
	rank_math_the_breadcrumbs( [
		'delimiter'   => '<i class="ip-breadcrumb c-breadcrumbs__separator"><!-- --></i>',
		'wrap_before' => '<nav aria-label="breadcrumbs" class="rank-math-breadcrumb c-breadcrumbs">',
		'wrap_after'  => '</nav>',
	] );
} elseif ( function_exists( 'yoast_breadcrumb' ) ) {
	yoast_breadcrumb( '<nav aria-label="breadcrumbs" class="c-breadcrumbs c-breadcrumbs--yoast">', '</nav>' );
} elseif ( ideapark_mod( 'header_breadcrumbs' ) && ( $breadcrumb_items = ideapark_breadcrumb_list() ) ) { ?>
	<nav class="c-breadcrumbs">
		<ol class="c-breadcrumbs__list c-breadcrumbs__list--<?php echo esc_attr( $position ); ?>" itemscope
			itemtype="http://schema.org/BreadcrumbList">
			<?php
			$i               = 1;
			$with_hidden     = sizeof( $breadcrumb_items ) > 1 && ! empty( $breadcrumb_items[ sizeof( $breadcrumb_items ) - 1 ]['is_hidden'] );
			foreach ( $breadcrumb_items as $item_index => $item ):
				$title = isset( $item['title'] ) ? $item['title'] : '';
				$link        = isset( $item['link'] ) ? $item['link'] : '';
				$is_last     = $item_index == sizeof( $breadcrumb_items ) - 1;
				$is_previous = $item_index == sizeof( $breadcrumb_items ) - 2;
				?>
				<li class="c-breadcrumbs__item <?php if ( $with_hidden && $is_last ) { ?>h-hidden <?php } ?><?php ideapark_class( ! $item_index, 'c-breadcrumbs__item--first' ); ?> <?php ideapark_class( $item_index == sizeof( $breadcrumb_items ) - 1, 'c-breadcrumbs__item--last' ); ?>"
					itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem">
					<?php if ( $item['link'] ) { ?><a itemprop="item" title="<?php echo esc_attr( $title ); ?>"
													  href="<?php echo esc_url( $link ); ?>"><?php } ?><span
							itemprop="name"><?php echo esc_html( $title ); ?></span><?php if ( $item['link'] ) { ?></a>
					<?php } ?><?php if ( ! $is_last && ! ( $with_hidden && $is_previous ) ) { ?><!--
						--><i class="ip-breadcrumb c-breadcrumbs__separator"><!-- --></i><?php } ?>
					<meta itemprop="position" content="<?php echo esc_attr( $i ); ?>">
				</li>
				<?php
				$i ++;
			endforeach;
			?>
		</ol>
	</nav>
<?php } ?>