<?php if ( trim( ideapark_mod( 'header_hours' ) ) ) { ?>
	<div class="c-header__top-row-item c-header__top-row-item--hours">
		<?php if ( ideapark_mod( 'header_block_icon' ) ) { ?>
			<i class="ip-time c-header__top-row-icon c-header__top-row-icon--hours"></i>
		<?php } ?>
		<?php echo esc_html( trim( ideapark_mod( 'header_hours' ) ) ); ?>
	</div>
<?php } ?>