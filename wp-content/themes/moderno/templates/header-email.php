<?php if ( trim( ideapark_mod( 'header_email' ) ) ) { ?>
	<div class="c-header__top-row-item c-header__top-row-item--email">
		<?php if ( ideapark_mod( 'header_block_icon' ) ) { ?>
			<i class="ip-email c-header__top-row-icon c-header__top-row-icon--email"></i>
		<?php } ?>
		<?php echo make_clickable( esc_html( trim( ideapark_mod( 'header_email' ) ) ) ); ?>
	</div>
<?php } ?>