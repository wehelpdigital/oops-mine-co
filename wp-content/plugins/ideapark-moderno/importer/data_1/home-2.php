<?php
defined( 'ABSPATH' ) || exit;

global $theme_home;

$home_page_id   = ( $page = ideapark_get_page_by_title( 'Home 2' ) ) ? $page->ID : 0;
$footer_page_id = ( $page = ideapark_get_page_by_title( 'Footer 2', OBJECT, 'html_block' ) ) ? $page->ID : 0;

$mods                    = [];
$mods['header_blocks_1'] = 'social=1(top-left)|other=1(top-left)|logo=1(top-center)|lang=1(top-right)|buttons=1(top-right)|phone=1(bottom-left)|email=1(bottom-left)|menu=1(bottom-center)|address=1(bottom-right)|hours=1(bottom-right)';

if ( $footer_page_id ) {
	$mods['footer_page'] = $footer_page_id;
}

$options = [];
if ( $home_page_id ) {
	$options['page_on_front'] = $home_page_id;
}

$theme_home = [
	'title'      => __( 'Home 2', 'ideapark-moderno' ),
	'screenshot' => 'home-2.jpg',
	'url'        => 'https://parkofideas.com/moderno/demo/home-2/',
	'mods'       => $mods,
	'options'    => $options,
];