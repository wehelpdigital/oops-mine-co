<?php
/**
 * Two more things under the product page's buy box:
 *
 *   - "Ask about this piece": Messenger and Instagram buttons that open a chat with the shop, the
 *     message already written — the piece, the size picked on the page, and its link. Messenger takes
 *     the words in its link; Instagram cannot, so the message is copied for pasting either way and the
 *     button says so. A button only shows for a profile the shop has actually set (Customizer → Social
 *     Media Links): a bare instagram.com is not a place anyone can be messaged.
 *   - "Wear it with": two or three pieces from the rail that complete this one, chosen by the WHD
 *     stylist's own matching (WHD_Stylist::goes_with) — so they can only ever be real, in-stock pieces
 *     — each with its size picker, plus "Add the look" for all of them with this one.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

/* ─────────────────────────── Ask about this piece ─────────────────────────── */

/**
 * Where a chat opens: [ messenger => url, instagram => url ], only for profiles that are set.
 * Filter `omc_ask_links`.
 */
function omc_ask_links() {
	$links = function_exists( 'omc_social_links' ) ? omc_social_links() : [];
	$out   = [];

	$fb = (string) ( $links['facebook']['url'] ?? '' );
	if ( $fb && preg_match( '#facebook\.com/(?:p/)?([^/?\#]+)#i', $fb, $m ) ) {
		$page = $m[1];
		// A page with no username ("Oops-Mine-Co-100090677450840") is reached by its number.
		if ( preg_match( '/-(\d{6,})$/', $page, $n ) ) {
			$page = $n[1];
		}
		if ( ! in_array( strtolower( $page ), [ 'profile.php', 'pages', 'people', 'groups', 'share' ], true ) ) {
			$out['messenger'] = 'https://m.me/' . rawurlencode( $page );
		}
	}

	$ig = (string) ( $links['instagram']['url'] ?? '' );
	if ( $ig && preg_match( '#instagram\.com/([A-Za-z0-9._]{2,30})/?(?:[?\#].*)?$#i', $ig, $m ) && ! in_array( strtolower( $m[1] ), [ 'p', 'reel', 'reels', 'explore', 'accounts', 'stories' ], true ) ) {
		$out['instagram'] = 'https://ig.me/m/' . $m[1];
	}

	return (array) apply_filters( 'omc_ask_links', $out );
}

function omc_ask_about_piece() {
	if ( ! function_exists( 'omc_pdp_is_product_page' ) || ! omc_pdp_is_product_page() ) {
		return;
	}
	global $product;
	if ( ! $product instanceof WC_Product || (int) get_queried_object_id() !== $product->get_id() ) {
		return;
	}
	$links = omc_ask_links();
	if ( ! $links ) {
		return;
	}
	$labels = [
		'messenger' => [ __( 'Messenger', 'moderno-child' ), 'messenger' ],
		'instagram' => [ __( 'Instagram', 'moderno-child' ), 'instagram' ],
	];
	?>
	<div class="omc-ask" data-omc-ask
		data-name="<?php echo esc_attr( html_entity_decode( $product->get_name(), ENT_QUOTES, 'UTF-8' ) ); ?>"
		data-url="<?php echo esc_url( $product->get_permalink() ); ?>"
		data-hello="<?php echo esc_attr__( 'Hi! I have a question about the %s', 'moderno-child' ); ?>"
		data-copied="<?php echo esc_attr__( 'Your message is copied — paste it into the chat if it opens empty.', 'moderno-child' ); ?>">
		<p class="omc-ask__h"><?php esc_html_e( 'Ask about this piece', 'moderno-child' ); ?></p>
		<div class="omc-ask__row">
			<?php foreach ( $links as $key => $url ) : ?>
				<?php if ( isset( $labels[ $key ] ) ) : ?>
					<a class="omc-ask__btn omc-ask__btn--<?php echo esc_attr( $key ); ?>" href="<?php echo esc_url( $url ); ?>" data-base="<?php echo esc_url( $url ); ?>" data-prefill="<?php echo 'messenger' === $key ? '1' : '0'; ?>" target="_blank" rel="noopener">
						<?php echo omc_icon( $labels[ $key ][1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline SVG ?>
						<span><?php echo esc_html( $labels[ $key ][0] ); ?></span>
					</a>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<p class="omc-ask__note" role="status" aria-live="polite"></p>
	</div>
	<?php
}
add_action( 'woocommerce_after_add_to_cart_form', 'omc_ask_about_piece', 25 );

/* ─────────────────────────── Wear it with ─────────────────────────── */

function omc_wear_with() {
	if ( ! function_exists( 'omc_pdp_is_product_page' ) || ! omc_pdp_is_product_page() || ! class_exists( 'WHD_Stylist' ) || ! method_exists( 'WHD_Stylist', 'goes_with' ) ) {
		return;
	}
	global $product;
	if ( ! $product instanceof WC_Product || (int) get_queried_object_id() !== $product->get_id() ) {
		return;
	}
	$ids    = WHD_Stylist::goes_with( $product->get_id(), 3 );
	$pieces = array_filter( omc_pieces_use( $ids ), static fn( $p ) => $p['stock'] );
	if ( count( $pieces ) < 2 ) {
		return; // one piece is a suggestion, not an outfit
	}
	$self = omc_pieces_use( [ $product->get_id() ] );
	$look = array_merge( array_keys( $self ), array_keys( $pieces ) );
	?>
	<section class="omc-wear" aria-labelledby="omc-wear-title">
		<h2 class="omc-wear__h" id="omc-wear-title"><?php echo wp_kses( __( 'Wear it <em>with</em>', 'moderno-child' ), [ 'em' => [] ] ); ?></h2>
		<p class="omc-wear__lede"><?php esc_html_e( 'Matched from what is on the rail right now: the other half of the outfit, in colours that sit with this one.', 'moderno-child' ); ?></p>
		<ul class="omc-wear__list">
			<?php foreach ( $pieces as $id => $piece ) : ?>
				<?php $other = wc_get_product( $id ); ?>
				<li class="omc-wear__item">
					<a class="omc-wear__media" href="<?php echo esc_url( $piece['url'] ); ?>" tabindex="-1" aria-hidden="true">
						<?php echo $other ? wp_get_attachment_image( $other->get_image_id(), 'woocommerce_thumbnail', false, [ 'loading' => 'lazy', 'alt' => '' ] ) : ''; ?>
					</a>
					<div class="omc-wear__body">
						<a class="omc-wear__name" href="<?php echo esc_url( $piece['url'] ); ?>"><?php echo esc_html( $piece['name'] ); ?></a>
						<span class="omc-wear__price"><?php echo esc_html( $piece['price'] ); ?></span>
						<div data-omc-picker="<?php echo (int) $id; ?>" data-compact></div>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
		<button type="button" class="omc-btn omc-btn--outline omc-wear__all" data-outfit="<?php echo esc_attr( implode( ',', $look ) ); ?>">
			<?php
			/* translators: %d: number of pieces */
			echo esc_html( sprintf( __( 'Add the look — all %d pieces', 'moderno-child' ), count( $look ) ) );
			?>
		</button>
	</section>
	<?php
}
add_action( 'woocommerce_after_add_to_cart_form', 'omc_wear_with', 30 );
