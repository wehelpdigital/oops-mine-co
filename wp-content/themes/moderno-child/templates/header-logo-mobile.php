<?php
/**
 * Child override of the parent's mobile logo block — see templates/header-logo.php.
 * Order of preference: Customizer mobile logo → Customizer logo → bundled brand PNG.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

$omc_logo   = omc_logo( 'rose' );
$omc_mobile = (string) ideapark_mod( 'logo_mobile' );
$omc_sticky = (string) ( ideapark_mod( 'logo_mobile_sticky' ) ?: ideapark_mod( 'logo_sticky' ) );
$omc_link   = ! is_front_page() || ! ideapark_mod( 'remove_frontpage_logo_link' );
?>
<div class="c-header__logo c-header__logo--mobile<?php if ( ideapark_mod( 'sticky_logo_mobile_hide' ) ) { ?> c-header__logo--mobile-sticky-hide<?php } ?><?php if ( $omc_sticky ) { ?> c-header__logo--sticky<?php } ?>">
	<?php if ( $omc_link ) { ?><a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="c-header__logo-link"><?php } ?>
		<?php if ( $omc_mobile ) { ?>
			<img <?php echo ideapark_mod_image_size( 'logo_mobile' ); ?> src="<?php echo esc_url( $omc_mobile ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" class="c-header__logo-img c-header__logo-img--mobile <?php ideapark_svg_logo_class( $omc_mobile ); ?>"/>
		<?php } else { ?>
			<?php echo omc_logo_img( 'rose', 'c-header__logo-img c-header__logo-img--all' . ( preg_match( '~\.svg$~i', $omc_logo['url'] ) ? ' c-header__logo-img--svg' : '' ), [ 'fetchpriority' => 'high' ] ); ?>
		<?php } ?>
		<?php if ( $omc_sticky ) { ?>
			<img src="<?php echo esc_url( $omc_sticky ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" class="c-header__logo-img c-header__logo-img--sticky <?php ideapark_svg_logo_class( $omc_sticky ); ?>"/>
		<?php } ?>
	<?php if ( $omc_link ) { ?></a><?php } ?>
</div>
