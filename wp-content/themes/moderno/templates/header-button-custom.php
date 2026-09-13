<?php
if ( ideapark_mod( 'custom_header_button_icon' ) ) { ?>
	<a href="<?php echo esc_url( ideapark_mod( 'custom_header_button_link' ) ); ?>" class="h-cb c-header__button-link c-header__custom-button" type="button"
	   <?php if ( ideapark_mod( 'custom_header_button_link' ) && ideapark_mod( 'custom_header_button_new_window' ) ) { ?>target="_blank"<?php } ?>
	   <?php if ( ideapark_mod( 'custom_header_button_link' ) && ideapark_mod( 'custom_header_button_no_follow' ) ) { ?>rel="nofollow"<?php } ?>
	   aria-label="<?php echo esc_attr( ideapark_mod( 'custom_header_button_title' ) ); ?>" title="<?php echo esc_attr( ideapark_mod( 'custom_header_button_title' ) ); ?>"><i
			class="<?php echo ideapark_mod( 'custom_header_button_icon' ); ?>"><!-- --></i></a>
<?php } ?>