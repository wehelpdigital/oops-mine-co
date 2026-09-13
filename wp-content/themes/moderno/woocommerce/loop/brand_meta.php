<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( $terms = ideapark_brands() ) {
	$brands = [];
	foreach ( $terms as $term ) {
		$brands[] = '<a href="' . esc_url( get_term_link( $term->term_id, ideapark_mod( 'product_brand_attribute' ) ) ) . '">' . esc_html( $term->name ) . '</a>';
	}
	$brands = array_filter( $brands );
	$tax    = get_taxonomy( ideapark_mod( 'product_brand_attribute' ) );
	echo ideapark_wrap( implode( ', ', $brands ), '<div class="c-product__brands">' . esc_html( apply_filters( 'wpml_translate_single_string', $tax->labels->singular_name, IDEAPARK_NAME, "attribute name: " . $tax->labels->singular_name ) ) . ': ', '</div>' );
}