<?php
/**
 * Product page — conversion layer for Oops, Mine Co.
 *
 * Everything here is added with WooCommerce / Moderno hooks; no template is
 * overridden, so the parent theme keeps its own product markup, quick view and
 * the Variation Swatches plugin keep working.
 *
 * What it adds
 *  1. Size guide    — a trigger beside the Size row of a variable product (or
 *                     above the add-to-cart button of a simple one) that opens
 *                     an accessible modal. Chart data: omc_pdp_size_chart(), filter `omc_size_chart`.
 *  2. Scarcity      — "Only N left" when stock is managed and low. Real numbers
 *                     only: hidden when stock is not managed.
 *  3. Trust badges  — four compact lines under the add-to-cart form.
 *  4. Review photos — up to three images per review, stored as attachments and
 *                     shown under the review text.
 *
 * Assets: assets/css/omc-pdp.css, assets/js/omc-pdp.js (enqueued in functions.php).
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

/* ───────────────────────────── Helpers ───────────────────────────── */

/**
 * True while the real single-product page is being rendered (not the quick-view
 * AJAX response, not the admin).
 */
function omc_pdp_is_product_page() {
	if ( is_admin() || wp_doing_ajax() ) {
		return false;
	}

	return function_exists( 'is_product' ) && is_product();
}

/**
 * True while the shop is being rendered for a shopper — the product page, the
 * grids and the quick-view / variation AJAX responses that carry stock text.
 * False on admin screens and in REST responses, which keep WooCommerce's own
 * wording.
 */
function omc_pdp_in_store_context() {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return false;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return false;
	}

	return true;
}

/**
 * Permalink of a page by slug, falling back to the plain path so the link still
 * works before the page exists.
 *
 * @param string $slug Page slug.
 */
function omc_pdp_page_url( $slug ) {
	$page = get_page_by_path( $slug );

	return $page && 'publish' === $page->post_status ? get_permalink( $page ) : home_url( '/' . trim( $slug, '/' ) . '/' );
}

/**
 * Inline line icons (24×24, stroked with currentColor).
 *
 * @param string $name Icon key.
 */
function omc_pdp_icon( $name ) {
	$icons = [
		'ruler'  => '<path d="M3.8 14.6 14.6 3.8a1.2 1.2 0 0 1 1.7 0l3.9 3.9a1.2 1.2 0 0 1 0 1.7L9.4 20.2a1.2 1.2 0 0 1-1.7 0l-3.9-3.9a1.2 1.2 0 0 1 0-1.7Z"/><path d="m8 10.4 1.7 1.7M10.9 7.5l1.7 1.7M13.8 4.6l1.7 1.7M5.1 13.3l1.7 1.7"/>',
		'lock'   => '<rect x="4" y="10.5" width="16" height="10.5" rx="2.2"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>',
		'return' => '<path d="M3.2 12a8.8 8.8 0 1 0 2.9-6.5"/><path d="M3.2 4.4v4.8H8"/>',
		'pin'    => '<path d="M12 21.2c3.6-4 5.4-7 5.4-9.2a5.4 5.4 0 1 0-10.8 0c0 2.2 1.8 5.2 5.4 9.2Z"/><circle cx="12" cy="11.8" r="2"/>',
		'mail'   => '<rect x="3" y="5.5" width="18" height="13" rx="2.2"/><path d="m3.6 7 8.4 5.9L20.4 7"/>',
		'close'  => '<path d="m6.5 6.5 11 11M17.5 6.5l-11 11"/>',
		'arrow'  => '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
		'camera' => '<path d="M4 8.4h3.1l1.4-2.2h7l1.4 2.2H20a1.4 1.4 0 0 1 1.4 1.4v8A1.4 1.4 0 0 1 20 19.4H4a1.4 1.4 0 0 1-1.4-1.4v-8A1.4 1.4 0 0 1 4 8.4Z"/><circle cx="12" cy="13.5" r="3.3"/>',
	];

	$path = isset( $icons[ $name ] ) ? $icons[ $name ] : $icons['arrow'];

	return '<svg class="omc-pdp-icon omc-pdp-icon--' . esc_attr( $name ) . '" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
}

/* ─────────────────────────── 1. Size guide ─────────────────────────── */

