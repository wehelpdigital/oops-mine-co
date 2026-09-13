<?php

defined( 'ABSPATH' ) or die();

class Ideapark_Importer {

	private $file;
	private $dir;
	private $importer;
	private $importer_dir;
	private $importer_url;
	private $_version;
	private static $_instance = null;
	private $export_path = '';
	private $demo_content_folder = 'data';
	private $slides = [];
	private $options_to_export_page_id = [
		'woocommerce_shop_page_id',
		'woocommerce_cart_page_id',
		'woocommerce_checkout_page_id',
		'woocommerce_myaccount_page_id',
		'woocommerce_terms_page_id',
		'page_for_posts',
		'page_on_front'
	];

	private $options_to_export = [
		'permalink_structure',
		'show_on_front',
		'posts_per_page',
		'permalink_structure',
		'woocommerce_catalog_columns',
		'woocommerce_catalog_rows',
		'woocommerce_shop_page_display',
		'woocommerce_category_archive_display',
		'woocommerce_enable_myaccount_registration',
		'woocommerce_placeholder_image',
		'woocommerce_currency',
		'woocommerce_currency_pos',
		'woocommerce_price_thousand_sep',
		'woocommerce_price_decimal_sep',
		'woocommerce_price_num_decimals',
		'site_icon',
		'ideapark_added_blocks',
		'elementor_viewport_lg',
		'elementor_viewport_md',
		'elementor_scheme_color',
		'elementor_scheme_color-picker',
		'elementor_scheme_typography',
		'elementor_cpt_support',
		'elementor_controls_usage',
		'elementor_disable_color_schemes',
		'elementor_disable_typography_schemes',
		'elementor_space_between_widgets',
		'elementor_container_width',
		'elementor_experiment-container',
		'_elementor_general_settings',
		'_elementor_global_css',
		'woo_variation_swatches',
	];

	private $mods_for_clear = [
		'google_maps_api_key'
	];

	private $session_fn;
	private $session_fn_start;
	private $is_file_transient;
	private $step = '';

	function __construct( $file, $version = '1.0.0' ) {

		$this->_version     = $version;
		$this->file         = $file;
		$this->dir          = dirname( $this->file );
		$this->importer_dir = trailingslashit( $this->dir ) . 'importer';
		$this->importer_url = rtrim( plugins_url( '/importer/', $this->file ), '/' );

		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
		add_action( 'wp_ajax_ideapark_importer', [ $this, 'importer' ] );
		add_action( 'wp_ajax_ideapark_exporter', [ $this, 'exporter' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'scripts' ] );

		if ( is_admin() || defined( 'WP_LOAD_IMPORTERS' ) ) {
			add_filter( 'wp_import_post_meta', [ $this, 'on_wp_import_post_meta' ] );
			add_filter( 'wxr_importer.pre_process.post_meta', [ $this, 'on_wxr_importer_pre_process_post_meta' ] );
		}
	}

	public static function instance( $file, $version = '1.0.0' ) {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self( $file, $version );
		}

