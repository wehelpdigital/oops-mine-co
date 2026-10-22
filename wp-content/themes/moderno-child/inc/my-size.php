<?php
/**
 * "Only show my size" on the shop and category grids.
 *
 * The WHD plugin already remembers the size a shopper chose last (cookie `whd_fit`, e.g.
 * {"pa_size":"m"}), so a grid can offer to hide everything not in stock in it. The switch is drawn by
 * the browser from that cookie — a cached page must never carry one visitor's size to the next — and
 * turning it on is an ordinary link: ?my_size=m. With that in the address, the grid keeps only pieces
 * that have that size in stock, and the switch is printed already on, with the way back.
 *
 * Sizes are written several ways in this shop (M, M (8–10), S/M (4–10), 8, 10…), so one size is
 * matched to the others the catalogue itself says are the same: a term that names a letter and a
 * range ("m-8-10") makes M and 8 and 10 the same size. One-size pieces fit everyone and stay in.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

const OMC_MY_SIZE_VAR = 'my_size';
const OMC_MY_SIZE_TAX = 'pa_size';

/** Letters and numbers a size slug names: "s-m-4-10" → [ [ s, m ], [ 4, 6, 8, 10 ] ]. */
function omc_size_parts( $slug ) {
	$letters = [];
	$numbers = [];
	foreach ( explode( '-', strtolower( (string) $slug ) ) as $t ) {
		if ( preg_match( '/^(\d?x{0,3}[sml]|\dxl|xxs|xxl)$/', $t ) ) {
			$letters[] = $t;
		} elseif ( preg_match( '/^\d{1,3}$/', $t ) ) {
			$numbers[] = (int) $t;
		}
	}
	// "8-10" is a range: every even size between, the way the numbers on these labels run.
	if ( 2 === count( $numbers ) && $numbers[1] > $numbers[0] && $numbers[1] - $numbers[0] <= 10 ) {
		$numbers = range( $numbers[0], $numbers[1], ( $numbers[0] % 2 || $numbers[1] % 2 ) ? 1 : 2 );
	}
	return [ array_values( array_unique( $letters ) ), array_values( array_unique( $numbers ) ) ];
}

/** Every pa_size slug that means the same size as $slug, one-size included. */
function omc_size_equivalents( $slug ) {
	$slug  = sanitize_title( (string) $slug );
	$terms = get_terms( [ 'taxonomy' => OMC_MY_SIZE_TAX, 'hide_empty' => false, 'fields' => 'id=>slug' ] );
	if ( is_wp_error( $terms ) || ! in_array( $slug, (array) $terms, true ) ) {
		return [];
	}
	list( $letters, $numbers ) = omc_size_parts( $slug );

	// What the catalogue says each single letter means in numbers ("m-8-10": M is 8 and 10), and back.
	foreach ( $terms as $other ) {
		list( $l, $n ) = omc_size_parts( $other );
		if ( 1 === count( $l ) && $n ) {
			if ( in_array( $l[0], $letters, true ) ) {
				$numbers = array_merge( $numbers, $n );
			} elseif ( ! $letters && array_intersect( $n, $numbers ) ) {
				$letters[] = $l[0];
			}
		}
	}

	$out = [ $slug ];
	foreach ( $terms as $other ) {
		list( $l, $n ) = omc_size_parts( $other );
		if ( 'one-size' === $other || array_intersect( $l, $letters ) || ( ! $l && array_intersect( $n, $numbers ) ) ) {
			$out[] = $other;
		}
	}
	return array_values( array_unique( $out ) );
}

/** The size asked for in the address, if it is one the shop has. */
function omc_my_size() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a filter in a link
	$slug = isset( $_GET[ OMC_MY_SIZE_VAR ] ) ? sanitize_title( wp_unslash( $_GET[ OMC_MY_SIZE_VAR ] ) ) : '';
	if ( '' === $slug || ! taxonomy_exists( OMC_MY_SIZE_TAX ) ) {
		return null;
	}
	$term = get_term_by( 'slug', $slug, OMC_MY_SIZE_TAX );
	return $term && ! is_wp_error( $term ) && ! preg_match( '/^one-?size/', $term->slug ) ? $term : null;
}

/**
 * Products with any of these sizes in stock: variations with that size, variations open to any
 * size on a product that offers it, and simple products filed under it.
 *
 * @return int[]
 */
function omc_products_in_size( array $slugs ) {
	global $wpdb;
	if ( ! $slugs ) {
		return [];
	}
	$in    = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
	$stock = "('instock','onbackorder')";

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is placeholders only
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT v.post_parent FROM {$wpdb->posts} v
		INNER JOIN {$wpdb->postmeta} a ON a.post_id = v.ID AND a.meta_key = 'attribute_pa_size'
		INNER JOIN {$wpdb->postmeta} s ON s.post_id = v.ID AND s.meta_key = '_stock_status' AND s.meta_value IN {$stock}
		WHERE v.post_type = 'product_variation' AND v.post_status = 'publish' AND a.meta_value IN ({$in})",
		$slugs
	) );
	$any = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT v.post_parent FROM {$wpdb->posts} v
		INNER JOIN {$wpdb->postmeta} a ON a.post_id = v.ID AND a.meta_key = 'attribute_pa_size' AND a.meta_value = ''
		INNER JOIN {$wpdb->postmeta} s ON s.post_id = v.ID AND s.meta_key = '_stock_status' AND s.meta_value IN {$stock}
		INNER JOIN {$wpdb->term_relationships} r ON r.object_id = v.post_parent
		INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id AND tt.taxonomy = 'pa_size'
		INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
		WHERE v.post_type = 'product_variation' AND v.post_status = 'publish' AND t.slug IN ({$in})",
		$slugs
	) );
	$simple = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
		INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_stock_status' AND s.meta_value IN {$stock}
		INNER JOIN {$wpdb->term_relationships} r ON r.object_id = p.ID
		INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id AND tt.taxonomy = 'pa_size'
		INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
		INNER JOIN {$wpdb->term_relationships} r2 ON r2.object_id = p.ID
		INNER JOIN {$wpdb->term_taxonomy} tt2 ON tt2.term_taxonomy_id = r2.term_taxonomy_id AND tt2.taxonomy = 'product_type'
		INNER JOIN {$wpdb->terms} t2 ON t2.term_id = tt2.term_id AND t2.slug = 'simple'
		WHERE p.post_type = 'product' AND p.post_status = 'publish' AND t.slug IN ({$in})",
		$slugs
	) );
	// phpcs:enable
	return array_values( array_unique( array_map( 'intval', array_merge( $ids, $any, $simple ) ) ) );
}

