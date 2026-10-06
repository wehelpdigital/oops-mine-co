<?php
/**
 * Builds the figure that stands in the stylist band on the home page.
 *
 *   php .ftp-sync/tools/make-stylist-photo.php [--preview]
 *
 * The band asks "Not sure what's perfect?", so the picture has to be somebody deciding: the shot is
 * the client's own, a woman with her hand at her ear, mid-thought. Two things are done to it here
 * that CSS cannot do.
 *
 * The studio wall is cut away. Grading it to the band's sand was the obvious move and it reads as a
 * pale rectangle however carefully the colour is matched, because the wall is lit unevenly and the
 * band is flat. Taken out, she stands in the band itself and the band can be any colour later.
 *
 * The wall is found by flooding inwards from the edges rather than by picking out pale pixels: her
 * turtleneck is paler than the wall, and a brightness rule eats it. Flooding cannot reach it,
 * because the jacket encloses it.
 *
 * Output: wp-content/themes/moderno-child/assets/img/stylist-thinking.webp — a theme asset, like the
 * logo variants, so it travels with the theme and needs nothing in the database.
 */

$src  = 'C:/Users/User/Downloads/oops-photos/Gemini_Generated_Image_5wf0kr5wf0kr5wf0.jpg';
$dir  = dirname( __DIR__, 2 ) . '/wp-content/themes/moderno-child/assets/img';
$out  = $dir . '/stylist-thinking.webp';
$prev = in_array( '--preview', $argv, true );

/* Her, with a little air, and the floor left out. */
$crop = [ 'x' => 250, 'y' => 0, 'w' => 540, 'h' => 920 ];

/* How sure a pixel has to be that it is wall before the flood crosses it. */
$wall_lum = 142;   // darker than this is her, not the room
$wall_sat = 34;    // and the wall has no colour in it to speak of

$im = imagecreatefromjpeg( $src );
if ( ! $im ) {
	exit( "cannot read $src\n" );
}
$cut = imagecreatetruecolor( $crop['w'], $crop['h'] );
imagecopy( $cut, $im, 0, 0, $crop['x'], $crop['y'], $crop['w'], $crop['h'] );
imagedestroy( $im );

$w = imagesx( $cut );
$h = imagesy( $cut );

/* ── 1. read the pixels once ──────────────────────────────────────────────── */

$r = [];
$g = [];
$b = [];
$wall = [];                       // could this pixel be room?
for ( $y = 0; $y < $h; $y++ ) {
	for ( $x = 0; $x < $w; $x++ ) {
		$i  = $y * $w + $x;
		$c  = imagecolorat( $cut, $x, $y );
		$rr = ( $c >> 16 ) & 0xFF;
		$gg = ( $c >> 8 ) & 0xFF;
		$bb = $c & 0xFF;
		$r[ $i ] = $rr;
		$g[ $i ] = $gg;
		$b[ $i ] = $bb;
		$lum = 0.299 * $rr + 0.587 * $gg + 0.114 * $bb;
		$sat = max( $rr, $gg, $bb ) - min( $rr, $gg, $bb );
		$wall[ $i ] = ( $lum >= $wall_lum && $sat <= $wall_sat );
	}
}

/* ── 2. flood the room in from the edges ──────────────────────────────────── */

$seen  = array_fill( 0, $w * $h, false );
$queue = [];
for ( $x = 0; $x < $w; $x++ ) {
	foreach ( [ 0, $h - 1 ] as $y ) {
		$i = $y * $w + $x;
		if ( $wall[ $i ] && ! $seen[ $i ] ) {
			$seen[ $i ] = true;
			$queue[]    = $i;
		}
	}
}
for ( $y = 0; $y < $h; $y++ ) {
	foreach ( [ 0, $w - 1 ] as $x ) {
		$i = $y * $w + $x;
		if ( $wall[ $i ] && ! $seen[ $i ] ) {
			$seen[ $i ] = true;
			$queue[]    = $i;
		}
	}
}
for ( $q = 0; $q < count( $queue ); $q++ ) {
	$i = $queue[ $q ];
	$x = $i % $w;
	$y = intdiv( $i, $w );
	foreach ( [ [ 1, 0 ], [ -1, 0 ], [ 0, 1 ], [ 0, -1 ] ] as $d ) {
		$nx = $x + $d[0];
		$ny = $y + $d[1];
		if ( $nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h ) {
			continue;
		}
		$n = $ny * $w + $nx;
		if ( ! $seen[ $n ] && $wall[ $n ] ) {
			$seen[ $n ] = true;
			$queue[]    = $n;
		}
	}
}
printf( "room: %d of %d pixels (%d%%)\n", count( $queue ), $w * $h, round( 100 * count( $queue ) / ( $w * $h ) ) );

/*
 * The floor is darker than the wall and faintly warm, so the rule above stops at it and leaves a
 * smear under her feet. A second flood crosses it with a looser rule, seeded only along the bottom
 * edge - her jeans sit at a fifth of that brightness, so there is no danger of walking into them.
 */
