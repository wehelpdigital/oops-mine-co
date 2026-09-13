<?php
global $post;

if ( $post ) {
	$post_id = $post->ID;
	if ( ideapark_is_elementor_page( $post_id ) ) {
		ob_start();
		\Elementor\Plugin::$instance->modules_manager->get_modules( 'page-templates' )->print_content();
		$page_content                 = ob_get_clean();
		$ideapark_footer_is_elementor = true;
	} elseif ( $post = get_post( $post_id ) ) {
		$page_content = apply_filters( 'the_content', $post->post_content );
		$page_content = str_replace( ']]>', ']]&gt;', $page_content );
		$page_content = ideapark_wrap( $page_content, '<div class="entry-content">', '</div>' );
		wp_reset_postdata();
	} else {
		$page_content = '';
	}

	if ( $page_content ) {
		$str_start = '<!-- grid-start -->';
		$str_end   = '<!-- grid-end -->';
		if ( $pos_start = mb_strpos( $page_content, $str_start ) ) {
			$page_content = mb_substr( $page_content,  $pos_start + mb_strlen( $str_start ) );
		}
		if ( $pos_end = mb_strpos( $page_content, $str_end ) ) {
			$page_content = mb_substr( $page_content, 0, $pos_end );
		}
	}

	wp_send_json( [ 'products' => $page_content, 'paging' => ideapark_mod( '_infinity_paging' ) ] );
}