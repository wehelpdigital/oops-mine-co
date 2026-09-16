<?php
/**
 * Child override of the parent's desktop logo block.
 *
 * Same markup and sticky/front-page-link behaviour as the parent, but the image comes from omc_logo():
 * the Customizer logo when one is set, otherwise the brand PNG bundled with the theme (assets/img).
 * The parent falls back to a text placeholder when the `logo` mod is empty; we never need that.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

$omc_logo   = omc_logo( 'rose' );
$omc_sticky = (string) ideapark_mod( 'logo_sticky' );
$omc_link   = ! is_front_page() || ! ideapark_mod( 'remove_frontpage_logo_link' );
?>
<div class="c-header__logo c-header__logo--desktop<?php if ( $omc_sticky ) { ?> c-header__logo--sticky<?php } ?> <?php ideapark_class( ideapark_mod( 'sticky_menu_desktop' ) && ideapark_mod( 'sticky_logo_desktop_hide' ), 'c-header__logo--sticky-hide' ); ?>">
	<?php if ( $omc_link ) { ?><a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="c-header__logo-link"><?php } ?>
		<?php echo omc_logo_img( 'rose', 'c-header__logo-img c-header__logo-img--desktop' . ( preg_match( '~\.svg$~i', $omc_logo['url'] ) ? ' c-header__logo-img--svg' : '' ), [ 'fetchpriority' => 'high' ] ); ?>
		<?php if ( $omc_sticky ) { ?>
			<img <?php echo ideapark_mod_image_size( 'logo_sticky' ); ?> src="<?php echo esc_url( $omc_sticky ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" class="c-header__logo-img c-header__logo-img--sticky <?php ideapark_svg_logo_class( $omc_sticky ); ?>"/>
		<?php } ?>
		<?php if ( ideapark_mod( 'sticky_menu_desktop' ) && ideapark_mod( 'sticky_logo_desktop_hide' ) && ideapark_mod( 'sticky_logo_desktop_hide_text' ) ) { ?>
			<span class="c-header__logo-empty c-header__logo-hidden"><?php echo esc_html( trim( ideapark_mod( 'sticky_logo_desktop_hide_text' ) ) ); ?></span>
		<?php } ?>
	<?php if ( $omc_link ) { ?></a><?php } ?>
</div>
