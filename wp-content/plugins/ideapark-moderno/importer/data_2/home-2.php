<?php
defined( 'ABSPATH' ) || exit;

global $theme_home;

$home_page_id   = ( $page = ideapark_get_page_by_title( 'Home 2 Furniture' ) ) ? $page->ID : 0;
$footer_page_id = ( $page = ideapark_get_page_by_title( 'Footer White', OBJECT, 'html_block' ) ) ? $page->ID : 0;

$mods = [];

if ( $footer_page_id ) {
	$mods['footer_page'] = $footer_page_id;
}

$options = [];
if ( $home_page_id ) {
	$options['page_on_front'] = $home_page_id;
}

$theme_home = [
	'title'      => __( 'Furniture Home 2', 'ideapark-moderno' ),
	'screenshot' => 'home-2.jpg',
	'url'        => 'https://parkofideas.com/moderno/furniture/home-2/',
	'mods'       => $mods,
	'options'    => $options,
];