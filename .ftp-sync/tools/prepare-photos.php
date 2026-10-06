<?php
/**
 * Turn the client's photographs into web images the site can serve.
 *
 *   php .ftp-sync/tools/prepare-photos.php [--dry-run] [--only=slug,slug]
 *
 * Reads `.ftp-sync/content/photos.json`, which names every picture the site uses, what it is of,
 * and what it is for. Each one is rotated upright, cropped to the shape its slot needs, resized to
 * the largest width that slot can ever ask for, and written as WebP into
 * `wp-content/uploads/omc-2026/`.
 *
 * WebP because these are photographs of clothes: at the same apparent quality it is roughly a third
 * the weight of the JPEG it came from, and every one of them sits above the fold on a phone.
 *
 * The file is the only output. Titles, alt text and descriptions live in the same JSON and are
 * written to the media library by `.ftp-sync/import-photos.php`, so the words and the pixels are
 * never out of step.
 */

$root   = dirname( __DIR__, 2 );
$source = 'C:/Users/User/Downloads/oops-photos/';
$dest   = $root . '/wp-content/uploads/omc-2026/';
$plan   = $root . '/.ftp-sync/content/photos.json';

$dry  = in_array( '--dry-run', $argv, true );
$only = [];
foreach ( $argv as $arg ) {
	if ( 0 === strpos( $arg, '--only=' ) ) {
		$only = array_filter( array_map( 'trim', explode( ',', substr( $arg, 7 ) ) ) );
	}
}

if ( ! file_exists( $plan ) ) {
	exit( "no plan at $plan\n" );
}
$photos = json_decode( file_get_contents( $plan ), true );
if ( ! is_array( $photos ) ) {
	exit( "the plan is not valid JSON\n" );
}

/**
 * How wide each kind of slot ever needs its picture, and the shape it is cropped to.
 *
 * The width is the largest the slot can be asked for on a 2× screen; anything beyond that is
 * weight nobody sees. A null ratio means "leave the shape alone".
 */
$roles = [
	/*
	 * The hero and the wide bands are drawn with object-fit: cover, so they do not need the file to
	 * be their shape — and these photographs are small enough that cropping one to 16:9 threw away
	 * more than half of what little height there was. They keep their own frame and a width cap.
	 */
	'hero'      => [ 'w' => 2400, 'ratio' => null, 'short' => 1400 ],
	'banner'    => [ 'w' => 1800, 'ratio' => null, 'short' => 1100 ],
	'wide'      => [ 'w' => 2200, 'ratio' => null, 'short' => 1000 ],
	'square'    => [ 'w' => 1200, 'ratio' => 1,    'short' => 1100 ],
	'tile'      => [ 'w' => 1600, 'ratio' => null, 'short' => 1100 ],
	'tile-tall' => [ 'w' => 1200, 'ratio' => 1 / 2, 'short' => 760 ],
	'tile-wide' => [ 'w' => 2000, 'ratio' => 2,    'short' => 820 ],
	'portrait'  => [ 'w' => 1200, 'ratio' => 2 / 3, 'short' => 800 ],
	'landscape' => [ 'w' => 1600, 'ratio' => 3 / 2, 'short' => 900 ],
	'product'   => [ 'w' => 1600, 'ratio' => null, 'short' => 1200 ],
];

if ( ! $dry && ! is_dir( $dest ) ) {
	mkdir( $dest, 0775, true );
}

/** Phones write the orientation into EXIF instead of the pixels. Put it back where it belongs. */
function upright( $im, $file ) {
	if ( ! function_exists( 'exif_read_data' ) ) {
		return $im;
	}
	$exif = @exif_read_data( $file );
	$o    = (int) ( $exif['Orientation'] ?? 1 );
	if ( $o <= 1 ) {
		return $im;
	}
	$map = [ 3 => 180, 6 => -90, 8 => 90 ];
	if ( isset( $map[ $o ] ) ) {
		$rot = imagerotate( $im, $map[ $o ], 0 );
		if ( $rot ) {
			imagedestroy( $im );
			return $rot;
		}
	}
	return $im;
}

