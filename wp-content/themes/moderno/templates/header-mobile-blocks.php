<?php
ob_start();
$header_blocks = ideapark_parse_checklist( ideapark_mod( 'header_blocks_2' ) );
foreach ( $header_blocks as $block_index => $enabled ) {
	if ( $enabled ) {
		get_template_part( 'templates/header-' . $block_index );
	}
}
$content = trim( ob_get_clean() );
?>
<?php if ( $content ) { ?>
	<div class="c-header__mobile_blocks">
		<div class="c-header__top js-mobile-blocks">
			<div class="c-header__top-row-list">
				<?php
				echo ideapark_wrap( $content );
				?>
			</div>
		</div>
	</div>
<?php } ?>
