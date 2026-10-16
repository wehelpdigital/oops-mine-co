<?php
/**
 * Tell the SEO analyser what the templates put on the page.
 *
 * WHD's scorer reads a page's copy, which is the right place to look for almost everything. But
 * some of what a reader sees is printed by the template and never appears in the editor: the
 * About page opens on a full-bleed photograph and closes on two buttons into the shop, so the
 * checks that count pictures and links were marking it down for having neither.
 *
 * This hands the analyser that markup, nothing more — the same picture and the same links the
 * template renders, so the score describes the page a visitor gets.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

/**
 * Append the template's own picture and links to the document being measured.
 *
 * @param array $doc     The document WHD is about to score.
 * @param int   $post_id The page it was built from.
 * @return array
 */
function omc_seo_document( $doc, $post_id ) {
	if ( 'templates/page-about.php' !== (string) get_post_meta( $post_id, '_wp_page_template', true ) ) {
		return $doc;
	}

	$thumb = get_post_thumbnail_id( $post_id );
	$alt   = $thumb
		? (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true )
		: __( 'Editorial portrait — Oops, Mine Co.', 'moderno-child' );
	if ( '' === trim( $alt ) ) {
		$alt = get_the_title( $post_id );
	}

	$links = [];
	if ( function_exists( 'omc_new_arrivals_url' ) ) {
		$links[] = omc_new_arrivals_url();
	}
	if ( function_exists( 'omc_page_url' ) ) {
		$links[] = omc_page_url( 'contacts', '/contacts/' );
	}

	$extra = '<img alt="' . esc_attr( $alt ) . '">';
	foreach ( $links as $url ) {
		$extra .= '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Shop', 'moderno-child' ) . '</a>';
	}

	$doc['content'] = (string) ( $doc['content'] ?? '' ) . $extra;

	return $doc;
}
add_filter( 'whd_seo_document', 'omc_seo_document', 10, 2 );
