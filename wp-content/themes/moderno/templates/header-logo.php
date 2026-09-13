<div
	class="c-header__logo c-header__logo--desktop<?php if ( ideapark_mod( 'logo' ) && ideapark_mod( 'logo_sticky' ) ) { ?> c-header__logo--sticky<?php } ?> <?php ideapark_class( ideapark_mod( 'sticky_menu_desktop' ) && ideapark_mod( 'sticky_logo_desktop_hide' ), 'c-header__logo--sticky-hide' ); ?>">
	<?php if ( ! is_front_page() || ! ideapark_mod( 'remove_frontpage_logo_link' ) ) { ?>
	<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="c-header__logo-link"><?php } ?>
		<?php $logo_url = ideapark_mod( 'logo' ); ?>
		<?php if ( $logo_url ) { ?>
			<img <?php echo ideapark_mod_image_size( 'logo' ); ?>
				src="<?php echo esc_url( $logo_url ); ?>"
				alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
				class="c-header__logo-img c-header__logo-img--desktop <?php ideapark_svg_logo_class( $logo_url ); ?>"/>
		<?php } else { ?>
			<?php $empty_logo = true; ?>
			<span
				class="c-header__logo-empty"><?php echo ideapark_truncate_logo_placeholder(); ?></span>
		<?php } ?>

		<?php if ( ideapark_mod( 'logo_sticky' ) && $logo_url ) { ?>
			<img <?php echo ideapark_mod_image_size( 'logo_sticky' ); ?>
				src="<?php echo esc_url( ideapark_mod( 'logo_sticky' ) ); ?>"
				alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
				class="c-header__logo-img c-header__logo-img--sticky <?php ideapark_svg_logo_class( ideapark_mod( 'logo_sticky' ) ); ?>"/>
		<?php } ?>

		<?php if ( empty( $empty_logo ) && ideapark_mod( 'sticky_menu_desktop' ) && ideapark_mod( 'sticky_logo_desktop_hide' ) && ideapark_mod( 'sticky_logo_desktop_hide_text' ) ) { ?>
			<span
				class="c-header__logo-empty c-header__logo-hidden"><?php echo esc_html( trim( ideapark_mod( 'sticky_logo_desktop_hide_text' ) ) ); ?></span>
		<?php } ?>

		<?php if ( ! is_front_page() || ! ideapark_mod( 'remove_frontpage_logo_link' ) ) { ?></a><?php } ?>
</div>

