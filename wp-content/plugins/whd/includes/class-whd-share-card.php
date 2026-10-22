<?php
/**
 * "Just got mine": a card of the piece somebody has just bought, at Instagram-story size, made on
 * the order-received page for them to save or share.
 *
 * The card is drawn in the browser with the same Fabric.js the Image Editor runs on, from a design
 * made in the Image Editor: WHD → Settings → "Order share card" names one saved design, whose photo
 * (the one the design was started from) is swapped for the piece's photograph and whose words have
 * {product} and {first_name} filled in. With no design chosen, a plain card in the shop's colours is
 * drawn instead, so the feature works before anyone has opened the editor.
 *
 * Nothing is uploaded or stored: the picture is made on the shopper's own device from photos the
 * shop already shows, and leaves only if they save or share it.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Share_Card {

	public static function init() {
		add_action( 'woocommerce_thankyou', [ __CLASS__, 'render' ], 5 );
	}

	public static function enabled(): bool {
		return ! empty( WHD_Plugin::settings()['share_card_on'] );
	}

	/** Designs saved from the Image Editor, newest first: [ id => title ]. */
	public static function designs(): array {
		$ids = get_posts( [
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'numberposts' => 60,
			'fields'      => 'ids',
			'meta_key'    => WHD_Image_Editor::DESIGN, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- admin screen, 60 rows
			'orderby'     => 'date',
			'order'       => 'DESC',
		] );
		$out = [];
		foreach ( $ids as $id ) {
			$design = json_decode( (string) get_post_meta( $id, WHD_Image_Editor::DESIGN, true ), true );
			$size   = is_array( $design ) && ! empty( $design['W'] ) ? (int) $design['W'] . ' × ' . (int) $design['H'] : '';
			$out[ (int) $id ] = html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) . ( $size ? ' (' . $size . ')' : '' );
		}
		return $out;
	}

	/** The chosen design, decoded, or null for the built-in card. */
	public static function design(): ?array {
		$id = (int) ( WHD_Plugin::settings()['share_card'] ?? 0 );
		if ( ! $id ) {
			return null;
		}
		$design = json_decode( (string) get_post_meta( $id, WHD_Image_Editor::DESIGN, true ), true );
		return is_array( $design ) && ! empty( $design['json'] ) && ! empty( $design['W'] ) ? $design : null;
	}

	/** Every font family a design uses, so the page loads those and no others. */
	private static function families( $node, array &$out ) {
		if ( ! is_array( $node ) ) {
			return;
		}
		if ( ! empty( $node['fontFamily'] ) && is_string( $node['fontFamily'] ) ) {
			$out[ trim( $node['fontFamily'], '"\'' ) ] = true;
		}
		foreach ( $node as $child ) {
			if ( is_array( $child ) ) {
				self::families( $child, $out );
			}
		}
	}

	/** The pieces in the order that have a photograph, one card each (variations share the parent's name). */
	private static function items( WC_Order $order ): array {
		$out  = [];
		$seen = [];
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
			$image  = (int) $product->get_image_id() ?: (int) ( $parent ? $parent->get_image_id() : 0 );
			if ( ! $image || isset( $seen[ $image ] ) ) {
				continue;
			}
			$seen[ $image ] = true;
			$url            = wp_get_attachment_image_url( $image, '1536x1536' ) ?: wp_get_attachment_image_url( $image, 'full' );
			if ( ! $url ) {
				continue;
			}
			$out[] = [
				'name'  => $parent ? $parent->get_name() : $item->get_name(),
				'image' => $url,
				'thumb' => (string) wp_get_attachment_image_url( $image, 'thumbnail' ),
			];
		}
		return array_slice( $out, 0, 8 );
	}

	public static function render( $order_id ) {
		if ( ! self::enabled() ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->has_status( [ 'failed', 'cancelled' ] ) ) {
			return;
		}
		$items = self::items( $order );
		if ( ! $items ) {
			return;
		}

		$design   = self::design();
		$families = [ 'Cormorant Garamond' => true, 'Manrope' => true ];
		if ( $design ) {
			self::families( $design['json'], $families );
		}
		$fonts = WHD_Image_Editor::fonts();
		$query = [];
		foreach ( array_keys( $families ) as $family ) {
			if ( isset( $fonts[ $family ] ) ) {
				$query[] = 'family=' . str_replace( ' ', '+', $family ) . ( '' !== $fonts[ $family ] ? ':' . $fonts[ $family ] : '' );
			}
		}
		if ( $query ) {
			wp_enqueue_style( 'whd-share-fonts', 'https://fonts.googleapis.com/css2?' . implode( '&', $query ) . '&display=swap', [], null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}
		wp_enqueue_style( 'whd-share-card', WHD_URL . 'assets/share-card.css', [], WHD_VERSION );
		wp_enqueue_script( 'whd-fabric', WHD_URL . 'admin/vendor/fabric.min.js', [], '5.5.2', true );
		wp_enqueue_script( 'whd-share-card', WHD_URL . 'assets/share-card.js', [ 'whd-fabric' ], WHD_VERSION, true );

		/**
		 * The shop's mark and handle for the built-in card.
		 *
		 * @param array $brand logo (URL of the shop's mark, drawn on the cream band under the photo), handle (e.g. @shop), site.
		 */
		$brand = (array) apply_filters( 'whd_share_card_brand', [
			'logo'   => '',
			'handle' => '',
			'site'   => WHD_Emails::site_domain(),
			'name'   => get_bloginfo( 'name' ),
		] );

		wp_localize_script( 'whd-share-card', 'WHD_SHARE', [
			'items'  => $items,
			'first'  => (string) $order->get_billing_first_name(),
			'design' => $design,
			'brand'  => $brand,
			'i18n'   => [
				'title'   => __( 'Just got mine', 'whd' ),
				'line'    => __( 'Just got mine.', 'whd' ),
				'making'  => __( 'Making your card…', 'whd' ),
				'failed'  => __( 'The card could not be drawn on this device.', 'whd' ),
				'shared'  => __( 'Shared — thank you for showing it off.', 'whd' ),
				'saved'   => __( 'Saved. Add it to your story from your photos.', 'whd' ),
				'file'    => sanitize_title( get_bloginfo( 'name' ) ) . '-just-got-mine',
				/* translators: %d: the piece's number, e.g. Piece 2 */
				'piece'   => __( 'Piece %d', 'whd' ),
			],
		] );

		$handle = trim( (string) ( $brand['handle'] ?? '' ) );
		?>
		<section class="whd-share" id="whd-share" aria-labelledby="whd-share-title">
			<div class="whd-share__copy">
				<p class="whd-share__eyebrow"><?php esc_html_e( 'Just got mine', 'whd' ); ?></p>
				<h2 class="whd-share__title" id="whd-share-title"><?php echo wp_kses( __( 'Made for your <em>story</em>', 'whd' ), [ 'em' => [] ] ); ?></h2>
				<p class="whd-share__text">
					<?php
					echo esc_html(
						$handle
							/* translators: %s: the shop's Instagram handle */
							? sprintf( __( 'We made a card of your piece, sized for an Instagram story. Save it or share it straight to your story — and tag %s so we can see it.', 'whd' ), $handle )
							: __( 'We made a card of your piece, sized for an Instagram story. Save it, or share it straight to your story.', 'whd' )
					);
					?>
				</p>
				<?php if ( count( $items ) > 1 ) : ?>
					<div class="whd-share__picks" role="group" aria-label="<?php esc_attr_e( 'Which piece', 'whd' ); ?>">
						<?php foreach ( $items as $i => $it ) : ?>
							<button type="button" class="whd-share__pick<?php echo 0 === $i ? ' is-on' : ''; ?>" data-i="<?php echo (int) $i; ?>" aria-pressed="<?php echo 0 === $i ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr( $it['name'] ); ?>">
								<img src="<?php echo esc_url( $it['thumb'] ?: $it['image'] ); ?>" alt="" loading="lazy">
							</button>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<div class="whd-share__actions">
					<button type="button" class="whd-share__btn whd-share__btn--share" hidden><?php esc_html_e( 'Share to your story', 'whd' ); ?></button>
					<a class="whd-share__btn whd-share__btn--save" href="#" download hidden><?php esc_html_e( 'Save the image', 'whd' ); ?></a>
				</div>
				<p class="whd-share__note" role="status" aria-live="polite"></p>
			</div>
			<figure class="whd-share__preview">
				<img class="whd-share__img" alt="<?php esc_attr_e( 'Your story card', 'whd' ); ?>" hidden>
				<span class="whd-share__wait"><?php esc_html_e( 'Making your card…', 'whd' ); ?></span>
			</figure>
		</section>
		<?php
	}
}
