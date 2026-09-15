<?php
/**
 * Tracking & verification scripts: GA4, Google Search Console verification,
 * Meta (Facebook) Pixel, plus raw head/body/footer snippets. Purchase events
 * are pushed to GA4 and the Pixel on the order-received page.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Scripts {

	const OPTION = 'whd_scripts';

	public static function defaults() {
		return [
			'ga4_id'           => '',
			'gsc_verification' => '',
			'fb_pixel_id'      => '',
			'head_extra'       => '',
			'body_extra'       => '',
			'footer_extra'     => '',
			'exclude_admins'   => 1,
		];
	}

	public static function get() {
		return wp_parse_args( get_option( self::OPTION, [] ), self::defaults() );
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : [];
		$out   = self::defaults();
		$out['ga4_id']           = preg_match( '/^G-[A-Z0-9]{4,20}$/i', trim( $input['ga4_id'] ?? '' ) ) ? strtoupper( trim( $input['ga4_id'] ) ) : '';
		$out['fb_pixel_id']      = preg_replace( '/\D+/', '', $input['fb_pixel_id'] ?? '' );
		$gsc                     = trim( $input['gsc_verification'] ?? '' );
		if ( preg_match( '/content=["\']([^"\']+)["\']/', $gsc, $m ) ) {
			$gsc = $m[1]; // whole <meta> tag pasted — keep just the token
		}
		$out['gsc_verification'] = sanitize_text_field( $gsc );
		$out['exclude_admins']   = empty( $input['exclude_admins'] ) ? 0 : 1;
		// Raw snippets: only users allowed to publish unfiltered HTML may store script tags.
		foreach ( [ 'head_extra', 'body_extra', 'footer_extra' ] as $k ) {
			$raw       = (string) ( $input[ $k ] ?? '' );
			$out[ $k ] = current_user_can( 'unfiltered_html' ) ? $raw : wp_kses_post( $raw );
		}
		return $out;
	}

	public static function init() {
		add_action( 'wp_head', [ __CLASS__, 'head' ], 2 );
		add_action( 'wp_body_open', [ __CLASS__, 'body' ], 1 );
		add_action( 'wp_footer', [ __CLASS__, 'footer' ], 99 );
		add_action( 'woocommerce_thankyou', [ __CLASS__, 'purchase_event' ], 20 );
	}

	private static function tracking_off() {
		$s = self::get();
		return is_admin() || is_customize_preview() || ( $s['exclude_admins'] && current_user_can( 'manage_options' ) );
	}

	public static function head() {
		$s = self::get();
		// Verification tag prints for everyone: Google fetches the page anonymously.
		if ( $s['gsc_verification'] ) {
			echo '<meta name="google-site-verification" content="' . esc_attr( $s['gsc_verification'] ) . '">' . "\n";
		}
		if ( self::tracking_off() ) {
			return;
		}
		if ( $s['ga4_id'] ) {
			$id = esc_js( $s['ga4_id'] );
			echo "<!-- WHD: GA4 -->\n<script async src=\"https://www.googletagmanager.com/gtag/js?id={$id}\"></script>\n<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{$id}');</script>\n";
		}
		if ( $s['fb_pixel_id'] ) {
			$id = esc_js( $s['fb_pixel_id'] );
			echo "<!-- WHD: Meta Pixel -->\n<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','{$id}');fbq('track','PageView');</script>\n";
		}
		if ( $s['head_extra'] ) {
			echo "<!-- WHD: custom head -->\n" . $s['head_extra'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- admin-provided script
		}
	}

	public static function body() {
		if ( self::tracking_off() ) {
			return;
		}
		$s = self::get();
		if ( $s['fb_pixel_id'] ) {
			echo '<noscript><img height="1" width="1" style="display:none" alt="" src="https://www.facebook.com/tr?id=' . esc_attr( $s['fb_pixel_id'] ) . '&ev=PageView&noscript=1"></noscript>' . "\n";
		}
		if ( $s['body_extra'] ) {
			echo $s['body_extra'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	public static function footer() {
		if ( self::tracking_off() ) {
			return;
		}
		$s = self::get();
		if ( $s['footer_extra'] ) {
			echo "<!-- WHD: custom footer -->\n" . $s['footer_extra'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/** GA4 + Pixel purchase events on the thank-you page (once per order). */
	public static function purchase_event( $order_id ) {
		if ( self::tracking_off() ) {
			return;
		}
		$s     = self::get();
		$order = wc_get_order( $order_id );
		if ( ! $order || ( ! $s['ga4_id'] && ! $s['fb_pixel_id'] ) || $order->get_meta( '_whd_tracked' ) ) {
			return;
		}
		$order->update_meta_data( '_whd_tracked', 1 );
		$order->save();
		$items = [];
		foreach ( $order->get_items() as $item ) {
			$p       = $item->get_product();
			$items[] = [ 'item_id' => $p ? $p->get_sku() ?: (string) $p->get_id() : (string) $item->get_product_id(), 'item_name' => $item->get_name(), 'quantity' => $item->get_quantity(), 'price' => round( (float) $item->get_total() / max( 1, $item->get_quantity() ), 2 ) ];
		}
		$payload = [ 'transaction_id' => (string) $order->get_order_number(), 'value' => (float) $order->get_total(), 'currency' => $order->get_currency(), 'tax' => (float) $order->get_total_tax(), 'shipping' => (float) $order->get_shipping_total(), 'items' => $items ];
		echo '<script>';
		if ( $s['ga4_id'] ) {
			echo "if(window.gtag){gtag('event','purchase'," . wp_json_encode( $payload ) . ');}';
		}
		if ( $s['fb_pixel_id'] ) {
			echo "if(window.fbq){fbq('track','Purchase',{value:" . (float) $order->get_total() . ",currency:'" . esc_js( $order->get_currency() ) . "',content_type:'product',content_ids:" . wp_json_encode( array_column( $items, 'item_id' ) ) . '});}';
		}
		echo '</script>';
	}
}
