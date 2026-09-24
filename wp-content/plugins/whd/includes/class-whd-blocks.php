<?php
/**
 * Block definitions, sanitising and rendering shared by the popup and email
 * builders. A "design" is:
 *
 *   [ 'settings' => [ … document settings … ],
 *     'blocks'   => [ [ 'type' => 'heading', 'props' => [ … ] ], … ] ]
 *
 * The same design renders as div-based HTML for popups and as table-based,
 * inline-styled HTML for emails, so the admin live preview IS the output.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Blocks {

	const SERIF = "'Cormorant Garamond', Georgia, 'Times New Roman', serif";
	const SANS  = "'Manrope', 'Helvetica Neue', Helvetica, Arial, sans-serif";

	/* ─────────────────────────── definitions ─────────────────────────── */

	/** Block types available in a builder mode ('popup' | 'email'). */
	public static function types( $mode ) {
		$align = [ 'type' => 'align', 'label' => __( 'Alignment', 'whd' ), 'default' => 'center' ];

		$types = [
			'heading' => [
				'label'  => __( 'Heading', 'whd' ),
				'icon'   => 'H',
				'fields' => [
					'text'  => [ 'type' => 'text', 'label' => __( 'Text', 'whd' ), 'default' => __( 'A heading', 'whd' ) ],
					'level' => [ 'type' => 'select', 'label' => __( 'Size', 'whd' ), 'default' => 'h2', 'options' => [ 'h1' => __( 'Large', 'whd' ), 'h2' => __( 'Medium', 'whd' ), 'h3' => __( 'Small', 'whd' ) ] ],
					'align' => $align,
					'color' => [ 'type' => 'color', 'label' => __( 'Colour', 'whd' ), 'default' => '#3a2b26' ],
				],
			],
			'text' => [
				'label'  => __( 'Text', 'whd' ),
				'icon'   => '¶',
				'fields' => [
					'text'  => [ 'type' => 'textarea', 'label' => __( 'Text (basic HTML and {merge_tags} allowed)', 'whd' ), 'default' => __( 'Write something people will want to read.', 'whd' ) ],
					'align' => $align,
					'size'  => [ 'type' => 'number', 'label' => __( 'Font size (px)', 'whd' ), 'default' => 16, 'min' => 10, 'max' => 40 ],
					'color' => [ 'type' => 'color', 'label' => __( 'Colour', 'whd' ), 'default' => '#4a3f3a' ],
				],
			],
			'image' => [
				'label'  => __( 'Image', 'whd' ),
				'icon'   => '▣',
				'fields' => [
					'url'   => [ 'type' => 'image', 'label' => __( 'Image', 'whd' ), 'default' => '' ],
					'alt'   => [ 'type' => 'text', 'label' => __( 'Alt text', 'whd' ), 'default' => '' ],
					'width' => [ 'type' => 'number', 'label' => __( 'Width (%)', 'whd' ), 'default' => 100, 'min' => 10, 'max' => 100 ],
					'align' => $align,
					'link'  => [ 'type' => 'url', 'label' => __( 'Link (optional)', 'whd' ), 'default' => '' ],
				],
			],
			'button' => [
				'label'  => __( 'Button', 'whd' ),
				'icon'   => '▭',
				'fields' => [
					'text'   => [ 'type' => 'text', 'label' => __( 'Label', 'whd' ), 'default' => __( 'Shop now', 'whd' ) ],
					'url'    => [ 'type' => 'url', 'label' => __( 'Link', 'whd' ), 'default' => '{shop_url}' ],
					'align'  => $align,
					'bg'     => [ 'type' => 'color', 'label' => __( 'Background', 'whd' ), 'default' => '#3a2b26' ],
					'color'  => [ 'type' => 'color', 'label' => __( 'Text colour', 'whd' ), 'default' => '#f7f2ed' ],
					'radius' => [ 'type' => 'number', 'label' => __( 'Corner radius (px)', 'whd' ), 'default' => 0, 'min' => 0, 'max' => 40 ],
					'full'   => [ 'type' => 'toggle', 'label' => __( 'Full width', 'whd' ), 'default' => 0 ],
				],
			],
			'coupon' => [
				'label'  => __( 'Coupon code', 'whd' ),
				'icon'   => '%',
				'fields' => [
					'code'  => [ 'type' => 'text', 'label' => __( 'Code', 'whd' ), 'default' => '{coupon_code}' ],
					'note'  => [ 'type' => 'text', 'label' => __( 'Note', 'whd' ), 'default' => __( 'Use at checkout', 'whd' ) ],
					'color' => [ 'type' => 'color', 'label' => __( 'Accent colour', 'whd' ), 'default' => '#b98b7e' ],
				],
			],
			'divider' => [
				'label'  => __( 'Divider', 'whd' ),
				'icon'   => '—',
				'fields' => [ 'color' => [ 'type' => 'color', 'label' => __( 'Colour', 'whd' ), 'default' => '#e9e1da' ] ],
			],
			'spacer' => [
				'label'  => __( 'Spacer', 'whd' ),
				'icon'   => '↕',
				'fields' => [ 'height' => [ 'type' => 'number', 'label' => __( 'Height (px)', 'whd' ), 'default' => 24, 'min' => 4, 'max' => 160 ] ],
			],
		];

		if ( 'popup' === $mode ) {
			$types['form'] = [
				'label'  => __( 'Sign-up form', 'whd' ),
				'icon'   => '✉',
				'fields' => [
					'show_name'         => [ 'type' => 'toggle', 'label' => __( 'Ask for a first name', 'whd' ), 'default' => 0 ],
					'show_phone'        => [ 'type' => 'toggle', 'label' => __( 'Ask for a mobile number (SMS)', 'whd' ), 'default' => 1 ],
					'name_placeholder'  => [ 'type' => 'text', 'label' => __( 'Name placeholder', 'whd' ), 'default' => __( 'First name', 'whd' ) ],
					'email_placeholder' => [ 'type' => 'text', 'label' => __( 'Email placeholder', 'whd' ), 'default' => __( 'Your email', 'whd' ) ],
					'phone_placeholder' => [ 'type' => 'text', 'label' => __( 'Phone placeholder', 'whd' ), 'default' => __( 'Mobile (optional)', 'whd' ) ],
					'button_text'       => [ 'type' => 'text', 'label' => __( 'Button label', 'whd' ), 'default' => __( 'Send my code', 'whd' ) ],
					'consent_text'      => [ 'type' => 'textarea', 'label' => __( 'SMS consent text (shown with the mobile field)', 'whd' ), 'default' => self::default_consent_text() ],
					'success_text'      => [ 'type' => 'textarea', 'label' => __( 'Thank-you message (HTML and {merge_tags} allowed)', 'whd' ), 'default' => __( 'You’re in — use code <b>{coupon_code}</b> at checkout.', 'whd' ), 'help' => __( 'Shown in place of the form after sign-up, and to visitors who already signed up.', 'whd' ) ],
					'source'            => [ 'type' => 'text', 'label' => __( 'List source (saved with the subscriber)', 'whd' ), 'default' => 'popup' ],
					'bg'                => [ 'type' => 'color', 'label' => __( 'Button background', 'whd' ), 'default' => '#3a2b26' ],
					'color'             => [ 'type' => 'color', 'label' => __( 'Button text colour', 'whd' ), 'default' => '#f7f2ed' ],
					'radius'            => [ 'type' => 'number', 'label' => __( 'Corner radius (px)', 'whd' ), 'default' => 0, 'min' => 0, 'max' => 40 ],
				],
			];
			$types['countdown'] = [
				'label'  => __( 'Countdown (cookie-based)', 'whd' ),
				'icon'   => '⏱',
				'fields' => [
					'minutes'        => [ 'type' => 'number', 'label' => __( 'Length (minutes)', 'whd' ), 'default' => 15, 'min' => 1, 'max' => 10080 ],
					'label'          => [ 'type' => 'text', 'label' => __( 'Label', 'whd' ), 'default' => __( 'Offer ends in', 'whd' ) ],
					'expired'        => [ 'type' => 'text', 'label' => __( 'Text when expired', 'whd' ), 'default' => __( 'This offer has ended', 'whd' ) ],
					'color'          => [ 'type' => 'color', 'label' => __( 'Digit colour', 'whd' ), 'default' => '#3a2b26' ],
					'hide_on_expire' => [ 'type' => 'toggle', 'label' => __( 'Hide the popup once expired', 'whd' ), 'default' => 0 ],
				],
			];
		}

		if ( 'email' === $mode ) {
			$types['order_items'] = [
				'label'  => __( 'Order items', 'whd' ),
				'icon'   => '☰',
				'fields' => [ 'show_images' => [ 'type' => 'toggle', 'label' => __( 'Show product images', 'whd' ), 'default' => 1 ] ],
			];
			$types['order_summary'] = [
				'label'  => __( 'Order totals & addresses', 'whd' ),
				'icon'   => 'Σ',
				'fields' => [ 'show_addresses' => [ 'type' => 'toggle', 'label' => __( 'Show billing / shipping addresses', 'whd' ), 'default' => 1 ] ],
			];
			$types['cart_items'] = [
				'label'  => __( 'Abandoned cart items', 'whd' ),
				'icon'   => '🛒',
				'fields' => [ 'show_images' => [ 'type' => 'toggle', 'label' => __( 'Show product images', 'whd' ), 'default' => 1 ] ],
			];
		}

		return apply_filters( 'whd_block_types', $types, $mode );
	}

	/** Consent line for the SMS checkbox — from the integrations module when it is there. */
	public static function default_consent_text() {
		if ( class_exists( 'WHD_Integrations' ) && method_exists( 'WHD_Integrations', 'consent_text' ) ) {
			$text = trim( (string) WHD_Integrations::consent_text() );
			if ( '' !== $text ) {
				return $text;
			}
		}
		return __( 'Text me new arrivals and offers. Message and data rates may apply. Reply STOP to opt out.', 'whd' );
	}

	public static function popup_settings_defaults() {
		return [
			'enabled'     => 0,
			'cookie_days' => 7,     // don't show again for N days after it has been shown
			'delay'       => 4,     // seconds (welcome popup) / minimum time on page before exit-intent arms
			'scroll_pct'  => 0,     // optional: only after the visitor scrolled N%
			'show_on'     => 'all', // all | home | shop | not_checkout
			'width'       => 520,
			'bg'          => '#fffaf6',
			'overlay'     => 'rgba(58,43,38,0.55)',
			'radius'      => 0,
			'padding'     => 40,
			'image'       => '',    // optional side image (desktop)
		];
	}

	public static function email_settings_defaults() {
		return [
			'enabled'   => 0,
			'subject'   => '',
			'preheader' => '',
			'width'     => 600,
			'bg'        => '#f7f2ed',
			'card'      => '#ffffff',
			'padding'   => 36,
			'footer'    => '{site_name} · {site_url}',
		];
	}

	/** Merge tags with a short description (also shown in the editor). */
	public static function merge_tags() {
		return [
			'site_name'        => __( 'Store name', 'whd' ),
			'site_url'         => __( 'Store URL', 'whd' ),
			'shop_url'         => __( 'Shop page URL', 'whd' ),
			'cart_url'         => __( 'Cart URL', 'whd' ),
			'checkout_url'     => __( 'Checkout URL', 'whd' ),
			'my_account_url'   => __( 'My-account URL', 'whd' ),
			'customer_name'    => __( 'Customer full name', 'whd' ),
			'first_name'       => __( 'Customer first name', 'whd' ),
			'email'            => __( 'Customer email', 'whd' ),
			'order_number'     => __( 'Order number', 'whd' ),
			'order_date'       => __( 'Order date', 'whd' ),
			'order_total'      => __( 'Order total', 'whd' ),
			'order_status'     => __( 'Order status', 'whd' ),
			'order_url'        => __( 'View-order URL (My account)', 'whd' ),
			'payment_url'      => __( 'Pay-for-order URL', 'whd' ),
			'shipping_method'  => __( 'Shipping method', 'whd' ),
			'customer_note'    => __( 'Note to customer', 'whd' ),
			'recovery_url'     => __( 'Abandoned cart: restore-cart link', 'whd' ),
			'cart_total'       => __( 'Abandoned cart: total', 'whd' ),
			'coupon_code'      => __( 'Coupon code (WHD → Settings)', 'whd' ),
			'exit_coupon'      => __( 'Exit-intent coupon code (WHD → Settings)', 'whd' ),
			'reset_url'        => __( 'Password reset link', 'whd' ),
			'set_password_url' => __( 'Set-password link (new accounts)', 'whd' ),
			'user_login'       => __( 'Username', 'whd' ),
			'unsubscribe_url'  => __( 'Unsubscribe link', 'whd' ),
			'year'             => __( 'Current year', 'whd' ),
		];
	}

	/* ─────────────────────────── sanitising ─────────────────────────── */

	public static function sanitize_design( $design, $mode ) {
		$design   = is_array( $design ) ? $design : [];
		$types    = self::types( $mode );
		$defaults = 'popup' === $mode ? self::popup_settings_defaults() : self::email_settings_defaults();
		$out      = [ 'settings' => [], 'blocks' => [] ];

		$settings = isset( $design['settings'] ) && is_array( $design['settings'] ) ? $design['settings'] : [];
		foreach ( $defaults as $key => $default ) {
			$value = $settings[ $key ] ?? $default;
			if ( is_int( $default ) ) {
				$value = (int) $value;
			} elseif ( in_array( $key, [ 'bg', 'card' ], true ) ) {
				$value = sanitize_hex_color( $value ) ?: $default;
			} elseif ( 'overlay' === $key ) {
				$value = preg_match( '/^(#[0-9a-f]{3,8}|rgba?\([\d\s.,%]+\))$/i', trim( $value ) ) ? trim( $value ) : $default;
			} elseif ( 'image' === $key ) {
				$value = esc_url_raw( $value );
			} elseif ( 'show_on' === $key ) {
				$value = in_array( $value, [ 'all', 'home', 'shop', 'not_checkout' ], true ) ? $value : 'all';
			} elseif ( 'footer' === $key ) {
				$value = wp_kses_post( $value );
			} else {
				$value = sanitize_text_field( $value );
			}
			$out['settings'][ $key ] = $value;
		}

		$blocks = isset( $design['blocks'] ) && is_array( $design['blocks'] ) ? $design['blocks'] : [];
		foreach ( $blocks as $block ) {
			$type = $block['type'] ?? '';
			if ( ! isset( $types[ $type ] ) ) {
				continue;
			}
			$props = [];
			foreach ( $types[ $type ]['fields'] as $name => $field ) {
				$raw = isset( $block['props'][ $name ] ) ? $block['props'][ $name ] : $field['default'];
				switch ( $field['type'] ) {
					case 'textarea':
						$props[ $name ] = wp_kses( (string) $raw, [ 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 'a' => [ 'href' => [], 'target' => [] ], 'span' => [ 'style' => [] ], 'p' => [] ] );
						break;
					case 'url':
					case 'image':
						$raw            = trim( (string) $raw );
						$props[ $name ] = ( '' === $raw || preg_match( '/^\{[a-z_]+\}$/', $raw ) ) ? $raw : esc_url_raw( $raw );
						break;
					case 'color':
						$props[ $name ] = sanitize_hex_color( $raw ) ?: $field['default'];
						break;
					case 'number':
						$n              = (int) $raw;
						$props[ $name ] = max( $field['min'] ?? 0, min( $field['max'] ?? PHP_INT_MAX, $n ) );
						break;
					case 'select':
						$props[ $name ] = isset( $field['options'][ $raw ] ) ? $raw : $field['default'];
						break;
					case 'align':
						$props[ $name ] = in_array( $raw, [ 'left', 'center', 'right' ], true ) ? $raw : 'center';
						break;
					case 'toggle':
						$props[ $name ] = (int) (bool) $raw;
						break;
					default:
						$props[ $name ] = sanitize_text_field( (string) $raw );
				}
			}
			$out['blocks'][] = [ 'type' => $type, 'props' => $props ];
		}
		return $out;
	}

	/** Fill a design with defaults for any missing props (for rendering older designs). */
	public static function with_defaults( $design, $mode ) {
		$types = self::types( $mode );
		$design['settings'] = wp_parse_args( $design['settings'] ?? [], 'popup' === $mode ? self::popup_settings_defaults() : self::email_settings_defaults() );
		foreach ( $design['blocks'] ?? [] as $i => $block ) {
			if ( isset( $types[ $block['type'] ] ) ) {
				foreach ( $types[ $block['type'] ]['fields'] as $name => $field ) {
					if ( ! isset( $block['props'][ $name ] ) ) {
						$design['blocks'][ $i ]['props'][ $name ] = $field['default'];
					}
				}
			}
		}
		return $design;
	}

	/* ─────────────────────────── merge tags ─────────────────────────── */

	/** Replace {tags} using $ctx['data']; unknown tags are left as-is. */
	public static function merge( $text, $ctx ) {
		$data = $ctx['data'] ?? [];
		return preg_replace_callback( '/\{([a-z_]+)\}/', function ( $m ) use ( $data ) {
			return array_key_exists( $m[1], $data ) ? (string) $data[ $m[1] ] : $m[0];
		}, (string) $text );
	}

	/* ─────────────────────────── rendering ─────────────────────────── */

	/**
	 * Render a whole design.
	 *  popup → the inner HTML of the dialog body
	 *  email → a complete HTML document
	 */
	public static function render( $design, $mode, $ctx = [] ) {
		$design = self::with_defaults( $design, $mode );
		$s      = $design['settings'];
		$html   = '';
		foreach ( $design['blocks'] as $i => $block ) {
			$html .= self::render_block( $block, $mode, $ctx, $i );
		}

		if ( 'popup' === $mode ) {
			return $html;
		}

		$width   = (int) $s['width'];
		$pad     = (int) $s['padding'];
		$title   = esc_html( self::merge( $s['subject'] ?: get_bloginfo( 'name' ), $ctx ) );
		$pre     = esc_html( self::merge( $s['preheader'], $ctx ) );
		$footer  = wp_kses_post( self::merge( $s['footer'], $ctx ) );
		$unsub   = ! empty( $ctx['data']['unsubscribe_url'] ) ? '<br><a href="' . esc_url( $ctx['data']['unsubscribe_url'] ) . '" style="color:#9c6f63;text-decoration:underline">' . esc_html__( 'Unsubscribe', 'whd' ) . '</a>' : '';

		return '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="x-apple-disable-message-reformatting"><title>' . $title . '</title></head>'
			. '<body style="margin:0;padding:0;background:' . esc_attr( $s['bg'] ) . ';-webkit-text-size-adjust:100%">'
			. ( $pre ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">' . $pre . str_repeat( '&nbsp;&zwnj;', 40 ) . '</div>' : '' )
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . esc_attr( $s['bg'] ) . '"><tr><td align="center" style="padding:32px 12px">'
			. '<table role="presentation" width="' . $width . '" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:' . $width . 'px;background:' . esc_attr( $s['card'] ) . ';font-family:' . self::SANS . '">'
			. '<tr><td style="padding:' . $pad . 'px">' . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $html . '</table>' . '</td></tr>'
			. '</table>'
			. '<p style="margin:22px 0 0;font-family:' . self::SANS . ';font-size:12px;line-height:1.6;color:#7d7470;text-align:center">' . $footer . $unsub . '</p>'
			. '</td></tr></table></body></html>';
	}

	/** Render one block. Emails get a <tr> wrapper; popups a <div>. */
	public static function render_block( $block, $mode, $ctx = [], $index = 0 ) {
		$p     = $block['props'];
		$type  = $block['type'];
		$email = 'email' === $mode;
		$inner = '';

		switch ( $type ) {
			case 'heading':
				$sizes = [ 'h1' => 40, 'h2' => 30, 'h3' => 22 ];
				$tag   = $email ? 'p' : $p['level']; // real headings inside email tables trip some clients
				$inner = '<' . $tag . ' class="whd-b whd-b-heading whd-b-heading--' . esc_attr( $p['level'] ) . '" style="margin:0 0 14px;font-family:' . self::SERIF . ';font-weight:500;font-size:' . $sizes[ $p['level'] ] . 'px;line-height:1.15;text-align:' . esc_attr( $p['align'] ) . ';color:' . esc_attr( $p['color'] ) . '">' . esc_html( self::merge( $p['text'], $ctx ) ) . '</' . $tag . '>';
				break;

			case 'text':
				$text  = self::merge( $p['text'], $ctx );
				$text  = wp_kses_post( wpautop( $text ) );
				$text  = str_replace( '<p>', '<p style="margin:0 0 12px;font-family:' . self::SANS . ';font-size:' . (int) $p['size'] . 'px;line-height:1.65;color:' . esc_attr( $p['color'] ) . ';text-align:' . esc_attr( $p['align'] ) . '">', $text );
				$inner = '<div class="whd-b whd-b-text">' . $text . '</div>';
				break;

			case 'image':
				if ( $p['url'] ) {
					$img   = '<img src="' . esc_url( self::merge( $p['url'], $ctx ) ) . '" alt="' . esc_attr( $p['alt'] ) . '" width="' . (int) $p['width'] . '%" style="display:inline-block;width:' . (int) $p['width'] . '%;max-width:100%;height:auto;border:0">';
					$link  = $p['link'] ? self::merge( $p['link'], $ctx ) : '';
					$inner = '<div class="whd-b whd-b-image" style="text-align:' . esc_attr( $p['align'] ) . ';margin:0 0 16px">' . ( $link ? '<a href="' . esc_url( $link ) . '">' . $img . '</a>' : $img ) . '</div>';
				}
				break;

			case 'button':
				$url   = self::merge( $p['url'], $ctx );
				$style = 'display:' . ( $p['full'] ? 'block' : 'inline-block' ) . ';padding:15px 30px;background:' . esc_attr( $p['bg'] ) . ';color:' . esc_attr( $p['color'] ) . ';text-decoration:none;border-radius:' . (int) $p['radius'] . 'px;font-family:' . self::SANS . ';font-size:12px;font-weight:600;letter-spacing:.16em;text-transform:uppercase;text-align:center;line-height:1';
				$inner = '<div class="whd-b whd-b-button" style="text-align:' . esc_attr( $p['align'] ) . ';margin:8px 0 16px"><a class="whd-btn" href="' . esc_url( $url ) . '" style="' . $style . '">' . esc_html( self::merge( $p['text'], $ctx ) ) . '</a></div>';
				break;

			case 'coupon':
				$code  = esc_html( self::merge( $p['code'], $ctx ) );
				$inner = '<div class="whd-b whd-b-coupon" style="margin:8px 0 18px;text-align:center"><div style="display:inline-block;padding:14px 26px;border:1px dashed ' . esc_attr( $p['color'] ) . ';font-family:' . self::SANS . ';font-size:20px;font-weight:700;letter-spacing:.22em;color:' . esc_attr( $p['color'] ) . '">' . $code . '</div>' . ( $p['note'] ? '<div style="margin-top:8px;font-family:' . self::SANS . ';font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:#7d7470">' . esc_html( self::merge( $p['note'], $ctx ) ) . '</div>' : '' ) . '</div>';
				break;

			case 'divider':
				$inner = '<div class="whd-b whd-b-divider" style="height:1px;background:' . esc_attr( $p['color'] ) . ';margin:14px 0"></div>';
				break;

			case 'spacer':
				$inner = '<div class="whd-b whd-b-spacer" style="height:' . (int) $p['height'] . 'px;line-height:' . (int) $p['height'] . 'px;font-size:0">&nbsp;</div>';
				break;

			case 'form':
				if ( $email ) {
					break; // capture forms are a popup thing
				}
				$source  = sanitize_key( $p['source'] ) ?: 'popup';
				$success = wp_kses_post( self::merge( $p['success_text'], $ctx ) );
				$fields  = '';
				if ( ! empty( $p['show_name'] ) ) {
					$fields .= '<input class="whd-form__input" type="text" name="name" autocomplete="given-name" placeholder="' . esc_attr( $p['name_placeholder'] ) . '" aria-label="' . esc_attr( $p['name_placeholder'] ) . '">';
				}
				$fields .= '<input class="whd-form__input" type="email" name="email" required autocomplete="email" placeholder="' . esc_attr( $p['email_placeholder'] ) . '" aria-label="' . esc_attr( $p['email_placeholder'] ) . '">';
				if ( ! empty( $p['show_phone'] ) ) {
					$fields .= '<input class="whd-form__input" type="tel" name="phone" autocomplete="tel" placeholder="' . esc_attr( $p['phone_placeholder'] ) . '" aria-label="' . esc_attr( $p['phone_placeholder'] ) . '">'
						. '<label class="whd-form__consent"><input type="checkbox" name="sms_consent" value="1"><span>' . wp_kses_post( self::merge( $p['consent_text'], $ctx ) ) . '</span></label>';
				}
				$inner = '<form class="whd-b whd-b-form whd-form" method="post" action="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-source="' . esc_attr( $source ) . '" data-success="' . esc_attr( $success ) . '"'
					. ' data-invalid="' . esc_attr__( 'Please enter a valid email address.', 'whd' ) . '"'
					. ' data-error="' . esc_attr__( 'Something went wrong. Please try again.', 'whd' ) . '"'
					. ' data-preview="' . esc_attr__( 'Preview only — nothing was sent.', 'whd' ) . '" novalidate>'
					. '<input type="hidden" name="action" value="whd_subscribe">'
					. '<input type="hidden" name="nonce" value="' . esc_attr( wp_create_nonce( 'whd_subscribe' ) ) . '">'
					. '<input type="hidden" name="source" value="' . esc_attr( $source ) . '">'
					. $fields
					. '<button type="submit" class="whd-form__btn" style="background:' . esc_attr( $p['bg'] ) . ';color:' . esc_attr( $p['color'] ) . ';border-radius:' . (int) $p['radius'] . 'px">' . esc_html( self::merge( $p['button_text'], $ctx ) ) . '</button>'
					. '<p class="whd-form__status" role="status" aria-live="polite"></p>'
					. '</form>';
				break;

			case 'countdown':
				$inner = '<div class="whd-b whd-b-countdown whd-countdown" data-minutes="' . (int) $p['minutes'] . '" data-expired="' . esc_attr( $p['expired'] ) . '" data-hide="' . ( $p['hide_on_expire'] ? '1' : '0' ) . '" data-key="cd' . (int) $index . '" style="text-align:center;margin:6px 0 18px">'
					. '<div class="whd-countdown__label" style="font-family:' . self::SANS . ';font-size:11px;letter-spacing:.24em;text-transform:uppercase;color:#7d7470;margin-bottom:6px">' . esc_html( $p['label'] ) . '</div>'
					. '<div class="whd-countdown__time" style="font-family:' . self::SERIF . ';font-size:44px;font-weight:500;letter-spacing:.06em;line-height:1;color:' . esc_attr( $p['color'] ) . '">' . sprintf( '%02d:00', (int) $p['minutes'] ) . '</div></div>';
				break;

			case 'order_items':
				$inner = self::render_items( $ctx['items'] ?? [], ! empty( $p['show_images'] ), __( 'Your order', 'whd' ) );
				break;

			case 'cart_items':
				$inner = self::render_items( $ctx['cart_items'] ?? [], ! empty( $p['show_images'] ), __( 'Still in your bag', 'whd' ) );
				break;

			case 'order_summary':
				$inner = self::render_summary( $ctx, ! empty( $p['show_addresses'] ) );
				break;
		}

		if ( '' === $inner ) {
			return '';
		}
		return $email ? '<tr><td>' . $inner . '</td></tr>' : $inner;
	}

	/** Line-items table shared by order and abandoned-cart blocks. */
	private static function render_items( $items, $images, $caption ) {
		if ( ! $items ) {
			return '';
		}
		$rows = '';
		foreach ( $items as $it ) {
			$img  = ( $images && ! empty( $it['image'] ) ) ? '<img src="' . esc_url( $it['image'] ) . '" alt="" width="64" style="display:block;width:64px;height:auto;border:0;background:#f4efea">' : '';
			$name = esc_html( $it['name'] );
			$name = ! empty( $it['url'] ) ? '<a href="' . esc_url( $it['url'] ) . '" style="color:#3a2b26;text-decoration:none">' . $name . '</a>' : $name;
			$rows .= '<tr>'
				. ( $images ? '<td style="padding:10px 12px 10px 0;width:64px;vertical-align:top">' . $img . '</td>' : '' )
				. '<td style="padding:10px 0;vertical-align:top;font-family:' . self::SANS . ';font-size:14px;line-height:1.5;color:#3a2b26">' . $name . ( ! empty( $it['meta'] ) ? '<div style="font-size:12px;color:#7d7470">' . esc_html( $it['meta'] ) . '</div>' : '' ) . '<div style="font-size:12px;color:#7d7470">× ' . (int) $it['qty'] . '</div></td>'
				. '<td align="right" style="padding:10px 0;vertical-align:top;white-space:nowrap;font-family:' . self::SANS . ';font-size:14px;color:#3a2b26">' . wp_kses_post( $it['total'] ) . '</td>'
				. '</tr>';
		}
		return '<div class="whd-b whd-b-items" style="margin:0 0 16px"><div style="font-family:' . self::SANS . ';font-size:11px;letter-spacing:.22em;text-transform:uppercase;color:#b98b7e;margin:0 0 6px">' . esc_html( $caption ) . '</div>'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #e9e1da;border-bottom:1px solid #e9e1da">' . $rows . '</table></div>';
	}

	private static function render_summary( $ctx, $addresses ) {
		$totals = $ctx['totals'] ?? [];
		if ( ! $totals ) {
			return '';
		}
		$rows = '';
		foreach ( $totals as $label => $value ) {
			$bold  = strtolower( $label ) === strtolower( __( 'Total', 'whd' ) );
			$rows .= '<tr><td style="padding:6px 0;font-family:' . self::SANS . ';font-size:' . ( $bold ? 15 : 13 ) . 'px;color:' . ( $bold ? '#3a2b26' : '#7d7470' ) . ';' . ( $bold ? 'font-weight:700;' : '' ) . '">' . esc_html( $label ) . '</td><td align="right" style="padding:6px 0;font-family:' . self::SANS . ';font-size:' . ( $bold ? 15 : 13 ) . 'px;color:#3a2b26;' . ( $bold ? 'font-weight:700;' : '' ) . '">' . wp_kses_post( $value ) . '</td></tr>';
		}
		$html = '<div class="whd-b whd-b-summary" style="margin:0 0 18px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table></div>';

		if ( $addresses && ( ! empty( $ctx['billing_address'] ) || ! empty( $ctx['shipping_address'] ) ) ) {
			$cell = function ( $title, $addr ) {
				return '<td width="50%" style="vertical-align:top;padding:0 8px 0 0;font-family:' . self::SANS . ';font-size:13px;line-height:1.6;color:#4a3f3a"><div style="font-size:11px;letter-spacing:.22em;text-transform:uppercase;color:#b98b7e;margin-bottom:6px">' . esc_html( $title ) . '</div>' . wp_kses_post( $addr ) . '</td>';
			};
			$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px"><tr>' . $cell( __( 'Billing', 'whd' ), $ctx['billing_address'] ?? '' ) . $cell( __( 'Shipping', 'whd' ), $ctx['shipping_address'] ?? '' ) . '</tr></table>';
		}
		return $html;
	}
}
