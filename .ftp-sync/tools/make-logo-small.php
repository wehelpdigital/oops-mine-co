<?php
/**
 * Downscaled copies of the brand logos, for the sizes the site actually draws them at.
 *
 *   php .ftp-sync/tools/make-logo-small.php [--width=400]
 *
 * The masters are 1071×729. The header draws the mark at 78–132px and the photo watermark smaller
 * still, so every page was downloading roughly 48 kB of PNG to paint something 78 pixels wide —
 * twice over on the home page. 400px covers the largest of those slots at 3× on a phone.
 *
 * Re-run after .ftp-sync/tools/make-logo-variants.php, which regenerates the tinted masters.
 *
 * @package oopsmine-tools
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "CLI only.\n" );
}

$width = 400;
foreach ( $_SERVER['argv'] as $a ) {
	if ( 0 === strpos( $a, '--width=' ) ) {
		$width = max( 120, (int) substr( $a, 8 ) );
	}
}

$dir = dirname( __DIR__, 2 ) . '/wp-content/themes/moderno-child/assets/img';
$made = 0;

foreach ( [ 'rose', 'cream', 'black', 'white' ] as $variant ) {
	$src = "$dir/oops-logo-$variant.png";
	$out = "$dir/oops-logo-$variant-$width.png";
	if ( ! file_exists( $src ) ) {
		echo "missing: $src\n";
		continue;
	}

	$im = imagecreatefrompng( $src );
	if ( ! $im ) {
		echo "unreadable: $src\n";
		continue;
	}
	$w = imagesx( $im );
	$h = imagesy( $im );
	$dh = (int) round( $h * ( $width / $w ) );

	$small = imagecreatetruecolor( $width, $dh );
	// Keep the transparency: these sit on cream, on photographs and on white product shots.
	imagealphablending( $small, false );
	imagesavealpha( $small, true );
	imagefilledrectangle( $small, 0, 0, $width, $dh, imagecolorallocatealpha( $small, 0, 0, 0, 127 ) );
	imagealphablending( $small, true );
	imagecopyresampled( $small, $im, 0, 0, 0, 0, $width, $dh, $w, $h );

	imagepng( $small, $out, 9 );
	printf(
		"%-28s %dx%d  %s → %s\n",
		basename( $out ),
		$width,
		$dh,
		number_format( filesize( $src ) / 1024, 1 ) . ' kB',
		number_format( filesize( $out ) / 1024, 1 ) . ' kB'
	);
	$made++;
}

echo "\n$made file(s) written.\n";
