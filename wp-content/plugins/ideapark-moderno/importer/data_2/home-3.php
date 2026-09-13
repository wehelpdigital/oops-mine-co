<?php
defined( 'ABSPATH' ) || exit;

global $theme_home;

$home_page_id   = ( $page = ideapark_get_page_by_title( 'Home 3 Furniture' ) ) ? $page->ID : 0;

$mods                       = [];
$mods['header_advert_bar_page'] = 0;
$mods['header_blocks_1']        = 'logo=1(top-left)|menu=1(top-center)|buttons=1(top-right)|social=0|phone=0|email=0|address=0|hours=0|lang=0|other=0';


$options = [];
if ( $home_page_id ) {
	$options['page_on_front'] = $home_page_id;
}

$theme_home = [
	'title'      => __( 'Furniture Home 3', 'ideapark-moderno' ),
	'screenshot' => 'home-3.jpg',
	'url'        => 'https://parkofideas.com/moderno/furniture/home-3/',
	'mods'       => $mods,
	'options'    => $options,
];