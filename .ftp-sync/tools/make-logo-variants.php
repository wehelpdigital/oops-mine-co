<?php
/**
 * Regenerates the tinted logo PNGs in wp-content/themes/moderno-child/assets/img from the white master.
 *
 *   php .ftp-sync/tools/make-logo-variants.php
 *
 * oops-logo-white.png and oops-logo-black.png are the client's files (re-encoded once to drop a broken
 * iCCP chunk). The tinted variants are the theme palette (see :root in assets/css/omc.css):
 *   rose  — --omc-rose-dark #9c6f63, the colour of section titles; used on light backgrounds (header, footer)
 *   cream — --omc-rose-light #e9d3ca, the hero's italic line; used on dark backgrounds / photos (hero)
 * Add a variant here and reference it with omc_logo( '<name>' ).
 */

$dir      = dirname( __DIR__, 2 ) . '/wp-content/themes/moderno-child/assets/img';
$variants = [
	'rose'  => [ 0x9c, 0x6f, 0x63 ],
	'cream' => [ 0xe9, 0xd3, 0xca ],
];

foreach ( $variants as $name => $rgb ) {
	$im = imagecreatefrompng( "$dir/oops-logo-white.png" );
	imagealphablending( $im, false );
	imagesavealpha( $im, true );
	// The master is pure white: adding (target − 255) per channel turns every opaque pixel into the target colour
	// and leaves the alpha channel untouched, so anti-aliased edges keep their partial transparency.
	imagefilter( $im, IMG_FILTER_COLORIZE, $rgb[0] - 255, $rgb[1] - 255, $rgb[2] - 255, 0 );
	imagepng( $im, "$dir/oops-logo-$name.png", 9 );
	$c = imagecolorat( $im, 60, 200 ); // a pixel on the O's stroke
	printf( "%-6s → oops-logo-%s.png  %d bytes  sample rgb=%d,%d,%d alpha=%d\n", $name, $name, filesize( "$dir/oops-logo-$name.png" ), ( $c >> 16 ) & 0xFF, ( $c >> 8 ) & 0xFF, $c & 0xFF, ( $c >> 24 ) & 0x7F );
	imagedestroy( $im );
}
