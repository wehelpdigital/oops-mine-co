<?php

if ( is_front_page() && ! is_home() && ! ideapark_is_shop() ) {
	return '';
}

$header_class  = '';
$header_height = '';
$title_align   = '';

extract( ideapark_header_params() );

if ( ideapark_mod( '_header_height' ) ) {
	$header_height = ideapark_mod( '_header_height' );
}

$show_breadcrumbs = ! ideapark_mod( '_header_hide_breadcrumbs' ) && $header_height !== 'hide' && $header_height !== 'no-breadcrumbs' && ! ( ideapark_woocommerce_on() && is_product() );
$show_title       = ! ideapark_mod( '_header_hide_title' ) && $header_height !== 'hide' && $header_height !== 'no-title';
$show_ordering    = ideapark_woocommerce_on() && wc_get_loop_prop( 'is_paginated' ) && woocommerce_products_will_display();
$header_class_add = ideapark_mod( '_header_class_add' );
$is_fullwidth     = false;
$is_centered      = false;
$is_h1            = true;
$title            = '';
$filter           = '';
$width_class      = '';
ob_start();
$show_categories = function_exists( 'ideapark_header_categories' ) && ideapark_header_categories();
$categories      = ob_get_clean();

if ( is_front_page() && ideapark_is_shop() ) {
	$show_breadcrumbs = false;
	$show_title       = false;
}

if ( $show_title ) {
	if ( ideapark_woocommerce_on() && is_account_page() ) {
		if ( ! is_user_logged_in() || isset( $wp->query_vars['lost-password'] ) ) {
			$is_h1 = false;
		} else {
			$endpoint = WC()->query->get_current_endpoint();
			$action   = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
			$title    = WC()->query->get_endpoint_title( $endpoint, $action );
		}
	} elseif ( ideapark_is_shop() && ! $title ) {
		if ( is_search() ) {
			$title = esc_html__( 'Search:', 'moderno' ) . ' ' . esc_html( get_search_query( false ) );
		} else {
			$shop_page_id = wc_get_page_id( 'shop' );
			$title        = get_the_title( $shop_page_id );
		}
	} elseif ( ideapark_woocommerce_on() && is_woocommerce() && ! $title ) {
		if ( is_product() ) {
			$is_h1 = false;
			$is_fullwidth = ideapark_mod( 'product_category_carousel' );
		} else {
			$title = woocommerce_page_title( false );
		}
	} elseif ( is_404() ) {
		$title = esc_html__( 'Page not found', 'moderno' );
	} elseif ( is_single() ) {
		if ( ! $title ) {
			if ( ideapark_woocommerce_on() && is_product() ) {
				$is_h1 = false;
				$title = '';
			} else {
				$title = ( is_sticky() ? '<i class="ip-sticky c-page-header__sticky"><!-- --></i>' : '' ) . get_the_title();
			}
		}
	} elseif ( is_search() && ! $title ) {
		$is_fullwidth = ideapark_mod( 'post_layout' ) == 'grid';
		$found_posts  = $wp_query->found_posts;
		if ( $found_posts ) {
			$title = esc_html__( 'Search:', 'moderno' ) . ' ' . esc_html( get_search_query( false ) );
		} else {
			$title = esc_html__( 'No search results for:', 'moderno' ) . ' ' . esc_html( get_search_query( false ) );
		}
	} elseif ( is_archive() ) {

		$is_fullwidth = ideapark_mod( 'post_layout' ) == 'grid';

		if ( ! $title ) {
			if ( is_category() ) {
				$title = single_cat_title( '', false );
			} elseif ( is_tax() ) {
				$title = single_term_title( '', false );
			} elseif ( is_tag() ) {
				$title = single_tag_title( '', false );
			} elseif ( is_author() ) {
				the_post();
				$title = get_the_author();
				rewind_posts();
			} elseif ( is_day() ) {
				$title = get_the_date();
			} elseif ( is_month() ) {
				$title = get_the_date( 'F Y' );
			} elseif ( is_year() ) {
				$title = get_the_date( 'Y' );
			} else {
				$queried_object = get_queried_object();
				$title          = isset( $queried_object->labels->name ) ? $queried_object->labels->name : esc_html__( 'Archives', 'moderno' );
			}
		}
	} elseif ( is_home() && get_option( 'page_for_posts' ) && 'page' == get_option( 'show_on_front' ) && ! $title ) {
		$is_fullwidth = ideapark_mod( 'post_layout' ) == 'grid';
		$title        = get_the_title( get_option( 'page_for_posts' ) );
	} elseif ( is_front_page() && get_option( 'page_on_front' ) && 'page' == get_option( 'show_on_front' ) && ! $title ) {
		$title = get_the_title( get_option( 'page_on_front' ) );
	} elseif ( is_home() && ! $title ) {
		$is_fullwidth = ideapark_mod( 'post_layout' ) == 'grid';
		$title        = esc_html__( 'Posts', 'moderno' );
	} elseif ( ideapark_woocommerce_on() && ( is_cart() && WC()->cart->is_empty() || is_checkout() && isset( $wp->query_vars['order-received'] ) ) || ideapark_is_wishlist_page() && ! ideapark_wishlist()->ids() ) {
		$is_h1 = false;
	}

	if ( ! $title && $is_h1 ) {
		$title = get_the_title();
	}

	if ( is_search() && ! have_posts() ) {
		$is_centered = true;
	} elseif ( is_page() || is_single() ) {
		$is_fullwidth = $is_fullwidth || ( $title_align == 'left-fullwidth' );
		$is_centered  = ! in_array( $title_align, [ 'left-boxed', 'left-fullwidth' ] );
		if ( $title_align == 'default' && is_single() && ideapark_mod( 'sidebar_post' ) && is_active_sidebar( 'post-sidebar' ) ) {
			$is_centered = false;
		}
	}
}

