<?php

if ( ! $content = ideapark_mod( '_soc_cache' ) ) {
	ob_start();
	foreach ( ideapark_social_networks() as $code => $name ) {
		if ( ideapark_mod( $code ) ) { ?>
			<a href="<?php echo esc_url( ideapark_mod( $code ) ); ?>" class="c-soc__link" target="_blank"
			   aria-label="<?php echo esc_attr( $name ); ?>"><i
					class="ip-<?php echo esc_attr( $code ) ?> c-soc__icon c-soc__icon--<?php echo esc_attr( $code ) ?>"></i></a>
		<?php }
	}
	for ( $i = 1; $i <= max( 1, (int) apply_filters( 'ideapark_custom_soc_count', 2 ) ); $i ++ ) {
		if ( ideapark_mod( 'custom_soc_icon_' . $i ) && ideapark_mod( 'custom_soc_url_' . $i ) ) {
			$host = parse_url( ideapark_mod( 'custom_soc_url_' . $i ), PHP_URL_HOST );
			?>
			<a href="<?php echo esc_url( ideapark_mod( 'custom_soc_url_' . $i ) ); ?>" class="c-soc__link"
			   target="_blank"
			   aria-label="<?php echo esc_attr( $host ); ?>"><i
					class="<?php echo esc_attr( ideapark_mod( 'custom_soc_icon_' . $i ) ) ?> c-soc__icon c-soc__icon--<?php echo esc_attr( preg_replace( '~[^a-z0-9]~', '-', strtolower( $host ?: ( 'custom-' . $i ) ) ) ) ?>"></i></a>
		<?php }
	}
	$content = ob_get_contents();
	ob_end_clean();
	ideapark_mod_set_temp( '_soc_cache', $content );
}

echo ideapark_wrap( $content, '<div class="c-soc' . ( ! empty( $ideapark_var['class'] ) ? ' ' . esc_attr( $ideapark_var['class'] ) : '' ) . '">', '</div>' ) ?>