/* Keep the grid to those products while ?my_size= is in the address. */
add_action( 'woocommerce_product_query', static function ( $q ) {
	$term = omc_my_size();
	if ( ! $term || is_admin() ) {
		return;
	}
	$ids  = omc_products_in_size( omc_size_equivalents( $term->slug ) );
	$keep = (array) $q->get( 'post__in' );
	if ( $keep ) {
		$ids = array_intersect( $ids, array_map( 'intval', $keep ) );
	}
	$q->set( 'post__in', $ids ? array_values( $ids ) : [ 0 ] );
} );

/** The switch above the grid: printed on when the filter is on, otherwise drawn by the browser from the cookie. */
function omc_my_size_switch() {
	if ( ! function_exists( 'is_shop' ) || ! ( is_shop() || is_product_taxonomy() ) || ! taxonomy_exists( OMC_MY_SIZE_TAX ) ) {
		return;
	}
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	$term = omc_my_size();
	if ( $term ) {
		?>
		<div class="omc-mysize is-on">
			<a class="omc-mysize__switch" href="<?php echo esc_url( remove_query_arg( [ OMC_MY_SIZE_VAR, 'paged' ] ) ); ?>" role="switch" aria-checked="true">
				<span class="omc-mysize__track" aria-hidden="true"><span class="omc-mysize__knob"></span></span>
				<span class="omc-mysize__text">
					<?php
					/* translators: %s: a size, e.g. M */
					echo wp_kses( sprintf( __( 'Only pieces in stock in <b>%s</b>', 'moderno-child' ), esc_html( $term->name ) ), [ 'b' => [] ] );
					?>
				</span>
			</a>
			<span class="omc-mysize__note"><?php esc_html_e( 'One-size pieces stay in. Tap to see everything.', 'moderno-child' ); ?></span>
		</div>
		<?php
		return;
	}
	$names = get_terms( [ 'taxonomy' => OMC_MY_SIZE_TAX, 'hide_empty' => false, 'fields' => 'id=>name' ] );
	$slugs = get_terms( [ 'taxonomy' => OMC_MY_SIZE_TAX, 'hide_empty' => false, 'fields' => 'id=>slug' ] );
	$map   = [];
	foreach ( (array) $slugs as $id => $slug ) {
		$map[ $slug ] = html_entity_decode( (string) ( $names[ $id ] ?? $slug ), ENT_QUOTES, 'UTF-8' );
	}
	?>
	<div class="omc-mysize" data-omc-mysize
		data-var="<?php echo esc_attr( OMC_MY_SIZE_VAR ); ?>"
		data-sizes="<?php echo esc_attr( wp_json_encode( $map ) ); ?>"
		data-label="<?php echo esc_attr__( 'Only show pieces in stock in <b>%s</b>', 'moderno-child' ); ?>" hidden>
		<a class="omc-mysize__switch" href="#" role="switch" aria-checked="false">
			<span class="omc-mysize__track" aria-hidden="true"><span class="omc-mysize__knob"></span></span>
			<span class="omc-mysize__text"></span>
		</a>
	</div>
	<?php
	wp_add_inline_script( 'omc', omc_my_size_script() );
}
add_action( 'woocommerce_before_shop_loop', 'omc_my_size_switch', 25 );
add_action( 'woocommerce_no_products_found', 'omc_my_size_switch', 5 );

/** The few lines that draw the switch from the whd_fit cookie. */
function omc_my_size_script() {
	return <<<'JS'
(function () {
	var box = document.querySelector('[data-omc-mysize]');
	if (!box) { return; }
	var m = document.cookie.match(/(?:^|;\s*)whd_fit=([^;]*)/);
	if (!m) { return; }
	var fit = null, text = m[1];
	for (var i = 0; i < 3 && !fit; i++) {
		try { fit = JSON.parse(text); } catch (e) { try { text = decodeURIComponent(text); } catch (e2) { return; } }
	}
	var size = fit && fit.pa_size;
	// One size fits everyone: as "my size" it would hide every piece that comes in sizes.
	if (!size || /^one-?size/.test(size)) { return; }
	var sizes = {};
	try { sizes = JSON.parse(box.getAttribute('data-sizes') || '{}'); } catch (e) {}
	if (!size || !sizes[size]) { return; }
	var url = new URL(window.location.href);
	url.searchParams.set(box.getAttribute('data-var'), size);
	url.searchParams.delete('paged');
	url.pathname = url.pathname.replace(/\/page\/\d+\/?$/, '/');
	var link = box.querySelector('.omc-mysize__switch');
	var label = document.createElement('div');
	label.textContent = sizes[size];
	box.querySelector('.omc-mysize__text').innerHTML = (box.getAttribute('data-label') || '%s').replace('%s', label.innerHTML);
	link.href = url.toString();
	box.hidden = false;
}());
JS;
}
