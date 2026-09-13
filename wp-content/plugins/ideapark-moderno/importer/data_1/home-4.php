<?php
defined( 'ABSPATH' ) || exit;

global $theme_home;

$home_page_id   = ( $page = ideapark_get_page_by_title( 'Home 4' ) ) ? $page->ID : 0;
$footer_page_id = ( $page = ideapark_get_page_by_title( 'Footer 4', OBJECT, 'html_block' ) ) ? $page->ID : 0;
$logo_id        = ( $page = ideapark_get_page_by_title( 'moderno-981', OBJECT, 'attachment' ) ) ? $page->ID : 0;
$logo_mobile_id = ( $page = ideapark_get_page_by_title( 'moderno-44', OBJECT, 'attachment' ) ) ? $page->ID : 0;

$mods                                = [];
$mods['header_top_background_color'] = '#A1ACAF';
$mods['header_top_color']            = '#FFFFFF';
$mods['header_top_first_line']       = '#B6BDBF';
$mods['header_top_second_line']      = '#A1ACAF';

if ( $footer_page_id ) {
	$mods['footer_page'] = $footer_page_id;
}

$options = [];
if ( $home_page_id ) {
	$options['page_on_front'] = $home_page_id;
}

if ( $logo_id ) {
	$control_name                              = 'logo';
	$params                                    = wp_get_attachment_image_src( $logo_id, 'full' );
	$mods[ $control_name ]                     = wp_get_attachment_url( $logo_id );
	$mods[ $control_name . '__url' ]           = $params[0];
	$mods[ $control_name . '__attachment_id' ] = $logo_id;
	$mods[ $control_name . '__width' ]         = $params[1];
	$mods[ $control_name . '__height' ]        = $params[2];
}

if ( $logo_mobile_id ) {
	$control_name                              = 'logo_mobile';
	$params                                    = wp_get_attachment_image_src( $logo_mobile_id, 'full' );
	$mods[ $control_name ]                     = wp_get_attachment_url( $logo_mobile_id );
	$mods[ $control_name . '__url' ]           = $params[0];
	$mods[ $control_name . '__attachment_id' ] = $logo_mobile_id;
	$mods[ $control_name . '__width' ]         = $params[1];
	$mods[ $control_name . '__height' ]        = $params[2];
}

$theme_home = [
	'title'      => __( 'Home 4', 'ideapark-moderno' ),
	'screenshot' => 'home-4.jpg',
	'url'        => 'https://parkofideas.com/moderno/demo/home-4/',
	'mods'       => $mods,
	'options'    => $options,
];