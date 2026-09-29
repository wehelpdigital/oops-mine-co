<?php
/**
 * Front-end weight: stop shipping code no page on this site uses.
 *
 * Two plugins load on every request and neither is used by the front end:
 *
 *   Elementor        every page carried six stylesheets, three scripts and two Google Fonts
 *                    requests (Roboto and Roboto Slab, every weight, italics included) for a
 *                    design built on Cormorant Garamond and Manrope. Everything in this install
 *                    that Elementor built is either a draft or one of the theme demo's html_blocks,
 *                    and the child theme replaced the Elementor footer with plain markup.
 *
 *   Contact Form 7   a stylesheet and two scripts, plus the wp-polyfill / api-fetch chain they pull
 *                    behind them. Three forms exist from the demo; no published page contains the
 *                    shortcode. The contact page posts to the child theme's own endpoint.
 *
 * Nothing is disabled outright. Elementor's assets come back the moment a page is actually built
 * with it, and Contact Form 7's the moment a form appears in the content — so this stays correct if
 * someone builds a page next week. Both can be turned off with a filter.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

/* ─────────────────────────── Elementor ─────────────────────────── */

/**
 * Is the thing being viewed actually built with Elementor?
 *
 * Checked against the queried object rather than a global switch: a page the owner builds later
 * keeps its styles without anyone having to remember this file exists.
 */
function omc_perf_needs_elementor() {
	if ( ! did_action( 'elementor/loaded' ) ) {
		return false;
	}
	if ( is_admin() || is_embed() || ( function_exists( 'is_preview' ) && is_preview() ) ) {
		return true; // never interfere with the editor or a preview
	}

	$needed = false;
	if ( is_singular() ) {
		$id     = get_queried_object_id();
		$needed = $id && 'builder' === get_post_meta( $id, '_elementor_edit_mode', true );
	}

	/**
	 * Filter whether Elementor's front-end assets are needed on this request.
	 *
	 * @param bool $needed True to load Elementor as usual.
	 */
	return (bool) apply_filters( 'omc_needs_elementor', $needed );
}

/**
 * Take Elementor's handles out of the queue on pages it has nothing to do with.
 *
 * Filtered at print time rather than dequeued: Elementor adds its per-post sheets (post-6.css,
 * post-505.css — the demo kit and the theme's Elementor footer page, neither of which this site
 * renders) after wp_enqueue_scripts has run, so an earlier pass misses them. Deregistering instead
 * would leave those late sheets depending on a handle that no longer exists, which WordPress
 * reports as a doing-it-wrong notice on every request.
 *
 * @param array $handles Handles about to be printed.
 * @return array
 */
function omc_perf_strip_elementor( $handles ) {
	if ( is_admin() || omc_perf_needs_elementor() ) {
		return $handles;
	}
	return array_values( array_filter(
		(array) $handles,
		static function ( $handle ) {
			// Its own handles, the per-widget sheets it registers unprefixed, and the Google faces.
			return ! (
				0 === strpos( $handle, 'elementor' )
				|| 0 === strpos( $handle, 'widget-' )
				|| 0 === strpos( $handle, 'google-fonts' )
			);
		}
	) );
}
add_filter( 'print_styles_array', 'omc_perf_strip_elementor' );
add_filter( 'print_scripts_array', 'omc_perf_strip_elementor' );

/*
 * Roboto and Roboto Slab, both requested from Google with every weight and both italics, for a
 * shop that uses neither. This kills the request even on a page that does use Elementor: the two
 * brand faces are already loaded by the child theme, and an Elementor page here should use those.
 */
add_filter( 'elementor/frontend/print_google_fonts', '__return_false' );

/* ─────────────────────────── Contact Form 7 ─────────────────────────── */

/**
 * Does the content being shown actually contain a Contact Form 7 form?
 *
 * The page has not been rendered yet at enqueue time, so this reads post_content — which is where
 * the shortcode would be. A form dropped in by a widget or a template call is covered by the
 * `omc_needs_cf7` filter.
 */
function omc_perf_needs_cf7() {
	$needed = false;
	if ( is_singular() ) {
		$post   = get_post( get_queried_object_id() );
		$needed = $post && (
			has_shortcode( (string) $post->post_content, 'contact-form-7' )
			|| false !== strpos( (string) $post->post_content, 'wpcf7' )
		);
	}

	/**
	 * Filter whether Contact Form 7's assets are needed on this request.
	 *
	 * @param bool $needed True to load Contact Form 7 as usual.
	 */
	return (bool) apply_filters( 'omc_needs_cf7', $needed );
}

/** Contact Form 7's own switches, which it checks before enqueuing anything. */
function omc_perf_cf7_assets() {
	if ( is_admin() || omc_perf_needs_cf7() ) {
		return;
	}
	add_filter( 'wpcf7_load_css', '__return_false' );
	add_filter( 'wpcf7_load_js', '__return_false' );
}
add_action( 'wp', 'omc_perf_cf7_assets' );

/**
 * Belt and braces: some versions enqueue on init, before `wp` has run and before those filters
 * exist to be read.
 *
 * @param array $handles Handles about to be printed.
 * @return array
 */
function omc_perf_strip_cf7( $handles ) {
	if ( is_admin() || omc_perf_needs_cf7() ) {
		return $handles;
	}
	return array_values( array_diff( (array) $handles, [ 'contact-form-7', 'swv' ] ) );
}
add_filter( 'print_styles_array', 'omc_perf_strip_cf7' );
add_filter( 'print_scripts_array', 'omc_perf_strip_cf7' );
