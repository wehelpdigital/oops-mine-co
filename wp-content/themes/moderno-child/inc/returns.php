<?php
/**
 * Returns and exchanges: a request form — pick the order and the pieces, say why, add a photo.
 *
 * It lives in two places: My account → "Returns & exchanges" (the shopper's own orders, listed), and
 * at the foot of the returns policy page (WooCommerce's refund/returns page), where somebody who
 * checked out as a guest finds their order by its number and the email it was placed with.
 *
 * A request does not refund or cancel anything. It is written on the order as a private note (so it
 * sits in the order's history in WooCommerce), kept on the order as data, and emailed to the shop
 * with the photos linked; the shop answers by email, from the policy, as it does today.
 *
 * Who may ask about which order: the form carries a signed token per order — an order the logged-in
 * customer owns, or one whose number and billing email were both typed correctly — and nothing is
 * accepted without it. Looking orders up is rate-limited, so the guest form cannot be used to walk
 * through order numbers.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

const OMC_RETURNS_ENDPOINT = 'returns';
const OMC_RETURNS_META     = '_omc_return_requests';

/** Order statuses a request can be made against: paid and on their way, or arrived. */
function omc_returns_statuses() {
	return (array) apply_filters( 'omc_returns_statuses', [ 'processing', 'completed' ] );
}

function omc_returns_reasons() {
	return (array) apply_filters( 'omc_returns_reasons', [
		'small'   => __( 'Too small', 'moderno-child' ),
		'big'     => __( 'Too big', 'moderno-child' ),
		'look'    => __( 'Not as pictured', 'moderno-child' ),
		'faulty'  => __( 'Arrived damaged or faulty', 'moderno-child' ),
		'wrong'   => __( 'Not what I ordered', 'moderno-child' ),
		'changed' => __( 'Changed my mind', 'moderno-child' ),
		'other'   => __( 'Something else', 'moderno-child' ),
	] );
}

/* ─────────────────────────── who may ask ─────────────────────────── */

/** A token tying the form to one order and its billing email, good for a day. */
function omc_returns_token( WC_Order $order, $expires = 0 ) {
	$expires = $expires ?: time() + DAY_IN_SECONDS;
	$sig     = hash_hmac( 'sha256', $order->get_id() . '|' . strtolower( $order->get_billing_email() ) . '|' . $expires, wp_salt( 'nonce' ) );
	return $order->get_id() . '.' . $expires . '.' . $sig;
}

/** The order a token speaks for, or null. */
function omc_returns_order_from_token( $token ) {
	$parts = explode( '.', (string) $token );
	if ( 3 !== count( $parts ) || (int) $parts[1] < time() ) {
		return null;
	}
	$order = wc_get_order( (int) $parts[0] );
	if ( ! $order || ! hash_equals( omc_returns_token( $order, (int) $parts[1] ), (string) $token ) ) {
		return null;
	}
	return $order;
}

/** What the form needs to know about one order. */
function omc_returns_order_data( WC_Order $order ) {
	$items = [];
	foreach ( $order->get_items() as $item_id => $item ) {
		$product = $item->get_product();
		$image   = $product ? (int) $product->get_image_id() : 0;
		if ( ! $image && $product && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$image  = $parent ? (int) $parent->get_image_id() : 0;
		}
		$items[] = [
			'id'    => (int) $item_id,
			'name'  => html_entity_decode( $item->get_name(), ENT_QUOTES, 'UTF-8' ),
			'qty'   => (int) $item->get_quantity(),
			'meta'  => wp_strip_all_tags( wc_display_item_meta( $item, [ 'echo' => false, 'separator' => ', ', 'before' => '', 'after' => '', 'label_before' => '', 'label_after' => ': ' ] ) ),
			'thumb' => $image ? (string) wp_get_attachment_image_url( $image, 'thumbnail' ) : '',
		];
	}
	return [
		'token'  => omc_returns_token( $order ),
		'number' => $order->get_order_number(),
		'date'   => wc_format_datetime( $order->get_date_created() ),
		'items'  => $items,
	];
}

