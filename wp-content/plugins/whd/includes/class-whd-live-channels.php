<?php
/**
 * The outside services a live stream talks to: Daily (the room and the RTMP copy), Facebook and
 * YouTube (create the broadcast, read the comments, answer them) and TikTok (a stream key, because
 * that is all TikTok offers).
 *
 * Every secret stays in this file's requests. A stream key goes to Daily as a named endpoint, and
 * from then on the browser and Daily's own error messages only ever see the name.
 *
 * Costs and limits that shaped this, from each platform's documentation:
 *
 * - Daily: live streaming needs a card on file. Rooms hold 200 until Daily raises it for the account.
 * - Facebook: the Page needs 100 followers and a 60-day-old account to go live. A private reply is
 *   one per comment, within 7 days, and reaches only people with a role on the app until
 *   `pages_messaging` has Advanced Access — which is why a failed one falls back to a comment.
 * - YouTube: 10,000 quota units a day. Reading the chat is 5 a call, replying 20. YouTube's own
 *   streaming endpoint for the chat is gRPC, which PHP on shared hosting cannot hold open, so the
 *   chat is polled no faster than YOUTUBE_EVERY. A reply has to fit in 200 characters.
 * - TikTok: no public API for live comments or replies; the only tools for it are reverse-engineered
 *   and not something to build a shop on. The card on screen carries the short link instead.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Live_Channels {

	const DAILY_API = 'https://api.daily.co/v1';
	const GRAPH_API = 'https://graph.facebook.com/v27.0';
	const YT_API    = 'https://www.googleapis.com/youtube/v3';
	const YT_TOKEN  = 'https://oauth2.googleapis.com/token';
	const YT_AUTH   = 'https://accounts.google.com/o/oauth2/v2/auth';
	const YT_SCOPE  = 'https://www.googleapis.com/auth/youtube.force-ssl';

	/** Seconds between pulls. YouTube's is what a two-hour stream can spend inside the daily quota. */
	const FACEBOOK_EVERY = 4;
	const YOUTUBE_EVERY  = 10;

	/* ─────────────────────────── plumbing ─────────────────────────── */

	/** One JSON request. @return array|WP_Error The decoded body, or an error in the service's words. */
	private static function http( string $service, string $method, string $url, array $headers = [], $body = null, int $timeout = 15 ) {
		$args = [
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => $headers,
		];
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		} elseif ( 'POST' === $method ) {
			$args['body']                      = '';
			$args['headers']['Content-Length'] = '0';
		}
		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$data = is_array( $data ) ? $data : [];
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( $service . '_' . $code, self::error_words( $service, $code, $data ), [ 'status' => $code, 'body' => $data ] );
		}
		return $data;
	}

	/** Pull the sentence out of each service's own error shape. */
	private static function error_words( string $service, int $code, array $data ): string {
		switch ( $service ) {
			case 'daily':
				$words = (string) ( $data['info'] ?? $data['error'] ?? '' );
				break;
			case 'facebook':
				$words = (string) ( $data['error']['error_user_msg'] ?? $data['error']['message'] ?? '' );
				break;
			default:
				$words = (string) ( $data['error']['message'] ?? $data['error_description'] ?? '' );
		}
		/* translators: 1: service, 2: HTTP status */
		return '' !== $words ? $words : sprintf( __( '%1$s answered with an error (HTTP %2$d).', 'whd' ), ucfirst( $service ), $code );
	}

	/* ─────────────────────────── Daily ─────────────────────────── */

	public static function daily( string $method, string $path, $body = null ) {
		$key = (string) WHD_Live::settings()['daily_api_key'];
		if ( '' === $key ) {
			return new WP_Error( 'whd_live_no_key', __( 'Add your Daily API key in WHD → Settings → Live Stream.', 'whd' ) );
		}
		return self::http( 'daily', $method, self::DAILY_API . $path, [ 'Authorization' => 'Bearer ' . $key ], $body );
	}

	/** Is the key good, and for which Daily domain? */
	public static function daily_test() {
		return self::daily( 'GET', '/' );
	}

	/**
	 * This stream's room, made once and reused when the host comes back to it.
	 *
	 * `enable_hidden_participants` is what makes it a broadcast: anybody without an owner token
	 * joins hidden, can only receive, and cannot turn on a camera. It also takes the room past the
	 * point where every viewer would be listed to every other one.
	 */
	public static function ensure_room( $id ) {
		$stream = WHD_Live::get( $id );
		$exp    = time() + WHD_Live::MAX_SECONDS;
		$name   = (string) ( $stream['room']['name'] ?? '' );

		if ( '' !== $name ) {
			$room = self::daily( 'POST', '/rooms/' . rawurlencode( $name ), [ 'properties' => [ 'exp' => $exp ] ] );
			if ( ! is_wp_error( $room ) && ! empty( $room['url'] ) ) {
				return [ 'name' => $name, 'url' => (string) $room['url'] ];
			}
			if ( is_wp_error( $room ) && 404 !== (int) ( $room->get_error_data()['status'] ?? 0 ) ) {
				return $room; // anything but "gone" is worth telling the host about
			}
		}

		$properties = (array) apply_filters( 'whd_live_room_properties', [
			'enable_mesh_sfu'            => true,
			'enable_terse_logging'       => true,
			'enable_hidden_participants' => true,
			'exp'                        => $exp,
			'eject_at_room_exp'          => true,
		], $id );

		$room = self::daily( 'POST', '/rooms', [
			'name'       => 'whd-live-' . (int) $id . '-' . strtolower( wp_generate_password( 6, false ) ),
			'privacy'    => 'public',
			'properties' => $properties,
		] );
		if ( is_wp_error( $room ) ) {
			return $room;
		}
		if ( empty( $room['url'] ) || empty( $room['name'] ) ) {
			return new WP_Error( 'whd_live_room', __( 'Daily did not return a room.', 'whd' ) );
		}
		$out = [ 'name' => (string) $room['name'], 'url' => (string) $room['url'] ];
		WHD_Live::patch( $id, [ 'room' => $out ] );
		return $out;
	}

	/** The host's way in: an owner token, for as long as the room lasts. */
	public static function owner_token( $id ) {
		$stream = WHD_Live::get( $id );
		$res    = self::daily( 'POST', '/meeting-tokens', [
			'properties' => [
				'room_name'       => (string) ( $stream['room']['name'] ?? '' ),
				'is_owner'        => true,
				'user_name'       => (string) get_bloginfo( 'name' ),
				'exp'             => time() + WHD_Live::MAX_SECONDS,
				'start_video_off' => false,
				'start_audio_off' => false,
			],
		] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return empty( $res['token'] ) ? new WP_Error( 'whd_live_token', __( 'Daily did not return a host token.', 'whd' ) ) : (string) $res['token'];
	}

	/**
	 * Hand Daily the stream keys under names, so it can start them and report on them by name.
	 *
	 * Daily's errors reduce a raw URL to scheme://host, which could not tell the studio which
	 * platform failed; `details.endpoints` and `live-streaming-updated` use the name.
	 */
	public static function set_endpoints( array $room, array $dests ) {
		$endpoints = [];
		foreach ( $dests as $dest ) {
			if ( ! empty( $dest['endpoint'] ) && ! empty( $dest['url'] ) ) {
				$endpoints[] = [
					'name'        => (string) $dest['endpoint'],
					'type'        => 'rtmp',
					'rtmp_config' => [ 'url' => (string) $dest['url'] ],
				];
			}
		}
		if ( ! $endpoints ) {
			return true;
		}
		$res = self::daily( 'POST', '/rooms/' . rawurlencode( $room['name'] ), [ 'properties' => [ 'streaming_endpoints' => $endpoints ] ] );
		return is_wp_error( $res ) ? $res : true;
	}

	/** Start the RTMP copy. Daily needs somebody in the room first, so this runs once the host has joined. */
	public static function start_rtmp( array $room, array $dests, string $format ) {
		$names = [];
		foreach ( $dests as $dest ) {
			if ( ! empty( $dest['endpoint'] ) && ! empty( $dest['url'] ) ) {
				$names[] = [ 'endpoint' => (string) $dest['endpoint'] ];
			}
		}
		if ( ! $names ) {
			return true;
		}
		$portrait = 'portrait' === $format;
		$res      = self::daily( 'POST', '/rooms/' . rawurlencode( $room['name'] ) . '/live-streaming/start', [
			'endpoints'      => $names,
			'width'          => $portrait ? 720 : 1280,
			'height'         => $portrait ? 1280 : 720,
			'fps'            => 30,
			'videoBitrate'   => 3000,
			'audioBitrate'   => 128,
			'minIdleTimeOut' => 300, // a reloaded studio has five minutes to come back before the platforms drop
			'maxDuration'    => WHD_Live::MAX_SECONDS,
			'backgroundColor' => '#000000',
			// The host's canvas is the whole picture; nothing to lay out.
			'layout'         => [ 'preset' => 'default', 'max_cam_streams' => 1 ],
		] );
		return is_wp_error( $res ) ? $res : true;
	}

	public static function stop_rtmp( array $room ) {
		return self::daily( 'POST', '/rooms/' . rawurlencode( $room['name'] ) . '/live-streaming/stop' );
	}

	/** End the room a minute from now, so the RTMP copy can finish cleanly and nobody lingers. */
	public static function retire_room( array $room ) {
		return self::daily( 'POST', '/rooms/' . rawurlencode( $room['name'] ), [
			'properties' => [ 'exp' => time() + MINUTE_IN_SECONDS, 'eject_at_room_exp' => true ],
		] );
	}

	/* ─────────────────────────── getting each destination ready ─────────────────────────── */

	/**
	 * Get every chosen platform ready to receive. One failing does not stop the others: a live on
	 * the site and YouTube is still worth having if Facebook said no, and the studio says which.
	 *
	 * Called again when the host retries or comes back, so it reuses what already exists: a second
	 * Facebook live video would be a second post on the Page.
	 *
	 * @return array[] key => endpoint, url (secret), state ready|failed|local, message, live_url
	 */
	public static function prepare( $id ): array {
		$stream = WHD_Live::get( $id );
		$dests  = [];
		$known  = (array) $stream['dests'];

		foreach ( (array) $stream['destinations'] as $key ) {
			if ( 'site' === $key ) {
				$dests['site'] = [ 'state' => 'local', 'message' => '', 'live_url' => WHD_Live::page_url( $id ) ];
				continue;
			}
			if ( ! empty( $known[ $key ]['url'] ) ) {
				$dests[ $key ] = $known[ $key ];
				continue;
			}
			switch ( $key ) {
				case 'facebook':
					$dests[ $key ] = self::facebook_prepare( $id );
					break;
				case 'youtube':
					$dests[ $key ] = self::youtube_prepare( $id );
					break;
				case 'tiktok':
					$dests[ $key ] = self::tiktok_prepare( $id );
					break;
			}
		}
		WHD_Live::patch( $id, [ 'dests' => $dests ] );
		return $dests;
	}

	/** What the studio may be told about the destinations: everything but the URLs, which hold the keys. */
	public static function public_dests( array $dests ): array {
		$out = [];
		foreach ( $dests as $key => $dest ) {
			$out[ $key ] = [
				'endpoint' => (string) ( $dest['endpoint'] ?? '' ),
				'state'    => (string) ( $dest['state'] ?? '' ),
				'message'  => (string) ( $dest['message'] ?? '' ),
				'live_url' => (string) ( $dest['live_url'] ?? '' ),
			];
		}
		return $out;
	}

	private static function failed( string $message ): array {
		return [ 'endpoint' => '', 'url' => '', 'state' => 'failed', 'message' => $message, 'live_url' => '' ];
	}

	/** Join a server address and a stream key, whichever way the platform wrote them. */
	private static function join_url( string $server, string $key ): string {
		$server = trim( $server );
		$key    = trim( $key );
		return '' === $key ? $server : rtrim( $server, '/' ) . '/' . ltrim( $key, '/' );
	}

	/** What a platform shows under the video: the stream's words, and where to shop along. */
	private static function description( $id ): string {
		$text = trim( (string) get_post_field( 'post_excerpt', $id ) );
		if ( WHD_Live::has_site( WHD_Live::get( $id ) ) ) {
			/* translators: %s: this stream's page */
			$text = trim( $text . "\n\n" . sprintf( __( 'Shop along: %s', 'whd' ), WHD_Live::page_url( $id ) ) );
		}
		return $text;
	}

	/* ─────────────────────────── Facebook ─────────────────────────── */

	private static function graph( string $method, string $path, array $params = [] ) {
		$s = WHD_Live::settings();
		$params['access_token'] = (string) $s['fb_token'];
		$url = self::GRAPH_API . $path;
		if ( 'GET' === $method ) {
			return self::http( 'facebook', 'GET', add_query_arg( array_map( 'rawurlencode', $params ), $url ) );
		}
		return self::http( 'facebook', $method, $url, [], $params );
	}

	public static function facebook_test() {
		$s = WHD_Live::settings();
		if ( '' === (string) $s['fb_token'] || '' === (string) $s['fb_page_id'] ) {
			return new WP_Error( 'whd_live_fb', __( 'Add the Page ID and the Page access token first.', 'whd' ) );
		}
		$page = self::graph( 'GET', '/' . rawurlencode( $s['fb_page_id'] ), [ 'fields' => 'id,name,followers_count' ] );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$live = self::graph( 'GET', '/' . rawurlencode( $s['fb_page_id'] ) . '/live_videos', [ 'limit' => '1', 'fields' => 'id' ] );
		$page['can_read_live'] = ! is_wp_error( $live );
		return $page;
	}

	private static function facebook_prepare( $id ): array {
		$s     = WHD_Live::settings();
		$title = html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' );

		if ( '' !== (string) $s['fb_token'] && '' !== (string) $s['fb_page_id'] ) {
			$live = self::graph( 'POST', '/' . rawurlencode( $s['fb_page_id'] ) . '/live_videos', [
				'status'      => 'LIVE_NOW',
				'title'       => mb_substr( $title, 0, 254 ),
				'description' => self::description( $id ),
			] );
			if ( ! is_wp_error( $live ) && ! empty( $live['secure_stream_url'] ) ) {
				$facts = self::graph( 'GET', '/' . rawurlencode( (string) $live['id'] ), [ 'fields' => 'permalink_url,video' ] );
				$link  = ! is_wp_error( $facts ) && ! empty( $facts['permalink_url'] ) ? 'https://www.facebook.com' . $facts['permalink_url'] : '';
				WHD_Live::patch( $id, [ 'fb' => [ 'live_id' => (string) $live['id'], 'permalink' => $link ] ] );
				return [ 'endpoint' => 'whd_facebook', 'url' => (string) $live['secure_stream_url'], 'state' => 'ready', 'message' => '', 'live_url' => $link ];
			}
			$why = is_wp_error( $live ) ? self::facebook_words( $live ) : __( 'Facebook did not return a stream address.', 'whd' );
			WHD_Integrations::log( 'live', 0, 'Facebook: ' . $why );
			// The pasted key is the way round a token that may not create videos.
			if ( '' === (string) $s['fb_key'] ) {
				return self::failed( $why );
			}
		}

		if ( '' !== (string) $s['fb_key'] && '' !== (string) $s['fb_server'] ) {
			return [ 'endpoint' => 'whd_facebook', 'url' => self::join_url( $s['fb_server'], $s['fb_key'] ), 'state' => 'ready', 'message' => '', 'live_url' => '' ];
		}
		return self::failed( __( 'No Page token or stream key saved.', 'whd' ) );
	}

	/** Meta's "subcode" errors are the ones a person can actually do something about. */
	private static function facebook_words( WP_Error $error ): string {
		$body = (array) ( $error->get_error_data()['body'] ?? [] );
		$sub  = (int) ( $body['error']['error_subcode'] ?? 0 );
		if ( in_array( $sub, [ 1363120, 1363144 ], true ) ) {
			return __( 'Facebook only lets a Page with 100+ followers and a 60-day-old account go live.', 'whd' );
		}
		return $error->get_error_message();
	}

	/**
	 * A stream started from a pasted key has no live video id, and comments hang off that id.
	 * Once a token is saved, find it by looking for what is on air on the Page.
	 */
	public static function facebook_discover( $id ): string {
		$s   = WHD_Live::settings();
		$res = self::graph( 'GET', '/' . rawurlencode( $s['fb_page_id'] ) . '/live_videos', [
			'broadcast_status' => '["LIVE"]',
			'fields'           => 'id,permalink_url',
			'limit'            => '1',
		] );
		if ( is_wp_error( $res ) || empty( $res['data'][0]['id'] ) ) {
			return '';
		}
		$found = $res['data'][0];
		$link  = ! empty( $found['permalink_url'] ) ? 'https://www.facebook.com' . $found['permalink_url'] : '';
		WHD_Live::patch( $id, [ 'fb' => [ 'live_id' => (string) $found['id'], 'permalink' => $link ] ] );
		return (string) $found['id'];
	}

	/**
	 * New comments on the live video, oldest first.
	 *
	 * `live_filter=no_filter` because Facebook's default drops what it scores as low quality, and
	 * a one-word code is exactly the kind of comment it scores low. `from` only arrives with the
	 * right permission, so its absence is not treated as a failure.
	 *
	 * @return array|WP_Error
	 */
	public static function facebook_pull( string $live_id, int $since ) {
		$res = self::graph( 'GET', '/' . rawurlencode( $live_id ) . '/comments', [
			'fields'      => 'id,message,created_time,from{id,name}',
			'filter'      => 'stream',
			'live_filter' => 'no_filter',
			'order'       => 'reverse_chronological',
			'limit'       => '50',
			'since'       => (string) $since,
		] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array_reverse( (array) ( $res['data'] ?? [] ) );
	}

	/** The link, by Messenger. @return true|WP_Error */
	public static function facebook_dm( string $comment_id, string $text ) {
		$s   = WHD_Live::settings();
		$res = self::http(
			'facebook',
			'POST',
			self::GRAPH_API . '/' . rawurlencode( $s['fb_page_id'] ) . '/messages?access_token=' . rawurlencode( $s['fb_token'] ),
			[],
			[ 'recipient' => [ 'comment_id' => $comment_id ], 'message' => [ 'text' => mb_substr( $text, 0, 1900 ) ] ]
		);
		return is_wp_error( $res ) ? $res : true;
	}

	/** A reply under the comment. @return true|WP_Error */
	public static function facebook_comment( string $comment_id, string $text ) {
		$res = self::graph( 'POST', '/' . rawurlencode( $comment_id ) . '/comments', [ 'message' => mb_substr( $text, 0, 1900 ) ] );
		return is_wp_error( $res ) ? $res : true;
	}

	private static function facebook_end( array $stream ) {
		if ( ! empty( $stream['fb']['live_id'] ) ) {
			self::graph( 'POST', '/' . rawurlencode( (string) $stream['fb']['live_id'] ), [ 'end_live_video' => 'true' ] );
		}
	}

	/* ─────────────────────────── YouTube ─────────────────────────── */

	public static function youtube_redirect_uri(): string {
		return admin_url( 'admin-post.php?action=whd_live_yt_oauth' );
	}

	public static function youtube_auth_url( string $state ): string {
		$s = WHD_Live::settings();
		return add_query_arg( array_map( 'rawurlencode', [
			'client_id'     => (string) $s['yt_client_id'],
			'redirect_uri'  => self::youtube_redirect_uri(),
			'response_type' => 'code',
			'scope'         => self::YT_SCOPE,
			'access_type'   => 'offline',
			'prompt'        => 'consent', // otherwise a second connection comes back without a refresh token
			'state'         => $state,
		] ), self::YT_AUTH );
	}

	/** Google's token endpoint takes a form, not JSON. */
	private static function youtube_token_request( array $fields ) {
		$s   = WHD_Live::settings();
		$res = wp_remote_post( self::YT_TOKEN, [
			'timeout' => 15,
			'body'    => array_merge( [
				'client_id'     => (string) $s['yt_client_id'],
				'client_secret' => (string) $s['yt_client_secret'],
			], $fields ),
		] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$data = is_array( $data ) ? $data : [];
		if ( empty( $data['access_token'] ) ) {
			return new WP_Error( 'whd_live_yt_token', (string) ( $data['error_description'] ?? $data['error'] ?? __( 'Google did not return a token.', 'whd' ) ), [ 'body' => $data ] );
		}
		return $data;
	}

	/** Finish connecting: swap the code for a refresh token and note whose channel it is. */
	public static function youtube_connect( string $code ) {
		$tokens = self::youtube_token_request( [
			'code'         => $code,
			'grant_type'   => 'authorization_code',
			'redirect_uri' => self::youtube_redirect_uri(),
		] );
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}
		if ( empty( $tokens['refresh_token'] ) ) {
			return new WP_Error( 'whd_live_yt_refresh', __( 'Google did not grant lasting access. Remove this app under your Google account’s third-party access and connect again.', 'whd' ) );
		}
		set_transient( 'whd_live_yt_access', (string) $tokens['access_token'], max( 60, (int) ( $tokens['expires_in'] ?? 3600 ) - 60 ) );

		$channel = self::http( 'youtube', 'GET', self::YT_API . '/channels?part=snippet&mine=true', [ 'Authorization' => 'Bearer ' . $tokens['access_token'] ] );
		$name    = is_wp_error( $channel ) ? '' : (string) ( $channel['items'][0]['snippet']['title'] ?? '' );

		update_option( WHD_Live::OPTION_YT, [ 'yt_refresh' => (string) $tokens['refresh_token'], 'yt_channel' => $name ], false );
		return $name;
	}

	public static function youtube_disconnect() {
		delete_option( WHD_Live::OPTION_YT );
		delete_transient( 'whd_live_yt_access' );
	}

	private static function youtube_access() {
		$cached = get_transient( 'whd_live_yt_access' );
		if ( $cached ) {
			return (string) $cached;
		}
		$s = WHD_Live::settings();
		if ( '' === (string) $s['yt_refresh'] ) {
			return new WP_Error( 'whd_live_yt', __( 'Connect your YouTube channel in WHD → Settings → Live Stream.', 'whd' ) );
		}
		$tokens = self::youtube_token_request( [ 'refresh_token' => (string) $s['yt_refresh'], 'grant_type' => 'refresh_token' ] );
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}
		set_transient( 'whd_live_yt_access', (string) $tokens['access_token'], max( 60, (int) ( $tokens['expires_in'] ?? 3600 ) - 60 ) );
		return (string) $tokens['access_token'];
	}

	private static function yt( string $method, string $path, $body = null ) {
		$access = self::youtube_access();
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		return self::http( 'youtube', $method, self::YT_API . $path, [ 'Authorization' => 'Bearer ' . $access ], $body );
	}

	public static function youtube_test() {
		$res = self::yt( 'GET', '/channels?part=snippet,status&mine=true' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( empty( $res['items'][0] ) ) {
			return new WP_Error( 'whd_live_yt', __( 'This Google account has no YouTube channel.', 'whd' ) );
		}
		return $res['items'][0];
	}

	/** YouTube's reason, in its own short code, is what tells a quota problem from any other. */
	private static function youtube_reason( WP_Error $error ): string {
		$body = (array) ( $error->get_error_data()['body'] ?? [] );
		return (string) ( $body['error']['errors'][0]['reason'] ?? '' );
	}

	private static function youtube_words( WP_Error $error ): string {
		switch ( self::youtube_reason( $error ) ) {
			case 'liveStreamingNotEnabled':
				return __( 'This channel is not enabled for live streaming yet. YouTube can take 24 hours after you ask.', 'whd' );
			case 'quotaExceeded':
				return __( 'YouTube’s daily API quota is used up. It resets at midnight Pacific time.', 'whd' );
		}
		return $error->get_error_message();
	}

	/**
	 * Broadcast + stream + bind, with auto start and stop so nobody has to press "Go live" on YouTube
	 * as well: it goes live when the video arrives and ends about a minute after it stops.
	 */
	private static function youtube_prepare( $id ): array {
		$s = WHD_Live::settings();

		if ( '' !== (string) $s['yt_refresh'] ) {
			$made = self::youtube_create( $id );
			if ( ! is_wp_error( $made ) ) {
				return $made;
			}
			$why = self::youtube_words( $made );
			WHD_Integrations::log( 'live', 0, 'YouTube: ' . $why );
			if ( '' === (string) $s['yt_key'] ) {
				return self::failed( $why );
			}
		}

		if ( '' !== (string) $s['yt_key'] && '' !== (string) $s['yt_server'] ) {
			return [ 'endpoint' => 'whd_youtube', 'url' => self::join_url( $s['yt_server'], $s['yt_key'] ), 'state' => 'ready', 'message' => '', 'live_url' => '' ];
		}
		return self::failed( __( 'No connected channel or stream key saved.', 'whd' ) );
	}

	private static function youtube_create( $id ) {
		$s       = WHD_Live::settings();
		$title   = mb_substr( html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ), 0, 100 );
		$privacy = in_array( $s['yt_privacy'], [ 'public', 'unlisted', 'private' ], true ) ? $s['yt_privacy'] : 'public';

		$broadcast = self::yt( 'POST', '/liveBroadcasts?part=id,snippet,contentDetails,status', [
			'snippet'        => [
				'title'              => $title,
				'description'        => self::description( $id ),
				'scheduledStartTime' => gmdate( 'c' ),
			],
			'status'         => [ 'privacyStatus' => $privacy, 'selfDeclaredMadeForKids' => false ],
			'contentDetails' => [
				'enableAutoStart'   => true,
				'enableAutoStop'    => true,
				'enableDvr'         => true,
				'recordFromStart'   => true,
				'latencyPreference' => 'low',
			],
		] );
		if ( is_wp_error( $broadcast ) ) {
			return $broadcast;
		}

		$stream = self::yt( 'POST', '/liveStreams?part=id,snippet,cdn,contentDetails,status', [
			'snippet'        => [ 'title' => $title ],
			'cdn'            => [ 'ingestionType' => 'rtmp', 'frameRate' => 'variable', 'resolution' => 'variable' ],
			'contentDetails' => [ 'isReusable' => false ],
		] );
		if ( is_wp_error( $stream ) ) {
			return $stream;
		}

		$bound = self::yt( 'POST', '/liveBroadcasts/bind?part=id,contentDetails&id=' . rawurlencode( (string) $broadcast['id'] ) . '&streamId=' . rawurlencode( (string) $stream['id'] ) );
		if ( is_wp_error( $bound ) ) {
			return $bound;
		}

		$info = (array) ( $stream['cdn']['ingestionInfo'] ?? [] );
		$base = (string) ( $info['rtmpsIngestionAddress'] ?? $info['ingestionAddress'] ?? '' );
		$key  = (string) ( $info['streamName'] ?? '' );
		if ( '' === $base || '' === $key ) {
			return new WP_Error( 'whd_live_yt', __( 'YouTube did not return a stream address.', 'whd' ) );
		}
		$link = 'https://www.youtube.com/watch?v=' . rawurlencode( (string) $broadcast['id'] );
		WHD_Live::patch( $id, [ 'yt' => [
			'broadcast_id' => (string) $broadcast['id'],
			'stream_id'    => (string) $stream['id'],
			'chat_id'      => (string) ( $broadcast['snippet']['liveChatId'] ?? '' ),
		] ] );
		return [ 'endpoint' => 'whd_youtube', 'url' => self::join_url( $base, $key ), 'state' => 'ready', 'message' => '', 'live_url' => $link ];
	}

	/**
	 * New chat messages.
	 *
	 * @return array|WP_Error items, next (page token), wait (seconds before asking again), reason
	 */
	public static function youtube_pull( string $chat_id, string $token ) {
		$path = '/liveChat/messages?part=id,snippet,authorDetails&maxResults=200&liveChatId=' . rawurlencode( $chat_id );
		if ( '' !== $token ) {
			$path .= '&pageToken=' . rawurlencode( $token );
		}
		$res = self::yt( 'GET', $path );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'whd_live_yt_pull', self::youtube_words( $res ), [ 'reason' => self::youtube_reason( $res ) ] );
		}
		return [
			'items' => (array) ( $res['items'] ?? [] ),
			'next'  => (string) ( $res['nextPageToken'] ?? $token ),
			'wait'  => max( self::YOUTUBE_EVERY, (int) ceil( (int) ( $res['pollingIntervalMillis'] ?? 0 ) / 1000 ) ),
		];
	}

	/** Answer in the chat. @return true|WP_Error */
	public static function youtube_reply( string $chat_id, string $text ) {
		$res = self::yt( 'POST', '/liveChat/messages?part=snippet', [
			'snippet' => [
				'liveChatId'         => $chat_id,
				'type'               => 'textMessageEvent',
				'textMessageDetails' => [ 'messageText' => $text ],
			],
		] );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'whd_live_yt_reply', self::youtube_words( $res ), [ 'reason' => self::youtube_reason( $res ) ] );
		}
		return true;
	}

	private static function youtube_end( array $stream ) {
		if ( ! empty( $stream['yt']['broadcast_id'] ) ) {
			// Auto-stop ends it anyway about a minute after the video stops; this is only sooner.
			self::yt( 'POST', '/liveBroadcasts/transition?part=status&broadcastStatus=complete&id=' . rawurlencode( (string) $stream['yt']['broadcast_id'] ) );
		}
	}

	/* ─────────────────────────── TikTok ─────────────────────────── */

	private static function tiktok_prepare( $id ): array {
		$s      = WHD_Live::settings();
		$stream = WHD_Live::get( $id );
		$key    = '' !== (string) $stream['tt_key'] ? (string) $stream['tt_key'] : (string) $s['tt_key'];
		if ( '' === (string) $s['tt_server'] ) {
			return self::failed( __( 'No TikTok server URL saved.', 'whd' ) );
		}
		if ( '' === $key ) {
			return self::failed( __( 'Paste this session’s TikTok stream key.', 'whd' ) );
		}
		return [ 'endpoint' => 'whd_tiktok', 'url' => self::join_url( $s['tt_server'], $key ), 'state' => 'ready', 'message' => '', 'live_url' => '' ];
	}

	/* ─────────────────────────── ending ─────────────────────────── */

	/** Tell every platform it is over. Best effort: each one also ends by itself when the video stops. */
	public static function end_platforms( $id ) {
		$stream = WHD_Live::get( $id );
		self::facebook_end( $stream );
		self::youtube_end( $stream );
	}
}
