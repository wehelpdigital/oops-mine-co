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

if ( function_exists( 'omc_is_home_template' ) && ( omc_is_home_template() || omc_is_about_template() ) ) {
	return;
}

require get_template_directory() . '/templates/page-header.php';
