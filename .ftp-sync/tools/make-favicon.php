<?php
/**
 * Build a square site icon from the brand logo.
 *
 *   php .ftp-sync/tools/make-favicon.php [--size=512]
 *
 * The logo is a wide transparent PNG: the QMC monogram on the left, "OOPS. MINE CO." set beside it.
 * A favicon is painted at 16 to 32 pixels, where that wordmark is an unreadable smudge, so this
 * keeps the monogram only.
 *
 * It is set cream on a solid rose ground rather than rose on cream. At 16px the monogram's hairline
 * strokes all but vanish against a light tile; a filled tile keeps a recognisable shape and the
 * brand colour, which is what a favicon is actually doing at that size. The mark is held close to
 * the edges for the same reason — a generous margin is margin no one can see.
 *
 * Output: wp-content/themes/moderno-child/assets/img/oops-favicon.png
 * Import it and set it as the site icon by running .ftp-sync/publish-site.php.
 *
 * @package oopsmine-tools
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "CLI only.\n" );
}

$size = 512;
foreach ( $_SERVER['argv'] as $a ) {
	if ( 0 === strpos( $a, '--size=' ) ) {
		$size = max( 64, (int) substr( $a, 7 ) );
	}
}

$root = dirname( __DIR__, 2 );
$src  = $root . '/wp-content/themes/moderno-child/assets/img/oops-logo-cream.png';
$out  = $root . '/wp-content/themes/moderno-child/assets/img/oops-favicon.png';

// --omc-rose-dark from assets/css/omc.css; keep the two in step if the palette moves.
$ground = [ 0x9C, 0x6F, 0x63 ];

if ( ! file_exists( $src ) ) {
	exit( "Source logo not found: {$src}\n" );
}

$logo = imagecreatefrompng( $src );
if ( ! $logo ) {
	exit( "Could not read the logo.\n" );
}
imagealphablending( $logo, false );
imagesavealpha( $logo, true );

$w = imagesx( $logo );
$h = imagesy( $logo );

/*
 * Erase the wordmark before measuring, or the bounding box stretches to the right edge and the
 * monogram ends up small and off-centre. The block sits clear of the monogram's strokes: the M's
 * right stem stops near x=0.68w and the C's shoulder is below y=0.40h.
 */
$clear_x0 = (int) ( $w * 0.685 );
$clear_y0 = (int) ( $h * 0.16 );
$clear_y1 = (int) ( $h * 0.40 );
$blank    = imagecolorallocatealpha( $logo, 0, 0, 0, 127 );
imagefilledrectangle( $logo, $clear_x0, $clear_y0, $w - 1, $clear_y1, $blank );

// Bounding box of what is left.
$min_x = $w;
$min_y = $h;
$max_x = -1;
$max_y = -1;
for ( $y = 0; $y < $h; $y++ ) {
	for ( $x = 0; $x < $w; $x++ ) {
		$alpha = ( imagecolorat( $logo, $x, $y ) >> 24 ) & 0x7F;
		if ( $alpha > 100 ) {
			continue; // effectively transparent
		}
		$min_x = min( $min_x, $x );
		$min_y = min( $min_y, $y );
		$max_x = max( $max_x, $x );
		$max_y = max( $max_y, $y );
	}
}
if ( $max_x < 0 ) {
	exit( "The logo looks empty after clearing the wordmark — check the crop constants.\n" );
}

$mark_w = $max_x - $min_x + 1;
$mark_h = $max_y - $min_y + 1;
printf( "monogram: %dx%d at (%d,%d) of %dx%d\n", $mark_w, $mark_h, $min_x, $min_y, $w, $h );

// Square canvas, filled edge to edge in the brand rose.
$canvas = imagecreatetruecolor( $size, $size );
imagealphablending( $canvas, false );
imagesavealpha( $canvas, true );
imagefilledrectangle( $canvas, 0, 0, $size, $size, imagecolorallocate( $canvas, ...$ground ) );
imagealphablending( $canvas, true );

// Fit the monogram inside the square, keeping its proportions.
$pad   = (int) round( $size * 0.07 );
$box   = $size - ( $pad * 2 );
$scale = min( $box / $mark_w, $box / $mark_h );
$dst_w = (int) round( $mark_w * $scale );
$dst_h = (int) round( $mark_h * $scale );
$dst_x = (int) round( ( $size - $dst_w ) / 2 );
$dst_y = (int) round( ( $size - $dst_h ) / 2 );

imagecopyresampled( $canvas, $logo, $dst_x, $dst_y, $min_x, $min_y, $dst_w, $dst_h, $mark_w, $mark_h );

if ( ! imagepng( $canvas, $out, 9 ) ) {
	exit( "Could not write {$out}\n" );
}
printf( "wrote %s (%dx%d, %s)\n", $out, $size, $size, number_format( filesize( $out ) / 1024, 1 ) . " kB" );
