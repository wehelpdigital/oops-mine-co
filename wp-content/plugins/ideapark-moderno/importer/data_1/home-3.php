<?php
defined( 'ABSPATH' ) || exit;

global $theme_home;

$home_page_id   = ( $page = ideapark_get_page_by_title( 'Home 3' ) ) ? $page->ID : 0;
$footer_page_id = ( $page = ideapark_get_page_by_title( 'Footer White', OBJECT, 'html_block' ) ) ? $page->ID : 0;
$logo_id        = ( $page = ideapark_get_page_by_title( 'moderno-981', OBJECT, 'attachment' ) ) ? $page->ID : 0;

$mods                       = [];
$mods['header_blocks_1']    = 'logo=1(top-left)|menu=1(top-center)|buttons=1(top-right)|social=0|phone=0|email=0|address=0|hours=0|lang=0|other=0';
$mods['transparent_header'] = true;

if ( $footer_page_id ) {
	$mods['footer_page'] = $footer_page_id;
}

$options = [];
if ( $home_page_id ) {
	$options['page_on_front'] = $home_page_id;
}

if ( $logo_id ) {
	$control_name                              = 'logo_sticky';
	$params                                    = wp_get_attachment_image_src( $logo_id, 'full' );
	$mods[ $control_name ]                     = wp_get_attachment_url( $logo_id );
	$mods[ $control_name . '__url' ]           = $params[0];
	$mods[ $control_name . '__attachment_id' ] = $logo_id;
	$mods[ $control_name . '__width' ]         = $params[1];
	$mods[ $control_name . '__height' ]        = $params[2];
}

$theme_home = [
	'title'      => __( 'Home 3', 'ideapark-moderno' ),
	'screenshot' => 'home-3.jpg',
	'url'        => 'https://parkofideas.com/moderno/demo/home-3/',
	'mods'       => $mods,
	'options'    => $options,
];