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

	/** Named here too, so the preview can fabricate the tags this one uses. */
	const TRIGGER_STOCK = 'back_in_stock';

	/** Every trigger the builder can customise. `template` = the WooCommerce HTML template it replaces. */
	public static function triggers() {
		return [
			'customer_processing_order' => [ 'label' => __( 'Order confirmation (processing)', 'whd' ), 'group' => 'customer', 'template' => 'emails/customer-processing-order.php' ],
			'customer_on_hold_order'    => [ 'label' => __( 'Order on hold (awaiting payment)', 'whd' ), 'group' => 'customer', 'template' => 'emails/customer-on-hold-order.php' ],
			'customer_completed_order'  => [ 'label' => __( 'Order completed / shipped', 'whd' ), 'group' => 'customer', 'template' => 'emails/customer-completed-order.php' ],
			'customer_refunded_order'   => [ 'label' => __( 'Order refunded', 'whd' ), 'group' => 'customer', 'template' => 'emails/customer-refunded-order.php' ],
			// WooCommerce 9+ also writes to the customer when an order fails or is cancelled.
			'customer_failed_order'     => [ 'label' => __( 'Order failed (payment did not go through)', 'whd' ), 'group' => 'customer', 'template' => 'emails/customer-failed-order.php' ],
			'customer_cancelled_order'  => [ 'label' => __( 'Order cancelled', 'whd' ), 'group' => 'customer', 'template' => 'emails/customer-cancelled-order.php' ],
			'customer_invoice'          => [ 'label' => __( 'Invoice / order details', 'whd' ), 'group' => 'customer', 'template' => 'emails/customer-invoice.php' ],
			'customer_note'             => [ 'label' => __( 'Note to customer', 'whd' ), 'group' => 'customer', 'template' => 'emails/customer-note.php' ],
			'customer_new_account'      => [ 'label' => __( 'New account welcome', 'whd' ), 'group' => 'customer', 'template' => 'emails/customer-new-account.php' ],
			'customer_reset_password'   => [ 'label' => __( 'Password reset', 'whd' ), 'group' => 'customer', 'template' => 'emails/customer-reset-password.php' ],
			'customer_verify_email'     => [ 'label' => __( 'Confirm email address', 'whd' ), 'group' => 'customer', 'template' => 'emails/customer-verify-email.php' ],
			'abandoned_cart'            => [ 'label' => __( 'Abandoned cart — 1 h: friendly reminder', 'whd' ), 'group' => 'automation', 'template' => '' ],
			'abandoned_cart_2'          => [ 'label' => __( 'Abandoned cart — 24 h: still available + social proof', 'whd' ), 'group' => 'automation', 'template' => '' ],
			'abandoned_cart_3'          => [ 'label' => __( 'Abandoned cart — 48 h: 10% incentive', 'whd' ), 'group' => 'automation', 'template' => '' ],
			'welcome_subscriber'        => [ 'label' => __( 'Newsletter welcome', 'whd' ), 'group' => 'automation', 'template' => '' ],
			'back_in_stock'             => [ 'label' => __( 'Back in stock — the size someone waited for', 'whd' ), 'group' => 'automation', 'template' => '' ],
			'stylist_edit'              => [ 'label' => __( 'Stylist — the edit someone was picked', 'whd' ), 'group' => 'automation', 'template' => '' ],
			'wheel_prize'               => [ 'label' => __( 'Spin to win — the prize code', 'whd' ), 'group' => 'automation', 'template' => '' ],
			'drop_open'                 => [ 'label' => __( 'Drop reminder — it is open', 'whd' ), 'group' => 'automation', 'template' => '' ],
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

	/**
	 * Fill in any design an install is missing (also on upgrade — the cart follow-ups
	 * were added later, and they arrive switched on so the sequence works out of the box).
	 */
	public static function ensure_defaults() {
		$stored   = get_option( self::OPTION, [] );
		// Step 1 has to be on with steps 2 and 3. The sequence advances one step per send, so a
		// disabled first email parks every cart at step 0: the send returns false, the row is
		// written back as 'failed', and steps 2 and 3 are never reached.
		// Back-in-stock is a reply to something a shopper asked for, so it ships enabled: an alert
		// nobody receives is worse than no alert button at all.
		// Both of these answer something a visitor asked for, so they ship enabled.
		// The prize code and the drop reminder are the only way either promise is kept, so they ship on too.
		$auto_on  = [ 'abandoned_cart', 'abandoned_cart_2', 'abandoned_cart_3', 'back_in_stock', 'stylist_edit', 'wheel_prize', 'drop_open' ];
		foreach ( self::triggers() as $id => $t ) {
			if ( empty( $stored[ $id ] ) ) {
				$stored[ $id ] = self::default_design( $id );
				if ( in_array( $id, $auto_on, true ) ) {
					$stored[ $id ]['settings']['enabled'] = 1;
				}
			}
		}
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Put the shipped designs back. `$ids` limits it to some triggers; the enabled flag
	 * is kept unless $keep_enabled is false. Called by the publish script.
	 */
	public static function reset_to_defaults( $ids = null, $keep_enabled = true ) {
		$stored = get_option( self::OPTION, [] );
		$ids    = null === $ids ? array_keys( self::triggers() ) : (array) $ids;
		foreach ( $ids as $id ) {
			if ( ! isset( self::triggers()[ $id ] ) ) {
				continue;
			}
			$enabled       = $keep_enabled && ! empty( $stored[ $id ]['settings']['enabled'] ) ? 1 : 0;
			$design        = self::default_design( $id );
			$design['settings']['enabled'] = $enabled ?: $design['settings']['enabled'];
			$stored[ $id ] = WHD_Blocks::sanitize_design( $design, 'email' );
		}
		update_option( self::OPTION, $stored, false );
		return count( $ids );
	}

	/** Brand-voice starter design per trigger. */
	public static function default_design( $id ) {
		$b = function ( $type, $props ) { return [ 'type' => $type, 'props' => $props ]; };
		$heading = function ( $text ) use ( $b ) { return $b( 'heading', [ 'text' => $text, 'level' => 'h2', 'align' => 'left', 'color' => '#3a2b26' ] ); };
		$text    = function ( $text ) use ( $b ) { return $b( 'text', [ 'text' => $text, 'align' => 'left', 'size' => 16, 'color' => '#4a3f3a' ] ); };
		$note    = function ( $text ) use ( $b ) { return $b( 'text', [ 'text' => $text, 'align' => 'left', 'size' => 15, 'color' => '#9c6f63' ] ); };
		$button  = function ( $label, $url ) use ( $b ) { return $b( 'button', [ 'text' => $label, 'url' => $url, 'align' => 'left', 'bg' => '#3a2b26', 'color' => '#f7f2ed', 'radius' => 0, 'full' => 0 ] ); };
		$brand   = $b( 'text', [ 'text' => '<strong>OOPS, MINE CO.</strong>', 'align' => 'left', 'size' => 12, 'color' => '#b98b7e' ] );
		$signoff = $text( 'Find something special. Trust your instinct. And when you know, you know.<br>— {site_name}' );
		$items   = $b( 'order_items', [ 'show_images' => 1 ] );
		$summary = $b( 'order_summary', [ 'show_addresses' => 1 ] );

		$map = [
			'customer_processing_order' => [ 'Oops — it’s yours. Order #{order_number} is confirmed', [ $brand, $heading( 'It’s officially yours, {first_name}.' ), $text( 'Thank you for your order. We’re preparing it with the same care we used to choose it. Here’s what’s coming your way:' ), $items, $summary, $button( 'View your order', '{order_url}' ), $signoff ] ],
			'customer_on_hold_order'    => [ 'Your order #{order_number} is on hold', [ $brand, $heading( 'Almost there, {first_name}.' ), $text( 'Your order is on hold until we confirm payment. As soon as it clears, we’ll get your pieces moving.' ), $items, $summary, $button( 'Complete payment', '{payment_url}' ), $signoff ] ],
			'customer_completed_order'  => [ 'Your Oops, Mine order #{order_number} is on its way', [ $brand, $heading( 'On its way to you.' ), $text( 'Order #{order_number} has shipped. We hope it feels exactly like you hoped it would.' ), $items, $summary, $button( 'Track your order', '{order_url}' ), $signoff ] ],
			'customer_refunded_order'   => [ 'Your refund for order #{order_number}', [ $brand, $heading( 'Your refund is on its way.' ), $text( 'We’ve refunded order #{order_number}. Depending on your bank, it can take a few days to appear.' ), $items, $summary, $signoff ] ],
			'customer_failed_order'     => [ 'Your order #{order_number} did not go through', [ $brand, $heading( 'The payment did not go through, {first_name}.' ), $text( 'Order #{order_number} was not completed, so nothing has been charged for it. Your pieces are still listed below — you can try the payment again whenever you are ready.' ), $items, $button( 'Try the payment again', '{payment_url}' ), $signoff ] ],
			'customer_cancelled_order'  => [ 'Your order #{order_number} has been cancelled', [ $brand, $heading( 'Order #{order_number} is cancelled.' ), $text( 'Your order has been cancelled. If that comes as a surprise, reply to this email and we will look into it.' ), $items, $summary, $signoff ] ],
			'customer_verify_email'     => [ 'Confirm your email address for {site_name}', [ $brand, $heading( 'One quick check, {first_name}.' ), $text( 'Please confirm that <strong>{email}</strong> is your email address. The link works once.' ), $button( 'Confirm my email', '{verify_url}' ), $text( 'If you did not ask for this, you can ignore this email.' ), $signoff ] ],
			'customer_invoice'          => [ 'Order #{order_number} from {site_name}', [ $brand, $heading( 'Your order details' ), $text( 'Here are the details for order #{order_number}, placed on {order_date}.' ), $items, $summary, $button( 'Pay for this order', '{payment_url}' ), $signoff ] ],
			'customer_note'             => [ 'A note about your order #{order_number}', [ $brand, $heading( 'A quick note from us' ), $text( '{customer_note}' ), $items, $button( 'View your order', '{order_url}' ), $signoff ] ],
			'customer_new_account'      => [ 'Welcome to {site_name}', [ $brand, $heading( 'Welcome, {first_name}.' ), $text( 'Your account is ready. Save your details, follow your orders and keep a wishlist of the pieces that caught your eye.' ), $button( 'Go to my account', '{my_account_url}' ), $signoff ] ],
			'customer_reset_password'   => [ 'Reset your {site_name} password', [ $brand, $heading( 'Reset your password' ), $text( 'Someone asked to reset the password for <strong>{user_login}</strong>. If that was you, use the button below. If not, you can safely ignore this email.' ), $button( 'Choose a new password', '{reset_url}' ), $signoff ] ],
			'abandoned_cart'            => [ 'Oops — did you forget something?', [ $brand, $heading( 'Your bag is still here, {first_name}.' ), $text( 'You picked, then life happened. We kept everything exactly where you left it — one tap and you are back to it.' ), $b( 'cart_items', [ 'show_images' => 1 ] ), $button( 'Back to my bag', '{recovery_url}' ), $text( 'Oops, mine? We thought so.<br>— {site_name}' ) ] ],
			'abandoned_cart_2'          => [ 'Still thinking it over? These don’t come back', [ $brand, $heading( 'Still on your mind?' ), $text( 'Your picks are waiting, {first_name}. Take another look while your size is still there.' ), $b( 'cart_items', [ 'show_images' => 1 ] ), $note( 'Small batches. When a size sells through, it’s gone.' ), $button( 'Take another look', '{recovery_url}' ), $text( 'Find something yours.<br>— {site_name}' ) ] ],
			'abandoned_cart_3'          => [ '10% off to finish the look', [ $brand, $heading( 'Here’s 10% off to finish the look.' ), $text( 'Last note about your bag, {first_name}. Use the code below at checkout and make it yours.' ), $b( 'coupon', [ 'code' => '{coupon_code}', 'note' => '10% off your order', 'color' => '#9c6f63' ] ), $b( 'cart_items', [ 'show_images' => 1 ] ), $button( 'Finish my order', '{recovery_url}' ), $text( 'One unexpected find at a time.<br>— {site_name}' ) ] ],
			'stylist_edit'              => [ 'Your edit from Oops, Mine Co.', [ $brand, $heading( 'Chosen for you, {first_name}.' ), $text( 'Here is the edit from your answers — the pieces on the rail now that we would have put in your hands. Each one links straight through.' ), $b( 'cart_items', [ 'show_images' => 1 ] ), $button( 'See everything on the rail', '{shop_url}' ), $text( 'Runs here are small, so an edit is a snapshot of today rather than a promise about next week.<br>— {site_name}' ) ] ],
			'back_in_stock'             => [ '{product_name} is back — the size you wanted', [ $brand, $heading( 'It came back, {first_name}.' ), $text( 'You asked to hear when <strong>{product_name}</strong> returned in <strong>{variation}</strong>. It has, and these go out in small runs — so this is worth opening now rather than later.' ), $button( 'Take another look', '{product_url}' ), $text( 'If you have already found something else, <a href="{cancel_url}">let us know</a> and we will stop watching this one for you.' ), $signoff ] ],
			'wheel_prize'               => [ 'It landed on {prize_label} — your code is inside', [ $brand, $heading( 'It landed on {prize_label}, {first_name}.' ), $text( 'Here is the code the wheel stopped on. It is yours: use it at checkout. We only ever send it here, never on the screen, so nobody else can copy it.' ), $b( 'coupon', [ 'code' => '{prize_code}', 'note' => '{prize_label} — use at checkout', 'color' => '#b98b7e' ] ), $button( 'Start shopping', '{shop_url}' ), $signoff ] ],
			'drop_open'                 => [ 'It’s open: {drop_title}', [ $brand, $heading( 'It’s open, {first_name}.' ), $text( 'You asked us to tell you the moment <strong>{drop_title}</strong> went live. It just has — and the runs are small, so this one is worth opening now rather than later.' ), $b( 'image', [ 'url' => '{drop_image}', 'alt' => '{drop_title}', 'width' => 100, 'align' => 'center', 'link' => '{drop_url}' ] ), $button( 'Shop the drop', '{drop_url}' ), $signoff ] ],
			'welcome_subscriber'        => [ 'Your code is inside', [ $brand, $heading( 'Found with intention. Claimed on instinct.' ), $text( 'Thanks for joining the list, {first_name}. Here is the code we promised — expect new arrivals, quiet restocks and the occasional note, never noise.' ), $b( 'coupon', [ 'code' => '{welcome_coupon}', 'note' => '{welcome_discount} off your first order', 'color' => '#b98b7e' ] ), $button( 'Shop new arrivals', '{shop_url}' ), $signoff ] ],
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
			// An invoice for an order already paid takes WooCommerce's "paid" subject, through its own filter.
			$hooks = 'customer_invoice' === $id ? [ $id, 'customer_invoice_paid' ] : [ $id ];
			foreach ( $hooks as $hook ) {
				self::subject_filter( $hook, $id );
			}
		}
	}

	/** Swap WooCommerce's subject for the design's when the design is switched on. */
	private static function subject_filter( $hook, $id ) {
		add_filter( "woocommerce_email_subject_{$hook}", function ( $subject, $object = null ) use ( $id ) {
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
		// "Confirm your email" carries no order or user, only these.
		if ( ! empty( $vars['verify_url'] ) ) {
			$data['verify_url'] = $vars['verify_url'];
		}
		if ( ! empty( $vars['user_email'] ) && empty( $args['order'] ) && empty( $args['user'] ) ) {
			$data['email']         = $vars['user_email'];
			$data['customer_name'] = (string) ( $vars['user_display_name'] ?? '' );
			$data['first_name']    = (string) strtok( (string) ( $vars['user_display_name'] ?? '' ), ' ' ) ?: __( 'there', 'whd' );
		}
		$args['data'] = $data;
		return WHD_Blocks::render( $design, 'email', self::context( $args ) );
	}

	/* ─────────────────────────── the sign-up coupon ─────────────────────────── */

	/**
	 * The code somebody gets for joining the list.
	 *
	 * WHD → Settings holds two: one for an abandoned cart and one offered on the way out. The
	 * second is the one every sign-up modal promises, so it is the one the welcome email sends.
	 * A code that has been renamed or deleted in WooCommerce is not offered at all — promising a
	 * discount that will be refused at checkout is worse than promising none.
	 */
	public static function welcome_coupon(): string {
		$settings = WHD_Plugin::settings();
		foreach ( [ $settings['exit_coupon'] ?? '', $settings['cart_coupon'] ?? '' ] as $code ) {
			$code = trim( (string) $code );
			if ( '' === $code ) {
				continue;
			}
			if ( ! function_exists( 'wc_get_coupon_id_by_code' ) || wc_get_coupon_id_by_code( $code ) ) {
				return $code;
			}
		}

		return '';
	}

	/** What that code is worth, read off the coupon — "15%", or empty when it is a fixed amount. */
	public static function welcome_discount(): string {
		$code = self::welcome_coupon();
		if ( '' === $code || ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
			return '';
		}
		$id = wc_get_coupon_id_by_code( $code );
		if ( ! $id ) {
			return '';
		}
		$coupon = new WC_Coupon( $id );
		if ( 'percent' !== $coupon->get_discount_type() ) {
			return '';
		}

		// Trailing zeros only after a decimal point: rtrim( '10', '0' ) is "1", which turned a 10% code into "1%".
		$value = (float) $coupon->get_amount();
		return ( floor( $value ) === $value ? (string) (int) $value : rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' ) ) . '%';
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
			'exit_coupon'    => $settings['exit_coupon'] ?? 'STAY15', // exit-intent code (WHD → Settings)
			/*
			 * The code a sign-up is promised, and what it is worth.
			 *
			 * Every modal on the site offers a discount for an email address, and the email that
			 * follows has to carry the same code the modal promised. It did not: the modals read
			 * exit_coupon (STAY15, 15%) and the welcome email printed cart_coupon (OOPS10, 10%),
			 * so a shopper was shown one number and sent another. Both now come from here, and
			 * the amount is read off the coupon rather than typed into the copy, so changing the
			 * coupon changes every place that quotes it.
			 */
			'welcome_coupon'   => self::welcome_coupon(),
			'welcome_discount' => self::welcome_discount(),
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
			'verify_url'       => home_url( '/my-account/?verify=sample' ),
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
		if ( in_array( $id, [ 'abandoned_cart', 'abandoned_cart_2', 'abandoned_cart_3', 'welcome_subscriber' ], true ) ) {
			$args['cart'] = self::sample_cart();
		}
		if ( 'stylist_edit' === $id ) {
			$args['cart'] = self::sample_cart();
		}
		if ( 'wheel_prize' === $id ) {
			$args['data'] = [
				'prize_label' => __( '10% off', 'whd' ),
				'prize_code'  => self::welcome_coupon() ?: 'OOPS10',
			];
		}
		if ( 'drop_open' === $id ) {
			$drop         = (array) apply_filters( 'whd_drop', null );
			$args['data'] = [
				'drop_title' => (string) ( $drop['title'] ?? __( 'The new drop', 'whd' ) ),
				'drop_url'   => (string) ( $drop['url'] ?? ( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ) ) ),
				'drop_image' => (string) ( $drop['image'] ?? '' ),
			];
		}
		if ( self::TRIGGER_STOCK === $id ) {
			$sample = self::sample_cart()['items'][0] ?? [];
			$args['data'] = [
				'product_name' => $sample['name'] ?? 'Pleated satin midi skirt',
				'product_url'  => $sample['url'] ?? home_url( '/' ),
				'variation'    => 'Black / M',
				'cancel_url'   => home_url( '/?whd_stock_cancel=sample' ),
			];
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
		$subject = wp_strip_all_tags( WHD_Blocks::merge( $design['settings']['subject'] ?: get_bloginfo( 'name' ), $ctx ) );
		$html    = WHD_Blocks::render( $design, 'email', $ctx );
		$headers = self::headers();

		$error = null;
		$catch = function ( $e ) use ( &$error ) {
			$error = $e;
		};
		add_action( 'wp_mail_failed', $catch );
		$sent = wp_mail( $to, $subject, $html, $headers );
		remove_action( 'wp_mail_failed', $catch );
		if ( ! $sent && class_exists( 'WHD_Integrations' ) ) {
			// Shown under WHD → Integrations → Last API calls, the one place an owner already looks.
			WHD_Integrations::log( 'email', 0, sprintf( '%s to %s — not sent: %s', $id, $to, is_wp_error( $error ) ? $error->get_error_message() : 'wp_mail() refused it' ) );
		}
		return $sent;
	}

	/** Mail headers for anything WHD sends itself: HTML, the shop's sender, and the reply-to. */
	public static function headers(): array {
		list( $from, $reply_to ) = self::sender();
		$name    = sanitize_text_field( WHD_Plugin::settings()['from_name'] ?: get_bloginfo( 'name' ) );
		$headers = [ 'Content-Type: text/html; charset=UTF-8', 'From: ' . $name . ' <' . $from . '>' ];
		if ( $reply_to ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}
		return $headers;
	}

	/**
	 * The address the automations send from, and where replies go: [ from, reply-to ].
	 *
	 * The From address has to be on the shop's own domain. The host signs mail for that domain
	 * and no other, so a Gmail address in the sender box makes mail that fails gmail.com's DMARC
	 * check, and Gmail refuses it outright ("Unauthenticated email from gmail.com is not accepted")
	 * rather than filing it under spam. The welcome email carries the sign-up code, so that was
	 * the code never arriving. An address on another domain is kept as the Reply-To, so answers
	 * still reach the inbox somebody typed in; the mail itself goes from WooCommerce's sender if
	 * that one is on the domain, and otherwise from the address WordPress itself would use.
	 */
	public static function sender() {
		$wanted = sanitize_email( (string) ( WHD_Plugin::settings()['from_email'] ?? '' ) );
		if ( is_email( $wanted ) && self::own_domain( $wanted ) ) {
			return [ $wanted, '' ];
		}
		$woo  = sanitize_email( (string) get_option( 'woocommerce_email_from_address', '' ) );
		$from = is_email( $woo ) && self::own_domain( $woo ) ? $woo : 'wordpress@' . self::site_domain();
		return [ $from, is_email( $wanted ) ? $wanted : '' ];
	}

	/** The shop's host without "www.", e.g. oopsmineco.com. */
	public static function site_domain() {
		return preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
	}

	/** Is this address on the shop's domain (or the domain the shop is a subdomain of)? */
	public static function own_domain( $email ) {
		$domain = strtolower( (string) substr( (string) strrchr( (string) $email, '@' ), 1 ) );
		$host   = self::site_domain();
		return '' !== $domain && ( $domain === $host || str_ends_with( $host, '.' . $domain ) );
	}
}
