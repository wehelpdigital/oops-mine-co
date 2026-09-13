<?php
/**
 * Moderno Child — functions and definitions.
 *
 * @package moderno-child
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Enqueue the child stylesheet AFTER the parent's CSS.
 *
 * The parent enqueues its (combined) stylesheet as `ideapark-core` on
 * wp_enqueue_scripts at priority 999 (see ideapark_scripts_load), so we hook
 * at 1000 and declare it as a dependency to guarantee cascade order.
 */
function moderno_child_enqueue_styles() {
	$style = get_stylesheet_directory() . '/style.css';

	wp_enqueue_style(
		'moderno-child-style',
		get_stylesheet_directory_uri() . '/style.css',
		[ 'ideapark-core' ],
		file_exists( $style ) ? (string) filemtime( $style ) : wp_get_theme()->get( 'Version' )
	);
}
add_action( 'wp_enqueue_scripts', 'moderno_child_enqueue_styles', 1000 );

/**
 * Carry the parent's Customizer settings over the first time the child is
 * activated (mirrors ideapark_migrate_mods_child() in the parent's installer).
 *
 * theme_mods are stored per stylesheet slug, so without this a switch from
 * "moderno" to "moderno-child" would start from a blank Customizer.
 * Only runs when the child has no mods of its own yet — never overwrites.
 */
function moderno_child_migrate_parent_mods() {
	$child_key  = 'theme_mods_' . get_stylesheet();
	$parent_key = 'theme_mods_' . get_template();

	if ( false !== get_option( $child_key ) ) {
		return; // Child already has settings — leave them alone.
	}

	$parent_mods = get_option( $parent_key );

	if ( is_array( $parent_mods ) && ! empty( $parent_mods ) ) {
		update_option( $child_key, $parent_mods );
	}
}
add_action( 'after_switch_theme', 'moderno_child_migrate_parent_mods' );
