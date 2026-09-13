<?php

function ideapark_theme_colors() {
	/**
	 * @var $background_color                            string
	 * @var $text_color                                  string
	 * @var $text_color_body                             string
	 * @var $text_color_light                            string
	 * @var $text_color_med_light                        string
	 * @var $accent_color                                string
	 * @var $border_color_light                          string
	 * @var $border_color_dark                           string
	 */
	return [
		'background_color'     => $bg_color = ideapark_mod_hex_color_norm( 'background_color', '#FFFFFF' ),
		'text_color'           => $text_color = ideapark_mod_hex_color_norm( 'text_color' ),
		'text_color_body'      => ideapark_hex_to_rgb_overlay( $bg_color, $text_color, 0.75 ),
		'text_color_light'     => ideapark_hex_to_rgb_overlay( $bg_color, $text_color, 0.5 ),
		'text_color_med_light' => ideapark_hex_to_rgb_overlay( $bg_color, $text_color, 0.4 ),
		'accent_color'         => ideapark_mod_hex_color_norm( 'accent_color' ),
		'border_color_light'   => ideapark_mod_hex_color_norm( 'button_color_light' ),
		'border_color_dark'    => ideapark_mod_hex_color_norm( 'button_color_dark' ),
	];
}

function ideapark_customize_css( $is_return_value = false ) {

	$custom_css = '';

	/**
	 * @var $background_color                            string
	 * @var $text_color                                  string
	 * @var $text_color_body                             string
	 * @var $text_color_light                            string
	 * @var $text_color_med_light                        string
	 * @var $accent_color                                string
	 * @var $border_color_light                          string
	 * @var $border_color_dark                           string
	 */
	extract( ideapark_theme_colors() );

	$text_color_lighting               = ideapark_hex_lighting( $text_color );
	$transparent_header_color_lighting = ideapark_hex_lighting( ideapark_mod_hex_color_norm( 'transparent_header_color', '#FFFFFF' ) );

	$desktop_category_title_width      = (int) ideapark_mod( 'category_carousel_title_width' ) + 100;
	$desktop_category_item_width       = ( (int) ideapark_mod( 'category_carousel_width_desktop' ) ?: 50 );
	$desktop_category_list_count_boxed = floor( ( 1170 - $desktop_category_title_width - 1 ) / $desktop_category_item_width );
	$desktop_category_css              = '';

	$i         = floor( ( 1190 - $desktop_category_title_width - 30 * 2 - 1 ) / $desktop_category_item_width );
	$min_width = 1190;
	do {
		$screen_width = $desktop_category_title_width + 30 * 2 + ( $i + 1 ) * $desktop_category_item_width + 1;

		if ( $screen_width < 1920 ) {
			$desktop_category_css .= "
			@media (min-width: " . $min_width . "px) and (max-width: " . $screen_width . "px) {
				.c-page-header__sub-cat--fullwidth {
					max-width: calc(var(--sub-cat-item-width) * " . $i . " + 1px);
				}
			}
			";
		} else {
			$desktop_category_css .= "
			@media (min-width: 1920px) {
				.c-page-header__sub-cat--fullwidth {
					max-width: calc(var(--sub-cat-item-width) * " . $i . " + 1px);
				}
			}
			";
		}
		$min_width = $screen_width + 1;
		$i ++;
	} while ( $screen_width < 1920 );

	$lang_postfix = ideapark_get_lang_postfix();
	$font_text    = preg_replace( '~^(custom-|system-)~', '',  (string) ( ideapark_mod( 'theme_font_text' . $lang_postfix ) ?: ideapark_mod( 'theme_font_text' ) ) );

	$custom_css .= '
	<style> 
		:root {
			--text-color: ' . esc_attr( $text_color ) . ';
			--text-color-body: ' . esc_attr( $text_color_body ) . ';
			--text-color-light: ' . esc_attr( $text_color_light ) . ';
			--text-color-med-light: ' . esc_attr( $text_color_med_light ) . ';
			--text-color-tr: ' . ideapark_hex_to_rgba( $text_color, 0.15 ) . ';
			--text-color-tr-50: ' . ideapark_hex_to_rgba( $text_color, 0.25 ) . ';
			--border-color-light: ' . esc_attr( $border_color_light ) . ';
			--border-color-dark: ' . esc_attr( $border_color_dark ) . ';
			--button-color: ' . ideapark_mod_hex_color_norm( 'button_color' ) . ';
			--button-color-hover: ' . ideapark_mod_hex_color_norm( 'button_color_hover' ) . ';
			--background-color: ' . esc_attr( $background_color ) . ';
			--background-color-tr-80: ' . ideapark_hex_to_rgba( $background_color, 0.8 ) . ';
			--background-color-dark: ' . ideapark_hex_to_rgb_overlay( $background_color, '#000000', 0.03 ) . ';
			--accent-color: ' . esc_attr( $accent_color ) . ';
			--accent-color-dark: ' . ideapark_hex_to_rgb_overlay( $accent_color, '#000000', 0.1 ) . ';
			--white-color: ' . esc_attr( $text_color_lighting > 128 ? '#000000' : '#FFFFFF' ) . ';
			--red-color: #DA432E;
			--smart-color: ' . esc_attr( $text_color_lighting > 128 ? $background_color : $text_color ) . ';
			--star-rating-color: ' . ideapark_mod_hex_color_norm( 'star_rating_color', $text_color ) . ';
			--font-text: "' . esc_attr( $font_text ) . '", sans-serif;
			--font-icons: "theme-icons";
			--text-transform: ' . ( ideapark_mod( 'capitalize_headers' ) ? 'capitalize' : 'none' ) . ';
			--logo-size: ' . esc_attr( (int) ( ideapark_mod( 'logo_size' ) ) ) . 'px;
			--logo-size-sticky: ' . esc_attr( (int) ( (int) ideapark_mod( 'sticky_logo_desktop_size' ) ?: ideapark_mod( 'logo_size' ) ) ) . 'px;
			--logo-size-mobile: ' . esc_attr( (int) ( ideapark_mod( 'logo_size_mobile' ) ) ) . 'px;
			--shadow-color-desktop: ' . ideapark_hex_to_rgba( ideapark_mod_hex_color_norm( 'shadow_color_desktop', '#FFFFFF' ), 0.95 ) . ';
			--search-color-desktop: ' . esc_attr( ideapark_hex_lighting( ideapark_mod_hex_color_norm( 'shadow_color_desktop', '#FFFFFF' ) ) > 128 ? ( $text_color_lighting > 128 ? $background_color : $text_color ) : ( $text_color_lighting > 128 ? $text_color : $background_color ) ) . ';
			--custom-transform-transition: visibility 0.3s cubic-bezier(0.86, 0, 0.07, 1), opacity 0.3s cubic-bezier(0.86, 0, 0.07, 1), transform 0.3s cubic-bezier(0.86, 0, 0.07, 1), box-shadow 0.3s cubic-bezier(0.86, 0, 0.07, 1);
			--opacity-transition: opacity 0.3s linear, visibility 0.3s linear;
			--opacity-transform-transition: opacity 0.3s linear, visibility 0.3s linear, transform 0.3s ease-out, box-shadow 0.3s ease-out;
			--hover-transition: opacity 0.15s linear, visibility 0.15s linear, color 0.15s linear, border-color 0.15s linear, background-color 0.15s linear, box-shadow 0.15s linear;
			--star-rating-image: url("data:image/svg+xml;base64,' . ideapark_b64enc( '<svg height="12" fill="' . ideapark_mod_hex_color_norm( 'star_rating_color', $text_color ) . '" viewBox="0 0 16 12" width="16" xmlns="http://www.w3.org/2000/svg"><path d="M11.77 11.355c.055.172-.144.314-.294.21L7.998 9.133 4.52 11.562c-.15.104-.349-.038-.294-.21l1.285-3.99L2.078 4.87c-.148-.107-.072-.336.112-.338l4.272-.036L7.82.528c.058-.17.305-.17.363 0l1.355 3.969 4.272.039c.184.002.26.231.112.338l-3.434 2.49z"/></svg>' ) . '");
			--select-image: url("data:image/svg+xml;base64,' . ideapark_b64enc( '<svg width="10" height="8" viewBox="0 0 10 8" fill="none" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M4.978 4.815 8.293 1.5 9 2.207 4.978 6.23.955 2.207l.707-.707 3.316 3.315Z" fill="' . $text_color . '"/></svg>' ) . '");
			--select-ordering-image: url("data:image/svg+xml;base64,' . ideapark_b64enc( '<svg width="6" height="4" viewBox="0 0 6 4" fill="none" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M3 2.551.633.184 0 .816l3 3 3-3-.633-.632L3 2.55Z" fill="' . $text_color_light . '"/></svg>' ) . '");
			--reset-image: url("data:image/svg+xml;base64,' . ideapark_b64enc( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="' . $text_color . '"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>' ) . '");
			--li-image: url("data:image/svg+xml;base64,' . ideapark_b64enc( '<svg width="7" height="8" viewBox="0 0 7 8" fill="none" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M6.6.2a1 1 0 0 1 .2 1.4L3.052 6.597.241 3.317a1 1 0 0 1 1.518-1.301l1.189 1.387L5.2.4A1 1 0 0 1 6.6.2Z" fill="' . $accent_color . '"/></svg>' ) . '");
			--image-grid-prop: ' . (int) ( ideapark_mod( 'grid_image_prop' ) * 100 ) . '%;
			--image-grid-aspect-ratio: 100 / ' . (int) ( ideapark_mod( 'grid_image_prop' ) * 100 ) . ';
			--image-product-prop: ' . (int) ( ideapark_mod( 'product_image_prop' ) * 100 ) . '%;
			--image-product-prop-qv-mobile: ' . round( (float) ideapark_mod( 'product_image_prop' ) * 100 ) . '%;
			--image-product-aspect-ratio: 100 / ' . (int) ( ideapark_mod( 'product_image_prop' ) * 100 ) . ';
			
			--icon-zoom: "\f13b" /* ip-plus-zoom */;
			--icon-user: "\f150" /* ip-user */;
			--icon-close-small: "\f115" /* ip-close-small */;
			--icon-check: "\f113" /* ip-check */;
			--icon-select: "\f11b" /* ip-down_arrow */;
			--icon-select-bold: "\f11a" /* ip-down */;
			--icon-romb: "\f143" /* ip-romb */;
			--icon-calendar: "\f10e" /* ip-calendar */;
			--icon-li: "\f119" /* ip-dot */;
			--icon-submenu: "\f135" /* ip-menu-right */;
			--icon-eye-back: "\f11d" /* ip-eye-back */;
			--icon-heart-back: "\f124" /* ip-heart-active */;
			--icon-bag-added: "\f108" /* ip-bag-added */;
			--icon-bag-back: "\f109" /* ip-bag-back */;
			--icon-logout: "\f12d" /* ip-logout */;
			--icon-eye: "\f11e" /* ip-eye */;
			
			--image-background-color: ' . esc_attr( ideapark_mod_hex_color_norm( 'product_image_background_color', 'transparent' ) ) . ';
			--image-page-background-color: ' . esc_attr( ideapark_mod_hex_color_norm( 'product_page_image_background_color', 'transparent' ) ) . ';
			
			--fullwidth-limit: ' . ( ideapark_mod( 'limit_fullwidth_1920' ) ? '1920px' : '100%' ) . ';
			
			--container-default-padding-block-start: 0px;
			--container-default-padding-block-end: 0px;
			--container-default-padding-inline-start: 0px;
			--container-default-padding-inline-end: 0px;
		}
		
		.woobt-wrap:before {
			content: "' . esc_html__( 'Frequently Bought Together', 'moderno' ) . '";
		}
		
		.c-page-header {
			--title-font-size-desktop: ' . esc_attr( (int) ideapark_mod( 'header_font_size' ) ) . 'px;
			--title-font-size-mobile: ' . round( (float) ideapark_mod( 'header_font_size' ) * 20 / 24 ) . 'px;
			--cat-hover-color: var(--border-color-dark);
			--sub-cat-item-font-size: ' . esc_attr( (int) ideapark_mod( 'category_carousel_font_size' ) ) . 'px;
			--sub-cat-item-width: ' . esc_attr( (int) ideapark_mod( 'category_carousel_width_desktop' ) ) . 'px;
		}
	
		@media (min-width: 1190px) {	
			.c-page-header__sub-cat--boxed {
				max-width: calc(var(--sub-cat-item-width) * ' . esc_attr( $desktop_category_list_count_boxed ) . ' + 1px);
			}
		}
		
		@media (max-width: 1189px) {
			.c-page-header {
				--sub-cat-item-width: ' . esc_attr( (int) ideapark_mod( 'category_carousel_width_tablet' ) ) . 'px;
			}
		}
		
		@media (max-width: 767px) {
			.c-page-header {
				--sub-cat-item-width: ' . esc_attr( (int) ideapark_mod( 'category_carousel_width_mobile' ) ) . 'px;
			}
		}
		
		' . $desktop_category_css . '
		
		.owl-nav {
			--disable-color: var(--border-color-light);
		}
		
		.c-badge,
		.c-cart__item-out-of-stock {
			--badge-bgcolor-featured: ' . ideapark_mod_hex_color_norm( 'featured_badge_color', $text_color ) . ';
			--badge-bgcolor-new: ' . ideapark_mod_hex_color_norm( 'new_badge_color', $text_color ) . ';
			--badge-bgcolor-sale: ' . ideapark_mod_hex_color_norm( 'sale_badge_color', $accent_color ) . ';
			--badge-bgcolor-outofstock: ' . ideapark_mod_hex_color_norm( 'outofstock_badge_color', $text_color ) . ';
		}
		
		.c-to-top-button {
			--to-top-button-color: ' . ideapark_mod_hex_color_norm( 'to_top_button_color' ) . ';
		}
		
		.c-top-menu__list {
			--top-menu-submenu-color: ' . ideapark_mod_hex_color_norm( 'top_menu_submenu_color', $text_color ) . ';
			--top-menu-submenu-bg-color: ' . ideapark_mod_hex_color_norm( 'top_menu_submenu_bg_color', '#FFFFFF' ) . ';
			--top_menu_submenu_accent_color: ' . ideapark_mod_hex_color_norm( 'top_menu_submenu_accent_color', $accent_color ) . ';
			--top-menu-font-size: ' . esc_attr( (int) ideapark_mod( 'top_menu_font_size' ) ) . 'px;
			--top-menu-item-space: ' . esc_attr( (int) ideapark_mod( 'top_menu_item_space' ) ) . 'px; 
		}
		
		.c-product-grid__item {
			--font-size: ' . ideapark_mod( 'product_font_size' ) . 'px;
			--price-font-size: ' . ideapark_mod( 'product_price_font_size' ) . 'px;
			--font-size-mobile-1-rows: ' . ideapark_mod( 'product_font_size' ) . 'px;
			--font-size-mobile-2-rows: ' . round( (float) ideapark_mod( 'product_font_size' ) * 13 / 14 ) . 'px;
			--text-alignment: ' . ( ideapark_mod( 'product_grid_alignment' ) == 'left' ? 'start' : 'center' ) . ';
			--flex-justify: ' . ( ideapark_mod( 'product_grid_alignment' ) == 'left' ? 'flex-start' : 'center' ) . ';
			--flex-justify-space: ' . ( ideapark_mod( 'product_grid_alignment' ) == 'left' ? 'flex-start' : 'space-between' ) . ';
			--color-variations-size: ' . ideapark_mod( 'color_variations_size' ) . 'px;
		}
		
		.product {
			--font-size-desktop: ' . ideapark_mod( 'product_page_font_size_desktop' ) . 'px;
			--font-size-desktop-qv: ' . round( (float) ideapark_mod( 'product_page_font_size_desktop' ) * 34 / 45 ) . 'px;
			--font-size-mobile: ' . ideapark_mod( 'product_page_font_size_mobile' ) . 'px;
			--font-size-mobile-qv: ' . round( (float) ideapark_mod( 'product_page_font_size_mobile' ) * 34 / 40 ) . 'px;
		}
		
		.c-product__summary,
		.woobt-products {
			--price-font-size: ' . ideapark_mod( 'product_page_price_font_size' ) . 'px;
		}
		
		.l-header {
			--top-color: ' . esc_attr( $top_color = ideapark_mod_hex_color_norm( 'header_top_color', $text_color ) ) . ';
			--top-background-color: ' . esc_attr( $top_bg = ideapark_mod_hex_color_norm( 'header_top_background_color', $background_color ) ) . ';
			--top-hover-color: ' . esc_attr( ideapark_hex_to_rgb_overlay( $top_bg, $top_color, 0.5 ) ) . ';
			--top-accent-color: ' . esc_attr( ideapark_mod_hex_color_norm( 'header_top_accent_color', $accent_color ) ) . ';
			--top-first-line-color: ' . esc_attr( ideapark_mod_hex_color_norm( 'header_top_first_line', 'var(--border-color-dark)' ) ) . ';
			--top-second-line-color: ' . esc_attr( ideapark_mod_hex_color_norm( 'header_top_second_line', 'var(--border-color-light)' ) ) . ';
			
			--header-color-mobile: ' . ideapark_mod_hex_color_norm( 'mobile_header_color', $text_color ) . ';
			--header-color-bg-mobile: ' . ideapark_mod_hex_color_norm( 'mobile_header_background_color', $background_color ) . ';
			--header-color-accent-mobile: ' . esc_attr( ideapark_mod_hex_color_norm( 'header_top_accent_color', $accent_color ) ) . ';
			
			--transparent-header-color: ' . ideapark_mod_hex_color_norm( 'transparent_header_color', '#FFFFFF' ) . ';
			--transparent-header-color-inverse: ' . esc_attr( $transparent_header_color_lighting > 128 ? $text_color : '#FFFFFF' ) . ';
			--transparent-header-accent: ' . ideapark_mod_hex_color_norm( 'transparent_header_accent_color', $text_color ) . ';
			
			--header-height-mobile: ' . ideapark_mod( 'header_height_mobile' ) . 'px;
			--sticky-header-height-mobile: ' . ideapark_mod( 'sticky_header_height_mobile' ) . 'px; 
		}
		
		.woocommerce-store-notice {
			--store-notice-color: ' . ideapark_mod_hex_color_norm( 'store_notice_color' ) . ';
			--store-notice-background-color: ' . ideapark_mod_hex_color_norm( 'store_notice_background_color' ) . ';
		}
		
		.c-product-features {
			--feature-icon-color: ' . ideapark_mod_hex_color_norm( 'product_features_icon_color', $accent_color ) . ';
			--feature-text-color: ' . ideapark_mod_hex_color_norm( 'product_features_text_color', $text_color ) . ';
			--feature-description-color: ' . ideapark_mod_hex_color_norm( 'product_features_description_color', $text_color_body ) . ';
			--feature-background-color: ' . ideapark_mod_hex_color_norm( 'product_features_background_color', '#F9F9F9' ) . ';
			--feature-border: ' . ( ideapark_mod( 'product_features_border' ) ? 'dashed 1px ' . ideapark_mod_hex_color_norm( 'product_features_border_color', $border_color_dark ) : 'none' ) . ';
		}
		
		.c-product__custom-html {
			--custom-text-color: ' . ideapark_mod_hex_color_norm( 'product_html_text_color' ) . ';
			--custom-background-color: ' . ideapark_mod_hex_color_norm( 'product_html_background_color' ) . ';
			--custom-border: ' . ( ideapark_mod( 'product_html_border' ) ? 'dashed 1px ' . ideapark_mod_hex_color_norm( 'product_html_border_color', $border_color_dark ) : 'none' ) . ';
			--custom-columns: ' . ( ideapark_mod( 'product_html_2_col' ) ? 2 : 1 ) . ';
		}
		
		.c-post-list {
			--background: ' . ( ideapark_mod( 'post_grid_list_white_background' ) ? 'var(--white-color)' : 'transparent' ) . ';
		}
		
		.c-product__slider-item--video .mejs-mediaelement .wp-video-shortcode,
		.c-product__slider-item--video .c-inline-video {
			object-fit: ' . ideapark_mod( 'product_image_fit' ) . ';
		}
			
	</style>';

	$custom_css = preg_replace( '~[\r\n]~', '', preg_replace( '~[\t\s]+~', ' ', str_replace( [
		'<style>',
		'</style>'
	], [ '', '' ], $custom_css ) ) );

	if ( $custom_css ) {
		if ( $is_return_value ) {
			return $custom_css;
		} else {
			wp_add_inline_style( 'ideapark-core', $custom_css );
		}
	}

	return '';
}

function ideapark_uniord( $u ) {
	$k  = mb_convert_encoding( $u, 'UCS-2LE', 'UTF-8' );
	$k1 = ord( substr( $k, 0, 1 ) );
	$k2 = ord( substr( $k, 1, 1 ) );

	return $k2 * 256 + $k1;
}

function ideapark_b64enc( $input ) {

	$keyStr = "ABCDEFGHIJKLMNOP" .
	          "QRSTUVWXYZabcdef" .
	          "ghijklmnopqrstuv" .
	          "wxyz0123456789+/" .
	          "=";

	$output = "";
	$i      = 0;

	do {
		$chr1 = ord( substr( $input, $i ++, 1 ) );
		$chr2 = $i < strlen( $input ) ? ord( substr( $input, $i ++, 1 ) ) : null;
		$chr3 = $i < strlen( $input ) ? ord( substr( $input, $i ++, 1 ) ) : null;

		$enc1 = $chr1 >> 2;
		$enc2 = ( ( $chr1 & 3 ) << 4 ) | ( $chr2 >> 4 );
		$enc3 = ( ( $chr2 & 15 ) << 2 ) | ( $chr3 >> 6 );
		$enc4 = $chr3 & 63;

		if ( $chr2 === null ) {
			$enc3 = $enc4 = 64;
		} else if ( $chr3 === null ) {
			$enc4 = 64;
		}

		$output = $output .
		          substr( $keyStr, $enc1, 1 ) .
		          substr( $keyStr, $enc2, 1 ) .
		          substr( $keyStr, $enc3, 1 ) .
		          substr( $keyStr, $enc4, 1 );
		$chr1   = $chr2 = $chr3 = "";
		$enc1   = $enc2 = $enc3 = $enc4 = "";
	} while ( $i < strlen( $input ) );

	return $output;
}

