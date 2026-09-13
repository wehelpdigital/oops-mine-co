<?php
/**
 * Single Product Image
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/single-product/product-image.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see            https://docs.woocommerce.com/document/template-structure/
 * @package        WooCommerce/Templates
 * @version         11.0.0
 */

defined( 'ABSPATH' ) || exit;

// Note: `wc_get_gallery_image_html` was added in WC 3.3.2 and did not exist prior. This check protects against theme overrides being used on older versions of WC.
if ( ! function_exists( 'wc_get_gallery_image_html' ) ) {
	return;
}
global $post, $product;

if ( empty( $has_variation_gallery_images ) ) {
	$product_id = $post->ID;
	$images     = ideapark_product_images();
}

$index               = 0;
$product_page_layout = ideapark_mod( 'product_page_layout' );

ob_start();
if ( $images ) {
	foreach ( $images as $i => $image ) {
		$item_class = "c-product__slider-item";
		if ( ! $i && ideapark_mod( 'single_image_first_row' ) && ( $product_page_layout == 'layout-1' || $product_page_layout == 'layout-2' )) {
			$item_class .= ' c-product__slider-item--single-in-row';
		}

		$link_class = [];
		if ( ideapark_mod( 'shop_product_zoom' ) ) {
			$link_class[] = 'c-product__image-link--zoom';
		}

		if ( ideapark_mod( 'shop_product_modal' ) ) {
			$link_class[] = 'c-product__image-link--modal js-product-modal';
		}

		if ( ! empty( $image['video_url'] ) ) {
			$is_inline_video = false;

			if ( preg_match( '~youtube\.com/shorts/([^/?#]+)~', $image['video_url'], $match ) ) {
				$image['video_url'] = 'https://www.youtube.com/watch?v=' . $match[1];
			}

			if ( ideapark_mod( 'shop_product_modal' ) ) {
				$image_wrap_open  = sprintf( '<a download href="%s" class="c-product__image-link %s" data-index="%s" data-product-id="%s" data-elementor-open-lightbox="no" onclick="return false;">', esc_url( $image['video_url'] ), implode( ' ', $link_class ), $index ++, $product_id );
				$image_wrap_close = '</a>';
				if ( ideapark_mod( 'product_inline_video' ) && ( $video_html = ideapark_inline_video( $image['video_url'], $image['full_url'] ) ) ) {
					$image_wrap_open  .= $video_html;
					$image_wrap_close = '<span class="c-product__round"></span><span class="c-product__loading js-loading-wrap"></span>' . $image_wrap_close;
					$is_inline_video  = true;
				}
				wp_enqueue_style( 'wp-mediaelement' );
				wp_enqueue_script( 'wp-mediaelement' );
				wp_enqueue_script( 'mediaelement-vimeo' );
			} elseif ( ideapark_mod( '_is_quickview' ) ) {
				$image_wrap_open = '';
				if ( ideapark_mod( 'product_inline_video' ) ) {
					$image_wrap_open = ideapark_inline_video( $image['video_url'], $image['full_url'] );
				}
				if ( ! $image_wrap_open ) {
					$image_wrap_open = sprintf( '<a href="%s" class="c-product__image-link js-video %s" data-elementor-open-lightbox="no" onclick="return false;" data-autoplay="true" data-vbtype="video">', esc_url( $image['video_url'] ), implode( ' ', $link_class ) );
				} else {
					$is_inline_video = true;
				}
				$image_wrap_close = '</a>';
			} else {
				$is_inline_video = true;
				$image_wrap_open = '';
				if ( ideapark_mod( 'product_inline_video' ) ) {
					$image_wrap_open = ideapark_inline_video( $image['video_url'], $image['full_url'] );
				}
				if ( ! $image_wrap_open ) {
					$image_wrap_open = do_shortcode( '[video src="' . esc_url( $image['video_url'] ) . '" ' . ( $image['full_url'] ? 'poster="' . esc_url( $image['full_url'] ) . '"' : '' ) . ( ! $i && preg_match( '~\.(mp4|m4v|webm|ogv|wmv|flv)~i', $image['video_url'] ) ? ' autoplay="on" muted="on"' : '' ) . ' loop="on"]' );
				}
				if ( strpos( $image_wrap_open, 'wp-embedded-video' ) ) {
					$image_wrap_open = wp_oembed_get( $image['video_url'] );
				}
				$image_wrap_close = '';
			}

			if ( $is_inline_video ) {
				echo sprintf( '<div class="' . $item_class . ' c-product__slider-item--video">%s%s%s</div>', $image_wrap_open, '', $image_wrap_close );
			} else {
				echo sprintf( '<div class="' . $item_class . ' c-product__slider-item--video">%s%s%s</div>', $image_wrap_open, '<span class="c-product__slider--video" style="background-image: url(' . $image['image_url'] . ')"><span class="c-product__video-mask"></span></span><i class="c-play c-play--large c-play--disabled"></i>', $image_wrap_close );
			}

		} else {
			if ( ideapark_mod( 'shop_product_modal' ) ) {
				$image_wrap_open  = sprintf( '<a download href="%s" class="c-product__image-link %s" data-size="%sx%s" data-index="%s" data-product-id="%s" data-elementor-open-lightbox="no" onclick="return false;">', esc_url( $image['full'][0] ), implode( ' ', $link_class ), intval( $image['full'][1] ), intval( $image['full'][2] ), $index ++, $product_id );
				$image_wrap_close = ( ideapark_mod( 'product_page_layout' ) != 'layout-4' ? '<span class="c-product__round"></span>' : '' ) . '<span class="c-product__loading js-loading-wrap"></span></a>';
			} elseif ( ideapark_mod( '_is_quickview' ) ) {
				$image_wrap_open  = '';
				$image_wrap_close = '';
			} else {
				$image_wrap_open  = '';
				$image_wrap_close = '';
			}

			if ( ! ideapark_mod( '_is_quickview' ) && ideapark_mod( 'shop_product_zoom' ) ) {
				$image_wrap_open  .= sprintf( '<div data-img="%s" class="c-product__image-zoom js-product-zoom %s">', esc_url( $image['full'][0] ), ideapark_mod( 'shop_product_zoom_mobile_hide' ) ? 'js-product-zoom--mobile-hide' : '' );
				$image_wrap_close = "</div>" . $image_wrap_close;
			}

			if ( ideapark_mod( '_is_quickview' ) && ideapark_mod( 'quickview_product_zoom' ) ) {
				$image_wrap_open  .= sprintf( '<div data-img="%s" class="c-product__image-zoom js-product-zoom %s">', esc_url( $image['full'][0] ), ideapark_mod( 'quickview_product_zoom_mobile_hide' ) ? 'js-product-zoom--mobile-hide' : '' );
				$image_wrap_close = "</div>" . $image_wrap_close;
			}

			if ( ! ideapark_mod( '_is_quickview' ) && ideapark_mod( 'shop_product_zoom' ) && ideapark_mod( 'shop_product_modal' ) && ! ideapark_mod( 'shop_product_zoom_mobile_hide' ) ) {
				$image_wrap_close .= "<button class='h-cb c-product__image-zoom-mobile js-mobile-modal'><i class='ip-zoom'></i></button>";
			}

			echo apply_filters( 'woocommerce_single_product_image_html', sprintf( '<div class="' . $item_class . ' ' . ( ideapark_mod( 'shop_product_modal' ) ? 'c-product__slider-item--zoom' : '' ) . ' woocommerce-product-gallery__image"  data-thumb-alt="%s">%s%s%s</div>', isset( $image['alt'] ) ? esc_attr( $image['alt'] ) : '', $image_wrap_open, $image['image'], $image_wrap_close ), $product_id );
		}
	}
} else {
	$item_class = "c-product__slider-item";
	if ( ideapark_mod( 'single_image_first_row' ) && ( $product_page_layout == 'layout-1' || $product_page_layout == 'layout-2' )) {
		$item_class .= ' c-product__slider-item--single-in-row';
	}
	echo apply_filters( 'woocommerce_single_product_image_html', sprintf( '<div class="' . $item_class . '"><img src="%s" alt="%s" /></div>', wc_placeholder_img_src( 'woocommerce_single' ), esc_attr__( 'Placeholder', 'woocommerce' ) ), $product_id );
}
$images_html = ob_get_clean();