/* ─────────────────────────── the form ─────────────────────────── */

function omc_returns_assets() {
	$ver = static fn( $rel ) => file_exists( OMC_DIR . $rel ) ? (string) filemtime( OMC_DIR . $rel ) : OMC_VERSION;
	wp_enqueue_style( 'omc-returns', OMC_URI . '/assets/css/omc-returns.css', [ 'omc' ], $ver( '/assets/css/omc-returns.css' ) );
	wp_enqueue_script( 'omc-returns', OMC_URI . '/assets/js/omc-returns.js', [], $ver( '/assets/js/omc-returns.js' ), true );
}

/* In the head on the two pages that carry it, so it is styled from the first paint. */
add_action( 'wp_enqueue_scripts', static function () {
	if ( omc_returns_is_policy_page() || ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( OMC_RETURNS_ENDPOINT ) ) ) {
		omc_returns_assets();
	}
}, 1001 );

function omc_returns_is_policy_page( $page_id = 0 ) {
	$returns = (int) get_option( 'woocommerce_refund_returns_page_id' );
	return $returns && ( $page_id ? (int) $page_id === $returns : is_page( $returns ) );
}

/**
 * The request form. Logged in: their own recent orders to choose from. Otherwise: find the order by
 * its number and email.
 */
function omc_returns_form( $args = [] ) {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return;
	}
	omc_returns_assets();
	$a      = wp_parse_args( $args, [ 'heading' => true, 'order' => 0 ] );
	$orders = [];
	if ( is_user_logged_in() ) {
		foreach ( wc_get_orders( [ 'customer_id' => get_current_user_id(), 'status' => omc_returns_statuses(), 'limit' => 20, 'orderby' => 'date', 'order' => 'DESC' ] ) as $order ) {
			$orders[] = omc_returns_order_data( $order );
		}
	}
	// The order to open first (from an order's own page): its number, if it is one of theirs.
	$pick = '';
	if ( (int) $a['order'] ) {
		$first = wc_get_order( (int) $a['order'] );
		$pick  = $first && (int) $first->get_customer_id() === get_current_user_id() ? (string) $first->get_order_number() : '';
	}
	?>
	<section class="omc-returns" id="start-a-return" data-omc-returns
		data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
		data-nonce="<?php echo esc_attr( wp_create_nonce( 'omc_returns' ) ); ?>"
		data-orders="<?php echo esc_attr( wp_json_encode( $orders ) ); ?>"
		data-pick="<?php echo esc_attr( $pick ); ?>"
		data-error="<?php esc_attr_e( 'Something went wrong. Please try again, or email us.', 'moderno-child' ); ?>"
		data-choose="<?php esc_attr_e( 'Tick at least one piece.', 'moderno-child' ); ?>"
		data-reason="<?php esc_attr_e( 'Tell us why — choose a reason.', 'moderno-child' ); ?>"
		data-qty="<?php esc_attr_e( 'How many', 'moderno-child' ); ?>">
		<?php if ( $a['heading'] ) : ?>
			<h2 class="omc-returns__h"><?php echo wp_kses( __( 'Start a return or <em>exchange</em>', 'moderno-child' ), [ 'em' => [] ] ); ?></h2>
		<?php endif; ?>
		<p class="omc-returns__lede"><?php esc_html_e( 'Choose the order and the pieces, tell us why, and add a photo if something is wrong with it. We read every request and reply by email with what happens next.', 'moderno-child' ); ?></p>

		<?php if ( is_user_logged_in() ) : ?>
			<?php if ( $orders ) : ?>
				<p class="omc-returns__row">
					<label for="omc-returns-order"><?php esc_html_e( 'Which order?', 'moderno-child' ); ?></label>
					<select id="omc-returns-order" class="omc-returns__select" data-omc-returns-orders>
						<option value=""><?php esc_html_e( 'Choose an order', 'moderno-child' ); ?></option>
						<?php foreach ( $orders as $i => $o ) : ?>
							<option value="<?php echo (int) $i; ?>">
								<?php
								/* translators: 1: order number, 2: date, 3: number of pieces */
								echo esc_html( sprintf( _n( '#%1$s — %2$s — %3$d piece', '#%1$s — %2$s — %3$d pieces', count( $o['items'] ), 'moderno-child' ), $o['number'], $o['date'], count( $o['items'] ) ) );
								?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="omc-returns__or"><button type="button" class="omc-returns__switch" data-omc-returns-guest><?php esc_html_e( 'An order placed without signing in? Find it by its number', 'moderno-child' ); ?></button></p>
			<?php else : ?>
				<p class="omc-returns__empty"><?php esc_html_e( 'There are no orders on this account that can be returned yet. Checked out without signing in? Find the order by its number and email below.', 'moderno-child' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
		<?php /* Anyone can find an order by number and email — a member's guest checkouts included. */ ?>
		<form class="omc-returns__find" data-omc-returns-find novalidate<?php echo $orders ? ' hidden' : ''; ?>>
				<div class="omc-returns__grid">
					<p class="omc-returns__row">
						<label for="omc-returns-number"><?php esc_html_e( 'Order number', 'moderno-child' ); ?></label>
						<input id="omc-returns-number" type="text" name="number" inputmode="numeric" autocomplete="off" required placeholder="1234">
					</p>
					<p class="omc-returns__row">
						<label for="omc-returns-email"><?php esc_html_e( 'Email used for the order', 'moderno-child' ); ?></label>
						<input id="omc-returns-email" type="email" name="email" autocomplete="email" required>
					</p>
				</div>
				<button type="submit" class="omc-btn omc-btn--outline"><?php esc_html_e( 'Find my order', 'moderno-child' ); ?></button>
				<p class="omc-returns__note" role="status" aria-live="polite"></p>
		</form>

		<form class="omc-returns__form" data-omc-returns-form enctype="multipart/form-data" novalidate hidden>
			<input type="hidden" name="token" value="">
			<p class="omc-returns__order"></p>
			<fieldset class="omc-returns__items">
				<legend><?php esc_html_e( 'Which pieces?', 'moderno-child' ); ?></legend>
				<ul class="omc-returns__list"></ul>
			</fieldset>
			<fieldset class="omc-returns__want">
				<legend><?php esc_html_e( 'What would you like?', 'moderno-child' ); ?></legend>
				<label class="omc-returns__radio"><input type="radio" name="want" value="return" checked> <span><?php esc_html_e( 'A return', 'moderno-child' ); ?></span></label>
				<label class="omc-returns__radio"><input type="radio" name="want" value="exchange"> <span><?php esc_html_e( 'An exchange', 'moderno-child' ); ?></span></label>
				<p class="omc-returns__row omc-returns__swap" hidden>
					<label for="omc-returns-swap"><?php esc_html_e( 'What would you like instead? (size, colour, piece)', 'moderno-child' ); ?></label>
					<input id="omc-returns-swap" type="text" name="swap" maxlength="200">
				</p>
			</fieldset>
			<p class="omc-returns__row">
				<label for="omc-returns-reason"><?php esc_html_e( 'Why?', 'moderno-child' ); ?></label>
				<select id="omc-returns-reason" class="omc-returns__select" name="reason" required>
					<option value=""><?php esc_html_e( 'Choose a reason', 'moderno-child' ); ?></option>
					<?php foreach ( omc_returns_reasons() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="omc-returns__row">
				<label for="omc-returns-details"><?php esc_html_e( 'Anything we should know?', 'moderno-child' ); ?></label>
				<textarea id="omc-returns-details" name="details" rows="4" maxlength="3000"></textarea>
			</p>
			<p class="omc-returns__row">
				<label for="omc-returns-photos"><?php esc_html_e( 'Photos (up to 3)', 'moderno-child' ); ?></label>
				<input id="omc-returns-photos" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple>
				<span class="omc-returns__hint"><?php esc_html_e( 'Helpful for a fault, a mark or the wrong piece. JPG, PNG or WebP, 5 MB each at most.', 'moderno-child' ); ?></span>
			</p>
			<p class="omc-returns__hp" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></p>
			<div class="omc-returns__actions">
				<button type="submit" class="omc-btn omc-btn--solid"><?php esc_html_e( 'Send the request', 'moderno-child' ); ?></button>
				<p class="omc-returns__note" role="status" aria-live="polite"></p>
			</div>
		</form>
		<div class="omc-returns__done" role="status" hidden></div>
	</section>
	<?php
}
add_shortcode( 'omc_returns', static function () {
	ob_start();
	omc_returns_form();
	return ob_get_clean();
} );

/* The returns policy page: the form under the policy, and a line in its "On this page" list. */
add_action( 'omc_policy_after_text', static function ( $page_id ) {
	if ( omc_returns_is_policy_page( $page_id ) ) {
		omc_returns_form();
	}
} );
add_filter( 'omc_policy_nav', static function ( $nav, $page_id ) {
	if ( omc_returns_is_policy_page( $page_id ) ) {
		$nav[] = [ 'id' => 'start-a-return', 'label' => __( 'Start a return', 'moderno-child' ) ];
	}
	return $nav;
}, 10, 2 );

/* ─────────────────────────── My account ─────────────────────────── */

add_filter( 'woocommerce_get_query_vars', static function ( $vars ) {
	$vars[ OMC_RETURNS_ENDPOINT ] = OMC_RETURNS_ENDPOINT;
	return $vars;
} );

/* A new endpoint needs the rewrite rules rebuilt once — on the first request after it arrives. */
add_action( 'init', static function () {
	if ( get_option( 'omc_returns_rewrite' ) !== OMC_RETURNS_ENDPOINT ) {
		flush_rewrite_rules( false );
		update_option( 'omc_returns_rewrite', OMC_RETURNS_ENDPOINT, true );
	}
}, 99 );

add_filter( 'woocommerce_account_menu_items', static function ( $items ) {
	$out = [];
	foreach ( $items as $key => $label ) {
		if ( 'customer-logout' === $key ) {
			$out[ OMC_RETURNS_ENDPOINT ] = __( 'Returns & exchanges', 'moderno-child' );
		}
		$out[ $key ] = $label;
	}
	if ( ! isset( $out[ OMC_RETURNS_ENDPOINT ] ) ) {
		$out[ OMC_RETURNS_ENDPOINT ] = __( 'Returns & exchanges', 'moderno-child' );
	}
	return $out;
} );

add_filter( 'woocommerce_endpoint_' . OMC_RETURNS_ENDPOINT . '_title', static fn() => __( 'Returns & exchanges', 'moderno-child' ) );

add_action( 'woocommerce_account_' . OMC_RETURNS_ENDPOINT . '_endpoint', static function () {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which order to open first
	omc_returns_form( [ 'heading' => false, 'order' => isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0 ] );
} );

/* From an order's own page: straight to the form with that order chosen. */
add_action( 'woocommerce_order_details_after_order_table', static function ( $order ) {
	if ( ! is_user_logged_in() || ! $order instanceof WC_Order || ! $order->has_status( omc_returns_statuses() ) || (int) $order->get_customer_id() !== get_current_user_id() ) {
		return;
	}
	if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'view-order' ) ) {
		return;
	}
	echo '<p class="omc-returns__from-order"><a class="omc-btn omc-btn--outline" href="' . esc_url( add_query_arg( 'order', $order->get_id(), wc_get_account_endpoint_url( OMC_RETURNS_ENDPOINT ) ) ) . '">' . esc_html__( 'Return or exchange a piece', 'moderno-child' ) . '</a></p>';
} );