if ( $show_title = $show_title && $title ) {
	ob_start(); ?>
	<?php if ( $is_h1 ) { ?>
		<?php echo ideapark_wrap( $title, '<h1 class="c-page-header__title' . ( $is_centered ? ' c-page-header__title--center' : '' ) . '">', '</h1>' ); ?>
	<?php } else { ?>
		<?php echo ideapark_wrap( $title, '<div class="c-page-header__title' . ( $is_centered ? ' c-page-header__title--center' : '' ) . '">', '</div>' ); ?>
	<?php } ?>
	<?php
	$title = ob_get_clean();
}


if ( $show_breadcrumbs ) {
	ob_start();
	ideapark_get_template_part( 'templates/breadcrumbs' );
	$breadcrumbs      = trim( ob_get_clean() );
	$show_breadcrumbs = $show_breadcrumbs && $breadcrumbs;
}

if ( ideapark_woocommerce_on() && is_woocommerce() && is_archive() ) {
	$is_fullwidth = ideapark_mod( 'product_grid_width' ) == 'fullwidth';
}

if ( $filter = ideapark_filter_button( 'js-page-header-filter' ) ) {
	$show_ordering = true;
}

if ( $show_ordering || $show_breadcrumbs || $show_title || $show_categories ) { ?>
	<header
		class="l-section c-page-header c-page-header--<?php echo esc_attr( ideapark_mod( 'header_type' ) ); ?>
<?php if ( $header_class ) { ?> c-page-header--<?php echo esc_attr( $header_class ); ?><?php } ?>
<?php if ( $header_class_add ) { ?> c-page-header--<?php echo esc_attr( $header_class_add ); ?><?php } ?>
<?php if ( $header_height ) { ?> c-page-header--<?php echo esc_attr( $header_height ); ?><?php } ?>
<?php if ( ! $show_title ) { ?> c-page-header--no-title<?php } ?>">

		<?php if ( $show_ordering || $show_breadcrumbs ) {
			if ( $show_ordering && $show_breadcrumbs ) { ?>
				<div class="c-page-header__row-1 c-page-header__row-1--3-columns">
					<div
						class="c-page-header__row-1-col c-page-header__row-1-col--count"><?php woocommerce_result_count(); ?></div>
					<div
						class="c-page-header__row-1-col c-page-header__row-1-col--breadcrumbs c-page-header__row-1-col--breadcrumbs-desktop">
						<?php echo ideapark_wrap( $breadcrumbs ); ?>
					</div>
					<div class="c-page-header__row-1-col c-page-header__row-1-col--ordering">
						<?php woocommerce_catalog_ordering(); ?>
						<?php echo ideapark_wrap( $filter ); ?>
					</div>
				</div>
			<?php } elseif ( $show_ordering ) { ?>
				<div class="c-page-header__row-1 c-page-header__row-1--2-columns">
					<div
						class="c-page-header__row-1-col c-page-header__row-1-col--count"><?php woocommerce_result_count(); ?></div>
					<div class="c-page-header__row-1-col c-page-header__row-1-col--ordering">
						<?php woocommerce_catalog_ordering(); ?>
						<?php echo ideapark_wrap( $filter ); ?>
					</div>
				</div>
			<?php } elseif ( $show_breadcrumbs ) { ?>
				<div class="c-page-header__row-1 c-page-header__row-1--1-columns c-page-header__row-1-col--breadcrumbs">
					<div class="c-page-header__row-1-col">
						<?php echo ideapark_wrap( $breadcrumbs ); ?>
					</div>
				</div>
			<?php }
		}


		if ( ! $show_title && $show_breadcrumbs ) { ?>
			<div class="h-hide-desktop">
				<div class="c-page-header__row-2-col c-page-header__row-2-col--breadcrumbs">
					<?php echo ideapark_wrap( $breadcrumbs ); ?>
				</div>
				<div class="c-page-header__line"></div>
			</div>
		<?php }
		if ( $show_title || $show_categories ) { ?>
			<?php $width_class = $is_fullwidth ? 'l-section__container-wide' : 'l-section__container' ?>
			<?php if ( $show_title && $show_categories ) { ?>
				<div
					class="c-page-header__row-2 c-page-header__row-2--2-columns <?php echo ideapark_wrap( $width_class ); ?>">
					<div
						class="c-page-header__row-2-col <?php ideapark_class( $show_breadcrumbs, 'c-page-header__row-2-col--title-breadcrumbs', 'c-page-header__row-2-col--title' ); ?>">
						<?php echo ideapark_wrap( $title ); ?>
						<?php if ( $show_breadcrumbs ) { ?>
							<?php echo ideapark_wrap( $breadcrumbs ); ?>
						<?php } ?>
					</div>
					<div class="c-page-header__row-2-col c-page-header__row-2-col--categories">
						<?php echo ideapark_wrap( $categories ); ?>
					</div>
				</div>
			<?php } elseif ( $show_title ) { ?>
				<div
					class="c-page-header__row-2 c-page-header__row-2--1-columns <?php echo ideapark_wrap( $width_class ); ?>">
					<div
						class="c-page-header__row-2-col <?php ideapark_class( $show_breadcrumbs, 'c-page-header__row-2-col--title-breadcrumbs', 'c-page-header__row-2-col--title' ); ?>">
						<?php echo ideapark_wrap( $title ); ?>
						<?php if ( $show_breadcrumbs ) { ?>
							<?php echo ideapark_wrap( $breadcrumbs ); ?>
						<?php } ?>
					</div>
				</div>
			<?php } elseif ( $show_categories ) { ?>
				<div
					class="c-page-header__row-2 c-page-header__row-2--1-columns <?php echo ideapark_wrap( $width_class ); ?>">
					<div class="c-page-header__row-2-col c-page-header__row-2-col--categories">
						<?php echo ideapark_wrap( $categories ); ?>
					</div>
				</div>
			<?php } ?>

			<div class="c-page-header__line"></div>
		<?php } ?>
	</header>
	<?php
}
