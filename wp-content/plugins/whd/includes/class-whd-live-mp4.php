<?php
/**
 * Makes a browser's recording quick to open.
 *
 * MediaRecorder writes a fragmented MP4: a header, then a small box of bookkeeping (moof) and its video
 * (mdat) for every few seconds. Nothing says how long the file is or where any moment sits, so a player
 * finds out by visiting every fragment, one web request each. A recording of an hour took 1,083 requests
 * and over two and a half minutes before it would start, on an ordinary connection.
 *
 * Segment indexes (`sidx` boxes) ahead of the first fragment are that table of contents, one per track.
 * Their size depends on a length nobody knows while the first bytes are going up, so make_room() leaves
 * a `free` box there, which every player skips, and index() writes the indexes over it once the recording
 * is complete. No byte of the video moves, so stopping stays immediate however long the recording is.
 *
 * Any file this does not understand is left exactly as it was: it still plays, only slower to start.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Live_MP4 {

	/** Set aside for the indexes. At 12 bytes a fragment for each of two tracks, over five hours of a browser's recording. */
	const ROOM = 128 * KB_IN_BYTES;

	/** Bookkeeping boxes are a few kilobytes. Anything past this is not what it claims to be. */
	const MAX_BOX = 8 * MB_IN_BYTES;

	/** A box's size and type, or null when there is no sensible box there. */
	private static function box( string $bytes, int $at ) {
		if ( $at + 8 > strlen( $bytes ) ) {
			return null;
		}
		$size = unpack( 'N', substr( $bytes, $at, 4 ) )[1];
		return $size < 8 ? null : [ $size, substr( $bytes, $at + 4, 4 ) ];
	}

	/** The boxes inside a stretch of bytes: [ type, where its contents start, where they end ]. */
	private static function children( string $bytes, int $from = 0, ?int $to = null ): array {
		$to  = null === $to ? strlen( $bytes ) : $to;
		$out = [];
		while ( $from + 8 <= $to ) {
			$box = self::box( $bytes, $from );
			if ( ! $box || $from + $box[0] > $to ) {
				break;
			}
			$out[] = [ $box[1], $from + 8, $from + $box[0] ];
			$from += $box[0];
		}
		return $out;
	}

	private static function u32( string $bytes, int $at ): int {
		return (int) unpack( 'N', substr( $bytes, $at, 4 ) )[1];
	}

	private static function free_box( int $size ): string {
		return pack( 'N', $size ) . 'free' . str_repeat( "\0", $size - 8 );
	}

	/**
	 * A slice, with the room set aside ahead of the first fragment if this is the slice that has it.
	 * A browser's first slice is only the header (ftyp, moov) and its second starts with the first fragment.
	 *
	 * Moving the fragments is only safe when each counts from its own moof. One that names a place in the
	 * file (base-data-offset, which some recorders may write) would point at the wrong bytes afterwards.
	 *
	 * @param string $slice   The bytes as they came.
	 * @param bool   $settled Set to false when this slice is whole boxes with no fragment yet, so the
	 *                        next is worth a look. True when that is decided, either way.
	 */
	public static function make_room( string $slice, &$settled = false ): string {
		$settled = true;
		$end     = strlen( $slice );
		$at      = 0;
		while ( $at < $end ) {
			$box = self::box( $slice, $at );
			if ( ! $box || $at + $box[0] > $end || 'mdat' === $box[1] ) {
				return $slice; // cut in the middle of a box, or not fragmented: not a shape this knows
			}
			if ( 'moof' === $box[1] ) {
				return self::names_a_place( substr( $slice, $at + 8, $box[0] - 8 ) )
					? $slice
					: substr( $slice, 0, $at ) . self::free_box( self::ROOM ) . substr( $slice, $at );
			}
			$at += $box[0];
		}
		$settled = false;
		return $slice;
	}

	private static function names_a_place( string $moof ): bool {
		foreach ( self::children( $moof ) as $traf ) {
			if ( 'traf' !== $traf[0] ) {
				continue;
			}
			foreach ( self::children( $moof, $traf[1], $traf[2] ) as $child ) {
				if ( 'tfhd' === $child[0] && ( self::u32( $moof, $child[1] ) & 0x000001 ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** The tracks: id => [ clock, the sample length they fall back on ]. */
	private static function tracks( string $moov ): array {
		$defaults = [];
		foreach ( self::children( $moov ) as $box ) {
			if ( 'mvex' === $box[0] ) {
				foreach ( self::children( $moov, $box[1], $box[2] ) as $child ) {
					if ( 'trex' === $child[0] ) {
						$defaults[ self::u32( $moov, $child[1] + 4 ) ] = self::u32( $moov, $child[1] + 12 );
					}
				}
			}
		}
		$out = [];
		foreach ( self::children( $moov ) as $trak ) {
			if ( 'trak' !== $trak[0] ) {
				continue;
			}
			$id    = 0;
			$scale = 0;
			foreach ( self::children( $moov, $trak[1], $trak[2] ) as $part ) {
				if ( 'tkhd' === $part[0] ) {
					$id = self::u32( $moov, $part[1] + ( ord( $moov[ $part[1] ] ) ? 20 : 12 ) );
				} elseif ( 'mdia' === $part[0] ) {
					foreach ( self::children( $moov, $part[1], $part[2] ) as $media ) {
						if ( 'mdhd' === $media[0] ) {
							$scale = self::u32( $moov, $media[1] + ( ord( $moov[ $media[1] ] ) ? 20 : 12 ) );
						}
					}
				}
			}
			if ( $id && $scale ) {
				$out[ $id ] = [ 'scale' => $scale, 'sample' => $defaults[ $id ] ?? 0 ];
			}
		}
		return $out;
	}

	/** When each track's part of this fragment starts and how long it runs, on the track's clock: id => [ start, length ]. */
	private static function fragment( string $moof, array $tracks ): array {
		$out = [];
		foreach ( self::children( $moof ) as $traf ) {
			if ( 'traf' !== $traf[0] ) {
				continue;
			}
			$id     = 0;
			$start  = null;
			$sample = 0;
			$runs   = [];
			foreach ( self::children( $moof, $traf[1], $traf[2] ) as $box ) {
				if ( 'tfhd' === $box[0] ) {
					$flags  = self::u32( $moof, $box[1] ) & 0xffffff;
					$id     = self::u32( $moof, $box[1] + 4 );
					$sample = (int) ( $tracks[ $id ]['sample'] ?? 0 );
					if ( $flags & 0x08 ) {
						$sample = self::u32( $moof, $box[1] + 8 + ( $flags & 0x01 ? 8 : 0 ) + ( $flags & 0x02 ? 4 : 0 ) );
					}
				} elseif ( 'tfdt' === $box[0] ) {
					$start = ord( $moof[ $box[1] ] )
						? (int) unpack( 'J', substr( $moof, $box[1] + 4, 8 ) )[1]
						: self::u32( $moof, $box[1] + 4 );
				} elseif ( 'trun' === $box[0] ) {
					$runs[] = $box;
				}
			}
			if ( ! isset( $tracks[ $id ] ) || null === $start ) {
				continue;
			}
			$length = 0;
			foreach ( $runs as $run ) {
				$flags = self::u32( $moof, $run[1] ) & 0xffffff;
				$count = self::u32( $moof, $run[1] + 4 );
				if ( ! ( $flags & 0x100 ) ) {
					$length += $count * $sample;
					continue;
				}
				$at   = $run[1] + 8 + ( $flags & 0x01 ? 4 : 0 ) + ( $flags & 0x04 ? 4 : 0 );
				$step = 4 + ( $flags & 0x200 ? 4 : 0 ) + ( $flags & 0x400 ? 4 : 0 ) + ( $flags & 0x800 ? 4 : 0 );
				for ( $i = 0; $i < $count && $at + 4 <= $run[2]; $i++, $at += $step ) {
					$length += self::u32( $moof, $at );
				}
			}
			$out[ $id ] = [ $start, $length ];
		}
		return $out;
	}

	/**
	 * Write the indexes into the room make_room() left. Reads only the bookkeeping, so its time depends on
	 * the number of fragments and not on the size of the video.
	 *
	 * @return bool Whether the file now has an index.
	 */
	public static function index( string $file ): bool {
		$handle = @fopen( $file, 'r+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			return false;
		}
		$total  = (int) fstat( $handle )['size'];
		$room   = null;
		$tracks = [];
		$found  = [];
		$at     = 0;
		while ( $at + 8 <= $total ) {
			fseek( $handle, $at );
			$box = self::box( (string) fread( $handle, 8 ), 0 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( ! $box ) {
				break; // 64-bit and to-the-end sizes only happen on a box this has no need to step over
			}
			if ( 'free' === $box[1] && self::ROOM === $box[0] && ! $found ) {
				$room = $at;
			} elseif ( ( 'moov' === $box[1] || 'moof' === $box[1] ) && $box[0] <= self::MAX_BOX && $at + $box[0] <= $total ) {
				$body = (string) fread( $handle, $box[0] - 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( 'moov' === $box[1] ) {
					$tracks = self::tracks( $body );
				} else {
					$found[] = [ 'at' => $at, 'parts' => self::fragment( $body, $tracks ) ];
				}
			}
			$at += $box[0];
		}
		$index = $found && null !== $room && $room + self::ROOM === $found[0]['at'] ? self::indexes( $found, $total, $tracks ) : '';
		$done  = false;
		if ( '' !== $index ) {
			fseek( $handle, $room );
			$done = strlen( $index ) === fwrite( $handle, $index ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $done;
	}

	/**
	 * The room's new contents: what is left of the free box, then one index for each track in the first
	 * fragment, the last right against that fragment. Each counts from its own end, so the earlier ones
	 * step over the ones after them.
	 */
	private static function indexes( array $found, int $total, array $tracks ): string {
		$ids  = array_keys( $found[0]['parts'] );
		$fits = $ids ? (int) floor( ( ( self::ROOM - 8 ) / count( $ids ) - 40 ) / 12 ) : 0;
		if ( ! $fits ) {
			return '';
		}
		$step   = (int) ceil( count( $found ) / $fits ); // past the room, a row covers several fragments
		$tables = [];
		foreach ( $ids as $id ) {
			$tables[] = self::rows( $found, $total, $id, $step );
		}

		$boxes = '';
		$after = 0;
		foreach ( array_reverse( array_keys( $ids ) ) as $i ) {
			$id    = $ids[ $i ];
			$first = $found[0]['parts'][ $id ][0];
			$body  = pack( 'N', 0x01000000 ) . pack( 'NN', $id, $tracks[ $id ]['scale'] ) . pack( 'JJ', $first, $after )
				. pack( 'nn', 0, (int) ( strlen( $tables[ $i ] ) / 12 ) ) . $tables[ $i ];
			$box    = pack( 'N', 8 + strlen( $body ) ) . 'sidx' . $body;
			$boxes  = $box . $boxes;
			$after += strlen( $box );
		}
		return self::free_box( self::ROOM - strlen( $boxes ) ) . $boxes;
	}

	/** One track's rows: the bytes each covers and the time it runs. A fragment without this track joins the row before it. */
	private static function rows( array $found, int $total, int $id, int $step ): string {
		$rows  = '';
		$count = count( $found );
		$i     = 0;
		while ( $i < $count ) {
			// Where the row ends: $step fragments on, moved along until a fragment has this track.
			$next = $i + $step;
			while ( $next < $count && ! isset( $found[ $next ]['parts'][ $id ] ) ) {
				$next++;
			}
			$start = $found[ $i ]['parts'][ $id ][0];
			if ( $next < $count ) {
				$time = $found[ $next ]['parts'][ $id ][0] - $start;
				$size = $found[ $next ]['at'] - $found[ $i ]['at'];
			} else {
				$last = $i;
				for ( $k = $i; $k < $count; $k++ ) {
					$last = isset( $found[ $k ]['parts'][ $id ] ) ? $k : $last;
				}
				$time = $found[ $last ]['parts'][ $id ][0] + $found[ $last ]['parts'][ $id ][1] - $start;
				$size = $total - $found[ $i ]['at'];
			}
			$rows .= pack( 'NNN', $size & 0x7fffffff, max( 0, $time ), 0x90000000 ); // starts on a keyframe
			$i     = $next;
		}
		return $rows;
	}
}
