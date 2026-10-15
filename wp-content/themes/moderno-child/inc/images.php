<?php
/**
 * Title attributes on images, because a crawl asked for them.
 *
 * Worth saying plainly: the HTML title attribute on an image is not a ranking signal. Google
 * reads alt; title shows a tooltip on hover, is unavailable to anyone using a keyboard or a
 * touchscreen, and when it repeats the alt text a screen reader may read the same sentence
 * twice. Crawlers flag it as missing because it is easy to count, not because it is costing
 * anything. It is added here because it was asked for, it does no harm done this way, and a
 * clean report is worth something of its own.
 *
 * Two rules keep it harmless:
 *
 *   - a decorative picture never gets one. Those carry alt="" and aria-hidden on purpose — the
 *     hero's cross-fade slides behind the first, the washed-out photographs behind the story,
 *     the newsletter band and the quotes band. A tooltip on a background is noise.
 *   - the title is the attachment's own title, not a copy of the alt text. Where they would say
 *     the same thing, the attribute is left off rather than repeated.
 *
 * Switch the whole thing off with: add_filter( 'omc_image_titles', '__return_false' );
 *
 * @package Moderno Child
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add a title to an image WordPress is rendering.
 *
 * @param array        $attr       The attributes about to be printed.
 * @param WP_Post|null $attachment The picture.
 * @return array
 */
function omc_image_title_attr( $attr, $attachment = null ) {
	if ( ! apply_filters( 'omc_image_titles', true ) || is_admin() ) {
		return $attr;
	}
	if ( ! empty( $attr['title'] ) ) {
		return $attr;
	}

	/* Decorative: alt is deliberately empty, or the picture is hidden from the accessibility
	   tree. Either way there is nothing a tooltip could usefully say. */
	if ( '' === trim( (string) ( $attr['alt'] ?? '' ) ) ) {
		return $attr;
	}
	if ( ! empty( $attr['aria-hidden'] ) && 'false' !== $attr['aria-hidden'] ) {
		return $attr;
	}

	$title = $attachment instanceof WP_Post ? trim( (string) $attachment->post_title ) : '';
	if ( '' === $title ) {
		return $attr;
	}

	// Saying the same thing twice helps nobody; alt already carries it.
	if ( 0 === strcasecmp( $title, trim( (string) $attr['alt'] ) ) ) {
		return $attr;
	}

	$attr['title'] = $title;

	return $attr;
}
add_filter( 'wp_get_attachment_image_attributes', 'omc_image_title_attr', 20, 2 );

/**
 * The same for pictures inside post content, which WordPress renders as plain markup.
 *
 * `wp_content_img_tag` hands over the finished tag, so the attribute is spliced in rather than
 * set — and only when the tag has alt text and no title of its own.
 *
 * @param string $html The <img> tag.
 * @param string $context Where it is being rendered.
 * @param int    $id   Attachment id, 0 when WordPress could not work it out.
 * @return string
 */
function omc_content_img_title( $html, $context = '', $id = 0 ) {
	if ( ! apply_filters( 'omc_image_titles', true ) || is_admin() || ! $id ) {
		return $html;
	}
	if ( false !== stripos( $html, ' title=' ) || false !== stripos( $html, 'aria-hidden="true"' ) ) {
		return $html;
	}
	if ( ! preg_match( '/\salt=(["\'])(.*?)\1/i', $html, $m ) || '' === trim( $m[2] ) ) {
		return $html; // no alt, or decorative
	}

	$title = trim( (string) get_the_title( $id ) );
	if ( '' === $title || 0 === strcasecmp( $title, trim( $m[2] ) ) ) {
		return $html;
	}

	return preg_replace( '/<img\s/i', '<img title="' . esc_attr( $title ) . '" ', $html, 1 );
}
add_filter( 'wp_content_img_tag', 'omc_content_img_title', 20, 3 );
