<?php
/**
 * Admin: the "WHD" menu, its pages, the block editor screen and the REST
 * endpoints the editor talks to (load/save design, live render, test email).
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Admin {

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_filter( 'parent_file', [ __CLASS__, 'highlight_menu' ] );
		add_action( 'load-admin_page_whd-editor', function () {
			$GLOBALS['title'] = __( 'Editor', 'whd' ); // hidden pages get no <title> from the menu tables
		} );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'rest' ] );
	}

	/* ─────────────────────────── menu ─────────────────────────── */

	public static function menu() {
		$cap = 'manage_options';
		add_menu_page( 'WHD', 'WHD', $cap, 'whd', [ __CLASS__, 'page_overview' ], 'dashicons-megaphone', 58 );
		add_submenu_page( 'whd', __( 'Overview', 'whd' ), __( 'Overview', 'whd' ), $cap, 'whd', [ __CLASS__, 'page_overview' ] );
		add_submenu_page( 'whd', __( 'Popups', 'whd' ), __( 'Popups', 'whd' ), $cap, 'whd-popups', [ __CLASS__, 'page_popups' ] );
		add_submenu_page( 'whd', __( 'Emails', 'whd' ), __( 'Emails', 'whd' ), $cap, 'whd-emails', [ __CLASS__, 'page_emails' ] );
		add_submenu_page( 'whd', __( 'Abandoned carts', 'whd' ), __( 'Abandoned carts', 'whd' ), $cap, 'whd-carts', [ __CLASS__, 'page_carts' ] );
		add_submenu_page( 'whd', __( 'Subscribers', 'whd' ), __( 'Subscribers', 'whd' ), $cap, 'whd-subscribers', [ __CLASS__, 'page_subscribers' ] );
		if ( class_exists( 'WHD_Stock_Alerts' ) ) {
			add_submenu_page( 'whd', __( 'Stock alerts', 'whd' ), __( 'Stock alerts', 'whd' ), $cap, 'whd-stock-alerts', [ __CLASS__, 'page_stock_alerts' ] );
		}
		if ( class_exists( 'WHD_Stylist' ) ) {
			add_submenu_page( 'whd', __( 'AI stylist', 'whd' ), __( 'AI stylist', 'whd' ), $cap, 'whd-stylist', [ __CLASS__, 'page_stylist' ] );
		}
		add_submenu_page( 'whd', __( 'Integrations', 'whd' ), __( 'Integrations', 'whd' ), $cap, 'whd-integrations', [ 'WHD_Integrations', 'page' ] );
		add_submenu_page( 'whd', __( 'Tracking scripts', 'whd' ), __( 'Tracking scripts', 'whd' ), $cap, 'whd-scripts', [ __CLASS__, 'page_scripts' ] );
		// Registered here rather than from WHD_AI_Admin so the parent menu is guaranteed to exist first.
		add_submenu_page( 'whd', __( 'AI content', 'whd' ), __( 'AI content', 'whd' ), $cap, WHD_AI_Admin::PAGE, [ 'WHD_AI_Admin', 'page' ] );
		add_submenu_page( 'whd', __( 'Settings', 'whd' ), __( 'Settings', 'whd' ), $cap, 'whd-settings', [ __CLASS__, 'page_settings' ] );
		// The editor is a hidden page: registered with an empty parent so its hook resolves as
		// admin_page_whd-editor. (Registering it under 'whd' and then remove_submenu_page() leaves a hook
		// WordPress can no longer match, and every visit — even by an administrator — gets
		// "Sorry, you are not allowed to access this page".)
		add_submenu_page( '', __( 'Editor', 'whd' ), __( 'Editor', 'whd' ), $cap, 'whd-editor', [ __CLASS__, 'page_editor' ] );
	}

	/** Keep the WHD menu open / the right submenu highlighted while the (hidden) editor page is shown. */
	public static function highlight_menu( $parent_file ) {
		global $submenu_file;
		if ( isset( $_GET['page'] ) && 'whd-editor' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$submenu_file = ( isset( $_GET['type'] ) && 'email' === $_GET['type'] ) ? 'whd-emails' : 'whd-popups'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return 'whd';
		}
		return $parent_file;
	}

	public static function register_settings() {
		register_setting( 'whd_scripts_group', WHD_Scripts::OPTION, [ 'type' => 'array', 'sanitize_callback' => [ 'WHD_Scripts', 'sanitize' ] ] );
		register_setting( 'whd_settings_group', 'whd_settings', [ 'type' => 'array', 'sanitize_callback' => [ __CLASS__, 'sanitize_settings' ] ] );
		register_setting( 'whd_integrations_group', WHD_Integrations::OPTION, [ 'type' => 'array', 'sanitize_callback' => [ 'WHD_Integrations', 'sanitize' ] ] );
	}

	public static function sanitize_settings( $in ) {
		$in = is_array( $in ) ? $in : [];
		return [
			'exclude_admins'          => empty( $in['exclude_admins'] ) ? 0 : 1,
			'cart_delay_hours'        => max( 0.25, (float) ( $in['cart_delay_hours'] ?? 1 ) ),
			'cart_coupon'             => sanitize_text_field( $in['cart_coupon'] ?? '' ),
			'exit_coupon'             => sanitize_text_field( $in['exit_coupon'] ?? '' ),
			'free_shipping_threshold' => max( 0, round( (float) ( $in['free_shipping_threshold'] ?? 0 ), 2 ) ),
			'from_name'               => sanitize_text_field( $in['from_name'] ?? '' ),
			'from_email'              => sanitize_email( $in['from_email'] ?? '' ),
		];
	}

	public static function assets( $hook ) {
		if ( strpos( $hook, 'whd' ) === false ) {
			return;
		}
		wp_enqueue_style( 'whd-admin', WHD_URL . 'admin/admin.css', [], WHD_VERSION );
		if ( 'admin_page_whd-editor' !== $hook ) { // hidden page (empty parent) → admin_page_ prefix
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading which design to open
		$req = self::resolve_design( isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '', isset( $_GET['id'] ) ? wp_unslash( $_GET['id'] ) : '' );
		// phpcs:enable
		if ( ! $req ) {
			return;
		}
		[ $type, $id, $meta ] = $req;
		wp_enqueue_media();
		wp_enqueue_style( 'whd-editor', WHD_URL . 'admin/editor.css', [ 'whd-admin' ], WHD_VERSION );
		wp_enqueue_script( 'whd-editor', WHD_URL . 'admin/editor.js', [], WHD_VERSION, true );
		wp_localize_script( 'whd-editor', 'WHD_EDITOR', [
			'mode'      => $type,
			'id'        => $id,
			'label'     => $meta['label'],
			'desc'      => $meta['description'] ?? '',
			'design'    => self::design_get( $type, $id ),
			'types'     => WHD_Blocks::types( 'product' === $type ? 'popup' : $type ),
			'tags'      => WHD_Blocks::merge_tags(),
			'rest'      => [ 'root' => esc_url_raw( rest_url( 'whd/v1/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ],
			'backUrl'   => 'product' === $type
				? get_edit_post_link( (int) $id, 'raw' )
				: admin_url( 'admin.php?page=' . ( 'email' === $type ? 'whd-emails' : 'whd-popups' ) ),
			'siteUrl'   => home_url( '/' ),
			'previewUrl'=> 'popup' === $type ? add_query_arg( 'whd_preview', $id, home_url( '/' ) ) : '',
			'userEmail' => wp_get_current_user()->user_email,
			'settingsFields' => self::settings_fields( $type ),
			'i18n'      => [
				'save' => __( 'Save', 'whd' ), 'saved' => __( 'Saved', 'whd' ), 'saving' => __( 'Saving…', 'whd' ), 'unsaved' => __( 'Unsaved changes', 'whd' ),
				'blocks' => __( 'Blocks', 'whd' ), 'dragHint' => __( 'Drag blocks into the canvas, or click to add.', 'whd' ), 'tags' => __( 'Merge tags', 'whd' ), 'tagHint' => __( 'Click a tag to copy it. Paste it into any text, link or subject.', 'whd' ),
				'block' => __( 'Block', 'whd' ), 'settings' => __( 'Settings', 'whd' ), 'selectHint' => __( 'Select a block on the canvas to edit it, or open Settings.', 'whd' ), 'empty' => __( 'Your canvas is empty — drag a block here.', 'whd' ),
				'delete' => __( 'Delete', 'whd' ), 'duplicate' => __( 'Duplicate', 'whd' ), 'up' => __( 'Move up', 'whd' ), 'down' => __( 'Move down', 'whd' ), 'choose' => __( 'Choose', 'whd' ), 'preview' => __( 'Live preview', 'whd' ),
				'sendTest' => __( 'Send test to me', 'whd' ), 'sent' => __( 'Test email sent to', 'whd' ), 'openSite' => __( 'Preview on site', 'whd' ), 'back' => __( '← Back', 'whd' ), 'error' => __( 'Something went wrong', 'whd' ), 'copied' => __( 'Copied', 'whd' ), 'leave' => __( 'You have unsaved changes.', 'whd' ),
			],
		] );
	}

	/** Document-level settings shown in the editor's Settings tab. */
	private static function settings_fields( $type ) {
		if ( 'popup' === $type ) {
			return [
				'enabled'     => [ 'type' => 'toggle', 'label' => __( 'Enabled (show on the site)', 'whd' ) ],
				'cookie_days' => [ 'type' => 'number', 'label' => __( 'Don’t show again for (days)', 'whd' ), 'min' => 0, 'max' => 365, 'help' => __( 'Cookie set the moment the popup appears.', 'whd' ) ],
				'delay'       => [ 'type' => 'number', 'label' => __( 'Delay (seconds)', 'whd' ), 'min' => 0, 'max' => 600, 'help' => __( 'Welcome popup: wait this long, then show. Exit-intent: minimum time on page before it can trigger.', 'whd' ) ],
				'scroll_pct'  => [ 'type' => 'number', 'label' => __( 'Only after scrolling (%)', 'whd' ), 'min' => 0, 'max' => 100, 'help' => __( '0 = no scroll requirement.', 'whd' ) ],
				'show_on'     => [ 'type' => 'select', 'label' => __( 'Show on', 'whd' ), 'options' => [ 'all' => __( 'All pages', 'whd' ), 'home' => __( 'Home page only', 'whd' ), 'shop' => __( 'Shop, categories, products & cart', 'whd' ), 'not_checkout' => __( 'Everywhere except checkout', 'whd' ) ] ],
				'width'       => [ 'type' => 'number', 'label' => __( 'Width (px)', 'whd' ), 'min' => 320, 'max' => 1000 ],
				'padding'     => [ 'type' => 'number', 'label' => __( 'Inner padding (px)', 'whd' ), 'min' => 0, 'max' => 120 ],
				'radius'      => [ 'type' => 'number', 'label' => __( 'Corner radius (px)', 'whd' ), 'min' => 0, 'max' => 40 ],
				'bg'          => [ 'type' => 'color', 'label' => __( 'Background', 'whd' ) ],
				'overlay'     => [ 'type' => 'text', 'label' => __( 'Overlay colour (CSS)', 'whd' ), 'help' => 'rgba(20,20,20,0.6)' ],
				'image'       => [ 'type' => 'image', 'label' => __( 'Side image (desktop, optional)', 'whd' ) ],
			];
		}
		return [
			'enabled'   => [ 'type' => 'toggle', 'label' => __( 'Use this design (replaces the default email)', 'whd' ) ],
			'subject'   => [ 'type' => 'text', 'label' => __( 'Subject', 'whd' ), 'help' => __( 'Merge tags allowed, e.g. {first_name}, {order_number}', 'whd' ) ],
			'preheader' => [ 'type' => 'text', 'label' => __( 'Preview text (preheader)', 'whd' ) ],
			'width'     => [ 'type' => 'number', 'label' => __( 'Width (px)', 'whd' ), 'min' => 480, 'max' => 800 ],
			'padding'   => [ 'type' => 'number', 'label' => __( 'Inner padding (px)', 'whd' ), 'min' => 0, 'max' => 80 ],
			'bg'        => [ 'type' => 'color', 'label' => __( 'Page background', 'whd' ) ],
			'card'      => [ 'type' => 'color', 'label' => __( 'Card background', 'whd' ) ],
			'footer'    => [ 'type' => 'textarea', 'label' => __( 'Footer text', 'whd' ) ],
		];
	}

	/* ─────────────────────────── pages ─────────────────────────── */

	private static function editor_url( $type, $id ) {
		return admin_url( 'admin.php?page=whd-editor&type=' . $type . '&id=' . $id );
	}

	private static function header( $title, $intro = '' ) {
		echo '<div class="wrap whd-wrap"><h1 class="whd-title">' . esc_html( $title ) . '</h1>';
		if ( $intro ) {
			echo '<p class="whd-intro">' . wp_kses_post( $intro ) . '</p>';
		}
	}

	public static function page_overview() {
		$popups = WHD_Popups::all();
		$emails = WHD_Emails::all();
		$on     = fn( $arr ) => count( array_filter( $arr, fn( $d ) => ! empty( $d['settings']['enabled'] ) ) );
		$stats  = class_exists( 'WHD_Cart' ) && class_exists( 'WooCommerce' ) ? WHD_Cart::stats() : [ 'active' => 0, 'sent' => 0, 'recovered' => 0 ];
		$s      = WHD_Scripts::get();
		self::header( 'WHD', __( 'Popups, email designs, abandoned-cart recovery, your newsletter list and tracking — everything marketing-related for the store, in one place.', 'whd' ) );
		echo '<div class="whd-grid">';
		self::card( __( 'Popups', 'whd' ), sprintf( __( '%1$d of %2$d enabled', 'whd' ), $on( $popups ), count( $popups ) ), admin_url( 'admin.php?page=whd-popups' ) );
		self::card( __( 'Email designs', 'whd' ), sprintf( __( '%1$d of %2$d active', 'whd' ), $on( $emails ), count( $emails ) ), admin_url( 'admin.php?page=whd-emails' ) );
		self::card( __( 'Abandoned carts', 'whd' ), sprintf( __( '%1$d waiting · %2$d reminded · %3$d recovered', 'whd' ), $stats['active'], $stats['sent'], $stats['recovered'] ), admin_url( 'admin.php?page=whd-carts' ) );
		self::card( __( 'Subscribers', 'whd' ), sprintf( _n( '%d address', '%d addresses', WHD_Subscribers::count(), 'whd' ), WHD_Subscribers::count() ), admin_url( 'admin.php?page=whd-subscribers' ) );
		self::card( __( 'Integrations', 'whd' ), implode( ' · ', WHD_Integrations::status_lines() ), admin_url( 'admin.php?page=whd-integrations' ) );
		self::card( __( 'Tracking', 'whd' ), implode( ' · ', array_filter( [ $s['ga4_id'] ? 'GA4' : '', $s['gsc_verification'] ? 'Search Console' : '', $s['fb_pixel_id'] ? 'Meta Pixel' : '' ] ) ) ?: __( 'Nothing configured yet', 'whd' ), admin_url( 'admin.php?page=whd-scripts' ) );
		echo '</div></div>';
	}

	private static function card( $title, $value, $url ) {
		echo '<a class="whd-card" href="' . esc_url( $url ) . '"><span class="whd-card__title">' . esc_html( $title ) . '</span><span class="whd-card__value">' . esc_html( $value ) . '</span></a>';
	}

	public static function page_popups() {
		self::header( __( 'Popups', 'whd' ), __( 'Two popups, each with its own drag-and-drop design and cookie rules. Countdown blocks are cookie-based, so a visitor’s timer keeps running across pages and reloads.', 'whd' ) );
		echo '<div class="whd-list">';
		foreach ( WHD_Popups::all() as $id => $design ) {
			$meta = WHD_Popups::ids()[ $id ];
			$on   = ! empty( $design['settings']['enabled'] );
			echo '<div class="whd-row"><div class="whd-row__main"><span class="whd-badge ' . ( $on ? 'is-on' : '' ) . '">' . ( $on ? esc_html__( 'Enabled', 'whd' ) : esc_html__( 'Off', 'whd' ) ) . '</span><strong>' . esc_html( $meta['label'] ) . '</strong><p>' . esc_html( $meta['description'] ) . '</p>'
				. '<p class="whd-muted">' . sprintf( esc_html__( 'Cookie: %1$d days · delay: %2$ds · shows on: %3$s · %4$d blocks', 'whd' ), (int) $design['settings']['cookie_days'], (int) $design['settings']['delay'], esc_html( $design['settings']['show_on'] ), count( $design['blocks'] ) ) . '</p></div>'
				. '<div class="whd-row__actions"><a class="button button-primary" href="' . esc_url( self::editor_url( 'popup', $id ) ) . '">' . esc_html__( 'Edit design', 'whd' ) . '</a> <a class="button" target="_blank" href="' . esc_url( add_query_arg( 'whd_preview', $id, home_url( '/' ) ) ) . '">' . esc_html__( 'Preview on site', 'whd' ) . '</a></div></div>';
		}
		echo '</div></div>';
	}

	public static function page_emails() {
		self::header( __( 'Emails', 'whd' ), __( 'Design every customer email in the same editor. Enable a design to replace WooCommerce’s default for that trigger; the abandoned-cart and newsletter-welcome emails are sent automatically once enabled.', 'whd' ) );
		if ( ! class_exists( 'WooCommerce' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'WooCommerce is not active — order emails cannot be sent until it is.', 'whd' ) . '</p></div>';
		}
		$groups = [ 'customer' => __( 'Customer emails', 'whd' ), 'automation' => __( 'Automations', 'whd' ), 'admin' => __( 'Store-owner notifications', 'whd' ) ];
		$all    = WHD_Emails::all();
		foreach ( $groups as $g => $label ) {
			echo '<h2 class="whd-h2">' . esc_html( $label ) . '</h2><table class="widefat striped whd-table"><thead><tr><th>' . esc_html__( 'Trigger', 'whd' ) . '</th><th>' . esc_html__( 'Subject', 'whd' ) . '</th><th>' . esc_html__( 'Status', 'whd' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( WHD_Emails::triggers() as $id => $t ) {
				if ( $t['group'] !== $g ) {
					continue;
				}
				$d  = $all[ $id ];
				$on = ! empty( $d['settings']['enabled'] );
				echo '<tr><td><strong>' . esc_html( $t['label'] ) . '</strong></td><td class="whd-muted">' . esc_html( $d['settings']['subject'] ) . '</td><td><span class="whd-badge ' . ( $on ? 'is-on' : '' ) . '">' . ( $on ? esc_html__( 'Custom design', 'whd' ) : esc_html__( 'Default', 'whd' ) ) . '</span></td><td style="text-align:right"><a class="button" href="' . esc_url( self::editor_url( 'email', $id ) ) . '">' . esc_html__( 'Edit', 'whd' ) . '</a></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	public static function page_carts() {
		self::header( __( 'Abandoned carts', 'whd' ), __( 'Carts are captured for logged-in customers automatically and for guests as soon as they type their email at checkout. Reminders go out after the delay set in Settings, using the “Abandoned cart recovery” email design.', 'whd' ) );
		if ( isset( $_GET['sent'] ) ) {
			echo '<div class="notice notice-' . ( $_GET['sent'] ? 'success' : 'error' ) . ' is-dismissible"><p>' . ( $_GET['sent'] ? esc_html__( 'Reminder sent.', 'whd' ) : esc_html__( 'The reminder could not be sent (is the email design enabled and wp_mail working?).', 'whd' ) ) . '</p></div>';
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			echo '<p>' . esc_html__( 'WooCommerce is not active.', 'whd' ) . '</p></div>';
			return;
		}
		$rows = WHD_Cart::recent( 100 );
		echo '<table class="widefat striped whd-table"><thead><tr><th>' . esc_html__( 'Customer', 'whd' ) . '</th><th>' . esc_html__( 'Items', 'whd' ) . '</th><th>' . esc_html__( 'Total', 'whd' ) . '</th><th>' . esc_html__( 'Status', 'whd' ) . '</th><th>' . esc_html__( 'Last activity', 'whd' ) . '</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No carts captured yet.', 'whd' ) . '</td></tr>';
		}
		foreach ( $rows as $r ) {
			$items = (array) json_decode( (string) $r->items, true );
			$names = implode( ', ', array_slice( array_column( $items, 'name' ), 0, 3 ) );
			echo '<tr><td><strong>' . esc_html( $r->first_name ?: '—' ) . '</strong><br><span class="whd-muted">' . esc_html( $r->email ) . '</span></td><td>' . (int) $r->item_count . '<br><span class="whd-muted">' . esc_html( $names ) . '</span></td><td>' . wp_kses_post( wc_price( (float) $r->total ) ) . '</td><td><span class="whd-badge whd-badge--' . esc_attr( $r->status ) . '">' . esc_html( $r->status ) . '</span></td><td>' . esc_html( human_time_diff( strtotime( $r->updated_at ), current_time( 'timestamp' ) ) ) . ' ' . esc_html__( 'ago', 'whd' ) . '</td><td style="text-align:right">';
			if ( in_array( $r->status, [ 'active', 'sent', 'clicked', 'failed' ], true ) ) {
				echo '<a class="button button-small" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=whd_send_cart&id=' . (int) $r->id ), 'whd_send_cart' ) ) . '">' . esc_html__( 'Send reminder now', 'whd' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function page_subscribers() {
		self::header( __( 'Subscribers', 'whd' ), __( 'Addresses collected by the newsletter form and the popups. Enable the “Newsletter welcome” email design to greet new subscribers automatically, and set up WHD → Integrations to copy each one into Mailchimp, Klaviyo or your own webhook.', 'whd' ) );
		$total = WHD_Subscribers::count();
		$sms   = WHD_Subscribers::sms_count();
		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=whd_export_subscribers' ), 'whd_export' ) ) . '">' . esc_html__( 'Export CSV', 'whd' ) . '</a> <span class="whd-muted">'
			. sprintf( esc_html( _n( '%d subscriber', '%d subscribers', $total, 'whd' ) ), (int) $total ) . ' · '
			. sprintf( esc_html( _n( '%d says yes to texts', '%d say yes to texts', $sms, 'whd' ) ), (int) $sms ) . '</span></p>';
		echo '<table class="widefat striped whd-table"><thead><tr><th>' . esc_html__( 'Email', 'whd' ) . '</th><th>' . esc_html__( 'Name', 'whd' ) . '</th><th>' . esc_html__( 'Phone', 'whd' ) . '</th><th>' . esc_html__( 'SMS consent', 'whd' ) . '</th><th>' . esc_html__( 'Source', 'whd' ) . '</th><th>' . esc_html__( 'Subscribed', 'whd' ) . '</th><th>' . esc_html__( 'Synced', 'whd' ) . '</th></tr></thead><tbody>';
		$rows = WHD_Subscribers::recent( 200 );
		if ( ! $rows ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No subscribers yet.', 'whd' ) . '</td></tr>';
		}
		$connected = class_exists( 'WHD_Integrations' ) && WHD_Integrations::email_enabled();
		foreach ( $rows as $r ) {
			$consent = ! empty( $r->sms_consent );
			$synced  = ! empty( $r->synced_at );
			echo '<tr><td>' . esc_html( $r->email ) . '</td><td>' . esc_html( $r->name ) . '</td><td>' . esc_html( $r->phone ?: '—' ) . '</td>'
				. '<td><span class="whd-pill ' . ( $consent ? 'whd-pill--on' : 'whd-pill--off' ) . '">' . ( $consent ? esc_html__( 'Yes', 'whd' ) : esc_html__( 'No', 'whd' ) ) . '</span></td>'
				. '<td>' . esc_html( $r->source ) . '</td><td>' . esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $r->created_at ) ) . '</td>'
				. '<td>' . ( $synced
					? '<span class="whd-pill whd-pill--on">' . esc_html( mysql2date( get_option( 'date_format' ), $r->synced_at ) ) . '</span>'
					: '<span class="whd-pill whd-pill--off">' . ( $connected ? esc_html__( 'Waiting', 'whd' ) : esc_html__( 'Not sent', 'whd' ) ) . '</span>' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Who is waiting for what.
	 *
	 * The top table is the reason the feature exists: a list of sizes people have asked for,
	 * heaviest demand first, which is the closest thing a shop this size has to a reorder report.
	 */
	public static function page_stock_alerts() {
		$counts = WHD_Stock_Alerts::counts();
		self::header(
			__( 'Stock alerts', 'whd' ),
			__( 'Everyone who asked to hear when a sold-out size comes back. The email goes out on its own — within a minute of the stock changing, and on a sweep every fifteen minutes for stock that changed quietly.', 'whd' )
		);

		echo '<div class="whd-grid">';
		self::card( __( 'Waiting', 'whd' ), sprintf( _n( '%d request', '%d requests', $counts['waiting'], 'whd' ), $counts['waiting'] ), '#whd-demand' );
		self::card( __( 'Told', 'whd' ), sprintf( _n( '%d email sent', '%d emails sent', $counts['sent'], 'whd' ), $counts['sent'] ), admin_url( 'admin.php?page=whd-emails' ) );
		self::card( __( 'Withdrawn', 'whd' ), sprintf( _n( '%d cancelled', '%d cancelled', $counts['cancelled'], 'whd' ), $counts['cancelled'] ), '#whd-recent' );
		echo '</div>';

		$demand = WHD_Stock_Alerts::demand();
		echo '<h2 class="whd-h2" id="whd-demand">' . esc_html__( 'What people are waiting for', 'whd' ) . '</h2>';
		echo '<p class="whd-intro">' . esc_html__( 'Heaviest demand first. A row marked back in stock has had its emails sent, or will on the next sweep.', 'whd' ) . '</p>';
		echo '<table class="widefat striped whd-table"><thead><tr>'
			. '<th>' . esc_html__( 'Piece', 'whd' ) . '</th><th>' . esc_html__( 'Size', 'whd' ) . '</th>'
			. '<th>' . esc_html__( 'Waiting', 'whd' ) . '</th><th>' . esc_html__( 'Stock', 'whd' ) . '</th>'
			. '<th>' . esc_html__( 'Last asked', 'whd' ) . '</th></tr></thead><tbody>';
		if ( ! $demand ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Nobody is waiting yet. The form appears on a product page the moment a size sells out.', 'whd' ) . '</td></tr>';
		}
		foreach ( $demand as $d ) {
			echo '<tr><td><strong>' . ( $d['url'] ? '<a href="' . esc_url( $d['url'] ) . '">' . esc_html( $d['name'] ) . '</a>' : esc_html( $d['name'] ) ) . '</strong></td>'
				. '<td>' . esc_html( $d['variation_id'] ? $d['label'] : '—' ) . '</td>'
				. '<td><strong>' . esc_html( number_format_i18n( $d['waiting'] ) ) . '</strong></td>'
				. '<td><span class="whd-pill ' . ( $d['in_stock'] ? 'whd-pill--on' : 'whd-pill--off' ) . '">'
				. esc_html( $d['in_stock'] ? __( 'Back in stock', 'whd' ) : __( 'Sold out', 'whd' ) ) . '</span></td>'
				. '<td class="whd-muted">' . esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $d['latest'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2 class="whd-h2" id="whd-recent">' . esc_html__( 'Latest requests', 'whd' ) . '</h2>';
		echo '<table class="widefat striped whd-table"><thead><tr>'
			. '<th>' . esc_html__( 'When', 'whd' ) . '</th><th>' . esc_html__( 'Email', 'whd' ) . '</th>'
			. '<th>' . esc_html__( 'Piece', 'whd' ) . '</th><th>' . esc_html__( 'Size', 'whd' ) . '</th>'
			. '<th>' . esc_html__( 'State', 'whd' ) . '</th></tr></thead><tbody>';
		$recent = WHD_Stock_Alerts::recent( 50 );
		if ( ! $recent ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Nothing yet.', 'whd' ) . '</td></tr>';
		}
		$states = [
			'waiting'   => __( 'Waiting', 'whd' ),
			'sent'      => __( 'Told', 'whd' ),
			'cancelled' => __( 'Withdrawn', 'whd' ),
		];
		foreach ( $recent as $r ) {
			$product = wc_get_product( (int) $r->product_id );
			echo '<tr><td class="whd-muted">' . esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $r->created ) ) . '</td>'
				. '<td>' . esc_html( $r->email ) . '</td>'
				. '<td>' . esc_html( $product ? $product->get_name() : __( '(deleted)', 'whd' ) ) . '</td>'
				. '<td>' . esc_html( $r->variation_id ? $r->label : '—' ) . '</td>'
				. '<td><span class="whd-pill ' . ( 'waiting' === $r->status ? 'whd-pill--off' : 'whd-pill--on' ) . '">'
				. esc_html( $states[ $r->status ] ?? $r->status ) . '</span></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * What the stylist has been told.
	 *
	 * The totals are the point: six questions answered a few hundred times is a description of the
	 * customer this shop actually has, which is worth more than any one session.
	 */
	public static function page_stylist() {
		self::header(
			__( 'AI stylist', 'whd' ),
			__( 'A short wizard that asks a few things and answers with pieces from the rail. Everyone who starts it joins the newsletter list first, with source “stylist”.', 'whd' )
		);

		$total  = WHD_Stylist::count();
		$totals = WHD_Stylist::answer_totals();

		echo '<div class="whd-grid">';
		self::card( __( 'Sessions', 'whd' ), sprintf( _n( '%d finished', '%d finished', $total, 'whd' ), $total ), '#whd-sty-recent' );
		self::card( __( 'Model', 'whd' ), WHD_AI::ready() ? esc_html( WHD_AI::model() ) : __( 'Not connected — matching only', 'whd' ), admin_url( 'admin.php?page=whd-ai' ) );
		self::card( __( 'Robot check', 'whd' ), WHD_AI::recaptcha_ready() ? __( 'reCAPTCHA on', 'whd' ) : __( 'Built-in checks only', 'whd' ), admin_url( 'admin.php?page=whd-ai' ) );
		echo '</div>';

		if ( $totals ) {
			echo '<h2 class="whd-h2">' . esc_html__( 'What people are telling you', 'whd' ) . '</h2>';
			echo '<div class="whd-sty-totals">';
			foreach ( WHD_Stylist::questions() as $q ) {
				if ( empty( $totals[ $q['key'] ] ) ) {
					continue;
				}
				$rows = $totals[ $q['key'] ];
				arsort( $rows );
				$max = max( $rows );
				echo '<div class="whd-sty-total"><h3>' . esc_html( wp_strip_all_tags( $q['question'] ) ) . '</h3><ul>';
				foreach ( $rows as $value => $n ) {
					$label = $q['options'][ $value ] ?? $value;
					printf(
						'<li><span class="whd-sty-total__bar" style="width:%d%%"></span><span class="whd-sty-total__label">%s</span><span class="whd-sty-total__n">%d</span></li>',
						$max ? (int) round( $n / $max * 100 ) : 0,
						esc_html( $label ),
						(int) $n
					);
				}
				echo '</ul></div>';
			}
			echo '</div>';
		}

		echo '<h2 class="whd-h2" id="whd-sty-recent">' . esc_html__( 'Latest sessions', 'whd' ) . '</h2>';
		echo '<table class="widefat striped whd-table"><thead><tr>'
			. '<th>' . esc_html__( 'When', 'whd' ) . '</th><th>' . esc_html__( 'Who', 'whd' ) . '</th>'
			. '<th>' . esc_html__( 'Said', 'whd' ) . '</th><th>' . esc_html__( 'Shown', 'whd' ) . '</th>'
			. '<th>' . esc_html__( 'By', 'whd' ) . '</th></tr></thead><tbody>';
		$rows = WHD_Stylist::sessions( 50 );
		if ( ! $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Nobody has been through it yet.', 'whd' ) . '</td></tr>';
		}
		foreach ( $rows as $r ) {
			$answers = json_decode( (string) $r->answers, true );
			$said    = [];
			foreach ( (array) $answers as $k => $v ) {
				$said[] = $k . ': ' . implode( '/', (array) $v );
			}
			$picks = json_decode( (string) $r->picks, true );
			$names = [];
			foreach ( array_slice( (array) $picks, 0, 3 ) as $pid ) {
				$p = wc_get_product( (int) $pid );
				if ( $p ) {
					$names[] = $p->get_name();
				}
			}
			echo '<tr><td class="whd-muted">' . esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $r->created ) ) . '</td>'
				. '<td>' . esc_html( $r->name ? $r->name . ' · ' . $r->email : $r->email ) . '</td>'
				. '<td class="whd-muted">' . esc_html( implode( ', ', $said ) ) . '</td>'
				. '<td>' . esc_html( implode( ', ', $names ) ) . '</td>'
				. '<td><span class="whd-pill ' . ( $r->used_ai ? 'whd-pill--on' : 'whd-pill--off' ) . '">'
				. esc_html( $r->used_ai ? __( 'Model', 'whd' ) : __( 'Matching', 'whd' ) ) . '</span></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function page_scripts() {
		$s = WHD_Scripts::get();
		self::header( __( 'Tracking scripts', 'whd' ), __( 'Paste your IDs and the snippets are printed on every page (purchase events are sent to GA4 and the Meta Pixel on the order-received page). Raw snippets go in the boxes at the bottom.', 'whd' ) );
		echo '<form method="post" action="options.php" class="whd-form">';
		settings_fields( 'whd_scripts_group' );
		$f = fn( $k ) => WHD_Scripts::OPTION . '[' . $k . ']';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="ga4_id">' . esc_html__( 'Google Analytics 4 — Measurement ID', 'whd' ) . '</label></th><td><input class="regular-text" id="ga4_id" name="' . esc_attr( $f( 'ga4_id' ) ) . '" value="' . esc_attr( $s['ga4_id'] ) . '" placeholder="G-XXXXXXXXXX"></td></tr>';
		echo '<tr><th><label for="gsc">' . esc_html__( 'Google Search Console — verification', 'whd' ) . '</label></th><td><input class="regular-text" id="gsc" name="' . esc_attr( $f( 'gsc_verification' ) ) . '" value="' . esc_attr( $s['gsc_verification'] ) . '" placeholder="content value, or paste the whole <meta> tag"><p class="description">' . esc_html__( 'Choose the “HTML tag” method in Search Console; the tag is printed for all visitors, including Google’s verifier.', 'whd' ) . '</p></td></tr>';
		echo '<tr><th><label for="fb">' . esc_html__( 'Meta (Facebook) Pixel ID', 'whd' ) . '</label></th><td><input class="regular-text" id="fb" name="' . esc_attr( $f( 'fb_pixel_id' ) ) . '" value="' . esc_attr( $s['fb_pixel_id'] ) . '" placeholder="1234567890"></td></tr>';
		echo '<tr><th>' . esc_html__( 'Exclude administrators', 'whd' ) . '</th><td><label><input type="checkbox" name="' . esc_attr( $f( 'exclude_admins' ) ) . '" value="1" ' . checked( $s['exclude_admins'], 1, false ) . '> ' . esc_html__( 'Don’t track logged-in administrators (keeps your own visits out of the data).', 'whd' ) . '</label></td></tr>';
		foreach ( [ 'head_extra' => __( 'Extra <head> code', 'whd' ), 'body_extra' => __( 'Code after <body> opens', 'whd' ), 'footer_extra' => __( 'Code before </body>', 'whd' ) ] as $k => $label ) {
			echo '<tr><th><label for="' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label></th><td><textarea class="large-text code" rows="5" id="' . esc_attr( $k ) . '" name="' . esc_attr( $f( $k ) ) . '">' . esc_textarea( $s[ $k ] ) . '</textarea></td></tr>';
		}
		echo '</tbody></table>';
		submit_button();
		echo '</form></div>';
	}

	public static function page_settings() {
		$s = WHD_Plugin::settings();
		self::header( __( 'Settings', 'whd' ) );
		echo '<form method="post" action="options.php" class="whd-form">';
		settings_fields( 'whd_settings_group' );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Popups for administrators', 'whd' ) . '</th><td><label><input type="checkbox" name="whd_settings[exclude_admins]" value="1" ' . checked( $s['exclude_admins'], 1, false ) . '> ' . esc_html__( 'Hide popups from logged-in administrators (use “Preview on site” to see them).', 'whd' ) . '</label></td></tr>';
		echo '<tr><th><label for="cdh">' . esc_html__( 'Abandoned-cart reminder after (hours)', 'whd' ) . '</label></th><td><input type="number" step="0.25" min="0.25" id="cdh" name="whd_settings[cart_delay_hours]" value="' . esc_attr( $s['cart_delay_hours'] ) . '"><p class="description">' . esc_html__( 'Checked every 15 minutes by WP-Cron.', 'whd' ) . '</p></td></tr>';
		echo '<tr><th><label for="cc">' . esc_html__( 'Coupon code for {coupon_code}', 'whd' ) . '</label></th><td><input class="regular-text" id="cc" name="whd_settings[cart_coupon]" value="' . esc_attr( $s['cart_coupon'] ) . '" placeholder="OOPS10"><p class="description">' . esc_html__( 'Create the coupon in WooCommerce → Marketing → Coupons; this only fills the merge tag.', 'whd' ) . '</p></td></tr>';
		echo '<tr><th><label for="xc">' . esc_html__( 'Exit-intent coupon', 'whd' ) . '</label></th><td><input class="regular-text" id="xc" name="whd_settings[exit_coupon]" value="' . esc_attr( $s['exit_coupon'] ) . '" placeholder="STAY15"><p class="description">' . esc_html__( 'Offered by the exit-intent popup to keep a leaving visitor. Create the coupon in WooCommerce first.', 'whd' ) . '</p></td></tr>';
		echo '<tr><th><label for="fst">' . esc_html__( 'Free shipping threshold', 'whd' ) . '</label></th><td><input type="number" step="1" min="0" id="fst" name="whd_settings[free_shipping_threshold]" value="' . esc_attr( $s['free_shipping_threshold'] ) . '"><p class="description">' . esc_html__( 'The cart progress bar counts up to this amount (“you are $15 away from free shipping”). Set the matching free-shipping method in WooCommerce → Shipping; 0 hides the bar.', 'whd' ) . '</p></td></tr>';
		echo '<tr><th><label for="fn">' . esc_html__( 'Sender name (automations)', 'whd' ) . '</label></th><td><input class="regular-text" id="fn" name="whd_settings[from_name]" value="' . esc_attr( $s['from_name'] ) . '"></td></tr>';
		echo '<tr><th><label for="fe">' . esc_html__( 'Sender email (automations)', 'whd' ) . '</label></th><td><input class="regular-text" id="fe" type="email" name="whd_settings[from_email]" value="' . esc_attr( $s['from_email'] ) . '"></td></tr>';
		echo '</tbody></table>';
		submit_button();
		echo '</form></div>';
	}

	/**
	 * Resolve the design a screen or request is about.
	 *
	 * Three kinds now: a popup, an email, or one product's story. A story is identified by the
	 * product id, so it is the only one whose id is numeric and whose permission is per-post.
	 *
	 * @return array|null [ type, id, meta ] or null when it is not a design we know.
	 */
	private static function resolve_design( $type, $id ) {
		if ( 'product' === $type ) {
			$pid = (int) $id;
			$post = $pid ? get_post( $pid ) : null;
			if ( ! $post || 'product' !== $post->post_type || ! current_user_can( 'edit_post', $pid ) ) {
				return null;
			}
			return [ 'product', (string) $pid, [
				'label'       => get_the_title( $pid ),
				'description' => __( 'The written part of this product page: how it wears, what it goes with, when to size up. Shows below the gallery and above “You might also like”.', 'whd' ),
			] ];
		}
		$type = 'email' === $type ? 'email' : 'popup';
		$id   = sanitize_key( (string) $id );
		$meta = 'email' === $type ? ( WHD_Emails::triggers()[ $id ] ?? null ) : ( WHD_Popups::ids()[ $id ] ?? null );
		return $meta ? [ $type, $id, $meta ] : null;
	}

	/** Read one design, whichever kind it is. */
	private static function design_get( $type, $id ) {
		if ( 'product' === $type ) {
			return WHD_Product_Story::get( (int) $id );
		}
		return 'email' === $type ? WHD_Emails::get( $id ) : WHD_Popups::get( $id );
	}

	/** Write one design, whichever kind it is. */
	private static function design_save( $type, $id, $design ) {
		if ( 'product' === $type ) {
			return WHD_Product_Story::save( (int) $id, $design );
		}
		return 'email' === $type ? WHD_Emails::save( $id, $design ) : WHD_Popups::save( $id, $design );
	}

	public static function page_editor() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading which design to open
		$req  = self::resolve_design( isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '', isset( $_GET['id'] ) ? wp_unslash( $_GET['id'] ) : '' );
		// phpcs:enable
		$type = $req[0] ?? '';
		if ( ! $req ) {
			self::header( __( 'Editor', 'whd' ) );
			echo '<p>' . esc_html__( 'Unknown design.', 'whd' ) . '</p></div>';
			return;
		}
		echo '<div class="wrap whd-wrap whd-wrap--editor"><div id="whd-editor" class="whd-ed" data-mode="' . esc_attr( $type ) . '"><p class="whd-ed__loading">' . esc_html__( 'Loading editor…', 'whd' ) . '</p></div></div>';
	}

	/* ─────────────────────────── REST ─────────────────────────── */

	public static function rest() {
		$perm = fn() => current_user_can( 'manage_options' );
		register_rest_route( 'whd/v1', '/design', [
			[ 'methods' => 'GET', 'callback' => [ __CLASS__, 'rest_get_design' ], 'permission_callback' => $perm ],
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'rest_save_design' ], 'permission_callback' => $perm ],
		] );
		register_rest_route( 'whd/v1', '/render', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'rest_render' ], 'permission_callback' => $perm ] );
		register_rest_route( 'whd/v1', '/test-email', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'rest_test_email' ], 'permission_callback' => $perm ] );
	}

	private static function rest_args( WP_REST_Request $r ) {
		$req = self::resolve_design( (string) $r->get_param( 'type' ), (string) $r->get_param( 'id' ) );
		return $req ? [ $req[0], $req[1] ] : null;
	}

	public static function rest_get_design( WP_REST_Request $r ) {
		$a = self::rest_args( $r );
		if ( ! $a ) {
			return new WP_Error( 'whd_unknown', 'Unknown design', [ 'status' => 404 ] );
		}
		[ $type, $id ] = $a;
		return [ 'design' => self::design_get( $type, $id ) ];
	}

	public static function rest_save_design( WP_REST_Request $r ) {
		$a = self::rest_args( $r );
		if ( ! $a ) {
			return new WP_Error( 'whd_unknown', 'Unknown design', [ 'status' => 404 ] );
		}
		[ $type, $id ] = $a;
		$saved = self::design_save( $type, $id, $r->get_param( 'design' ) );
		return [ 'saved' => true, 'design' => WHD_Blocks::with_defaults( $saved, 'product' === $type ? 'popup' : $type ) ];
	}

	public static function rest_render( WP_REST_Request $r ) {
		$a = self::rest_args( $r );
		if ( ! $a ) {
			return new WP_Error( 'whd_unknown', 'Unknown design', [ 'status' => 404 ] );
		}
		[ $type, $id ] = $a;

		if ( 'product' === $type ) {
			// Preview the story the way the product page shows it: same markup, same stylesheet.
			$design = WHD_Blocks::sanitize_design( $r->get_param( 'design' ), 'popup' );
			$body   = WHD_Blocks::render( $design, 'popup', WHD_Product_Story::context( (int) $id ) );
			$eyebrow = trim( (string) ( $design['settings']['subject'] ?? '' ) );
			$html   = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
				. '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Manrope:wght@400;500;600;700&display=swap">'
				. '<link rel="stylesheet" href="' . esc_url( WHD_URL . 'assets/story.css?v=' . WHD_VERSION ) . '">'
				. '<style>body{margin:0;background:#fff}</style></head><body>'
				. '<section class="whd-story"><div class="whd-story__inner">'
				. ( $eyebrow ? '<p class="whd-story__eyebrow">' . esc_html( $eyebrow ) . '</p>' : '' )
				. $body
				. '</div></section></body></html>';
			return [ 'html' => $html ];
		}

		$design = WHD_Blocks::sanitize_design( $r->get_param( 'design' ), $type );
		if ( 'email' === $type ) {
			return [ 'html' => WHD_Blocks::render( $design, 'email', WHD_Emails::preview_context( $id ) ) ];
		}
		$design = WHD_Blocks::with_defaults( $design, 'popup' );
		$s      = $design['settings'];
		$style  = '--whd-width:' . (int) $s['width'] . 'px;--whd-bg:' . esc_attr( $s['bg'] ) . ';--whd-overlay:' . esc_attr( $s['overlay'] ) . ';--whd-radius:' . (int) $s['radius'] . 'px;--whd-pad:' . (int) $s['padding'] . 'px';
		$body   = WHD_Blocks::render( $design, 'popup', WHD_Emails::context( [] ) );
		$html   = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Manrope:wght@400;500;600;700&display=swap">'
			. '<link rel="stylesheet" href="' . esc_url( WHD_URL . 'assets/popups.css?v=' . WHD_VERSION ) . '">'
			. '<style>body{margin:0;min-height:100vh;background:#f2ece6 url(data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#f2ece6"/><rect width="20" height="20" fill="#ebe4dd"/><rect x="20" y="20" width="20" height="20" fill="#ebe4dd"/></svg>' ) . ')}</style></head><body>'
			. '<div class="whd-popup whd-popup--preview' . ( $s['image'] ? ' whd-popup--with-image' : '' ) . '" data-config="' . esc_attr( wp_json_encode( [ 'id' => $id, 'trigger' => 'delay', 'delay' => 0, 'cookieDays' => 0, 'scrollPct' => 0, 'preview' => true ] ) ) . '" style="' . $style . '" hidden>'
			. '<div class="whd-popup__overlay"></div><div class="whd-popup__dialog" role="dialog" tabindex="-1"><button type="button" class="whd-popup__close" aria-label="Close">&times;</button>'
			. ( $s['image'] ? '<div class="whd-popup__image"><img src="' . esc_url( $s['image'] ) . '" alt=""></div>' : '' )
			. '<div class="whd-popup__body">' . $body . '</div></div></div>'
			. '<script src="' . esc_url( WHD_URL . 'assets/popups.js?v=' . WHD_VERSION ) . '"></script></body></html>';
		return [ 'html' => $html ];
	}

	public static function rest_test_email( WP_REST_Request $r ) {
		$id = sanitize_key( (string) $r->get_param( 'id' ) );
		if ( ! isset( WHD_Emails::triggers()[ $id ] ) ) {
			return new WP_Error( 'whd_unknown', 'Unknown trigger', [ 'status' => 404 ] );
		}
		$design  = WHD_Blocks::sanitize_design( $r->get_param( 'design' ), 'email' );
		$ctx     = WHD_Emails::preview_context( $id );
		$to      = wp_get_current_user()->user_email;
		$subject = '[TEST] ' . wp_strip_all_tags( WHD_Blocks::merge( $design['settings']['subject'] ?: get_bloginfo( 'name' ), $ctx ) );
		$ok      = wp_mail( $to, $subject, WHD_Blocks::render( $design, 'email', $ctx ), [ 'Content-Type: text/html; charset=UTF-8' ] );
		return [ 'sent' => (bool) $ok, 'to' => $to ];
	}
}
