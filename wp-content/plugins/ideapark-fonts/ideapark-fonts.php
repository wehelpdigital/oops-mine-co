<?php
/*
 * Plugin Name: Font Icons Loader
 * Version: 1.10
 * Description: Loader for font icons set.
 * Author: parkofideas.com
 * Author URI: http://parkofideas.com
 * Text Domain: ideapark-fonts
 * Domain Path: /lang/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ideapark_Fonts' ) ) {

	define( 'IDEAPARK_FONTS_IS_AJAX', function_exists( 'wp_doing_ajax' ) ? wp_doing_ajax() : ( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX ) );
	define( 'IDEAPARK_FONTS_IS_AJAX_HEARTBEAT', IDEAPARK_FONTS_IS_AJAX && ! empty( $_POST['action'] ) && ( $_POST['action'] == 'heartbeat' ) );

	if ( ! class_exists( '\\FontLib\\Font' ) ) {
		require_once( dirname( __FILE__ ) . '/lib/FontLib/Autoloader.php' );
	}

	class Ideapark_Fonts {

		private static $_instance = null;
		public $settings = null;
		public $_version;
		public $_token;
		public $file;
		public $dir;
		public $assets_dir;
		public $assets_url;
		public $script_suffix;
		public $upload_dir;
		public $upload_url;
		private $upload_result = '';


		public function __construct( $file = '' ) {

			if ( IDEAPARK_FONTS_IS_AJAX_HEARTBEAT ) {
				return;
			}

			$this->_version = '1.10';
			$this->_token   = 'ideapark_fonts';

			// Load plugin environment variables
			$this->file          = $file;
			$this->dir           = dirname( $this->file );
			$this->assets_dir    = trailingslashit( $this->dir ) . 'assets';
			$this->assets_url    = esc_url( trailingslashit( plugins_url( '/assets/', $this->file ) ) );
			$this->script_suffix = '';
			$wp_upload_arr       = wp_get_upload_dir();
			if ( preg_match( '~^https~i', $this->assets_url ) && ! preg_match( '~^https~i', $wp_upload_arr['baseurl'] ) ) {
				$wp_upload_arr['baseurl'] = preg_replace( '~^http:~i', 'https:', $wp_upload_arr['baseurl'] );
			}
			$this->upload_dir = $wp_upload_arr['basedir'] . "/" . strtolower( sanitize_file_name( $this->_token ) ) . "/";
			$this->upload_url = $wp_upload_arr['baseurl'] . "/" . strtolower( sanitize_file_name( $this->_token ) ) . "/";

			if ( is_admin() ) {
				register_activation_hook( $this->file, [ $this, 'install' ] );
				add_action( 'after_setup_theme', [ $this, 'init_filesystem' ], 0 );
				add_action( 'after_setup_theme', [ $this, 'check_font_path' ], 99 );
				add_action( 'after_update_theme', [ $this, 'need_install_font' ], 100 );
				add_action( 'after_switch_theme', [ $this, 'need_install_font' ], 100 );
				add_action( 'ideapark_after_import_finish', [ $this, 'need_install_font' ], 100 );
				add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ], 10, 1 );
				add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_styles' ], 10, 1 );
				add_action( 'elementor/editor/after_enqueue_styles', [ $this, 'enqueue_styles' ], 99 );
				add_action( 'elementor/controls/controls_registered', [ $this, 'elementor_add_icons' ], 10, 1 );
				add_filter( 'elementor/icons_manager/additional_tabs', [ $this, 'elementor_add_icons_tab' ] );
				add_action( 'admin_menu', [ $this, 'setup_menu' ] );
				add_action( 'current_screen', [ $this, 'action' ], 999 );
			} else {
				add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_styles' ], 99 );
			}

			$this->load_plugin_textdomain();

			add_shortcode( 'font-icon', [ $this, 'shortcode' ] );

		} // End __construct ()

		public function load_plugin_textdomain() {
			$domain = 'ideapark-fonts';
			$locale = apply_filters( 'plugin_locale', get_locale(), $domain );
			load_textdomain( $domain, WP_LANG_DIR . '/' . $domain . '/' . $domain . '-' . $locale . '.mo' );
			if ( ! load_textdomain( $domain, get_template_directory() . '/languages/' . $domain . '-' . $locale . '.mo' ) ) {
				load_plugin_textdomain( $domain, false, dirname( plugin_basename( $this->file ) ) . '/lang/' );
			}
		}

		public function need_install_font() {
			$theme_fonts = apply_filters( 'ideapark_fonts_theme_font', [] );
			if ( ! empty( $theme_fonts ) && is_array( $theme_fonts ) ) {
				$fonts_info = get_option( 'ideapark_fonts_info' );
				foreach ( $theme_fonts as $font_name => $font ) {

					if ( empty( $font['zip'] ) || empty( $font['version'] ) ) {
						continue;
					}

					if (
						empty( $fonts_info['fonts'] ) ||
						! array_key_exists( $font_name, $fonts_info['fonts'] ) ||
						! array_key_exists( 'version', $fonts_info['fonts'][ $font_name ] ) ||
						$fonts_info['fonts'][ $font_name ]['version'] != $font['version'] ||
						! $this->check_font_file( $font_name )
					) {

						if ( ! $this->is_file( $font['zip'] ) ) {
							continue;
						}

						$this->_remove( $font_name, false );

						$movefile = [
							'file'  => $font['zip'],
							'type'  => 'application/zip',
							'error' => '',
						];

						$this->_add( $movefile, $font['version'] );
					}
				}
			}
		}

		public function check_font_path() {
			$fonts_info = get_option( 'ideapark_fonts_info' );
			if ( empty( $fonts_info['<ver>'] ) ) {
				$fonts_info['<ver>'] = $this->_version;
				update_option( 'ideapark_fonts_info', $fonts_info );
			}
			if ( ! empty( $fonts_info['<css>'] ) && ! $this->is_file( $this->upload_dir . $fonts_info['<css>'] ) ) {
				$this->make_css();
			}
		}

		public function check_font_file( $font_name ) {
			$fonts_info = get_option( 'ideapark_fonts_info' );
			if ( array_key_exists( $font_name, $fonts_info['fonts'] ) ) {
				$_font  = $fonts_info['fonts'][ $font_name ];
				$css_fn = $this->upload_dir . '/' . $_font['font_path'] . '/font-style.css';
				$svg_fn = $this->upload_dir . '/' . $_font['font_path'] . '/' . $_font['font_path'] . '.svg';
				if ( $this->is_dir( $this->upload_dir . '/' . $_font['font_path'] ) && $this->is_file( $css_fn ) && $this->is_file( $svg_fn ) ) {
					return true;
				}
			} else {
				return false;
			}
		}

		public function elementor_add_icons( $controls_registry ) {

			$fonts_info = get_option( 'ideapark_fonts_info' );
			if ( ! empty( $fonts_info['fonts'] ) ) {

				$icons     = $controls_registry->get_control( 'icon' )->get_settings( 'options' );
				$new_icons = [];

				foreach ( $fonts_info['fonts'] as $_font_name => $_font ) {
					foreach ( $_font['unicodes'] as $class_name => $code ) {
						$new_icons[ $class_name ] = $class_name; //'font-icon ' . $code;
					}
				}
				$new_icons = array_merge(
					$new_icons,
					$icons
				);
				$controls_registry->get_control( 'icon' )->set_settings( 'options', $new_icons );
			}
		}

		public function elementor_add_icons_tab( $tabs ) {

			$fonts_info = get_option( 'ideapark_fonts_info' );
			if ( ! empty( $fonts_info['fonts'] ) ) {

				foreach ( $fonts_info['fonts'] as $_font_name => $_font ) {

					if ( ! empty( $fonts_info['<css>'] ) ) {
						//wp_register_style( $this->_token . '-icons', $fonts_info['<css>'] );

						$tabs[ $_font_name ] = [
							'name'          => $_font_name,
							'label'         => $_font_name,
//						'url'           => $this->upload_url . $fonts_info['<css>'],
							'prefix'        => $_font['prefix'],
							'displayPrefix' => '',
							'labelIcon'     => 'eicon-font',
							'ver'           => $_font['version'],
							'fetchJson'     => $this->upload_url . $_font['json'],
							'native'        => false,
						];
					}
				}
			}

			return $tabs;
		}


		public function setup_menu() {
			add_theme_page( __( 'Font Icons Loader', 'ideapark-fonts' ), __( 'Font Icons Loader', 'ideapark-fonts' ), 'manage_options', $this->_token, [
				$this,
				'upload_page'
			] );
		}

		private function list_files( $path, &$dirlist ) {
			$list = [];
			foreach ( $dirlist as $item ) {
				if ( $item['type'] == 'd' ) {
					if ( ! empty( $item['files'] ) ) {
						$list = array_merge( $list, $this->list_files( $path . $item['name'] . '/', $item['files'] ) );
					}
				} elseif ( $item['type'] == 'f' ) {
					$list[] = $path . $item['name'];
				}
			}

			return $list;
		}

		public function action() {
			$this->upload_result = $this->_action();
		}

		public function make_css() {
			$fonts_info = get_option( 'ideapark_fonts_info' );

			$css   = '';
			$hash  = '';
			$icons = [];

			if ( ! empty( $fonts_info['fonts'] ) ) {

				foreach ( $fonts_info['fonts'] as $_font_name => $_font ) {
					$css_fn = $this->upload_dir . '/' . $_font['font_path'] . '/font-style.css';
					if ( $this->is_dir( $this->upload_dir . '/' . $_font['font_path'] ) && $this->is_file( $css_fn ) ) {
						$css .= $this->fgc( $css_fn );
					} else {
						unset( $fonts_info['fonts'][ $_font_name ] );
					}
					$hash .= $_font_name . ' - ';
				}

				if ( ! empty( $fonts_info['<css>'] ) && $this->is_file( $this->upload_dir . $fonts_info['<css>'] ) ) {
					$this->delete( $this->upload_dir . $fonts_info['<css>'] );
				}

				$css_file_name = 'font-style-' . strtolower( substr( md5( $hash . date( 'Y-m-d H:i:s' ) ), 0, 8 ) ) . '.min.css';
				$this->fpc( $this->upload_dir . $css_file_name, $css );

				$fonts_info['<css>'] = $css_file_name;

				update_option( 'ideapark_fonts_info', $fonts_info );

				if ( class_exists( 'Elementor\Plugin' ) && \Elementor\Plugin::instance()->files_manager ) {
					\Elementor\Plugin::instance()->files_manager->clear_cache();
				}
			}
		}

		private function _remove( $font_name, $need_make_css = true ) {
			if ( ! empty( $font_name ) ) {

				$fonts_info = get_option( 'ideapark_fonts_info' );

				if ( ! empty( $fonts_info['fonts'][ $font_name ] ) ) {
					$font_dir = $this->upload_dir . $fonts_info['fonts'][ $font_name ]['font_path'] . '/';

					$this->delete( $font_dir, true );
					if ( ! empty( $fonts_info['fonts']['<css>'] ) ) {
						$this->delete( $this->upload_dir . $fonts_info['fonts']['<css>'], false, 'f' );
					}

					if ( $need_make_css ) {
						$this->make_css();
					}

					return sprintf( __( 'Font %s was successfully removed', 'ideapark-fonts' ), $font_name );
				}

			}
		}

		private function _add( $movefile, $version = '' ) {
			$fonts_info = get_option( 'ideapark_fonts_info' );

			if ( $movefile && empty( $movefile['error'] ) ) {
				if ( $movefile['type'] != 'application/zip' ) {
					return new WP_Error( 'ideapark_upload_file_error', __( 'File is not Zip-archive.', 'ideapark-fonts' ) );
				}

				$temp_dir = $this->upload_dir . 'temp/';

				if ( ! $this->is_dir( $temp_dir ) ) {
					$this->mkdir( $temp_dir );
				}

				$result = $this->unzip_file( $movefile['file'], $temp_dir );

				if ( ! $version ) {
					$this->delete( $movefile['file'] );
				}

				if ( ! $result ) {
					$this->delete( $temp_dir, true );

					return new WP_Error( 'ideapark_unzip_file_error', __( 'Unzip file error.', 'ideapark-fonts' ) );
				}

				$dirlist = $this->dirlist( $temp_dir, false, true );
				$files   = $this->list_files( $temp_dir, $dirlist );

				$font_name = '';
				$font_dir  = '';
				$font_url  = '';
				$unicodes  = [];
				$font_urls = [ 'eot' => '', 'svg' => '', 'ttf' => '', 'woff' => '', 'woff2' => '' ];
				$is_found  = false;

				foreach ( $files as $file ) {
					if ( preg_match( '~\.woff$~i', $file ) ) {
						if ( $font = @\FontLib\Font::load( $file ) ) {
							$font->parse();
							$font_name   = $font->getFontName();
							$font_path   = strtolower( sanitize_file_name( $font_name ) );
							$font_dir    = $this->upload_dir . $font_path . '/';
							$font_url    = $font_path . '/';
							$font_prefix = strtolower( substr( md5( $font_name ), 0, 4 ) );
							$names       = $font->getData( "post", "names" );
							if ( $characters = $font->getUnicodeCharMap() ) {
								foreach ( $characters as $code_dec => $index ) {
									if ( array_key_exists( $index, $names ) ) {
										$name = sanitize_title( trim( $names[ $index ] ) );
									} else {
										$name = $index;
									}
									$unicodes[ 'fi-' . $font_prefix . '-' . strtolower( $name ) ] = strtoupper( dechex( $code_dec ) );
								}
							}
							$font->close();
						}
						if ( $unicodes ) {
							$is_found = true;
						}
						break;
					}
				}

				if ( ! $is_found ) {
					foreach ( $files as $file ) {
						if ( preg_match( '~\.svg$~i', $file ) ) {
							$svg = $this->fgc( $file );
							if ( preg_match( '~<font[^<]+id\s*=\s*["\']([^"\']+)["\']~', $svg, $match ) ) {
								$font_name   = $match[1];
								$font_path   = strtolower( sanitize_file_name( $match[1] ) );
								$font_dir    = $this->upload_dir . $font_path . '/';
								$font_url    = $font_path . '/';
								$font_prefix = strtolower( substr( md5( $font_name ), 0, 4 ) );
								if ( preg_match_all( '~unicode=["\']([^"\']+)["\']~i', $svg, $matches, PREG_SET_ORDER ) ) {
									foreach ( $matches as $index => $match ) {

										if ( ! preg_match( '~<glyph[^>]+' . preg_quote( $match[1], '~' ) . '[^>]+d\s*=\s*["\'][^>]+>~', $svg, $match_glyph ) &&
											 ! preg_match( '~<glyph[^>]+d\s*=\s*["\'][^>]+' . preg_quote( $match[1], '~' ) . '[^>]+>~', $svg, $match_glyph ) ||
											 ! trim( $match[1] ) ) {
											continue;
										}
										$code = str_replace( '&#x', '', str_replace( ';', '', $match[1] ) );

										//glyph-name="space"

										if ( ! empty( $match_glyph[0] ) && preg_match( '~glyph-name\s*=\s*["\']([^"\']+)["\']~', $match_glyph[0], $match_name ) ) {
											$name = sanitize_title( $match_name[1] );
										} elseif ( strpos( $match[1], '&#x' ) !== false ) {
											$name = $code;
										} else {
											$name = $index;
										}

										$unicodes[ 'fi-' . $font_prefix . '-' . strtolower( $name ) ] = strtoupper( $code );
									}
								}
							}
							if ( $unicodes ) {
								$is_found = true;
							}
							break;
						}
					}
				}

				if ( ! $is_found ) {
					$this->delete( $temp_dir, true );

					return new WP_Error( 'ideapark_unzip_file_error', __( 'Can`t recognize WOFF or SVG font file', 'ideapark-fonts' ) );
				}

				if ( ! $this->is_dir( $font_dir ) ) {
					$this->mkdir( $font_dir );
				} elseif ( $version ) {
					$this->delete( $font_dir, true );
					$this->mkdir( $font_dir );
				} else {
					$this->delete( $temp_dir, true );

					return new WP_Error( 'ideapark_unzip_file_error', sprintf( __( 'Font %s is already installed, remove it before uploading.', 'ideapark-fonts' ), $font_name ) );
				}

				if ( ! is_array( $fonts_info ) || ! isset( $fonts_info['fonts'] ) ) {
					$fonts_info = [ 'fonts' => [] ];
				}

				$orig_css = '';
				$has      = [
					'eot'   => false,
					'svg'   => false,
					'ttf'   => false,
					'woff'  => false,
					'woff2' => false,
				];
				foreach ( $files as $file ) {
					if ( preg_match( '~\.(eot|svg|ttf|woff|woff2)$~i', $file, $match ) ) {
						$result = $this->move( $file, $font_dir . basename( $file ) );
						if ( ! $result ) {
							return new WP_Error( 'ideapark_unzip_file_error', __( 'Can`t copy font file', 'ideapark-fonts' ) );
						}
						$has[ $match[1] ] = true;

						$font_urls[ $match[1] ] = $font_url . basename( $file ) . '?v=' . $this->mtime( $font_dir . basename( $file ) );
					} elseif ( preg_match( '~\.css$~i', $file, $match ) ) {
						$orig_css = $this->fgc( $file );
						$orig_css = preg_replace( '~@font-face[\r\n\s\t]*\{[^\}]+\}~i', '', $orig_css );
					}
				}

				$smoothing = apply_filters( 'ideapark_font_icons_smoothing', '
	-webkit-font-smoothing: subpixel-antialiased;
	-moz-osx-font-smoothing: grayscale;' );

				$_url = [];
				if ( $has['eot'] ) {
					$_url[] = 'url("' . $font_urls['eot'] . '#iefix") format("embedded-opentype")';
				}
				if ( $has['woff2'] ) {
					$_url[] = 'url("' . $font_urls['woff'] . '") format("woff2")';
				}
				if ( $has['woff'] ) {
					$_url[] = 'url("' . $font_urls['woff'] . '") format("woff")';
				}
				if ( $has['svg'] ) {
					$_url[] = 'url("' . $font_urls['woff'] . '") format("woff")';
				}
				if ( $has['ttf'] ) {
					$_url[] = 'url("' . $font_urls['ttf'] . '") format("truetype")';
				}


				$css = '@font-face {
	font-family: "' . $font_name . '"; ' .
					   ( $has['eot'] ? "\n" . 'src: url("' . $font_urls['eot'] . '");' : '' ) .
					   "\n" . 'src:' . implode( ",\n", $_url ) . ';
	font-weight: normal;
	font-style: normal;
	font-display: swap;
}

i[class^="fi-' . $font_prefix . '"], [class*=" fi-' . $font_prefix . '"] {
	display: inline-block;
	' . $smoothing . '
	font-family: "' . $font_name . '";
	font-weight: normal;
	font-style: normal;
	font-variant: normal;
	text-rendering: auto;
	line-height: 1;
	speak: none;
}

' . $orig_css;

				foreach ( $unicodes as $class_name => $code ) {
					$icons[] = str_replace( 'fi-' . $font_prefix . '-', '', $class_name );

					$css .= "\n.{$class_name}:before {
content: \"" . ( preg_match( '~^[0-9a-f]+$~i', $code ) ? "\\" : '' ) . $code . "\";
}
"; //todo-me сделать минимизацию
				}

				$this->fpc( $font_dir . 'font-style.css', $css );
				$this->delete( $temp_dir, true );

				$json_file_name = $font_name . '/' . $font_name . '.json';
				$this->fpc( $this->upload_dir . $json_file_name, json_encode( [ 'icons' => $icons ] ) );

				$fonts_info['fonts'][ $font_name ] = [
					'font_path' => $font_path,
					'unicodes'  => $unicodes,
					'version'   => $version,
					'prefix'    => 'fi-' . $font_prefix . '-',
					'json'      => $json_file_name
				];

				update_option( 'ideapark_fonts_info', $fonts_info );

				$this->make_css();

				return __( 'Font was successfully uploaded', 'ideapark-fonts' );
			} else {
				return new WP_Error( 'ideapark_upload_file_error', $movefile['error'] );
			}
		}

		private
		function _action() {

			if ( get_current_screen()->id != 'appearance_page_ideapark_fonts' ) {
				return '';
			}

			if ( ! empty( $_POST['remove'] ) ) {
				return $this->_remove( $_POST['remove'] );

			} elseif ( ! empty( $_POST['submit'] ) ) {

				check_admin_referer( 'ideapark_upload_action', 'ideapark_upload_icons' );

				if ( ! isset( $_FILES['ideapark_upload_icons'] ) ) {
					return new WP_Error( 'ideapark_upload_file_empty', __( 'File is empty. Please upload something more substantial. This error could also be caused by uploads being disabled in your php.ini or by post_max_size being defined as smaller than upload_max_filesize in php.ini.', 'ideapark-fonts' ) );
				}

				$movefile = wp_handle_upload( $_FILES['ideapark_upload_icons'], [ 'test_form' => false ] );

				return $this->_add( $movefile );
			}
		}

		public
		function upload_page() {

			$fonts_info = get_option( 'ideapark_fonts_info' );
			if ( ! is_array( $fonts_info ) || ! isset( $fonts_info['fonts'] ) ) {
				$fonts_info = [ 'fonts' => [] ];
				update_option( 'ideapark_fonts_info', $fonts_info );
			}
			?>
			<div class="wrap ideapark-fonts__page">
				<h1><?php esc_html_e( 'Font Icons Loader', 'ideapark-fonts' ) ?></h1>
				<div class="ideapark-fonts__container">
					<?php
					$upload_result = $this->upload_result;
					if ( is_wp_error( $upload_result ) ) {
						/* @var WP_Error $upload_result */
						$errors = $upload_result->get_error_messages();
						foreach ( $errors as $error ) {
							?>
							<div class="notice notice-error is-dismissible">
								<p><strong><?php echo esc_html( $error ); ?></strong></p>
								<button type="button" class="notice-dismiss">
								<span
									class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice.', 'ideapark-fonts' ); ?></span>
								</button>
							</div>
							<?php
						}
					} elseif ( ! empty( $upload_result ) ) {
						/* @var String $upload_result */
						?>
						<div class="notice notice-success is-dismissible">
							<p><strong><?php echo esc_html( $upload_result ); ?></strong></p>
							<button type="button" class="notice-dismiss">
							<span
								class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice.', 'ideapark-fonts' ); ?></span>
							</button>
						</div>
						<?php
					}
					?>
					<div class="ideapark-fonts__upload-form">
						<h3><?php esc_html_e( 'Upload a Zip with font files', 'ideapark-fonts' ) ?></h3>
						<p><?php printf( esc_html__( 'You can generate font with SVG-icons using online font generator %s or similar', 'ideapark-fonts' ), '<a href="https://fontello.com/" rel="noreferrer" target="_blank">https://fontello.com/</a>' ) ?></p>
						<form method="post" enctype="multipart/form-data">
							<?php wp_nonce_field( 'ideapark_upload_action', 'ideapark_upload_icons' ); ?>
							<input type='file' id='ideapark_upload_icons' name='ideapark_upload_icons'/>
							<?php submit_button( 'Upload' ) ?>
						</form>
					</div>
					<div class="ideapark-fonts__header-wrap">
						<h3><?php esc_html_e( 'Installed fonts', 'ideapark-fonts' ) ?></h3>
						<div>
							<button type="button"
									class="ideapark-fonts__expand-all"><?php esc_html_e( 'Expand all', 'ideapark-fonts' ) ?></button>
							/
							<button type="button"
									class="ideapark-fonts__collapse-all"><?php esc_html_e( 'Collapse all', 'ideapark-fonts' ) ?></button>
						</div>
					</div>
					<form method="post">
						<ul class="ideapark-fonts__list">
							<?php foreach ( $fonts_info['fonts'] as $font_name => $font ) { ?>
								<li class="ideapark-fonts__item">

									<div class="ideapark-fonts__item-header">
										<?php if ( empty( $font['version'] ) || defined( 'IDEAPARK_DEMO' ) && IDEAPARK_DEMO ) { ?>
											<button type="submit" name="remove"
													value="<?php echo esc_attr( $font_name ); ?>"
													class="dashicons dashicons-no-alt ideapark-fonts__item-remove"></button>
										<?php } ?>
										<a href="#"
										   class="ideapark-fonts__icon-link js-ideapark-fonts-font"><?php echo esc_html( $font_name ); ?></a>
										<span
											class="ideapark-fonts__item-count">(<?php echo sprintf( esc_html__( 'icons: %s', 'ideapark-fonts' ), count( $font['unicodes'] ) ) ?>)</span>
										<button type="button"
												class="dashicons dashicons-arrow-down-alt2 ideapark-fonts__item-view"></button>
									</div>
									<div class="ideapark-fonts__wrap">
										<ul class="ideapark-fonts__icon-list">
											<?php foreach ( $font['unicodes'] as $class_name => $code ) { ?>
												<li class="ideapark-fonts__icon">
													<a href="#" class="ideapark-fonts__icon-link js-ideapark-fonts-icon"
													   data-code="<?php echo esc_attr( $code ); ?>"
													   data-class="<?php echo esc_attr( $class_name ); ?>">
														<div class="ideapark-fonts__icon-wrap">
															<i class="<?php echo esc_attr( $class_name ); ?> ideapark-fonts__icon-img"></i>
															<span class="ideapark-fonts__icon-title">
														<?php echo esc_attr( $class_name ); ?>
														</span>
														</div>
													</a>
												</li>
											<?php } ?>
										</ul>
									</div>
								</li>
							<?php } ?>
						</ul>
					</form>
				</div>
				<div class="ideapark-fonts__popup">
					<div class="ideapark-fonts__popup-container">
						<i class="ideapark-fonts__popup-img"></i>
						<div class="ideapark-fonts__popup-title"><?php esc_html_e( 'HTML', 'ideapark-fonts' ) ?></div>
						<div
							class="ideapark-fonts__popup-code"><?php echo esc_html( '<i class="' ) . '<span class="ideapark-fonts__code"></span>' . esc_html( '"><!-- --></i>' ); ?></div>
						<div
							class="ideapark-fonts__popup-title"><?php esc_html_e( 'Shortcode', 'ideapark-fonts' ) ?></div>
						<div class="ideapark-fonts__popup-code">[font-icon code="<span
								class="ideapark-fonts__code"></span>"
							size="" color=""]
						</div>
						<button type="button" class="dashicons dashicons-no-alt ideapark-fonts__popup-close"></button>
					</div>
				</div>
			</div>
			<?php
		}

		public
		function admin_enqueue_scripts() {
			wp_register_script( $this->_token . '-admin', esc_url( $this->assets_url ) . 'js/admin' . $this->script_suffix . '.js', [ 'jquery' ], $this->_version, true );
			wp_enqueue_script( $this->_token . '-admin' );
		} // End admin_enqueue_scripts ()


		public
		function admin_enqueue_styles() {
			$fonts_info = get_option( 'ideapark_fonts_info' );
			if ( ! empty( $fonts_info['<css>'] ) ) {
				wp_register_style( $this->_token . '-icons', $this->upload_url . $fonts_info['<css>'] );
				wp_enqueue_style( $this->_token . '-icons' );
			}

			wp_register_style( $this->_token . '-admin', esc_url( $this->assets_url ) . 'css/admin.css', [], $this->_version );
			wp_enqueue_style( $this->_token . '-admin' );
		} // End admin_enqueue_styles ()


		public
		function enqueue_styles() {
			$fonts_info = get_option( 'ideapark_fonts_info' );
			if ( ! empty( $fonts_info['<css>'] ) ) {
				wp_register_style( $this->_token . '-icons', $this->upload_url . $fonts_info['<css>'] );
				wp_enqueue_style( $this->_token . '-icons' );
			}
		} // End enqueue_styles ()


		private
		function _log_version_number() {
			update_option( $this->_token . '_version', $this->_version );
		} // End _log_version_number ()

		public
		function shortcode(
			$atts
		) {
			$content = '';

			if ( ! empty( $atts['code'] ) ) {
				$div_attr  = [
					'class="' . esc_attr( $atts['code'] ) . '"'
				];
				$div_style = [];
				if ( ! empty( $atts['size'] ) ) {
					if ( preg_match( '~^\d+$~', $atts['size'] ) ) {
						$atts['size'] .= 'px';
					}
					$div_style[] = 'font-size:' . esc_attr( $atts['size'] );
				}
				if ( ! empty( $atts['color'] ) ) {
					$div_style[] = 'color:' . esc_attr( $atts['color'] );
				}
				foreach ( $atts as $key => $val ) {
					$div_attr[] = 'data-' . esc_attr( $key ) . '="' . esc_attr( $val ) . '"';
				}
				if ( $div_style ) {
					$div_attr[] = 'style="' . implode( ';', $div_style ) . '"';
				}
				$content = '<i ' . implode( ' ', $div_attr ) . '></i>';
			}

			return $content;
		}

		public
		function init_filesystem() {
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

		function is_dir( $file ) {
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

		function is_file( $file ) {
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

		function mtime( $file ) {
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

		function mkdir( $file ) {
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

		function unzip_file( $file, $path ) {
			/**
			 * @var WP_Filesystem_Base $wp_filesystem
			 */
			global $wp_filesystem;
			if ( ! empty( $file ) ) {
				if ( isset( $wp_filesystem ) && is_object( $wp_filesystem ) ) {
					$file = str_replace( ABSPATH, $wp_filesystem->abspath(), $file );
					$path = str_replace( ABSPATH, $wp_filesystem->abspath(), $path );

					return unzip_file( $file, $path );
				}
			}

			return false;
		}

		function delete( $file, $recursive = false, $type = false ) {
			/**
			 * @var WP_Filesystem_Base $wp_filesystem
			 */
			global $wp_filesystem;
			if ( ! empty( $file ) ) {
				if ( isset( $wp_filesystem ) && is_object( $wp_filesystem ) ) {
					$file = str_replace( ABSPATH, $wp_filesystem->abspath(), $file );

					$wp_filesystem->delete( $file, $recursive, $type );
				}
			}
		}

		function move( $file, $destination, $overwrite = false ) {
			/**
			 * @var WP_Filesystem_Base $wp_filesystem
			 */
			global $wp_filesystem;
			if ( ! empty( $file ) ) {
				if ( isset( $wp_filesystem ) && is_object( $wp_filesystem ) ) {
					$file        = str_replace( ABSPATH, $wp_filesystem->abspath(), $file );
					$destination = str_replace( ABSPATH, $wp_filesystem->abspath(), $destination );

					if ( $this->is_file( $file ) ) {
						return $wp_filesystem->move( $file, $destination, $overwrite );
					}
				}
			}

			return false;
		}

		function dirlist( $path, $include_hidden = true, $recursive = false ) {
			/**
			 * @var WP_Filesystem_Base $wp_filesystem
			 */
			global $wp_filesystem;
			if ( ! empty( $path ) ) {
				if ( isset( $wp_filesystem ) && is_object( $wp_filesystem ) ) {
					$path = str_replace( ABSPATH, $wp_filesystem->abspath(), $path );

					$dirlist = $wp_filesystem->dirlist( $path, $include_hidden, $recursive );
					if ( ! is_array( $dirlist ) ) {
						$dirlist = [];
					}

					return $dirlist;
				}
			}

			return [];
		}

		function fgc( $file ) {
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

		function fpc( $file, $data, $flag = 0 ) {
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

		public
		static function instance(
			$file = ''
		) {
			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self( $file );
			}

			return self::$_instance;
		} // End instance ()


		public
		function __clone() {
			_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'ideapark-fonts' ), $this->_version );
		} // End __clone ()


		public
		function __wakeup() {
			_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'ideapark-fonts' ), $this->_version );
		} // End __wakeup ()


		public
		function install() {
			$this->init_filesystem();
			if ( ! $this->is_dir( $this->upload_dir ) ) {
				$this->mkdir( $this->upload_dir );
			}
			$this->_log_version_number();
			$this->need_install_font();
		} // End install ()

	}

	Ideapark_Fonts::instance( __FILE__ );
}