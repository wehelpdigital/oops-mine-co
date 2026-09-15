<?php
/**
 * Popups: an exit-intent popup and a welcome (cookie) popup, each built with
 * the block editor. Designs live in the `whd_popups` option; the front end
 * prints the markup in the footer and assets/popups.js handles triggers,
 * cookies and the cookie-based countdown.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Popups {

	const OPTION = 'whd_popups';

	public static function ids() {
		return [
			'exit_intent' => [
				'label'       => __( 'Exit-intent popup', 'whd' ),
				'trigger'     => 'exit',
				'description' => __( 'Shows when the cursor leaves the page towards the browser bar (on touch devices: after the delay, or when the visitor scrolls back up quickly). Once shown, a cookie hides it for N days.', 'whd' ),
			],
			'welcome'     => [
				'label'       => __( 'Welcome popup (cookie-based)', 'whd' ),
				'trigger'     => 'delay',
				'description' => __( 'Shows once after a delay (or scroll depth). A cookie is set as soon as it appears, so the visitor never sees it twice within the cookie period.', 'whd' ),
			],
		];
	}

	public static function init() {
		// Priority 5: before wp_print_footer_scripts (20) so assets enqueued here still print.
		add_action( 'wp_footer', [ __CLASS__, 'output' ], 5 );
	}

	public static function all() {
		$stored = get_option( self::OPTION, [] );
		$out    = [];
		foreach ( self::ids() as $id => $meta ) {
			$out[ $id ] = WHD_Blocks::with_defaults( $stored[ $id ] ?? self::default_design( $id ), 'popup' );
		}
		return $out;
	}

	public static function get( $id ) {
		return self::all()[ $id ] ?? null;
	}

	public static function save( $id, $design ) {
		if ( ! isset( self::ids()[ $id ] ) ) {
			return false;
		}
		$stored        = get_option( self::OPTION, [] );
		$stored[ $id ] = WHD_Blocks::sanitize_design( $design, 'popup' );
		update_option( self::OPTION, $stored, false );
		return $stored[ $id ];
	}

	public static function ensure_defaults() {
		$stored = get_option( self::OPTION, [] );
		foreach ( self::ids() as $id => $meta ) {
			if ( empty( $stored[ $id ] ) ) {
				$stored[ $id ] = self::default_design( $id );
			}
		}
		update_option( self::OPTION, $stored, false );
	}

	/** Starter designs in the brand voice; everything is editable. */
	public static function default_design( $id ) {
		$settings = WHD_Blocks::popup_settings_defaults();
		if ( 'exit_intent' === $id ) {
			return [
				'settings' => array_merge( $settings, [ 'delay' => 5, 'cookie_days' => 3 ] ),
				'blocks'   => [
					[ 'type' => 'heading', 'props' => [ 'text' => 'Oops — leaving already?', 'level' => 'h2', 'align' => 'center', 'color' => '#141414' ] ],
					[ 'type' => 'text', 'props' => [ 'text' => 'Take 10% off your first order. The pieces you paused on are still here.', 'align' => 'center', 'size' => 16, 'color' => '#2b2724' ] ],
					[ 'type' => 'countdown', 'props' => [ 'minutes' => 15, 'label' => 'Offer ends in', 'expired' => 'This offer has ended', 'color' => '#141414', 'hide_on_expire' => 0 ] ],
					[ 'type' => 'coupon', 'props' => [ 'code' => 'OOPS10', 'note' => 'Use at checkout', 'color' => '#b98b7e' ] ],
					[ 'type' => 'button', 'props' => [ 'text' => 'Claim it & keep shopping', 'url' => '{shop_url}', 'align' => 'center', 'bg' => '#141414', 'color' => '#ffffff', 'radius' => 0, 'full' => 1 ] ],
				],
			];
		}
		return [
			'settings' => array_merge( $settings, [ 'delay' => 6, 'cookie_days' => 14 ] ),
			'blocks'   => [
				[ 'type' => 'heading', 'props' => [ 'text' => 'Welcome to Oops, Mine Co.', 'level' => 'h2', 'align' => 'center', 'color' => '#141414' ] ],
				[ 'type' => 'text', 'props' => [ 'text' => 'Found with intention. Claimed on instinct.<br>New arrivals land every week — start with 10% off.', 'align' => 'center', 'size' => 16, 'color' => '#2b2724' ] ],
				[ 'type' => 'coupon', 'props' => [ 'code' => 'HELLO10', 'note' => 'Use at checkout', 'color' => '#b98b7e' ] ],
				[ 'type' => 'button', 'props' => [ 'text' => 'Shop new arrivals', 'url' => '{shop_url}', 'align' => 'center', 'bg' => '#141414', 'color' => '#ffffff', 'radius' => 0, 'full' => 1 ] ],
			],
		];
	}

	/** Page targeting. */
	private static function should_show( $settings ) {
		switch ( $settings['show_on'] ) {
			case 'home':
				return is_front_page();
			case 'shop':
				return function_exists( 'is_woocommerce' ) && ( is_woocommerce() || is_cart() );
			case 'not_checkout':
				return ! ( function_exists( 'is_checkout' ) && is_checkout() );
			default:
				return true;
		}
	}

	/** Front-end markup + assets for every enabled popup that targets this page. */
	public static function output() {
		if ( is_admin() || is_feed() || is_embed() || is_customize_preview() || ( function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) ) {
			return;
		}
		if ( ! empty( $_GET['elementor-preview'] ) ) {
			return;
		}
		$settings_global = WHD_Plugin::settings();
		$preview         = isset( $_GET['whd_preview'] ) ? sanitize_key( $_GET['whd_preview'] ) : '';
		if ( ! empty( $settings_global['exclude_admins'] ) && current_user_can( 'manage_options' ) && ! $preview ) {
			return;
		}

		$ctx    = WHD_Emails::context( [] );
		$printed = false;
		foreach ( self::all() as $id => $design ) {
			$s = $design['settings'];
			if ( ( empty( $s['enabled'] ) && $preview !== $id ) || ! self::should_show( $s ) ) {
				continue;
			}
			$config = [
				'id'         => $id,
				'trigger'    => self::ids()[ $id ]['trigger'],
				'delay'      => (int) $s['delay'],
				'cookieDays' => (int) $s['cookie_days'],
				'scrollPct'  => (int) $s['scroll_pct'],
				'preview'    => $preview === $id,
			];
			$style = '--whd-width:' . (int) $s['width'] . 'px;--whd-bg:' . esc_attr( $s['bg'] ) . ';--whd-overlay:' . esc_attr( $s['overlay'] ) . ';--whd-radius:' . (int) $s['radius'] . 'px;--whd-pad:' . (int) $s['padding'] . 'px';
			?>
			<div class="whd-popup<?php echo $s['image'] ? ' whd-popup--with-image' : ''; ?>" id="whd-popup-<?php echo esc_attr( $id ); ?>" data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>" style="<?php echo $style; ?>" hidden>
				<div class="whd-popup__overlay" data-whd-close></div>
				<div class="whd-popup__dialog" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( self::ids()[ $id ]['label'] ); ?>" tabindex="-1">
					<button type="button" class="whd-popup__close" data-whd-close aria-label="<?php esc_attr_e( 'Close', 'whd' ); ?>">&times;</button>
					<?php if ( $s['image'] ) : ?>
						<div class="whd-popup__image"><img src="<?php echo esc_url( $s['image'] ); ?>" alt=""></div>
					<?php endif; ?>
					<div class="whd-popup__body"><?php echo WHD_Blocks::render( $design, 'popup', $ctx ); ?></div>
				</div>
			</div>
			<?php
			$printed = true;
		}
		if ( $printed ) {
			wp_enqueue_style( 'whd-popups', WHD_URL . 'assets/popups.css', [], WHD_VERSION );
			wp_enqueue_script( 'whd-popups', WHD_URL . 'assets/popups.js', [], WHD_VERSION, true );
			// Assets enqueued from the footer still print because wp_footer runs wp_print_footer_scripts after us.
		}
	}
}
