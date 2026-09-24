<?php
/**
 * Abandoned-cart tracking and recovery.
 *
 *  capture  → logged-in customers automatically; guests once they type their
 *             email (and phone) on the checkout page (small inline script → AJAX).
 *  cron     → every 15 min: a three-step email sequence per cart —
 *             step 1 after `cart_delay_hours` (default 1 h), step 2 after 24 h,
 *             step 3 (the 10% incentive) after 48 h, all measured from the last
 *             cart activity. One SMS may go out 2 h after abandonment when the
 *             SMS integration is on and the shopper agreed to texts.
 *  recover  → /?whd_recover=TOKEN rebuilds the cart and lands on checkout.
 *  convert  → a new order for that email marks the cart recovered.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Cart {

	/** Bump when the table changes; the upgrade runs dbDelta once on init. */
	const DB_VERSION = '2';

	/** step → email trigger. step 3 = the sequence is finished. */
	const STEPS = [ 0 => 'abandoned_cart', 1 => 'abandoned_cart_2', 2 => 'abandoned_cart_3' ];

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
			step tinyint NOT NULL DEFAULT 0,
			phone varchar(32) NOT NULL DEFAULT '',
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			sent_at datetime NULL,
			sms_sent_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY email (email),
			KEY status_updated (status, updated_at)
		) $charset;" );
		update_option( 'whd_cart_db_version', self::DB_VERSION, false );
	}

	/** Runs once per version bump: adds step / phone / sms_sent_at to older installs. */
	public static function maybe_upgrade() {
		if ( (string) get_option( 'whd_cart_db_version', '1' ) === self::DB_VERSION ) {
			return;
		}
		self::install();
		if ( class_exists( 'WHD_Emails' ) ) {
			WHD_Emails::ensure_defaults(); // the 24 h / 48 h designs arrive with this upgrade
		}
	}

	public static function init() {
		add_action( 'init', [ __CLASS__, 'maybe_upgrade' ] );
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
		$sum   = 0.0;
		foreach ( WC()->cart->get_cart() as $line ) {
			$product = $line['data'];
			if ( ! $product ) {
				continue;
			}
			$qty = max( 1, (int) $line['quantity'] );
			// line_subtotal only exists once WooCommerce has run the totals; on a cart
			// snapshotted straight after add_to_cart (REST, CLI) fall back to the price.
			$subtotal = isset( $line['line_subtotal'] ) ? (float) $line['line_subtotal'] : (float) $product->get_price() * $qty;
			$sum     += $subtotal;
			$items[]  = [
				'product_id'   => (int) $line['product_id'],
				'variation_id' => (int) ( $line['variation_id'] ?? 0 ),
				'variation'    => (array) ( $line['variation'] ?? [] ),
				'qty'          => $qty,
				'name'         => $product->get_name(),
				'total'        => wc_price( $subtotal ),
				'image'        => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ),
				'url'          => $product->get_permalink(),
			];
		}
		$total = (float) WC()->cart->get_subtotal();
		return [ 'items' => $items, 'count' => WC()->cart->get_cart_contents_count(), 'total' => $total ?: $sum ];
	}

	/** Who is this cart for? Logged-in user, or a guest we captured on checkout. */
	private static function identity() {
		if ( is_user_logged_in() ) {
			$u = wp_get_current_user();
			return [
				'email'      => $u->user_email,
				'first_name' => $u->first_name ?: $u->display_name,
				'user_id'    => $u->ID,
				'phone'      => self::clean_phone( get_user_meta( $u->ID, 'billing_phone', true ) ),
			];
		}
		if ( function_exists( 'WC' ) && WC()->session ) {
			$guest = WC()->session->get( 'whd_guest' );
			if ( ! empty( $guest['email'] ) ) {
				return [ 'email' => $guest['email'], 'first_name' => $guest['first_name'] ?? '', 'user_id' => 0, 'phone' => self::clean_phone( $guest['phone'] ?? '' ) ];
			}
		}
		return null;
	}

	/** Keep digits and the punctuation a phone number is written with; 32 chars max (column width). */
	public static function clean_phone( $raw ) {
		$raw = preg_replace( '/[^0-9+()\-. ]/', '', (string) $raw );
		return substr( trim( (string) $raw ), 0, 32 );
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
		// 'emptied' and 'clicked' belong here too. Restoring a cart from a recovery link empties the
		// cart first, which flips the row to 'emptied'; if the refill then failed to find it we would
		// insert a second row at step 0 and the shopper would receive the whole sequence twice.
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM $table WHERE email = %s AND status IN ('active','sent','failed','clicked','emptied') ORDER BY id DESC LIMIT 1", $who['email'] ) );

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
		if ( ! empty( $who['phone'] ) ) {
			$data['phone'] = $who['phone']; // never blank a number we already have
		}
		if ( $row ) {
			// A cart that changes after the reminder went out becomes "active" again; the step it
			// reached is kept, so a shopper who keeps editing the bag never gets the same email twice.
			$wpdb->update( $table, $data + [ 'status' => 'active' ], [ 'id' => $row->id ] );
		} else {
			$wpdb->insert( $table, $data + [ 'token' => wp_generate_password( 32, false ), 'status' => 'active', 'step' => 0, 'created_at' => $now ] );
		}
	}

	public static function ajax_capture() {
		check_ajax_referer( 'whd_capture', 'nonce' );
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) || ! function_exists( 'WC' ) || ! WC()->session ) {
			wp_send_json_error();
		}
		$guest = (array) WC()->session->get( 'whd_guest' );
		$phone = isset( $_POST['phone'] ) ? self::clean_phone( wp_unslash( $_POST['phone'] ) ) : '';
		WC()->session->set( 'whd_guest', [
			'email'      => $email,
			'first_name' => isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : ( $guest['first_name'] ?? '' ),
			'phone'      => $phone ?: ( $guest['phone'] ?? '' ),
		] );
		self::sync();
		wp_send_json_success();
	}

	/** Tiny inline script on the checkout page: send email + first name + phone once typed. */
	public static function checkout_script() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_user_logged_in() || is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}
		$watch = '#billing_email,#email,input[type=email],#billing_first_name,#billing-first_name,#billing_phone,#billing-phone,#phone,input[type=tel]';
		$js    = "(function(){var t,f=function(){var e=document.querySelector('#billing_email,#email,input[type=email]');if(!e||!/^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$/.test(e.value))return;var n=document.querySelector('#billing_first_name,#billing-first_name');var p=document.querySelector('#billing_phone,#billing-phone,#phone,input[type=tel]');var d=new FormData();d.append('action','whd_capture');d.append('nonce','" . esc_js( wp_create_nonce( 'whd_capture' ) ) . "');d.append('email',e.value);d.append('first_name',n?n.value:'');d.append('phone',p?p.value:'');fetch('" . esc_url( admin_url( 'admin-ajax.php' ) ) . "',{method:'POST',body:d,credentials:'same-origin'});};document.addEventListener('change',function(ev){if(ev.target&&ev.target.matches('" . $watch . "')){clearTimeout(t);t=setTimeout(f,600);}},true);})();";
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
			'UPDATE ' . self::table() . " SET status = 'recovered', order_id = %d, updated_at = %s WHERE email = %s AND status IN ('active','sent','clicked','failed')",
			$order->get_id(), current_time( 'mysql' ), $order->get_billing_email()
		) );
	}

	/**
	 * Hours after the last cart activity for each step of the sequence.
	 * Filter `whd_cart_sequence` to change the timing (values are hours).
	 */
	public static function sequence() {
		$settings = WHD_Plugin::settings();
		$seq      = apply_filters( 'whd_cart_sequence', [
			'step1' => max( 0.25, (float) ( $settings['cart_delay_hours'] ?? 1 ) ), // friendly reminder
			'step2' => 24,                                                          // still available
			'step3' => 48,                                                          // 10% incentive
			'sms'   => 2,                                                           // single text message
		] );
		$out = [];
		foreach ( [ 'step1' => 1, 'step2' => 24, 'step3' => 48, 'sms' => 2 ] as $key => $fallback ) {
			$out[ $key ] = max( 0.0, (float) ( $seq[ $key ] ?? $fallback ) );
		}
		return $out;
	}

	/**
	 * The three-step sequence. Timing is measured from `updated_at` (the last time the
	 * shopper touched the bag), so a step that is already overdue when the cron wakes up
	 * goes out on the next run; steps never run twice because `step` is stored on the row.
	 */
	public static function cron() {
		global $wpdb;
		$table  = self::table();
		$seq    = self::sequence();
		$now    = strtotime( current_time( 'mysql' ) );
		$sms_on = self::sms_ready();
		$window = gmdate( 'Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS );
		// Only rows that can actually do work this run. Without the age tests in SQL, finished carts
		// that merely carry a phone number match forever, and because the batch is ordered oldest
		// first they fill the LIMIT and new carts never get their first reminder.
		$ago = static function ( $hours ) use ( $now ) {
			return gmdate( 'Y-m-d H:i:s', $now - (int) $hours * HOUR_IN_SECONDS );
		};
		$due_email = $wpdb->prepare(
			'( ( step = 0 AND updated_at <= %s ) OR ( step = 1 AND updated_at <= %s ) OR ( step = 2 AND updated_at <= %s ) )',
			$ago( $seq['step1'] ),
			$ago( $seq['step2'] ),
			$ago( $seq['step3'] )
		);
		// A send that failed is retried on the hour, not on every run.
		$due_email = '( ' . $due_email . $wpdb->prepare(
			" AND ( status <> 'failed' OR sent_at IS NULL OR sent_at <= %s ) )",
			$ago( 1 )
		);
		if ( $sms_on ) {
			$due_sms = $wpdb->prepare(
				"( phone <> '' AND sms_sent_at IS NULL AND updated_at BETWEEN %s AND %s )",
				gmdate( 'Y-m-d H:i:s', $now - (int) $seq['sms'] * HOUR_IN_SECONDS - DAY_IN_SECONDS ),
				gmdate( 'Y-m-d H:i:s', $now - (int) $seq['sms'] * HOUR_IN_SECONDS )
			);
			$where = "( $due_email OR $due_sms )";
		} else {
			$where = $due_email;
		}
		$rows   = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table WHERE item_count > 0 AND status IN ('active','sent','clicked','failed') AND $where AND updated_at > %s ORDER BY updated_at ASC LIMIT 100",
			$window
		) );
		$unsub = (array) get_option( 'whd_unsubscribed', [] );

		foreach ( $rows as $row ) {
			if ( in_array( strtolower( $row->email ), $unsub, true ) ) {
				$wpdb->update( $table, [ 'status' => 'unsubscribed' ], [ 'id' => $row->id ] );
				continue;
			}
			$age = $now - strtotime( $row->updated_at );

			// One text message, 2 h after the bag was left (inside a one-day window, so a
			// gateway outage cannot turn into a retry loop for old carts).
			if ( $sms_on && $age >= $seq['sms'] * HOUR_IN_SECONDS && $age <= $seq['sms'] * HOUR_IN_SECONDS + DAY_IN_SECONDS ) {
				self::maybe_send_sms( $row );
			}

			$step = max( 0, (int) $row->step );
			if ( $step > 2 ) {
				continue;
			}
			if ( $age < $seq[ 'step' . ( $step + 1 ) ] * HOUR_IN_SECONDS ) {
				continue;
			}
			// A send that failed (the mailer was down) is tried again on the hour instead of
			// dropping the cart out of the sequence for good.
			if ( 'failed' === $row->status && ! empty( $row->sent_at ) && strtotime( $row->sent_at ) > $now - HOUR_IN_SECONDS ) {
				continue;
			}
			$sent = self::send_step( $row, $step );
			$wpdb->update( $table, [
				'status'  => $sent ? 'sent' : 'failed',
				'step'    => $sent ? $step + 1 : $step,
				'sent_at' => current_time( 'mysql' ),
			], [ 'id' => $row->id ] );
		}
	}

	/** Trigger id for a step index (0-based): 0 → abandoned_cart, 1 → …_2, 2 → …_3. */
	public static function trigger_for_step( $step ) {
		$step = max( 0, min( 2, (int) $step ) );
		return self::STEPS[ $step ];
	}

	/** Send one step of the sequence to the shopper on this row. */
	public static function send_step( $row, $step, $force = false ) {
		$row          = (array) $row;
		$row['items'] = is_array( $row['items'] ?? null ) ? $row['items'] : (array) json_decode( (string) ( $row['items'] ?? '' ), true );
		$ctx          = WHD_Emails::context( [ 'cart' => $row ] );
		return WHD_Emails::send_custom( self::trigger_for_step( $step ), $ctx, $row['email'], $force );
	}

	/** Back-compat wrapper: sends the step this row is waiting for. */
	public static function send_reminder( $row, $force = false ) {
		$row = (array) $row;
		return self::send_step( $row, isset( $row['step'] ) ? (int) $row['step'] : 0, $force );
	}

	/* ─────────────────────────── SMS ─────────────────────────── */

	/** Is the SMS integration configured? (The integrations module is optional.) */
	public static function sms_ready() {
		return class_exists( 'WHD_Integrations' ) && method_exists( 'WHD_Integrations', 'sms_enabled' ) && WHD_Integrations::sms_enabled();
	}

	/**
	 * Did this address agree to text messages? Asked of the subscribers table, which may
	 * not have the column yet (the integrations module adds it) — checked once per request.
	 */
	public static function sms_consent( $email ) {
		global $wpdb;
		static $has_column = null;
		if ( ! class_exists( 'WHD_Subscribers' ) ) {
			return false;
		}
		$table = WHD_Subscribers::table();
		if ( null === $has_column ) {
			$has_column = false;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				$has_column = (bool) $wpdb->get_var( "SHOW COLUMNS FROM `$table` LIKE 'sms_consent'" );
			}
		}
		if ( ! $has_column ) {
			return false;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT sms_consent FROM `$table` WHERE email = %s LIMIT 1", strtolower( (string) $email ) ) );
	}

	/** Brand-voice text, 160 characters at most, with the restore link. */
	public static function sms_text( $row ) {
		$row  = (object) (array) $row;
		$name = trim( (string) ( $row->first_name ?? '' ) );
		$cut  = static function ( $text, $len ) {
			return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $len ) : substr( $text, 0, $len );
		};
		$len  = static function ( $text ) {
			return function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		};

		/*
		 * The link and the opt-out sentence are not optional: a marketing text without "Reply STOP"
		 * is a carrier and TCPA problem, and a clipped URL is a dead link. So the tail is built
		 * first and only the greeting is shortened to fit — never the other way round.
		 */
		$tail = sprintf(
			/* translators: 1: recovery link */
			__( 'Grab it: %1$s Reply STOP to opt out.', 'whd' ),
			self::recovery_url( $row->token )
		);
		$head = sprintf(
			/* translators: 1: first name or "Your", 2: store name */
			__( '%1$s bag at %2$s is still here.', 'whd' ),
			$name ? $name . ', your' : __( 'Your', 'whd' ),
			get_bloginfo( 'name' )
		);

		$room = 160 - $len( $tail ) - 1; // the space that joins them
		if ( $room < 1 ) {
			// Nothing but the tail fits; send that rather than a broken link.
			$text = $cut( $tail, 160 );
		} else {
			if ( $len( $head ) > $room ) {
				// Drop the name first, then trim what is left.
				$head = sprintf( __( '%1$s bag at %2$s is still here.', 'whd' ), __( 'Your', 'whd' ), get_bloginfo( 'name' ) );
				if ( $len( $head ) > $room ) {
					$head = rtrim( $cut( $head, $room ) );
				}
			}
			$text = $head . ' ' . $tail;
		}

		/**
		 * Filter the recovery text message.
		 *
		 * Anything returned here is sent as-is, so keep the opt-out wording and the link intact.
		 *
		 * @param string $text The message.
		 * @param object $row  The cart row.
		 */
		return apply_filters( 'whd_cart_sms_text', $text, $row );
	}

	/** One text per cart: needs the integration on, a number on the row and a recorded consent. */
	public static function maybe_send_sms( $row ) {
		global $wpdb;
		$phone = self::clean_phone( $row->phone ?? '' );
		if ( '' === $phone || ! self::sms_ready() || ! self::sms_consent( $row->email ) ) {
			return false;
		}
		if ( ! empty( $row->sms_sent_at ) && '0000-00-00 00:00:00' !== $row->sms_sent_at ) {
			return false;
		}
		$sent = (bool) WHD_Integrations::send_sms( $phone, self::sms_text( $row ) );
		if ( $sent ) {
			$wpdb->update( self::table(), [ 'sms_sent_at' => current_time( 'mysql' ) ], [ 'id' => (int) $row->id ] );
		}
		return $sent;
	}

	public static function admin_send_now() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'whd_send_cart' ) ) {
			wp_die( 'Not allowed' );
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', isset( $_GET['id'] ) ? (int) $_GET['id'] : 0 ) );
		$ok  = false;
		if ( $row ) {
			$step = max( 0, min( 2, (int) $row->step ) ); // the step this cart is waiting for
			$ok   = self::send_step( $row, $step, true );
			$wpdb->update( self::table(), [
				'status'  => $ok ? 'sent' : 'failed',
				'step'    => $ok ? max( (int) $row->step, $step + 1 ) : (int) $row->step,
				'sent_at' => current_time( 'mysql' ),
			], [ 'id' => $row->id ] );
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

	/**
	 * Where a cart stands in the sequence, as a label for the admin table.
	 * Accepts a row object or array; safe on rows saved before the column existed.
	 */
	public static function step_label( $row ) {
		$row    = (object) (array) $row;
		$step   = isset( $row->step ) ? max( 0, min( 3, (int) $row->step ) ) : 0;
		$labels = [
			0 => __( 'Waiting', 'whd' ),
			1 => __( 'Reminder 1 sent', 'whd' ),
			2 => __( 'Reminder 2 sent (24 h)', 'whd' ),
			3 => __( 'Reminder 3 sent (10% off)', 'whd' ),
		];
		$label = $labels[ $step ];
		if ( ! empty( $row->sms_sent_at ) && '0000-00-00 00:00:00' !== $row->sms_sent_at ) {
			$label .= ' · ' . __( 'SMS sent', 'whd' );
		}
		return $label;
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
