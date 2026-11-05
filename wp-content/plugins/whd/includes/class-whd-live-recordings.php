<?php
/**
 * Recording a live stream straight into the media library.
 *
 * An hour of video is over a gigabyte, far past what a shared host takes in one upload and more than a
 * browser should have to send in one go after a long stream. So the studio sends each slice as
 * MediaRecorder produces it, every few seconds, and this appends it to a part file. Pressing stop only
 * has the last slice to send, so the recording is in the library at once. A recording whose tab died
 * is still on the server up to its last slice, and the studio turns that into a video when it reopens.
 *
 * Slices are numbered, and one that has already landed is acknowledged and not written again: a retry
 * after a dropped answer would otherwise put a duplicate slice in the middle of the video.
 *
 * The attachment's metadata is written from what the studio knows rather than read by getID3. That
 * walks the whole file, which on a fragmented recording of an hour or two is long enough for this host
 * to kill the request, and the point here is that stop is immediate.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Live_Recordings {

	/** On the stream: the recording being written, [ rec, ext, seq, bytes, seconds, width, height, at ]. */
	const OPEN = '_whd_live_rec_open';

	/** On the attachment: which stream it came from. */
	const META = '_whd_live_recording';

	const DIR = 'whd-live/rec';

	const TYPES = [ 'mp4' => 'video/mp4', 'webm' => 'video/webm' ];

	/** The most one slice can be. They are about 1.5 MB; this only stops a request being something else. */
	const MAX_SLICE = 32 * MB_IN_BYTES;

	/** Part files nobody came back for. */
	const KEEP_DAYS = 7;

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes() {
		$rec   = '(?P<rec>[a-f0-9-]{36})';
		$route = static function ( string $path, string $callback ) {
			register_rest_route( WHD_Live_Rest::NS, '/live/(?P<id>\d+)/recording' . $path, [
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, $callback ],
				'permission_callback' => [ __CLASS__, 'can_record' ],
			] );
		};
		$route( '', 'start' );
		$route( '/recover', 'recover' );
		$route( '/' . $rec . '/slice', 'slice' );
		$route( '/' . $rec . '/finish', 'finish' );
	}

	public static function can_record() {
		return WHD_Live_Rest::can_host() && current_user_can( 'upload_files' );
	}

	/* ─────────────────────────── where it is ─────────────────────────── */

	private static function dir(): string {
		$up = wp_upload_dir( null, false );
		return trailingslashit( $up['basedir'] ) . self::DIR . '/';
	}

	private static function part( string $rec ): string {
		return self::dir() . $rec . '.part';
	}

	/** The folder, closed to the web: a part file is nobody's to download. */
	private static function make_dir(): bool {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		if ( ! file_exists( $dir . '.htaccess' ) ) {
			@file_put_contents( $dir . '.htaccess', "Require all denied\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			@file_put_contents( $dir . 'index.html', '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
		return true;
	}

	private static function open_state( $id ): array {
		$open = get_post_meta( (int) $id, self::OPEN, true );
		return is_array( $open ) ? $open : [];
	}

	private static function clear_old_parts() {
		foreach ( (array) glob( self::dir() . '*.part' ) as $file ) {
			if ( $file && filemtime( $file ) < time() - self::KEEP_DAYS * DAY_IN_SECONDS ) {
				wp_delete_file( $file );
			}
		}
	}

	private static function stream_id( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		return WHD_Live::exists( $id ) ? $id : new WP_Error( 'whd_live_none', __( 'That stream does not exist.', 'whd' ), [ 'status' => 404 ] );
	}

	/* ─────────────────────────── the routes ─────────────────────────── */

	public static function start( WP_REST_Request $r ) {
		$id = self::stream_id( $r );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( ! self::make_dir() ) {
			return new WP_Error( 'whd_live_rec_dir', __( 'The uploads folder can’t be written to.', 'whd' ), [ 'status' => 500 ] );
		}
		self::clear_old_parts();

		// One left unfinished by a closed tab goes to the library first, so nothing is overwritten.
		$recovered = self::finish_open( $id );

		$ext = isset( self::TYPES[ (string) $r['type'] ] ) ? (string) $r['type'] : 'webm';
		$rec = wp_generate_uuid4();
		// phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( false === @file_put_contents( self::part( $rec ), '' ) ) {
			return new WP_Error( 'whd_live_rec_dir', __( 'The uploads folder can’t be written to.', 'whd' ), [ 'status' => 500 ] );
		}
		update_post_meta( $id, self::OPEN, [
			'rec'     => $rec,
			'ext'     => $ext,
			'seq'     => 0,
			'bytes'   => 0,
			'seconds' => 0,
			'width'   => absint( $r['width'] ),
			'height'  => absint( $r['height'] ),
			'at'      => time(),
		] );
		return [ 'rec' => $rec, 'recovered' => $recovered ? [ $recovered ] : [] ];
	}

	/**
	 * One slice of the video, in the request's body, its number and the running time in the query.
	 */
	public static function slice( WP_REST_Request $r ) {
		$id = self::stream_id( $r );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$open = self::open_state( $id );
		if ( ( $open['rec'] ?? '' ) !== (string) $r['rec'] ) {
			return new WP_Error( 'whd_live_rec_gone', __( 'That recording is no longer open.', 'whd' ), [ 'status' => 404 ] );
		}

		$seq = (int) $r['seq'];
		if ( $seq < (int) $open['seq'] ) {
			return [ 'next' => (int) $open['seq'], 'bytes' => (int) $open['bytes'] ]; // a retry of one that landed
		}
		if ( $seq > (int) $open['seq'] ) {
			return new WP_Error( 'whd_live_rec_order', __( 'A slice of the recording arrived out of order.', 'whd' ), [ 'status' => 409, 'next' => (int) $open['seq'] ] );
		}

		$body = (string) $r->get_body();
		$size = strlen( $body );
		if ( ! $size || $size > self::MAX_SLICE ) {
			return new WP_Error( 'whd_live_rec_size', __( 'That slice of the recording is the wrong size.', 'whd' ), [ 'status' => 413 ] );
		}

		$file = self::part( (string) $open['rec'] );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		$wrote = @file_put_contents( $file, $body, FILE_APPEND | LOCK_EX );
		if ( $wrote !== $size ) {
			// A full disk writes part of it. Cut back, or the retry would leave a tear in the video.
			$handle = @fopen( $file, 'r+' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			if ( $handle ) {
				ftruncate( $handle, (int) $open['bytes'] );
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			WHD_Integrations::log( 'live', 507, 'Recording: the server could not store it. Is the disk or hosting quota full?' );
			return new WP_Error( 'whd_live_rec_full', __( 'The server has no room for the recording. Check your hosting storage.', 'whd' ), [ 'status' => 507 ] );
		}

		$open['seq']     = $seq + 1;
		$open['bytes']   = (int) $open['bytes'] + $size;
		$open['seconds'] = max( (int) $open['seconds'], absint( $r['t'] ) );
		$open['at']      = time();
		update_post_meta( $id, self::OPEN, $open );
		return [ 'next' => (int) $open['seq'], 'bytes' => (int) $open['bytes'] ];
	}

	public static function finish( WP_REST_Request $r ) {
		$id = self::stream_id( $r );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$open = self::open_state( $id );
		if ( ( $open['rec'] ?? '' ) !== (string) $r['rec'] ) {
			return new WP_Error( 'whd_live_rec_gone', __( 'That recording is no longer open.', 'whd' ), [ 'status' => 404 ] );
		}
		if ( $r['seconds'] ) {
			$open['seconds'] = absint( $r['seconds'] );
			update_post_meta( $id, self::OPEN, $open );
		}
		$saved = self::finish_open( $id );
		return $saved ?: new WP_Error( 'whd_live_rec_empty', __( 'Nothing was recorded.', 'whd' ), [ 'status' => 400 ] );
	}

	/** The studio opening: anything a closed tab left behind becomes a video now. */
	public static function recover( WP_REST_Request $r ) {
		$id = self::stream_id( $r );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$saved = self::finish_open( $id );
		return [ 'recovered' => $saved ? [ $saved ] : [] ];
	}

	/* ─────────────────────────── into the library ─────────────────────────── */

	/** Turn the stream's open recording into an attachment. @return array|null What the studio shows. */
	private static function finish_open( $id ) {
		$open = self::open_state( $id );
		if ( ! $open ) {
			return null;
		}
		delete_post_meta( (int) $id, self::OPEN );

		$part = self::part( (string) $open['rec'] );
		if ( ! file_exists( $part ) ) {
			return null;
		}
		if ( ! filesize( $part ) ) {
			wp_delete_file( $part );
			return null;
		}

		$up = wp_upload_dir(); // this one makes the year/month folder
		if ( ! empty( $up['error'] ) ) {
			WHD_Integrations::log( 'live', 0, 'Recording: ' . $up['error'] );
			return null;
		}

		$ext   = isset( self::TYPES[ $open['ext'] ] ) ? (string) $open['ext'] : 'webm';
		$slug  = (string) get_post_field( 'post_name', (int) $id );
		$name  = wp_unique_filename( $up['path'], 'live-' . ( $slug ?: (int) $id ) . '-' . wp_date( 'Y-m-d-Hi' ) . '.' . $ext );
		$dest  = trailingslashit( $up['path'] ) . $name;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( ! @rename( $part, $dest ) ) {
			WHD_Integrations::log( 'live', 0, 'Recording: could not move it into the media library folder.' );
			update_post_meta( (int) $id, self::OPEN, $open );
			return null;
		}
		// The permissions a file uploaded the ordinary way gets.
		@chmod( $dest, fileperms( dirname( $dest ) ) & 0000666 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

		$stream_title = html_entity_decode( get_the_title( (int) $id ), ENT_QUOTES, 'UTF-8' );
		$title        = sprintf(
			/* translators: 1: stream title, 2: date */
			__( '%1$s (live recording, %2$s)', 'whd' ),
			$stream_title,
			wp_date( get_option( 'date_format' ) . ' H:i' )
		);
		$attachment = wp_insert_attachment( [
			'post_mime_type' => self::TYPES[ $ext ],
			'post_title'     => $title,
			'post_status'    => 'inherit',
			'guid'           => trailingslashit( $up['url'] ) . $name,
		], $dest, 0, true );
		if ( is_wp_error( $attachment ) ) {
			WHD_Integrations::log( 'live', 0, 'Recording: ' . $attachment->get_error_message() );
			return null;
		}

		$seconds = (int) $open['seconds'];
		update_post_meta( $attachment, self::META, (int) $id );
		wp_update_attachment_metadata( $attachment, [
			'mime_type'        => self::TYPES[ $ext ],
			'fileformat'       => $ext,
			'dataformat'       => $ext,
			'filesize'         => (int) filesize( $dest ),
			'length'           => $seconds,
			'length_formatted' => self::clock( $seconds ),
			'width'            => (int) $open['width'],
			'height'           => (int) $open['height'],
		] );

		return [
			'id'      => (int) $attachment,
			'title'   => $title,
			'url'     => (string) wp_get_attachment_url( $attachment ),
			'edit'    => (string) get_edit_post_link( $attachment, 'raw' ),
			'seconds' => $seconds,
			'bytes'   => (int) filesize( $dest ),
		];
	}

	private static function clock( int $seconds ): string {
		$h = (int) floor( $seconds / 3600 );
		$m = (int) floor( $seconds / 60 ) % 60;
		$s = $seconds % 60;
		return $h ? sprintf( '%d:%02d:%02d', $h, $m, $s ) : sprintf( '%d:%02d', $m, $s );
	}

	/** This stream's recordings, newest first. */
	public static function of_stream( $id ): array {
		return get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 20,
			'fields'         => 'ids',
			'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => (int) $id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'orderby'        => 'ID',
			'order'          => 'DESC',
		] );
	}

	/** A stream being deleted. Finished recordings are in the library and stay; an open part file goes. */
	public static function forget( $id ) {
		$open = self::open_state( $id );
		if ( ! empty( $open['rec'] ) && file_exists( self::part( (string) $open['rec'] ) ) ) {
			wp_delete_file( self::part( (string) $open['rec'] ) );
		}
	}
}
