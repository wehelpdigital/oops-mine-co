<?php
/**
 * Abandoned-cart tracking and recovery.
 *
 *  capture  → logged-in customers automatically; guests once they type their
 *             email on the checkout page (small inline script → AJAX).
 *  cron     → every 15 min: carts untouched for N hours get the "abandoned_cart"
 *             email (built in the email editor) with a one-click restore link.
 *  recover  → /?whd_recover=TOKEN rebuilds the cart and lands on checkout.
 *  convert  → a new order for that email marks the cart recovered.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Cart {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'whd_carts';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(190) NOT NULL,
			first_name varchar(100) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			items longtext NULL,
			item_count int unsigned NOT NULL DEFAULT 0,
			total decimal(12,2) NOT NULL DEFAULT 0,
			token varchar(40) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			sent_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY email (email),
			KEY status_updated (status, updated_at)
		) $charset;" );
	}

	public static function init() {
		// Keep the snapshot fresh while the cart changes.
		foreach ( [ 'woocommerce_add_to_cart', 'woocommerce_cart_item_removed', 'woocommerce_cart_item_restored', 'woocommerce_after_cart_item_quantity_update', 'woocommerce_cart_emptied' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'sync' ], 20 );
		}
		add_action( 'wp_ajax_whd_capture', [ __CLASS__, 'ajax_capture' ] );
		add_action( 'wp_ajax_nopriv_whd_capture', [ __CLASS__, 'ajax_capture' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'checkout_script' ] );
		add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'on_order' ], 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'on_order' ], 10, 1 );
		add_action( 'whd_cart_cron', [ __CLASS__, 'cron' ] );
		add_action( 'template_redirect', [ __CLASS__, 'handle_links' ] );
		add_action( 'admin_post_whd_send_cart', [ __CLASS__, 'admin_send_now' ] );
	}

	/* ─────────────────────────── capture ─────────────────────────── */

	/** Current cart as a storable snapshot. */
	public static function snapshot() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return [ 'items' => [], 'count' => 0, 'total' => 0 ];
		}
		$items = [];
		foreach ( WC()->cart->get_cart() as $line ) {
			$product = $line['data'];
			if ( ! $product ) {
				continue;
			}
			$items[] = [
				'product_id'   => (int) $line['product_id'],
				'variation_id' => (int) ( $line['variation_id'] ?? 0 ),
				'variation'    => (array) ( $line['variation'] ?? [] ),
				'qty'          => (int) $line['quantity'],
				'name'         => $product->get_name(),
				'total'        => wc_price( (float) $line['line_subtotal'] ),
				'image'        => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ),
				'url'          => $product->get_permalink(),
			];
		}
		return [ 'items' => $items, 'count' => WC()->cart->get_cart_contents_count(), 'total' => (float) WC()->cart->get_subtotal() ];
	}

	/** Who is this cart for? Logged-in user, or a guest we captured on checkout. */
	private static function identity() {
		if ( is_user_logged_in() ) {
			$u = wp_get_current_user();
			return [ 'email' => $u->user_email, 'first_name' => $u->first_name ?: $u->display_name, 'user_id' => $u->ID ];
		}
		if ( function_exists( 'WC' ) && WC()->session ) {
			$guest = WC()->session->get( 'whd_guest' );
			if ( ! empty( $guest['email'] ) ) {
				return [ 'email' => $guest['email'], 'first_name' => $guest['first_name'] ?? '', 'user_id' => 0 ];
			}
		}
		return null;
	}

	public static function sync() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		$who = self::identity();
		if ( ! $who || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		global $wpdb;
		$snap  = self::snapshot();
		$table = self::table();
		$now   = current_time( 'mysql' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM $table WHERE email = %s AND status IN ('active','sent') ORDER BY id DESC LIMIT 1", $who['email'] ) );

		if ( ! $snap['count'] ) {
			if ( $row ) {
				$wpdb->update( $table, [ 'status' => 'emptied', 'updated_at' => $now ], [ 'id' => $row->id ] );
			}
			return;
		}
		$data = [
			'email'      => $who['email'],
			'first_name' => $who['first_name'],
			'user_id'    => $who['user_id'],
			'items'      => wp_json_encode( $snap['items'] ),
			'item_count' => $snap['count'],
			'total'      => $snap['total'],
			'updated_at' => $now,
		];
		if ( $row ) {
			// A cart that changes after the reminder went out becomes "active" again (one more reminder, later).
			$wpdb->update( $table, $data + [ 'status' => 'active' ], [ 'id' => $row->id ] );
		} else {
			$wpdb->insert( $table, $data + [ 'token' => wp_generate_password( 32, false ), 'status' => 'active', 'created_at' => $now ] );
		}
	}

	public static function ajax_capture() {
		check_ajax_referer( 'whd_capture', 'nonce' );
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) || ! function_exists( 'WC' ) || ! WC()->session ) {
			wp_send_json_error();
		}
		WC()->session->set( 'whd_guest', [ 'email' => $email, 'first_name' => isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '' ] );
		self::sync();
		wp_send_json_success();
	}

	/** Tiny inline script on the checkout page: send email + first name once typed. */
	public static function checkout_script() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_user_logged_in() || is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}
		$js = "(function(){var t,f=function(){var e=document.querySelector('#billing_email,#email,input[type=email]');if(!e||!/^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$/.test(e.value))return;var n=document.querySelector('#billing_first_name,#billing-first_name');var d=new FormData();d.append('action','whd_capture');d.append('nonce','" . esc_js( wp_create_nonce( 'whd_capture' ) ) . "');d.append('email',e.value);d.append('first_name',n?n.value:'');fetch('" . esc_url( admin_url( 'admin-ajax.php' ) ) . "',{method:'POST',body:d,credentials:'same-origin'});};document.addEventListener('change',function(ev){if(ev.target&&ev.target.matches('#billing_email,#email,input[type=email],#billing_first_name,#billing-first_name')){clearTimeout(t);t=setTimeout(f,600);}},true);})();";
		wp_register_script( 'whd-capture', '', [], WHD_VERSION, true );
		wp_enqueue_script( 'whd-capture' );
		wp_add_inline_script( 'whd-capture', $js );
	}

	/* ─────────────────────────── conversion / cron ─────────────────────────── */

	public static function on_order( $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order ) {
			return;
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table() . " SET status = 'recovered', order_id = %d, updated_at = %s WHERE email = %s AND status IN ('active','sent','clicked')",
			$order->get_id(), current_time( 'mysql' ), $order->get_billing_email()
		) );
	}

	public static function cron() {
		global $wpdb;
		$settings = WHD_Plugin::settings();
		$hours    = max( 0.25, (float) $settings['cart_delay_hours'] );
		$cutoff   = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - (int) ( $hours * HOUR_IN_SECONDS ) );
		$rows     = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE status = 'active' AND item_count > 0 AND updated_at < %s ORDER BY updated_at ASC LIMIT 50", $cutoff ) );
		$unsub    = get_option( 'whd_unsubscribed', [] );
		foreach ( $rows as $row ) {
			if ( in_array( strtolower( $row->email ), $unsub, true ) ) {
				$wpdb->update( self::table(), [ 'status' => 'unsubscribed' ], [ 'id' => $row->id ] );
				continue;
			}
			$sent = self::send_reminder( $row );
			$wpdb->update( self::table(), [ 'status' => $sent ? 'sent' : 'failed', 'sent_at' => current_time( 'mysql' ) ], [ 'id' => $row->id ] );
		}
	}

	public static function send_reminder( $row, $force = false ) {
		$row = (array) $row;
		$row['items'] = is_array( $row['items'] ) ? $row['items'] : (array) json_decode( (string) $row['items'], true );
		$ctx = WHD_Emails::context( [ 'cart' => $row ] );
		return WHD_Emails::send_custom( 'abandoned_cart', $ctx, $row['email'], $force );
	}

	public static function admin_send_now() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'whd_send_cart' ) ) {
			wp_die( 'Not allowed' );
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $_GET['id'] ) );
		$ok  = $row ? self::send_reminder( $row, true ) : false;
		if ( $row ) {
			$wpdb->update( self::table(), [ 'status' => $ok ? 'sent' : 'failed', 'sent_at' => current_time( 'mysql' ) ], [ 'id' => $row->id ] );
		}
		wp_safe_redirect( add_query_arg( [ 'page' => 'whd-carts', 'sent' => $ok ? 1 : 0 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ─────────────────────────── links ─────────────────────────── */

	public static function recovery_url( $token ) {
		return add_query_arg( 'whd_recover', rawurlencode( $token ), home_url( '/' ) );
	}

	public static function unsubscribe_url( $email ) {
		return add_query_arg( 'whd_unsub', rawurlencode( base64_encode( $email . '|' . wp_hash( $email ) ) ), home_url( '/' ) );
	}

	public static function handle_links() {
		if ( isset( $_GET['whd_unsub'] ) ) {
			$parts = explode( '|', (string) base64_decode( rawurldecode( $_GET['whd_unsub'] ) ) );
			if ( count( $parts ) === 2 && hash_equals( wp_hash( $parts[0] ), $parts[1] ) ) {
				$list   = get_option( 'whd_unsubscribed', [] );
				$list[] = strtolower( sanitize_email( $parts[0] ) );
				update_option( 'whd_unsubscribed', array_values( array_unique( $list ) ), false );
				if ( function_exists( 'wc_add_notice' ) ) {
					wc_add_notice( __( 'You will not receive further cart reminders from us.', 'whd' ), 'success' );
				}
			}
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		if ( isset( $_GET['whd_recover'] ) && function_exists( 'WC' ) ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token = %s', sanitize_text_field( wp_unslash( $_GET['whd_recover'] ) ) ) );
			if ( $row && ! in_array( $row->status, [ 'recovered' ], true ) ) {
				$items = (array) json_decode( (string) $row->items, true );
				if ( WC()->cart ) {
					WC()->cart->empty_cart();
					foreach ( $items as $it ) {
						try {
							WC()->cart->add_to_cart( (int) $it['product_id'], max( 1, (int) $it['qty'] ), (int) ( $it['variation_id'] ?? 0 ), (array) ( $it['variation'] ?? [] ) );
						} catch ( Exception $e ) { /* product gone — skip */ }
					}
				}
				if ( WC()->session && ! is_user_logged_in() ) {
					WC()->session->set( 'whd_guest', [ 'email' => $row->email, 'first_name' => $row->first_name ] );
				}
				$wpdb->update( self::table(), [ 'status' => 'clicked', 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $row->id ] );
				if ( function_exists( 'wc_add_notice' ) ) {
					wc_add_notice( __( 'Welcome back — your bag is just how you left it.', 'whd' ), 'success' );
				}
			}
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}
	}

	/* ─────────────────────────── admin helpers ─────────────────────────── */

	public static function recent( $limit = 50 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY updated_at DESC LIMIT %d', $limit ) );
	}

	public static function stats() {
		global $wpdb;
		$t = self::table();
		return [
			'active'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE status = 'active'" ),
			'sent'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE status IN ('sent','clicked')" ),
			'recovered' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE status = 'recovered'" ),
		];
	}
}
