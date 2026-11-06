<?php
/**
 * REST routes for Live Stream: the studio's (administrators, cookie + nonce) and the one public one,
 * a viewer's comment.
 *
 * Going live is three calls on purpose, each short enough for a shared host and each safe to repeat:
 *
 *   start  makes the room and gets the platforms ready, hands the browser a host token
 *   (the browser joins the room with the canvas as its camera)
 *   on-air starts the RTMP copy, which Daily only allows with somebody in the room, and opens the page
 *
 * The page does not say "live" until on-air, so viewers never join an empty room.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Live_Rest {

	const NS = 'whd/v1';

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function can_host() {
		return current_user_can( 'manage_options' );
	}

	public static function routes() {
		$host = [
			'start'  => [ 'POST', 'start' ],
			'on-air' => [ 'POST', 'on_air' ],
			'end'    => [ 'POST', 'end' ],
			'feed'   => [ 'GET', 'feed' ],
			'showing' => [ 'POST', 'showing' ],
			'cover'  => [ 'POST', 'cover' ],
			'scene'  => [ 'POST', 'scene' ],
			'pieces' => [ 'POST', 'set_pieces' ],
		];
		foreach ( $host as $path => $route ) {
			register_rest_route( self::NS, '/live/(?P<id>\d+)/' . $path, [
				'methods'             => $route[0],
				'callback'            => [ __CLASS__, $route[1] ],
				'permission_callback' => [ __CLASS__, 'can_host' ],
			] );
		}
		register_rest_route( self::NS, '/live/products', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'products' ],
			'permission_callback' => [ __CLASS__, 'can_host' ],
		] );
		register_rest_route( self::NS, '/live/comment/(?P<cid>\d+)/(?P<do>send|hide|block)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'moderate' ],
			'permission_callback' => [ __CLASS__, 'can_host' ],
		] );

		/* Public. The page reads the static file; this is its way back when uploads cannot be written. */
		register_rest_route( self::NS, '/live/(?P<id>\d+)/comment', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'comment' ],
			'permission_callback' => '__return_true',
		] );
		register_rest_route( self::NS, '/live/(?P<id>\d+)/state', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'public_state' ],
			'permission_callback' => '__return_true',
		] );
	}

	/** The stream a request is about, or a 404 to hand straight back. */
	private static function stream_id( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		return WHD_Live::exists( $id ) ? $id : new WP_Error( 'whd_live_none', __( 'That stream does not exist.', 'whd' ), [ 'status' => 404 ] );
	}

	/**
	 * The two public routes. Having the link is the only way in, so they want the key its page holds
	 * and do not answer whoever walks the numbers.
	 */
	private static function viewer_stream( WP_REST_Request $r ) {
		$id = self::stream_id( $r );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$key = $r['k'];
		return is_string( $key ) && hash_equals( WHD_Live::file_key( $id ), $key ) ? $id : new WP_Error( 'whd_live_none', __( 'That stream does not exist.', 'whd' ), [ 'status' => 404 ] );
	}

	/** A service's refusal as a 502 with its words, so the studio can show them. */
	private static function upstream( WP_Error $error ) {
		return new WP_Error( $error->get_error_code(), $error->get_error_message(), [ 'status' => 502 ] );
	}

	/* ─────────────────────────── going live ─────────────────────────── */

	public static function start( WP_REST_Request $r ) {
		$id = self::stream_id( $r );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$stream = WHD_Live::get( $id );
		if ( 'ended' === $stream['stage'] ) {
			return new WP_Error( 'whd_live_over', __( 'This stream has ended. Make a new one to go live again.', 'whd' ), [ 'status' => 409 ] );
		}

		// TikTok's key changes by session, so it can be typed at the moment of going live.
		$tiktok = sanitize_text_field( (string) $r['tiktok_key'] );
		if ( '' !== $tiktok ) {
			$dests = (array) $stream['dests'];
			unset( $dests['tiktok'] );
			$stream = WHD_Live::patch( $id, [ 'tt_key' => $tiktok, 'dests' => $dests ] );
		}

		$room = WHD_Live_Channels::ensure_room( $id );
		if ( is_wp_error( $room ) ) {
			WHD_Integrations::log( 'live', 0, 'Daily room: ' . $room->get_error_message() );
			return self::upstream( $room );
		}

		$dests = WHD_Live_Channels::prepare( $id );
		$set   = WHD_Live_Channels::set_endpoints( $room, $dests );
		if ( is_wp_error( $set ) ) {
			WHD_Integrations::log( 'live', 0, 'Daily endpoints: ' . $set->get_error_message() );
			foreach ( $dests as $key => $dest ) {
				if ( ! empty( $dest['endpoint'] ) ) {
					$dests[ $key ]['state']   = 'failed';
					$dests[ $key ]['message'] = $set->get_error_message();
				}
			}
			WHD_Live::patch( $id, [ 'dests' => $dests ] );
		}

		$token = WHD_Live_Channels::owner_token( $id );
		if ( is_wp_error( $token ) ) {
			WHD_Integrations::log( 'live', 0, 'Daily token: ' . $token->get_error_message() );
			return self::upstream( $token );
		}

		return [
			'url'   => $room['url'],
			'token' => $token,
			'dests' => WHD_Live_Channels::public_dests( $dests ),
			'stage' => WHD_Live::get( $id )['stage'],
		];
	}

	public static function on_air( WP_REST_Request $r ) {
		$id = self::stream_id( $r );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$stream = WHD_Live::get( $id );
		$room   = (array) $stream['room'];
		if ( empty( $room['name'] ) ) {
			return new WP_Error( 'whd_live_noroom', __( 'There is no room for this stream yet.', 'whd' ), [ 'status' => 409 ] );
		}

		$dests = array_filter( (array) $stream['dests'], static fn( $d ) => 'ready' === ( $d['state'] ?? '' ) );
		$rtmp  = WHD_Live_Channels::start_rtmp( $room, $dests, (string) $stream['format'] );

		$error = '';
		if ( is_wp_error( $rtmp ) ) {
			$error = $rtmp->get_error_message();
			// Coming back to a stream whose RTMP copy never stopped is not a failure.
			if ( false !== stripos( $error, 'already' ) ) {
				$error = '';
			} else {
				WHD_Integrations::log( 'live', (int) ( $rtmp->get_error_data()['status'] ?? 0 ), 'Daily RTMP: ' . $error );
			}
		}

		$first = 'live' !== $stream['stage'];
		if ( $first ) {
			$stream = WHD_Live::patch( $id, [ 'stage' => 'live', 'started_at' => (int) $stream['started_at'] ?: time() ] );
			update_post_meta( $id, WHD_Live::RT, [ 'last_seen' => time(), 'viewers' => 0, 'peak' => 0 ] );
		}
		// A piece put on stage in the preview goes public now, and not before.
		$show = get_post_meta( $id, WHD_Live::SHOWING, true );
		if ( is_array( $show ) && ! empty( $show['id'] ) ) {
			WHD_Live::set_showing( $id, (int) $show['id'], $show['rect'] ?? null );
		}
		WHD_Live::write_state( $id );

		return [
			'stage'     => 'live',
			'startedAt' => (int) $stream['started_at'],
			'rtmpError' => $error,
		];
	}

	public static function end( WP_REST_Request $r ) {
		$id = self::stream_id( $r );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$stream = WHD_Live::get( $id );
		$room   = (array) $stream['room'];
		$stream = WHD_Live::patch( $id, [ 'stage' => 'ended', 'ended_at' => time() ] );
		delete_post_meta( $id, WHD_Live::SHOWING );
		WHD_Live::write_state( $id );

		// The page knows at once; the services can take their time after the answer has gone.
		WHD_Plugin::after_response( static function () use ( $id, $room ) {
			if ( ! empty( $room['name'] ) ) {
				WHD_Live_Channels::stop_rtmp( $room );
				WHD_Live_Channels::retire_room( $room );
			}
			WHD_Live_Channels::end_platforms( $id );
		} );

		$rt = WHD_Live::rt( $id );
		return [
			'stage'   => 'ended',
			'stats'   => WHD_Live_Comments::stats( $id ),
			'peak'    => (int) $rt['peak'],
			'seconds' => (int) $stream['started_at'] ? max( 0, (int) $stream['ended_at'] - (int) $stream['started_at'] ) : 0,
		];
	}

	/* ─────────────────────────── on air ─────────────────────────── */

	/**
	 * The studio's heartbeat, every couple of seconds: it says the studio is alive and how many are
	 * watching, gets back what is new, and leaves the platform work to run after the answer has gone.
	 */
	public static function feed( WP_REST_Request $r ) {
		$id = self::stream_id( $r );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$stream  = WHD_Live::get( $id );
		$rt      = WHD_Live::rt( $id );
		$viewers = max( 0, (int) $r['viewers'] );

		if ( 'live' === $stream['stage'] ) {
			$changed         = $viewers !== (int) $rt['viewers'];
			$rt['last_seen'] = time();
			$rt['viewers']   = $viewers;
			$rt['peak']      = max( (int) $rt['peak'], $viewers );
			update_post_meta( $id, WHD_Live::RT, $rt );
			if ( $changed ) {
				WHD_Live::write_state( $id );
			}
			WHD_Plugin::after_response( static function () use ( $id ) {
				WHD_Live_Comments::work( $id );
			} );
		}

		$pull = WHD_Live_Comments::pull_state( $id );
		$feed = WHD_Live_Comments::for_studio( $id, max( 0, (int) $r['after'] ) );
		return [
			'new'    => $feed['new'],
			'moved'  => $feed['moved'],
			'stats'  => WHD_Live_Comments::stats( $id ),
			'peak'   => (int) $rt['peak'],
			'errors' => array_filter( [ 'facebook' => (string) $pull['fb_error'], 'youtube' => (string) $pull['yt_error'] ] ),
		];
	}

	/**
	 * Which piece is on screen, and where its card sits, as fractions of the picture.
	 * The page puts a clickable area over that spot, since the card itself is part of the video.
	 */
	public static function showing( WP_REST_Request $r ) {
		$id = self::stream_id( $r );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$pid = absint( $r['product'] );
		$rect = array_map( static fn( $n ) => round( max( 0, min( 1, (float) $n ) ), 4 ), array_slice( (array) $r['rect'], 0, 4 ) );
		WHD_Live::set_showing( $id, $pid, 4 === count( $rect ) ? $rect : null );
		WHD_Live::write_state( $id );
		return [ 'ok' => true ];
	}

	/**
	 * The picture is covered, or no longer. The cover itself is in the video; the page only needs to
	 * know so nothing clickable is left floating over a product card that is not showing.
	 */
	public static function cover( WP_REST_Request $r ) {
		$id = self::stream_id( $r );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( $r['covered'] ) {
			update_post_meta( $id, WHD_Live::COVER, 1 );
		} else {
			delete_post_meta( $id, WHD_Live::COVER );
		}
		WHD_Live::write_state( $id );
		return [ 'ok' => true ];
	}

	public static function scene( WP_REST_Request $r ) {
		$id = self::stream_id( $r );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$scene = $r->get_param( 'scene' );
		if ( ! is_array( $scene ) ) {
			return new WP_Error( 'whd_live_scene', __( 'Nothing to save.', 'whd' ), [ 'status' => 400 ] );
		}
		$json = wp_json_encode( $scene );
		if ( false === $json || strlen( $json ) > 200 * KB_IN_BYTES ) {
			return new WP_Error( 'whd_live_scene', __( 'That is too much to save.', 'whd' ), [ 'status' => 413 ] );
		}
		// JSON in meta, slashed the way update_post_meta expects, or every backslash in it is lost.
		update_post_meta( $id, WHD_Live::SCENE, wp_slash( $json ) );
		return [ 'ok' => true ];
	}

	public static function set_pieces( WP_REST_Request $r ) {
		$id = self::stream_id( $r );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		WHD_Live::set_pieces( $id, (array) $r->get_param( 'pieces' ) );
		return [ 'pieces' => WHD_Live::pieces( $id ) ];
	}

	/**
	 * Product search for the form and the studio: by name, or an exact SKU, within a category if one
	 * is chosen, a page at a time. No words: the newest pieces.
	 */
	public static function products( WP_REST_Request $r ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return [ 'items' => [], 'total' => 0, 'more' => false ];
		}
		$q    = trim( sanitize_text_field( (string) $r['q'] ) );
		$cat  = absint( $r['cat'] );
		$page = max( 1, absint( $r['page'] ) );
		$per  = min( 30, max( 1, absint( $r['per_page'] ) ?: 12 ) );
		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $per,
			'paged'          => $page,
			'fields'         => 'ids',
			'orderby'        => '' === $q ? 'date' : 'relevance',
			'order'          => 'DESC',
		];
		if ( '' !== $q ) {
			$args['s'] = $q;
		}
		if ( $cat ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			$args['tax_query'] = [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat ] ];
		}
		$found = new WP_Query( $args );
		$ids   = array_map( 'intval', $found->posts );
		$total = (int) $found->found_posts;

		// A SKU typed in full goes first. A size's own SKU finds the piece it belongs to.
		if ( '' !== $q && ! $cat && 1 === $page ) {
			$by_sku = (int) wc_get_product_id_by_sku( $q );
			$hit    = $by_sku ? wc_get_product( $by_sku ) : null;
			$parent = $hit ? ( $hit->get_parent_id() ?: $by_sku ) : 0;
			if ( $parent && 'publish' === get_post_status( $parent ) ) {
				if ( ! in_array( $parent, $ids, true ) ) {
					++$total;
				}
				$ids = array_values( array_unique( array_merge( [ $parent ], $ids ) ) );
			}
		}

		$out = [];
		foreach ( $ids as $pid ) {
			$piece = WHD_Live::piece( $pid, 0, '', false );
			if ( $piece ) {
				unset( $piece['options'], $piece['short'] );
				$out[] = $piece;
			}
		}
		return [ 'items' => $out, 'total' => $total, 'more' => $page < (int) $found->max_num_pages ];
	}

	public static function moderate( WP_REST_Request $r ) {
		$cid = (int) $r['cid'];
		switch ( (string) $r['do'] ) {
			case 'send':
				$sent = WHD_Live_Comments::send_by_hand( $cid, absint( $r['product'] ) );
				return is_wp_error( $sent ) ? $sent : [ 'row' => $sent ];
			case 'hide':
				$stream = WHD_Live_Comments::hide( $cid );
				break;
			default:
				$stream = WHD_Live_Comments::block( $cid );
		}
		if ( $stream ) {
			WHD_Live::write_state( $stream );
		}
		return [ 'ok' => (bool) $stream ];
	}

	/* ─────────────────────────── a viewer's comment ─────────────────────────── */

	/**
	 * Twelve a minute, and not two within a second and a half, per address. No nonce: the page may
	 * outlive one, and there is nothing here a forged request could do that a real one could not.
	 */
	private static function rate_limited(): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'whd_lc_' . md5( $ip );
		$hit = get_transient( $key );
		$now = microtime( true );
		$hit = is_array( $hit ) ? array_values( array_filter( $hit, static fn( $t ) => $now - $t < 60 ) ) : [];
		if ( count( $hit ) >= 12 || ( $hit && $now - end( $hit ) < 1.5 ) ) {
			return true;
		}
		$hit[] = $now;
		set_transient( $key, $hit, MINUTE_IN_SECONDS );
		return false;
	}

	public static function comment( WP_REST_Request $r ) {
		$id = self::viewer_stream( $r );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		// The empty field people cannot see. A bot that fills it is told all is well.
		if ( '' !== (string) $r['website'] ) {
			return [ 'ok' => true ];
		}

		$stream = WHD_Live::get( $id );
		if ( ! WHD_Live::has_site( $stream ) || 'live' !== $stream['stage'] ) {
			return new WP_Error( 'whd_live_closed', __( 'Comments open when we go live.', 'whd' ), [ 'status' => 409 ] );
		}

		$message = WHD_Live_Comments::clean_text( (string) $r['message'] );
		if ( '' === $message ) {
			return new WP_Error( 'whd_live_empty', __( 'Type a comment first.', 'whd' ), [ 'status' => 400 ] );
		}
		if ( self::rate_limited() ) {
			return new WP_Error( 'whd_live_fast', __( 'One moment, you’re going fast.', 'whd' ), [ 'status' => 429 ] );
		}

		// Nobody is asked who they are. The browser's own id keeps one person the same guest, and blockable.
		$typed  = mb_substr( trim( sanitize_text_field( (string) $r['name'] ) ), 0, 60 );
		$viewer = substr( (string) preg_replace( '/[^a-zA-Z0-9-]/', '', (string) $r['viewer'] ), 0, 64 );
		$viewer = '' !== $viewer ? $viewer : wp_generate_uuid4();
		$name   = '' !== $typed ? $typed : WHD_Live_Comments::guest_name( $viewer );

		$row = WHD_Live_Comments::ingest( $id, [
			'source'      => 'site',
			'external_id' => wp_generate_uuid4(),
			'author'      => $name,
			'author_key'  => 'site:' . md5( $viewer ),
			'message'     => mb_substr( $message, 0, 300 ),
		] );
		if ( ! $row ) {
			return new WP_Error( 'whd_live_save', __( 'That didn’t send. Try again.', 'whd' ), [ 'status' => 500 ] );
		}

		WHD_Live::write_state( $id );

		$piece = null;
		if ( 'sent' === $row['reply_status'] && $row['product_id'] ) {
			$rows = WHD_Live::piece_rows( $id );
			$at    = array_search( (int) $row['product_id'], array_column( $rows, 'id' ), true );
			$piece = WHD_Live::piece( (int) $row['product_id'], false === $at ? 0 : $at + 1, false === $at ? '' : $rows[ $at ]['code'] );
		}

		return [
			'ok'      => true,
			'as'      => $name,
			'comment' => $row['hidden'] ? null : [ 'i' => (int) $row['id'], 'n' => WHD_Live_Comments::first_name( $name ), 'm' => (string) $row['message'] ],
			'piece'   => $piece,
			'reply'   => $piece ? self::site_reply( $typed, $piece['name'] ) : '',
		];
	}

	/** The greeting in the side panel. With no name to use, "Here it is, ." would read as a slip. */
	private static function site_reply( string $typed, string $product ): string {
		$text = strtr( (string) WHD_Live::settings()['site_text'], [ '{name}' => WHD_Live_Comments::first_name( $typed ), '{product}' => $product ] );
		if ( '' !== $typed ) {
			return $text;
		}
		$text = (string) preg_replace( [ '/[\s,]+([.!?])/u', '/\s+,/u', '/\s{2,}/u' ], [ '$1', ',', ' ' ], $text );
		return ucfirst( ltrim( $text, ' ,.!?' ) );
	}

	public static function public_state( WP_REST_Request $r ) {
		$id = self::viewer_stream( $r );
		return is_wp_error( $id ) ? $id : WHD_Live::state_data( $id );
	}

}
