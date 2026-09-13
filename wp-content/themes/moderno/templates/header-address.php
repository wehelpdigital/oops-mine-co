<?php if ( trim( ideapark_mod( 'header_address' ) ) ) { ?>
	<div class="c-header__top-row-item c-header__top-row-item--address">
		<?php if ( ideapark_mod( 'header_block_icon' ) ) { ?>
			<i class="ip-map-pin c-header__top-row-icon c-header__top-row-icon--address"></i>
		<?php } ?>
		<?php echo esc_html( trim( ideapark_mod( 'header_address' ) ) ); ?>
	</div>
<?php } ?>