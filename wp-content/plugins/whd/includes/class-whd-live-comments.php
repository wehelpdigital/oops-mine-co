<?php
/**
 * Comments on a live stream, wherever they were typed, and the link each one earns.
 *
 * One table for all of them, so the studio has one feed. A row is unique on (stream, source, the
 * platform's own id), which is the guard that matters: the same comment arrives over and over because
 * the platforms are polled, and INSERT IGNORE means only one request ever gets to act on it.
 *
 * The rule for who gets a link, which is the shop owner's: typing the piece's code, and nothing else.
 * Anybody can comment anything; a comment without a code is simply a comment. It does not matter which
 * piece is on screen, or whether any is.
 *
 * On Facebook and YouTube nobody is sent the same piece twice in one stream, which on Facebook is also
 * a matter of being allowed to stay on the platform.
 *
 * Sending happens on the studio's heartbeat, after its response has gone (WHD_Plugin::after_response),
 * so a slow Graph call never holds the feed up. A row is claimed with a conditional UPDATE before
 * anything is sent: two overlapping heartbeats cannot both send, and a private reply can only be sent
 * once in any case.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Live_Comments {

	/** Bump when a table changes. */
	const DB_VERSION = 1;

	/** How many replies one heartbeat sends, so a rush of comments cannot run one request for minutes. */
	const SEND_BATCH = 6;

	const ATTEMPTS = 3;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'whd_live_comments';
	}

	public static function clicks_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'whd_live_clicks';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stream_id bigint(20) unsigned NOT NULL,
			source varchar(12) NOT NULL DEFAULT 'site',
			external_id varchar(120) NOT NULL DEFAULT '',
			author varchar(120) NOT NULL DEFAULT '',
			author_key varchar(64) NOT NULL DEFAULT '',
			message text NOT NULL,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			reply_status varchar(12) NOT NULL DEFAULT 'none',
			reply_channel varchar(12) NOT NULL DEFAULT '',
			reply_note varchar(190) NOT NULL DEFAULT '',
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			hidden tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY one_copy (stream_id,source,external_id),
			KEY stream_author (stream_id,author_key,product_id),
			KEY stream_reply (stream_id,reply_status)
		) {$charset};" );
		dbDelta( 'CREATE TABLE ' . self::clicks_table() . " (
			stream_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			source varchar(12) NOT NULL DEFAULT '',
			clicks int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (stream_id,product_id,source)
		) {$charset};" );
	}

	public static function forget_stream( $id ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::table(), [ 'stream_id' => (int) $id ] );
		$wpdb->delete( self::clicks_table(), [ 'stream_id' => (int) $id ] );
		// phpcs:enable
	}

	public static function count_click( $stream_id, $product_id, $source ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::clicks_table() . ' (stream_id, product_id, source, clicks) VALUES (%d, %d, %s, 1) ON DUPLICATE KEY UPDATE clicks = clicks + 1', (int) $stream_id, (int) $product_id, substr( (string) $source, 0, 12 ) ) );
	}

	/* ─────────────────────────── which piece does a comment want? ─────────────────────────── */

	/** Lower case, accents off, punctuation to spaces, one space between words. */
	private static function plain( string $text ): string {
		$text = strtolower( remove_accents( $text ) );
		$text = preg_replace( '/[^a-z0-9#\s]+/', ' ', $text );
		return ' ' . trim( preg_replace( '/\s+/', ' ', (string) $text ) ) . ' ';
	}

	/** Does this comment contain the phrase as whole words? */
	private static function says( string $plain, string $phrase ): bool {
		$phrase = trim( str_replace( '#', '', self::plain( $phrase ) ) );
		// "#DRESS1" asks for the same thing as "DRESS1".
		return '' !== $phrase && false !== strpos( str_replace( '#', '', $plain ), ' ' . $phrase . ' ' );
	}

	/**
	 * Is this comment this code? Words anywhere in it, as whole words: "I'd love DRESS1 please". A code
	 * that is only a number has to be the whole comment, or "size 8" and "I have 2 kids" would be orders.
	 */
	private static function says_code( string $plain, string $code ): bool {
		$digits = trim( str_replace( '#', '', self::plain( $code ) ) );
		if ( ctype_digit( $digits ) ) {
			return 1 === preg_match( '/^ #?' . $digits . ' $/', $plain );
		}
		return self::says( $plain, $code );
	}

	/**
	 * Which piece a comment asks for, by its code.
	 *
	 * @return int The product id, or 0: it is just a comment.
	 */
	public static function match( $stream_id, string $message ): int {
		$plain = self::plain( $message );
		$rows  = WHD_Live::piece_rows( $stream_id );

		// The codes the host set first, across all pieces: one can be another piece's number.
		foreach ( $rows as $row ) {
			if ( '' !== $row['code'] && self::says_code( $plain, $row['code'] ) ) {
				return (int) $row['id'];
			}
		}
		// A piece with no code answers to its number, which is what its card tells people to type. Only
		// once it has been on air: numbers can be guessed, and typing "3" must not spoil a surprise.
		$seen = WHD_Live::revealed( $stream_id );
		foreach ( $rows as $i => $row ) {
			if ( '' === $row['code'] && in_array( (int) $row['id'], $seen, true ) && self::says_code( $plain, (string) ( $i + 1 ) ) ) {
				return (int) $row['id'];
			}
		}
		return 0;
	}

	/** Has this person already been given this piece in this stream? */
	private static function already_given( $stream_id, string $author_key, $product_id, $except = 0 ): bool {
		if ( '' === $author_key ) {
			return false;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . " WHERE stream_id = %d AND author_key = %s AND product_id = %d AND id <> %d AND reply_status IN ('queued','sending','sent') LIMIT 1", (int) $stream_id, $author_key, (int) $product_id, (int) $except ) );
	}

	/* ─────────────────────────── taking a comment in ─────────────────────────── */

	/**
	 * Comments stay as typed: every place they appear prints them as text. WordPress's sanitisers are
	 * for HTML, and they turn "I <3 this" into "I " or "I &lt;3 this" depending on which is used.
	 */
	public static function clean_text( string $text ): string {
		$text = wp_check_invalid_utf8( $text, true );
		$text = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $text );
		return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	}

	/**
	 * One comment, once.
	 *
	 * @param array $c source, external_id, author, author_key, message
	 * @return array|null The row as stored; null when this copy was already known.
	 */
	public static function ingest( $stream_id, array $c ) {
		global $wpdb;
		$message = mb_substr( self::clean_text( (string) ( $c['message'] ?? '' ) ), 0, 500 );
		if ( '' === $message ) {
			return null;
		}
		$source = (string) ( $c['source'] ?? 'site' );
		$author = mb_substr( self::clean_text( (string) ( $c['author'] ?? '' ) ), 0, 120 );

		$stream = WHD_Live::get( $stream_id );
		$key    = (string) ( $c['author_key'] ?? '' );
		$hidden = '' !== $key && in_array( $key, (array) $stream['blocked'], true ) ? 1 : 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$done = $wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . self::table() . ' (stream_id, source, external_id, author, author_key, message, product_id, reply_status, hidden, created_at) VALUES (%d, %s, %s, %s, %s, %s, %d, %s, %d, %s)',
			(int) $stream_id,
			$source,
			mb_substr( (string) ( $c['external_id'] ?? '' ), 0, 120 ),
			$author,
			$key,
			$message,
			0,
			'none',
			$hidden,
			current_time( 'mysql' )
		) );
		if ( ! $done ) {
			return null;
		}
		$id = (int) $wpdb->insert_id;

		// After the insert: the platforms are polled, and most of what arrives is a copy already held.
		$product = self::match( $stream_id, $message );

		$status  = 'none';
		$channel = '';
		$note    = '';
		if ( $product && ! $hidden ) {
			if ( 'site' === $source ) {
				$status  = 'sent'; // the answer to this very request carries it, and opening again costs nothing
				$channel = 'panel';
			} elseif ( self::already_given( $stream_id, $key, $product ) ) {
				$status = 'skipped';
				$note   = __( 'Already had this piece', 'whd' );
			} elseif ( self::can_reply( $source ) ) {
				$status = 'queued';
			} else {
				$status = 'skipped';
				$note   = __( 'This platform can’t be answered', 'whd' );
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( self::table(), [
			'product_id'    => $product,
			'reply_status'  => $status,
			'reply_channel' => $channel,
			'reply_note'    => $note,
		], [ 'id' => $id ] );

		return [
			'id'           => $id,
			'product_id'   => (int) $product,
			'reply_status' => $status,
			'hidden'       => (bool) $hidden,
			'author'       => $author,
			'message'      => $message,
		];
	}

	private static function can_reply( string $source ): bool {
		$channels = WHD_Live::channels();
		return ! empty( $channels[ $source ]['replies'] );
	}

	/* ─────────────────────────── the heartbeat's work ─────────────────────────── */

	/** Cursors and errors for the platform pulls, only ever written from here, under the lock. */
	const PULL = '_whd_live_pull';

	public static function pull_state( $id ): array {
		$saved = get_post_meta( (int) $id, self::PULL, true );
		return wp_parse_args( is_array( $saved ) ? $saved : [], [
			'fb_next'  => 0,
			'fb_since' => 0,
			'fb_error' => '',
			'yt_next'  => 0,
			'yt_token' => '',
			'yt_error' => '',
			'yt_stop'  => 0,
		] );
	}

	/**
	 * Fetch what is new and answer what is due. Runs after the studio's heartbeat has its answer.
	 * The lock keeps a slow round from being joined by the next heartbeat.
	 */
	public static function work( $id ) {
		$lock = 'whd_live_work_' . (int) $id;
		if ( get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, 25 );

		$stream = WHD_Live::get( $id );
		$state  = self::pull_state( $id );
		$now    = time();

		if ( 'live' === $stream['stage'] ) {
			if ( in_array( 'facebook', (array) $stream['destinations'], true ) && self::can_reply( 'facebook' ) && $now >= (int) $state['fb_next'] ) {
				$state = self::pull_facebook( $id, $stream, $state );
			}
			if ( in_array( 'youtube', (array) $stream['destinations'], true ) && self::can_reply( 'youtube' ) && ! $state['yt_stop'] && $now >= (int) $state['yt_next'] ) {
				$state = self::pull_youtube( $id, $stream, $state );
			}
			update_post_meta( (int) $id, self::PULL, $state );
			self::send_queue( $id );
		}

		delete_transient( $lock );
	}

	private static function pull_facebook( $id, array $stream, array $state ): array {
		$state['fb_next'] = time() + WHD_Live_Channels::FACEBOOK_EVERY;

		$live_id = (string) ( $stream['fb']['live_id'] ?? '' );
		if ( '' === $live_id ) {
			// Streaming from a pasted key: look for it now and then rather than every round.
			$state['fb_next'] = time() + 15;
			$live_id          = WHD_Live_Channels::facebook_discover( $id );
			if ( '' === $live_id ) {
				return $state;
			}
		}

		$since = max( (int) $state['fb_since'], (int) $stream['started_at'] );
		$items = WHD_Live_Channels::facebook_pull( $live_id, $since );
		if ( is_wp_error( $items ) ) {
			$state['fb_error'] = $items->get_error_message();
			return $state;
		}
		$state['fb_error'] = '';

		$page = (string) WHD_Live::settings()['fb_page_id'];
		$last = $since;
		foreach ( $items as $item ) {
			$from = (array) ( $item['from'] ?? [] );
			if ( ! empty( $from['id'] ) && (string) $from['id'] === $page ) {
				continue; // our own replies coming back round
			}
			self::ingest( $id, [
				'source'      => 'facebook',
				'external_id' => (string) ( $item['id'] ?? '' ),
				'author'      => (string) ( $from['name'] ?? __( 'Facebook viewer', 'whd' ) ),
				// Without `from` the person cannot be recognised, so each comment stands alone.
				'author_key'  => ! empty( $from['id'] ) ? 'fb:' . $from['id'] : 'fbc:' . md5( (string) ( $item['id'] ?? '' ) ),
				'message'     => (string) ( $item['message'] ?? '' ),
			] );
			$at = strtotime( (string) ( $item['created_time'] ?? '' ) );
			if ( $at && $at > $last ) {
				$last = $at;
			}
		}
		$state['fb_since'] = $last; // the table's unique key takes care of the overlap at the boundary
		return $state;
	}

	private static function pull_youtube( $id, array $stream, array $state ): array {
		$state['yt_next'] = time() + WHD_Live_Channels::YOUTUBE_EVERY;

		$chat = (string) ( $stream['yt']['chat_id'] ?? '' );
		if ( '' === $chat ) {
			return $state;
		}
		$res = WHD_Live_Channels::youtube_pull( $chat, (string) $state['yt_token'] );
		if ( is_wp_error( $res ) ) {
			$reason            = (string) ( $res->get_error_data()['reason'] ?? '' );
			$state['yt_error'] = $res->get_error_message();
			if ( in_array( $reason, [ 'quotaExceeded', 'liveChatEnded', 'liveChatDisabled', 'liveChatNotFound' ], true ) ) {
				$state['yt_stop'] = 1; // asking again only spends what is left
			}
			return $state;
		}
		$state['yt_error'] = '';
		$state['yt_token'] = $res['next'];
		$state['yt_next']  = time() + (int) $res['wait'];

		foreach ( $res['items'] as $item ) {
			$snippet = (array) ( $item['snippet'] ?? [] );
			$who     = (array) ( $item['authorDetails'] ?? [] );
			if ( 'textMessageEvent' !== ( $snippet['type'] ?? '' ) || ! empty( $who['isChatOwner'] ) ) {
				continue; // joins, super chats' system lines, and the shop's own replies
			}
			self::ingest( $id, [
				'source'      => 'youtube',
				'external_id' => (string) ( $item['id'] ?? '' ),
				'author'      => (string) ( $who['displayName'] ?? __( 'YouTube viewer', 'whd' ) ),
				'author_key'  => 'yt:' . (string) ( $who['channelId'] ?? '' ),
				'message'     => (string) ( $snippet['textMessageDetails']['messageText'] ?? $snippet['displayMessage'] ?? '' ),
			] );
		}
		return $state;
	}

	/* ─────────────────────────── answering ─────────────────────────── */

	/** Fill a reply's placeholders. The link is never shortened away: it is the point of the message. */
	public static function reply_text( string $template, string $author, $product_id, string $letter, int $limit = 0 ): string {
		$piece = WHD_Live::piece( $product_id );
		if ( ! $piece ) {
			return '';
		}
		$first = trim( strtok( trim( $author ), ' ' ) ?: '' );
		$link  = WHD_Live::short_link( $product_id, $letter );
		$fill  = static function ( $name ) use ( $template, $first, $piece, $link ) {
			return strtr( $template, [ '{name}' => $first, '{product}' => $name, '{price}' => $piece['price'], '{link}' => $link ] );
		};
		$text = $fill( $piece['name'] );
		if ( $limit && mb_strlen( $text ) > $limit ) {
			$room = max( 8, mb_strlen( $piece['name'] ) - ( mb_strlen( $text ) - $limit ) - 1 );
			$text = $fill( rtrim( mb_substr( $piece['name'], 0, $room ) ) . '…' );
			$text = mb_substr( $text, 0, $limit );
		}
		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	public static function send_queue( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE stream_id = %d AND reply_status = 'queued' ORDER BY id ASC LIMIT %d", (int) $id, self::SEND_BATCH ) );
		foreach ( (array) $rows as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$claimed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET reply_status = 'sending', attempts = attempts + 1 WHERE id = %d AND reply_status = 'queued'", (int) $row->id ) );
			if ( ! $claimed ) {
				continue;
			}
			$outcome = 'facebook' === $row->source ? self::send_facebook( $row, $id ) : self::send_youtube( $row, $id );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( self::table(), $outcome, [ 'id' => (int) $row->id ] );
		}
	}

	/** One that may be worth another go goes back in the queue; after that it is a failure to show. */
	private static function retry_or_fail( $row, string $why ): array {
		return [
			'reply_status' => (int) $row->attempts + 1 >= self::ATTEMPTS ? 'failed' : 'queued',
			'reply_note'   => mb_substr( $why, 0, 190 ),
		];
	}

	/**
	 * The link by Messenger; a comment reply when Messenger refuses.
	 *
	 * The refusal is the normal case until the app has Advanced Access for pages_messaging, so the
	 * reply under the comment carries the link too, not the "check your messages" line.
	 */
	private static function send_facebook( $row, $id ): array {
		$s  = WHD_Live::settings();
		$dm = self::reply_text( (string) $s['fb_dm_text'], (string) $row->author, $row->product_id, 'f' );
		if ( '' === $dm ) {
			return [ 'reply_status' => 'failed', 'reply_note' => __( 'That piece is no longer on sale', 'whd' ) ];
		}
		$sent = WHD_Live_Channels::facebook_dm( (string) $row->external_id, $dm );
		if ( ! is_wp_error( $sent ) ) {
			$public = self::reply_text( (string) $s['fb_public_text'], (string) $row->author, $row->product_id, 'f' );
			if ( '' !== $public ) {
				WHD_Live_Channels::facebook_comment( (string) $row->external_id, $public );
			}
			return [ 'reply_status' => 'sent', 'reply_channel' => 'dm', 'reply_note' => '' ];
		}

		$why     = $sent->get_error_message();
		$comment = WHD_Live_Channels::facebook_comment( (string) $row->external_id, $dm );
		if ( ! is_wp_error( $comment ) ) {
			return [ 'reply_status' => 'sent', 'reply_channel' => 'comment', 'reply_note' => mb_substr( $why, 0, 190 ) ];
		}
		WHD_Integrations::log( 'live', 0, 'Facebook reply: ' . $why . ' / ' . $comment->get_error_message() );
		return self::retry_or_fail( $row, $comment->get_error_message() );
	}

	private static function send_youtube( $row, $id ): array {
		$stream = WHD_Live::get( $id );
		$chat   = (string) ( $stream['yt']['chat_id'] ?? '' );
		$text   = self::reply_text( (string) WHD_Live::settings()['yt_text'], (string) $row->author, $row->product_id, 'y', 200 );
		if ( '' === $chat || '' === $text ) {
			return [ 'reply_status' => 'failed', 'reply_note' => __( 'No chat to answer in', 'whd' ) ];
		}
		$sent = WHD_Live_Channels::youtube_reply( $chat, $text );
		if ( ! is_wp_error( $sent ) ) {
			return [ 'reply_status' => 'sent', 'reply_channel' => 'chat', 'reply_note' => '' ];
		}
		$reason = (string) ( $sent->get_error_data()['reason'] ?? '' );
		if ( 'quotaExceeded' === $reason ) {
			return [ 'reply_status' => 'failed', 'reply_note' => mb_substr( $sent->get_error_message(), 0, 190 ) ];
		}
		return self::retry_or_fail( $row, $sent->get_error_message() );
	}

	/**
	 * The host pressed "Send link" on a comment.
	 *
	 * @return array|WP_Error The row's new state.
	 */
	public static function send_by_hand( $comment_id, $product_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $comment_id ) );
		if ( ! $row ) {
			return new WP_Error( 'whd_live_gone', __( 'That comment is gone.', 'whd' ), [ 'status' => 404 ] );
		}
		if ( 'site' === $row->source ) {
			return new WP_Error( 'whd_live_site', __( 'A comment on the site gets its link in the side panel as it is typed.', 'whd' ), [ 'status' => 400 ] );
		}
		if ( ! self::can_reply( (string) $row->source ) ) {
			return new WP_Error( 'whd_live_noreply', __( 'This platform can’t be answered.', 'whd' ), [ 'status' => 400 ] );
		}
		if ( ! $product_id ) {
			return new WP_Error( 'whd_live_nopiece', __( 'Show a piece first, then send its link.', 'whd' ), [ 'status' => 400 ] );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( self::table(), [ 'product_id' => (int) $product_id, 'reply_status' => 'queued', 'attempts' => 0, 'reply_note' => '' ], [ 'id' => (int) $row->id ] );
		self::send_queue( (int) $row->stream_id );
		return self::row_for_studio( (int) $row->id );
	}

	public static function hide( $comment_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, stream_id, author_key, source FROM ' . self::table() . ' WHERE id = %d', (int) $comment_id ) );
		if ( ! $row ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( self::table(), [ 'hidden' => 1 ], [ 'id' => (int) $row->id ] );
		return (int) $row->stream_id;
	}

	/** Hide this person from the page: what they have said and whatever they say next. */
	public static function block( $comment_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, stream_id, author_key FROM ' . self::table() . ' WHERE id = %d', (int) $comment_id ) );
		if ( ! $row || '' === (string) $row->author_key ) {
			return 0;
		}
		$stream  = WHD_Live::get( (int) $row->stream_id );
		$blocked = array_values( array_unique( array_merge( (array) $stream['blocked'], [ (string) $row->author_key ] ) ) );
		WHD_Live::patch( (int) $row->stream_id, [ 'blocked' => $blocked ] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( self::table(), [ 'hidden' => 1 ], [ 'stream_id' => (int) $row->stream_id, 'author_key' => (string) $row->author_key ] );
		return (int) $row->stream_id;
	}

	/* ─────────────────────────── reading ─────────────────────────── */

	/** What goes in the public file: first name and the words, nothing else about anyone. */
	public static function public_recent( $id, $limit = 40 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, author, message FROM ' . self::table() . " WHERE stream_id = %d AND source = 'site' AND hidden = 0 ORDER BY id DESC LIMIT %d", (int) $id, (int) $limit ) );
		$out  = [];
		foreach ( array_reverse( (array) $rows ) as $row ) {
			$out[] = [ 'i' => (int) $row->id, 'n' => self::first_name( (string) $row->author ), 'm' => (string) $row->message ];
		}
		return $out;
	}

	public static function first_name( string $author ): string {
		$author = trim( $author );
		if ( preg_match( '/^\S+ \d+$/u', $author ) ) {
			return $author; // a guest's number is what tells one guest from another
		}
		$first = strtok( $author, ' ' );
		return false === $first ? '' : $first;
	}

	/** Somebody who has not given a name. The same browser is the same guest all stream long. */
	public static function guest_name( string $viewer ): string {
		/* translators: %d: a three-digit number */
		return sprintf( __( 'Guest %d', 'whd' ), 100 + hexdec( substr( md5( $viewer ), 0, 6 ) ) % 900 );
	}

	private static function row_for_studio( int $comment_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $comment_id ) );
		return $row ? self::shape( $row ) : null;
	}

	private static function shape( $row ): array {
		return [
			'id'      => (int) $row->id,
			'source'  => (string) $row->source,
			'author'  => (string) $row->author,
			'message' => (string) $row->message,
			'product' => (int) $row->product_id,
			'status'  => (string) $row->reply_status,
			'channel' => (string) $row->reply_channel,
			'note'    => (string) $row->reply_note,
			'hidden'  => (bool) $row->hidden,
			'time'    => mysql2date( 'H:i', (string) $row->created_at ),
		];
	}

	/**
	 * What changed for the studio: new comments, plus rows whose answer has moved on.
	 *
	 * @param int $after Newest comment id the studio has.
	 */
	public static function for_studio( $id, int $after, int $limit = 100 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE stream_id = %d AND id > %d ORDER BY id ASC LIMIT %d', (int) $id, $after, $limit ) );

		// Anything still waiting or recently settled, so a "sending" row turns to "sent" on screen.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$moved = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE stream_id = %d AND id <= %d AND (reply_status IN ('queued','sending') OR id > %d) ORDER BY id DESC LIMIT 30", (int) $id, $after, max( 0, $after - 30 ) ) );

		return [
			'new'   => array_map( [ __CLASS__, 'shape' ], (array) $rows ),
			'moved' => array_map( [ __CLASS__, 'shape' ], (array) $moved ),
		];
	}

	public static function stats( $id ): array {
		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS comments,
				SUM(reply_status = 'sent') AS links
			FROM {$table} WHERE stream_id = %d",
			(int) $id
		) );
		$clicks = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(clicks), 0) FROM ' . self::clicks_table() . ' WHERE stream_id = %d', (int) $id ) );
		// phpcs:enable
		return [
			'comments' => (int) ( $row->comments ?? 0 ),
			'links'    => (int) ( $row->links ?? 0 ),
			'clicks'   => $clicks,
		];
	}
}