		return self::$_instance;
	}

	public function init() {
		$this->session_fn        = IDEAPARK_UPLOAD_DIR . 'import.dat';
		$this->session_fn_start  = IDEAPARK_UPLOAD_DIR . 'import_start.dat';
		$this->is_file_transient = ideapark_is_file( $this->session_fn );
		$this->importer          = [];

		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
			define( 'WP_LOAD_IMPORTERS', true );
		}

		if ( ! class_exists( 'WP_Importer' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-importer.php';
		}

		require_once $this->importer_dir . '/parsers.php';
		require_once $this->importer_dir . '/wordpress-importer.php';
		require_once $this->importer_dir . '/wordpress-importer-extend.php';
	}

	function admin_menu() {
		add_theme_page( __( 'Import Demo Content', 'ideapark-moderno' ), __( 'Import Demo Content', 'ideapark-moderno' ), 'manage_options', 'ideapark_themes_importer_page', [
			$this,
			'importer_page'
		] );
	}

	public function scripts( $hook ) {
		if ( 'appearance_page_ideapark_themes_importer_page' != $hook ) {
			return;
		}

		wp_enqueue_style( 'ideapark-importer', $this->importer_url . '/importer.css', [], $this->_version, 'all' );
		wp_enqueue_script( 'ideapark-importer', $this->importer_url . '/importer.js', [ 'jquery' ], $this->_version );
		wp_localize_script( 'ideapark-importer', 'ideapark_wp_vars_importer', [
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'please_wait'  => __( 'Please wait...', 'ideapark-moderno' ),
			'are_you_sure' => __( 'Are you sure you want to import?', 'ideapark-moderno' ),
			'importing'    => __( 'Importing ...', 'ideapark-moderno' ),
			'progress'     => __( 'Progress', 'ideapark-moderno' ),
			'output_error' => __( 'Output Error', 'ideapark-moderno' ),
		] );
	}

	public function _sort_importer_page( $a, $b ) {
		if ( $a == $b ) {
			return 0;
		}

		return ( $a < $b ) ? - 1 : 1;
	}

	function importer_page() {

		/* @var WP_Filesystem_Base $wp_filesystem */

		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->get_error_code() == 'empty_hostname' ) {
			$output = '';
			$output .= '<div id="ip-import" class="wrap">';
			$output .= '<h1>' . IDEAPARK_NAME . ' - ' . __( 'One-Click Import Demo Content', 'ideapark-moderno' ) . '</h1>';
			$output .= '<div class="ip-import-block">';
			$output .= 'Error: No access to data folder. Try to add line &quot;<b>define(\'FS_METHOD\',\'direct\');</b>&quot; to the file <b>wp-config.php</b>';
			$output .= '</div>';

			echo ideapark_wrap( $output );

			return;
		}

		$folders = $wp_filesystem->dirlist( $this->importer_dir . '/' );

		$locale       = get_locale();
		$themes       = [];
		$is_revslider = false;
		$is_fonts     = false;
		foreach ( $folders as $name => $folder ) {
			if ( $folder['type'] == 'd' && ( $theme_title_fn = $this->importer_dir . '/' . $name . '/theme.txt' ) && ideapark_is_file( $theme_title_fn ) ) {

				if ( preg_match( '~^\w{2}(_\w{2})?$~', $name ) && $name != $locale ) {
					continue;
				}

				$themes[ $name ] = [
					'title' => ideapark_fgc( $theme_title_fn ),
					'fonts' => false,
				];

				if ( ideapark_is_file( $fn = $this->importer_dir . '/' . $name . '/theme_url.txt' ) ) {
					$themes[ $name ]['url'] = ideapark_fgc( $fn );
				}

				if ( ideapark_is_file( $fn = $this->importer_dir . '/' . $name . '/theme.jpg' ) ) {
					$themes[ $name ]['screenshot'] = $this->importer_url . '/' . $name . '/theme.jpg?v=' . filemtime( $fn );
				} elseif ( ideapark_is_file( $fn = $this->importer_dir . '/' . $name . '/theme.png' ) ) {
					$themes[ $name ]['screenshot'] = $this->importer_url . '/' . $name . '/theme.png?v=' . filemtime( $fn );
				}

				if ( class_exists( 'Ideapark_Fonts' ) && ideapark_is_dir( $fd = $this->importer_dir . '/' . $name . '/fonts/' ) ) {
					$themes[ $name ]['fonts'] = true;
					$is_fonts                 = true;
				}

				$files = $wp_filesystem->dirlist( $this->importer_dir . '/' . $name . '/' );
				foreach ( $files as $file_name => $file ) {
					if ( $file['type'] == 'f' && preg_match( '~(home-\d)\.php~', $file_name, $match ) ) {
						global $theme_home;
						include( $this->importer_dir . '/' . $name . '/' . $file_name );
						$new_theme          = $themes[ $name ];
						$new_theme['title'] = $theme_home['title'];
						$new_theme['url']   = $theme_home['url'];
						if ( ideapark_is_file( $fn = $this->importer_dir . '/' . $name . '/' . $theme_home['screenshot'] ) ) {
							$new_theme['screenshot'] = $this->importer_url . '/' . $name . '/' . $theme_home['screenshot'] . '?v=' . filemtime( $fn );
						}
						$themes[ $name . '~' . $match[1] ] = $new_theme;
					}
				}
			}
		}

		$output = '';
		$output .= '<div id="ip-import" class="wrap">';
		$output .= '<h1>' . IDEAPARK_NAME . ' - ' . __( 'One-Click Import Demo Content', 'ideapark-moderno' ) . '</h1>';
		$output .= '<div class="ip-import-block">';

		if ( ! empty( $themes ) ) {
			$output   .= '<p><span class="subheader">' . __( 'Select the demo site you want to import: ', 'ideapark-moderno' ) . '</span></p>';
			$output   .= '<ul class="ip-demos">';
			$is_first = true;
			uksort( $themes, [ $this, '_sort_importer_page' ] );
			foreach ( $themes as $name => $theme ) {
				$preview_button = '';
				if ( ! empty( $theme['url'] ) ) {
					$preview_button = '<a class="ip-demo-preview" href="' . esc_url( $theme['url'] ) . '" target="_blank">' . __( 'Preview Demo', 'ideapark-moderno' ) . '</a>';
				}
				$data = 'data-revslider="' . ( ! empty( $theme['revslider'] ) ? 'yes' : 'no' ) . '" data-fonts="' . ( ! empty( $theme['fonts'] ) ? 'yes' : 'no' ) . '"';

				if ( ! empty( $theme['screenshot'] ) ) {
					$output .= '<li class="ip-demo" ' . $data . '><label><img class="ip-screenshot" alt="' . esc_attr( $theme['title'] ) . '" src="' . esc_url( $theme['screenshot'] ) . '" /><input class="ip-import-demo" type="radio" name="import_demo" value="' . esc_attr( $name ) . '" ' . ( $is_first ? 'checked' : '' ) . '/>' . esc_attr( $theme['title'] ) . '</label>' . $preview_button . '</li>';
				} else {
					$output .= '<li class="ip-demo" ' . $data . '><label><span class="ip-no-image"></span><input class="ip-import-demo" type="radio" name="import_demo" value="' . esc_attr( $name ) . '`" ' . ( $is_first ? 'checked' : '' ) . '/> ' . esc_attr( $theme['title'] ) . '</label>' . $preview_button . '</li>';
				}

				$is_first = false;
			}
			$output .= '</ul>';
		}


		if ( ! empty( $themes ) || IDEAPARK_DEMO ) {
			$output .= '<p' . ( ( ! $is_fonts && ! $is_revslider ) ? ' class="ip-invisible"' : '' ) . '><span class="subheader">' . __( 'Select the data you want to import: ', 'ideapark-moderno' ) . '</span>' .
			           '<br />
			<label><input type="radio" name="import_option" value="all" checked/>' . __( 'All data', 'ideapark-moderno' ) . '</label><br />
			';
			if ( $is_fonts ) {
				$output .= '<label class="ip-fonts-radio"><input type="radio" name="import_option" value="fonts"/>' . __( 'Fonts', 'ideapark-moderno' ) . '</label><br />';
			}
			if ( $is_revslider ) {
				$output .= '<label class="ip-rev-slider-radio"><input type="radio" name="import_option" value="revslider"/>' . __( 'Slider Revolution', 'ideapark-moderno' ) . '</label><br />';
			}
			$output .= '</p>';
			if ( PHP_OS == "Darwin" && IDEAPARK_DEMO ) {
				$output .= '<label><input type="radio" name="import_option" value="options"/>' . __( 'Options', 'ideapark-moderno' ) . '</label><br />';
			}

			$output .= '<p><label><input type="checkbox" value="1" name="import_attachments" checked /> ' . __( 'Import attachments', 'ideapark-moderno' ) . '</label></p>';
			if ( ( $code = ideapark_get_purchase_code() ) && $code !== IDEAPARK_SKIP_REGISTER ) {
				$this->init();
				$this->load_importer_data();

				$output .= '<p class="submit">' . ( PHP_OS == "Darwin" || ! IDEAPARK_DEMO ? '<button class="button button-primary" ' . ( ! empty( $this->importer['steps'] ) ? ' style="display:none" ' : '' ) . ' id="ip-import-submit">' . __( 'Import', 'ideapark-moderno' ) . '</button> ' : '' ) . '</p>';
				$output .= '<div class="ip-loading-progress' . ( ! empty( $this->importer['steps'] ) ? ' paused' : '' ) . '"><div class="ip-loading-bar"><div class="ip-loading-state"></div><div class="ip-loading-info">' . __( 'Progress', 'ideapark-moderno' ) . ': 0%...</div></div><div class="ip-import-output">' . __( 'Prepare data...', 'ideapark-moderno' ) . '</div></div>';
				if ( ! empty( $this->importer['steps'] ) ) {
					$percent = round( ( $this->importer['base']->step_done / $this->importer['base']->step_total ) * 100 );
					$output  .= '<div class="ip-import-continue">' . ( PHP_OS == "Darwin" || ! IDEAPARK_DEMO ? '<button type="button" class="button button-primary" id="ip-import-continue" data-percent="' . esc_attr( $percent ) . '">' . __( 'Continue', 'ideapark-moderno' ) . '</button>&nbsp;&nbsp;&nbsp;<button type="button" class="button button-default" id="ip-import-cancel" data-percent="' . esc_attr( $percent ) . '">' . __( 'Cancel', 'ideapark-moderno' ) . '</button> ' : '' ) . '</div>';
				}
				$output .= '<div class="ip-import-notes">';
				$output .= __( 'Important notes:', 'ideapark-moderno' ) . '<br />';
				$output .= __( 'Please note that import process will take time needed to download all attachments from demo web site.', 'ideapark-moderno' ) . '<br />';
				$output .= __( 'If you plan to use shop, please install WooCommerce before you run import.', 'ideapark-moderno' ) . '<br />';
				$output .= sprintf( wp_kses( __( 'We recommend you to <a href="%s" target="_blank">reset data</a> & clean wp-content/uploads folder before import to prevent duplicate content.', 'ideapark-moderno' ), [ 'a' => [ 'href' => [] ] ] ), esc_url( 'https://wordpress.org/plugins/wp-reset/' ) ) . '<br />';
				if ( ! ini_get( 'allow_url_fopen' ) ) {
					$output .= '<div class="error">' . esc_html__( 'Downloading remote files is prohibited in PHP (allow_url_fopen in php.ini)', 'ideapark-moderno' ) . '</div>';
				} elseif ( ! isset( $_GET['download_test'] ) ) {
					$output .= '<p>' . esc_html__( 'If you have problems importing images, then run a test to get information about the error', 'ideapark-moderno' ) . ': <a href="' . admin_url( 'themes.php?page=ideapark_themes_importer_page&download_test' ) . '">' . esc_html__( 'download test', 'ideapark-moderno' ) . '</a></p>';
				} else {
					if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
						define( 'WP_LOAD_IMPORTERS', true );
					}
					if ( ! class_exists( 'WP_Importer' ) ) {
						require_once ABSPATH . 'wp-admin/includes/class-wp-importer.php';
					}
					require_once $this->importer_dir . '/parsers.php';
					require_once $this->importer_dir . '/wordpress-importer.php';
					require_once $this->importer_dir . '/wordpress-importer-extend.php';
					$importer = new WP_Importer_Extend();
					$_post    = [
						'upload_date' => date( 'Y-m-d H:i:s' ),
						'guid'        => '1234567890'
					];
					$_url     = 'https://parkofideas.com/_logo.jpg';
					$result   = $importer->fetch_remote_file( $_url, $_post );
					if ( is_wp_error( $result ) ) {
						$output .= '<div class="error">' . esc_html__( 'Download test failed', 'ideapark-moderno' ) . ': ' . $result->get_error_message() . '</div>';
					} else {
						$output .= '<p class="warning">' . esc_html__( 'Download test completed successfully', 'ideapark-moderno' ) . '</p>';
						ideapark_delete_file( $result['file'] );
					}
				}
				$output .= '</div>';
			} else {
				$output .= '<p class="ideapark-moderno-warning">' . esc_html__( 'Available only to registered theme', 'ideapark-moderno' ) . '</p>';
				$output .= '<p><a class="button button-primary" href="' . admin_url( 'themes.php?page=ideapark_about' ) . '">' . __( 'Register theme', 'ideapark-moderno' ) . '</a></p>';
				$output .= '<div class="ip-import-output"></div>';
			}
			if ( defined( 'IDEAPARK_DEMO' ) && IDEAPARK_DEMO ) {
				$output .= '<p><a href="" style="color: #F0F0F1;" onclick="return false;" id="ip-export-submit">' . __( 'Export', 'ideapark-moderno' ) . '</a></p>';
			}
		} else {
			$output .= '<div>' . __( 'No themes data found.', 'ideapark-moderno' ) . '</div>';
			$output .= '<div>' . __( 'Try adding this code to the beginning of the file wp-config.php:', 'ideapark-moderno' ) . '</div>';
			$output .= "<p><code>define( 'FS_METHOD', 'direct' );</code></p>";
		}

		$output .= '</div>';

		echo ideapark_wrap( $output );
	}

	public function load_importer_data() {
		if ( ideapark_is_file( $this->session_fn ) ) {
			$this->is_file_transient = true;
			if ( $serialised_data = ideapark_fgc( $this->session_fn ) ) {
				$this->importer = unserialize( $serialised_data );
				unset( $serialised_data );
			} else {
				$this->importer = [];
			}
		} else {
			$this->importer = ( ( $session = get_transient( 'ideapark_demo_import' ) ) && is_string( $session ) ) ? unserialize( $session ) : [];
		}
		if ( isset( $this->importer['base'] ) && is_object( $this->importer['base'] ) ) {
			$this->importer['base']->message = '';
			$this->demo_content_folder       = $this->importer['import_demo_content_folder'];
		}
	}

	function importer() {
		global $wp_filesystem, $wpdb;

		ini_set( 'max_execution_time', 300 );

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->import_response( 'error', __( 'Error: Permission denied', 'ideapark-moderno' ) );
		}

		$this->init();

		$code    = 'continue';
		$message = '';
		$among   = 3;

		if ( isset( $_REQUEST['stage'] ) && $_REQUEST['stage'] == 'cancel' ) {
			delete_transient( 'ideapark_demo_import' );
			if ( $this->is_file_transient ) {
				ideapark_delete_file( $this->session_fn );
			}
			ideapark_delete_file( $this->session_fn_start );
			$this->import_response( 'completed', '', 0 );


		} elseif ( isset( $_REQUEST['stage'] ) && $_REQUEST['stage'] == 'start' ) {
			if ( ! empty( $_REQUEST['import_option'] ) && ! empty( $_REQUEST['import_demo'] ) ) {

				$folder = trim( $_REQUEST['import_demo'] );
				$e      = explode( '~', $folder );
				if ( sizeof( $e ) == 2 ) {
					$folder                 = $e[0];
					$this->importer['home'] = $e[1];
				}
				$this->demo_content_folder = $folder;

				if ( ! ideapark_is_dir( $theme_title_fn = $this->importer_dir . '/' . $this->demo_content_folder . '/' ) ) {
					$this->import_response( 'error', __( 'Error: Can`t open file: ', 'ideapark-moderno' ) . $theme_title_fn );
				}

				switch ( $_REQUEST['import_option'] ) {

					case 'all':
						$this->importer['steps'] = [
							'prepare',
							'terms',
							'post',
							'options',
							'widget',
							'fonts',
							'revslider',
							'finish',
							'completed'
						];
						break;

					case 'content':
						$this->importer['steps'] = [
							'prepare',
							'terms',
							'post',
							'finish',
							'completed'
						];
						break;

					case 'options':
						$this->importer['steps'] = [
							'options',
							'completed'
						];
						break;

					case 'widgets':
						$this->importer['steps'] = [
							'widget',
							'completed'
						];
						break;

					case 'fonts':
						$this->importer['steps'] = [
							'fonts',
							'completed'
						];
						break;

					case 'revslider':
						$this->importer['steps'] = [
							'revslider',
							'completed'
						];
						break;

					default:
						$this->import_response( 'error', __( 'Error: Select the data you want to import', 'ideapark-moderno' ) );
				}

			} else {
				$this->import_response( 'error', __( 'Error: Select the data you want to import', 'ideapark-moderno' ) );
			}


			$this->importer['import_attachments']         = ! empty( $_REQUEST['import_attachments'] );
			$this->importer['import_demo_content_folder'] = $this->demo_content_folder;
			$this->importer['base']                       = new Ideapark_Importer_Base();
			$this->importer['base']->step_total           = sizeof( $this->importer['steps'] ) * $among;

			$sub_folders = $wp_filesystem->dirlist( IDEAPARK_UPLOAD_DIR );
			if ( $sub_folders ) {
				foreach ( $sub_folders as $sub_name => $sub_item ) {
					if ( $sub_item['type'] == 'f' ) {
						ideapark_delete_file( IDEAPARK_UPLOAD_DIR . $sub_name );
					}
				}
			}

		} else {
			$this->load_importer_data();
		}

		if ( empty( $this->importer['steps'][0] ) ) {
			if ( ideapark_is_file( $this->session_fn_start ) ) {
				$this->is_file_transient = true;
				if ( $serialised_data = ideapark_fgc( $this->session_fn_start ) ) {
					$this->importer                  = unserialize( $serialised_data );
					$this->importer['base']->message = '';
					$this->demo_content_folder       = $this->importer['import_demo_content_folder'];
					unset( $serialised_data );
				} else {
					$this->importer = [];
				}
				ideapark_delete_file( $this->session_fn_start );
			}

			if ( empty( $this->importer['steps'][0] ) ) {
				$this->import_response( 'error', __( 'Error: Select the data you want to import', 'ideapark-moderno' ) . ( $this->is_file_transient ? ' (file)' : ' (db)' ) );
			}
		}

		if ( empty( $this->importer['import_demo_content_folder'] ) ) {
			$this->import_response( 'error', __( 'Error: Selected data is empty', 'ideapark-moderno' ) . ( $this->is_file_transient ? ' (file)' : ' (db)' ) );
		}

		$step = $this->step = $this->importer['steps'][0];

		ob_start();

		switch ( $step ) {

			case 'prepare':
				$_GET['force_delete_kit'] = true;
				do_action( 'ideapark_before_import_prepare' );
				if ( function_exists( 'ideapark_woocommerce_set_image_dimensions' ) ) {
					ideapark_woocommerce_set_image_dimensions();
				}

				$this->import_options( true );

				if ( function_exists( 'wc_get_page_id' ) ) {
					$ids   = [];
					$ids[] = wc_get_page_id( 'cart' );
					$ids[] = wc_get_page_id( 'checkout' );
					$ids[] = wc_get_page_id( 'myaccount' );
					$ids[] = wc_get_page_id( 'terms' );
					$ids[] = wc_get_page_id( 'shop' );
					$ids   = array_filter( $ids, function ( $val ) {
						return $val > 0;
					} );

					foreach ( $ids as $id ) {
						wp_delete_post( $id, true );
					}
				}
				foreach ( [ 'hello-world', 'sample-page', 'privacy-policy', 'shop', 'refund_returns' ] as $slug ) {
					if ( $defaultPost = get_posts( [
						'name'           => $slug,
						'posts_per_page' => 1,
						'post_type'      => [ 'post', 'page' ],
						'post_status'    => 'any'
					] ) ) {
						wp_delete_post( $defaultPost[0]->ID, true );
					}
				}

				/**
				 * @pvar Ideapark_Importer_Base $this->importer['base']
				 */

				$this->importer['base']                    = new WP_Importer_Extend();
				$this->importer['base']->fetch_attachments = $this->importer['import_attachments'];
				$this->importer['base']->step_total        += sizeof( $this->importer['steps'] ) * $among;
				$this->importer['base']->placeholder_path  = $this->importer_url . '/img/placeholder.svg';

				$theme_xml = $this->demo_content_folder . '/content.xml.zip';

				$result = $this->importer['base']->import_start( $theme_xml );

				if ( is_wp_error( $result ) ) {
					$this->import_response( 'error', $result->get_error_message() );
				}

				array_shift( $this->importer['steps'] );
				$this->importer['base']->step_done = $among;

				$message = __( 'Prepared data successfully', 'ideapark-moderno' );

				do_action( 'ideapark_after_import_prepare' );
				break;

			case 'terms':
				do_action( 'ideapark_before_import_terms' );
				$this->importer['base']->import_terms();
				array_shift( $this->importer['steps'] );
				$this->importer['base']->step_done += $among;
				$message                           = __( 'Imported terms successfully', 'ideapark-moderno' );
				do_action( 'ideapark_after_import_terms' );
				break;

			case 'post':
				do_action( 'ideapark_before_import_post' );

				if ( ! $this->importer['base']->importing() ) {
					array_shift( $this->importer['steps'] );
					$message                           = __( 'Imported post data successfully', 'ideapark-moderno' );
					$this->importer['base']->step_done += $among;
				} else {
					$message = $this->importer['base']->message;
				}
				do_action( 'ideapark_after_import_post' );
				break;

			case 'options':
				do_action( 'ideapark_before_import_options' );
				$this->import_options( false, ! empty( $this->importer['home'] ) ? $this->importer['home'] : '' );
				array_shift( $this->importer['steps'] );
				$this->importer['base']->step_done += $among;
				$message                           = __( 'Imported options successfully', 'ideapark-moderno' );
				do_action( 'ideapark_after_import_options' );
				break;

			case 'fonts':
				do_action( 'ideapark_before_import_fonts' );
				$this->import_fonts();
				array_shift( $this->importer['steps'] );
				$this->importer['base']->step_done += $among;
				$message                           = __( 'Imported fonts successfully', 'ideapark-moderno' );
				do_action( 'ideapark_after_import_fonts' );
				break;

			case 'widget':
				do_action( 'ideapark_before_import_widget' );
				$this->import_widgets();
				array_shift( $this->importer['steps'] );
				$this->importer['base']->step_done += $among;
				$message                           = __( 'Imported widgets successfully', 'ideapark-moderno' );
				do_action( 'ideapark_after_import_widget' );
				break;

			case 'revslider':
				do_action( 'ideapark_before_import_revslider' );
				$message = $this->import_revslider();
				array_shift( $this->importer['steps'] );
				$this->importer['base']->step_done += $among;
				do_action( 'ideapark_after_import_revslider' );
				break;

			case 'finish':
				do_action( 'ideapark_before_import_finish' );
				array_shift( $this->importer['steps'] );
				$this->importer['base']->import_end();
				$this->importer['base']->step_done += $among;
				$this->import_finish();
				do_action( 'ideapark_after_import_finish' );
				break;

			case 'completed':
				do_action( 'ideapark_before_import_completed' );
				$this->importer['base']->step_done = $this->importer['base']->step_total;
				if ( ! count( $this->importer['base']->error_msg ) ) {
					$message = '<b style="color:#444">' . __( 'Cheers! The demo data has been imported successfully! Please reload this page to finish!', 'ideapark-moderno' ) . '</b>';
				} else {
					$permalinks_settings_url = get_admin_url( null, 'options-permalink.php' );
					$message                 = '<b style="color:#444">' . __( 'Data import completed!', 'ideapark-moderno' ) . '</b>' . '<p><a class="button" href="' . $permalinks_settings_url . '">' . __( 'Re-save the site permalinks! ', 'ideapark-moderno' ) . '</a></p>' . '<div>' . implode( '', $this->importer['base']->error_msg ) . '</div>';
				}
				$code = 'completed';
				do_action( 'ideapark_after_import_completed' );
				break;

			default:
				$this->import_response( 'error', __( 'Error: step not found: ', 'ideapark-moderno' ) . $step );
				break;
		}

		if ( $output = ob_get_clean() ) {
			$this->importer['base']->error_msg[] = wp_kses( $output, [ 'br' => [] ] );
		}

		if ( $step == 'completed' ) {
			delete_transient( 'ideapark_demo_import' );
			if ( $this->is_file_transient ) {
				ideapark_delete_file( $this->session_fn );
			}
			ideapark_delete_file( $this->session_fn_start );
		} else {
			if ( $step == 'prepare' ) {
				delete_transient( 'ideapark_demo_import' );
			}

			$serialised_data = serialize( $this->importer );

			if ( $this->is_file_transient ) {
				ideapark_fpc( $this->session_fn, $serialised_data );
			} else {
				try {
					ob_start();
					set_transient( 'ideapark_demo_import', $serialised_data, MINUTE_IN_SECONDS * 60 );
					ob_end_clean();
					if ( $step == 'prepare' ) {
						$_result = ideapark_fpc( $this->session_fn_start, $serialised_data );
						if ( ! $_result || ! ideapark_is_file( $this->session_fn_start ) ) {
							if ( get_filesystem_method() !== 'direct' ) {
								$this->import_response( 'error', __( 'Error: No access to data folder. Try to add line "define(\'FS_METHOD\',\'direct\');" to the file wp-config.php', 'ideapark-moderno' ) );
							} else {
								$this->import_response( 'error', __( 'Error: Uploads folder must be writable - ', 'ideapark-moderno' ) . IDEAPARK_UPLOAD_DIR );
							}
						}
					}
				} catch ( Exception $e ) {
					$_result = ideapark_fpc( $this->session_fn, $serialised_data );
					if ( ! $_result || ! ideapark_is_file( $this->session_fn ) ) {
						if ( get_filesystem_method() !== 'direct' ) {
							$this->import_response( 'error', __( 'Error: No access to data folder. Try to add line "define(\'FS_METHOD\',\'direct\');" to the file wp-config.php', 'ideapark-moderno' ) );
						} else {
							$this->import_response( 'error', __( 'Error: Uploads folder must be writable - ', 'ideapark-moderno' ) . IDEAPARK_UPLOAD_DIR );
						}
					}
				}
			}
			unset( $serialised_data );
		}

		// calculate processed percent
		$percent = round( ( $this->importer['base']->step_done / $this->importer['base']->step_total ) * 100 );

		/** response to client */
		$this->import_response( $code, $message, $percent );
	}

	function import_finish() {
		global $wp_taxonomies, $wpdb;

		$taxonomy_names = array_keys( $wp_taxonomies );

		foreach ( $taxonomy_names as $taxonomy_name ) {
			$sql = $wpdb->prepare( "
				SELECT term_taxonomy_id
				FROM $wpdb->term_taxonomy
				WHERE taxonomy = %s
			", $taxonomy_name );

			if ( ( $term_taxonomy_ids = $wpdb->get_col( $sql ) ) && ! is_wp_error( $term_taxonomy_ids ) ) {
				wp_update_term_count_now( $term_taxonomy_ids, $taxonomy_name );
			}
		}

		$this->prepere_post_meta( 1 );
		$this->prepare_term_meta( 1 );

		if ( empty( $this->importer['import_attachments'] ) ) {
			$wpdb->query( "DELETE FROM $wpdb->termmeta WHERE meta_key = 'thumbnail_id'" );
		}

		$wpdb->query( "DELETE FROM $wpdb->postmeta WHERE meta_key = '_ideapark_inline_svg'" );

		if ( ideapark_is_elementor() ) {
			$elementor_instance = Elementor\Plugin::instance();
			$elementor_instance->files_manager->clear_cache();
		}

		if ( function_exists( 'ideapark_clear_customize_cache' ) ) {
			ideapark_clear_customize_cache();
		}

		$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '\_transient\_%' OR option_name LIKE '\_site\_transient\_%'" );
		ideapark_regenerate_wc_lookup();

		if ( ideapark_is_file( $fn = $this->importer_dir . '/' . $this->demo_content_folder . '/theme_url.txt' ) ) {
			$theme_demo_url = ideapark_fgc( $fn );
			$this->update_urls( $from = trailingslashit( $theme_demo_url ), $to = trailingslashit( home_url( '/' ) ) );
		}

		if ( ideapark_is_elementor() ) {
			Elementor\Utils::replace_urls( $from, $to );
		}

		wp_cache_flush();

		if ( ideapark_is_file( $fn = IDEAPARK_UPLOAD_DIR . 'demo.dat' ) ) {
			ideapark_delete_file( $fn );
		}

		if ( ideapark_is_file( $fn = IDEAPARK_UPLOAD_DIR . 'content.xml' ) ) {
			ideapark_delete_file( $fn );
		}
	}

	function import_options( $is_preliminary = false, $home = '' ) {
		global $wpdb, $theme_home;

		if ( ! $is_preliminary ) {
			$theme_options_fn = $this->demo_content_folder . '/theme_options.txt';
			$theme_home_fn    = $home ? $this->importer_dir . '/' . $this->demo_content_folder . '/' . $home . '.php' : '';
			$theme_home       = [];
			if ( ideapark_is_file( $theme_home_fn ) ) {
				include( $theme_home_fn );
			}
			$f  = implode( '_', [ 'ideapark', 'api', 'theme', 'get', 'file' ] );
			$fn = $f( $theme_options_fn );

			if ( is_wp_error( $fn ) ) {
				$this->import_response( 'error', $fn->get_error_message() );
			} else {
				$theme_options_fn = $fn;
			}
			if ( ideapark_is_file( $theme_options_fn ) ) {
				$theme_options_txt = ideapark_fgc( $theme_options_fn );
//				ideapark_fpc( $theme_options_fn . '.txt', base64_decode( $theme_options_txt ) );
				$options                  = unserialize( base64_decode( $theme_options_txt ) );
				$ideapark_customize_types = $this->_get_customize_types();
				ideapark_reset_theme_mods();

				foreach ( $options as $mod_name => $val ) {
					if ( $mod_name === 'nav_menu_locations' ) {
						$menu_names = [];
						$menus      = wp_get_nav_menus();
						foreach ( $menus as $menu ) {
							$menu_names[ $menu->name ] = $menu->term_id;
						}
						if ( is_array( $val ) ) {
							foreach ( $val as $menu_slug => $menu_name ) {
								if ( array_key_exists( $menu_name, $menu_names ) ) {
									$val[ $menu_slug ] = $menu_names[ $menu_name ];
								}
							}
						}
					} elseif ( $mod_name == 'custom_fonts' && $val ) {
						foreach ( $val as &$font ) {
							foreach ( [ 'woff2', 'woff', 'ttf', 'otf', 'svg' ] as $_key ) {
								if ( ! empty( $font[ $_key ] ) ) {
									$font[ $_key ] = wp_get_attachment_url( $this->get_post_id_by_name( $font[ $_key ] ) );
								}
							}
						}
					} elseif ( array_key_exists( $mod_name, $ideapark_customize_types ) ) {
						if ( $ideapark_customize_types[ $mod_name ] == 'WP_Customize_Image_Control' && strpos( $val, '{{site_url}}' ) !== false ) {
							$val = str_replace( '{{site_url}}', home_url(), $val );
						} elseif ( $ideapark_customize_types[ $mod_name ] == 'WP_Customize_Category_Control' ) {
							$term = get_term_by( 'name', $val, 'category' );
							$val  = isset( $term->term_id ) ? $term->term_id : 0;
						} elseif ( $ideapark_customize_types[ $mod_name ] == 'WP_Customize_Product_Categories_Control' ) {
							$term = get_term_by( 'name', $val, 'product_cat' );
							$val  = isset( $term->term_id ) ? $term->term_id : 0;
						} elseif ( $ideapark_customize_types[ $mod_name ] == 'WP_Customize_Page_Control' ) {
							$page = ideapark_get_page_by_title( $val );
							$val  = isset( $page->ID ) ? $page->ID : 0;
						} elseif ( $ideapark_customize_types[ $mod_name ] == 'WP_Customize_HTML_Block_Control' ) {
							$page = ideapark_get_page_by_title( $val, OBJECT, 'html_block' );
							$val  = isset( $page->ID ) ? $page->ID : 0;
						}
					}

					if ( is_string( $val ) && preg_match( '~^\[\[([\s\S]+)\]\]$~', $val, $match ) ) {
						$val_orig      = $val;
						$attachment_id = ( $_posts = get_posts( [
							'name'      => $match[1],
							'post_type' => 'attachment'
						] ) ) && sizeof( $_posts ) == 1 ? $_posts[0]->ID : false;
						if ( $attachment_id && wp_attachment_is_image( $attachment_id ) ) {
							$val = wp_get_attachment_url( $attachment_id );
						} else {
							$val = '';
						}
					}

					if ( ! empty( $theme_home ) && isset( $theme_home['mods'][ $mod_name ] ) ) {
						if ( is_array( $theme_home['mods'][ $mod_name ] ) && is_array( $val ) ) {
							foreach ( $theme_home['mods'][ $mod_name ] as $_key => $_val ) {
								$val[ $_key ] = $_val;
							}
						} else {
							$val = $theme_home['mods'][ $mod_name ];
						}
					}

					if ( $mod_name != '0' ) {
						$options[ $mod_name ] = $val;
						set_theme_mod( $mod_name, $val );
					}
				}
			} else {
				$this->import_response( 'error', __( 'Error: file not found: ', 'ideapark-moderno' ) . $theme_options_fn );
			}
		}

		$options_fn = $this->demo_content_folder . '/options.txt';
		$f          = implode( '_', [ 'ideapark', 'api', 'theme', 'get', 'file' ] );
		$fn         = $f( $options_fn, $is_preliminary ? '' : ( $this->demo_content_folder . '/' . $home ) );

		if ( is_wp_error( $fn ) ) {
			$this->import_response( 'error', $fn->get_error_message() );
		} else {
			$options_fn = $fn;
		}
		$options_txt = ideapark_fgc( $options_fn );
		$options     = unserialize( base64_decode( $options_txt ) );

		foreach ( $options as $option_name => $val ) {
			if ( $option_name == 'wc_get_attribute_taxonomies' && function_exists( 'wc_get_attribute_taxonomies' ) ) {
				foreach ( $val as $taxonomy ) {
					if ( ! taxonomy_exists( wc_attribute_taxonomy_name( $taxonomy->attribute_name ) ) ) {
						$wpdb->insert( $wpdb->prefix . 'woocommerce_attribute_taxonomies', $attribute_data = [
							'attribute_name'    => $taxonomy->attribute_name,
							'attribute_label'   => $taxonomy->attribute_label,
							'attribute_type'    => $taxonomy->attribute_type,
							'attribute_orderby' => $taxonomy->attribute_orderby,
							'attribute_public'  => $taxonomy->attribute_public,
						] );
						do_action( 'woocommerce_attribute_added', $wpdb->insert_id, $attribute_data );
						$transient_name       = 'wc_attribute_taxonomies';
						$attribute_taxonomies = $wpdb->get_results( "SELECT * FROM " . $wpdb->prefix . "woocommerce_attribute_taxonomies" );
						set_transient( $transient_name, $attribute_taxonomies );
					}
				}
			} elseif ( ! $is_preliminary ) {
				if ( in_array( $option_name, $this->options_to_export_page_id ) ) {
					$page = ideapark_get_page_by_title( $val );
					$val  = isset( $page->ID ) ? $page->ID : 0;
				}
				update_option( $option_name, $val );
			}
		}

		if ( ! $is_preliminary && ! empty( $theme_home['options'] ) ) {
			foreach ( $theme_home['options'] as $option_name => $option_val ) {
				update_option( $option_name, $option_val );
			}
		}

		wp_cache_flush();

		if ( ! $is_preliminary ) {
			delete_option( '_wc_needs_pages' );
			delete_transient( '_wc_activation_redirect' );
			if ( class_exists( 'WC_Admin_Notices' ) ) {
				WC_Admin_Notices::remove_notice( 'template_files' );
				WC_Admin_Notices::remove_notice( 'install' );
			}

			if ( class_exists( 'WooCommerce' ) ) {

				$shop_page_id   = wc_get_page_id( 'shop' );
				$shop_permalink = ( $shop_page_id > 0 && get_post( $shop_page_id ) ) ? get_page_uri( $shop_page_id ) : '';
				if ( $shop_permalink ) {
					$permalinks                 = wc_get_permalink_structure();
					$permalinks['product_base'] = '/' . $shop_permalink;
					update_option( 'woocommerce_permalinks', $permalinks );
					wc_restore_locale();
				}
			}

			flush_rewrite_rules();
			wp_schedule_single_event( time(), 'woocommerce_flush_rewrite_rules' );
		}
	}

	function import_widgets() {
		$widgets_json = $this->demo_content_folder . '/widgets.txt';
		$f            = implode( '_', [ 'ideapark', 'api', 'theme', 'get', 'file' ] );
		$fn           = $f( $widgets_json );

		if ( is_wp_error( $fn ) ) {
			$this->import_response( 'error', $fn->get_error_message() );
		} else {
			$widgets_json = $fn;
		}
		$widget_data = ideapark_fgc( $widgets_json );
		$this->import_widget_data( $widget_data );
	}

	function import_revslider() {

		if ( ! ( defined( 'IDEAPARK_IMPORT_REVSLIDER' ) && IDEAPARK_IMPORT_REVSLIDER ) ) {
			return '';
		}

		$widgets_json = $this->demo_content_folder . '/revslider.zip';
		$f            = implode( '_', [ 'ideapark', 'api', 'theme', 'get', 'file' ] );
		$fn           = $f( $widgets_json );

		if ( is_wp_error( $fn ) ) {
			$this->import_response( 'error', $fn->get_error_message() );
		} else {
			$this->slides[] = $fn;
		}

		$has_errors = false;

		if ( $this->slides ) {
			if ( class_exists( 'RevSlider' ) ) {

				$slider                         = new RevSlider();
				$updateAnim                     = true;
				$updateStatic                   = 'none';
				$updateNavigation               = true;
				$_FILES['import_file']['error'] = false;

				foreach ( $this->slides as $slide ) {

					$_FILES["import_file"]["tmp_name"] = $slide;

					$response = $slider->importSliderFromPost( $updateAnim, $updateStatic, false, false, false, $updateNavigation );

					if ( $response["success"] == false ) {
						$this->importer['base']->error_msg[] = $message = $response["error"];

						$has_errors = true;
					}
					if ( ideapark_is_file( $slide ) ) {
						ideapark_delete_file( $slide );
					}
				}

				if ( ! $has_errors ) {
					$message = esc_html__( 'Imported Slider Revolution successfully', 'ideapark-moderno' );
				}

			} else {
				$this->importer['base']->error_msg[] = $message = __( 'The plugin Slider Revolution is not installed', 'ideapark-moderno' );
			}
		} else {
			$message = __( 'There are no slides in this demo', 'ideapark-moderno' );
		}

		return $message;
	}

	function import_response( $code, $message, $percent = 0 ) {
		$response            = [];
		$response['code']    = $code;
		$response['msg']     = $message;
		$response['percent'] = $percent;
		if ( $response['code'] == 'error' && $this->step ) {
			$response['msg'] .= ' (' . $this->step . ' / ' . ( $this->is_file_transient ? 'file' : 'db' ) . ')';
		}
		echo json_encode( $response );
		exit;
	}

	function import_widget_data( $widget_data ) {
		$data = unserialize( base64_decode( $widget_data ) );

		$sidebar_data = $data[0];
		$widget_data  = $data[1];

		$menus = wp_get_nav_menus();

		foreach ( $widget_data as $key => $tp_widgets ) {
			if ( $key == 'nav_menu' ) {
				$new_wg = [];
				foreach ( $tp_widgets as $key => $tp_widget ) {
					if ( empty( $tp_widget['nav_menu'] ) ) {
						$new_wg[ $key ] = $tp_widget;
						continue;
					}
					foreach ( $menus as $menu ) {
						if ( $tp_widget['nav_menu'] == $menu->name ) {
							$tp_widget['nav_menu'] = $menu->term_id;
							break;
						}
					}
					$new_wg[ $key ] = $tp_widget;
				}
				$widget_data['nav_menu'] = $new_wg;
			} elseif ( $key == 'ip_woocommerce_color_filter' ) {
				foreach ( $tp_widgets as $key => $val ) {
					if ( ! empty( $val['colors'] ) ) {
						$a = [];
						foreach ( $val['colors'] as $color_key => $color_val ) {
							if ( $term = get_term_by( 'name', $color_key, 'pa_color' ) ) {
								$a[ $term->term_id ] = $color_val;
							}
						}
						$tp_widgets[ $key ]['colors'] = $a;
					}
				}
				$widget_data['ip_woocommerce_color_filter'] = $tp_widgets;
			}

		}

		foreach ( $widget_data as $widget_data_title => $widget_data_value ) {
			$widgets[ $widget_data_title ] = [];
			foreach ( $widget_data_value as $widget_data_key => $widget_data_array ) {
				if ( is_int( $widget_data_key ) ) {
					$widgets[ $widget_data_title ][ $widget_data_key ] = 'on';
				}
			}
		}
		unset( $widgets[""] );

		foreach ( $sidebar_data as $title => $sidebar ) {
			$count = count( $sidebar );
			for ( $i = 0; $i < $count; $i ++ ) {
				$widget               = [];
				$widget['type']       = trim( substr( $sidebar[ $i ], 0, strrpos( $sidebar[ $i ], '-' ) ) );
				$widget['type-index'] = trim( substr( $sidebar[ $i ], strrpos( $sidebar[ $i ], '-' ) + 1 ) );
				if ( ! isset( $widgets[ $widget['type'] ][ $widget['type-index'] ] ) ) {
					unset( $sidebar_data[ $title ][ $i ] );
				}
			}
			$sidebar_data[ $title ] = array_values( $sidebar_data[ $title ] );
		}

		foreach ( $widgets as $widget_title => $widget_value ) {
			foreach ( $widget_value as $widget_key => $widget_value ) {
				$widgets[ $widget_title ][ $widget_key ] = $widget_data[ $widget_title ][ $widget_key ];
			}
		}

		$sidebar_data = [ array_filter( $sidebar_data ), $widgets ];

		$this->parse_import_data( $sidebar_data );
	}

	function parse_import_data( $import_array, $is_allow_clones = false ) {
		global $wp_registered_sidebars;
		$sidebars_data    = $import_array[0];
		$widget_data      = $import_array[1];
		$current_sidebars = $is_allow_clones ? get_option( 'sidebars_widgets' ) : [];
		$new_widgets      = [];

		foreach ( $sidebars_data as $import_sidebar => $import_widgets ) :

			foreach ( $import_widgets as $import_widget ) :

				if ( isset( $wp_registered_sidebars[ $import_sidebar ] ) ) :
					$title               = trim( substr( $import_widget, 0, strrpos( $import_widget, '-' ) ) );
					$index               = trim( substr( $import_widget, strrpos( $import_widget, '-' ) + 1 ) );
					$current_widget_data = get_option( 'widget_' . $title );

					if ( $is_allow_clones ) {
						$new_widget_name = $this->get_new_widget_name( $title, $index );
						$new_index       = trim( substr( $new_widget_name, strrpos( $new_widget_name, '-' ) + 1 ) );
						if ( ! empty( $new_widgets[ $title ] ) && is_array( $new_widgets[ $title ] ) ) {
							while ( array_key_exists( $new_index, $new_widgets[ $title ] ) ) {
								$new_index ++;
							}
						}
					} else {
						$new_index = $index;
					}

					if ( array_key_exists( $import_sidebar, $current_sidebars ) ) {
						if ( $is_allow_clones || ! is_array( $current_sidebars[ $import_sidebar ] ) || ! in_array( $title . '-' . $new_index, $current_sidebars[ $import_sidebar ] ) ) {
							$current_sidebars[ $import_sidebar ][] = $title . '-' . $new_index;
							if ( array_key_exists( $title, $new_widgets ) ) {
								$new_widgets[ $title ][ $new_index ] = $widget_data[ $title ][ $index ];
							} else {
								$current_widget_data[ $new_index ] = $widget_data[ $title ][ $index ];

								$current_multiwidget = isset( $current_widget_data['_multiwidget'] ) ? $current_widget_data['_multiwidget'] : '';
								$new_multiwidget     = isset( $widget_data[ $title ]['_multiwidget'] ) ? $widget_data[ $title ]['_multiwidget'] : false;
								$multiwidget         = ( $current_multiwidget != $new_multiwidget ) ? $current_multiwidget : 1;
								unset( $current_widget_data['_multiwidget'] );
								$current_widget_data['_multiwidget'] = $multiwidget;
								$new_widgets[ $title ]               = $current_widget_data;
							}
						} elseif ( in_array( $title . '-' . $new_index, $current_sidebars[ $import_sidebar ] ) ) {
							$new_widgets[ $title ][ $new_index ] = $widget_data[ $title ][ $index ];
						}
					} elseif ( array_key_exists( $import_sidebar, $wp_registered_sidebars ) ) {
						$current_sidebars[ $import_sidebar ] = [ $title . '-' . $new_index ];
						if ( array_key_exists( $title, $new_widgets ) ) {
							$new_widgets[ $title ][ $new_index ] = $widget_data[ $title ][ $index ];
						} else {
							$current_widget_data[ $new_index ] = $widget_data[ $title ][ $index ];

							$current_multiwidget = isset( $current_widget_data['_multiwidget'] ) ? $current_widget_data['_multiwidget'] : '';
							$new_multiwidget     = isset( $widget_data[ $title ]['_multiwidget'] ) ? $widget_data[ $title ]['_multiwidget'] : false;
							$multiwidget         = ( $current_multiwidget != $new_multiwidget ) ? $current_multiwidget : 1;
							unset( $current_widget_data['_multiwidget'] );
							$current_widget_data['_multiwidget'] = $multiwidget;
							$new_widgets[ $title ]               = $current_widget_data;
						}
					}

				endif;
			endforeach;
		endforeach;

		if ( isset( $new_widgets ) && isset( $current_sidebars ) ) {
			update_option( 'sidebars_widgets', $current_sidebars );

			foreach ( $new_widgets as $title => $content ) {
				update_option( 'widget_' . $title, $content );
			}

			return true;
		}

		return false;
	}

	function get_new_widget_name( $widget_name, $widget_index ) {
		$current_sidebars = get_option( 'sidebars_widgets' );
		$all_widget_array = [];
		foreach ( $current_sidebars as $sidebar => $widgets ) {
			if ( ! empty( $widgets ) && is_array( $widgets ) && $sidebar != 'wp_inactive_widgets' ) {
				foreach ( $widgets as $widget ) {
					$all_widget_array[] = $widget;
				}
			}
		}
		while ( in_array( $widget_name . '-' . $widget_index, $all_widget_array ) ) {
			$widget_index ++;
		}
		$new_widget_name = $widget_name . '-' . $widget_index;

		return $new_widget_name;
	}

	function exporter() {
		global $wp_filesystem, $wpdb;

		$result = $wpdb->get_col( $s = "SELECT ID FROM {$wpdb->posts} WHERE post_title = 'woocommerce_update_marketplace_suggestions'" );

		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! function_exists( 'export_wp' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/export.php' );
		}

		if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
			define( 'WP_LOAD_IMPORTERS', true );
		}

		if ( ! class_exists( 'WP_Importer' ) ) {
			include ABSPATH . 'wp-admin/includes/class-wp-importer.php';
		}

		include $this->importer_dir . '/wordpress-importer.php';

		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'export' ) ) {
			$this->import_response( 'error', __( 'Error: Permission denied', 'ideapark-moderno' ) );
		}

		$this->export_path = $this->importer_dir . "/" . $this->demo_content_folder . "/";

		if ( ! ideapark_is_dir( $this->export_path ) ) {
			if ( ! ideapark_mkdir( $this->export_path ) ) {
				$this->import_response( 'error', __( 'Error: Permission denied', 'ideapark-moderno' ) . ': ' . $this->export_path );
			}
		}

		if ( $count = $wpdb->get_var( $s = "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_title = 'woocommerce_update_marketplace_suggestions'" ) ) {
			$result = $wpdb->get_col( $s = "SELECT ID FROM {$wpdb->posts} WHERE post_title = 'woocommerce_update_marketplace_suggestions' LIMIT 100" );

			foreach ( $result as $_post_id ) {
				wp_delete_post( $_post_id, true );
			}

			$this->import_response( 'continue', 'Deleted: ' . sizeof( $result ) . ' from ' . $count, round( sizeof( $result ) / $count * 100 ) );
		}

		$this->export_fonts();
		$this->export_options();
		$this->export_content();
		$this->export_widgets();

		$code    = 'completed';
		$message = '<b style="color:#444">' . __( 'The demo data has been exported successfully!', 'ideapark-moderno' ) . '</b>';

		$message = apply_filters( 'ideapark_export_complete', $this->export_path, $message );

		$this->import_response( $code, $message, 100 );
	}

	private function _get_customize_types() {
		global $ideapark_customize;

		$ideapark_customize_types = [];
		foreach ( $ideapark_customize as $group ) {
			if ( ! empty( $group['controls'] ) ) {
				foreach ( $group['controls'] as $mod_name => $mod ) {
					$ideapark_customize_types[ $mod_name ] = isset( $mod['class'] ) ? $mod['class'] : ( isset( $mod['type'] ) ? $mod['type'] : null );
				}
			}
		}

		return $ideapark_customize_types;
	}

	function import_fonts() {
		global $wp_filesystem;
		/**
		 * @var $plugin Ideapark_Fonts
		 */
		if ( class_exists( 'Ideapark_Fonts' ) ) {
			$plugin     = Ideapark_Fonts::instance();
			$fonts_info = get_option( 'ideapark_fonts_info' );

			$wp_upload_arr = wp_get_upload_dir();
			$upload_dir    = $wp_upload_arr['basedir'] . "/ideapark_fonts/";

			$theme_fonts_fn  = $this->importer_dir . '/' . $this->demo_content_folder . '/fonts.txt';
			$theme_fonts_dir = $this->importer_dir . '/' . $this->demo_content_folder . '/fonts/';
			if ( ideapark_is_file( $theme_fonts_fn ) && ideapark_is_dir( $theme_fonts_dir ) ) {
				$fonts = unserialize( base64_decode( ideapark_fgc( $theme_fonts_fn ) ) );
				foreach ( $fonts as $font_name => $font ) {
					$fonts_info['fonts'][ $font_name ] = $font;
				}
				update_option( 'ideapark_fonts_info', $fonts_info );
				$dirlist = $wp_filesystem->dirlist( $theme_fonts_dir, true, false );
				foreach ( $dirlist as $row ) {
					unzip_file( $theme_fonts_dir . $row['name'], $upload_dir );
				}
				$plugin->make_css();
			}
		}
	}

	function export_fonts() {
		global $wp_filesystem;
		$fonts_info = get_option( 'ideapark_fonts_info' );

		$wp_upload_arr = wp_get_upload_dir();
		$upload_dir    = $wp_upload_arr['basedir'] . "/ideapark_fonts/";

		$theme_fonts = apply_filters( 'ideapark_fonts_theme_font', [] );
		if ( ! empty( $theme_fonts ) && is_array( $theme_fonts ) ) {
			$fonts_info = get_option( 'ideapark_fonts_info' );
			foreach ( $theme_fonts as $font_name => $font ) {

				if ( empty( $font['zip'] ) || empty( $font['version'] ) ) {
					continue;
				}

			}
		}

		$_fonts = $fonts_info['fonts'];

		if ( ! empty( $fonts_info['fonts'] ) ) {
			$fonts = [];
			foreach ( $fonts_info['fonts'] as $_font_name => $_font ) {
				if ( ! empty( $theme_fonts[ $_font_name ] ) ) {
					unset( $_fonts[ $_font_name ] );
					continue;
				}
				$fonts[] = $_font_name;
			}

			if ( $fonts ) {
				$fonts_fn  = $this->export_path . "fonts.txt";
				$fonts_dir = $this->export_path . "fonts/";

				if ( ideapark_is_file( $fonts_fn ) ) {
					ideapark_delete_file( $fonts_fn );
				}

				ideapark_fpc( $fonts_fn, base64_encode( serialize( $_fonts ) ) );

				if ( ! ideapark_is_dir( $fonts_dir ) ) {
					ideapark_mkdir( $fonts_dir );
				}

				foreach ( $fonts as $font ) {
					$_dir = $upload_dir . $font . '/';
					$zip  = new ZipArchive();

					if ( $zip->open( $fonts_dir . $font . '.zip', ZipArchive::CREATE ) === true ) {
						$zip->addEmptyDir( $font );
						$dirlist = $wp_filesystem->dirlist( $_dir, true, false );
						foreach ( $dirlist as $row ) {
							$zip->addFile( $_dir . $row['name'], $font . "/" . $row['name'] );
						}
					}
					$zip->close();
				}

			}
		}
	}

	function export_options() {
		global $wp_filesystem, $ideapark_customize_mods;

//		$theme_title_fn = $this->export_path . "theme.txt";
//		ideapark_fpc( $theme_title_fn, get_bloginfo( 'name' ) );

		$theme_title_fn = $this->export_path . "theme_url.txt";
		ideapark_fpc( $theme_title_fn, get_site_url() . '/' );

//		$image = wp_get_image_editor( get_template_directory() . '/screenshot.png' );
//		if ( ! is_wp_error( $image ) ) {
//			$image->resize( 300, '' );
//			$image->save( $this->export_path . 'theme.png' );
//		}

		$ideapark_customize_types = $this->_get_customize_types();

		ideapark_init_theme_mods();
		$options = $ideapark_customize_mods;

		foreach ( $options as $mod_name => $val ) {

			if ( ! empty( $this->mods_for_clear ) && in_array( $mod_name, $this->mods_for_clear ) ) {
				continue;
			}

			if ( array_key_exists( $mod_name, $ideapark_customize_types ) && $ideapark_customize_types[ $mod_name ] == 'WP_Customize_Image_Control' ) {
				if ( $attachment_id = attachment_url_to_postid( $val ) ) {
					$options[ $mod_name ] = '[[' . get_post_field( 'post_name', get_post( $attachment_id ) ) . ']]';
				}
			} elseif ( array_key_exists( $mod_name, $ideapark_customize_types ) && $ideapark_customize_types[ $mod_name ] == 'WP_Customize_Category_Control' ) {
				$options[ $mod_name ] = get_cat_name( $val );
			} elseif ( array_key_exists( $mod_name, $ideapark_customize_types ) && $ideapark_customize_types[ $mod_name ] == 'WP_Customize_Product_Categories_Control' ) {
				$_category = get_term( $val, 'product_cat' );
				if ( $_category && ! is_wp_error( $_category ) ) {
					$options[ $mod_name ] = $_category->name;
				}
			} elseif ( array_key_exists( $mod_name, $ideapark_customize_types ) && $ideapark_customize_types[ $mod_name ] == 'WP_Customize_Page_Control' ) {
				$options[ $mod_name ] = get_the_title( $val );
			} elseif ( array_key_exists( $mod_name, $ideapark_customize_types ) && $ideapark_customize_types[ $mod_name ] == 'WP_Customize_HTML_Block_Control' ) {
				$options[ $mod_name ] = get_the_title( $val );
			} elseif ( preg_match( '~_post_id$~i', $mod_name ) ) {
				$options[ $mod_name ] = get_the_title( $val );
			} elseif ( is_string( $val ) && preg_match( '~^' . preg_quote( home_url(), '~' ) . '~', $val, $match ) ) {
				if ( $attachment_id = attachment_url_to_postid( $val ) ) {
					$options[ $mod_name ] = '[[' . get_post_field( 'post_name', get_post( $attachment_id ) ) . ']]';
				}
			} elseif ( $mod_name == 'custom_fonts' && $val ) {
				foreach ( $val as &$font ) {
					foreach ( [ 'woff2', 'woff', 'ttf', 'otf', 'svg' ] as $_key ) {
						if ( ! empty( $font[ $_key ] ) ) {
							$font[ $_key ] = $this->get_post_name_by_id( attachment_url_to_postid( $font[ $_key ] ) );
						}
					}
				}
				$options[ $mod_name ] = $val;
			}
		}

		$menu_names = [];
		$menus      = wp_get_nav_menus();
		foreach ( $menus as $menu ) {
			$menu_names[ $menu->term_id ] = $menu->name;
		}

		if ( $menus = get_theme_mod( 'nav_menu_locations' ) ) {
			foreach ( $menus as $menu_slug => $menu_id ) {
				$menus[ $menu_slug ] = $menu_names[ $menu_id ];
			}
			$options['nav_menu_locations'] = $menus;
		}

		$options_fn = $this->export_path . "theme_options.txt";

		if ( ideapark_is_file( $options_fn ) ) {
			ideapark_delete_file( $options_fn );
		}

		foreach ( $options as $name => $value ) {
			if ( is_string( $value ) && preg_match( '~https?://(localhost|parkofideas)~', $value ) ) {
				$this->import_response( 'error', 'Theme mod export error: ' . $name . ' = ' . $value );
			}
		}

		ideapark_fpc( $options_fn, base64_encode( serialize( $options ) ) );

//		if ( IDEAPARK_DEMO ) {
//			ideapark_fpc( $options_fn . '.txt', serialize( $options ) );
//		}

		$options = [];

		foreach ( $this->options_to_export_page_id as $option_name ) {
			if ( $post_id = (int) get_option( $option_name ) ) {
				$post                    = get_post( $post_id );
				$options[ $option_name ] = $post->post_title;
			}
		}

		foreach ( $this->options_to_export as $option_name ) {
			$options[ $option_name ] = get_option( $option_name );
		}

		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			$options['wc_get_attribute_taxonomies'] = wc_get_attribute_taxonomies();
		}

		$options_fn = $this->export_path . "options.txt";

		if ( ideapark_is_file( $options_fn ) ) {
			ideapark_delete_file( $options_fn );
		}

		ideapark_fpc( $options_fn, base64_encode( serialize( $options ) ) );
	}

	private function _pack_term( &$val, $taxonomy ) {
		$cat_id   = abs( $val );
		$category = get_term( $cat_id, $taxonomy );
		if ( $category && ! is_wp_error( $category ) ) {
			$val = '[[' . $category->name . ']]';
		}
	}

	private function _unpack_term( &$val, $taxonomy, $is_negative = false ) {
		if ( is_string( $val ) && preg_match( '~^\[\[([\s\S]+)\]\]$~', $val, $match ) ) {
			$term = get_term_by( 'name', $match[1], $taxonomy );
			$val  = isset( $term->term_id ) ? ( $is_negative ? (string) ( - $term->term_id ) : $term->term_id ) : 0;
		}
	}

	private function _pack_menu( &$val ) {
		if ( $menu = wp_get_nav_menu_object( (int) $val ) ) {
			$val = '[[' . $menu->slug . ']]';
		}
	}

	private function _unpack_menu( &$val ) {
		if ( is_string( $val ) && preg_match( '~^\[\[([\s\S]+)\]\]$~', $val, $match ) ) {
			if ( $menu = wp_get_nav_menu_object( $match[1] ) ) {
				$val = $menu->term_id;
			} else {
				$val = 0;
			}
		}
	}

	private function _pack_product( &$val ) {
		if ( $title = get_the_title( $val ) ) {
			$val = '[[' . $title . ']]';
		}
	}

	private function _unpack_product( &$val ) {
		if ( is_string( $val ) && preg_match( '~^\[\[([\s\S]+)\]\]$~', $val, $match ) ) {
			$page = ideapark_get_page_by_title( $match[1], OBJECT, 'product' );
			$val  = isset( $page->ID ) ? $page->ID : 0;
		}
	}

	private function _pack_html_block( &$val ) {
		if ( $title = get_the_title( $val ) ) {
			$val = '[[' . $title . ']]';
		}
	}

	private function _unpack_html_block( &$val ) {
		if ( is_string( $val ) && preg_match( '~^\[\[([\s\S]+)\]\]$~', $val, $match ) ) {
			$page = ideapark_get_page_by_title( $match[1], OBJECT, 'html_block' );
			$val  = isset( $page->ID ) ? $page->ID : 0;
		}
	}

	private function _pack_img( &$val, &$url ) {
		if ( $post_name = get_post_field( 'post_name', get_post( (int) $val ) ) ) {
			$val = 'image[[' . $post_name . ']]';
			$url = '';
		}
	}

	private function _unpack_img( &$val, &$url ) {
		if ( is_string( $val ) && preg_match( '~^image\[\[([\s\S]+)\]\]$~', $val, $match ) ) {
			if ( ! $this->importer['base']->fetch_attachments ) {
				if ( $this->importer['base']->placeholder_path && $this->importer['base']->placeholder_post_id ) {
					$url = $this->importer['base']->placeholder_path;
					$val = $this->importer['base']->placeholder_post_id;
				} else {
					$url = '';
					$val = 0;
				}
			} else {
				$attachment_id = ( $_posts = get_posts( [
					'name'      => $match[1],
					'post_type' => 'attachment'
				] ) ) && sizeof( $_posts ) == 1 ? $_posts[0]->ID : false;
				if ( $attachment_id && wp_attachment_is_image( $attachment_id ) ) {
					$url = wp_get_attachment_url( $attachment_id );
					$val = $attachment_id;
				} else {
					$url = '';
					$val = 0;
				}
			}
		}
	}

	function prepare_elementor_data( $mode, &$data, $level ) { // mode = 0 - export, mode = 1 - import

		$widget_type = $data->elType == 'widget' ? $data->widgetType : '';

//		echo $level . '. ' . $data->elType . ' ' . $data->id . ( $data->elType == 'widget' ? ' TYPE: ' . $data->widgetType : '' ) . '<br>';
		if ( isset( $data->elements ) ) {
			foreach ( $data->elements as &$element ) {
				$this->prepare_elementor_data( $mode, $element, $level + 1 );
			}
			unset( $element );
		}

		if ( $widget_type == 'wp-widget-rev-slider-widget' && isset( $data->settings->wp->rev_slider ) ) {
			$val_orig                       = $data->settings->wp->rev_slider;
			$data->settings->wp->rev_slider = $this->prepare_revslider( $mode, $data->settings->wp->rev_slider );
//			echo "<b>REV SLIDER</b>: " . $val_orig . ' ' . $data->settings->wp->rev_slider . '<br>';
		}

		if ( isset( $data->settings ) ) {
			foreach ( $data->settings as $key => $val ) {

				if ( $widget_type == 'ideapark-hotspot-carousel' && $key == 'image_list' && ! empty( $data->settings->{$key} ) && is_array( $data->settings->{$key} ) ) {
					foreach ( $data->settings->{$key} as &$item ) {
						$hotspots = ! empty( $item->hotspots ) ? json_decode( $item->hotspots, true ) : [];
						if ( ! empty( $hotspots ) ) {
							if ( $mode ) {
								foreach ( $hotspots as $i => $hotspot ) {
									if ( ! empty( $hotspots[ $i ]['product_id'] ) ) {
										$this->_unpack_product( $hotspots[ $i ]['product_id'] );
									}
								}
							} else {
								foreach ( $hotspots as $i => $hotspot ) {
									if ( ! empty( $hotspots[ $i ]['product_id'] ) ) {
										$this->_pack_product( $hotspots[ $i ]['product_id'] );
									}
								}
							}
							$item->hotspots = json_encode( $hotspots );
						}
					}
				}

				if ( preg_match( '~menu~i', $key ) ) {
					if ( $mode ) {
						$this->_unpack_menu( $data->settings->{$key} );
					} elseif ((int) $val > 0) {
						$this->_pack_menu( $data->settings->{$key} );
					}
				}

				if ( $widget_type == 'ideapark-tabs' && $key == 'tabs' && ! empty( $data->settings->{$key} ) && is_array( $data->settings->{$key} ) ) {
					foreach ( $data->settings->{$key} as &$item ) {
						if ( $mode ) {
							$this->_unpack_html_block( $item->page_id );
						} else {
							if ( ! empty( $item->page_id ) ) {
								$this->_pack_html_block( $item->page_id );
							}
						}
					}
				}

				if ( $widget_type == 'ideapark-product-tabs' && $key == 'tabs' && ! empty( $data->settings->{$key} ) && is_array( $data->settings->{$key} ) ) {
					foreach ( $data->settings->{$key} as &$item ) {
						if ( $mode ) {
							$this->_unpack_term( $item->type, 'product_cat', true );
						} else {
							if ( ! empty( $item->type ) && preg_match( '~^\-\d+$~', $item->type ) ) {
								$this->_pack_term( $item->type, 'product_cat' );
							}
						}
					}
				}

				if ( $key == 'type' && ! empty( $data->settings->{$key} ) && ( preg_match( '~^\-\d+$~', $data->settings->{$key} ) || preg_match( '~^\[\[([\s\S]+)\]\]$~', $data->settings->{$key} ) ) ) {
					if ( $mode ) {
						$this->_unpack_term( $data->settings->{$key}, 'product_cat', true );
					} else {
						$this->_pack_term( $data->settings->{$key}, 'product_cat' );
					}
				}
			}
		}
	}

	private static $revsliders = [];

	function prepare_revslider( $mode, $val ) {

		if ( empty( $revsliders ) && class_exists( 'RevSliderSlider' ) ) {

			$_slider = new RevSliderSlider();
			try {
				self::$revsliders = $_slider->get_sliders();
			} catch ( Exception $e ) {
			}
		}

		if ( ! empty( self::$revsliders ) ) {
			foreach ( self::$revsliders as $slide ) {
				if ( $mode ) {
					if ( $slide->alias == $val ) {
						$val = $slide->id;
						break;
					}
				} else {
					if ( $slide->id == $val ) {
						$val = $slide->alias;
						break;
					}
				}
			}
		}

		return $val;
	}

	function prepare_term_meta( $mode ) {
		global $wpdb;
		$termtmeta = $wpdb->get_results( "SELECT * FROM {$wpdb->termmeta} WHERE meta_key LIKE '" . ( $mode ? '_' : '' ) . "html_block_%'" );
		foreach ( $termtmeta as $meta ) {
			if ( $mode ) { //import
				$page  = ideapark_get_page_by_title( $meta->meta_value, OBJECT, 'html_block' );
				$value = isset( $page->ID ) ? $page->ID : 0;
				update_term_meta( $meta->term_id, preg_replace( '~^_~', '', $meta->meta_key ), $value );
				delete_term_meta( $meta->term_id, $meta->meta_key );
			} else { //export
				update_term_meta( $meta->term_id, '_' . $meta->meta_key, get_the_title( $meta->meta_value ) );
			}
		}

		$termtmeta = $wpdb->get_results( "SELECT * FROM {$wpdb->termmeta} WHERE meta_key LIKE '" . ( $mode ? '_' : '' ) . "thumbnail_id'" );
		foreach ( $termtmeta as $meta ) {
			if ( $mode ) { //import
				$val = $meta->meta_value;
				$url = '';
				$this->_unpack_img( $val, $url );
				update_term_meta( $meta->term_id, preg_replace( '~^_~', '', $meta->meta_key ), $val );
				delete_term_meta( $meta->term_id, $meta->meta_key );
			} else { //export
				$val = $meta->meta_value;
				$url = '';
				$this->_pack_img( $val, $url );
				update_term_meta( $meta->term_id, '_' . $meta->meta_key, $val );
			}
		}
	}

	function prepare_elementor_images( $mode, &$data ) {
		foreach ( $data as $key => &$val ) {
			if ( $mode && ( $key === 'url' && isset( $data->id ) && preg_match( '~^image\[\[~', $data->id ) ) ) {
				$this->_unpack_img( $data->id, $data->url );
			} elseif ( ! $mode && ( $key === 'url' && preg_match( '~/wp-content/uploads/~', $val ) && isset( $data->id ) && is_int( $data->id ) ) ) {
				$this->_pack_img( $data->id, $data->url );
			}
			if ( is_array( $val ) || is_object( $val ) ) {
				$this->prepare_elementor_images( $mode, $val );
			}
		}
	}

	function prepere_post_meta( $mode ) {
		global $wpdb;
		$postmeta = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->postmeta} WHERE meta_key = %s", $mode ? '_elementor_data_moderno' : '_elementor_data' ) );
		foreach ( $postmeta as $meta ) {
			$data = json_decode( $meta->meta_value );
			$this->prepare_elementor_images( $mode, $data );
			foreach ( $data as &$element ) {
				$this->prepare_elementor_data( $mode, $element, 0 );
			}
			if ( $mode ) {
				update_post_meta( $meta->post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
				delete_post_meta( $meta->post_id, '_elementor_data_moderno' );
			} else {
//				update_post_meta( $meta->post_id, '_elementor_data_moderno', '' );
//				$table = _get_meta_table( 'post' );
//				$wpdb->update( $table, [ 'meta_value' => wp_json_encode( $data ) ], [ 'post_id'  => $meta->post_id,
//				                                                                      'meta_key' => '_elementor_data_moderno'
//				] );
				if ( $data && ( $post_id = (int) $meta->post_id ) && get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
					$wpdb->update( $wpdb->posts, [ 'post_content' => ' ' ], [ 'ID' => $post_id ] );
				}
				update_post_meta( $meta->post_id, '_elementor_data_moderno', wp_slash( wp_json_encode( $data ) ) );
			}
		}

		$postmeta = $wpdb->get_results( "SELECT * FROM {$wpdb->postmeta} WHERE meta_key LIKE '" . ( $mode ? '_' : '' ) . "ideapark_variation_images'" );
		foreach ( $postmeta as $meta ) {
			$values = unserialize( $meta->meta_value );
			if ( $mode ) { //import
				foreach ( $values as &$value ) {
					$this->_unpack_img( $value, $url );
				}
				update_post_meta( $meta->post_id, preg_replace( '~^_~', '', $meta->meta_key ), $values );
				delete_post_meta( $meta->post_id, $meta->meta_key );
			} else { //export
				foreach ( $values as &$value ) {
					$this->_pack_img( $value, $url );
				}
				update_post_meta( $meta->post_id, '_' . $meta->meta_key, $values );
			}
		}
	}

	public function query_filter( $query ) {
		global $wpdb;
		$query = str_replace( "{$wpdb->posts}.post_status != 'auto-draft'", "({$wpdb->posts}.post_status != 'auto-draft' AND {$wpdb->posts}.post_status != 'draft')", $query );

		return $query;
	}

	function export_content() {
		global $wpdb;

		$this->prepere_post_meta( 0 );
		$this->prepare_term_meta( 0 );

		$wpdb->query( "DELETE FROM $wpdb->postmeta WHERE meta_key = '_ideapark_inline_svg'" );

		$args = [ 'content' => 'all', 'status' => 'publish' ];
		ob_start();
		add_filter( 'query', [ $this, 'query_filter' ] );
		add_filter( 'wxr_export_skip_postmeta', function ( $skip, $meta_key, $meta ) {
			if ( ! isset( $meta->meta_value ) || is_null( $meta->meta_value ) ) {
				$meta->meta_value = '';
			}
			return $skip;
		}, 10, 3 );
		export_wp( $args );
		remove_filter( 'query', [ $this, 'query_filter' ] );

		$content_fn = $this->export_path . "content.xml";
		if ( ideapark_is_file( $content_fn ) ) {
			ideapark_delete_file( $content_fn );
		}

		ideapark_fpc( $content_fn, preg_replace( '#[\x00-\x08\x0B-\x0C\x0E-\x1F]+#is', ' ', ob_get_clean() ) );

		$wpdb->query( "DELETE FROM $wpdb->postmeta WHERE meta_key IN ('_elementor_data_moderno', '_ideapark_variation_images')" );

		if ( ! headers_sent() ) {
			header_remove( 'Content-Description' );
			header_remove( 'Content-Disposition' );
			header_remove( 'Content-Type' );
			header( 'Content-Type:text/html; charset=UTF-8' );
		}
	}

	function export_widgets() {
		global $wp_filesystem;

		$sidebars_array = get_option( 'sidebars_widgets' );
		$sidebar_export = [];
		$posted_array   = [];
		foreach ( $sidebars_array as $sidebar => $widgets ) {
			if ( ! empty( $widgets ) && is_array( $widgets ) ) {
				foreach ( $widgets as $sidebar_widget ) {
					if ( $sidebar != 'wp_inactive_widgets' ) {
						$sidebar_export[ $sidebar ][] = $sidebar_widget;
						$posted_array[]               = $sidebar_widget;
					}
				}
			}
		}
		$widgets = [];
		foreach ( $posted_array as $k ) {
			$widget                = [];
			$widget['type']        = trim( substr( $k, 0, strrpos( $k, '-' ) ) );
			$widget['type-index']  = trim( substr( $k, strrpos( $k, '-' ) + 1 ) );
			$widget['export_flag'] = true;
			$widgets[]             = $widget;
		}

		$menus = wp_get_nav_menus();

		$widgets_array = [];
		foreach ( $widgets as $widget ) {
			$widget_val = get_option( 'widget_' . $widget['type'] );
			$widget_val = apply_filters( 'widget_data_export', $widget_val, $widget['type'] );

			if ( $widget['type'] == 'nav_menu' ) {
				foreach ( $widget_val as $key => $val ) {
					foreach ( $menus as $menu ) {
						if ( $val['nav_menu'] == $menu->term_id ) {
							$widget_val[ $key ]['nav_menu'] = $menu->name;
							break;
						}
					}
				}
			} elseif ( $widget['type'] == 'ip_woocommerce_color_filter' ) {
				foreach ( $widget_val as $key => $val ) {
					if ( ! empty( $val['colors'] ) ) {
						$a = [];
						foreach ( $val['colors'] as $color_key => $color_val ) {
							if ( $term = get_term_by( 'term_taxonomy_id', $color_key, 'pa_color' ) ) {
								$a[ $term->name ] = $color_val;
							}
						}
						$widget_val[ $key ]['colors'] = $a;
					}
				}
			}

			$multiwidget_val                                           = $widget_val['_multiwidget'];
			$widgets_array[ $widget['type'] ][ $widget['type-index'] ] = $widget_val[ $widget['type-index'] ];
			if ( isset( $widgets_array[ $widget['type'] ]['_multiwidget'] ) ) {
				unset( $widgets_array[ $widget['type'] ]['_multiwidget'] );
			}
			$widgets_array[ $widget['type'] ]['_multiwidget'] = $multiwidget_val;
		}
		unset( $widgets_array['export'] );
		$export_array = [ $sidebar_export, $widgets_array ];

		$options_fn = $this->export_path . "widgets.txt";

		if ( ideapark_is_file( $options_fn ) ) {
			ideapark_delete_file( $options_fn );
		}

		ideapark_fpc( $options_fn, base64_encode( serialize( $export_array ) ) );
	}

	public function on_wp_import_post_meta( $post_meta ) {
		foreach ( $post_meta as &$meta ) {
			if ( '_elementor_data_moderno' === $meta['key'] ) {
				$meta['value'] = wp_slash( $meta['value'] );
				break;
			}
		}

		return $post_meta;
	}

	public function on_wxr_importer_pre_process_post_meta( $post_meta ) {
		if ( '_elementor_data_moderno' === $post_meta['key'] ) {
			$post_meta['value'] = wp_slash( $post_meta['value'] );
		}

		return $post_meta;
	}

	public function update_urls( $old_url, $new_url ) {
		global $wpdb;
		$queries = [
			'content'     => "UPDATE $wpdb->posts SET post_content = replace(post_content, %s, %s)",
			'excerpts'    => "UPDATE $wpdb->posts SET post_excerpt = replace(post_excerpt, %s, %s)",
			'attachments' => "UPDATE $wpdb->posts SET guid = replace(guid, %s, %s) WHERE post_type = 'attachment'",
			'links'       => "UPDATE $wpdb->links SET link_url = replace(link_url, %s, %s)",
			'custom'      => "UPDATE $wpdb->postmeta SET meta_value = replace(meta_value, %s, %s)",
			'guids'       => "UPDATE $wpdb->posts SET guid = replace(guid, %s, %s)",
		];
		foreach ( $queries as $option => $query ) {
			if ( $option == 'custom' ) {
				$row_count = $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->postmeta" );
				$page_size = 10000;
				$pages     = ceil( $row_count / $page_size );

				for ( $page = 0; $page < $pages; $page ++ ) {
					$pm_query = "SELECT * FROM $wpdb->postmeta WHERE meta_value <> ''";
					$items    = $wpdb->get_results( $pm_query );
					foreach ( $items as $item ) {
						$value = $item->meta_value;
						if ( trim( $value ) == '' ) {
							continue;
						}

						$edited = $this->unserialize_replace( $old_url, $new_url, $value );

						if ( $edited != $value ) {
							$wpdb->query( "UPDATE $wpdb->postmeta SET meta_value = '" . $edited . "' WHERE meta_id = " . $item->meta_id );
						}
					}
				}
			} else {
				$wpdb->query( $wpdb->prepare( $query, $old_url, $new_url ) );
			}
		}
	}

	function unserialize_replace( $from = '', $to = '', $data = '', $serialised = false ) {
		try {
			if ( false !== is_serialized( $data ) ) {
				$unserialized = unserialize( $data );
				$data         = $this->unserialize_replace( $from, $to, $unserialized, true );
			} elseif ( is_array( $data ) ) {
				$_tmp = [];
				foreach ( $data as $key => $value ) {
					$_tmp[ $key ] = $this->unserialize_replace( $from, $to, $value, false );
				}
				$data = $_tmp;
				unset( $_tmp );
			} else {
				if ( is_string( $data ) ) {
					$data = str_replace( $from, $to, $data );
				}
			}
			if ( $serialised ) {
				return serialize( $data );
			}
		} catch ( Exception $error ) {
		}

		return $data;
	}

	function get_post_id_by_name( $post_name ) {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_name = %s", $post_name ) );
	}

	function get_post_name_by_id( $post_id ) {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( "SELECT post_name FROM $wpdb->posts WHERE ID = %d", $post_id ) );
	}
}

class Ideapark_Importer_Base {
	var $message = '';
	var $step_total = 0;
	var $step_done = 0;
	var $error_msg = [];
}