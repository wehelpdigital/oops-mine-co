<?php
/**
 * Email builder: one block design per trigger. WooCommerce's own emails are
 * taken over by pointing wc_locate_template() at templates/wc-email.php when a
 * trigger is enabled; the custom triggers (abandoned cart, newsletter welcome)
 * are sent through wp_mail() with the same renderer.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Emails {

	const OPTION = 'whd_emails';

	/** Every trigger the builder can customise. `template` = the WooCommerce HTML template it replaces. */
	public static function triggers() {
		return [
			'customer_processing_order' => [ 'label' => __( 'Order confirmation (processing)', 'whd' ), 'group' => 'customer', 'template' => 'emails/customer-processing-order.php' ],
			'customer_on_hold_order'    => [ 'label' => __( 'Order on hold (awaiting payment)', 'whd' ), 'group' => 'customer', 'template' => 'emails/customer-on-hold-order.php' ],
			'customer_completed_order'  => [ 'label' => __( 'Order completed / shipped', 'whd' ), 'group' => 'customer', 'template' => 'emails/customer-completed-order.php' ],
			'customer_refunded_order'   => [ 'label' => __( 'Order refunded', 'whd' ), 'group' => 'customer', 'template' => 'emails/customer-refunded-order.php' ],
			'customer_invoice'          => [ 'label' => __( 'Invoice / order details', 'whd' ), 'group' => 'customer', 'template' => 'emails/customer-invoice.php' ],
			'customer_note'             => [ 'label' => __( 'Note to customer', 'whd' ), 'group' => 'customer', 'template' => 'emails/customer-note.php' ],
			'customer_new_account'      => [ 'label' => __( 'New account welcome', 'whd' ), 'group' => 'customer', 'template' => 'emails/customer-new-account.php' ],
			'customer_reset_password'   => [ 'label' => __( 'Password reset', 'whd' ), 'group' => 'customer', 'template' => 'emails/customer-reset-password.php' ],
			'abandoned_cart'            => [ 'label' => __( 'Abandoned cart recovery', 'whd' ), 'group' => 'automation', 'template' => '' ],
			'welcome_subscriber'        => [ 'label' => __( 'Newsletter welcome', 'whd' ), 'group' => 'automation', 'template' => '' ],
			'new_order'                 => [ 'label' => __( 'New order (to admin)', 'whd' ), 'group' => 'admin', 'template' => 'emails/admin-new-order.php' ],
			'cancelled_order'           => [ 'label' => __( 'Cancelled order (to admin)', 'whd' ), 'group' => 'admin', 'template' => 'emails/admin-cancelled-order.php' ],
			'failed_order'              => [ 'label' => __( 'Failed order (to admin)', 'whd' ), 'group' => 'admin', 'template' => 'emails/admin-failed-order.php' ],
		];
	}

	public static function init() {
		add_filter( 'woocommerce_locate_template', [ __CLASS__, 'locate_template' ], 20, 3 );
		add_action( 'init', [ __CLASS__, 'register_subject_filters' ] );
	}

	/* ─────────────────────────── storage ─────────────────────────── */

	public static function all() {
		$stored = get_option( self::OPTION, [] );
		$out    = [];
		foreach ( self::triggers() as $id => $t ) {
			$out[ $id ] = WHD_Blocks::with_defaults( $stored[ $id ] ?? self::default_design( $id ), 'email' );
		}
		return $out;
	}

	public static function get( $id ) {
		return self::all()[ $id ] ?? null;
	}

	public static function save( $id, $design ) {
		if ( ! isset( self::triggers()[ $id ] ) ) {
			return false;
		}
		$stored        = get_option( self::OPTION, [] );
		$stored[ $id ] = WHD_Blocks::sanitize_design( $design, 'email' );
		update_option( self::OPTION, $stored, false );
		return $stored[ $id ];
	}

	public static function ensure_defaults() {
		$stored = get_option( self::OPTION, [] );
		foreach ( self::triggers() as $id => $t ) {
			if ( empty( $stored[ $id ] ) ) {
				$stored[ $id ] = self::default_design( $id );
			}
		}
		update_option( self::OPTION, $stored, false );
	}

	/** Brand-voice starter design per trigger. */
	public static function default_design( $id ) {
		$b = function ( $type, $props ) { return [ 'type' => $type, 'props' => $props ]; };
		$heading = function ( $text ) use ( $b ) { return $b( 'heading', [ 'text' => $text, 'level' => 'h2', 'align' => 'left', 'color' => '#141414' ] ); };
		$text    = function ( $text ) use ( $b ) { return $b( 'text', [ 'text' => $text, 'align' => 'left', 'size' => 16, 'color' => '#2b2724' ] ); };
		$button  = function ( $label, $url ) use ( $b ) { return $b( 'button', [ 'text' => $label, 'url' => $url, 'align' => 'left', 'bg' => '#141414', 'color' => '#ffffff', 'radius' => 0, 'full' => 0 ] ); };
		$brand   = $b( 'text', [ 'text' => '<strong>OOPS, MINE CO.</strong>', 'align' => 'left', 'size' => 12, 'color' => '#b98b7e' ] );
		$signoff = $text( 'Find something special. Trust your instinct. And when you know, you know.<br>— {site_name}' );
		$items   = $b( 'order_items', [ 'show_images' => 1 ] );
		$summary = $b( 'order_summary', [ 'show_addresses' => 1 ] );

		$map = [
			'customer_processing_order' => [ 'Oops — it’s yours. Order #{order_number} is confirmed', [ $brand, $heading( 'It’s officially yours, {first_name}.' ), $text( 'Thank you for your order. We’re preparing it with the same care we used to choose it. Here’s what’s coming your way:' ), $items, $summary, $button( 'View your order', '{order_url}' ), $signoff ] ],
			'customer_on_hold_order'    => [ 'Your order #{order_number} is on hold', [ $brand, $heading( 'Almost there, {first_name}.' ), $text( 'Your order is on hold until we confirm payment. As soon as it clears, we’ll get your pieces moving.' ), $items, $summary, $button( 'Complete payment', '{payment_url}' ), $signoff ] ],
			'customer_completed_order'  => [ 'Your Oops, Mine order #{order_number} is on its way', [ $brand, $heading( 'On its way to you.' ), $text( 'Order #{order_number} has shipped. We hope it feels exactly like you hoped it would.' ), $items, $summary, $button( 'Track your order', '{order_url}' ), $signoff ] ],
			'customer_refunded_order'   => [ 'Your refund for order #{order_number}', [ $brand, $heading( 'Your refund is on its way.' ), $text( 'We’ve refunded order #{order_number}. Depending on your bank, it can take a few days to appear.' ), $items, $summary, $signoff ] ],
			'customer_invoice'          => [ 'Order #{order_number} from {site_name}', [ $brand, $heading( 'Your order details' ), $text( 'Here are the details for order #{order_number}, placed on {order_date}.' ), $items, $summary, $button( 'Pay for this order', '{payment_url}' ), $signoff ] ],
			'customer_note'             => [ 'A note about your order #{order_number}', [ $brand, $heading( 'A quick note from us' ), $text( '{customer_note}' ), $items, $button( 'View your order', '{order_url}' ), $signoff ] ],
			'customer_new_account'      => [ 'Welcome to {site_name}', [ $brand, $heading( 'Welcome, {first_name}.' ), $text( 'Your account is ready. Save your details, follow your orders and keep a wishlist of the pieces that caught your eye.' ), $button( 'Go to my account', '{my_account_url}' ), $signoff ] ],
			'customer_reset_password'   => [ 'Reset your {site_name} password', [ $brand, $heading( 'Reset your password' ), $text( 'Someone asked to reset the password for <strong>{user_login}</strong>. If that was you, use the button below. If not, you can safely ignore this email.' ), $button( 'Choose a new password', '{reset_url}' ), $signoff ] ],
			'abandoned_cart'            => [ 'You left something behind, {first_name}', [ $brand, $heading( 'Still thinking about it?' ), $text( 'Your bag is exactly how you left it. Some pieces don’t stay in stock long — and this one caught your eye for a reason.' ), $b( 'cart_items', [ 'show_images' => 1 ] ), $b( 'coupon', [ 'code' => '{coupon_code}', 'note' => 'A little nudge — use it at checkout', 'color' => '#b98b7e' ] ), $button( 'Return to my bag', '{recovery_url}' ), $text( 'Oops, mine? We thought so.<br>— {site_name}' ) ] ],
			'welcome_subscriber'        => [ 'Welcome to Oops, Mine Co.', [ $brand, $heading( 'Found with intention. Claimed on instinct.' ), $text( 'Thanks for joining the list. Expect new arrivals, quiet restocks and the occasional note — never noise.' ), $b( 'coupon', [ 'code' => '{coupon_code}', 'note' => '10% off your first order', 'color' => '#b98b7e' ] ), $button( 'Shop new arrivals', '{shop_url}' ), $signoff ] ],
			'new_order'                 => [ '[{site_name}] New order #{order_number} from {customer_name}', [ $brand, $heading( 'New order #{order_number}' ), $text( '{customer_name} ({email}) placed an order on {order_date}. Status: {order_status}.' ), $items, $summary, $button( 'Open in WooCommerce', '{admin_order_url}' ) ] ],
			'cancelled_order'           => [ '[{site_name}] Order #{order_number} cancelled', [ $brand, $heading( 'Order #{order_number} was cancelled' ), $text( 'Order from {customer_name} ({email}) has been cancelled.' ), $items, $summary ] ],
			'failed_order'              => [ '[{site_name}] Order #{order_number} failed', [ $brand, $heading( 'Payment failed for order #{order_number}' ), $text( '{customer_name} ({email}) attempted to pay but the payment failed.' ), $items, $summary, $button( 'Open in WooCommerce', '{admin_order_url}' ) ] ],
		];
		$entry = $map[ $id ] ?? [ '{site_name}', [ $brand, $heading( 'Hello {first_name}' ), $text( 'Write something.' ) ] ];
		$settings = WHD_Blocks::email_settings_defaults();
		$settings['subject']   = $entry[0];
		$settings['preheader'] = '';
		return [ 'settings' => $settings, 'blocks' => $entry[1] ];
	}

	/* ─────────────────────────── WooCommerce take-over ─────────────────────────── */

	public static function locate_template( $template, $template_name, $template_path ) {
		static $map = null;
		if ( null === $map ) {
			$map = [];
			foreach ( self::triggers() as $id => $t ) {
				if ( $t['template'] ) {
					$map[ $t['template'] ] = $id;
				}
			}
		}
		if ( isset( $map[ $template_name ] ) ) {
			$design = self::get( $map[ $template_name ] );
			if ( ! empty( $design['settings']['enabled'] ) ) {
				return WHD_DIR . 'templates/wc-email.php';
			}
		}
		return $template;
	}

	public static function register_subject_filters() {
		foreach ( self::triggers() as $id => $t ) {
			if ( ! $t['template'] ) {
				continue;
			}
			add_filter( "woocommerce_email_subject_{$id}", function ( $subject, $object = null ) use ( $id ) {
				$design = self::get( $id );
				if ( empty( $design['settings']['enabled'] ) || '' === trim( $design['settings']['subject'] ) ) {
					return $subject;
				}
				$ctx = self::context( [
					'order' => $object instanceof WC_Order ? $object : null,
					'user'  => $object instanceof WP_User ? $object : null,
				] );
				return wp_strip_all_tags( WHD_Blocks::merge( $design['settings']['subject'], $ctx ) );
			}, 20, 2 );
		}
	}

	/** Called from templates/wc-email.php with the variables WooCommerce passed to the template. */
	public static function render_wc( $email, $vars ) {
		$id     = $email->id;
		$design = self::get( $id ) ?: self::default_design( $id );
		$args   = [ 'email' => $email, 'vars' => $vars ];
		if ( ! empty( $vars['order'] ) && $vars['order'] instanceof WC_Order ) {
			$args['order'] = $vars['order'];
		}
		if ( ! empty( $vars['user_id'] ) ) {
			$args['user'] = get_user_by( 'id', (int) $vars['user_id'] );
		} elseif ( ! empty( $vars['user_login'] ) ) {
			$args['user'] = get_user_by( 'login', $vars['user_login'] );
		}
		$data = [];
		if ( ! empty( $vars['reset_key'] ) && ! empty( $vars['user_id'] ) ) {
			$data['reset_url'] = add_query_arg( [ 'key' => $vars['reset_key'], 'id' => (int) $vars['user_id'] ], wc_get_endpoint_url( 'lost-password', '', wc_get_page_permalink( 'myaccount' ) ) );
		}
		if ( ! empty( $vars['set_password_url'] ) ) {
			$data['set_password_url'] = $vars['set_password_url'];
		}
		if ( ! empty( $vars['user_login'] ) ) {
			$data['user_login'] = $vars['user_login'];
		}
		if ( isset( $vars['customer_note'] ) ) {
			$data['customer_note'] = wptexturize( wp_kses_post( nl2br( $vars['customer_note'] ) ) );
		}
		$args['data'] = $data;
		return WHD_Blocks::render( $design, 'email', self::context( $args ) );
	}

	/* ─────────────────────────── merge context ─────────────────────────── */

	/**
	 * Build the render context: merge-tag values plus line items / totals.
	 * $args: order (WC_Order), user (WP_User), cart (row from whd_carts), data (extra tags)
	 */
	public static function context( $args = [] ) {
		$wc       = function_exists( 'wc_get_page_permalink' );
		$settings = WHD_Plugin::settings();
		$data     = [
			'site_name'      => get_bloginfo( 'name' ),
			'site_url'       => home_url( '/' ),
			'shop_url'       => $wc ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' ),
			'cart_url'       => $wc ? wc_get_cart_url() : home_url( '/cart/' ),
			'checkout_url'   => $wc ? wc_get_checkout_url() : home_url( '/checkout/' ),
			'my_account_url' => $wc ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' ),
			'coupon_code'    => $settings['cart_coupon'],
			'year'           => date_i18n( 'Y' ),
			'first_name'     => __( 'there', 'whd' ),
			'customer_name'  => '',
			'email'          => '',
		];
		$ctx = [ 'data' => $data, 'items' => [], 'totals' => [], 'cart_items' => [] ];

		if ( ! empty( $args['user'] ) && $args['user'] instanceof WP_User ) {
			$u = $args['user'];
			$ctx['data'] = array_merge( $ctx['data'], [
				'customer_name' => $u->display_name,
				'first_name'    => $u->first_name ?: $u->display_name,
				'email'         => $u->user_email,
				'user_login'    => $u->user_login,
			] );
		}

		if ( ! empty( $args['order'] ) && $args['order'] instanceof WC_Order ) {
			$o     = $args['order'];
			$first = $o->get_billing_first_name();
			$ctx['data'] = array_merge( $ctx['data'], [
				'customer_name'    => trim( $o->get_formatted_billing_full_name() ) ?: $o->get_billing_email(),
				'first_name'       => $first ?: __( 'there', 'whd' ),
				'last_name'        => $o->get_billing_last_name(),
				'email'            => $o->get_billing_email(),
				'order_number'     => $o->get_order_number(),
				'order_date'       => wc_format_datetime( $o->get_date_created() ),
				'order_total'      => wp_strip_all_tags( $o->get_formatted_order_total() ),
				'order_status'     => wc_get_order_status_name( $o->get_status() ),
				'order_url'        => $o->get_view_order_url(),
				'payment_url'      => $o->get_checkout_payment_url(),
				'admin_order_url'  => $o->get_edit_order_url(),
				'shipping_method'  => $o->get_shipping_method(),
				'customer_note'    => $ctx['data']['customer_note'] ?? wptexturize( wp_kses_post( nl2br( $o->get_customer_note() ) ) ),
			] );
			foreach ( $o->get_items() as $item ) {
				$product = $item->get_product();
				$ctx['items'][] = [
					'name'  => $item->get_name(),
					'qty'   => $item->get_quantity(),
					'total' => $o->get_formatted_line_subtotal( $item ),
					'image' => $product ? wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) : '',
					'url'   => $product ? $product->get_permalink() : '',
					'meta'  => wp_strip_all_tags( wc_display_item_meta( $item, [ 'echo' => false, 'separator' => ', ', 'before' => '', 'after' => '', 'label_before' => '', 'label_after' => ': ' ] ) ),
				];
			}
			foreach ( $o->get_order_item_totals() as $row ) {
				$ctx['totals'][ $row['label'] ] = $row['value'];
			}
			$ctx['billing_address']  = $o->get_formatted_billing_address();
			$ctx['shipping_address'] = $o->get_formatted_shipping_address( __( 'Same as billing', 'whd' ) );
		}

		if ( ! empty( $args['cart'] ) ) {
			$c = (object) $args['cart'];
			$ctx['data'] = array_merge( $ctx['data'], [
				'first_name'      => $c->first_name ?: __( 'there', 'whd' ),
				'customer_name'   => $c->first_name,
				'email'           => $c->email,
				'cart_total'      => $wc ? wp_strip_all_tags( wc_price( (float) $c->total ) ) : (string) $c->total,
				'recovery_url'    => class_exists( 'WHD_Cart' ) ? WHD_Cart::recovery_url( $c->token ) : '',
				'unsubscribe_url' => class_exists( 'WHD_Cart' ) ? WHD_Cart::unsubscribe_url( $c->email ) : '',
			] );
			$ctx['cart_items'] = is_array( $c->items ) ? $c->items : (array) json_decode( (string) $c->items, true );
		}

		if ( ! empty( $args['data'] ) && is_array( $args['data'] ) ) {
			$ctx['data'] = array_merge( $ctx['data'], $args['data'] );
		}
		return apply_filters( 'whd_email_context', $ctx, $args );
	}

	/** Context for the admin live preview / test email: latest order or realistic sample data. */
	public static function preview_context( $id ) {
		$args = [ 'data' => [
			'reset_url'        => home_url( '/my-account/lost-password/?key=sample&id=1' ),
			'set_password_url' => home_url( '/my-account/lost-password/' ),
			'user_login'       => 'customer',
			'customer_note'    => __( 'Your parcel is on its way — we added a little something extra.', 'whd' ),
			'unsubscribe_url'  => home_url( '/?whd_unsub=sample' ),
			'recovery_url'     => home_url( '/?whd_recover=sample' ),
		] ];
		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders( [ 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order' ] );
			if ( $orders ) {
				$args['order'] = $orders[0];
			}
		}
		$user = wp_get_current_user();
		if ( $user && $user->ID ) {
			$args['user'] = $user;
		}
		if ( in_array( $id, [ 'abandoned_cart', 'welcome_subscriber' ], true ) ) {
			$args['cart'] = self::sample_cart();
		}
		$ctx = self::context( $args );
		if ( empty( $ctx['items'] ) ) { // no orders yet: fake a couple of lines so item blocks preview
			$ctx['items']  = self::sample_cart()['items'];
			$ctx['totals'] = [ __( 'Subtotal', 'whd' ) => '$118.00', __( 'Shipping', 'whd' ) => __( 'Free', 'whd' ), __( 'Total', 'whd' ) => '$118.00' ];
			$ctx['data']   = array_merge( $ctx['data'], [ 'order_number' => '1024', 'order_date' => date_i18n( get_option( 'date_format' ) ), 'order_total' => '$118.00', 'order_status' => __( 'Processing', 'whd' ), 'order_url' => $ctx['data']['my_account_url'], 'payment_url' => $ctx['data']['checkout_url'], 'admin_order_url' => admin_url( 'admin.php?page=wc-orders' ), 'shipping_method' => __( 'Standard shipping', 'whd' ), 'first_name' => 'Anna', 'customer_name' => 'Anna Reyes', 'email' => 'anna@example.com' ] );
		}
		return $ctx;
	}

	/** A sample cart built from real products (for previews). */
	public static function sample_cart() {
		$items = [];
		if ( function_exists( 'wc_get_products' ) ) {
			foreach ( wc_get_products( [ 'limit' => 2, 'status' => 'publish', 'orderby' => 'date', 'order' => 'DESC' ] ) as $p ) {
				$items[] = [ 'name' => $p->get_name(), 'qty' => 1, 'total' => wc_price( (float) $p->get_price() ), 'image' => wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' ), 'url' => $p->get_permalink(), 'product_id' => $p->get_id(), 'variation_id' => 0 ];
			}
		}
		if ( ! $items ) {
			$items = [ [ 'name' => 'Pleated satin midi skirt', 'qty' => 1, 'total' => '$68.00', 'image' => '', 'url' => '' ], [ 'name' => 'Linen shirt in black', 'qty' => 1, 'total' => '$50.00', 'image' => '', 'url' => '' ] ];
		}
		return [ 'first_name' => 'Anna', 'email' => 'anna@example.com', 'items' => $items, 'total' => 118, 'token' => 'sample' ];
	}

	/* ─────────────────────────── sending custom triggers ─────────────────────────── */

	public static function send_custom( $id, $ctx, $to, $force = false ) {
		$design = self::get( $id );
		if ( ! $design || ( ! $force && empty( $design['settings']['enabled'] ) ) ) {
			return false;
		}
		$settings = WHD_Plugin::settings();
		$subject  = wp_strip_all_tags( WHD_Blocks::merge( $design['settings']['subject'] ?: get_bloginfo( 'name' ), $ctx ) );
		$html     = WHD_Blocks::render( $design, 'email', $ctx );
		$headers  = [ 'Content-Type: text/html; charset=UTF-8' ];
		if ( is_email( $settings['from_email'] ) ) {
			$headers[] = 'From: ' . sanitize_text_field( $settings['from_name'] ) . ' <' . $settings['from_email'] . '>';
		}
		return wp_mail( $to, $subject, $html, $headers );
	}
}