$hash = ideapark_mod( '_images_hash' );
?>

<?php if ( ideapark_mod( '_is_quickview' ) ) { ?>
	<div
		data-hash="<?php echo esc_attr( $hash ); ?>"
		class="c-product__slider h-fade c-product__slider--quick-view c-product__slider--carousel h-carousel h-carousel--inner h-carousel--round h-carousel--hover h-carousel--dots-hide h-carousel--default-dots js-single-product-carousel">
		<?php echo ideapark_wrap( $images_html ); ?>
	</div>
<?php } else { ?>

	<?php if ( $product_page_layout == 'layout-1' || $product_page_layout == 'layout-2' ) { ?>
		<div
			data-hash="<?php echo esc_attr( $hash ); ?>"
			data-cnt="<?php echo sizeof( $images ); ?>"
			class="c-product__slider woocommerce-product-gallery h-fade c-product__slider--list c-product__slider--<?php echo sizeof( $images ); ?> h-carousel h-carousel--inner h-carousel--hover h-carousel--round js-single-product-carousel h-carousel--dots-hide">
			<?php echo ideapark_wrap( $images_html ); ?>
		</div>
	<?php } else { ?>
		<div
			data-hash="<?php echo esc_attr( $hash ); ?>"
			data-layout="<?php echo esc_attr( $product_page_layout ); ?>"
			data-cnt="<?php echo sizeof( $images ); ?>"
			class="c-product__slider <?php if ($product_page_layout == 'layout-3' && ideapark_mod( 'single_image_carousel' )) { ?>c-product__slider--single-mobile<?php } ?> woocommerce-product-gallery h-fade c-product__slider--<?php echo sizeof( $images ); ?> c-product__slider--<?php if ( $product_page_layout == 'layout-4' ) { ?>carousel h-carousel--dots-hide<?php } else { ?>carousel-wide h-carousel--default-dots<?php } ?> h-carousel h-carousel--inner h-carousel--hover h-carousel--round js-single-product-carousel">
			<?php echo ideapark_wrap( $images_html ); ?>
		</div>
	<?php } ?>

	<?php if ( sizeof( $images ) > 1 && $product_page_layout != 'layout-3' ) { ?>
		<div class="c-product__thumbs-outer">
			<div
				data-layout="<?php echo esc_attr( $product_page_layout ); ?>"
				data-cnt="<?php echo esc_attr( sizeof( $images ) ); ?>"
				class="c-product__thumbs h-fade c-product__thumbs--<?php echo esc_attr( sizeof( $images ) ); ?> h-carousel h-carousel--nav-hide h-carousel--dots-hide js-product-thumbs-carousel">
				<?php foreach ( $images as $ii => $image ) { ?>
					<?php echo apply_filters( 'woocommerce_single_product_image_thumbnail_html',
						sprintf( '<div class="c-product__thumbs-item ' . ( ! $ii ? 'active' : '' ) . '"><button type="button" class="h-cb js-single-product-thumb %s" data-index="%s" %s>%s</button></div>',
							! empty( $image['thumb_url'] ) ? 'c-product__thumbs-video' : '',
							$ii,
							! empty( $image['thumb_url'] ) ? 'style="background-image: url(' . $image['thumb_url'] . ')"' : '',
							! empty( $image['video_url'] ) ? '<i class="c-play c-play--thumb"></i>' : $image['thumb']
						), ! empty( $image['attachment_id'] ) ? $image['attachment_id'] : 0, $product_id ); ?>
				<?php } ?>
			</div>
		</div>
	<?php } ?>
<?php } ?>
