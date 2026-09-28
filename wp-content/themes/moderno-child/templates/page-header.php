<?php
/**
 * Child override of the parent's templates/page-header.php.
 *
 * The OMC page templates (Home, About) render their own hero with the page's
 * single <h1>, so the theme's title band must not print there (it would add a
 * second <h1> and breadcrumbs above the hero). Every other page keeps the
 * parent's behaviour untouched — we simply defer to the parent file.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

$omc_own_hero = [
	'templates/page-home.php',
	'templates/page-about.php',
	'templates/page-video.php',
	'templates/page-videos.php',
	'templates/page-landing.php',
	'templates/page-faq.php',
	'templates/page-contact.php',
	'templates/page-policy.php',
];

foreach ( $omc_own_hero as $omc_tpl ) {
	if ( is_page_template( $omc_tpl ) ) {
		return;
	}
}

/*
 * The parent suppresses the title on an empty cart and on a logged-out account screen, which leaves
 * those pages opening on an empty band. Both are real pages with real titles, so say what they are.
 */
$omc_untitled = function_exists( 'is_cart' )
	&& ( ( is_cart() && WC()->cart && WC()->cart->is_empty() )
		|| ( function_exists( 'is_account_page' ) && is_account_page() && ! is_user_logged_in() ) );

if ( $omc_untitled && function_exists( 'omc_bare_header_band' ) ) {
	omc_bare_header_band( get_the_title( get_queried_object_id() ) );
	return;
}

require get_template_directory() . '/templates/page-header.php';
