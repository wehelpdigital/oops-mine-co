<?php
/**
 * "This or that": a deck of pieces on the home page to swipe through — right (or ♥) for love, left
 * (or ✕) for not this one. Built for a phone first: one card at a time, a thumb's reach from the
 * buttons, the drag follows the finger.
 *
 * A love goes into the theme's wishlist, exactly as the heart on a product card would put it there
 * (and is counted by the Meta pixel as AddToWishlist), and the WHD stylist reads the wishlist as
 * taste — so a few swipes change what the stylist suggests. A skip is remembered in this browser
 * only, so the deck does not offer the same piece again.
 *
 * Words are the Page Editor's (home page → "This or that"); the pieces are the newest in stock with a
 * photograph, from the same part of the shop as the home page's grids.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

const OMC_SWIPE_CARDS = 18;

/** The deck: newest in-stock pieces with a photo, from the home grids' category. */
function omc_swipe_products() {
	if ( ! function_exists( 'wc_get_products' ) ) {
		return [];
	}
	$cat = apply_filters( 'omc_home_product_category', omc_shop_root_category() );
	return wc_get_products( array_filter( [
		'status'       => 'publish',
		'limit'        => OMC_SWIPE_CARDS * 2,
		'orderby'      => 'date',
		'order'        => 'DESC',
		'stock_status' => 'instock',
		'visibility'   => 'catalog',
		'category'     => $cat ? [ $cat ] : [],
	] ) );
}

function omc_swipe_section() {
	if ( ! function_exists( 'omc_pe' ) || ! omc_pe( 'home', 'swipe.enabled', true ) ) {
		return;
	}
	$cards = [];
	foreach ( omc_swipe_products() as $p ) {
		if ( $p->get_image_id() && count( $cards ) < OMC_SWIPE_CARDS ) {
			$cards[] = $p;
		}
	}
	if ( count( $cards ) < 4 ) {
		return;
	}
	$wishlist = function_exists( 'ideapark_mod' ) ? (int) ideapark_mod( 'wishlist_page' ) : 0;
	$stylist  = class_exists( 'WHD_Stylist' ) && WHD_Stylist::enabled();
	?>
	<section class="omc-section omc-swipe omc-reveal" aria-labelledby="omc-swipe-title">
		<div class="l-section__container omc-swipe__inner">
			<div class="omc-swipe__copy">
				<p class="omc-eyebrow"><?php echo esc_html( omc_pe_copy( 'home', 'swipe.eyebrow' ) ); ?></p>
				<h2 class="omc-section__title" id="omc-swipe-title"><?php echo omc_pe_em( omc_pe_copy( 'home', 'swipe.title' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped, then *x* → <em>x</em> ?></h2>
				<p class="omc-swipe__text"><?php echo esc_html( omc_pe_copy( 'home', 'swipe.text' ) ); ?></p>
				<p class="omc-swipe__tally" aria-live="polite">
					<?php echo omc_icon( 'heart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline SVG ?>
					<span class="omc-swipe__count">0</span>
					<span><?php esc_html_e( 'loved so far', 'moderno-child' ); ?></span>
				</p>
				<div class="omc-swipe__links">
					<?php if ( $wishlist ) : ?>
						<a class="omc-link omc-swipe__wish" href="<?php echo esc_url( get_permalink( $wishlist ) ); ?>"><?php esc_html_e( 'See what you loved', 'moderno-child' ); ?></a>
					<?php endif; ?>
				</div>
			</div>

			<div class="omc-swipe__play">
				<div class="omc-swipe__deck" data-omc-swipe
					data-wishlist="<?php echo esc_attr( $wishlist ? get_permalink( $wishlist ) : '' ); ?>"
					data-saved="<?php esc_attr_e( 'Saved to your wishlist', 'moderno-child' ); ?>"
					data-skipped="<?php esc_attr_e( 'Not this one', 'moderno-child' ); ?>"
					tabindex="0" role="group" aria-roledescription="<?php esc_attr_e( 'card deck', 'moderno-child' ); ?>"
					aria-label="<?php esc_attr_e( 'Pieces to love or skip — swipe, or use the arrow keys', 'moderno-child' ); ?>">
					<?php foreach ( $cards as $i => $p ) : ?>
						<article class="omc-swipe__card" data-id="<?php echo (int) $p->get_id(); ?>" aria-hidden="<?php echo 0 === $i ? 'false' : 'true'; ?>">
							<div class="omc-swipe__media">
								<?php
								echo wp_get_attachment_image( $p->get_image_id(), 'woocommerce_single', false, [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
									'loading'   => $i < 3 ? 'eager' : 'lazy',
									'decoding'  => 'async',
									'alt'       => html_entity_decode( $p->get_name(), ENT_QUOTES, 'UTF-8' ),
									'draggable' => 'false',
									'sizes'     => '(max-width: 640px) 90vw, 420px',
								] );
								?>
								<span class="omc-swipe__stamp omc-swipe__stamp--love" aria-hidden="true"><?php esc_html_e( 'Love', 'moderno-child' ); ?></span>
								<span class="omc-swipe__stamp omc-swipe__stamp--skip" aria-hidden="true"><?php esc_html_e( 'Skip', 'moderno-child' ); ?></span>
							</div>
							<div class="omc-swipe__info">
								<h3 class="omc-swipe__name"><a href="<?php echo esc_url( $p->get_permalink() ); ?>"><?php echo esc_html( $p->get_name() ); ?></a></h3>
								<span class="omc-swipe__price"><?php echo wp_kses_post( $p->get_price_html() ); ?></span>
							</div>
						</article>
					<?php endforeach; ?>
					<div class="omc-swipe__end" hidden>
						<p class="omc-eyebrow"><?php esc_html_e( 'That’s the rail for now', 'moderno-child' ); ?></p>
						<p class="omc-swipe__end-title"><?php echo esc_html( omc_pe_copy( 'home', 'swipe.done' ) ); ?></p>
						<?php if ( $stylist ) : ?>
							<button type="button" class="omc-btn omc-btn--solid" data-whd-stylist><?php esc_html_e( 'Let the stylist pick', 'moderno-child' ); ?></button>
						<?php endif; ?>
						<?php if ( $wishlist ) : ?>
							<a class="omc-btn omc-btn--outline" href="<?php echo esc_url( get_permalink( $wishlist ) ); ?>"><?php esc_html_e( 'See what you loved', 'moderno-child' ); ?></a>
						<?php endif; ?>
						<button type="button" class="omc-swipe__again"><?php esc_html_e( 'Start the deck again', 'moderno-child' ); ?></button>
					</div>
				</div>
				<div class="omc-swipe__actions">
					<button type="button" class="omc-swipe__btn omc-swipe__btn--skip" data-swipe="skip" aria-label="<?php esc_attr_e( 'Not for me', 'moderno-child' ); ?>"><?php echo omc_icon( 'x' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
					<button type="button" class="omc-swipe__btn omc-swipe__btn--love" data-swipe="love" aria-label="<?php esc_attr_e( 'Love it — save to my wishlist', 'moderno-child' ); ?>"><?php echo omc_icon( 'heart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
				</div>
				<p class="omc-swipe__status omc-sr" role="status" aria-live="polite"></p>
			</div>
		</div>
	</section>
	<?php
}