function load( $file ) {
	$size = @getimagesize( $file );
	if ( ! $size ) {
		return null;
	}
	switch ( $size[2] ) {
		case IMAGETYPE_JPEG: $im = @imagecreatefromjpeg( $file ); break;
		case IMAGETYPE_PNG:  $im = @imagecreatefrompng( $file );  break;
		case IMAGETYPE_WEBP: $im = @imagecreatefromwebp( $file ); break;
		default: return null;
	}
	return $im ? upright( $im, $file ) : null;
}

/**
 * Shift a picture's white balance.
 *
 * `$amount` is how far, 0 to 1. Positive cools (red down, blue up), negative warms. The shift is
 * weighted by how far a pixel sits from black and white, so highlights keep their paper white and
 * shadows keep their weight — a flat offset tints those first and looks like a filter.
 */
function balance( $im, $amount ) {
	if ( ! $amount ) {
		return $im;
	}
	$w     = imagesx( $im );
	$h     = imagesy( $im );
	$shift = 26 * $amount; // at full strength, about a tenth of the range

	for ( $y = 0; $y < $h; $y++ ) {
		for ( $x = 0; $x < $w; $x++ ) {
			$c = imagecolorat( $im, $x, $y );
			$r = ( $c >> 16 ) & 0xFF;
			$g = ( $c >> 8 ) & 0xFF;
			$b = $c & 0xFF;

			// 1 through the midtones, 0 at pure black and pure white.
			$lum  = ( $r * 0.299 + $g * 0.587 + $b * 0.114 ) / 255;
			$step = $shift * ( 1 - abs( $lum - 0.5 ) * 2 );

			imagesetpixel(
				$im,
				$x,
				$y,
				imagecolorallocate(
					$im,
					max( 0, min( 255, (int) round( $r - $step ) ) ),
					$g,
					max( 0, min( 255, (int) round( $b + $step ) ) )
				)
			);
		}
	}

	return $im;
}

/**
 * Crop to a shape around a point of interest, then resize.
 *
 * `focus` is where the subject sits, 0–1 across and down, so a tall portrait can be cut to a wide
 * banner without beheading anyone. It defaults to the upper middle, which is where a person is in
 * almost every one of these.
 */
function shape( $im, $ratio, $maxW, $focus, $short = 0 ) {
	$sw = imagesx( $im );
	$sh = imagesy( $im );

	if ( $ratio ) {
		$cw = $sw;
		$ch = (int) round( $sw / $ratio );
		if ( $ch > $sh ) {
			$ch = $sh;
			$cw = (int) round( $sh * $ratio );
		}
		$fx = max( 0, min( 1, (float) ( $focus[0] ?? 0.5 ) ) );
		$fy = max( 0, min( 1, (float) ( $focus[1] ?? 0.38 ) ) );
		$x  = (int) round( ( $sw - $cw ) * $fx );
		$y  = (int) round( ( $sh - $ch ) * $fy );

		$cut = imagecreatetruecolor( $cw, $ch );
		imagecopy( $cut, $im, 0, 0, $x, $y, $cw, $ch );
		imagedestroy( $im );
		$im = $cut;
		$sw = $cw;
		$sh = $ch;
	}

	/*
	 * Shrink to the long-edge cap, but never past the short edge the slot needs — the short edge is
	 * the one that has to cover, and a file that satisfies the cap can still be half the pixels a
	 * high-density screen asks of it. Never upscales: if the photograph does not have them, it does
	 * not have them, and inventing pixels only makes it look worse.
	 */
	$scale = $sw > $maxW ? $maxW / $sw : 1;
	if ( $short > 0 ) {
		$shortest = min( $sw, $sh );
		$needed   = min( 1, $short / $shortest );
		$scale    = max( $scale, min( 1, $short / $shortest ) );
		unset( $needed );
	}
	if ( $scale < 1 ) {
		$tw  = max( 1, (int) round( $sw * $scale ) );
		$th  = max( 1, (int) round( $sh * $scale ) );
		$out = imagecreatetruecolor( $tw, $th );
		imagecopyresampled( $out, $im, 0, 0, 0, 0, $tw, $th, $sw, $sh );
		imagedestroy( $im );
		$im = $out;
	}

	return $im;
}

