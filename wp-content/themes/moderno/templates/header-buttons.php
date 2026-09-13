<?php
ob_start();
$header_blocks = ideapark_parse_checklist( ideapark_mod( 'header_buttons' ) );
foreach ( $header_blocks as $block_index => $enabled ) {
	if ( $enabled ) {
		get_template_part( 'templates/header-button-' . $block_index );
	}
}
$content = trim( ob_get_clean() );
if ( $content ) { ?>
	<div class="c-header__buttons c-header__buttons--<?php echo esc_attr( ideapark_mod( 'header_type' ) ); ?>">
		<?php echo ideapark_wrap( $content ); ?>
	</div>
<?php }