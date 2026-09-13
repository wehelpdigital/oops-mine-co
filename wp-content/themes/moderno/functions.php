<?php

/*------------------------------------*\
	Constants & Globals
\*------------------------------------*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

define( 'IDEAPARK_IS_AJAX', function_exists( 'wp_doing_ajax' ) ? wp_doing_ajax() : ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) );
define( 'IDEAPARK_IS_XML_HTTP_REQUEST', ( $n = '_SERVER' ) && ( 'xmlhttprequest' === strtolower( isset( $$n['HTTP_X_REQUESTED_WITH'] ) ? $$n['HTTP_X_REQUESTED_WITH'] : '' ) ) );
define( 'IDEAPARK_IS_AJAX_HEARTBEAT', IDEAPARK_IS_AJAX && ! empty( $_POST['action'] ) && ( $_POST['action'] == 'heartbeat' ) );
define( 'IDEAPARK_IS_AJAX_SEARCH', IDEAPARK_IS_AJAX && ! empty( $_POST['action'] ) && ( $_POST['action'] == 'ideapark_ajax_search' ) );
define( 'IDEAPARK_IS_AJAX_CSS', IDEAPARK_IS_AJAX && ! empty( $_POST['action'] ) && ( $_POST['action'] == 'ideapark_ajax_custom_css' ) );
define( 'IDEAPARK_IS_AJAX_WISHLIST', IDEAPARK_IS_AJAX && ! empty( $_POST['action'] ) && ( $_POST['action'] == 'ideapark_wishlist_toggle' ) );
define( 'IDEAPARK_IS_AJAX_QUICKVIEW', ! empty( $_REQUEST['action'] ) && ( $_REQUEST['action'] == 'ideapark_ajax_product' ) );
define( 'IDEAPARK_IS_AJAX_INFINITY', IDEAPARK_IS_XML_HTTP_REQUEST && ! empty( $_POST['ideapark_infinity_loading'] ) );
define( 'IDEAPARK_IS_AJAX_IMAGES', IDEAPARK_IS_AJAX && ! empty( $_REQUEST['action'] ) && ( $_REQUEST['action'] == 'ideapark_product_images' ) );
define( 'IDEAPARK_IS_AJAX_TAB', IDEAPARK_IS_AJAX && ! empty( $_REQUEST['action'] ) && ( $_REQUEST['action'] == 'ideapark_product_tab' ) );
define( 'IDEAPARK_NAME', 'Moderno' );
define( 'IDEAPARK_SLUG', 'moderno' );
define( 'IDEAPARK_DOMAIN', IDEAPARK_SLUG );
define( 'IDEAPARK_DIR', get_template_directory() );
define( 'IDEAPARK_URI', get_template_directory_uri() );
define( 'IDEAPARK_DEMO', false );
define( 'IDEAPARK_DEMO_URL', 'https://parkofideas.com/moderno/demo/' );
define( 'IDEAPARK_MANUAL', 'https://parkofideas.com/moderno/manual/' );
define( 'IDEAPARK_SUPPORT', 'https://parkofideas.ticksy.com/' );
define( 'IDEAPARK_CHANGELOG', 'https://themeforest.net/item/moderno-fashion-furniture-store-woocommerce-theme/45459282#item-description__moderno-theme-changelog' );
define( 'IDEAPARK_VERSION', '1.47' );

$wp_upload_arr = wp_get_upload_dir();

if ( preg_match( '~^https~i', IDEAPARK_URI ) && ! preg_match( '~^https~i', $wp_upload_arr['baseurl'] ) ) {
	$wp_upload_arr['baseurl'] = preg_replace( '~^http:~i', 'https:', $wp_upload_arr['baseurl'] );
}

define( "IDEAPARK_UPLOAD_DIR", $wp_upload_arr['basedir'] . "/" . strtolower( sanitize_file_name( IDEAPARK_SLUG ) ) . "/" );
define( "IDEAPARK_UPLOAD_URL", $wp_upload_arr['baseurl'] . "/" . strtolower( sanitize_file_name( IDEAPARK_SLUG ) ) . "/" );

/*------------------------------------*\
	Theme Support
\*------------------------------------*/

$ideapark_theme_scripts = [];
$ideapark_theme_styles  = [];
$ideapark_is_front_page = false;


if ( ! function_exists( 'ideapark_is_requset' ) ) {
	function ideapark_is_requset( $type ) {
		switch ( $type ) {
			case 'admin':
				return is_admin();
			case 'ajax':
				return defined( 'DOING_AJAX' );
			case 'cron':
				return defined( 'DOING_CRON' );
			case 'frontend':
				return ( ! is_admin() || defined( 'DOING_AJAX' ) || ( is_admin() && ! empty( $_GET['action'] ) && ( $_GET['action'] == 'elementor' ) ) ) && ! defined( 'DOING_CRON' ) && ! defined( 'REST_REQUEST' );
		}
	}
}

if ( ! function_exists( 'ideapark_setup' ) ) {

	function ideapark_setup() {

		if ( ! ideapark_is_dir( IDEAPARK_UPLOAD_DIR ) ) {
			ideapark_mkdir( IDEAPARK_UPLOAD_DIR );
		}

		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'automatic-feed-links' );
		add_theme_support( 'title-tag' );
		add_theme_support( 'custom-background' );
		add_theme_support( 'customize-selective-refresh-widgets' );
		add_theme_support( 'align-wide' );
		add_theme_support( 'editor-styles' );
		add_theme_support( 'post-formats', [ 'standard', 'image', 'gallery', 'video' ] );
		add_theme_support( 'responsive-embeds' );

		add_theme_support( 'html5', [ 'comment-list', 'comment-form', 'search-form', 'gallery', 'caption' ] );

		add_theme_support( 'woocommerce', [
			'thumbnail_image_width'         => 300,
			'gallery_thumbnail_image_width' => 100,
			'single_image_width'            => 600,
			'product_grid'                  => [
				'default_rows'    => 5,
				'min_rows'        => 1,
				'max_rows'        => 100,
				'default_columns' => 4,
				'min_columns'     => 2,
				'max_columns'     => 4,
			],
		] );

		add_theme_support( 'woo_variation_swatches', [
			'shape_style'          => 'rounded',
			'show_variation_label' => false,
			'enable_tooltip'       => true,
			'default_to_button'    => false,
			'width'                => 45,
			'height'               => 45,
			'show_on_archive'      => false,
		] );

		add_theme_support( 'rank-math-breadcrumbs' );
		add_theme_support( 'yoast-seo-breadcrumbs' );

		add_image_size( 'ideapark-thumbnail-x2', 200, 200, true );
		add_image_size( 'ideapark-compact', 145 );

		remove_image_size( '1536x1536' );
		remove_image_size( '2048x2048' );

		load_theme_textdomain( 'moderno', IDEAPARK_DIR . '/languages' );

		add_action( 'load_textdomain_mofile', 'ideapark_correct_tgmpa_mofile', 10, 2 );
		load_theme_textdomain( 'tgmpa', IDEAPARK_DIR . '/plugins/languages' );
		remove_action( 'load_textdomain_mofile', 'ideapark_correct_tgmpa_mofile', 10 );

		register_nav_menus( [
			'primary' => esc_html__( 'Primary (Desktop)', 'moderno' ),
			'mobile'  => esc_html__( 'Primary (Mobile)', 'moderno' ),
		] );
	}
}

if ( ! function_exists( 'ideapark_check_version' ) ) {
	function ideapark_check_version() {
		if ( ! defined( 'IFRAME_REQUEST' ) && ideapark_is_requset( 'admin' ) && ( ( $current_version = get_option( IDEAPARK_SLUG . '_theme_version', '' ) ) || ! $current_version ) && ( version_compare( $current_version, IDEAPARK_VERSION, '!=' ) ) ) {
			do_action( 'after_update_theme', $current_version, IDEAPARK_VERSION );
			add_action( 'wp_loaded', function () use ( $current_version ) {
				do_action( 'after_update_theme_late', $current_version, IDEAPARK_VERSION );
			}, 999 );
			update_option( IDEAPARK_SLUG . '_theme_version', IDEAPARK_VERSION );
			update_option( IDEAPARK_SLUG . '_about_page', 1, false );
		}
	}
}

if ( ! function_exists( 'ideapark_set_image_dimensions' ) ) {
	function ideapark_set_image_dimensions() {

		update_option( 'woocommerce_thumbnail_cropping', 'uncropped' );
		delete_option( 'woocommerce_thumbnail_cropping_custom_width' );
		delete_option( 'woocommerce_thumbnail_cropping_custom_height' );

		update_option( 'thumbnail_size_w', 100 );
		update_option( 'thumbnail_size_h', 100 );

		update_option( 'medium_size_w', 300 );
		update_option( 'medium_size_h', '' );

		update_option( 'medium_large_size_w', 600 );
		update_option( 'medium_large_size_h', '' );

		update_option( 'large_size_w', '960' );
		update_option( 'large_size_h', '' );

	}

	add_action( 'after_switch_theme', 'ideapark_set_image_dimensions', 1 );
	add_action( 'after_update_theme', 'ideapark_set_image_dimensions', 1 );
}

if ( ! function_exists( 'ideapark_woocommerce_get_image_size_gallery_thumbnail' ) ) {
	function ideapark_woocommerce_get_image_size_gallery_thumbnail( $size ) {
		$size['height'] = '';

		return $size;
	}
}

// Maximum width for media
if ( ! isset( $content_width ) ) {
	$content_width = 1170; // Pixels
}

require_once( IDEAPARK_DIR . '/includes/customize/ip_customize_settings.php' );
require_once( IDEAPARK_DIR . '/includes/customize/ip_customize_style.php' );
require_once( IDEAPARK_DIR . '/includes/megamenu/mega_menu.php' );

if ( is_admin() && ! IDEAPARK_IS_AJAX_SEARCH && ! IDEAPARK_IS_AJAX_CSS ) {
	require_once IDEAPARK_DIR . '/plugins/class-tgm-plugin-activation.php';
	add_action( 'tgmpa_register', 'ideapark_register_required_plugins' );
}

if ( is_admin() ) {
	require_once IDEAPARK_DIR . '/includes/theme-about/theme-about.php';
	require_once IDEAPARK_DIR . '/includes/lib/theme-fix.php';
}

if ( ! function_exists( 'ideapark_swatches_plugin_on' ) ) {
	function ideapark_swatches_plugin_on() {
		return function_exists( 'woo_variation_swatches' ) && defined( 'WOO_VARIATION_SWATCHES_PLUGIN_VERSION' ) ? 1 : 0;
	}
}

if ( ! function_exists( 'ideapark_metabox_plugin_on' ) ) {
	function ideapark_metabox_plugin_on() {
		return defined( 'RWMB_VER' ) ? 1 : 0;
	}
}

if ( ! function_exists( 'ideapark_core_plugin_on' ) ) {
	function ideapark_core_plugin_on() {
		return function_exists( 'ideapark_check_plugin_version' );
	}
}

if ( ! function_exists( 'ideapark_woocommerce_on' ) ) {
	function ideapark_woocommerce_on() {
		return class_exists( 'WooCommerce' ) ? 1 : 0;
	}
}

if ( ! function_exists( 'ideapark_woocommerce_ajax_on' ) ) {
	function ideapark_woocommerce_ajax_on() {
		return 'yes' === get_option( 'woocommerce_enable_ajax_add_to_cart' );
	}
}

if ( ! function_exists( 'ideapark_font_icons_loader_plugin_on' ) ) {
	function ideapark_font_icons_loader_plugin_on() {
		return class_exists( 'Ideapark_Fonts' ) ? 1 : 0;
	}
}

if ( ideapark_woocommerce_on() && ! IDEAPARK_IS_AJAX_HEARTBEAT ) {
	require_once( IDEAPARK_DIR . '/includes/woocommerce/woocommerce.php' );

	if ( ideapark_is_requset( 'frontend' ) || ideapark_is_requset( 'ajax' ) ) {
		require_once( IDEAPARK_DIR . '/includes/woocommerce/woocommerce-wishlist.php' );
	}
}

if ( ! function_exists( 'ideapark_get_required_plugins' ) ) {
	function ideapark_get_required_plugins() {
		/*
		 * Array of plugin arrays. Required keys are name and slug.
		 * If the source is NOT from the .org repo, then source is also required.
		 */
		$plugins = [
			[
				'name'     => esc_html__( 'Moderno Core', 'moderno' ),
				'slug'     => 'ideapark-moderno',
				'source'   => IDEAPARK_DIR . '/plugins/ideapark-moderno.zip',
				'required' => true,
				'version'  => IDEAPARK_VERSION,
			],

			[
				'name'     => esc_html__( 'Font Icons Loader', 'moderno' ),
				'slug'     => 'ideapark-fonts',
				'source'   => IDEAPARK_DIR . '/plugins/ideapark-fonts.zip',
				'required' => true,
				'version'  => '1.10',
			],

			[
				'name'     => esc_html__( 'WooCommerce', 'moderno' ),
				'slug'     => 'woocommerce',
				'required' => true
			],

			[
				'name'     => esc_html__( 'Meta Box', 'moderno' ),
				'slug'     => 'meta-box',
				'required' => true,
			],

			[
				'name'     => esc_html__( 'Elementor', 'moderno' ),
				'slug'     => 'elementor',
				'required' => true
			],

			[
				'name'     => esc_html__( 'Contact Form 7', 'moderno' ),
				'slug'     => 'contact-form-7',
				'required' => false,
			],

			[
				'name'     => esc_html__( 'Variation Swatches for WooCommerce', 'moderno' ),
				'slug'     => 'woo-variation-swatches',
				'required' => false,
			]
		];

		if ( ( $code = ideapark_get_purchase_code() ) && $code !== IDEAPARK_SKIP_REGISTER ) {
			return $plugins;
		}

		return array_filter( $plugins, function ( $plugin ) {
			return empty( $plugin['type'] ) || $plugin['type'] != 'api';
		} );
	}
}

if ( ! function_exists( 'ideapark_register_required_plugins' ) ) {
	function ideapark_register_required_plugins() {
		$plugins = ideapark_get_required_plugins();

		/*
		 * Array of configuration settings. Amend each line as needed.
		 *
		 * TGMPA will start providing localized text strings soon. If you already have translations of our standard
		 * strings available, please help us make TGMPA even better by giving us access to these translations or by
		 * sending in a pull-request with .po file(s) with the translations.
		 *
		 * Only uncomment the strings in the config array if you want to customize the strings.
		 */
		$config = [
			'id'           => 'moderno',
			// Unique ID for hashing notices for multiple instances of TGMPA.
			'default_path' => '',
			// Default absolute path to bundled plugins.
			'menu'         => 'tgmpa-install-plugins',
			// Menu slug.
			'parent_slug'  => 'themes.php',
			// Parent menu slug.
			'capability'   => 'edit_theme_options',
			// Capability needed to view plugin install page, should be a capability associated with the parent menu used.
			'has_notices'  => true,
			// Show admin notices or not.
			'dismissable'  => true,
			// If false, a user cannot dismiss the nag message.
			'dismiss_msg'  => '',
			// If 'dismissable' is false, this message will be output at top of nag.
			'is_automatic' => false,
			// Automatically activate plugins after installation or not.
			'message'      => '',
			// Message to output right before the plugins table.
		];

		tgmpa( $plugins, $config );
	}
}

if ( ! function_exists( 'ideapark_scripts_disable_cf7' ) ) {
	function ideapark_scripts_disable_cf7() {
		if ( ! is_singular() || is_front_page() ) {
			add_filter( 'wpcf7_load_js', '__return_false' );
			add_filter( 'wpcf7_load_css', '__return_false' );
		}
	}
}

if ( ! function_exists( 'ideapark_scripts' ) ) {
	function ideapark_scripts() {

		if ( $GLOBALS['pagenow'] != 'wp-login.php' && ! is_admin() ) {

			if ( ideapark_woocommerce_on() ) {
				wp_enqueue_script( 'wc-cart-fragments' );
				if ( ideapark_mod( 'load_jquery_in_footer' ) ) {
					wp_scripts()->add_data( 'wc-add-to-cart', 'group', 1 );
					wp_scripts()->add_data( 'woocommerce', 'group', 1 );
					wp_scripts()->add_data( 'js-cookie', 'group', 1 );
					wp_scripts()->add_data( 'wc-cart-fragments', 'group', 1 );
				}
			}

			ideapark_add_style( 'ideapark-entry-content', IDEAPARK_URI . '/assets/css/entry-content.css', [], ideapark_mtime( IDEAPARK_DIR . '/assets/css/entry-content.css' ), 'all' );
			ideapark_add_style( 'ideapark-core', IDEAPARK_URI . '/style.css', [], ideapark_mtime( IDEAPARK_DIR . '/style.css' ), 'all' );

			if ( ideapark_is_rtl() ) {
				ideapark_add_style( 'ideapark-rtl', IDEAPARK_URI . '/assets/css/rtl.css', [], ideapark_mtime( IDEAPARK_DIR . '/assets/css/rtl.css' ), 'all' );
			}

			if ( is_customize_preview() ) {
				wp_enqueue_style( 'ideapark-customize-preview', IDEAPARK_URI . '/assets/css/admin/admin-customizer-preview.css', [], ideapark_mtime( IDEAPARK_DIR . '/assets/css/admin/admin-customizer-preview.css' ), 'all' );
			}

			if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
				wp_enqueue_script( 'comment-reply', false, [], false, true );
			}

			ideapark_add_script( 'jquery-zoom', IDEAPARK_URI . '/assets/js/jquery.zoom.min.js', [ 'jquery' ], '1.7.21', true );
			ideapark_add_script( 'owl-carousel', IDEAPARK_URI . '/assets/js/owl.carousel.min.js', [ 'jquery' ], '2.3.4', true );
			ideapark_add_script( 'jquery-fitvids', IDEAPARK_URI . '/assets/js/jquery.fitvids.min.js', [ 'jquery' ], '1.1', true );
			ideapark_add_script( 'nice-select', IDEAPARK_URI . '/assets/js/jquery.nice-select.min.js', [ 'jquery' ], '1.0', true );

			if ( ideapark_woocommerce_on() && ideapark_mod( 'wishlist_page' ) ) {
				ideapark_add_script( 'ideapark-wishlist', IDEAPARK_URI . '/assets/js/wishlist.js', [ 'jquery' ], IDEAPARK_VERSION, true );
			}

			ideapark_add_script( 'ideapark-lib', IDEAPARK_URI . '/assets/js/site-lib.js', [ 'jquery' ], ideapark_mtime( IDEAPARK_DIR . '/assets/js/site-lib.js' ), true );
			ideapark_add_script( 'ideapark-core', IDEAPARK_URI . '/assets/js/site.js', [ 'jquery', 'ideapark-lib' ], ideapark_mtime( IDEAPARK_DIR . '/assets/js/site.js' ), true );
		}
	}
}

