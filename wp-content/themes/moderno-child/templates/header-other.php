<?php
/**
 * Child override of the parent's "Other" header block.
 *
 * The parent's desktop header is a grid of blocks placed from Customizer → Header (theme mod `header_blocks_1`);
 * this site has the "Other" block enabled in the top-left cell of the logo row. We use that slot for the social
 * icons (omc_social_links(): Facebook, Instagram, email, Pinterest), then print whatever the parent's own
 * "Other" text setting holds, exactly as the parent template would.
 *
 * The parent also renders this block inside the mobile menu (header_blocks_2); omc.css hides the icons there
 * because the mobile menu already lists the theme's social links, and on phones the icons sit in the
 * announcement bar instead.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

$omc_links = function_exists( 'omc_social_links' ) ? omc_social_links() : [];
if ( $omc_links ) : ?>
	<div class="c-header__top-row-item omc-header-social" aria-label="<?php esc_attr_e( 'Follow Oops, Mine Co.', 'moderno-child' ); ?>">
		<?php foreach ( $omc_links as $omc_key => $omc_l ) : ?>
			<a href="<?php echo esc_url( $omc_l['url'] ); ?>" aria-label="<?php echo esc_attr( $omc_l['label'] ); ?>" title="<?php echo esc_attr( $omc_l['label'] ); ?>"<?php echo 0 === strpos( $omc_l['url'], 'mailto:' ) ? '' : ' target="_blank" rel="noopener"'; ?>><?php echo omc_icon( $omc_key ); ?></a>
		<?php endforeach; ?>
	</div>
<?php endif; ?>
<?php if ( function_exists( 'ideapark_mod' ) && trim( (string) ideapark_mod( 'header_other' ) ) ) { ?>
	<div class="c-header__top-row-item c-header__top-row-item--other">
		<?php echo do_shortcode( trim( ideapark_mod( 'header_other' ) ) ); ?>
	</div>
<?php } ?>
