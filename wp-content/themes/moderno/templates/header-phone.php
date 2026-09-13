<?php if ( trim( ideapark_mod( 'header_phone' ) ) ) { ?>
	<div class="c-header__top-row-item c-header__top-row-item--phone">
		<?php if ( ideapark_mod( 'header_block_icon' ) ) { ?>
			<i class="ip-phone c-header__top-row-icon c-header__top-row-icon--phone"></i>
		<?php } ?>
		<?php echo ideapark_phone_wrap( esc_html( trim( ideapark_mod( 'header_phone' ) ) ) ); ?>
	</div>
<?php } ?>