$floor_lum = 88;
$floor_sat = 52;
$queue     = [];
for ( $x = 0; $x < $w; $x++ ) {
	$i   = ( $h - 1 ) * $w + $x;
	$lum = 0.299 * $r[ $i ] + 0.587 * $g[ $i ] + 0.114 * $b[ $i ];
	$sat = max( $r[ $i ], $g[ $i ], $b[ $i ] ) - min( $r[ $i ], $g[ $i ], $b[ $i ] );
	if ( ! $seen[ $i ] && $lum >= $floor_lum && $sat <= $floor_sat ) {
		$seen[ $i ] = true;
		$queue[]    = $i;
	}
}
for ( $q = 0; $q < count( $queue ); $q++ ) {
	$i = $queue[ $q ];
	$x = $i % $w;
	$y = intdiv( $i, $w );
	foreach ( [ [ 1, 0 ], [ -1, 0 ], [ 0, 1 ], [ 0, -1 ] ] as $d ) {
		$nx = $x + $d[0];
		$ny = $y + $d[1];
		if ( $nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h ) {
			continue;
		}
		$n = $ny * $w + $nx;
		if ( $seen[ $n ] ) {
			continue;
		}
		$lum = 0.299 * $r[ $n ] + 0.587 * $g[ $n ] + 0.114 * $b[ $n ];
		$sat = max( $r[ $n ], $g[ $n ], $b[ $n ] ) - min( $r[ $n ], $g[ $n ], $b[ $n ] );
		if ( $lum >= $floor_lum && $sat <= $floor_sat ) {
			$seen[ $n ] = true;
			$queue[]    = $n;
		}
	}
}
printf( "floor: %d more pixel(s)\n", count( $queue ) );

/* ── 3. soften the cut ────────────────────────────────────────────────────── */

/*
 * A hard mask leaves a rim of wall-coloured pixels around her hair, where the camera blended the
 * two. Eroding her side of the line by a pixel takes that rim off, and a small blur turns what is
 * left into a gradient the eye reads as an edge rather than a cut.
 */
$mask = [];
for ( $i = 0, $n = $w * $h; $i < $n; $i++ ) {
	$mask[ $i ] = $seen[ $i ] ? 1.0 : 0.0;
}
for ( $pass = 0; $pass < 1; $pass++ ) {   // grow the room by one pixel
	$grown = $mask;
	for ( $y = 1; $y < $h - 1; $y++ ) {
		for ( $x = 1; $x < $w - 1; $x++ ) {
			$i = $y * $w + $x;
			if ( $mask[ $i ] < 1 && ( $mask[ $i - 1 ] > 0 || $mask[ $i + 1 ] > 0 || $mask[ $i - $w ] > 0 || $mask[ $i + $w ] > 0 ) ) {
				$grown[ $i ] = 1.0;
			}
		}
	}
	$mask = $grown;
}
$blur = $mask;                             // 3×3 box blur, twice
for ( $pass = 0; $pass < 2; $pass++ ) {
	$next = $blur;
	for ( $y = 1; $y < $h - 1; $y++ ) {
		for ( $x = 1; $x < $w - 1; $x++ ) {
			$i = $y * $w + $x;
			$next[ $i ] = ( $blur[ $i - $w - 1 ] + $blur[ $i - $w ] + $blur[ $i - $w + 1 ]
				+ $blur[ $i - 1 ] + $blur[ $i ] + $blur[ $i + 1 ]
				+ $blur[ $i + $w - 1 ] + $blur[ $i + $w ] + $blur[ $i + $w + 1 ] ) / 9;
		}
	}
	$blur = $next;
}

/* ── 4. warm her to the palette, fade the cut legs, write it ──────────────── */

$grade = [ 1.045, 1.012, 0.975 ];   // a quarter-stop of warmth: the band either side of her is sand
$fade  = 300;                        // the legs are cut off; they dissolve rather than stop

$dst = imagecreatetruecolor( $w, $h );
imagealphablending( $dst, false );
imagesavealpha( $dst, true );

for ( $y = 0; $y < $h; $y++ ) {
	$tail = $y > $h - 1 - $fade ? ( $h - 1 - $y ) / $fade : 1.0;
	$tail = $tail * $tail * ( 3 - 2 * $tail );
	for ( $x = 0; $x < $w; $x++ ) {
		$i = $y * $w + $x;
		$a = ( 1 - $blur[ $i ] ) * $tail;
		if ( $a <= 0.004 ) {
			imagesetpixel( $dst, $x, $y, imagecolorallocatealpha( $dst, 255, 255, 255, 127 ) );
			continue;
		}
		imagesetpixel( $dst, $x, $y, imagecolorallocatealpha(
			$dst,
			min( 255, (int) round( $r[ $i ] * $grade[0] ) ),
			min( 255, (int) round( $g[ $i ] * $grade[1] ) ),
			min( 255, (int) round( $b[ $i ] * $grade[2] ) ),
			(int) round( ( 1 - $a ) * 127 )
		) );
	}
}
imagedestroy( $cut );

imagewebp( $dst, $out, 88 );
printf( "%dx%d  →  %s  (%s KB)\n", $w, $h, str_replace( dirname( __DIR__, 2 ) . '/', '', $out ), number_format( filesize( $out ) / 1024 ) );

/* Flattened onto the band's sand, to look at what the page will actually show. */
if ( $prev ) {
	$card = imagecreatetruecolor( $w + 460, $h + 60 );
	imagefill( $card, 0, 0, imagecolorallocate( $card, 0xef, 0xe6, 0xde ) );
	imagealphablending( $card, true );
	imagecopy( $card, $dst, 460, 60, 0, 0, $w, $h );
	$file = sys_get_temp_dir() . '/stylist-preview.png';
	imagepng( $card, $file );
	printf( "preview → %s\n", $file );
}
imagedestroy( $dst );