/**
 * The size chart, as data, so the owner can change it from a child plugin or
 * functions.php without touching markup.
 *
 * Body measurements (not garment measurements) for a general women's chart.
 * Each measurement carries both units; the modal swaps them with one attribute.
 */
function omc_pdp_size_chart() {
	$chart = [
		'title'        => __( 'Size guide', 'moderno-child' ),
		'intro'        => __( 'Body measurements, not garment measurements. Measure yourself, then match the closest row.', 'moderno-child' ),
		'default_unit' => 'in',
		'units'        => [
			'in' => __( 'inches', 'moderno-child' ),
			'cm' => __( 'centimetres', 'moderno-child' ),
		],
		'columns'      => [
			'size'  => __( 'Size', 'moderno-child' ),
			'us'    => __( 'US', 'moderno-child' ),
			'bust'  => __( 'Bust', 'moderno-child' ),
			'waist' => __( 'Waist', 'moderno-child' ),
			'hips'  => __( 'Hips', 'moderno-child' ),
		],
		'rows'         => [
			[
				'size'  => 'XS',
				'us'    => '0–2',
				'bust'  => [ 'in' => '32–33', 'cm' => '81–84' ],
				'waist' => [ 'in' => '24–25', 'cm' => '61–64' ],
				'hips'  => [ 'in' => '34–35', 'cm' => '86–89' ],
			],
			[
				'size'  => 'S',
				'us'    => '4–6',
				'bust'  => [ 'in' => '34–35', 'cm' => '86–89' ],
				'waist' => [ 'in' => '26–27', 'cm' => '66–69' ],
				'hips'  => [ 'in' => '36–37', 'cm' => '91–94' ],
			],
			[
				'size'  => 'M',
				'us'    => '8–10',
				'bust'  => [ 'in' => '36–37', 'cm' => '91–94' ],
				'waist' => [ 'in' => '28–29', 'cm' => '71–74' ],
				'hips'  => [ 'in' => '38–39', 'cm' => '97–99' ],
			],
			[
				'size'  => 'L',
				'us'    => '12–14',
				'bust'  => [ 'in' => '38.5–40', 'cm' => '98–102' ],
				'waist' => [ 'in' => '30.5–32', 'cm' => '77–81' ],
				'hips'  => [ 'in' => '40.5–42', 'cm' => '103–107' ],
			],
			[
				'size'  => 'XL',
				'us'    => '16–18',
				'bust'  => [ 'in' => '41.5–43', 'cm' => '105–109' ],
				'waist' => [ 'in' => '33.5–35', 'cm' => '85–89' ],
				'hips'  => [ 'in' => '43.5–45', 'cm' => '110–114' ],
			],
		],
		'note'         => __( 'Korean and Thai sizing runs small. We list garment measurements on each piece, so read those first — and when you land between two sizes, size up.', 'moderno-child' ),
		'measure_head' => __( 'How to measure', 'moderno-child' ),
		'measure'      => [
			[
				'label' => __( 'Bust', 'moderno-child' ),
				'text'  => __( 'Wrap the tape around the fullest part, arms down, tape level all the way round.', 'moderno-child' ),
			],
			[
				'label' => __( 'Waist', 'moderno-child' ),
				'text'  => __( 'Measure the narrowest part of your middle, usually just above the navel.', 'moderno-child' ),
			],
			[
				'label' => __( 'Hips', 'moderno-child' ),
				'text'  => __( 'Stand with your feet together and measure around the fullest part.', 'moderno-child' ),
			],
			[
				'label' => __( 'Tape', 'moderno-child' ),
				'text'  => __( 'Snug, never tight. Measure over underwear or one thin layer.', 'moderno-child' ),
			],
		],
		'link'         => [
			'text' => __( 'Read the full size guide', 'moderno-child' ),
			'url'  => omc_pdp_page_url( 'size-guide' ),
		],
	];

	/**
	 * Filter the size chart shown in the product-page modal.
	 *
	 * @param array $chart Chart data.
	 */
	$chart = apply_filters( 'omc_size_chart', $chart );

	return is_array( $chart ) ? $chart : [];
}

