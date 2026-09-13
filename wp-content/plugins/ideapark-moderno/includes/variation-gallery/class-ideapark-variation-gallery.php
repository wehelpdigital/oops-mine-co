<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ideapark_Variation_Gallery {

	private $dir;
	private $assets_dir;
	private $assets_url;

	private $column_id = 'ip_variation_gallery';
	private $column_name = 'Variation Gallery';

	public function __construct() {
		$this->dir        = dirname( __FILE__ );
		$this->assets_dir = trailingslashit( $this->dir ) . 'assets';
		$this->assets_url = esc_url( rtrim( plugins_url( '/assets/', __FILE__ ), '/' ) );

		add_action( 'woocommerce_product_after_variable_attributes', [ $this, 'output' ], 10, 3 );
		add_action( 'dokan_product_after_variable_attributes', [ $this, 'output' ], 10, 3 );
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ], 10, 1 );
		add_action( 'admin_footer', [ $this, 'admin_template' ] );
		add_action( 'woocommerce_save_product_variation', [ $this, 'save' ], 10, 2 );
		add_filter( 'woocommerce_available_variation', [ $this, 'available_variation_gallery' ], 110, 3 );

		add_action( 'wp_ajax_ideapark_variation_images', [ $this, 'variation_images' ] );
		add_action( 'wp_ajax_nopriv_ideapark_variation_images', [ $this, 'variation_images' ] );

		if (is_admin()) {
			add_filter( "woocommerce_product_export_product_default_columns", [ $this, 'export_column_name' ] );
			add_filter( "woocommerce_product_export_product_column_{$this->column_id}", [
				$this,
				'export_column_data'
			], 10, 3 );

			add_filter( 'woocommerce_csv_product_import_mapping_options', [ $this, 'export_column_name' ] );
			add_filter( 'woocommerce_csv_product_import_mapping_default_columns', [
				$this,
				'default_import_column_name'
			] );
			add_action( 'woocommerce_product_import_inserted_product_object', [
				$this,
				'process_wc_import'
			], 10, 2 );

			add_action('woocommerce_product_importer_before_set_parsed_data', function (){
				add_filter( 'http_headers_useragent', [ $this, 'user_agent' ] );
			});
		}
	}

	public function admin_template() { ?>
		<script type="text/html" id="tmpl-ideapark-variation-gallery-image">
			<li class="ideapark-variation-gallery-image">
				<input class="wvg_variation_id_input" type="hidden"
				       name="ideapark_variation_gallery[{{data.product_variation_id}}][]" value="{{data.id}}">
				<img src="{{data.url}}" alt="">
				<a onclick="return false" class="ideapark-variation-remove"><span
						class="dashicons dashicons-dismiss"></span></a>
			</li>
		</script>
	<?php }

	public function admin_enqueue_scripts() {
		wp_enqueue_style( 'ideapark-variation-gallery-admin', $this->assets_url . '/variation-gallery.css', [], ideapark_mtime( $this->assets_dir . '/variation-gallery.css' ) );
		wp_enqueue_script( 'ideapark-variation-gallery-admin', $this->assets_url . '/variation-gallery.js', [
			'jquery',
			'jquery-ui-sortable',
			'wp-util'
		], ideapark_mtime( $this->assets_dir . '/variation-gallery.js' ), true );
		wp_localize_script( 'ideapark-variation-gallery-admin', 'ideapark_variation_vars', [
			'choose_image' => esc_html__( 'Choose Image', 'ideapark-moderno' ),
			'add_image'    => esc_html__( 'Add Images', 'ideapark-moderno' ),
			'main_image'   => esc_html__( 'First you need to select the variation image above', 'ideapark-moderno' ),
		] );
	}

	public function output( $loop, $variation_data, $variation ) {
		$variation_id   = absint( $variation->ID );
		$gallery_images = get_post_meta( $variation_id, 'ideapark_variation_images', true );
		?>
		<div data-product_variation_id="<?php echo esc_attr( $variation_id ) ?>"
		     class="form-row form-row-full ideapark-variation-gallery-wrapper">
			<div><?php esc_html_e( 'Variation Image Gallery', 'ideapark-moderno' ) ?></div>
			<div class="ideapark-variation-gallery-image-container">
				<ul class="ideapark-variation-gallery-images">
					<?php
					if ( is_array( $gallery_images ) && ! empty( $gallery_images ) ) {
						foreach ( $gallery_images as $image_id ) {
							$image = wp_get_attachment_image_src( $image_id );
							?>
							<li class="ideapark-variation-gallery-image">
								<input type="hidden"
								       name="ideapark_variation_gallery[<?php echo esc_attr( $variation_id ) ?>][]"
								       value="<?php echo $image_id ?>">
								<img src="<?php echo esc_url( $image[0] ) ?>" alt="">
								<a onclick="return false" class="ideapark-variation-remove"><span
										class="dashicons dashicons-dismiss"></span></a>
							</li>
						<?php }
					}
					?>
				</ul>
			</div>
			<p class="ideapark-variation-button-wrap hide-if-no-js">
				<a onclick="return false" data-product_variation_loop="<?php echo absint( $loop ) ?>"
				   data-product_variation_id="<?php echo esc_attr( $variation_id ) ?>"
				   class="button ideapark-variation-add"><?php esc_html_e( 'Add Gallery Images', 'ideapark-moderno' ) ?></a>
			</p>
		</div>
		<?php
	}

	public function save( $variation_id, $loop ) {
		if ( isset( $_POST['ideapark_variation_gallery'] ) ) {

			if ( isset( $_POST['ideapark_variation_gallery'][ $variation_id ] ) ) {

				$gallery_image_ids = (array) array_map( 'absint', $_POST['ideapark_variation_gallery'][ $variation_id ] );
				update_post_meta( $variation_id, 'ideapark_variation_images', $gallery_image_ids );
			} else {
				delete_post_meta( $variation_id, 'ideapark_variation_images' );
			}
		} else {
			delete_post_meta( $variation_id, 'ideapark_variation_images' );
		}
	}

	public function variation_images() {
		if ( isset( $_POST['variation_id'] ) && ( $variation_id = absint( $_POST['variation_id'] ) ) && get_post_meta( $variation_id, 'ideapark_variation_images', true ) ) {
			$product_id   = wp_get_post_parent_id( $variation_id );
			$is_quickview = ! empty( $_POST['is_quickview'] );
			if ( $is_quickview ) {
				ideapark_mod_set_temp( '_is_quickview', true );
				ideapark_mod_set_temp( 'shop_product_modal', false );
			}
			wc_get_template( 'single-product/product-image.php', [
				'has_variation_gallery_images' => true,
				'product_id'                   => $product_id,
				'images'                       => ideapark_product_images( $product_id, $variation_id ),
			] );
		}
		die();
	}

	public function available_variation_gallery( $available_variation, $variationProductObject, $variation ) {
		$variation_id = absint( $variation->get_id() );

		$available_variation['has_variation_gallery_images'] = (bool) get_post_meta( $variation_id, 'ideapark_variation_images', true );

		return $available_variation;
	}

	public function export_column_name( $columns ) {
		$columns[ $this->column_id ] = $this->column_name;

		return $columns;
	}

	public function export_column_data( $value, $product, $column_id ) {
		if ( $product->get_type() == 'variation' ) {
			$_p             = $product->get_id();
			$gallery_images = get_post_meta( $product->get_id(), 'ideapark_variation_images', true );
			$image_urls     = [];
			if ( is_array( $gallery_images ) && ! empty( $gallery_images ) ) {
				foreach ( $gallery_images as $image_id ) {
					if ( $image = wp_get_attachment_image_src( $image_id, 'full' ) ) {
						$image_urls[] = $image[0];
					}
				}

				return $image_urls ? wp_json_encode( $image_urls ) : '';
			}
		}

		return '';
	}

	public function default_import_column_name( $columns ) {
		$columns[ esc_html__( 'Variation Gallery', 'ideapark-moderno' ) ] = $this->column_id;

		return $columns;
	}

	public function user_agent() {
		return 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36';
	}

	public function process_wc_import( $product, $data ) {

		$product_id = $product->get_id();

		if ( isset( $data[ $this->column_id ] ) && ! empty( $data[ $this->column_id ] ) ) {
			$image_urls = (array) json_decode( $data[ $this->column_id ], true );
			$image_ids  = [];

			if ( $image_urls ) {
				foreach ( $image_urls as $image_url ) {
					if ( $image_id = ! empty( $image_url ) ? $this->get_attachment_id_from_url( $image_url, 0 ) : '' ) {
						$image_ids[] = $image_id;
					}
				}
			}

			if ( $image_ids ) {
				update_post_meta( $product_id, 'ideapark_variation_images', $image_ids );
			} else {
				delete_post_meta( $product_id, 'ideapark_variation_images' );
			}
		}
	}

	public function get_attachment_id_from_url( $url, $product_id ) {
		if ( empty( $url ) ) {
			return 0;
		}

		$id         = 0;
		$upload_dir = wp_upload_dir( null, false );
		$base_url   = $upload_dir['baseurl'] . '/';

		if ( false !== strpos( $url, $base_url ) || false === strpos( $url, '://' ) ) {
			$file = str_replace( $base_url, '', $url );
			$args = array(
				'post_type'   => 'attachment',
				'post_status' => 'any',
				'fields'      => 'ids',
				'meta_query'  => array(
					'relation' => 'OR',
					array(
						'key'     => '_wp_attached_file',
						'value'   => '^' . $file,
						'compare' => 'REGEXP',
					),
					array(
						'key'     => '_wp_attached_file',
						'value'   => '/' . $file,
						'compare' => 'LIKE',
					),
					array(
						'key'     => '_wc_attachment_source',
						'value'   => '/' . $file,
						'compare' => 'LIKE',
					),
				),
			);
		} else {
			$args = array(
				'post_type'   => 'attachment',
				'post_status' => 'any',
				'fields'      => 'ids',
				'meta_query'  => array(
					array(
						'value' => $url,
						'key'   => '_wc_attachment_source',
					),
				),
			);
		}

		$ids = get_posts( $args );

		if ( $ids ) {
			$id = current( $ids );
		}

		// Upload if attachment does not exists.
		if ( ! $id && stristr( $url, '://' ) ) {
			$upload = wc_rest_upload_image_from_url( $url );

			if ( is_wp_error( $upload ) ) {
				throw new Exception( $upload->get_error_message(), 400 );
			}

			$id = wc_rest_set_uploaded_image_as_attachment( $upload, $product_id );

			if ( ! wp_attachment_is_image( $id ) ) {
				throw new Exception( sprintf( __( 'Not able to attach "%s".', 'ideapark-moderno' ), $url ), 400 );
			}

			update_post_meta( $id, '_wc_attachment_source', $url );
		}

		if ( ! $id ) {
			throw new Exception( sprintf( __( 'Unable to use image "%s".', 'ideapark-moderno' ), $url ), 400 );
		}

		return $id;
	}
}

new Ideapark_Variation_Gallery();