<?php
$header_type         = ideapark_mod( 'header_type' );
$header_blocks_place = [
	'top-left'      => [],
	'top-center'    => [],
	'top-right'     => [],
	'center-left'   => [],
	'center-center' => [],
	'center-right'  => [],
	'bottom-left'   => [],
	'bottom-center' => [],
	'bottom-right'  => [],
];
$header_blocks       = ideapark_parse_checklist( ideapark_mod( 'header_blocks_1' ) );
foreach ( $header_blocks as $block_index => $value ) {
	$enabled = (int) $value[0] == 1;
	$place   = preg_match( '~\(([^\)]+)\)~', $value, $match ) ? $match[1] : false;
	if ( $enabled && $place ) {
		ob_start();
		get_template_part( 'templates/header-' . $block_index );
		$header_blocks_place[ $place ][] = trim( ob_get_clean() );
	}
}
foreach ( $header_blocks_place as $place => $items ) {
	$header_blocks_place[ $place ] = implode( '', $items );
}
?>
<div
	class="c-header__outer c-header__outer--desktop <?php if ( ideapark_is_transparent_header() ) { ?> c-header__outer--tr<?php } ?> c-header__outer--<?php echo esc_attr( $header_type ); ?>">
	<div
		class="c-header c-header--desktop <?php if ( ideapark_is_transparent_header() ) { ?> c-header--tr<?php } ?> js-header-desktop c-header--<?php echo esc_attr( $header_type ); ?> c-header--rows">
		<?php if ( $header_blocks_place['top-left'] || $header_blocks_place['top-center'] || $header_blocks_place['top-right'] ) { ?>
			<div class="c-header__row-top">
				<div
					class="c-header__cell c-header__cell--top-left"><?php echo ideapark_wrap( $header_blocks_place['top-left'] ); ?></div>
				<div
					class="c-header__cell c-header__cell--top-center"><?php echo ideapark_wrap( $header_blocks_place['top-center'] ); ?></div>
				<div
					class="c-header__cell c-header__cell--top-right"><?php echo ideapark_wrap( $header_blocks_place['top-right'] ); ?></div>
			</div>
		<?php } ?>
		<?php if ( $header_blocks_place['bottom-left'] || $header_blocks_place['bottom-center'] || $header_blocks_place['bottom-right'] ) { ?>
			<div class="c-header__row-bottom">
				<div
					class="c-header__cell c-header__cell--bottom-left"><?php echo ideapark_wrap( $header_blocks_place['bottom-left'] ); ?></div>
				<div
					class="c-header__cell c-header__cell--bottom-center"><?php echo ideapark_wrap( $header_blocks_place['bottom-center'] ); ?></div>
				<div
					class="c-header__cell c-header__cell--bottom-right"><?php echo ideapark_wrap( $header_blocks_place['bottom-right'] ); ?></div>
			</div>
		<?php } ?>
		<?php echo ideapark_filter_button( 'c-header__filter js-header-filter' ); ?>
	</div>
</div>