/* ─────────────────────────── requests ─────────────────────────── */

/** Guest lookup: order number + billing email → the order's pieces and its token. */
function omc_returns_find_ajax() {
	check_ajax_referer( 'omc_returns', 'nonce' );
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key = 'omc_returns_find_' . md5( $ip );
	if ( $ip && (int) get_transient( $key ) >= 10 ) {
		wp_send_json_error( [ 'message' => __( 'That is a lot of tries. Wait an hour, or email us with your order number.', 'moderno-child' ) ], 429 );
	}
	if ( $ip ) {
		set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
	}
	$number = isset( $_POST['number'] ) ? preg_replace( '/[^0-9A-Za-z-]/', '', wp_unslash( $_POST['number'] ) ) : '';
	$email  = isset( $_POST['email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : '';
	$nope   = __( 'We could not find an order with that number and email. Check both against your order confirmation email.', 'moderno-child' );
	if ( '' === $number || ! is_email( $email ) ) {
		wp_send_json_error( [ 'message' => $nope ], 400 );
	}
	$order = wc_get_order( absint( ltrim( $number, '#' ) ) );
	if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || strtolower( $order->get_billing_email() ) !== $email || (string) $order->get_order_number() !== ltrim( $number, '#' ) ) {
		wp_send_json_error( [ 'message' => $nope ], 404 );
	}
	if ( ! $order->has_status( omc_returns_statuses() ) ) {
		wp_send_json_error( [ 'message' => __( 'That order cannot be returned yet — it has to be paid first. Email us if something is wrong with it.', 'moderno-child' ) ], 400 );
	}
	wp_send_json_success( omc_returns_order_data( $order ) );
}
add_action( 'wp_ajax_omc_returns_find', 'omc_returns_find_ajax' );
add_action( 'wp_ajax_nopriv_omc_returns_find', 'omc_returns_find_ajax' );

/** The request itself. */
function omc_returns_submit_ajax() {
	check_ajax_referer( 'omc_returns', 'nonce' );
	if ( ! empty( $_POST['website'] ) ) {
		wp_send_json_success( [ 'message' => __( 'Thank you — your request is on its way.', 'moderno-child' ) ] );
	}
	$order = omc_returns_order_from_token( isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '' );
	if ( ! $order ) {
		wp_send_json_error( [ 'message' => __( 'This form has expired. Reload the page and choose the order again.', 'moderno-child' ) ], 403 );
	}

	$reasons = omc_returns_reasons();
	$reason  = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : '';
	$want    = isset( $_POST['want'] ) && 'exchange' === $_POST['want'] ? 'exchange' : 'return';
	$swap    = isset( $_POST['swap'] ) ? sanitize_text_field( wp_unslash( $_POST['swap'] ) ) : '';
	$details = isset( $_POST['details'] ) ? substr( sanitize_textarea_field( wp_unslash( $_POST['details'] ) ), 0, 3000 ) : '';
	if ( ! isset( $reasons[ $reason ] ) ) {
		wp_send_json_error( [ 'message' => __( 'Tell us why — choose a reason.', 'moderno-child' ) ], 400 );
	}

	$asked = isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ids and counts, cast below
	$lines = [];
	foreach ( $order->get_items() as $item_id => $item ) {
		if ( ! isset( $asked[ $item_id ] ) ) {
			continue;
		}
		$qty     = max( 1, min( (int) $item->get_quantity(), (int) $asked[ $item_id ] ) );
		$meta    = wp_strip_all_tags( wc_display_item_meta( $item, [ 'echo' => false, 'separator' => ', ', 'before' => '', 'after' => '', 'label_before' => '', 'label_after' => ': ' ] ) );
		$lines[] = [ 'item' => (int) $item_id, 'name' => $item->get_name(), 'meta' => $meta, 'qty' => $qty ];
	}
	if ( ! $lines ) {
		wp_send_json_error( [ 'message' => __( 'Tick at least one piece.', 'moderno-child' ) ], 400 );
	}

	$photos = omc_returns_save_photos( $order );

	$request = [
		'at'      => current_time( 'mysql' ),
		'want'    => $want,
		'swap'    => $swap,
		'reason'  => $reason,
		'details' => $details,
		'items'   => $lines,
		'photos'  => $photos,
		'user'    => get_current_user_id(),
	];
	$all   = $order->get_meta( OMC_RETURNS_META );
	$all   = is_array( $all ) ? $all : [];
	$all[] = $request;
	$order->update_meta_data( OMC_RETURNS_META, $all );

	/* The note: the order's own history is where the shop will look first. */
	$text = sprintf(
		/* translators: 1: Return or Exchange, 2: reason */
		__( '%1$s requested on the website — %2$s.', 'moderno-child' ),
		'exchange' === $want ? __( 'Exchange', 'moderno-child' ) : __( 'Return', 'moderno-child' ),
		$reasons[ $reason ]
	) . "\n";
	foreach ( $lines as $l ) {
		$text .= '• ' . $l['qty'] . ' × ' . $l['name'] . ( $l['meta'] ? ' (' . $l['meta'] . ')' : '' ) . "\n";
	}
	if ( $swap ) {
		/* translators: %s: what the customer would like instead */
		$text .= sprintf( __( 'Would like instead: %s', 'moderno-child' ), $swap ) . "\n";
	}
	if ( $details ) {
		$text .= '“' . $details . '”' . "\n";
	}
	foreach ( $photos as $pid ) {
		$text .= wp_get_attachment_url( $pid ) . "\n";
	}
	$order->add_order_note( trim( $text ), 0, false );
	$order->save();

	$sent = omc_returns_email( $order, $request, $reasons );
	do_action( 'omc_return_requested', $order, $request );

	wp_send_json_success( [
		'message' => sprintf(
			/* translators: 1: order number, 2: email address */
			__( 'Thank you — we have your request for order #%1$s. We will reply to %2$s with what happens next.', 'moderno-child' ),
			$order->get_order_number(),
			$order->get_billing_email()
		),
		'mailed'  => $sent,
	] );
}
add_action( 'wp_ajax_omc_returns_submit', 'omc_returns_submit_ajax' );
add_action( 'wp_ajax_nopriv_omc_returns_submit', 'omc_returns_submit_ajax' );

/** Photos into the media library, marked with the order they belong to. @return int[] */
function omc_returns_save_photos( WC_Order $order ) {
	if ( empty( $_FILES['photos'] ) || ! function_exists( 'omc_pdp_normalize_files' ) ) {
		return [];
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$mimes    = [ 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ];
	$original = $_FILES;
	$ids      = [];
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- checked file by file below
	foreach ( omc_pdp_normalize_files( $_FILES['photos'] ) as $file ) {
		if ( count( $ids ) >= 3 ) {
			break;
		}
		if ( UPLOAD_ERR_OK !== $file['error'] || $file['size'] <= 0 || $file['size'] > 5 * MB_IN_BYTES || ! is_uploaded_file( $file['tmp_name'] ) ) {
			continue;
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $mimes );
		$size  = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( empty( $check['type'] ) || empty( $size[0] ) ) {
			continue;
		}
		$_FILES = [ 'omc_return_photo' => $file ];
		$id     = media_handle_upload( 'omc_return_photo', 0, [
			/* translators: %s: order number */
			'post_title' => sprintf( __( 'Return request — order #%s', 'moderno-child' ), $order->get_order_number() ),
		], [ 'test_form' => false, 'mimes' => $mimes ] );
		if ( ! is_wp_error( $id ) ) {
			update_post_meta( $id, '_omc_return_order', $order->get_id() );
			$ids[] = (int) $id;
		}
	}
	$_FILES = $original;
	return $ids;
}

/** The shop's copy of the request. */
function omc_returns_email( WC_Order $order, array $r, array $reasons ) {
	$to    = apply_filters( 'omc_returns_recipient', apply_filters( 'omc_contact_recipient', get_option( 'admin_email' ) ) );
	$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$kind  = 'exchange' === $r['want'] ? __( 'Exchange', 'moderno-child' ) : __( 'Return', 'moderno-child' );
	/* translators: 1: site, 2: Return or Exchange, 3: order number */
	$title = sprintf( __( '[%1$s] %2$s request — order #%3$s', 'moderno-child' ), $site, $kind, $order->get_order_number() );
	$row   = static fn( $k, $v ) => '<tr><td style="padding:6px 14px 6px 0;color:#8a7d76;vertical-align:top;white-space:nowrap">' . esc_html( $k ) . '</td><td style="padding:6px 0;color:#3a2b26">' . $v . '</td></tr>';
	$items = '';
	foreach ( $r['items'] as $l ) {
		$items .= esc_html( $l['qty'] . ' × ' . $l['name'] ) . ( $l['meta'] ? ' <span style="color:#8a7d76">(' . esc_html( $l['meta'] ) . ')</span>' : '' ) . '<br>';
	}
	$photos = '';
	foreach ( $r['photos'] as $pid ) {
		$photos .= '<a href="' . esc_url( wp_get_attachment_url( $pid ) ) . '"><img src="' . esc_url( wp_get_attachment_image_url( $pid, 'medium' ) ?: wp_get_attachment_url( $pid ) ) . '" alt="" width="160" style="margin:0 8px 8px 0;border:1px solid #e9e1da"></a>';
	}
	$body = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#3a2b26;max-width:620px">'
		. '<h2 style="font-family:Georgia,serif;font-weight:normal;color:#9c6f63;margin:0 0 12px">' . esc_html( $kind ) . ' request — order #' . esc_html( $order->get_order_number() ) . '</h2>'
		. '<table role="presentation" cellpadding="0" cellspacing="0" border="0">'
		. $row( __( 'From', 'moderno-child' ), esc_html( trim( $order->get_formatted_billing_full_name() ) . ' <' . $order->get_billing_email() . '>' ) )
		. $row( __( 'Ordered', 'moderno-child' ), esc_html( wc_format_datetime( $order->get_date_created() ) . ' · ' . wc_get_order_status_name( $order->get_status() ) ) )
		. $row( __( 'Pieces', 'moderno-child' ), $items )
		. $row( __( 'Why', 'moderno-child' ), esc_html( $reasons[ $r['reason'] ] ?? $r['reason'] ) )
		. ( $r['swap'] ? $row( __( 'Instead', 'moderno-child' ), esc_html( $r['swap'] ) ) : '' )
		. ( $r['details'] ? $row( __( 'Their words', 'moderno-child' ), nl2br( esc_html( $r['details'] ) ) ) : '' )
		. '</table>'
		. ( $photos ? '<p style="margin:16px 0 6px;color:#8a7d76">' . esc_html__( 'Photos', 'moderno-child' ) . '</p>' . $photos : '' )
		. '<p style="margin:18px 0 0"><a href="' . esc_url( $order->get_edit_order_url() ) . '" style="display:inline-block;padding:12px 20px;background:#3a2b26;color:#f7f2ed;text-decoration:none">' . esc_html__( 'Open the order', 'moderno-child' ) . '</a></p>'
		. '<p style="margin:16px 0 0;color:#8a7d76;font-size:12px">' . esc_html__( 'Reply to this email to answer the customer directly. The request is also written on the order as a private note.', 'moderno-child' ) . '</p>'
		. '</div>';
	$headers = class_exists( 'WHD_Emails' ) ? WHD_Emails::headers() : [ 'Content-Type: text/html; charset=UTF-8' ];
	$headers = array_values( array_filter( $headers, static fn( $h ) => 0 !== stripos( $h, 'Reply-To:' ) ) );
	$headers[] = 'Reply-To: ' . trim( $order->get_formatted_billing_full_name() ) . ' <' . $order->get_billing_email() . '>';
	return (bool) wp_mail( $to, $title, $body, $headers );
}