if ( ! function_exists( 'ideapark_scripts_load' ) ) {
	function ideapark_scripts_load() {
		ideapark_enqueue_style();
		ideapark_enqueue_script();

		if ( ideapark_woocommerce_on() ) {
			if ( ideapark_mod( 'disable_block_editor' ) ) {
				wp_dequeue_style( 'wc-block-style' );
				wp_dequeue_style( 'wc-blocks-style' );
			}
			wp_dequeue_style( 'woocommerce_prettyPhoto_css' );
			wp_dequeue_script( 'prettyPhoto' );
			wp_dequeue_script( 'prettyPhoto-init' );
		}

		if ( ideapark_mod( 'disable_block_editor' ) ) {
			wp_dequeue_style( 'wp-block-library' );
			wp_dequeue_style( 'wp-block-library-theme' );
		}

		if ( ideapark_mod( 'disable_elementor_default_gap' ) ) {
			wp_add_inline_style( 'elementor-frontend', '
			.elementor-column-gap-default > .elementor-row > .elementor-column > .elementor-element-populated,
			.elementor-column-gap-default > .elementor-column > .elementor-element-populated { padding: 0; }
			.e-con {
			--container-default-padding-top: 0;
			--container-default-padding-bottom: 0;
			--container-default-padding-left: 0;
			--container-default-padding-right: 0;
			}' );
		}

		if ( ideapark_mod( 'load_jquery_in_footer' ) ) {
			wp_scripts()->add_data( 'jquery', 'group', 1 );
			wp_scripts()->add_data( 'jquery-core', 'group', 1 );
			wp_scripts()->add_data( 'jquery-migrate', 'group', 1 );
		}

		wp_localize_script( 'ideapark-core', 'ideapark_wp_vars', ideapark_localize_vars() );

		global $wp_scripts;
		if ( ideapark_woocommerce_on() ) {
			foreach ( $wp_scripts->registered as $handler => $script ) {
				if ( $handler == 'wc-add-to-cart-variation' ) {
					wp_enqueue_script( 'wc-add-to-cart-variation-fix', IDEAPARK_URI . '/assets/js/add-to-cart-variation-fix.js', [
						'wc-add-to-cart-variation'
					], IDEAPARK_VERSION, true );
					if ( ideapark_mod( 'load_jquery_in_footer' ) ) {
						wp_scripts()->add_data( 'wc-add-to-cart-variation', 'group', 1 );
						wp_scripts()->add_data( 'wc-add-to-cart-variation-fix', 'group', 1 );
					}
					break;
				}
			}
		}

		$inline_css = '';

		if ( ideapark_woocommerce_on() ) {
			$font_path = str_replace( [
					'http:',
					'https:'
				], '', WC()->plugin_url() ) . '/assets/fonts/';

			$inline_css .= "
@font-face {
font-family: 'star';
src: url('{$font_path}star.eot');
src: url('{$font_path}star.eot?#iefix') format('embedded-opentype'),
	url('{$font_path}star.woff') format('woff'),
	url('{$font_path}star.ttf') format('truetype'),
	url('{$font_path}star.svg#star') format('svg');
font-weight: normal;
font-style: normal;
}";
		}
		if ( $inline_css ) {
			wp_add_inline_style( 'ideapark-core', $inline_css );
		}
	}
}

if ( ! function_exists( 'ideapark_widgets_init' ) ) {
	function ideapark_widgets_init() {

		register_sidebar( [
			'name'          => esc_html__( 'Post List', 'moderno' ),
			'id'            => 'post-sidebar',
			'before_widget' => '<aside id="%1$s" class="widget %2$s">',
			'after_widget'  => '</aside>',
			'before_title'  => '<div class="widget-title">',
			'after_title'   => '</div>',
		] );

		if ( ideapark_woocommerce_on() ) {
			if ( ideapark_mod( 'single_sidebar' ) ) {
				register_sidebar( [
					'name'          => esc_html__( 'Product List', 'moderno' ),
					'id'            => 'shop-sidebar',
					'before_widget' => '<aside id="%1$s" class="widget %2$s">',
					'after_widget'  => '</aside>',
					'before_title'  => '<div class="widget-title">',
					'after_title'   => '</div>',
				] );
			} else {
				register_sidebar( [
					'name'          => esc_html__( 'Product List (Desktop)', 'moderno' ),
					'id'            => 'shop-sidebar',
					'before_widget' => '<aside id="%1$s" class="widget %2$s">',
					'after_widget'  => '</aside>',
					'before_title'  => '<div class="widget-title">',
					'after_title'   => '</div>',
				] );
				register_sidebar( [
					'name'          => esc_html__( 'Product List (Mobile)', 'moderno' ),
					'id'            => 'filter-sidebar',
					'before_widget' => '<aside id="%1$s" class="widget %2$s">',
					'after_widget'  => '</aside>',
					'before_title'  => '<h2 class="widget-title">',
					'after_title'   => '</h2>',
				] );
			}
		}
	}
}

if ( ! function_exists( 'ideapark_disable_block_editor' ) ) {
	function ideapark_disable_block_editor() {
		if ( ideapark_mod( 'disable_block_editor' ) && ideapark_core_plugin_on() ) {
			add_filter( 'gutenberg_use_widgets_block_editor', '__return_false', 100 );
			add_filter( 'use_widgets_block_editor', '__return_false' );
			ideapark_ra( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
			ideapark_ra( 'wp_footer', 'wp_enqueue_global_styles', 1 );
			ideapark_ra( 'wp_body_open', 'wp_global_styles_render_svg_filters' );
		}
	}
}

if ( ! function_exists( 'ideapark_add_style' ) ) {
	function ideapark_add_style( $handle, $src = '', $deps = [], $ver = false, $media = 'all', $path = '' ) {
		global $ideapark_theme_styles;
		if ( ! array_key_exists( $handle, $ideapark_theme_styles ) ) {
			$ideapark_theme_styles[ $handle ] = [
				'handle' => $handle,
				'src'    => $src,
				'deps'   => $deps,
				'ver'    => $ver,
				'media'  => $media,
				'path'   => $path,
			];
		}
	}
}

if ( ! function_exists( 'ideapark_enqueue_style_hash' ) ) {
	function ideapark_enqueue_style_hash( $styles ) {
		$hash = IDEAPARK_VERSION . '_' . (string) ideapark_mtime( IDEAPARK_DIR . '/includes/customize/ip_customize_style.php' ) . '_' . ( IDEAPARK_DEMO ? 'on' : 'off' );

		if ( ! empty( $styles ) ) {
			foreach ( $styles as $item ) {
				if ( is_array( $item ) ) {
					$hash .= $item['ver'] . '_';
				} else {
					$hash .= (string) ideapark_mtime( IDEAPARK_DIR . $item ) . '_';
				}
			}
		}

		return $hash ? md5( $hash ) : '';
	}
}

if ( ! function_exists( 'ideapark_compress_css' ) ) {
	function ideapark_compress_css( $code ) {
		$patterns     = [];
		$replacements = [];

		$patterns[]     = '/\/\*.*?\*\//s';
		$replacements[] = '';

		$patterns[]     = '/\r\n|\r|\n|\t|\s\s+/';
		$replacements[] = '';

		$patterns[]     = '/\s?\:(?!(is\(|where\())\s?/';
		$replacements[] = ':';

		$patterns[]     = '/\s?\{\s?/';
		$replacements[] = '{';
		$patterns[]     = '/\s?\}\s?/';
		$replacements[] = '}';

		$patterns[]     = '/\s?\,\s?/';
		$replacements[] = ',';

		return preg_replace( $patterns, $replacements, $code );
	}
}

if ( ! function_exists( 'ideapark_editor_style' ) ) {
	function ideapark_editor_style() {

		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : false;
		$allowed = [ 'page', 'post', 'product', 'html_block', 'customize' ];
		if ( is_object( $screen ) && ! empty( $screen->id ) && in_array( $screen->id, $allowed ) ) {
			$styles = [
				'/assets/css/entry-content.css',
				'/assets/font/theme-icons.css'
			];

			if ( $hash = ideapark_enqueue_style_hash( $styles ) ) {
				if ( ! ideapark_is_dir( IDEAPARK_UPLOAD_DIR ) ) {
					ideapark_mkdir( IDEAPARK_UPLOAD_DIR );
				}
				if ( get_option( $option_name = 'ideapark_editor_styles_hash' ) != $hash || ! ideapark_is_file( IDEAPARK_UPLOAD_DIR . 'editor-styles.min.css' ) ) {

					$fonts = apply_filters( 'ideapark_theme_fonts', [
						ideapark_mod( 'theme_font_text' ),
					] );

					$code = '';

					if ( $google_font_uri = ideapark_get_google_font_uri( $fonts ) ) {
						$code .= "@" . "import url('" . esc_url( $google_font_uri ) . "');";
					}

					$code .= ideapark_custom_fonts_css();

					foreach ( $styles as $style ) {
						$code .= ideapark_fgc( IDEAPARK_DIR . $style );
					}
					$code = str_replace( 'url("./theme-icons', 'url("' . IDEAPARK_URI . '/assets/font/theme-icons', $code );
					$code .= ideapark_customize_css( true );

					$code = str_replace( ':is(.entry-content, div[class*="-text-editor"])', 'body', $code );
					$code = preg_replace( '~\.entry-content[\t\r\n\s]*\{~', 'body {', $code );
					$code = preg_replace( '~\.entry-content[\t\r\n\s]*~', '', $code );
					$code = preg_replace( '~(?<![a-z0-9_-])(button|input\[type=submit\])~i', '\\0:not(.components-button):not([role=presentation]):not(.mce-open)', $code );

					$code .= '
						body#tinymce {
							padding: 8px 12px !important;
						}
						.is-root-container,
						.editor-post-title {
							max-width: 660px;
							margin: 0 auto;
						}
						body h1.editor-post-title {
							margin-bottom: 20px;
							font-weight:    700;
							font-size:      24px;
							line-height:    1.375;
							letter-spacing: -0.015em;
							text-transform: uppercase;
						}
						@media (max-width: 767px) {
							body h1.editor-post-title {
								font-size:      20px;
								margin-bottom: 10px;
							}
						}
						';

					if ( ! ideapark_mod( 'sidebar_post' ) ) {
						$code .= '
						*.alignfull, .wp-block[data-align="full"] {
							margin-left:  0 !important;
							margin-right: 0 !important;
							width:        100% !important;
							max-width:    100% !important;
							padding-left: 0;
							padding-right: 0;
						}
						 *.alignwide, .wp-block[data-align="wide"] {
							margin-left:  auto !important;
							margin-right: auto !important;
							width:        100% !important;
							max-width:    914px !important;
						}
						';
					} else {
						$code .= '
						 *.alignfull, *.alignwide, .wp-block[data-align="wide"], .wp-block[data-align="full"] {
							width:        auto !important;
							margin-left:  auto !important;
							margin-right: auto !important;
						}
						';
					}

					$code = ideapark_compress_css( $code );

					ideapark_fpc( IDEAPARK_UPLOAD_DIR . 'editor-styles.min.css', $code );
					if ( get_option( $option_name ) !== null ) {
						update_option( $option_name, $hash );
					} else {
						add_option( $option_name, $hash );
					}
				}
			}

			add_editor_style( IDEAPARK_UPLOAD_URL . 'editor-styles.min.css' );
		}
	}
}

if ( ! function_exists( 'ideapark_enqueue_style' ) ) {
	function ideapark_enqueue_style() {
		global $ideapark_theme_styles;

		if ( ideapark_mod( 'use_minified_css' ) && ! is_customize_preview() ) {

			$lang_postfix = ideapark_get_lang_postfix();

			if ( $hash = ideapark_enqueue_style_hash( $ideapark_theme_styles ) . $lang_postfix ) {
				if ( ! ideapark_is_dir( IDEAPARK_UPLOAD_DIR ) ) {
					ideapark_mkdir( IDEAPARK_UPLOAD_DIR );
				}
				$css_path = IDEAPARK_UPLOAD_DIR . 'min' . $lang_postfix . '.css';
				$css_url  = IDEAPARK_UPLOAD_URL . 'min' . $lang_postfix . '.css';
				if ( get_option( $option_name = 'ideapark_styles_hash' . $lang_postfix ) != $hash || ! ideapark_is_file( $css_path ) ) {
					$code = "";
					foreach ( $ideapark_theme_styles as $style ) {
						$path = $style['path'] ? $style['path'] : ( IDEAPARK_DIR . preg_replace( '~^' . preg_quote( IDEAPARK_URI, '~' ) . '~', '', $style['src'] ) );
						$css  = ideapark_fgc( $path );
						$css  = preg_replace( '~url\("\./~', 'url("' . IDEAPARK_URI . dirname( preg_replace( '~^' . preg_quote( IDEAPARK_URI, '~' ) . '~', '', $style['src'] ) ) . '/', $css );
						$css  = preg_replace( '~\.\./fonts/~', IDEAPARK_URI . '/fonts/', $css );
						$css  = preg_replace( '~url\((assets/[^\)]+)\)~', 'url(' . IDEAPARK_URI . '/\\1)', $css );

						if ( ! ideapark_is_rtl() ) {
							$css = preg_replace( '~\.h-rtl[^,\}\{]+,~', '', $css );
							$css = preg_replace( '~\.h-rtl[^,\}\{]*\{[^\}]*\}~', '', $css );
						}
						$code .= $css;
					}
					$code .= ideapark_customize_css( true );
					$code = ideapark_compress_css( $code );
					ideapark_fpc( $css_path, $code );
					if ( get_option( $option_name ) !== null ) {
						update_option( $option_name, $hash );
					} else {
						add_option( $option_name, $hash );
					}
				}
			}
			wp_enqueue_style( 'ideapark-core', $css_url, [], ideapark_mtime( $css_path ), 'all' );
		} else {
			foreach ( $ideapark_theme_styles as $style ) {
				wp_enqueue_style( $style['handle'], $style['src'], $style['deps'], $style['ver'], $style['media'] );
			}
			ideapark_customize_css();
		}
	}
}

if ( ! function_exists( 'ideapark_add_script' ) ) {
	function ideapark_add_script( $handle, $src = '', $deps = [], $ver = false, $in_footer = false, $path = '' ) {
		global $ideapark_theme_scripts;
		if ( ! array_key_exists( $handle, $ideapark_theme_scripts ) ) {
			$ideapark_theme_scripts[ $handle ] = [
				'handle'    => $handle,
				'src'       => $src,
				'deps'      => $deps,
				'ver'       => $ver,
				'in_footer' => $in_footer,
				'path'      => $path,
			];
		}
	}
}

if ( ! function_exists( 'ideapark_enqueue_script' ) ) {
	function ideapark_enqueue_script() {
		global $ideapark_theme_scripts;

		$hash = '';

		if ( ideapark_mod( 'use_minified_js' ) ) {
			$deps = [];
			foreach ( $ideapark_theme_scripts as $script ) {
				$hash .= $script['ver'] . '_';
				$deps = array_merge( $deps, $script['deps'] );
			}
			$deps = array_unique( array_diff( $deps, array_keys( $ideapark_theme_scripts ) ) );
			if ( $hash ) {
				$hash = md5( $hash );
				if ( get_option( $option_name = 'ideapark_scripts_hash' ) != $hash || ! ideapark_is_file( IDEAPARK_UPLOAD_DIR . 'min.js' ) ) {

					class Ideapark_JS {
						const ORD_LF = 10;
						const ORD_SPACE = 32;

						protected $a = '';
						protected $b = '';
						protected $input = '';
						protected $inputIndex = 0;
						protected $inputLength = 0;
						protected $lookAhead = null;
						protected $output = '';

						public function __construct( $input ) {
							$this->input       = str_replace( "\r\n", "\n", $input );
							$this->inputLength = strlen( $this->input );
						}

						public static function min( $js ) {
							$ideapark_JS = new Ideapark_JS( $js );

							return $ideapark_JS->ideapark_min();
						}

						protected function ideapark_min() {
							$this->a = "\n";
							$this->ideapark_action( 3 );

							while ( $this->a !== null ) {
								switch ( $this->a ) {
									case ' ':
										if ( $this->ideapark_isAlphaNum( $this->b ) ) {
											$this->ideapark_action( 1 );
										} else {
											$this->ideapark_action( 2 );
										}
										break;

									case "\n":
										switch ( $this->b ) {
											case '{':
											case '[':
											case '(':
											case '+':
											case '-':
												$this->ideapark_action( 1 );
												break;

											case ' ':
												$this->ideapark_action( 3 );
												break;

											default:
												if ( $this->ideapark_isAlphaNum( $this->b ) ) {
													$this->ideapark_action( 1 );
												} else {
													$this->ideapark_action( 2 );
												}
										}
										break;

									default:
										switch ( $this->b ) {
											case ' ':
												if ( $this->ideapark_isAlphaNum( $this->a ) ) {
													$this->ideapark_action( 1 );
													break;
												}

												$this->ideapark_action( 3 );
												break;

											case "\n":
												switch ( $this->a ) {
													case '}':
													case ']':
													case ')':
													case '+':
													case '-':
													case '"':
													case "'":
														$this->ideapark_action( 1 );
														break;

													default:
														if ( $this->ideapark_isAlphaNum( $this->a ) ) {
															$this->ideapark_action( 1 );
														} else {
															$this->ideapark_action( 3 );
														}
												}
												break;

											default:
												$this->ideapark_action( 1 );
												break;
										}
								}
							}

							return $this->output;
						}

						protected function ideapark_action( $d ) {
							switch ( $d ) {
								case 1:
									$this->output .= $this->a;

								case 2:
									$this->a = $this->b;

									if ( $this->a === "'" || $this->a === '"' ) {
										for ( ; ; ) {
											$this->output .= $this->a;
											$this->a      = $this->ideapark_get();

											if ( $this->a === $this->b ) {
												break;
											}

											if ( ord( $this->a ) <= self::ORD_LF ) {
												throw new Exception( 'Unterminated string literal.' );
											}

											if ( $this->a === '\\' ) {
												$this->output .= $this->a;
												$this->a      = $this->ideapark_get();
											}
										}
									}

								case 3:
									$this->b = $this->ideapark_next();

									if ( $this->b === '/' && (
											$this->a === '(' || $this->a === ',' || $this->a === '=' ||
											$this->a === ':' || $this->a === '[' || $this->a === '!' ||
											$this->a === '&' || $this->a === '|' || $this->a === '?' ||
											$this->a === '{' || $this->a === '}' || $this->a === ';' ||
											$this->a === "\n" ) ) {

										$this->output .= $this->a . $this->b;

										for ( ; ; ) {
											$this->a = $this->ideapark_get();

											if ( $this->a === '[' ) {
												/*
												  inside a regex [...] set, which MAY contain a '/' itself. Example: mootools Form.Validator near line 460:
													return Form.Validator.getValidator('IsEmpty').test(element) || (/^(?:[a-z0-9!#$%&'*+/=?^_`{|}~-]\.?){0,63}[a-z0-9!#$%&'*+/=?^_`{|}~-]@(?:(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?|\[(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\])$/i).test(element.get('value'));
												*/
												for ( ; ; ) {
													$this->output .= $this->a;
													$this->a      = $this->ideapark_get();

													if ( $this->a === ']' ) {
														break;
													} elseif ( $this->a === '\\' ) {
														$this->output .= $this->a;
														$this->a      = $this->ideapark_get();
													} elseif ( ord( $this->a ) <= self::ORD_LF ) {
														throw new Exception( 'Unterminated regular expression set in regex literal.' );
													}
												}
											} elseif ( $this->a === '/' ) {
												break;
											} elseif ( $this->a === '\\' ) {
												$this->output .= $this->a;
												$this->a      = $this->ideapark_get();
											} elseif ( ord( $this->a ) <= self::ORD_LF ) {
												throw new Exception( 'Unterminated regular expression literal.' );
											}

											$this->output .= $this->a;
										}

										$this->b = $this->ideapark_next();
									}
							}
						}

						protected function ideapark_get() {
							$c               = $this->lookAhead;
							$this->lookAhead = null;

							if ( $c === null ) {
								if ( $this->inputIndex < $this->inputLength ) {
									$c                = substr( $this->input, $this->inputIndex, 1 );
									$this->inputIndex += 1;
								} else {
									$c = null;
								}
							}

							if ( $c === "\r" ) {
								return "\n";
							}

							if ( $c === null || $c === "\n" || ord( $c ) >= self::ORD_SPACE ) {
								return $c;
							}

							return ' ';
						}

						protected function ideapark_next() {
							$c = $this->ideapark_get();

							if ( $c === '/' ) {
								switch ( $this->ideapark_peek() ) {
									case '/':
										for ( ; ; ) {
											$c = $this->ideapark_get();

											if ( ord( $c ) <= self::ORD_LF ) {
												return $c;
											}
										}

									case '*':
										$this->ideapark_get();

										for ( ; ; ) {
											switch ( $this->ideapark_get() ) {
												case '*':
													if ( $this->ideapark_peek() === '/' ) {
														$this->ideapark_get();

														return ' ';
													}
													break;

												case null:
													throw new Exception( 'Unterminated comment.' );
											}
										}

									default:
										return $c;
								}
							}

							return $c;
						}

						protected function ideapark_peek() {
							$this->lookAhead = $this->ideapark_get();

							return $this->lookAhead;
						}

						protected function ideapark_isAlphaNum( $c ) {
							return $c !== null && ( ord( $c ) > 126 || $c === '\\' || preg_match( '/^[\w\$]$/', $c ) === 1 );
						}
					}

					$code = "";
					foreach ( $ideapark_theme_scripts as $script ) {
						$path        = $script['path'] ? $script['path'] : ( IDEAPARK_DIR . preg_replace( '~^' . preg_quote( IDEAPARK_URI, '~' ) . '~', '', $script['src'] ) );
						$script_code = ideapark_fgc( $path );
						$code        .= strpos( $path, '.min' ) !== false ? $script_code : Ideapark_JS::min( $script_code );
					}
					ideapark_fpc( IDEAPARK_UPLOAD_DIR . 'min.js', $code );
					if ( get_option( $option_name ) !== null ) {
						update_option( $option_name, $hash );
					} else {
						add_option( $option_name, $hash );
					}
				}

				wp_enqueue_script( 'ideapark-core', IDEAPARK_UPLOAD_URL . 'min.js', $deps, ideapark_mtime( IDEAPARK_UPLOAD_DIR . 'min.js' ), true );
			}
		}

		if ( ! $hash ) {
			foreach ( $ideapark_theme_scripts as $script ) {
				wp_enqueue_script( $script['handle'], $script['src'], $script['deps'], $script['ver'], $script['in_footer'] );
			}
		}
	}
}

if ( ! function_exists( 'ideapark_custom_excerpt_length' ) ) {
	function ideapark_custom_excerpt_length( $length ) {
		if ( ! is_admin() || ideapark_is_elementor_preview() || IDEAPARK_IS_AJAX ) {
			$length = ideapark_mod( 'post_layout' ) == 'list' ? 22 : 18;
		}

		return $length;
	}
}

if ( ! function_exists( 'ideapark_excerpt_more' ) ) {
	function ideapark_excerpt_more( $more ) {
		return '&hellip;';
	}
}

if ( ! function_exists( 'ideapark_product_search_sku' ) ) {
	function ideapark_product_search_sku( $args, $wp_query ) {
		global $wpdb;

		if ( empty( $wp_query->query_vars['s'] ) ) {
			return $args;
		}

		if ( ! IDEAPARK_IS_AJAX_SEARCH && ( is_admin() || ! $wp_query->is_main_query() ) ) {
			return $args;
		}

		if ( ! ideapark_mod( 'search_by_sku' ) ) {
			return $args;
		}

		if ( strlen( ( $s = trim( preg_replace( '~\s\s+~', ' ', $wp_query->query_vars['s'] ) ) ) ) > 0 ) {
			$e = explode( ' ', $s );

			$search_ids = ideapark_search_by_sku_ids( $e );

			if ( sizeof( $search_ids ) > 0 && ! empty( $args['where'] ) ) {
				$args['where'] = str_replace( '((' . $wpdb->posts . '.post_title LIKE', '(( ' . $wpdb->posts . '.ID IN (' . implode( ',', $search_ids ) . ')) OR (' . $wpdb->posts . '.post_title LIKE', $args['where'] );
			}
		}

		return $args;
	}
}

if ( ! function_exists( 'ideapark_search_by_sku_ids' ) ) {
	function ideapark_search_by_sku_ids( $e ) {
		global $wpdb;

		$_where = [];
		foreach ( $e as $word ) {
			$s        = '%' . esc_sql( $wpdb->esc_like( $word ) ) . '%';
			$_where[] = $wpdb->prepare( "( pm.meta_value LIKE %s )", $s );
		}

		$sku_to_id = $wpdb->get_col( "
			SELECT pm.post_id
			FROM {$wpdb->postmeta} pm 
			WHERE pm.meta_key='_sku' AND ( " . implode( " AND ", $_where ) . " )" );

		$sku_to_parent_id = $wpdb->get_col( "
			SELECT p.post_parent post_id 
			FROM {$wpdb->posts} p 
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id and pm.meta_key='_sku' and ( " . implode( " AND ", $_where ) . " )
			WHERE p.post_parent <> 0
			GROUP BY p.post_parent" );

		$search_ids = array_merge( $sku_to_id, $sku_to_parent_id );

		return $search_ids;
	}
}

if ( ! function_exists( 'ideapark_search_product_tag' ) ) {
	function ideapark_search_product_tag( $args, $wp_query ) {
		global $wpdb;

		if ( empty( $wp_query->query_vars['s'] ) ) {
			return $args;
		}

		if ( ! IDEAPARK_IS_AJAX_SEARCH && ( is_admin() || ! $wp_query->is_main_query() ) ) {
			return $args;
		}

		if ( ! ( ideapark_mod( 'search_by_category' ) || ideapark_mod( 'search_by_tag' ) || ideapark_mod( 'search_by_brand' ) ) ) {
			return $args;
		}

		if ( strlen( ( $s = trim( preg_replace( '~\s\s+~', ' ', $wp_query->query_vars['s'] ) ) ) ) > 0 ) {
			$e = explode( ' ', $s );

			$term_ids = ideapark_search_product_tag_ids( $e );

			if ( sizeof( $term_ids ) > 0 && ! empty( $args['where'] ) ) {
				$args['join']  .= " LEFT JOIN {$wpdb->term_relationships} tr_ip ON tr_ip.term_taxonomy_id IN (" . implode( ',', $term_ids ) . ") AND tr_ip.object_id = {$wpdb->posts}.ID";
				$args['where'] = str_replace( '((' . $wpdb->posts . '.post_title LIKE', '(tr_ip.object_id IS NOT NULL OR (' . $wpdb->posts . '.post_title LIKE', $args['where'] );
			}
		}

		return $args;
	}
}

if ( ! function_exists( 'ideapark_search_product_tag_ids' ) ) {
	function ideapark_search_product_tag_ids( $e ) {
		global $wpdb;

		$_where = [];
		foreach ( $e as $word ) {
			$s        = '%' . esc_sql( $wpdb->esc_like( $word ) ) . '%';
			$_where[] = $wpdb->prepare( "( t.name LIKE %s )", $s );
		}

		$taxonomies = [];

		if ( ideapark_mod( 'search_by_category' ) ) {
			$taxonomies[] = 'product_cat';
		}

		if ( ideapark_mod( 'search_by_tag' ) ) {
			$taxonomies[] = 'product_tag';
		}

		if ( ideapark_mod( 'search_by_brand' ) && ideapark_mod( 'product_brand_attribute' ) && taxonomy_exists( ideapark_mod( 'product_brand_attribute' ) ) ) {
			$taxonomies[] = ideapark_mod( 'product_brand_attribute' );
		}

		return $taxonomies ? $wpdb->get_col( "
			SELECT t.term_id
			FROM {$wpdb->terms} t 
			INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id and tt.taxonomy IN ('" . implode( "','", $taxonomies ) . "')
			WHERE " . implode( " AND ", $_where ) ) : [];
	}
}

if ( ! function_exists( 'ideapark_ajax_search' ) ) {
	function ideapark_ajax_search() {
		global $post, $product, $wp_query, $wp_the_query;
		if ( strlen( ( $s = trim( preg_replace( '~\s\s+~', ' ', $_POST['s'] ) ) ) ) > 0 ) {

			$lang = ideapark_query_lang();

			$query_args = [
				's'              => $s,
				'posts_per_page' => ideapark_mod( 'ajax_search_limit' ) ? ideapark_mod( 'ajax_search_limit' ) : 1,
				'post_status'    => 'publish',
				'lang'           => $lang
			];

			add_filter( 'trp_force_search', '__return_true', 999 );

			if ( $lang ) {
				do_action( 'wpml_switch_language', $lang );
				if ( ! empty( $_REQUEST['lang'] ) && ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
					switch_to_locale( $_REQUEST['lang'] );
				}
			}

			if ( ideapark_mod( 'ajax_search_post_type' ) == 'products' && ideapark_woocommerce_on() || isset( $_POST['post_type'] ) && $_POST['post_type'] == 'product' ) {
				$query_args['post_type'] = [ 'product' ];
			}

			if ( ideapark_woocommerce_on() ) {
				add_action( 'pre_get_posts', [ WC()->query, 'product_query' ] );
			}

			$wp_query = $wp_the_query = new WP_Query();

			$wp_query->query( $query_args );

			if ( is_a( $wp_query, 'WP_Query' ) ) {
				$wp_query->is_search = true;
			}

			if ( $wp_query->have_posts() ) {
				while ( $wp_query->have_posts() ) {
					$wp_query->the_post();
					$post_type = get_post_type();
					if ( $post_type == 'product' ) {
						$product = wc_get_product( get_the_ID() );
					}
					?>

					<div class="c-header-search__row">
						<div class="c-header-search__thumb">
							<a class="c-header-search__link" href="<?php the_permalink(); ?>">
								<?php the_post_thumbnail( ideapark_mod( 'thumb_aspect_ratio' ) == 'grid' ? 'medium' : 'woocommerce_gallery_thumbnail', [ 'class' => 'c-header-search__thumb-img' . ( ideapark_mod( 'thumb_aspect_ratio' ) == 'grid' ? ' c-header-search__thumb-img--grid' : ' c-header-search__thumb-img--crop' ) ] ); ?>
							</a>
						</div>
						<div class="c-header-search__col">

							<?php if ( $post_type == 'product' ) {
								$product_categories = [];
								$term_ids           = wc_get_product_term_ids( get_the_ID(), 'product_cat' );
								foreach ( $term_ids as $term_id ) {
									$product_categories[] = get_term_by( 'id', $term_id, 'product_cat' );
								}
								?>
								<div class="c-header-search__category-list">
									<?php ideapark_category( '<span class="h-bullet"></span>', $product_categories, 'c-header-search__category-item' ); ?>
								</div>
							<?php } ?>

							<div class="c-header-search__title">
								<a class="c-header-search__link" href="<?php the_permalink(); ?>">
									<?php the_title() ?>
								</a>
							</div>
							<?php if ( $post_type == 'product' ) { ?>
								<?php echo ideapark_wrap( $product->get_price_html(), '<div class="c-header-search__price">', '</div>' ); ?>
							<?php } ?>
						</div>
					</div>

				<?php } ?>
				<div class="c-header-search__view-all">
					<button class="c-button c-button--outline js-ajax-search-all"
							type="button"><?php echo esc_html__( 'View all results', 'moderno' ); ?></button>
				</div>
			<?php } else { ?>
				<div
					class="c-header-search__no-results"><?php echo esc_html__( 'No results found', 'moderno' ); ?></div>
			<?php }

		}
		die();
	}
}

if ( ! function_exists( 'ideapark_category' ) ) {
	function ideapark_category( $separator, $cat = null, $a_calss = '' ) {
		$catetories = [];

		if ( ! $cat ) {
			$cat = get_the_category();
		}
		foreach ( $cat as $category ) {
			$catetories[] = '<a ' . ( $a_calss ? 'class="' . esc_attr( $a_calss ) . '"' : '' ) . ' href="' . esc_url( get_category_link( $category->term_id ) ) . '" title="' . sprintf( esc_attr__( "View all posts in %s", 'moderno' ), $category->name ) . '" ' . '>' . esc_html( $category->name ) . '</a>';
		}

		if ( $catetories ) {
			echo implode( $separator, $catetories );
		}
	}
}

if ( ! function_exists( 'ideapark_pagination_prev' ) ) {
	function ideapark_pagination_prev() {
		return '<i class="ip-arrow-pagination page-numbers__prev-ico"></i><span class="screen-reader-text">' . esc_html__( 'Prev page', 'moderno' ) . '</span>';
	}
}

if ( ! function_exists( 'ideapark_pagination_next' ) ) {
	function ideapark_pagination_next() {
		return '<i class="ip-arrow-pagination page-numbers__next-ico"></i><span class="screen-reader-text">' . esc_html__( 'Next page', 'moderno' ) . '</span>';
	}
}

if ( ! function_exists( 'ideapark_corenavi' ) ) {
	function ideapark_corenavi( $custom_query = null ) {
		global $wp_query;

		if ( ! $custom_query ) {
			$custom_query = $wp_query;
		}

		if ( $custom_query->max_num_pages < 2 ) {
			return;
		}

		if ( ! $current = get_query_var( 'paged' ) ) {
			$current = 1;
		}

		$a = [ // WPCS: XSS ok.
			'base'      => str_replace( 999999999, '%#%', get_pagenum_link( 999999999 ) ),
			'add_args'  => false,
			'current'   => $current,
			'total'     => $custom_query->max_num_pages,
			'prev_text' => ideapark_pagination_prev(),
			'next_text' => ideapark_pagination_next(),
			'type'      => 'list',
			'end_size'  => 1,
			'mid_size'  => 1,
		];

		$pages = paginate_links( $a );

		echo ideapark_wrap( $pages, '<nav class="page-numbers__wrap">', '</nav>' );
	}
}

if ( ! function_exists( 'ideapark_default_menu' ) ) {
	function ideapark_default_menu() {
		$menu = '';
		$menu .= '<ul class="menu">';

		if ( is_home() ) {
			$menu .= '<li class="current_page_item menu-item"><a href="' . esc_url( home_url( '/' ) ) . '">Home</a></li>';
		} else {
			$menu .= '<li class="menu-item"><a href="' . esc_url( home_url( '/' ) ) . '">Home</a></li>';
		}

		$menu .= '</ul>';

		return $menu;
	}
}

if ( ! function_exists( 'ideapark_post_nav' ) ) {
	function ideapark_post_nav() {
		$previous = ( is_attachment() ) ? get_post( get_post()->post_parent ) : get_adjacent_post( false, '', true );
		$next     = get_adjacent_post( false, '', false );

		if ( ! $next && ! $previous ) {
			return;
		}
		?>
		<nav class="c-post__nav" role="navigation">
			<?php if ( is_attachment() && $previous ) { ?>

				<a class="c-post__nav-prev" href="<?php echo esc_url( get_permalink( $previous->ID ) ) ?>" rel="prev">
					<i class="c-post__nav-prev-ico ip-right"></i>
					<span class="c-post__nav-title">
							<?php echo apply_filters( 'the_title', $previous->post_title, $previous->ID ); ?>
						</span>
				</a>

			<?php } else { ?>
				<?php if ( $previous ) { ?>

					<a class="c-post__nav-prev" href="<?php echo esc_url( get_permalink( $previous->ID ) ) ?>"
					   rel="prev">
						<i class="c-post__nav-prev-ico ip-right"></i>
						<span class="c-post__nav-title">
								<?php echo apply_filters( 'the_title', $previous->post_title, $previous->ID ); ?>
							</span>
					</a>

				<?php } else { ?>
					<div class="c-post__nav-prev"></div>
				<?php } ?>

				<?php if ( $next ) { ?>

					<a class="c-post__nav-next" href="<?php echo esc_url( get_permalink( $next->ID ) ) ?>" rel="next">
							<span class="c-post__nav-title">
								<?php echo apply_filters( 'the_title', $next->post_title, $next->ID ); ?>
							</span>
						<i class="c-post__nav-next-ico ip-right"></i>
					</a>

				<?php } ?>

			<?php } ?>
		</nav><!-- .navigation -->
		<?php
	}
}

if ( ! function_exists( 'ideapark_html5_comment' ) ) {
	function ideapark_html5_comment( $comment, $args, $depth ) {
		$tag = ( 'div' === $args['style'] ) ? 'div' : 'li';
		?>
		<<?php echo esc_attr( $tag ); ?> id="comment-<?php comment_ID(); ?>" <?php comment_class(); ?>>
		<?php $ping_track_back = $comment->comment_type == 'trackback' || $comment->comment_type == 'pingback'; ?>
		<article id="div-comment-<?php comment_ID(); ?>"
				 class="comment-body <?php ideapark_class( $ping_track_back, 'no-avatar' ) ?>">
			<header class="comment-meta">
				<div class="comment-author vcard">
					<?php if ( 0 != $args['avatar_size'] && ! $ping_track_back ) {
						echo '<div class="author-img">' . get_avatar( $comment, $args['avatar_size'] ) . '</div>';
					} ?>
					<?php printf( '<strong class="author-name">%s</strong>', get_comment_author_link() ); ?>
				</div>

				<div class="comment-metadata">
					<a href="<?php echo esc_url( get_comment_link( $comment->comment_ID, $args ) ); ?>">
						<time datetime="<?php comment_time( 'c' ); ?>">
							<?php printf( esc_html_x( '%1$s at %2$s', '1: date, 2: time', 'moderno' ), get_comment_date(), get_comment_time() ); ?>
						</time>
					</a>
				</div>

				<?php if ( '0' == $comment->comment_approved ) : ?>
					<p class="comment-awaiting-moderation"><?php esc_html_e( 'Your comment is awaiting moderation.', 'moderno' ); ?></p>
				<?php endif; ?>
			</header>

			<div class="entry-content comment-content">
				<?php comment_text(); ?>
			</div>

			<div class="buttons">
				<?php comment_reply_link( array_merge( $args, [
					'reply_text' => esc_html__( 'Reply', 'moderno' ),
					'add_below'  => 'div-comment',
					'depth'      => $depth,
					'max_depth'  => $args['max_depth']
				] ) ); ?>

				<?php edit_comment_link( esc_html__( 'Edit', 'moderno' ) ); ?>
			</div>

		</article><!-- .comment-body -->
		<?php
	}
}

if ( ! function_exists( 'ideapark_style' ) ) {
	function ideapark_style( $str ) {
		if ( is_array( $str ) ) {
			$str = implode( ';', $str );
		}
		if ( trim( $str ) != '' ) {
			echo sprintf( '%s%s%s', ' style' . '="', $str, '" ' );
		} else {
			return;
		}
	}
}

if ( ! function_exists( 'ideapark_body_class' ) ) {
	function ideapark_body_class( $classes ) {
		$classes[] = 'h-preload';
		$classes[] = ideapark_woocommerce_on() ? 'woocommerce-on' : 'woocommerce-off';
		$classes[] = ideapark_is_rtl() ? 'h-rtl' : 'h-ltr';
		if ( ideapark_mod( 'wpml_style' ) ) {
			$classes[] = 'h-wpml';
		}
		if ( ideapark_mod( 'collapse_filters' ) ) {
			$classes[] = 'h-collapse-filters';
		}
		if ( ideapark_mod( 'sticky_add_to_cart' ) ) {
			$classes[] = 'h-sticky-atc';
		}
		if ( ideapark_mod( 'bottom_buttons_mobile_locations' ) == 'screen' ) {
			$classes[] = 'h-sticky-bottom';
		}

		return $classes;
	}
}

if ( ! function_exists( 'ideapark_empty_menu' ) ) {
	function ideapark_empty_menu() {
	}
}

if ( ! function_exists( 'ideapark_search_form' ) ) {
	function ideapark_search_form( $form ) {
		ob_start();
		do_action( 'wpml_add_language_form_field' );
		$lang = ob_get_clean();
		$form = '<form role="search" method="get" class="js-search-form-entry" action="' . esc_url( home_url( '/' ) ) . '">
				<div class="c-search-form__wrap">
				<label class="c-search-form__label">
					<span class="screen-reader-text">' . esc_html_x( 'Search for:', 'label', 'moderno' ) . '</span>
					<input class="c-form__input c-form__input--full c-search-form__input" type="search" placeholder="' . esc_attr_x( 'Search &hellip;', 'placeholder', 'moderno' ) . '" value="' . get_search_query() . '" name="s" />' .
				'</label>
				<button type="submit" class="c-button c-button--medium c-search-form__button" aria-label="' . esc_attr__( 'Search', 'moderno' ) . '"><i class="ip-search c-search-form__svg"></i></button>
				</div>' . $lang . '
				' . ( get_query_var( 'post_type' ) == 'product' ? '<input type="hidden" name="post_type" value="product" />' : '' ) . '
			</form>';

		return ideapark_wrap( $form, '<div class="c-search-form">', '</div>' );
	}
}

if ( ! function_exists( 'ideapark_search_form_ajax' ) ) {
	function ideapark_search_form_ajax( $form ) {
		ob_start();
		do_action( 'wpml_add_language_form_field' );
		$lang = ob_get_clean();
		$form = '
<form role="search" class="js-search-form" method="get" action="' . esc_url( home_url( '/' ) ) . '">
	<div class="c-header-search__input-block">
		<input class="js-ajax-search-input c-header-search__input' . ( ! ideapark_mod( 'ajax_search' ) ? ' no-ajax' : '' ) . '" autocomplete="off" type="text" name="s" placeholder="' . esc_attr__( 'Start typing...', 'moderno' ) . '" value="' . esc_attr( ideapark_mod( 'ajax_search' ) ? '' : get_search_query() ) . '" />
		<button class="js-search-clear h-cb c-header-search__clear' . ( ! ideapark_mod( 'ajax_search' ) ? ' no-ajax' : '' ) . '" type="button"><i class="ip-close-small c-header-search__clear-svg"></i><span class="c-header-search__clear-text">' . esc_html__( 'Clear', 'moderno' ) . '</span></button>
		' . ( ! ideapark_mod( 'ajax_search' ) ? '<button type="submit" class="c-header-search__submit h-cb h-cb--svg" aria-label="' . esc_attr__( 'Search', 'moderno' ) . '"><i class="ip-search"></i></button>' : '' ) . '
	</div>' . $lang . '
	' . ( ideapark_mod( 'ajax_search_post_type' ) == 'products' ? '<input type="hidden" name="post_type" value="product" class="js-ajax-search-type" />' : '' ) . '
</form>';

		return $form;
	}
}

if ( ! function_exists( 'ideapark_search_form_header' ) ) {
	function ideapark_search_form_header( $form ) {
		ob_start();
		do_action( 'wpml_add_language_form_field' );
		$lang = ob_get_clean();
		$form = '
<form role="search" class="js-search-form" method="get" action="' . esc_url( home_url( '/' ) ) . '">
	<div class="c-header__search-form">
		<input class="js-ajax-search-input c-header__search-input' . ( ! ideapark_mod( 'ajax_search' ) ? ' no-ajax' : '' ) . '" autocomplete="off" type="text" name="s" placeholder="' . esc_attr__( 'Start typing...', 'moderno' ) . '" value="' . esc_attr( ideapark_mod( 'ajax_search' ) ? '' : get_search_query() ) . '" />
		<button class="js-search-clear h-cb c-header__search-clear' . ( ! ideapark_mod( 'ajax_search' ) ? ' no-ajax' : '' ) . '" type="button"><i class="ip-close-small c-header__search-clear-svg"></i><span class="c-header__search-clear-text">' . esc_html__( 'Clear', 'moderno' ) . '</span></button>
		<button type="submit" class="c-header__search-submit h-cb h-cb--svg"><i class="ip-z-search-bold"></i></button>
	</div>' . $lang . '
	' . ( ideapark_mod( 'ajax_search_post_type' ) == 'products' ? '<input type="hidden" name="post_type" value="product" class="js-ajax-search-type" />' : '' ) . '
</form>';

		return $form;
	}
}

if ( ! function_exists( 'ideapark_get_account_link' ) ) {
	function ideapark_get_account_link( $prefix = '' ) {
		$link_title = '<i class="' . ( ideapark_mod( 'custom_header_icon_auth' ) ?: 'ip-user' ) . ' h-hide-mobile"><!-- --></i><i class="' . ( ideapark_mod( 'custom_header_icon_auth' ) ?: 'ip-m-auth' ) . ' h-hide-desktop"><!-- --></i>';

		return ideapark_wrap( $link_title, '<a class="c-header__button-link c-header__button-link--account" title="' . esc_attr( is_user_logged_in() ? esc_html__( 'Account', 'moderno' ) : esc_html__( 'Login', 'moderno' ) ) . '" href="' . esc_url( get_permalink( get_option( 'woocommerce_myaccount_page_id' ) ) ) . '" rel="nofollow" aria-label="' . esc_attr__( 'My Account', 'moderno' ) . '">', '</a>' );
	}
}

if ( ! function_exists( 'ideapark_localize_vars' ) ) {
	function ideapark_localize_vars() {
		global $wp_scripts;

		$js_url_imagesloaded = '';
		$js_url_masonry      = '';
		foreach ( $wp_scripts->registered as $handler => $script ) {
			if ( $handler == 'imagesloaded' ) {
				$js_url_imagesloaded = $wp_scripts->base_url . $script->src . ( ! empty( $script->ver ) ? '?v=' . $script->ver : '' );
			}
			if ( $handler == 'masonry' ) {
				$js_url_masonry = $wp_scripts->base_url . $script->src . ( ! empty( $script->ver ) ? '?v=' . $script->ver : '' );
			}
		}

		$return = [
			'themeDir'             => IDEAPARK_DIR,
			'themeUri'             => IDEAPARK_URI,
			'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
			'searchUrl'            => home_url( '?s=' ),
			'lazyload'             => ideapark_mod( 'lazyload' ),
			'isRtl'                => ideapark_is_rtl(),
			'stickySidebar'        => ideapark_mod( 'sticky_sidebar' ),
			'stickyMenuDesktop'    => ideapark_mod( 'sticky_menu_desktop' ),
			'stickyMenuMobile'     => ideapark_mod( 'sticky_menu_mobile' ),
			'headerType'           => ideapark_mod( 'header_type' ),
			'popupCartLayout'      => ideapark_mod( 'popup_cart_layout' ),
			'popupCartOpenMobile'  => ideapark_mod( 'popup_cart_auto_open_mobile' ),
			'popupCartOpenDesktop' => ideapark_mod( 'popup_cart_layout' ) == 'sidebar' && ideapark_mod( 'popup_cart_auto_open_desktop' ),
			'viewMore'             => esc_html__( 'View More', 'moderno' ),
			'imagesloadedUrl'      => $js_url_imagesloaded,
			'scriptsHash'          => substr( get_option( $option_name = 'ideapark_scripts_hash' ), 0, 8 ),
			'stylesHash'           => substr( get_option( $option_name = 'ideapark_styles_hash' ), 0, 8 ),
			'cookiePath'           => COOKIEPATH ? COOKIEPATH : '/',
			'cookieDomain'         => COOKIE_DOMAIN,
			'cookieHash'           => COOKIEHASH,
			'locale'               => get_locale(),
			'masonryUrl'           => $js_url_masonry,
			'elementorPreview'     => ideapark_is_elementor_preview_mode(),
			'jsDelay'              => ideapark_mod( 'js_delay' ),
			'ajaxAddToCart'        => ideapark_mod( 'product_page_ajax_add_to_cart' ) && 'yes' === get_option( 'woocommerce_enable_ajax_add_to_cart' ) && 'yes' !== get_option( 'woocommerce_cart_redirect_after_add' ),
			'collapseFilters'      => ideapark_mod( 'collapse_filters' ),
			'stickyFilter'         => ideapark_mod( 'sticky_filter' ),
			'menuItemSpace'        => ideapark_mod( 'top_menu_item_space' ),
			'bottomButtons'        => ideapark_mod( 'bottom_buttons_mobile_locations' ),
			'popupMessagesTimeout' => abs( (int) ideapark_mod( 'popup_messages_timeout' ) ),
			'singleImageCarousel'  => ideapark_mod( 'single_image_carousel' ),
			'slideText'            => esc_html__( 'Slide', 'moderno' ),
			'prevText'             => esc_html__( 'Prev', 'moderno' ),
			'nextText'             => esc_html__( 'Next', 'moderno' ),
		];

		if ( ideapark_woocommerce_on() && ideapark_mod( 'wishlist_page' ) && function_exists( 'ideapark_wishlist' ) ) {
			$return = array_merge( $return, [
				'wishlistCookieName'  => ideapark_wishlist()->cookie_name,
				'wishlistTitleAdd'    => esc_html__( 'Add to Wishlist', 'moderno' ),
				'wishlistTitleRemove' => esc_html__( 'Remove from Wishlist', 'moderno' )
			] );
		}

		return $return;
	}
}

if ( ! function_exists( 'ideapark_heartbeat' ) ) {
	function ideapark_heartbeat( $code = '', $url = '' ) {
		$timeout           = 5;
		$args              = [];
		$args['heartbeat'] = 1;
		$args['site']      = get_option( 'siteurl' );
		$args['slug']      = IDEAPARK_SLUG;
		$args['version']   = IDEAPARK_VERSION;
		$args['code']      = $code ?: call_user_func( 'ideapark_get_pur' . 'chase_code' );
		$args['wrong']     = get_option( '_is_wro' . 'ng_code' );

		return wp_remote_post( $url ?: ideapark_api_url(), [
			'timeout'   => $timeout,
			'body'      => (array) $args,
			'sslverify' => false
		] );
	}
}

if ( ! function_exists( 'ideapark_disable_background_image' ) ) {
	function ideapark_disable_background_image( $value ) {
		if ( ideapark_mod( 'hide_inner_background' ) && ! is_front_page() && ! is_admin() ) {
			return '';
		} else {
			return $value;
		}
	}
}

if ( ! function_exists( 'ideapark_admin_scripts' ) ) {
	function ideapark_admin_scripts() {
		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : false;
		$allowed = [ 'page', 'post', 'html_block', 'customize' ];
		if ( is_object( $screen ) && ! empty( $screen->id ) && in_array( $screen->id, $allowed ) ) {
			wp_enqueue_style( 'ideapark-theme-font', IDEAPARK_URI . '/assets/font/theme-icons.css', [], ideapark_mtime( IDEAPARK_DIR . '/assets/font/theme-icons.css' ) );
		}
		wp_enqueue_style( 'ideapark-admin', IDEAPARK_URI . '/assets/css/admin/admin.css', [], ideapark_mtime( IDEAPARK_DIR . '/assets/css/admin/admin.css' ) );
	}
}

if ( ! function_exists( 'ideapark_exists_theme_addons' ) ) {
	function ideapark_exists_theme_addons() {
		return defined( 'IDEAPARK_FUNC_VERSION' );
	}
}

if ( ! function_exists( 'ideapark_wrap' ) ) {
	function ideapark_wrap( $str, $before = '', $after = '' ) {
		if ( $str !== null && trim( $str ) != '' ) {
			return sprintf( '%s%s%s', $before, $str, $after );
		} else {
			return '';
		}
	}
}

if ( ! function_exists( 'ideapark_phone_wrap' ) ) {
	function ideapark_phone_wrap( $str, $before = '', $after = '', $add_link = true ) {
		if ( preg_match_all( '~\+?([\d \-()]{4,}|\d{3})~', $str, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$prefix  = $before;
				$postfix = $after;
				if ( $add_link ) {
					$prefix  .= '<a href="tel:' . preg_replace( '~[^0-9\+]~', '', $match[0] ) . '">';
					$postfix = '</a>' . $postfix;
				}
				$str = preg_replace( '~' . preg_quote( $match[0], '~' ) . '~', $prefix . '\\0' . $postfix, $str );
			}

			return $str;
		} else {
			return $str;
		}
	}
}

if ( ! function_exists( 'ideapark_bg' ) ) {
	function ideapark_bg( $bg_color, $url = '', $text_color = '', $other = '' ) {
		$styles = [];
		$data   = '';
		if ( $bg_color ) {
			$styles[] = sprintf( 'background-color:%s', esc_attr( $bg_color ) );
		}
		if ( $text_color ) {
			$styles[] = sprintf( 'color:%s', esc_attr( $text_color ) );
		}
		if ( trim( $url ) != '' ) {
			$styles[] = sprintf( 'background-image:url(%s)', esc_url( $url ) );
		}
		if ( $other ) {
			$styles[] = esc_attr( trim( $other ) );
		}

		return trim( $data . ( $styles ? ' style="' . implode( ';', $styles ) . '"' : '' ) );
	}
}

if ( ! function_exists( 'ideapark' . '_get_font_props' ) ) {
	function ideapark_get_font_props( $filename ) {
		$filename_lower = strtolower( $filename );

		$weight_map = [
			'extralight' => 200,
			'light'      => 300,
			'regular'    => 400,
			'medium'     => 500,
			'semibold'   => 600,
			'bold'       => 700,
			'black'      => 900
		];

		$style = ( strpos( $filename_lower, 'italic' ) !== false ) ? 'italic' : 'normal';

		foreach ( $weight_map as $term => $weight ) {
			if ( strpos( $filename_lower, $term ) !== false ) {
				return [
					'weight' => $weight,
					'style'  => $style
				];
			}
		}

		return [
			'weight' => 400,
			'style'  => $style
		];
	}
}

if ( ! function_exists( 'ideapark' . '_custom_fonts_css' ) ) {
	function ideapark_custom_fonts_css() {
		$css = '';
		if ( $custom_fonts = ideapark_mod( 'custom_fonts' ) ) {
			foreach ( $custom_fonts as $font ) {
				if ( empty( $font['name'] ) ) {
					continue;
				}
				$css          .= '@font-face { font-family:"' . esc_attr( $font['name'] ) . '";';
				$css          .= 'src:';
				$arr          = [];
				$url_to_check = '';
				if ( isset( $font['woff2'] ) && $woff2 = $font['woff2'] ) {
					$arr[]        = 'url(' . esc_url( $woff2 ) . ") format('woff2')";
					$url_to_check = $woff2;
				}
				if ( isset( $font['woff'] ) && $woff = $font['woff'] ) {
					$arr[]        = 'url(' . esc_url( $woff ) . ") format('woff')";
					$url_to_check = $woff;
				}
				if ( isset( $font['ttf'] ) && $ttf = $font['ttf'] ) {
					$arr[]        = 'url(' . esc_url( $ttf ) . ") format('truetype')";
					$url_to_check = $ttf;
				}
				if ( isset( $font['otf'] ) && $otf = $font['otf'] ) {
					$arr[]        = 'url(' . esc_url( $otf ) . ") format('opentype')";
					$url_to_check = $otf;
				}
				if ( isset( $font['svg'] ) && $svg = $font['svg'] ) {
					$arr[]        = 'url(' . esc_url( $svg ) . '#' . esc_attr( strtolower( str_replace( ' ', '_', $font['name'] ) ) ) . ") format('svg')";
					$url_to_check = $svg;
				}
				$css .= join( ', ', $arr );
				$css .= ';';
				if ( ! empty( $font['font_display'] ) ) {
					$css .= 'font-display: ' . esc_attr( $font['font_display'] ) . ';';
				}
				if ( $url_to_check && ( $props = ideapark_get_font_props( $url_to_check ) ) ) {
					$css .= 'font-weight: ' . $props['weight'] . ';';
					$css .= 'font-style: ' . $props['style'] . ';';
				}
				$css .= '}';
			}
		}

		return $css;
	}
}

if ( ! function_exists( 'ideapark_header_metadata' ) ) {
	function ideapark_header_metadata() {

		$lang_postfix = ideapark_get_lang_postfix();

		$fonts = apply_filters( 'ideapark_theme_fonts', [
			ideapark_mod( 'theme_font_text' . $lang_postfix ) ?: ideapark_mod( 'theme_font_text' ),
		] );

		$css              = ideapark_get_google_font_uri( $fonts );
		$css_icons        = IDEAPARK_URI . '/assets/font/theme-icons.css?ver=' . ideapark_mtime( IDEAPARK_DIR . '/assets/font/theme-icons.css' );
		$custom_fonts_css = ideapark_custom_fonts_css();

		if ( $css ) { ?>
			<link rel="stylesheet" href="<?php echo esc_url( $css ); ?>">
		<?php } ?>
		<link rel="stylesheet" href="<?php echo esc_url( $css_icons ); ?>">
		<?php if ( $custom_fonts_css ) {
			echo ideapark_wrap( $custom_fonts_css, '<style>', '</style>' );
		} ?>
		<script>var ideapark_supports_html5_storage = !1;
			try {
				ideapark_supports_html5_storage = "localStorage" in window && null !== window.localStorage, window.localStorage.setItem("ip", "test"), window.localStorage.removeItem("ip")
			} catch (t) {
				ideapark_supports_html5_storage = !1
			}</script>
		<?php
	}
}

if ( ! function_exists( 'ideapark_init_filesystem' ) ) {
	function ideapark_init_filesystem() {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once trailingslashit( ABSPATH ) . 'wp-admin/includes/file.php';
		}
		if ( is_admin() ) {
			$url   = admin_url();
			$creds = false;
			if ( function_exists( 'request_filesystem_credentials' ) ) {
				$creds = @request_filesystem_credentials( $url, '', false, false, [] );
				if ( false === $creds ) {
					return false;
				}
			}
			if ( ! WP_Filesystem( $creds ) ) {
				if ( function_exists( 'request_filesystem_credentials' ) ) {
					@request_filesystem_credentials( $url, '', true, false );
				}

				return false;
			}

			return true;
		} else {
			WP_Filesystem();
		}

		return true;
	}
}

if ( ! function_exists( 'ideapark_fpc' ) ) {
	function ideapark_fpc( $file, $data, $flag = 0 ) {
		/**
		 * @var WP_Filesystem_Base $wp_filesystem
		 */
		global $wp_filesystem;
		if ( ! empty( $file ) ) {
			if ( isset( $wp_filesystem ) && is_object( $wp_filesystem ) ) {
				$file = str_replace( ABSPATH, $wp_filesystem->abspath(), $file );

				return $wp_filesystem->put_contents( $file, ( FILE_APPEND == $flag && $wp_filesystem->exists( $file ) ? $wp_filesystem->get_contents( $file ) : '' ) . $data, false );
			}
		}

		return false;
	}
}

if ( ! function_exists( 'ideapark_fgc' ) ) {
	function ideapark_fgc( $file ) {
		/**
		 * @var WP_Filesystem_Base $wp_filesystem
		 */
		global $wp_filesystem;
		if ( ! empty( $file ) ) {
			if ( isset( $wp_filesystem ) && is_object( $wp_filesystem ) ) {
				$file = str_replace( ABSPATH, $wp_filesystem->abspath(), $file );

				return $wp_filesystem->get_contents( $file );
			}
		}

		return '';
	}
}

if ( ! function_exists( 'ideapark_is_file' ) ) {
	function ideapark_is_file( $file ) {
		/**
		 * @var WP_Filesystem_Base $wp_filesystem
		 */
		global $wp_filesystem;
		if ( ! empty( $file ) ) {
			if ( isset( $wp_filesystem ) && is_object( $wp_filesystem ) ) {
				$file = str_replace( ABSPATH, $wp_filesystem->abspath(), $file );

				return $wp_filesystem->is_file( $file );
			}
		}

		return '';
	}
}

if ( ! function_exists( 'ideapark_is_dir' ) ) {
	function ideapark_is_dir( $file ) {
		/**
		 * @var WP_Filesystem_Base $wp_filesystem
		 */
		global $wp_filesystem;
		if ( ! empty( $file ) ) {
			if ( isset( $wp_filesystem ) && is_object( $wp_filesystem ) ) {
				$file = str_replace( ABSPATH, $wp_filesystem->abspath(), $file );

				return $wp_filesystem->is_dir( $file );
			}
		}

		return '';
	}
}

if ( ! function_exists( 'ideapark_mkdir' ) ) {
	function ideapark_mkdir( $file ) {
		/**
		 * @var WP_Filesystem_Base $wp_filesystem
		 */
		global $wp_filesystem;
		if ( ! empty( $file ) ) {
			if ( isset( $wp_filesystem ) && is_object( $wp_filesystem ) ) {
				$file = str_replace( ABSPATH, $wp_filesystem->abspath(), $file );

				return wp_mkdir_p( $file );
			}
		}

		return '';
	}
}

if ( ! function_exists( 'ideapark_delete_file' ) ) {
	function ideapark_delete_file( $file ) {
		/**
		 * @var WP_Filesystem_Base $wp_filesystem
		 */
		global $wp_filesystem;
		if ( ! empty( $file ) ) {
			if ( isset( $wp_filesystem ) && is_object( $wp_filesystem ) ) {
				$file = str_replace( ABSPATH, $wp_filesystem->abspath(), $file );

				if ( ideapark_is_file( $file ) ) {
					wp_delete_file( $file );
				}

				return true;
			}
		}

		return '';
	}
}

if ( ! function_exists( 'ideapark_mtime' ) ) {
	function ideapark_mtime( $file ) {
		/**
		 * @var WP_Filesystem_Base $wp_filesystem
		 */
		global $wp_filesystem;
		if ( ! empty( $file ) ) {
			if ( isset( $wp_filesystem ) && is_object( $wp_filesystem ) ) {
				$file = str_replace( ABSPATH, $wp_filesystem->abspath(), $file );

				return $wp_filesystem->mtime( $file );
			}
		}

		return '';
	}
}

if ( ! function_exists( 'ideapark_array_merge' ) ) {
	function ideapark_array_merge( $a1, $a2 ) {
		for ( $i = 1; $i < func_num_args(); $i ++ ) {
			$arg = func_get_arg( $i );
			if ( is_array( $arg ) && count( $arg ) > 0 ) {
				foreach ( $arg as $k => $v ) {
					$a1[ $k ] = $v;
				}
			}
		}

		return $a1;
	}
}

if ( ! function_exists( 'ideapark_ajax_custom_css' ) ) {
	function ideapark_ajax_custom_css() {
		echo ideapark_customize_css( true );
		die();
	}
}

if ( ! function_exists( 'ideapark_correct_tgmpa_mofile' ) ) {
	function ideapark_correct_tgmpa_mofile( $mofile, $domain ) {
		if ( 'tgmpa' !== $domain ) {
			return $mofile;
		}

		return preg_replace( '`/([a-z]{2}_[A-Z]{2}.mo)$`', '/tgmpa-$1', $mofile );
	}
}

if ( ! function_exists( 'ideapark_get_template_part' ) ) {
	function ideapark_get_template_part( $template, $args = null ) {
		set_query_var( 'ideapark_var', $args );
		get_template_part( $template );
		set_query_var( 'ideapark_var', null );
	}
}

if ( ! function_exists( 'ideapark' . '_dsc' ) ) {
	function ideapark_dsc( $slug ) {
		$f = 'wp_deregister' . '_script';

		return call_user_func( $f, $slug );
	}
}

if ( ! function_exists( 'ideapark' . '_dst' ) ) {
	function ideapark_dst( $slug ) {
		$f = 'wp_deregister' . '_style';

		return call_user_func( $f, $slug );
	}
}

if ( ! function_exists( 'ideapark_af' ) ) {
	function ideapark_af( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $tag, $function_to_add, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'ideapark_rf' ) ) {
	function ideapark_rf( $tag, $function_to_remove, $priority = 10 ) {
		$f = 'remove_filter';

		return call_user_func( $f, $tag, $function_to_remove, $priority );
	}
}

if ( ! function_exists( 'ideapark_aa' ) ) {
	function ideapark_aa( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
		return add_action( $tag, $function_to_add, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'ideapark_ra' ) ) {
	function ideapark_ra( $tag, $function_to_remove, $priority = 10 ) {
		$f = 'remove_action';

		return call_user_func( $f, $tag, $function_to_remove, $priority );
	}
}

if ( ! function_exists( 'ideapark_shortcode' ) ) {
	function ideapark_shortcode( $code ) {
		$f = 'do' . '_shortcode';

		return call_user_func( $f, $code );
	}
}

if ( ! function_exists( 'ideapark_get_inline_svg' ) ) {
	function ideapark_get_inline_svg( $attachment_id, $class = '' ) {
		$svg = get_post_meta( $attachment_id, '_ideapark_inline_svg', true );
		if ( empty( $svg ) ) {
			$svg = ideapark_fgc( get_attached_file( $attachment_id ) );
			update_post_meta( $attachment_id, '_ideapark_inline_svg', $svg );
		}

		if ( ! empty( $svg ) ) {
			if ( $class ) {
				if ( preg_match( '~(<svg[^>]+class\s*=\s*[\'"][^\'"]*)([\'"][^>]*>)~i', $svg, $match ) ) {
					$svg = str_replace( $match[1], $match[1] . ' ' . esc_attr( $class ), $svg );
				} else {
					$svg = preg_replace( '~<svg~i', '<svg class="' . esc_attr( $class ) . '"', $svg );
				}
			}
		}

		return $svg;
	}
}

if ( ! function_exists( 'ideapark_class' ) ) {
	function ideapark_class( $cond, $class_yes, $class_no = '' ) {
		echo ideapark_wrap( $cond ? $class_yes : $class_no, ' ', ' ' );
	}
}

if ( ! function_exists( 'ideapark_html_attributes' ) ) {
	function ideapark_html_attributes( array $attributes ) {
		$rendered_attributes = [];

		foreach ( $attributes as $attribute_key => $attribute_values ) {
			if ( is_array( $attribute_values ) ) {
				$attribute_values = implode( ' ', $attribute_values );
			}

			$rendered_attributes[] = sprintf( '%1$s="%2$s"', $attribute_key, esc_attr( $attribute_values ) );
		}

		return implode( ' ', $rendered_attributes );
	}
}

if ( ! function_exists( 'ideapark_add_allowed_tags' ) ) {
	function ideapark_add_allowed_tags( $tags, $context_type = '' ) {

		if ( $context_type == 'post' ) {
			$tags['svg'] = [ 'class' => true ];
			$tags['use'] = [ 'xlink:href' => true ];
		}

		return $tags;
	}
}

if ( ! function_exists( 'ideapark_manu_link_hash_fix' ) ) {
	function ideapark_menu_link_hash_fix( $attr ) {
		global $ideapark_is_front_page;
		if ( ! $ideapark_is_front_page && ! empty( $attr['href'] ) && strpos( $attr['href'], '#' ) === 0 && strlen( $attr['href'] ) > 1 ) {
			$attr['href'] = home_url( '/' ) . $attr['href'];
		}

		return $attr;
	}
}

if ( ! function_exists( 'ideapark_empty_gif' ) ) {
	function ideapark_empty_gif() {
		return 'data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==';
	}
}

if ( ! function_exists( 'ideapark_check_front_page' ) ) {
	function ideapark_check_front_page() {
		global $ideapark_is_front_page;
		$ideapark_is_front_page = is_front_page();
	}
}

if ( ! function_exists( 'ideapark_custom_meta_boxes' ) ) {
	function ideapark_custom_meta_boxes( $meta_boxes ) {
		if ( ideapark_mod( 'product_brand_attribute' ) ) {
			$fields       = [
				[
					'name'             => __( 'Logo', 'moderno' ),
					'id'               => 'brand_logo',
					'type'             => 'image_advanced',
					'max_file_uploads' => 1,
					'force_delete'     => false,
					'max_status'       => false,
					'image_size'       => 'full',
				]
			];
			$meta_boxes[] = [
				'title'      => '',
				'taxonomies' => [ ideapark_mod( 'product_brand_attribute' ) ],
				'fields'     => $fields,
			];
		}

		$attributes = array_filter( ideapark_get_all_attributes() );
		$fields     = [
			[
				'type' => 'custom_html',
				'std'  => sprintf( __( 'If nothing is selected, the %s default settings %s will be used', 'moderno' ), '<a href="' . admin_url( 'customize.php?autofocus[control]=header_text_color' ) . '">', '</a>' ),
			],
			[
				'name'    => __( 'Header layout', 'moderno' ),
				'id'      => 'header_height',
				'type'    => 'select',
				'std'     => 'default',
				'options' => [
					'default'        => __( 'Default', 'moderno' ),
					'low'            => __( 'With title and breadcrumbs', 'moderno' ),
					'no-breadcrumbs' => __( 'Without breadcrumbs', 'moderno' ),
					'no-title'       => __( 'Without title', 'moderno' ),
					'hide'           => __( 'Hide', 'moderno' ),
				],
			],
			[
				'name'    => __( 'Title align', 'moderno' ),
				'id'      => 'title_align',
				'type'    => 'select',
				'std'     => 'default',
				'options' => [
					'default'        => __( 'Default', 'moderno' ),
					'center'         => __( 'Center', 'moderno' ),
					'left-boxed'     => __( 'Left (boxed)', 'moderno' ),
					'left-fullwidth' => __( 'Left (fullwidth)', 'moderno' ),
				],
			],
		];

		$meta_boxes[] = [
			'title'      => __( 'Page Header', 'moderno' ),
			'context'    => 'side',
			'post_types' => [ 'page', 'post' ],
			'priority'   => 'low',
			'fields'     => $fields
		];

		$taxonomies = [ 'category', 'post_tag', 'product_cat' ];

		if ( $attributes ) {
			$taxonomies = array_merge( $taxonomies, array_keys( $attributes ) );
		}

		$meta_boxes[] = [
			'title'      => __( 'Header', 'moderno' ),
			'taxonomies' => $taxonomies,
			'fields'     => $fields,
		];

		$fields = [
			[
				'name'              => __( 'HTML block (top)', 'moderno' ),
				'label_description' => '<a target="_blank" href="' . esc_url( admin_url( 'edit.php?post_type=html_block' ) ) . '">' . esc_html__( 'Manage html blocks', 'moderno' ) . '</a>',
				'id'                => 'html_block_top',
				'type'              => 'post',
				'post_type'         => 'html_block',
				'field_type'        => 'select_advanced',
				'placeholder'       => __( 'Select a HTML block', 'moderno' ),
				'query_args'        => [
					'post_status'    => [ 'publish' ],
					'posts_per_page' => - 1,
				],
			],
			[
				'name'              => __( 'HTML block (bottom)', 'moderno' ),
				'label_description' => '<a target="_blank" href="' . esc_url( admin_url( 'edit.php?post_type=html_block' ) ) . '">' . esc_html__( 'Manage html blocks', 'moderno' ) . '</a>',
				'id'                => 'html_block_bottom',
				'type'              => 'post',
				'post_type'         => 'html_block',
				'field_type'        => 'select_advanced',
				'placeholder'       => __( 'Select a HTML block', 'moderno' ),
				'query_args'        => [
					'post_status'    => [ 'publish' ],
					'posts_per_page' => - 1,
				],
			],
			[
				'name'      => esc_html__( 'Show HTML blocks only on the first page', 'moderno' ),
				'id'        => 'html_block_first_page',
				'type'      => 'switch',
				'style'     => 'rounded',
				'on_label'  => 'Yes',
				'off_label' => 'No',
			],
			[
				'name'      => esc_html__( 'Show top block above the shop content', 'moderno' ),
				'id'        => 'top_block_above',
				'type'      => 'switch',
				'style'     => 'rounded',
				'on_label'  => 'Yes',
				'off_label' => 'No',
			]
		];

		$taxonomies = [ 'product_cat', 'product_tag' ];
		$attributes = array_filter( ideapark_get_all_attributes() );
		if ( $attributes ) {
			$taxonomies = array_merge( $taxonomies, array_keys( $attributes ) );
		}
		$meta_boxes[] = [
			'title'      => '',
			'taxonomies' => $taxonomies,
			'fields'     => $fields,
		];

		$options = [
			'default' => __( 'Default', 'moderno' )
		];

		$posts = get_posts( [
			'numberposts' => - 1,
			'post_type'   => 'html_block',
			'orderby'     => 'title'
		] );

		foreach ( $posts as $post ) {
			$options[ $post->ID ] = $post->post_title;
		}

		$fields = [
			[
				'type' => 'custom_html',
				'std'  => sprintf( __( 'If nothing is selected, the %s default settings %s will be used', 'moderno' ), '<a href="' . admin_url( 'customize.php?autofocus[control]=footer_page' ) . '">', '</a>' ),
			],
			[
				'name'    => __( 'HTML block', 'moderno' ),
				'id'      => 'footer_page',
				'type'    => 'select',
				'std'     => 'default',
				'options' => $options,
			]
		];

		$meta_boxes[] = [
			'title'      => __( 'Page Footer', 'moderno' ),
			'context'    => 'side',
			'post_types' => [ 'page', 'post' ],
			'priority'   => 'low',
			'fields'     => $fields
		];

		$meta_boxes[] = [
			'title'      => __( 'Footer', 'moderno' ),
			'taxonomies' => $taxonomies,
			'fields'     => $fields,
		];

		return $meta_boxes;
	}
}

if ( ! function_exists( 'ideapark_breadcrumb_list' ) ) {
	function ideapark_breadcrumb_list() {

		$breadcrumb_home_text    = __( 'Home', 'moderno' );
		$breadcrumb_display_home = true;

		$home_url = home_url( '/' );

		$array_list = [];

		$is_page_for_posts = ! ! ( 'page' === get_option( 'show_on_front' ) && get_option( 'page_for_posts' ) );

		$page = get_query_var( 'paged' ) ? get_query_var( 'paged' ) : 1;

		if ( is_front_page() ) {
			// hide breadcrumbs

		} elseif ( is_home() ) {

			if ( $breadcrumb_display_home ) {
				$array_list[] = [
					'link'     => $home_url,
					'title'    => $breadcrumb_home_text,
					'location' => 'is_home',
				];
			}

			if ( $is_page_for_posts ) {
				$array_list[] = [
					'link'     => get_permalink( get_option( 'page_for_posts' ) ),
					'title'    => get_the_title( get_option( 'page_for_posts' ) ),
					'location' => 'is_category',
				];
			}


		} else if ( is_attachment() ) {

			$current_attachment_id   = get_query_var( 'attachment_id' );
			$current_attachment_link = get_attachment_link( $current_attachment_id );

			if ( $breadcrumb_display_home ) {
				$array_list[] = [
					'link'     => $home_url,
					'title'    => $breadcrumb_home_text,
					'location' => 'is_home',
				];
			}

			$array_list[] = [
				'link'     => $current_attachment_link,
				'title'    => get_the_title(),
				'location' => 'is_attachment',
			];

		} else if ( ideapark_woocommerce_on() && is_woocommerce() && is_shop() ) {
			$shop_page_id = wc_get_page_id( 'shop' );

			if ( $breadcrumb_display_home ) {
				$array_list[] = [
					'link'     => $home_url,
					'title'    => $breadcrumb_home_text,
					'location' => 'is_page',
				];
			}

			$array_list[] = [
				'link'     => get_permalink( $shop_page_id ),
				'title'    => get_the_title( $shop_page_id ),
				'location' => 'is_page',
			];

		} else if ( ideapark_woocommerce_on() && is_woocommerce() ) {
			woocommerce_breadcrumb();

		} else if ( is_page() ) {

			if ( $breadcrumb_display_home ) {
				$array_list[] = [
					'link'     => $home_url,
					'title'    => $breadcrumb_home_text,
					'location' => 'is_page',
				];
			}


			global $post;
			$home = get_post( get_option( 'page_on_front' ) );

			for ( $i = count( $post->ancestors ) - 1; $i >= 0; $i -- ) {
				if ( ( $home->ID ) != ( $post->ancestors[ $i ] ) ) {

					$array_list[] = [
						'link'     => get_permalink( $post->ancestors[ $i ] ),
						'title'    => get_the_title( $post->ancestors[ $i ] ),
						'location' => 'is_page',
					];
				}
			}


			$array_list[] = [
				'link'     => get_permalink( $post->ID ),
				'title'    => get_the_title( $post->ID ),
				'location' => 'is_page',
			];

		} else if ( is_singular() ) {

			if ( is_preview() ) {
				$array_list[] = [
					'link'     => '',
					'title'    => __( 'Post preview', 'moderno' ),
					'location' => 'post',
				];

				return $array_list;
			}

			$permalink_structure = get_option( 'permalink_structure', true );
			$permalink_structure = str_replace( '%postname%', '', $permalink_structure );
			$permalink_structure = str_replace( '%post_id%', '', $permalink_structure );

			$permalink_items = array_filter( explode( '/', $permalink_structure ) );

			global $post;
			$author_id        = $post->post_author;
			$author_posts_url = get_author_posts_url( $author_id );
			$author_name      = get_the_author_meta( 'display_name', $author_id );

			$post_date_year  = get_the_time( 'Y' );
			$post_date_month = get_the_time( 'm' );
			$post_date_day   = get_the_time( 'd' );

			$get_month_link = get_month_link( $post_date_year, $post_date_month );
			$get_year_link  = get_year_link( $post_date_year );
			$get_day_link   = get_day_link( $post_date_year, $post_date_month, $post_date_day );


			if ( $breadcrumb_display_home ) {
				$array_list[] = [
					'link'     => $home_url,
					'title'    => $breadcrumb_home_text,
					'location' => 'is_singular',
				];
			}

			if ( ! empty( $permalink_structure ) && get_post_type() == 'post' ) {

				if ( $is_page_for_posts || ! $breadcrumb_display_home ) {
					$array_list[] = [
						'link'     => $is_page_for_posts ? get_permalink( get_option( 'page_for_posts' ) ) : get_home_url(),
						'title'    => $is_page_for_posts ? get_the_title( get_option( 'page_for_posts' ) ) : __( 'Posts', 'moderno' ),
						'location' => 'is_category',
					];
				}

				if ( in_array( '%year%', $permalink_items ) ) {

					$array_list[] = [
						'link'     => $get_year_link,
						'title'    => $post_date_year,
						'location' => 'is_singular',
					];

				}

				if ( in_array( '%monthnum%', $permalink_items ) ) {
					$array_list[] = [
						'link'     => $get_month_link,
						'title'    => $post_date_month,
						'location' => 'is_singular',
					];
				}

				if ( in_array( '%day%', $permalink_items ) ) {
					$array_list[] = [
						'link'     => $get_day_link,
						'title'    => $post_date_day,
						'location' => 'is_singular',
					];

				}

				if ( in_array( '%author%', $permalink_items ) ) {
					$array_list[] = [
						'link'     => $author_posts_url,
						'title'    => $author_name,
						'location' => 'is_singular',
					];
				}

				if ( in_array( '%category%', $permalink_items ) ) {
					$category_string = get_query_var( 'category_name' );
					$category_arr    = [];
					$taxonomy        = 'category';
					if ( strpos( $category_string, '/' ) ) {

						$category_arr   = explode( '/', $category_string );
						$category_count = count( $category_arr );
						$last_cat       = $category_arr[ ( $category_count - 1 ) ];

						$term_data = get_term_by( 'slug', $last_cat, $taxonomy );

						$term_id   = $term_data->term_id;
						$term_name = $term_data->name;
						$term_link = get_term_link( $term_id, $taxonomy );


						$parents_id = get_ancestors( $term_id, $taxonomy );

						$parents_id = array_reverse( $parents_id );

						$i = 1;
						foreach ( $parents_id as $id ) {

							$parent_term_link = get_term_link( $id, $taxonomy );
							$paren_term_name  = get_term_by( 'id', $id, $taxonomy );

							$array_list[] = [
								'link'     => $parent_term_link,
								'title'    => $paren_term_name->name,
								'location' => 'is_singular',
							];


							$i ++;
						}

						$array_list[] = [
							'link'     => $term_link,
							'title'    => $term_name,
							'location' => 'is_singular',
						];
					} else {

						$term_data = get_term_by( 'slug', $category_string, $taxonomy );

						$term_id   = isset( $term_data->term_id ) ? $term_data->term_id : '';
						$term_name = isset( $term_data->name ) ? $term_data->name : '';

						if ( ! empty( $term_id ) ):
							$term_link = get_term_link( $term_id, $taxonomy );

							$array_list[] = [
								'link'     => $term_link,
								'title'    => $term_name,
								'location' => 'is_singular',
							];
						endif;
					}
				}

				$array_list[] = [
					'link'      => get_permalink( $post->ID ),
					'title'     => get_the_title( $post->ID ),
					'location'  => 'is_singular',
					'is_hidden' => ideapark_mod( 'remove_post_breadcrumbs' ),
				];

			} else {

				$post_type = get_post_type();

				$obj = get_post_type_object( $post_type );

				if ( $post_type == 'post' ) {
					if ( $is_page_for_posts || ! $breadcrumb_display_home ) {
						$array_list[] = [
							'link'     => $is_page_for_posts ? get_permalink( get_option( 'page_for_posts' ) ) : get_home_url(),
							'title'    => $is_page_for_posts ? get_the_title( get_option( 'page_for_posts' ) ) : __( 'Posts', 'moderno' ),
							'location' => 'is_singular',
						];
					}
				} else {
					$array_list[] = [
						'link'     => get_post_type_archive_link( $post_type ),
						'title'    => $obj->labels->name,
						'location' => 'is_singular',
					];
				}

				$array_list[] = [
					'link'     => get_permalink( $post->ID ),
					'title'    => get_the_title( $post->ID ),
					'location' => 'is_singular',
				];
			}


		} else if ( is_tax() ) {

			$queried_object = get_queried_object();
			$term_name      = $queried_object->name;
			$term_id        = $queried_object->term_id;

			$taxonomy   = $queried_object->taxonomy;
			$term_link  = get_term_link( $term_id, $taxonomy );
			$parents_id = get_ancestors( $term_id, $taxonomy );

			$parents_id = array_reverse( $parents_id );

			if ( $breadcrumb_display_home ) {
				$array_list[] = [
					'link'     => $home_url,
					'title'    => $breadcrumb_home_text,
					'location' => 'is_tax',
				];
			}

			foreach ( $parents_id as $id ) {

				$parent_term_link = get_term_link( $id, $taxonomy );
				$paren_term_name  = get_term_by( 'id', $id, $taxonomy );

				$array_list[] = [
					'link'     => $parent_term_link,
					'title'    => $paren_term_name->name,
					'location' => 'is_tax',
				];
			}

			$array_list[] = [
				'link'     => $term_link,
				'title'    => $term_name,
				'location' => 'is_tax',
			];


		} else if ( is_category() ) {

			$current_cat_id = get_query_var( 'cat' );
			$queried_object = get_queried_object();

			$taxonomy  = $queried_object->taxonomy;
			$term_id   = $queried_object->term_id;
			$term_name = $queried_object->name;
			$term_link = get_term_link( $term_id, $taxonomy );

			$parents_id = get_ancestors( $term_id, $taxonomy );
			$parents_id = array_reverse( $parents_id );

			if ( $breadcrumb_display_home ) {
				$array_list[] = [
					'link'     => $home_url,
					'title'    => $breadcrumb_home_text,
					'location' => 'is_category',
				];
			}

			if ( $taxonomy == 'category' ) {
				$array_list[] = [
					'link'     => $is_page_for_posts ? get_permalink( get_option( 'page_for_posts' ) ) : get_home_url(),
					'title'    => $is_page_for_posts ? get_the_title( get_option( 'page_for_posts' ) ) : __( 'Blog', 'moderno' ),
					'location' => 'is_category',
				];
			} else {
				$array_list[] = [
					'link'     => '',
					'title'    => $taxonomy,
					'location' => 'is_category',
				];
			}

			foreach ( $parents_id as $id ) {

				$parent_term_link = get_term_link( $id, $taxonomy );
				$paren_term_name  = get_term_by( 'id', $id, $taxonomy );

				$array_list[] = [
					'link'     => $parent_term_link,
					'title'    => $paren_term_name->name,
					'location' => 'is_category',
				];
			}

			$array_list[] = [
				'link'     => $term_link,
				'title'    => $term_name,
				'location' => 'is_category',
			];


		} else if ( is_tag() ) {

			$current_tag_id   = get_query_var( 'tag_id' );
			$current_tag      = get_tag( $current_tag_id );
			$current_tag_name = $current_tag->name;

			$current_tag_link = get_tag_link( $current_tag_id );;

			if ( $breadcrumb_display_home ) {
				$array_list[] = [
					'link'     => $home_url,
					'title'    => $breadcrumb_home_text,
					'location' => 'is_tag',
				];
			}

			if ( $is_page_for_posts ) {
				$array_list[] = [
					'link'     => get_permalink( get_option( 'page_for_posts' ) ),
					'title'    => get_the_title( get_option( 'page_for_posts' ) ),
					'location' => 'is_tag',
				];
			}

			$array_list[] = [
				'link'     => $current_tag_link,
				'title'    => __( 'Tag', 'moderno' ) . ': ' . $current_tag_name,
				'location' => 'is_tag',
			];
		} else if ( is_author() ) {

			if ( $breadcrumb_display_home ) {
				$array_list[] = [
					'link'     => $home_url,
					'title'    => $breadcrumb_home_text,
					'location' => 'is_author',
				];
			}

			if ( $is_page_for_posts ) {
				$array_list[] = [
					'link'     => get_permalink( get_option( 'page_for_posts' ) ),
					'title'    => get_the_title( get_option( 'page_for_posts' ) ),
					'location' => 'is_author',
				];
			}

			$array_list[] = [
				'link'     => '',
				'title'    => __( 'Author', 'moderno' ),
				'location' => 'is_author',
			];

			$array_list[] = [
				'link'     => get_author_posts_url( get_the_author_meta( "ID" ) ),
				'title'    => get_the_author(),
				'location' => 'is_author',
			];


		} else if ( is_search() ) {

			$current_query = sanitize_text_field( get_query_var( 's' ) );


			if ( $breadcrumb_display_home ) {
				$array_list[] = [
					'link'     => $home_url,
					'title'    => $breadcrumb_home_text,
					'location' => 'is_search',
				];
			}

			$array_list[] = [
				'link'     => '',
				'title'    => __( 'Search', 'moderno' ),
				'location' => 'is_search',
			];


			$array_list[] = [
				'link'     => '',
				'title'    => $current_query,
				'location' => 'is_search',
			];

		} else if ( is_year() ) {

			if ( $breadcrumb_display_home ) {
				$array_list[] = [
					'link'     => $home_url,
					'title'    => $breadcrumb_home_text,
					'location' => 'is_year',
				];
			}

			if ( $is_page_for_posts ) {
				$array_list[] = [
					'link'     => get_permalink( get_option( 'page_for_posts' ) ),
					'title'    => get_the_title( get_option( 'page_for_posts' ) ),
					'location' => 'is_year',
				];
			}

			$array_list[] = [
				'link'     => '',
				'title'    => get_the_time( 'Y' ),
				'location' => 'is_year',
			];

		} else if ( is_month() ) {

			if ( $breadcrumb_display_home ) {
				$array_list[] = [
					'link'     => $home_url,
					'title'    => $breadcrumb_home_text,
					'location' => 'is_month',
				];
			}

			if ( $is_page_for_posts ) {
				$array_list[] = [
					'link'     => get_permalink( get_option( 'page_for_posts' ) ),
					'title'    => get_the_title( get_option( 'page_for_posts' ) ),
					'location' => 'is_month',
				];
			}

			$array_list[] = [
				'link'     => get_year_link( get_the_time( 'Y' ) ),
				'title'    => get_the_time( 'Y' ),
				'location' => 'is_month',
			];


			$array_list[] = [
				'link'     => '',
				'title'    => get_the_time( 'F' ),
				'location' => 'is_month',
			];

		} else if ( is_date() ) {

			if ( $breadcrumb_display_home ) {
				$array_list[] = [
					'link'     => $home_url,
					'title'    => $breadcrumb_home_text,
					'location' => 'is_date',
				];
			}

			if ( $is_page_for_posts ) {
				$array_list[] = [
					'link'     => get_permalink( get_option( 'page_for_posts' ) ),
					'title'    => get_the_title( get_option( 'page_for_posts' ) ),
					'location' => 'is_date',
				];
			}

			$array_list[] = [
				'link'     => get_year_link( get_the_time( 'Y' ) ),
				'title'    => get_the_time( 'Y' ),
				'location' => 'is_date',
			];

			$array_list[] = [
				'link'     => get_month_link( get_the_time( 'Y' ), get_the_time( 'm' ) ),
				'title'    => get_the_time( 'F' ),
				'location' => 'is_date',
			];

			$array_list[] = [
				'link'     => '',
				'title'    => get_the_time( 'd' ),
				'location' => 'is_date',
			];
		} elseif ( is_404() ) {

			if ( $breadcrumb_display_home ) {
				$array_list[] = [
					'link'     => $home_url,
					'title'    => $breadcrumb_home_text,
					'location' => 'is_404',
				];
			}

			$array_list[] = [
				'link'     => '',
				'title'    => __( 'Page not found', 'moderno' ),
				'location' => 'is_404',
			];

		} elseif ( is_post_type_archive() ) {

			if ( $breadcrumb_display_home ) {
				$array_list[] = [
					'link'     => $home_url,
					'title'    => $breadcrumb_home_text,
					'location' => 'is_tax',
				];
			}

			$queried_object = get_queried_object();
			$array_list[]   = [
				'link'     => get_post_type_archive_link( $queried_object->query_var ),
				'title'    => $queried_object->label,
				'location' => 'is_tax',
			];
		}

		if ( $array_list && $page <= 1 ) {
			$array_list[ sizeof( $array_list ) - 1 ]['link'] = false;
		}

		return $array_list;

	}
}

if ( ! function_exists( 'ideapark_shorten_string' ) ) {
	function ideapark_shorten_string( $string, $limit_by = 'word', $limit_count = 4, $ending = '...' ) {

		if ( empty( $limit_count ) ) {
			$limit_count = 4;
		}

		if ( $limit_by == 'character' ) {
			if ( strlen( $string ) > $limit_count ) {
				$stringCut = substr( $string, 0, $limit_count );
				$string    = substr( $stringCut, 0, strrpos( $stringCut, ' ' ) );

				return $string . $ending;
			} else {
				return $string;
			}
		} else {
			$array = explode( " ", $string );
			if ( count( $array ) <= $limit_count ) {
				$retval = $string;
			} else {
				array_splice( $array, $limit_count );
				$retval = implode( " ", $array ) . $ending;
			}

			return $retval;
		}
	}
}

if ( ! function_exists( 'ideapark_is_elementor' ) ) {
	function ideapark_is_elementor() {
		return class_exists( 'Elementor\Plugin' );
	}
}

if ( ! function_exists( 'ideapark_is_elementor_page' ) ) {
	function ideapark_is_elementor_page( $page_id = null ) {
		global $post;
		if ( $page_id === null ) {
			$page_id = $post->ID;
		}

		return ideapark_is_elementor() && ( $elementor_instance = Elementor\Plugin::instance() ) && ( $el_page = $elementor_instance->documents->get( $page_id ) ) && $el_page->is_built_with_elementor();
	}
}

if ( ! function_exists( 'ideapark_set_cookie' ) ) {
	function ideapark_set_cookie( $name, $value, $expire = 0, $secure = false, $httponly = false ) {
		if ( ! headers_sent() ) {
			setcookie( $name, $value, $expire, defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, $secure, $httponly );
		}
	}
}

if ( ! function_exists( 'ideapark_theme_font' ) ) {
	function ideapark_theme_font( $fonts ) {
		$zip = IDEAPARK_DIR . '/plugins/font/icons.zip';

		if ( ideapark_is_file( $zip ) ) {
			$fonts['icons'] = [
				'zip'     => $zip,
				'version' => ideapark_mtime( IDEAPARK_DIR . '/plugins/font/icons.zip' )
			];
		}

		return $fonts;
	}
}

if ( ! function_exists( 'ideapark_elementor_add_css_editor' ) ) {
	function ideapark_elementor_add_css_editor() {
		wp_enqueue_style( 'ideapark-font', IDEAPARK_URI . '/assets/font/theme-icons.css', [], ideapark_mtime( IDEAPARK_DIR . '/assets/font/theme-icons.css' ), 'all' );
	}
}

if ( ! function_exists( 'ideapark_comment_form_fields' ) ) {
	function ideapark_comment_form_fields( $fields ) {

		if ( isset( $fields['comment'] ) && preg_match( '~comment-form-rating~', $fields['comment'] ) ) {
			return $fields;
		}

		if ( isset( $fields['cookies'] ) ) {
			$cookies_consent = $fields['cookies'];
			unset( $fields['cookies'] );
			$fields['cookies'] = $cookies_consent;
		}

		foreach ( $fields as $index => $field ) {
			if ( ( $index != 'cookies' ) && ( $label = preg_match( '~<label for="[^"]+">([^<]+)<~ui', $field, $match ) ) ) {
				$is_required      = preg_match( '~class="required"~ui', $field );
				$fields[ $index ] = preg_replace( '~<label[\s\S]+</label>~uiU', '', $fields[ $index ] );
				$fields[ $index ] = preg_replace( '~ id="~ui', ' placeholder="' . esc_attr( wp_specialchars_decode( $match[1] ) ) . ( $is_required ? '*' : '' ) . '" id="', $fields[ $index ] );
			}
		}

		return $fields;
	}
}

if ( ! function_exists( 'ideapark_utf8_wordwrap' ) ) {
	function ideapark_utf8_wordwrap( $string, $width = 75, $break = "\n", $cut = false ) {
		if ( $cut ) {
			// Match anything 1 to $width chars long followed by whitespace or EOS,
			// otherwise match anything $width chars long
			$search  = '/(.{1,' . $width . '})(?:\s|$)|(.{' . $width . '})/uS';
			$replace = '$1$2' . $break;
		} else {
			// Anchor the beginning of the pattern with a lookahead
			// to avoid crazy backtracking when words are longer than $width
			$search  = '/(?=\s)(.{1,' . $width . '})(?:\s|$)/uS';
			$replace = '$1' . $break;
		}

		return preg_replace( $search, $replace, $string );
	}
}

if ( ! function_exists( 'ideapark_truncate' ) ) {
	function ideapark_truncate( $str, $limit, $ending = '&hellip;' ) {
		return strtok( ideapark_utf8_wordwrap( $str, $limit, "{$ending}\n", true ), "\n" );
	}
}

if ( ! function_exists( 'ideapark_truncate_logo_placeholder' ) ) {
	function ideapark_truncate_logo_placeholder() {
		$str = get_bloginfo( 'name', 'display' );

		return esc_html( trim( ideapark_mod( 'truncate_logo_placeholder' ) ? ideapark_truncate( $str, 10, '' ) : $str, " -/.,\r\n\t" ) );
	}
}

if ( ! function_exists( 'ideapark_pingback_header' ) ) {
	function ideapark_pingback_header() {
		if ( is_singular() && pings_open() ) {
			echo '<link rel="pingback" href="', esc_url( get_bloginfo( 'pingback_url' ) ), '">';
		}
	}
}

if ( ! function_exists( 'ideapark_generator_tag' ) ) {
	function ideapark_generator_tag( $gen, $type ) {
		switch ( $type ) {
			case 'html':
				$gen .= "\n" . '<meta name="generator" content="' . esc_attr( IDEAPARK_NAME . ' ' . IDEAPARK_VERSION ) . '">';
				break;
			case 'xhtml':
				$gen .= "\n" . '<meta name="generator" content="' . esc_attr( IDEAPARK_NAME . ' ' . IDEAPARK_VERSION ) . '" />';
				break;
		}

		return $gen;
	}
}

if ( ! function_exists( 'ideapark_post_params' ) ) {
	function ideapark_post_params() {
		global $post;
		$ret               = [];
		$ret['is_product'] = ( ideapark_woocommerce_on() && get_post_type() == 'product' );
		$ret['is_page']    = ( get_post_type() == 'page' );
		$ret['format']     = get_post_format();
		if ( ! $ret['format'] ) {
			$ret['format'] = 'standard';
		}
		if ( ! in_array( $ret['format'], [ 'standard', 'image', 'video', 'gallery' ] ) ) {
			$ret['format'] = 'standard';
		}

		$ret['video_url']     = get_post_meta( $post->ID, 'post_video_url', true );
		$ret['image_gallery'] = empty( ideapark_mod( '_disable_post_image_gallery' ) ) ? get_post_meta( $post->ID, 'post_image_gallery' ) : false;
		$ret['has_thumb']     = has_post_thumbnail() || ( $ret['format'] == 'video' && $ret['video_url'] ) || ( $ret['format'] == 'gallery' && $ret['image_gallery'] );

		return $ret;
	}
}

if ( ! function_exists( 'ideapark_header_params' ) ) {
	function ideapark_header_params() {
		global $post;
		static $result;

		if ( $result === null ) {

			$result = [
				'header_height'      => '',
				'header_type'        => ideapark_mod( 'header_type' ),
				'header_type_mobile' => ideapark_mod( 'header_type_mobile' ),
				'header_class'       => '',
				'title_align'        => 'default',
			];

			$post_type = '';
			$postfix   = '';

			if ( ( ( is_admin() && function_exists( 'get_current_screen' ) && ( $screen = get_current_screen() ) && ! empty( $screen->id ) && in_array( $screen->id, [
							'page',
							'post',
						] ) ) || IDEAPARK_IS_AJAX ) && ! empty( $post->ID ) ) {
				$post_type = get_post_type( $post->ID );
			}

			if ( $height = ideapark_mod( 'header_height' ) ) {
				$result['header_height'] = $height;
			}

			if ( ideapark_woocommerce_on() && ( is_cart() || is_checkout() || is_account_page() || ideapark_is_wishlist_page() ) ) {
				$result['header_class'] = 'wc';
				$postfix                = 'wc';
			} elseif ( ideapark_woocommerce_on() && is_product() ) {
				$result['header_class'] = 'product-page';
				$postfix                = 'product_page';
			} elseif ( ideapark_woocommerce_on() && ( is_product_taxonomy() || is_shop() ) ) {
				$result['header_class'] = 'product-list';
				$postfix                = 'product_list';
			} elseif ( is_page() || $post_type == 'page' ) {
				$result['header_class'] = 'page';
				$postfix                = 'page';
			} elseif ( is_single() || is_home() || is_archive() ) {
				$result['header_class'] = 'post';
				$postfix                = 'post';
			}

			if ( $postfix && ( $height = ideapark_mod( 'header_height_' . $postfix ) ) && ( $height != 'default' ) ) {
				$result['header_height'] = $height;
			}

			if (
				( ( is_category() || is_tax() || is_tag() ) && ( $queried_object = get_queried_object() ) && ( $term_meta = get_term_meta( $queried_object->term_id ) ) )
			) {

				if ( ! empty( $term_meta['header_height'][0] ) && ( $term_meta['header_height'][0] != 'default' ) ) {
					$result['header_height'] = $term_meta['header_height'][0];
				}
			}

			if (
				( is_singular() && ( $post_id = $post->ID ) ) ||
				( ideapark_woocommerce_on() && is_shop() && ( $post_id = wc_get_page_id( 'shop' ) ) ) ||
				( is_home() && get_option( 'page_for_posts' ) && 'page' == get_option( 'show_on_front' ) && ( $post_id = get_option( 'page_for_posts' ) ) )
			) {

				if ( ( $val = get_post_meta( $post_id, 'header_height', true ) ) && ( $val != 'default' ) ) {
					$result['header_height'] = $val;
				}

				if ( $val = get_post_meta( $post_id, 'title_align', true ) ) {
					$result['title_align'] = $val;
				}
			}
		}

		if ( ! in_array( $result['header_height'], [ 'hide', 'no-title', 'no-breadcrumbs', 'low' ] ) ) {
			$result['header_height'] = 'low';
		}

		return $result;
	}
}

if ( ! function_exists( 'ideapark_is_wishlist_page' ) ) {
	function ideapark_is_wishlist_page() {
		global $post;

		return ( ideapark_woocommerce_on() && is_page() && ideapark_mod( 'wishlist_page' ) && apply_filters( 'wpml_object_id', ideapark_mod( 'wishlist_page' ), 'any' ) == apply_filters( 'wpml_object_id', $post->ID, 'any' ) );
	}
}

if ( ! function_exists( 'ideapark_image_meta' ) ) {
	function ideapark_image_meta( $image_id, $size = 'full', $sizes = '' ) {
		$full = wp_get_attachment_image_src( $image_id, 'full' );
		if ( $image = $size == 'full' ? $full : wp_get_attachment_image_src( $image_id, $size ) ) {
			$srcset     = wp_get_attachment_image_srcset( $image_id, $size );
			$sizes      = $sizes ?: wp_get_attachment_image_sizes( $image_id, $size );
			$alt        = get_post_meta( $image_id, '_wp_attachment_image_alt', true );
			$attachment = get_post( $image_id );
			if ( ! $alt ) {
				if ( ! $attachment ) {
					$alt = '';
				} else {
					$alt = $attachment->post_excerpt;
					if ( ! $alt ) {
						$alt = $attachment->post_title;
					}
				}
			}
			$alt   = trim( strip_tags( $alt ) );
			$title = $attachment ? $attachment->post_title : '';

			return [
				'width'       => $image[1],
				'height'      => $image[2],
				'src'         => $image[0],
				'srcset'      => $srcset,
				'sizes'       => $sizes,
				'full'        => $full[0],
				'full_width'  => $full[1],
				'full_height' => $full[2],
				'alt'         => $alt,
				'title'       => $title,
			];
		}

		return false;
	}
}

if ( ! function_exists( 'ideapark_img' ) ) {
	function ideapark_img( $image_meta, $class = '', $lazy = null, $attr = [] ) {
		static $is_skip_loading;
		if ( $is_skip_loading === null ) {
			$is_skip_loading = ( '1' === get_option( 'elementor_optimized_image_loading', '1' ) ) || wp_lazy_loading_enabled( 'img', null );
		}
		if ( $image_meta ) {
			if ( $class ) {
				$image_meta['class'] = $class;
			}
			if ( ! $is_skip_loading && ( ideapark_mod( 'lazyload' ) && $lazy === null || $lazy === true ) ) {
				$image_meta['loading'] = 'lazy';
			} elseif ( is_string( $lazy ) ) {
				$image_meta['loading'] = $lazy;
			}
			$attr_names = [
				'class',
				'width',
				'height',
				'src',
				'srcset',
				'sizes',
				'alt',
				'loading'
			];
			if ( is_array( $attr ) && $attr ) {
				foreach ( $attr as $key => $val ) {
					$image_meta[ $key ] = $val;
					if ( ! in_array( $key, $attr_names ) ) {
						$attr_names[] = $key;
					}
				}
			}
			$s = "<img";
			foreach (
				$attr_names as $attr_name
			) {
				if ( ! empty( $image_meta[ $attr_name ] ) || is_array( $attr ) && array_key_exists( $attr_name, $attr ) ) {
					if ( ( $attr_name == 'srcset' || $attr_name == 'sizes' ) && ( empty( $image_meta['srcset'] ) || empty( $image_meta['sizes'] ) ) ) {
						continue;
					}
					$s .= ' ' . $attr_name . '="' . esc_attr( $image_meta[ $attr_name ] ) . '"';
				}
			}
			$s .= "/>";

			return $s;
		}
	}
}

if ( ! function_exists( 'ideapark_source' ) ) {
	function ideapark_source( $image_meta, $attr = [], $media = '(max-width: 767px)' ) {
		if ( $image_meta ) {
			$image_meta['media'] = $media;
			$attr_names          = [
				'media',
				'srcset',
				'sizes'
			];
			if ( is_array( $attr ) && $attr ) {
				foreach ( $attr as $key => $val ) {
					$image_meta[ $key ] = $val;
					if ( ! in_array( $key, $attr_names ) ) {
						$attr_names[] = $key;
					}
				}
			}
			$s = "<source";
			foreach (
				$attr_names as $attr_name
			) {
				if ( ! empty( $image_meta[ $attr_name ] ) || is_array( $attr ) && array_key_exists( $attr_name, $attr ) ) {
					if ( ( $attr_name == 'srcset' || $attr_name == 'sizes' ) && ( empty( $image_meta['srcset'] ) || empty( $image_meta['sizes'] ) ) ) {
						continue;
					}
					$s .= ' ' . $attr_name . '="' . esc_attr( $image_meta[ $attr_name ] ) . '"';
				}
			}
			$s .= "/>";

			return $s;
		} else {
			return '';
		}
	}
}

if ( ! function_exists( 'ideapark_mod_image_size' ) ) {
	function ideapark_mod_image_size( $mod_name ) {
		if ( ideapark_mod( $mod_name . '__width' ) && ideapark_mod( $mod_name . '__height' ) ) {
			return ' width="' . ideapark_mod( $mod_name . '__width' ) . '" height="' . ideapark_mod( $mod_name . '__height' ) . '" ';
		} else {
			return '';
		}
	}
}

if ( ! function_exists( 'ideapark_post_status' ) ) {
	function ideapark_post_status( $post_id ) {
		global $wpdb;
		$query = "SELECT post_status FROM {$wpdb->posts} WHERE ID=%d";

		return $wpdb->get_var( $wpdb->prepare( $query, $post_id ) );
	}
}

if ( ! function_exists( 'ideapark_advert_bar_render' ) ) {
	function ideapark_advert_bar_render() {
		ideapark_mod_set_temp( '_advert_bar', ideapark_html_block( ideapark_mod( 'header_advert_bar_page' ) ) );
	}
}

if ( ! function_exists( 'ideapark_footer_render' ) ) {
	function ideapark_footer_render() {
		global  $post;
		$page_id = ideapark_mod( 'footer_page' );

		if (
			( ( is_category() || is_tax() || is_tag() ) && ( $queried_object = get_queried_object() ) && ( $term_meta = get_term_meta( $queried_object->term_id ) ) )
		) {
			if ( ! empty( $term_meta['footer_page'][0] ) && ( $term_meta['footer_page'][0] != 'default' ) ) {
				$page_id = (int) $term_meta['footer_page'][0];
			}
		}

		if (
			( is_singular() && ( $post_id = $post->ID ) ) ||
			( ideapark_woocommerce_on() && is_shop() && ( $post_id = wc_get_page_id( 'shop' ) ) ) ||
			( is_home() && get_option( 'page_for_posts' ) && 'page' == get_option( 'show_on_front' ) && ( $post_id = get_option( 'page_for_posts' ) ) )
		) {
			if ( ( $val = get_post_meta( $post_id, 'footer_page', true ) ) && ( $val != 'default' ) ) {
				$page_id = (int) $val;
			}
		}

		if ( $page_id ) {
			ideapark_mod_set_temp( '_footer', ideapark_html_block( $page_id ) );
			ideapark_mod_set_temp( '_footer_is_elementor', ideapark_is_elementor_page( $page_id ) );
		}
	}
}

if ( ! function_exists( 'ideapark_category_html_blocks_render' ) ) {
	function ideapark_category_html_blocks_render() {
		$ideapark_category_html_top_above = '';

		if ( ideapark_woocommerce_on() && ! is_search() && ( is_product_category() || is_shop() || is_product_taxonomy() ) ) {

			$page = get_query_var( 'paged' ) ? get_query_var( 'paged' ) : 1;
			if ( is_product_taxonomy() ) {
				$attribute_id = get_queried_object_id();

				if ( get_term_meta( $attribute_id, 'html_block_first_page', true ) && $page > 1 ) {
					return;
				}

				$html_block_top    = apply_filters( 'wpml_object_id', get_term_meta( $attribute_id, 'html_block_top', true ), 'any' );
				$html_block_bottom = apply_filters( 'wpml_object_id', get_term_meta( $attribute_id, 'html_block_bottom', true ), 'any' );

				$ideapark_category_html_top_above = get_term_meta( $attribute_id, 'top_block_above', true );
			} elseif ( is_product_category() ) {
				$category_id = get_queried_object_id();

				if ( get_term_meta( $category_id, 'html_block_first_page', true ) && $page > 1 ) {
					return;
				}

				$html_block_top    = apply_filters( 'wpml_object_id', get_term_meta( $category_id, 'html_block_top', true ), 'any' );
				$html_block_bottom = apply_filters( 'wpml_object_id', get_term_meta( $category_id, 'html_block_bottom', true ), 'any' );

				$ideapark_category_html_top_above = get_term_meta( $category_id, 'top_block_above', true );
			} else {
				if ( ideapark_mod( 'shop_html_block_first_page' ) && $page > 1 ) {
					return;
				}
				$html_block_top    = apply_filters( 'wpml_object_id', ideapark_mod( 'shop_html_block_top' ), 'any' );
				$html_block_bottom = apply_filters( 'wpml_object_id', ideapark_mod( 'shop_html_block_bottom' ), 'any' );

				$ideapark_category_html_top_above = ideapark_mod( 'shop_top_block_above' );
			}

			if ( $ideapark_category_html_top_above ) {
				ideapark_mod_set_temp( '_category_html_top_above', $ideapark_category_html_top_above );
			}

			if ( $html_block_top ) {
				ideapark_mod_set_temp( '_category_html_top', ideapark_html_block( $html_block_top ) );
			}

			if ( $html_block_bottom ) {
				ideapark_mod_set_temp( '_category_html_bottom', ideapark_html_block( $html_block_bottom ) );
			}
		}
	}
}

if ( ! function_exists( 'ideapark_is_rtl' ) ) {
	function ideapark_is_rtl() {
		return apply_filters( 'ideapark_is_rtl', is_rtl() );
	}
}

if ( ! function_exists( 'ideapark_is_elementor_preview' ) ) {
	function ideapark_is_elementor_preview() {
		return ideapark_is_elementor() && isset( $_GET['action'] ) && $_GET['action'] == 'elementor' && ! empty( $_GET['post'] ) && is_admin();
	}
}

if ( ! function_exists( 'ideapark_is_elementor_preview_mode' ) ) {
	function ideapark_is_elementor_preview_mode() {
		return ideapark_is_elementor() && ! empty( \Elementor\Plugin::$instance->preview ) && \Elementor\Plugin::$instance->preview->is_preview_mode();
	}
}

if ( ! function_exists( 'ideapark_font_icons_smoothing' ) ) {
	function ideapark_font_icons_smoothing() {
		return '
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
';
	}
}

if ( ! function_exists( 'ideapark_is_transparent_header' ) ) {
	function ideapark_is_transparent_header() {
		return apply_filters( 'ideapark_is_transparent_header', ideapark_mod( 'transparent_header' ) && is_front_page() );
	}
}

if ( ! function_exists( 'ideapark_wp_kses' ) ) {
	function ideapark_wp_kses( $string = '' ) {
		return wp_kses( $string, [
			'a'      => [
				'class'  => [],
				'href'   => [],
				'target' => []
			],
			'code'   => [
				'class' => []
			],
			'strong' => [],
			'br'     => [],
			'em'     => [],
			'p'      => [
				'class' => []
			],
			'span'   => [
				'class' => []
			],
		] );
	}
}

if ( ! function_exists( 'ideapark_wpml_theme_mods' ) ) {
	function ideapark_wpml_theme_mods() {
		if ( defined( 'WPML_ST_PATH' ) ) {
			$theme_path = get_stylesheet_directory();
			require_once WPML_ST_PATH . '/inc/admin-texts/wpml-admin-text-import.class.php';
			if ( class_exists( 'WPML_XML_Config_Validate' ) && class_exists( 'WPML_XML2Array' ) && class_exists( 'WPML_XML_Config_Read_File' ) ) {
				$validate        = new WPML_XML_Config_Validate( WPML_PLUGIN_PATH . '/res/xsd/wpml-config.xsd' );
				$transform       = new WPML_XML2Array();
				$xml_config_file = new WPML_XML_Config_Read_File( $theme_path . '/wpml-config.xml', $validate, $transform );
				$config          = $xml_config_file->get();
				if ( isset( $config['wpml-config']['admin-texts']['key']['attr']['name'], $config['wpml-config']['admin-texts']['key']['key'] ) && is_array( $config['wpml-config']['admin-texts']['key']['key'] ) ) {
					foreach ( $config['wpml-config']['admin-texts']['key']['key'] as $row ) {
						$name = $row['attr']['name'];
						set_theme_mod( $name, ideapark_mod( $name ) );
					}
				}
			}
		}
	}

	add_action( 'after_switch_theme', function () {
		add_action( 'wp_loaded', 'ideapark_wpml_theme_mods', 999 );
	} );
	add_action( 'wpml_activated', function () {
		add_action( 'wp_loaded', 'ideapark_wpml_theme_mods', 999 );
	} );
}

if ( ! function_exists( 'ideapark_wp_footer' ) ) {
	function ideapark_wp_footer() {
		if ( ideapark_mod( 'shop_modal' ) ) { ?>
			<div
				class="c-header__callback-popup c-header__callback-popup--disabled js-callback-popup js-quickview-popup">
				<div class="c-header__callback-bg js-callback-close"></div>
				<div class="c-header__callback-wrap c-header__callback-wrap--quickview">
					<div class="js-quickview-container"></div>
					<button type="button" class="h-cb h-cb--svg h-close-button js-callback-close"><i
							class="ip-close-rect"></i></button>
				</div>
			</div>
		<?php } ?>
		<div
			class="c-header__callback-popup c-header__callback-popup--disabled js-callback-popup js-attribute-hint-popup">
			<div class="c-header__callback-wrap c-header__callback-wrap--hint">
				<div
					class="c-header__callback-content c-header__callback-content--hint js-attribute-hint-container"></div>
				<button type="button" class="h-cb h-cb--svg h-close-button js-callback-close"><i
						class="ip-close-rect"></i></button>
			</div>
		</div>
		<?php
		get_template_part( 'templates/pswp' );
		if ( ideapark_mod( 'to_top_button' ) ) { ?>
			<button class="c-to-top-button js-to-top-button c-to-top-button--without-menu" type="button">
				<i class="ip-right-default c-to-top-button__svg"></i>
			</button>
		<?php }
	}
}

if ( ! function_exists( 'ideapark_social_networks' ) ) {
	function ideapark_social_networks( $filter = [] ) {
		$networks = [
			'facebook'  => __( 'Facebook', 'moderno' ),
			'instagram' => __( 'Instagram', 'moderno' ),
			'vk'        => __( 'VK', 'moderno' ),
			'ok'        => __( 'OK', 'moderno' ),
			'telegram'  => __( 'Telegram', 'moderno' ),
			'whatsapp'  => __( 'Whatsapp', 'moderno' ),
			'line'      => __( 'Line', 'moderno' ),
			'wechat'    => __( 'WeChat', 'moderno' ),
			'viber'     => __( 'Viber', 'moderno' ),
			'discord'   => __( 'Discord', 'moderno' ),
			'twitter'   => __( 'X (Twitter)', 'moderno' ),
			'threads'   => __( 'Threads', 'moderno' ),
			'youtube'   => __( 'YouTube', 'moderno' ),
			'vimeo'     => __( 'Vimeo', 'moderno' ),
			'snapchat'  => __( 'Snapchat', 'moderno' ),
			'tiktok'    => __( 'TikTok', 'moderno' ),
			'linkedin'  => __( 'LinkedIn', 'moderno' ),
			'flickr'    => __( 'Flickr', 'moderno' ),
			'pinterest' => __( 'Pinterest', 'moderno' ),
			'tumblr'    => __( 'Tumblr', 'moderno' ),
			'github'    => __( 'Github', 'moderno' ),
		];
		if ( $filter ) {
			return array_filter( $networks, function ( $key ) use ( $filter ) {
				return in_array( $key, $filter );
			}, ARRAY_FILTER_USE_KEY );

		} else {
			return $networks;
		}
	}
}

if ( ! function_exists( 'ideapark_clear_cache' ) ) {
	function ideapark_clear_cache( $is_redirect = true ) {
		global $wpdb;
		do_action( 'after_update_theme', IDEAPARK_VERSION, IDEAPARK_VERSION );
		do_action( 'after_update_theme_late', IDEAPARK_VERSION, IDEAPARK_VERSION );
		delete_option( 'ideapark_scripts_hash' );
		if ( ideapark_is_elementor() ) {
			$elementor_instance = Elementor\Plugin::instance();
			$elementor_instance->files_manager->clear_cache();
		}
		$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '\_transient\_%' OR option_name LIKE '\_site\_transient\_%'" );
		$wpdb->query( "DELETE FROM $wpdb->postmeta WHERE meta_key = '_ideapark_inline_svg'" );
		ideapark_regenerate_wc_lookup();

		$sql = $wpdb->prepare( "SELECT term_taxonomy_id FROM $wpdb->term_taxonomy WHERE taxonomy = %s", 'nav_menu' );
		if ( ( $term_taxonomy_ids = $wpdb->get_col( $sql ) ) && ! is_wp_error( $term_taxonomy_ids ) ) {
			wp_update_term_count_now( $term_taxonomy_ids, 'nav_menu' );
		}

		wp_cache_flush();
		do_action( 'ideapark_clear_cache' );
		if ( $is_redirect ) {
			ideapark_add_admin_notice( esc_html__( 'The theme cache has been successfully cleared', 'moderno' ), 'success' );
			wp_redirect( admin_url( 'themes.php?page=ideapark_about' ) );
		}
	}
}

if ( ! function_exists( 'ideapark_regenerate_wc_lookup' ) ) {
	function ideapark_regenerate_wc_lookup() {
		if ( class_exists( 'WC_REST_System_Status_Tools_Controller' ) ) {
			try {
				if ( function_exists( 'wc_get_container' ) ) {
					wc_get_container()->get( Automattic\WooCommerce\Internal\ProductAttributesLookup\DataRegenerator::class )->initiate_regeneration();
				}
				$tools_controller = new WC_REST_System_Status_Tools_Controller();
				$tools_controller->execute_tool( 'regenerate_product_lookup_tables' );
				$tools_controller->execute_tool( 'db_update_routine' );
				WC()->queue()->schedule_single(
					time() + 5,
					'ideapark_delete_transient'
				);
				$tools_controller->execute_tool( 'db_update_routine' );
			} catch ( Exception $e ) {
			}
		}
	}
}

if ( ! function_exists( 'ideapark_shy' ) ) {
	function ideapark_shy( $s ) {
		return preg_replace( '~(\S{15})~u', '\\1&shy;', $s );
	}
}

if ( ! function_exists( 'ideapark_svg_logo_class' ) ) {
	function ideapark_svg_logo_class( $url ) {
		echo preg_match( '~\.svg$~i', $url ) ? ' c-header__logo-img--svg ' : '';
	}
}

if ( ! function_exists( 'ideapark_close_button' ) ) {
	function ideapark_close_button( $class = '' ) { ?>
		<a href="#" onclick="return false" class="h-close-button <?php echo esc_attr( $class ); ?>"><i
				class="ip-close-rect"></i></a>
	<?php }
}

if ( ! function_exists( 'ideapark_filter_button' ) ) {
	function ideapark_filter_button( $class = '' ) {
		static $cache;

		if ( $cache !== null ) {
			return str_replace( '{{class}}', $class, $cache );
		}

		if ( ideapark_woocommerce_on() && is_woocommerce() && is_archive() && ( ideapark_get_loop_display_mode() != 'subcategories' ) ) {
			$_chosen_attributes = WC_Query::get_layered_nav_chosen_attributes();
			$min_price          = isset( $_GET['min_price'] ) ? wp_unslash( $_GET['min_price'] ) : 0;
			$max_price          = isset( $_GET['max_price'] ) ? wp_unslash( $_GET['max_price'] ) : 0;
			$rating_filter      = isset( $_GET['rating_filter'] ) ? array_filter( array_map( 'absint', explode( ',', wp_unslash( $_GET['rating_filter'] ) ) ) ) : [];

			$active_filter_counts = count( $_chosen_attributes ) + ( $min_price > 0 ? 1 : 0 ) + ( $max_price > 0 ? 1 : 0 ) + ( ! empty( $rating_filter ) ? 1 : 0 );

			extract( ideapark_init_archive_layout() );
			ob_start();
			if ( $with_filter_desktop ) { ?>
				<button
					class="h-cb c-page-header__filter c-button c-button--small c-page-header__filter--desktop js-filter-show-button {{class}}"
					type="button"><i
						class="ip-filter c-page-header__filter-ico"></i><?php esc_html_e( 'Filter', 'moderno' ); ?>
					<?php echo ideapark_wrap( $active_filter_counts ?: '', '<span class="c-page-header__filter-count">', '</span>' ); ?>
				</button>
			<?php }
			if ( $with_filter_mobile ) { ?>
				<button
					class="h-cb c-page-header__filter c-button c-button--small c-page-header__filter--mobile js-filter-show-button {{class}}"
					type="button"><i
						class="ip-filter c-page-header__filter-ico"></i><?php esc_html_e( 'Filter', 'moderno' ); ?>
					<?php echo ideapark_wrap( $active_filter_counts ?: '', '<span class="c-page-header__filter-count">', '</span>' ); ?>
				</button>
			<?php }

			$cache = ob_get_clean();

			return str_replace( '{{class}}', $class, $cache );
		} else {
			return '';
		}
	}
}

if ( ! function_exists( 'ideapark_hidden_category_ids' ) ) {
	function ideapark_hidden_category_ids() {
		static $cache;
		global $wpdb;

		if ( ! is_null( $cache ) ) {
			return $cache;
		}

		$main_category_ids = [];

		if ( $category_id = ideapark_mod( 'hidden_product_category' ) ) {
			$main_category_ids[] = $category_id;
		}

		if ( ideapark_mod( 'hide_uncategorized' ) && ( $category_id = get_option( 'default_product_cat' ) ) ) {
			$main_category_ids[] = $category_id;
		}

		if ( empty( $wpdb->wc_category_lookup ) ) {
			$wpdb->wc_category_lookup = $wpdb->prefix . 'wc_category_lookup';
		}

		if ( $main_category_ids ) {
			$_category_ids = $wpdb->get_col( "SELECT category_id FROM  {$wpdb->wc_category_lookup} WHERE category_tree_id IN (" . implode( ',', $main_category_ids ) . ")" );
			$category_ids  = array_merge( array_unique( $main_category_ids ), array_map( function ( $category_id ) {
				return apply_filters( 'wpml_object_id', $category_id, 'product_cat', true );
			}, $_category_ids ) );
		} else {
			$category_ids = [];
		}

		return $cache = array_filter( array_unique( $category_ids ) );
	}
}

if ( ! function_exists( 'ideapark_get_wvs_get_option' ) ) {
	function ideapark_get_wvs_get_option( $option, $default = null ) {
		$options = get_option( 'woo_variation_swatches' );

		if ( current_theme_supports( 'woo_variation_swatches' ) ) {
			$theme_support = get_theme_support( 'woo_variation_swatches' );
			$default       = isset( $theme_support[0][ $option ] ) ? $theme_support[0][ $option ] : $default;
		}

		return isset( $options[ $option ] ) ? $options[ $option ] : $default;
	}
}

if ( ! function_exists( 'ideapark_is_shop' ) ) {
	function ideapark_is_shop() {
		return ideapark_woocommerce_on() && is_shop();
	}
}

if ( ! function_exists( 'ideapark_wrap_open' ) ) {
	function ideapark_wrap_open() {
		echo '<div class="l-wrap">';
	}
}

if ( ! function_exists( 'ideapark_wrap_close' ) ) {
	function ideapark_wrap_close() {
		echo '</div><!-- /.l-wrap -->';
	}
}

if ( ! function_exists( 'ideapark_inline_video' ) ) {
	function ideapark_inline_video( $scr_video, $src_poster = '', $class = '', $attr = 'autoplay muted loop playsinline preload="auto"' ) {
		$yt_pattern    = '#^https?://(?:www\.)?(?:youtube\.com/watch|youtu\.be/)#';
		$vimeo_pattern = '#^https?://(.+\.)?vimeo\.com/.*#';
		$default_types = wp_get_video_extensions();

		if ( ! empty( $scr_video ) ) {
			$is_vimeo   = ( preg_match( $vimeo_pattern, $scr_video ) );
			$is_youtube = ( preg_match( $yt_pattern, $scr_video ) );

			if ( ! $is_youtube && ! $is_vimeo ) {
				$type = wp_check_filetype( $scr_video, wp_get_mime_types() );

				if ( in_array( strtolower( $type['ext'] ), $default_types, true ) ) {
					$html = '';
					// Attributes: autoplay muted loop playsinline disablepictureinpicture controls controlslist="play timeline volume nofullscreen nodownload noremoteplayback noplaybackrate foobar"
					$html .= '<video class="c-inline-video js-inline-video ' . esc_attr( $class ) . '" ' . ideapark_wrap( $attr ) . ( $src_poster ? ' poster="' . esc_url( $src_poster ) . '"' : '' ) . '>';
					$html .= sprintf( '<source type="%s" src="%s" />', $type['type'], esc_url( $scr_video ) );
					$html .= '</video>';

					return $html;
				}
			}
		}
	}
}

if ( ! function_exists( 'ideapark_metabox_media_fix' ) ) {
	function ideapark_metabox_media_fix( $response ) {
		if ( ! empty( $response['compat'] ) ) {
			$response['compat'] = [
				'item' => '',
				'meta' => '',
			];
		}

		return $response;
	}
}

if ( ! function_exists( 'ideapark_disable_native_wc_brands' ) ) {
	function ideapark_disable_native_wc_brands() {
		$value = get_theme_mod( 'disable_wc_native_brands', true ) ? 'no' : 'yes';
		if ( get_option( 'wc_feature_woocommerce_brands_enabled', '' ) !== $value ) {
			update_option( 'wc_feature_woocommerce_brands_enabled', $value );
			update_option( IDEAPARK_SLUG . '_flush_rewrite_rules', 'yes' );
		}
	}

	add_action( 'after_switch_theme', 'ideapark_disable_native_wc_brands', 2 );
	add_action( 'after_update_theme_late', 'ideapark_disable_native_wc_brands', 2 );
}

if ( ! function_exists( 'ideapark_font_icon_options' ) ) {
	function ideapark_font_icon_options() {
		static $icons;

		if ( $icons !== null ) {
			return $icons;
		}

		$fonts_info = get_option( 'ideapark_fonts_info' );
		$icons      = [];

		if ( ! empty( $fonts_info['fonts'] ) ) {
			foreach ( $fonts_info['fonts'] as $_font_name => $_font ) {
				foreach ( $_font['unicodes'] as $class_name => $code ) {
					$icons[ $class_name ] = preg_replace( '~^fi-[^\-]{4}-~', '', $class_name );
				}
			}
		}

		return $icons;
	}
}


if ( ! function_exists( 'ideapark_add_admin_notice' ) ) {
	/**
	 * Add a one-time admin notice that will be displayed on the next admin page load.
	 *
	 * @param string $message The notice message.
	 * @param string $type    The notice type: 'success', 'warning', 'error', 'info'. Default 'info'.
	 */
	function ideapark_add_admin_notice( $message, $type = 'info' ) {
		$notices   = get_transient( 'ideapark_admin_notices' );
		$notices   = is_array( $notices ) ? $notices : [];
		$notices[] = [
			'message' => $message,
			'type'    => in_array( $type, [ 'success', 'warning', 'error', 'info' ], true ) ? $type : 'info',
		];
		set_transient( 'ideapark_admin_notices', $notices, HOUR_IN_SECONDS );
	}
}

if ( ! function_exists( 'ideapark_display_admin_notices' ) ) {
	function ideapark_display_admin_notices() {
		$notices = get_transient( 'ideapark_admin_notices' );
		if ( ! empty( $notices ) && is_array( $notices ) ) {
			delete_transient( 'ideapark_admin_notices' );
			foreach ( $notices as $notice ) {
				$type    = ! empty( $notice['type'] ) ? $notice['type'] : 'info';
				$message = ! empty( $notice['message'] ) ? $notice['message'] : '';
				if ( $message ) {
					printf(
						'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
						esc_attr( $type ),
						wp_kses_post( $message )
					);
				}
			}
		}
	}

	add_action( 'admin_notices', 'ideapark_display_admin_notices' );
}

if ( ! function_exists( 'ideapark' . '_html_block' ) ) {
	function ideapark_html_block( $block_id ) {
		if ( $block_id && ( $page_id = apply_filters( 'wpml_object_id', $block_id, 'any' ) ) && 'publish' == ideapark_post_status( $page_id ) ) {
			if ( ideapark_is_elementor_page( $page_id ) ) {
				$page_content = Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $page_id );
				do_action(
					'elementor/post/render',
					$page_id
				);

			} elseif ( $post = get_post( $page_id ) ) {
				$page_content = apply_filters( 'the_content', $post->post_content );
				$page_content = str_replace( ']]>', ']]&gt;', $page_content );
				$page_content = ideapark_wrap( $page_content, '<div class="entry-content">', '</div>' );
				wp_reset_postdata();
			} else {
				$page_content = '';
			}

			return $page_content;
		} else {
			return '';
		}
	}
}

if ( ! function_exists( 'ideapark' . '_get_menu_html_block_values' ) ) {
	function ideapark_get_menu_html_block_values() {
		global $wpdb;

		if ( $html_block_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm1.meta_value
				FROM {$wpdb->postmeta} pm1
				INNER JOIN {$wpdb->postmeta} pm2
					ON pm1.post_id = pm2.post_id
					AND pm2.meta_key = %s
					AND pm2.meta_value = %s
				WHERE pm1.meta_key = %s
				AND pm1.meta_value != ''",
				'_menu_item_content',
				'html_block',
				'_menu_item_html_block'
			)
		) ) {
			foreach( $html_block_ids as $html_block_id) {
				do_action(
					'elementor/post/render',
					$html_block_id
				);
			}
		}
	}
}

/*------------------------------------*\
	Actions + Filters
\*------------------------------------*/

if ( IDEAPARK_IS_AJAX_SEARCH ) {
	add_filter( 'posts_clauses', 'ideapark_product_search_sku', 100, 2 );
	add_filter( 'posts_clauses', 'ideapark_search_product_tag', 100, 2 );
	add_action( 'wp_ajax_ideapark_ajax_search', 'ideapark_ajax_search' );
	add_action( 'wp_ajax_nopriv_ideapark_ajax_search', 'ideapark_ajax_search' );
	add_filter( 'wcml_multi_currency_ajax_actions', function ( $actions ) {
		$actions[] = 'ideapark_ajax_search';

		return $actions;
	} );
} elseif ( IDEAPARK_IS_AJAX_CSS ) {
	add_action( 'wp_ajax_ideapark_ajax_custom_css', 'ideapark_ajax_custom_css' );
} elseif ( ! IDEAPARK_IS_AJAX_HEARTBEAT ) {

	add_action( 'wp', 'ideapark_check_front_page' );
	add_action( 'wp_head', 'ideapark_header_metadata', 1 );
	add_action( 'wp_head', 'ideapark_pingback_header' );
	add_action( 'wp_head', 'ideapark_advert_bar_render', 1 );
	add_action( 'wp_head', 'ideapark_footer_render', 1 );
	add_action( 'wp_head', 'ideapark_category_html_blocks_render', 1 );
	add_action( 'wp_head', 'ideapark_get_menu_html_block_values', 1 );
	add_action( 'wp_body_open', 'ideapark_wrap_open', 1 );
	add_action( 'wp_footer', 'ideapark_wp_footer' );
	add_action( 'wp_footer', 'ideapark_wrap_close', 999 );
	add_action( 'widgets_init', 'ideapark_widgets_init' );
	add_action( 'wp_loaded', 'ideapark_disable_block_editor', 20 );
	add_action( 'admin_enqueue_scripts', 'ideapark_admin_scripts' );
	add_action( 'customize_controls_enqueue_scripts', 'ideapark_admin_scripts' );
	add_action( 'wp_enqueue_scripts', 'ideapark_scripts', 99 );
	add_action( 'wp_enqueue_scripts', 'ideapark_scripts_load', 999 );
	add_action( 'current_screen', 'ideapark_editor_style' );
	add_action( 'elementor/editor/after_enqueue_styles', 'ideapark_elementor_add_css_editor' );
	add_action( 'ideapark_delete_transient', function () {
		global $wpdb;
		$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '\_transient\_%' OR option_name LIKE '\_site\_transient\_%'" );
	} );

	add_filter( 'big_image_size_threshold', function () {
		return 3360;
	} );
	add_filter( 'body_class', 'ideapark_body_class', 100 );
	add_filter( 'theme_mod_background_image', 'ideapark_disable_background_image', 10, 1 );
	add_filter( 'get_search_form', 'ideapark_search_form', 10 );
	add_filter( 'excerpt_more', 'ideapark_excerpt_more' );
	add_filter( 'excerpt_length', 'ideapark_custom_excerpt_length', PHP_INT_MAX );
	add_filter( 'nav_menu_link_attributes', 'ideapark_menu_link_hash_fix' );
	add_filter( 'wp_kses_allowed_html', 'ideapark_add_allowed_tags', 100, 2 );
	add_filter( 'rwmb_meta_boxes', 'ideapark_custom_meta_boxes', 100 );
	add_filter( 'ideapark_fonts_theme_font', 'ideapark_theme_font' );
	add_filter( 'comment_form_fields', 'ideapark_comment_form_fields', 999 );
	add_filter( 'get_the_generator_html', 'ideapark_generator_tag', 10, 2 );
	add_filter( 'get_the_generator_xhtml', 'ideapark_generator_tag', 10, 2 );
	add_filter( 'posts_clauses', 'ideapark_product_search_sku', 100, 2 );
	add_filter( 'posts_clauses', 'ideapark_search_product_tag', 100, 2 );
	add_filter( 'ideapark_font_icons_smoothing', 'ideapark_font_icons_smoothing' );
	add_filter( 'should_load_separate_core_block_assets', '__return_true' );
	add_filter( 'wpcf7_autop_or_not', '__return_false' );
	add_filter( 'lazyload_is_enabled', '__return_false' ); // disable JetPack images Lazy load
	add_filter( 'do_rocket_lazyload', '__return_false' ); // disable WPRocket images Lazy load
	add_filter( 'pre_option_elementor_element_cache_ttl', function () {
		return 'disable';
	} );
	add_filter( 'wpseo_breadcrumb_separator', function () {
		return '<i class="ip-breadcrumb c-breadcrumbs__separator"><!-- --></i>';
	} );
	add_filter( 'pre_option_elementor_use_mini_cart_template', function () {
		return 'no';
	} );
	add_filter( 'pre_option_siteground_optimizer_lazyload_images', '__return_empty_string' );

	if ( ! defined( 'LITESPEED_CONF' ) ) {
		define( 'LITESPEED_CONF', true );
		define( 'LITESPEED_CONF__MEDIA_LAZY', false );
	}

	add_filter( 'elementor/fonts/groups', function ( $font_groups ) {
		if ( get_theme_mod( 'custom_fonts' ) ) {
			$font_groups = array_merge( [ 'ip-custom-fonts' => __( 'Custom', 'moderno' ) ], $font_groups );
		}

		return $font_groups;
	} );
	add_filter( 'elementor/fonts/additional_fonts', function ( $fonts ) {
		if ( $custom_fonts = get_theme_mod( 'custom_fonts' ) ) {
			foreach ( $custom_fonts as $custom_font ) {
				if ( ! empty( $custom_font['name'] ) ) {
					$fonts[ $custom_font['name'] ] = 'ip-custom-fonts';
				}
			}
		}

		return $fonts;
	} );

	add_filter( 'get_archives_link', function ( $html ) {
		return preg_replace( '~&nbsp;\((\d+)\)</li>~', '&nbsp;<span class="count">\\1</span></li>', $html );
	} );

	add_filter( 'wp_list_categories', function ( $html ) {
		return preg_replace( '~ \((\d+)\)\n~m', "&nbsp;<span class='count'>\\1</span>\n", str_replace( '<span class="count">(', '<span class="count">', str_replace( ')</span>', '</span>', $html ) ) );
	} );

	add_filter( 'rank_math/frontend/breadcrumb/html', function ( $html ) {
		return preg_replace( '~<span class="separator">[^<]*</span>~i', '<i class="ip-breadcrumb c-breadcrumbs__separator"><!-- --></i>', $html );
	} );

	add_filter( 'woocommerce_create_pages', function ( $data ) {
		$data['cart']['content']     = '<!-- wp:woocommerce/classic-shortcode {"shortcode":"cart"} /-->';
		$data['checkout']['content'] = '<!-- wp:woocommerce/classic-shortcode {"shortcode":"checkout"} /-->';

		return $data;
	} );

	add_filter( 'hidden_meta_boxes', function ( $hidden, $screen, $use_defaults ) {
		if ( $screen->id == 'nav-menus' ) {
			$hidden = array_diff( $hidden, [ 'add-product_cat' ] );
		}

		return $hidden;
	}, 99, 3 );

	if ( is_admin() ) {
		add_action( 'rwmb_before', function () {
			add_filter( 'wp_prepare_attachment_for_js', 'ideapark_metabox_media_fix' );
		} );
		add_action( 'rwmb_after', function () {
			remove_filter( 'wp_prepare_attachment_for_js', 'ideapark_metabox_media_fix' );
		} );
	}
	add_filter( 'elementor/frontend/builder_content/before_print_css', function ( $with_css ) {
		return is_customize_preview() ? false : $with_css;
	} );
}

add_action( 'after_setup_theme', 'ideapark_init_filesystem', 0 );
add_action( 'after_setup_theme', 'ideapark_check_version', 1 );
add_action( 'after_setup_theme', 'ideapark_setup' );
ideapark_ra( 'init', [ 'WC_Regenerate_Images', 'init' ] );
add_filter( 'woocommerce_resize_images', '__return_false' );
