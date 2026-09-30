<?php
/**
 * Prepare a press logo for the "As seen on" strip.
 *
 *   php .ftp-sync/tools/make-press-logo.php <source.png> <slug> [--width=240]
 *
 * Outlet logos arrive on whatever canvas the download site used, with different amounts of empty
 * margin around the mark. Sized by that canvas they come out at visibly different weights next to
 * each other. This trims each one to its own ink first, so the strip can size them all by height
 * and have that mean the same thing.
 *
 * It also reports the trimmed proportions, which is what decides whether a mark needs the extra
 * height a stacked logo wants — see the `scale` key in omc_press_logos().
 *
 * Output: wp-content/themes/moderno-child/assets/img/press/<slug>.png
 *
 * @package oopsmine-tools
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "CLI only.\n" );
}

$argv   = $_SERVER['argv'];
$source = $argv[1] ?? '';
$slug   = isset( $argv[2] ) ? preg_replace( '/[^a-z0-9-]/', '', strtolower( $argv[2] ) ) : '';
$width  = 240;
foreach ( $argv as $a ) {
	if ( 0 === strpos( $a, '--width=' ) ) {
		$width = max( 60, (int) substr( $a, 8 ) );
	}
}

if ( ! $source || ! $slug ) {
	exit( "Usage: php .ftp-sync/tools/make-press-logo.php <source.png> <slug> [--width=240]\n" );
}
if ( ! file_exists( $source ) ) {
	exit( "Not found: $source\n" );
}

$im = @imagecreatefrompng( $source );
if ( ! $im ) {
	exit( "Could not read $source — is it a PNG?\n" );
}
imagealphablending( $im, false );
imagesavealpha( $im, true );

$w = imagesx( $im );
$h = imagesy( $im );

// The ink: anything not effectively transparent. Also treat near-white as background, because some
// of these arrive flattened onto white rather than cut out.
$min_x = $w;
$min_y = $h;
$max_x = -1;
$max_y = -1;
for ( $y = 0; $y < $h; $y++ ) {
	for ( $x = 0; $x < $w; $x++ ) {
		$c = imagecolorat( $im, $x, $y );
		$a = ( $c >> 24 ) & 0x7F;
		if ( $a > 100 ) {
			continue;
		}
		$r = ( $c >> 16 ) & 0xFF;
		$g = ( $c >> 8 ) & 0xFF;
		$b = $c & 0xFF;
		if ( $r > 243 && $g > 243 && $b > 243 ) {
			continue; // a white canvas behind the mark
		}
		$min_x = min( $min_x, $x );
		$min_y = min( $min_y, $y );
		$max_x = max( $max_x, $x );
		$max_y = max( $max_y, $y );
	}
}
if ( $max_x < 0 ) {
	exit( "Nothing but background in that file.\n" );
}

$iw = $max_x - $min_x + 1;
$ih = $max_y - $min_y + 1;

$nw  = $width;
$nh  = max( 1, (int) round( $ih * ( $nw / $iw ) ) );
$out = imagecreatetruecolor( $nw, $nh );
imagealphablending( $out, false );
imagesavealpha( $out, true );
imagefilledrectangle( $out, 0, 0, $nw, $nh, imagecolorallocatealpha( $out, 0, 0, 0, 127 ) );
imagealphablending( $out, true );
imagecopyresampled( $out, $im, 0, 0, $min_x, $min_y, $nw, $nh, $iw, $ih );

$dir = dirname( __DIR__, 2 ) . '/wp-content/themes/moderno-child/assets/img/press';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0755, true );
}
$dest = "$dir/$slug.png";
imagepng( $out, $dest, 9 );

printf(
	"%s\n  canvas %dx%d  ink %dx%d (ratio %.2f)  →  %dx%d  %.1f kB\n",
	basename( $dest ),
	$w,
	$h,
	$iw,
	$ih,
	$iw / $ih,
	$nw,
	$nh,
	filesize( $dest ) / 1024
);
printf(
	"  suggested scale for omc_press_logos(): %.2f  (1 = a single-line wordmark)\n",
	max( 1, min( 2.2, 2.6 / ( $iw / $ih ) ) )
);
