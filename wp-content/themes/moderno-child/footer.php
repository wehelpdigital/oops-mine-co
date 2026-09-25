<?php
/**
 * Child override of the parent's footer.php — the Oops, Mine Co. footer.
 *
 * The parent renders an Elementor "footer page" here, which depends on Elementor's
 * frontend CSS being loaded; on the OMC templates (not built with Elementor) it
 * isn't, so that footer arrives unstyled — and it carries the demo's fake contact
 * details. This footer is plain theme markup: brand, shop links, help links,
 * newsletter, copyright. It keeps the parent's structural pieces (closing <main>,
 * the .c-footer classes the theme's CSS/JS expect, wp_footer()).
 *
 * Links: omc_footer_links() / filter `omc_footer_links`. Social: filter `omc_social_profiles`.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

$omc_links  = omc_footer_links();
$omc_social = apply_filters( 'omc_social_profiles', [] );
$omc_year   = date_i18n( 'Y' );
// The front page already ends with the newsletter band, so its footer skips the duplicate form.
$omc_show_news = apply_filters( 'omc_footer_newsletter', ! is_front_page() );
?>
</main><!-- /.l-inner -->
<footer class="l-section c-footer omc-footer c-footer--mobile-buttons-<?php echo esc_attr( ideapark_mod( 'bottom_buttons_mobile_locations' ) ); ?><?php if ( ideapark_mod( 'sticky_add_to_cart' ) ) { ?> c-footer--sticky-add-to-cart<?php } ?>">
	<div class="l-section__container-wide omc-footer__inner">
		<div class="omc-footer__grid<?php echo $omc_show_news ? '' : ' omc-footer__grid--3'; ?>">

			<div class="omc-footer__brand">
				<a class="omc-footer__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo omc_logo_img( 'rose', '', [ 'loading' => 'lazy' ] ); ?></a>
				<p class="omc-footer__tagline"><?php esc_html_e( 'Found with intention. Claimed on instinct.', 'moderno-child' ); ?></p>
				<p class="omc-footer__blurb"><?php esc_html_e( 'Curated Korean and Thai fashion — pieces chosen one at a time, for the moment you see something and think: oops, mine.', 'moderno-child' ); ?></p>
				<?php if ( $omc_social ) : ?>
					<ul class="omc-footer__social" aria-label="<?php esc_attr_e( 'Follow us', 'moderno-child' ); ?>">
						<?php foreach ( $omc_social as $omc_network => $omc_url ) : ?>
							<li><a href="<?php echo esc_url( $omc_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( is_string( $omc_network ) ? $omc_network : wp_parse_url( $omc_url, PHP_URL_HOST ) ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $omc_links['shop'] ) ) : ?>
				<nav class="omc-footer__col" aria-labelledby="omc-footer-shop">
					<h2 class="omc-footer__heading" id="omc-footer-shop"><?php esc_html_e( 'Shop', 'moderno-child' ); ?></h2>
					<ul>
						<?php foreach ( $omc_links['shop'] as $omc_l ) : ?>
							<li><a href="<?php echo esc_url( $omc_l['url'] ); ?>"><?php echo esc_html( $omc_l['label'] ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</nav>
			<?php endif; ?>

			<?php if ( ! empty( $omc_links['help'] ) ) : ?>
				<nav class="omc-footer__col" aria-labelledby="omc-footer-help">
					<h2 class="omc-footer__heading" id="omc-footer-help"><?php esc_html_e( 'Help', 'moderno-child' ); ?></h2>
					<ul>
						<?php foreach ( $omc_links['help'] as $omc_l ) : ?>
							<li><a href="<?php echo esc_url( $omc_l['url'] ); ?>"><?php echo esc_html( $omc_l['label'] ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</nav>
			<?php endif; ?>

			<?php if ( $omc_show_news ) : ?>
				<div class="omc-footer__col omc-footer__newsletter">
					<h2 class="omc-footer__heading"><?php esc_html_e( 'Stay close', 'moderno-child' ); ?></h2>
					<p><?php esc_html_e( 'New arrivals, quiet restocks and the occasional note. No noise.', 'moderno-child' ); ?></p>
					<?php omc_newsletter_form(); ?>
				</div>
			<?php endif; ?>

		</div>

		<?php $omc_badges = function_exists( 'omc_footer_badges' ) ? omc_footer_badges() : []; ?>
		<?php if ( $omc_badges ) : ?>
			<ul class="omc-footer__badges" aria-label="<?php esc_attr_e( 'Shopping here', 'moderno-child' ); ?>">
				<?php foreach ( $omc_badges as $omc_b ) : ?>
					<li class="omc-footer__badge">
						<?php echo omc_icon( $omc_b['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
						<span>
							<strong><?php echo esc_html( $omc_b['title'] ); ?></strong>
							<em><?php echo esc_html( $omc_b['text'] ); ?></em>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<div class="omc-footer__bottom">
			<p class="omc-footer__copy">&copy; <?php echo esc_html( $omc_year . ' ' . rtrim( get_bloginfo( 'name' ), '.' ) ); ?>. <?php esc_html_e( 'All rights reserved.', 'moderno-child' ); ?></p>
			<?php if ( ! empty( $omc_links['legal'] ) ) : ?>
				<ul class="omc-footer__legal">
					<?php foreach ( $omc_links['legal'] as $omc_l ) : ?>
						<li><a href="<?php echo esc_url( $omc_l['url'] ); ?>"><?php echo esc_html( $omc_l['label'] ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