/**
 * The "Size guide" trigger. `$move` marks the button that omc-pdp.js may move
 * into the label cell of the Size row.
 *
 * @param bool $move Whether JS should reposition it.
 */
function omc_pdp_size_guide_trigger( $move = false ) {
	$chart = omc_pdp_size_chart();
	if ( empty( $chart['rows'] ) ) {
		return;
	}
	?>
	<button type="button" class="omc-sg-open<?php echo $move ? ' omc-sg-open--movable' : ''; ?>"
			data-omc-sg-open<?php echo $move ? ' data-omc-sg-move' : ''; ?>
			aria-haspopup="dialog" aria-controls="omc-size-guide">
		<?php echo omc_pdp_icon( 'ruler' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — static markup. ?>
		<span><?php echo esc_html( $chart['title'] ); ?></span>
	</button>
	<?php
}

/**
 * Variable products: print the trigger inside the form, just above the
 * variations table. omc-pdp.js then moves it beside the Size row.
 */
function omc_pdp_size_guide_variable() {
	if ( ! omc_pdp_is_product_page() ) {
		return;
	}
	echo '<div class="omc-sg-slot omc-sg-slot--variable">';
	omc_pdp_size_guide_trigger( true );
	echo '</div>';
}
add_action( 'woocommerce_before_variations_form', 'omc_pdp_size_guide_variable', 5 );

/**
 * Simple products: print the trigger above the add-to-cart button. The parent
 * theme opens its `.c-product__atc-row-1` wrapper on the same hook at
 * PHP_INT_MAX, so priority 5 lands above the row.
 */
function omc_pdp_size_guide_simple() {
	global $product;

	if ( ! omc_pdp_is_product_page() || ! $product instanceof WC_Product || ! $product->is_type( 'simple' ) ) {
		return;
	}
	echo '<div class="omc-sg-slot omc-sg-slot--simple">';
	omc_pdp_size_guide_trigger( false );
	echo '</div>';
}
add_action( 'woocommerce_before_add_to_cart_button', 'omc_pdp_size_guide_simple', 5 );

/**
 * One measurement cell: both units, one of them hidden by CSS.
 *
 * @param array|string $value Either a unit map or a plain string.
 */
function omc_pdp_size_cell( $value ) {
	if ( ! is_array( $value ) ) {
		return esc_html( (string) $value );
	}

	$out = '';
	foreach ( $value as $unit => $text ) {
		$out .= '<span data-omc-unit="' . esc_attr( $unit ) . '">' . esc_html( $text ) . '</span>';
	}

	return $out;
}

/**
 * The modal itself, printed once in the footer of a product page.
 */
function omc_pdp_size_guide_modal() {
	if ( ! omc_pdp_is_product_page() ) {
		return;
	}

	$chart = omc_pdp_size_chart();
	if ( empty( $chart['rows'] ) || empty( $chart['columns'] ) ) {
		return;
	}

	$units = ! empty( $chart['units'] ) && is_array( $chart['units'] ) ? $chart['units'] : [ 'in' => 'inches', 'cm' => 'centimetres' ];
	$unit  = isset( $chart['default_unit'] ) && isset( $units[ $chart['default_unit'] ] ) ? $chart['default_unit'] : (string) array_key_first( $units );
	?>
	<div class="omc-sg" id="omc-size-guide" data-unit="<?php echo esc_attr( $unit ); ?>" hidden>
		<div class="omc-sg__overlay" data-omc-sg-close></div>
		<div class="omc-sg__dialog" role="dialog" aria-modal="true" aria-labelledby="omc-sg-title" tabindex="-1">
			<button type="button" class="omc-sg__close" data-omc-sg-close aria-label="<?php esc_attr_e( 'Close the size guide', 'moderno-child' ); ?>">
				<?php echo omc_pdp_icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>

			<h2 class="omc-sg__title" id="omc-sg-title"><?php echo esc_html( $chart['title'] ); ?></h2>

			<?php if ( ! empty( $chart['intro'] ) ) : ?>
				<p class="omc-sg__intro"><?php echo esc_html( $chart['intro'] ); ?></p>
			<?php endif; ?>

			<div class="omc-sg__units" role="group" aria-label="<?php esc_attr_e( 'Measurement units', 'moderno-child' ); ?>">
				<?php foreach ( $units as $key => $label ) : ?>
					<button type="button" class="omc-sg__unit<?php echo $key === $unit ? ' is-active' : ''; ?>"
							data-omc-sg-unit="<?php echo esc_attr( $key ); ?>"
							aria-pressed="<?php echo $key === $unit ? 'true' : 'false'; ?>">
						<span class="omc-sg__unit-short"><?php echo esc_html( $key ); ?></span>
						<span class="screen-reader-text"><?php echo esc_html( $label ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>

			<div class="omc-sg__table-wrap">
				<table class="omc-sg__table">
					<thead>
					<tr>
						<?php foreach ( $chart['columns'] as $label ) : ?>
							<th scope="col"><?php echo esc_html( $label ); ?></th>
						<?php endforeach; ?>
					</tr>
					</thead>
					<tbody>
					<?php foreach ( $chart['rows'] as $row ) : ?>
						<tr>
							<?php $first = true; ?>
							<?php foreach ( $chart['columns'] as $key => $label ) : ?>
								<?php if ( $first ) : ?>
									<th scope="row"><?php echo omc_pdp_size_cell( isset( $row[ $key ] ) ? $row[ $key ] : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped in omc_pdp_size_cell(). ?></th>
									<?php $first = false; ?>
								<?php else : ?>
									<td><?php echo omc_pdp_size_cell( isset( $row[ $key ] ) ? $row[ $key ] : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								<?php endif; ?>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ( ! empty( $chart['note'] ) ) : ?>
				<p class="omc-sg__note"><?php echo esc_html( $chart['note'] ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $chart['measure'] ) ) : ?>
				<h3 class="omc-sg__sub"><?php echo esc_html( isset( $chart['measure_head'] ) ? $chart['measure_head'] : __( 'How to measure', 'moderno-child' ) ); ?></h3>
				<ol class="omc-sg__steps">
					<?php foreach ( $chart['measure'] as $step ) : ?>
						<li>
							<?php if ( ! empty( $step['label'] ) ) : ?>
								<strong><?php echo esc_html( $step['label'] ); ?></strong><span class="omc-sg__steps-sep" aria-hidden="true"> — </span>
							<?php endif; ?>
							<?php echo esc_html( isset( $step['text'] ) ? $step['text'] : '' ); ?>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>

			<?php if ( ! empty( $chart['link']['url'] ) && ! empty( $chart['link']['text'] ) ) : ?>
				<a class="omc-sg__link" href="<?php echo esc_url( $chart['link']['url'] ); ?>">
					<span><?php echo esc_html( $chart['link']['text'] ); ?></span>
					<?php echo omc_pdp_icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</a>
			<?php endif; ?>
		</div>
	</div>
	<?php
}
add_action( 'wp_footer', 'omc_pdp_size_guide_modal', 20 );

/* ─────────────────────────── 2. Scarcity ─────────────────────────── */

/**
 * Below how many items in stock the "Only N left" line appears.
 */
function omc_pdp_scarcity_threshold() {
	return (int) apply_filters( 'omc_scarcity_threshold', 5 );
}

/**
 * The real number left, or null when there is nothing honest to show
 * (stock not managed, backorders on, empty or plentiful stock).
 *
 * @param WC_Product $product Product or variation.
 *
 * @return int|null
 */
function omc_pdp_scarcity_qty( $product ) {
	if ( ! $product instanceof WC_Product || ! $product->managing_stock() ) {
		return null;
	}
	if ( $product->backorders_allowed() || ! $product->is_in_stock() ) {
		return null;
	}

	$qty = $product->get_stock_quantity();
	if ( null === $qty ) {
		return null;
	}

	$qty = (int) $qty;

	return ( $qty > 0 && $qty <= omc_pdp_scarcity_threshold() ) ? $qty : null;
}

/**
 * The scarcity sentence as plain text.
 *
 * The availability *text* has to stay text: the parent theme prints it through
 * esc_html() on the wishlist, so markup there would show up as raw tags. The
 * dot is added later, only where HTML is expected.
 *
 * @param int  $qty       Items left.
 * @param bool $this_size Append "in this size".
 */
function omc_pdp_scarcity_text( $qty, $this_size = false ) {
	$qty = (int) $qty;

	return $this_size
		/* translators: %d: number of items left in the chosen size. */
		? sprintf( _n( 'Only %d left in this size', 'Only %d left in this size', $qty, 'moderno-child' ), $qty )
		/* translators: %d: number of items left. */
		: sprintf( _n( 'Only %d left', 'Only %d left', $qty, 'moderno-child' ), $qty );
}

/**
 * The same sentence with the pulsing dot. `wp_kses_post()` keeps
 * span/class/aria-hidden, so this survives WooCommerce's stock template.
 *
 * @param int  $qty       Items left.
 * @param bool $this_size Append "in this size".
 */
function omc_pdp_scarcity_html( $qty, $this_size = false ) {
	return '<span class="omc-scarcity"><span class="omc-scarcity__dot" aria-hidden="true"></span><span class="omc-scarcity__text">'
		. esc_html( omc_pdp_scarcity_text( $qty, $this_size ) )
		. '</span></span>';
}

/**
 * Simple (and other non-variation) products: replace the stock wording.
 *
 * @param string     $text    Availability text.
 * @param WC_Product $product Product.
 */
function omc_pdp_availability_text( $text, $product ) {
	if ( ! $product instanceof WC_Product || $product->is_type( 'variation' ) || ! omc_pdp_in_store_context() ) {
		return $text;
	}

	$qty = omc_pdp_scarcity_qty( $product );

	return null === $qty ? $text : omc_pdp_scarcity_text( $qty );
}
add_filter( 'woocommerce_get_availability_text', 'omc_pdp_availability_text', 20, 2 );

/**
 * Dress the plain sentence with the dot wherever the stock line is built as
 * HTML — WooCommerce's stock template and the theme's wishlist column both run
 * this filter, so each keeps its own wrapper.
 *
 * @param string     $html    Stock markup.
 * @param WC_Product $product Product.
 */
function omc_pdp_stock_html( $html, $product ) {
	if ( ! is_string( $html ) || '' === $html || ! $product instanceof WC_Product || ! omc_pdp_in_store_context() ) {
		return $html;
	}

	$qty = omc_pdp_scarcity_qty( $product );
	if ( null === $qty ) {
		return $html;
	}

	$text = omc_pdp_scarcity_text( $qty );

	foreach ( array_unique( [ esc_html( $text ), $text ] ) as $needle ) {
		if ( '' !== $needle && false !== strpos( $html, $needle ) ) {
			return str_replace( $needle, omc_pdp_scarcity_html( $qty ), $html );
		}
	}

	return $html;
}
add_filter( 'woocommerce_get_stock_html', 'omc_pdp_stock_html', 20, 2 );

/**
 * Variations: replace the availability HTML WooCommerce ships to the front end.
 *
 * "in this size" is only added when the variation owns its stock (a variation
 * inheriting the parent's stock shares that number with every other size) and
 * the variation really varies by a size attribute.
 *
 * @param array                $data      Variation data.
 * @param WC_Product_Variable  $parent    Parent product.
 * @param WC_Product_Variation $variation Variation.
 */
function omc_pdp_variation_scarcity( $data, $parent, $variation ) {
	$qty = omc_pdp_scarcity_qty( $variation );
	if ( null === $qty ) {
		return $data;
	}

	$own_stock = ( true === $variation->managing_stock() );
	$by_size   = false;
	foreach ( array_keys( (array) $variation->get_attributes() ) as $attribute ) {
		if ( preg_match( '~(^|_|-)sizes?$~i', (string) $attribute ) ) {
			$by_size = true;
			break;
		}
	}

	$this_size = ( $own_stock && $by_size && $qty <= 2 );

	$data['availability_html'] = '<p class="stock in-stock omc-scarcity-line">' . omc_pdp_scarcity_html( $qty, $this_size ) . '</p>';

	return $data;
}
add_filter( 'woocommerce_available_variation', 'omc_pdp_variation_scarcity', 20, 3 );

/* ───────────────────────── 3. Trust badges ───────────────────────── */

/**
 * The four lines under the add-to-cart form. Everything is filterable so the
 * owner can change the wording or the return window from one place.
 */
function omc_pdp_trust_badges() {
	$badges = [
		[
			'icon' => 'lock',
			'text' => __( 'Secure checkout', 'moderno-child' ),
			'url'  => '',
		],
		[
			'icon' => 'return',
			'text' => __( '7-day returns for store credit', 'moderno-child' ),
			'url'  => omc_pdp_page_url( 'refund_returns' ),
		],
		[
			'icon' => 'pin',
			'text' => __( 'Ships from Houston, Texas', 'moderno-child' ),
			'url'  => '',
		],
		[
			'icon' => 'mail',
			'text' => __( 'Questions? hello@oopsmineco.com', 'moderno-child' ),
			'url'  => 'mailto:hello@oopsmineco.com',
		],
	];

	/**
	 * Filter the product-page trust badges.
	 *
	 * @param array $badges Each: icon, text, url.
	 */
	$badges = apply_filters( 'omc_trust_badges', $badges );

	return is_array( $badges ) ? $badges : [];
}

/**
 * Print the badges under the add-to-cart form.
 */
function omc_pdp_render_trust_badges() {
	if ( ! omc_pdp_is_product_page() ) {
		return;
	}

	$badges = omc_pdp_trust_badges();
	if ( ! $badges ) {
		return;
	}
	?>
	<ul class="omc-trust">
		<?php foreach ( $badges as $badge ) : ?>
			<?php
			$text = isset( $badge['text'] ) ? (string) $badge['text'] : '';
			if ( '' === $text ) {
				continue;
			}
			$icon = isset( $badge['icon'] ) ? (string) $badge['icon'] : 'lock';
			$url  = isset( $badge['url'] ) ? (string) $badge['url'] : '';
			?>
			<li class="omc-trust__item">
				<?php if ( $url ) : ?>
					<a class="omc-trust__link" href="<?php echo esc_url( $url ); ?>">
						<?php echo omc_pdp_icon( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span><?php echo esc_html( $text ); ?></span>
					</a>
				<?php else : ?>
					<span class="omc-trust__link">
						<?php echo omc_pdp_icon( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span><?php echo esc_html( $text ); ?></span>
					</span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php
}
add_action( 'woocommerce_after_add_to_cart_form', 'omc_pdp_render_trust_badges', 20 );

/* ──────────────────────── 4. Review photos ──────────────────────── */

// Comment meta key holding the attachment ids of a review.
defined( 'OMC_REVIEW_PHOTOS_META' ) || define( 'OMC_REVIEW_PHOTOS_META', 'omc_review_photos' );

// Attachment meta key pointing back at the review, used when cleaning up.
defined( 'OMC_REVIEW_PHOTO_OWNER' ) || define( 'OMC_REVIEW_PHOTO_OWNER', '_omc_review_comment' );

/**
 * Photo uploads on/off.
 */
function omc_pdp_review_photos_enabled() {
	return (bool) apply_filters( 'omc_review_photos_enabled', true );
}

/**
 * How many photos one review may carry.
 */
function omc_pdp_review_photo_max() {
	return max( 1, (int) apply_filters( 'omc_review_photo_max', 3 ) );
}

/**
 * Byte ceiling for one photo.
 */
function omc_pdp_review_photo_max_size() {
	return max( 1, (int) apply_filters( 'omc_review_photo_max_size', 4 * MB_IN_BYTES ) );
}

/**
 * Image types accepted from shoppers.
 */
function omc_pdp_review_photo_mimes() {
	$mimes = [
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
	];

	return (array) apply_filters( 'omc_review_photo_mimes', $mimes );
}

/**
 * Add the file input to WooCommerce's review form.
 *
 * @param array $args comment_form() arguments.
 */
function omc_pdp_review_form_args( $args ) {
	if ( ! omc_pdp_review_photos_enabled() ) {
		return $args;
	}

	$max    = omc_pdp_review_photo_max();
	$mb     = round( omc_pdp_review_photo_max_size() / MB_IN_BYTES );
	$accept = implode( ',', array_values( array_unique( omc_pdp_review_photo_mimes() ) ) );

	$hint = sprintf(
		/* translators: 1: number of photos, 2: size in megabytes. */
		_n( 'Up to %1$d photo, %2$d MB each. JPG, PNG or WebP.', 'Up to %1$d photos, %2$d MB each. JPG, PNG or WebP.', $max, 'moderno-child' ),
		$max,
		$mb
	);

	$field  = '<p class="comment-form-omc-photos omc-review-upload">';
	$field .= '<label for="omc_review_photos">' . esc_html__( 'Show it on you (optional)', 'moderno-child' ) . '</label>';
	$field .= '<input type="file" id="omc_review_photos" name="omc_review_photos[]" class="omc-review-upload__input"'
			. ' accept="' . esc_attr( $accept ) . '" multiple'
			. ' data-omc-max="' . esc_attr( $max ) . '"'
			. ' data-omc-max-size="' . esc_attr( omc_pdp_review_photo_max_size() ) . '"'
			. ' data-omc-too-many="' . esc_attr( sprintf( /* translators: %d: number of photos. */ _n( 'Pick %d photo at most.', 'Pick %d photos at most.', $max, 'moderno-child' ), $max ) ) . '"'
			. ' data-omc-too-big="' . esc_attr( sprintf( /* translators: %d: size in megabytes. */ __( 'Each photo has to stay under %d MB.', 'moderno-child' ), $mb ) ) . '">';
	$field .= '<span class="omc-review-upload__hint">' . esc_html( $hint ) . '</span>';
	$field .= '<span class="omc-review-upload__list" data-omc-photo-list aria-live="polite"></span>';
	$field .= wp_nonce_field( 'omc_review_photos', 'omc_review_photos_nonce', false, false );
	$field .= '</p>';

	$args['comment_field'] = ( isset( $args['comment_field'] ) ? $args['comment_field'] : '' ) . $field;

	return $args;
}
add_filter( 'woocommerce_product_review_comment_form_args', 'omc_pdp_review_form_args' );

/**
 * Turn PHP's grouped $_FILES entry into a list of single-file arrays.
 *
 * @param array $files $_FILES['omc_review_photos'].
 */
function omc_pdp_normalize_files( $files ) {
	if ( empty( $files['name'] ) || ! is_array( $files['name'] ) ) {
		return [];
	}

	$out = [];
	foreach ( array_keys( $files['name'] ) as $i ) {
		$out[] = [
			'name'     => isset( $files['name'][ $i ] ) ? $files['name'][ $i ] : '',
			'type'     => isset( $files['type'][ $i ] ) ? $files['type'][ $i ] : '',
			'tmp_name' => isset( $files['tmp_name'][ $i ] ) ? $files['tmp_name'][ $i ] : '',
			'error'    => isset( $files['error'][ $i ] ) ? (int) $files['error'][ $i ] : UPLOAD_ERR_NO_FILE,
			'size'     => isset( $files['size'][ $i ] ) ? (int) $files['size'][ $i ] : 0,
		];
	}

	return $out;
}

/**
 * Store the uploaded photos when a product review is posted.
 *
 * @param int        $comment_id  New comment id.
 * @param int|string $approved    Approval flag.
 * @param array      $commentdata Comment data.
 */
function omc_pdp_save_review_photos( $comment_id, $approved, $commentdata = [] ) {
	unset( $approved );

	if ( ! omc_pdp_review_photos_enabled() || empty( $_FILES['omc_review_photos'] ) ) {
		return;
	}

	$post_id = isset( $commentdata['comment_post_ID'] ) ? (int) $commentdata['comment_post_ID'] : 0;
	if ( ! $post_id || 'product' !== get_post_type( $post_id ) ) {
		return;
	}

	$nonce = isset( $_POST['omc_review_photos_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['omc_review_photos_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'omc_review_photos' ) ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$mimes     = omc_pdp_review_photo_mimes();
	$max_size  = omc_pdp_review_photo_max_size();
	$max_files = omc_pdp_review_photo_max();
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash — $_FILES paths are validated below.
	$files     = omc_pdp_normalize_files( $_FILES['omc_review_photos'] );
	$original  = $_FILES;
	$ids       = [];

	foreach ( $files as $file ) {
		if ( count( $ids ) >= $max_files ) {
			break;
		}
		if ( UPLOAD_ERR_OK !== $file['error'] || $file['size'] <= 0 || $file['size'] > $max_size ) {
			continue;
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			continue;
		}

		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $mimes );
		if ( empty( $check['type'] ) || ! in_array( $check['type'], array_values( $mimes ), true ) ) {
			continue;
		}
		// A real raster image, not something renamed to .jpg.
		$size = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( empty( $size[0] ) || empty( $size[1] ) ) {
			continue;
		}

		$_FILES = [ 'omc_review_photo' => $file ];

		$attachment_id = media_handle_upload(
			'omc_review_photo',
			$post_id,
			[
				'post_title'   => sprintf(
					/* translators: %s: product name. */
					__( 'Review photo — %s', 'moderno-child' ),
					get_the_title( $post_id )
				),
				'post_content' => '',
			],
			[
				'test_form' => false,
				'mimes'     => $mimes,
			]
		);

		if ( is_wp_error( $attachment_id ) ) {
			continue;
		}

		update_post_meta( $attachment_id, OMC_REVIEW_PHOTO_OWNER, (int) $comment_id );
		$ids[] = (int) $attachment_id;
	}

	$_FILES = $original;

	if ( $ids ) {
		update_comment_meta( $comment_id, OMC_REVIEW_PHOTOS_META, $ids );
	}
}
add_action( 'comment_post', 'omc_pdp_save_review_photos', 10, 3 );

/**
 * Attachment ids of one review.
 *
 * @param int $comment_id Comment id.
 */
function omc_pdp_review_photo_ids( $comment_id ) {
	$ids = get_comment_meta( (int) $comment_id, OMC_REVIEW_PHOTOS_META, true );
	if ( ! is_array( $ids ) ) {
		return [];
	}

	return array_values( array_filter( array_map( 'absint', $ids ) ) );
}

/**
 * Thumbnails under the review text, each linking to the full image.
 *
 * @param WP_Comment $comment Review.
 */
function omc_pdp_render_review_photos( $comment ) {
	if ( ! $comment instanceof WP_Comment ) {
		return;
	}

	$ids = omc_pdp_review_photo_ids( $comment->comment_ID );
	if ( ! $ids ) {
		return;
	}

	$out = '';
	foreach ( $ids as $id ) {
		$full = wp_get_attachment_image_url( $id, 'large' );
		$img  = wp_get_attachment_image(
			$id,
			'thumbnail',
			false,
			[
				'class'   => 'omc-review-photos__img',
				'loading' => 'lazy',
				'alt'     => __( 'Photo from this review', 'moderno-child' ),
			]
		);
		if ( ! $full || ! $img ) {
			continue;
		}
		$out .= '<a class="omc-review-photos__item" href="' . esc_url( $full ) . '" target="_blank" rel="noopener nofollow">' . $img . '</a>';
	}

	if ( ! $out ) {
		return;
	}

	echo '<div class="omc-review-photos">' . wp_kses_post( $out ) . '</div>';
}
add_action( 'woocommerce_review_after_comment_text', 'omc_pdp_render_review_photos' );

/**
 * Drop the attachments when the review goes.
 *
 * @param int $comment_id Comment id.
 */
function omc_pdp_delete_review_photos( $comment_id ) {
	$ids = omc_pdp_review_photo_ids( $comment_id );
	if ( ! $ids ) {
		return;
	}

	foreach ( $ids as $id ) {
		if ( (int) get_post_meta( $id, OMC_REVIEW_PHOTO_OWNER, true ) === (int) $comment_id ) {
			wp_delete_attachment( $id, true );
		}
	}

	delete_comment_meta( $comment_id, OMC_REVIEW_PHOTOS_META );
}
add_action( 'delete_comment', 'omc_pdp_delete_review_photos' );

/* ─────────────────────────── 5. Polish ─────────────────────────── */

/**
 * Default heading above the related products. Priority 5 keeps the theme's own
 * Customizer value (`related_product_header`, filtered at 100) authoritative.
 *
 * @param string $heading Heading text.
 */
function omc_pdp_related_heading( $heading ) {
	$text = apply_filters( 'omc_related_heading', __( 'You might also like', 'moderno-child' ) );

	return $text ? $text : $heading;
}
add_filter( 'woocommerce_product_related_products_heading', 'omc_pdp_related_heading', 5 );
