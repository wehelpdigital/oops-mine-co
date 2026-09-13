<?php

$menu_locations = get_nav_menu_locations();
echo str_replace( '<nav', '<nav itemscope itemtype="http://schema.org/SiteNavigationElement"', wp_nav_menu( [
	'menu'            => ! empty( $menu_locations['mobile'] ) ? $menu_locations['mobile'] : ( ! empty( $menu_locations['primary'] ) ? $menu_locations['primary'] : '' ),
	'container'       => 'nav',
	'container_class' => 'c-mobile-menu c-mobile-menu--top-menu js-mobile-top-menu',
	'echo'            => false,
	'menu_id'         => 'mobile-top-menu',
	'menu_class'      => 'c-mobile-menu__list',
	'fallback_cb'     => '',
] ) );

// moderno