$done   = 0;
$skipped = 0;
$before = 0;
$after  = 0;
$missing = [];

foreach ( $photos as $p ) {
	$slug = $p['slug'] ?? '';
	if ( '' === $slug || ( $only && ! in_array( $slug, $only, true ) ) ) {
		continue;
	}
	$file = $source . ( $p['source'] ?? '' );
	if ( ! is_file( $file ) ) {
		$missing[] = $p['source'] ?? '(none)';
		continue;
	}
	$role = $roles[ $p['role'] ?? 'tile' ] ?? $roles['tile'];
	$out  = $dest . $slug . '.webp';

	if ( $dry ) {
		printf( "would write %-42s from %s\n", $slug . '.webp', basename( $file ) );
		$skipped++;
		continue;
	}

	$im = load( $file );
	if ( ! $im ) {
		$missing[] = basename( $file ) . ' (unreadable)';
		continue;
	}
	// A picture may carry the shape of the slot it is going into, overriding its role's.
	$ratio = array_key_exists( 'ratio', $p ) ? $p['ratio'] : $role['ratio'];
	$im    = shape( $im, $ratio, $role['w'], $p['focus'] ?? [ 0.5, 0.38 ], $role['short'] ?? 0 );
	// Graded after resizing, so the work lands on the pixels that ship.
	$im    = balance( $im, (float) ( $p['cool'] ?? 0 ) );
	imagewebp( $im, $out, 82 );
	imagedestroy( $im );

	$before += filesize( $file );
	$after  += filesize( $out );
	$done++;
	printf( "%-42s %5dx%-5d %6s → %6s\n",
		$slug . '.webp',
		(int) getimagesize( $out )[0],
		(int) getimagesize( $out )[1],
		round( filesize( $file ) / 1024 ) . 'k',
		round( filesize( $out ) / 1024 ) . 'k'
	);
}

echo "\n";
/*
 * The same words, as a file the theme can read.
 *
 * The media library is not on every environment at the moment the code is — the theme reaches live
 * before the import does — and a photograph with no description in that window is worse than one
 * described early. Written from the same JSON, so the two cannot disagree.
 */
if ( ! $dry ) {
	$words = [];
	foreach ( $photos as $p ) {
		if ( ! empty( $p['slug'] ) ) {
			$words[ $p['slug'] ] = [
				'title' => (string) ( $p['title'] ?? '' ),
				'alt'   => (string) ( $p['alt'] ?? '' ),
			];
		}
	}
	$head = "<?php" . PHP_EOL
		. "/**" . PHP_EOL
		. " * Titles and alt text for the prepared photographs." . PHP_EOL
		. " *" . PHP_EOL
		. " * Generated by .ftp-sync/tools/prepare-photos.php from .ftp-sync/content/photos.json." . PHP_EOL
		. " * Edit the JSON and re-run the tool; do not edit this file." . PHP_EOL
		. " *" . PHP_EOL
		. " * @package moderno-child" . PHP_EOL
		. " */" . PHP_EOL . PHP_EOL;
	file_put_contents( $root . '/wp-content/themes/moderno-child/inc/photos.php', $head . 'return ' . var_export( $words, true ) . ';' . PHP_EOL );
	printf( "wrote %d descriptions to the theme's inc/photos.php" . PHP_EOL, count( $words ) );
}

if ( $missing ) {
	printf( "%d source file(s) missing:\n", count( $missing ) );
	foreach ( array_slice( $missing, 0, 12 ) as $m ) {
		echo "  $m\n";
	}
}
if ( $done ) {
	printf( "%d images written — %s → %s (%d%% lighter)\n",
		$done,
		round( $before / 1048576, 1 ) . ' MB',
		round( $after / 1048576, 1 ) . ' MB',
		$before ? round( 100 - ( $after / $before * 100 ) ) : 0
	);
}
if ( $skipped ) {
	printf( "%d planned (dry run)\n", $skipped );
}
