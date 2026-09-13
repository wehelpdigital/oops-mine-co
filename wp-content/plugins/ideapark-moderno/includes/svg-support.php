<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'ideapark_moderno_mime_types' ) ) {
	function ideapark_moderno_mime_types( $mimes ) {
		if ( current_user_can( 'administrator' ) ) {
			$mimes['svg']  = 'image/svg+xml';
			$mimes['svgz'] = 'image/svg+xml';
		}

		return $mimes;
	}

	add_filter( 'upload_mimes', 'ideapark_moderno_mime_types' );
}

if ( ! function_exists( 'ideapark_moderno_ignore_upload_ext' ) ) {
	function ideapark_moderno_ignore_upload_ext( $checked, $file, $filename, $mimes ) {

		if ( ! $checked['type'] ) {
			$wp_filetype     = wp_check_filetype( $filename, $mimes );
			$ext             = $wp_filetype['ext'];
			$type            = $wp_filetype['type'];
			$proper_filename = $filename;

			if ( $type && 0 === strpos( $type, 'image/' ) && $ext !== 'svg' ) {
				$ext = $type = false;
			}

			$checked = compact( 'ext', 'type', 'proper_filename' );
		}

		return $checked;
	}

	add_filter( 'wp_check_filetype_and_ext', 'ideapark_moderno_ignore_upload_ext', 10, 4 );
}

if ( ! function_exists( 'ideapark_moderno_svgs_display_thumbs' ) ) {
	function ideapark_moderno_svgs_display_thumbs() {
		$screen = get_current_screen();
		if ( is_object( $screen ) && $screen->id == 'upload' ) {
			function ideapark_svgs_thumbs_filter( $content ) {
				return apply_filters( 'final_output', $content );
			}

			ob_start( 'ideapark_svgs_thumbs_filter' );
			add_filter( 'final_output', 'ideapark_svgs_final_output' );
			function ideapark_svgs_final_output( $content ) {
				$content = str_replace(
					'<# } else if ( \'image\' === data.type && data.sizes && data.sizes.full ) { #>',
					'<# } else if ( \'svg+xml\' === data.subtype ) { #>
					<img class="details-image" src="{{ data.url }}" draggable="false" />
					<# } else if ( \'image\' === data.type && data.sizes && data.sizes.full ) { #>',

					$content
				);
				$content = str_replace(
					'<# } else if ( \'image\' === data.type && data.sizes ) { #>',
					'<# } else if ( \'svg+xml\' === data.subtype ) { #>
					<div class="centered">
						<img src="{{ data.url }}" class="thumbnail" draggable="false" />
					</div>
					<# } else if ( \'image\' === data.type && data.sizes ) { #>',

					$content
				);

				return $content;
			}
		}
	}

	add_action( 'current_screen', 'ideapark_moderno_svgs_display_thumbs', 1000 );
}

if ( ! function_exists( 'ideapark_moderno_svgs_get_dimensions' ) ) {
	function ideapark_moderno_svgs_get_dimensions( $svg ) {
		$svg = simplexml_load_file( $svg );
		if ( $svg === false ) {
			$width  = '0';
			$height = '0';
		} else {
			$attributes = $svg->attributes();
			$width      = (string) $attributes->width;
			$height     = (string) $attributes->height;
			if ( ! $width || ! $height && ( $view_box = (string) $attributes->viewBox ) && preg_match( '~(\d+) (\d+) (\d+) (\d+)~', $view_box, $match ) ) {
				$width  = $match[3] - $match[1];
				$height = $match[4] - $match[2];
			}
		}

		return (object) [ 'width' => $width, 'height' => $height ];
	}
}

if ( ! function_exists( 'ideapark_moderno_svgs_response_for_svg' ) ) {
	function ideapark_moderno_svgs_response_for_svg( $response, $attachment, $meta ) {
		if ( $response['mime'] == 'image/svg+xml' && empty( $response['sizes'] ) ) {
			$svg_path = get_attached_file( $attachment->ID );
			if ( ! file_exists( $svg_path ) ) {
				$svg_path = $response['url'];
			}
			$dimensions        = ideapark_moderno_svgs_get_dimensions( $svg_path );
			$response['sizes'] = [
				'full' => [
					'url'         => $response['url'],
					'width'       => $dimensions->width,
					'height'      => $dimensions->height,
					'orientation' => $dimensions->width > $dimensions->height ? 'landscape' : 'portrait'
				]
			];
		}

		return $response;
	}

	add_filter( 'wp_prepare_attachment_for_js', 'ideapark_moderno_svgs_response_for_svg', 10, 3 );
}

if ( ! function_exists( 'ideapark_moderno_fix_wp_get_attachment_image_svg' ) ) {
	function ideapark_moderno_fix_wp_get_attachment_image_svg( $image, $attachment_id, $size, $icon ) {
		if ( is_array( $image ) && preg_match( '/\.svg$/i', $image[0] ) && $image[1] <= 1 ) {
			if ( is_array( $size ) ) {
				$image[1] = $size[0];
				$image[2] = $size[1];
			} else {
				$attachment_meta_data = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
				if ( ! empty( $attachment_meta_data['width'] ) && ! empty( $attachment_meta_data['height'] ) ) {
					$image[1] = $attachment_meta_data['width'];
					$image[2] = $attachment_meta_data['height'];
				} else {
					if ( ( $path = get_attached_file( $attachment_id ) ) && function_exists( 'simplexml_load_string' ) && ( $xml = simplexml_load_string( ideapark_fgc( $path ) ) ) !== false ) {
						$attr     = $xml->attributes();
						$viewbox  = explode( ' ', $attr->viewBox );
						$image[1] = isset( $attr->width ) && preg_match( '/\d+/', $attr->width, $value ) ? (int) $value[0] : ( count( $viewbox ) == 4 ? (int) $viewbox[2] : null );
						$image[2] = isset( $attr->height ) && preg_match( '/\d+/', $attr->height, $value ) ? (int) $value[0] : ( count( $viewbox ) == 4 ? (int) $viewbox[3] : null );
						if ( ! is_array( $attachment_meta_data ) ) {
							$attachment_meta_data = [];
						}
						$attachment_meta_data['width']  = $image[1];
						$attachment_meta_data['height'] = $image[2];
						update_post_meta( $attachment_id, '_wp_attachment_metadata', $attachment_meta_data );
					} else {
						$image[1] = $image[2] = null;
					}
				}
			}
		}

		return $image;
	}

	add_filter( 'wp_get_attachment_image_src', 'ideapark_moderno_fix_wp_get_attachment_image_svg', 10, 4 );
}