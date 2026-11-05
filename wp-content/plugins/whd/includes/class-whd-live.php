<?php
/**
 * Live Stream: live selling from the WordPress dashboard.
 *
 * The shape of it, because the reasons are not obvious from any one file:
 *
 * The host's browser is the studio. It draws the camera and every effect, image and product card
 * onto one canvas and sends that canvas to Daily as the camera. So what is on screen is burned into
 * the video itself, and the page on this site, Facebook, YouTube and TikTok all get the same picture
 * with no per-platform graphics to keep in step. (admin/live-stage.js)
 *
 * Daily carries it. Viewers on this site join the room as hidden participants and watch over WebRTC,
 * under a second behind. The other platforms get an RTMP copy, started from the server through named
 * `streaming_endpoints`, so stream keys never reach a browser. (WHD_Live_Channels)
 *
 * This host is shared cPanel with no sockets, so nothing here may depend on many PHP requests. What
 * every viewer needs to know — is it live, which room, which piece is showing, the last comments —
 * is a static JSON file in uploads that browsers revalidate every couple of seconds. A hundred viewers
 * cost a hundred 304s from the web server and no PHP at all. PHP only runs when something changes: a
 * comment arrives or the host changes the piece on screen.
 *
 * Comments from the other platforms are pulled by the studio's own heartbeat, because a stream only
 * exists while the studio is open — there is nothing to pull when it is not, so no cron and no
 * webhook to keep alive. (WHD_Live_Comments)
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Live {

	const CPT     = 'whd_live';
	const OPTION  = 'whd_live';
	const OPTION_YT = 'whd_live_yt';
	const META    = '_whd_live';        // the stream: stage, format, destinations, room, platform ids
	const RT      = '_whd_live_rt';     // the heartbeat: last seen, viewers, peak (written constantly)
	const PIECES  = '_whd_live_pieces'; // [ [ id, keyword ] ] in the host's order
	const SCENE   = '_whd_live_scene';  // the stage's layers, JSON, so a reload brings them back
	const SHOWING = '_whd_live_showing';
	const COVER   = '_whd_live_cover';  // 1 while the host has covered the picture
	const DIR     = 'whd-live';

	/** A stream whose studio has been gone this long is over, whatever the record says. */
	const GONE_AFTER = 30 * MINUTE_IN_SECONDS;

	/** The longest a stream may run: the room and the RTMP job both stop here. */
	const MAX_SECONDS = 8 * HOUR_IN_SECONDS;

	/** Daily's own loader. Not vendored: a daily-js too old for their servers refuses to run (`end-of-life`). */
	const DAILY_JS = 'https://unpkg.com/@daily-co/daily-js';

	const FONTS = 'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400;1,500&family=Manrope:wght@300;400;500;600;700&display=swap';

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ], 1 );
		add_action( 'init', [ __CLASS__, 'maybe_flush' ], 99 );
		add_filter( 'query_vars', [ __CLASS__, 'query_vars' ] );
		add_action( 'parse_request', [ __CLASS__, 'short_routes' ] );
		add_action( 'template_redirect', [ __CLASS__, 'route' ], 1 );
		add_filter( 'template_include', [ __CLASS__, 'template' ], 99 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ], 2000 );
		add_filter( 'woocommerce_add_to_cart_fragments', [ __CLASS__, 'bag_fragment' ] );
		add_filter( 'wp_sitemaps_post_types', [ __CLASS__, 'no_sitemap' ] );
		add_filter( 'wp_robots', [ __CLASS__, 'robots' ] );
		add_filter( 'omc_meta_description', [ __CLASS__, 'meta_description' ] );
		add_action( 'before_delete_post', [ __CLASS__, 'on_delete' ] );
	}

	/* ─────────────────────────── settings ─────────────────────────── */

	public static function defaults(): array {
		return [
			'daily_api_key' => '',

			'site_on'       => 1,
			'trigger_words' => 'mine, link, price, how much, i want, interested, buy, size, sizes, available',
			'reply_mode'    => 'any',
			'card_cta'      => __( 'Comment MINE for the link', 'whd' ),
			'site_text'     => __( 'Here it is, {name}.', 'whd' ),

			'fb_on'         => 0,
			'fb_page_id'    => '',
			'fb_token'      => '',
			'fb_server'     => 'rtmps://live-api-s.facebook.com:443/rtmp/',
			'fb_key'        => '',
			'fb_dm_text'    => __( 'Hi {name}! Here is {product} ({price}): {link}', 'whd' ),
			'fb_public_text' => __( 'Just sent you the link, {name}. Check your messages.', 'whd' ),

			'yt_on'         => 0,
			'yt_client_id'  => '',
			'yt_client_secret' => '',
			'yt_refresh'    => '',
			'yt_channel'    => '',
			'yt_privacy'    => 'public',
			'yt_server'     => 'rtmp://a.rtmp.youtube.com/live2',
			'yt_key'        => '',
			'yt_text'       => __( '@{name} {product} ({price}): {link}', 'whd' ),

			'tt_on'         => 0,
			'tt_server'     => '',
			'tt_key'        => '',
		];
	}

	public static function settings(): array {
		/*
		 * The YouTube connection is its own option. The settings form's sanitiser rebuilds this one
		 * from what was posted, and the connection is not something a form posts.
		 */
		$youtube = (array) get_option( self::OPTION_YT, [] );
		return array_merge( wp_parse_args( (array) get_option( self::OPTION, [] ), self::defaults() ), array_intersect_key( $youtube, [ 'yt_refresh' => 1, 'yt_channel' => 1 ] ) );
	}

	public static function daily_ready(): bool {
		return '' !== (string) self::settings()['daily_api_key'];
	}

	/**
	 * What each destination can do right now, for the form and the studio.
	 *
	 * `ready` means a stream can go there. `replies` means a comment there can be answered, which is
	 * a separate question: Facebook with only a pasted stream key can be streamed to but not read.
	 *
	 * @return array[] key => label, on, ready, replies, how, note
	 */
	public static function channels(): array {
		$s     = self::settings();
		$daily = '' !== (string) $s['daily_api_key'];

		$fb_token = '' !== (string) $s['fb_token'] && '' !== (string) $s['fb_page_id'];
		$fb_key   = '' !== (string) $s['fb_server'] && '' !== (string) $s['fb_key'];
		$yt_oauth = '' !== (string) $s['yt_refresh'];
		$yt_key   = '' !== (string) $s['yt_server'] && '' !== (string) $s['yt_key'];
		$tt_key   = '' !== (string) $s['tt_server'];

		return [
			'site'     => [
				'label'   => __( 'This website', 'whd' ),
				'on'      => (bool) $s['site_on'],
				'ready'   => $daily && $s['site_on'],
				'replies' => true,
				'how'     => __( 'A live page with comments. The piece opens in a side panel.', 'whd' ),
				'note'    => $daily ? '' : __( 'Add your Daily API key', 'whd' ),
			],
			'facebook' => [
				'label'   => __( 'Facebook', 'whd' ),
				'on'      => (bool) $s['fb_on'],
				'ready'   => $daily && $s['fb_on'] && ( $fb_token || $fb_key ),
				'replies' => $fb_token,
				'how'     => $fb_token ? __( 'Goes live on your Page and sends the link by Messenger.', 'whd' ) : __( 'Uses your stream key. Add a Page token to answer comments.', 'whd' ),
				'note'    => $s['fb_on'] ? ( ( $fb_token || $fb_key ) ? '' : __( 'Needs a Page token or a stream key', 'whd' ) ) : __( 'Not switched on', 'whd' ),
			],
			'youtube'  => [
				'label'   => __( 'YouTube', 'whd' ),
				'on'      => (bool) $s['yt_on'],
				'ready'   => $daily && $s['yt_on'] && ( $yt_oauth || $yt_key ),
				'replies' => $yt_oauth,
				'how'     => $yt_oauth ? __( 'Goes live on your channel and answers in the live chat.', 'whd' ) : __( 'Uses your stream key. Connect the channel to answer the chat.', 'whd' ),
				'note'    => $s['yt_on'] ? ( ( $yt_oauth || $yt_key ) ? '' : __( 'Needs a connected channel or a stream key', 'whd' ) ) : __( 'Not switched on', 'whd' ),
			],
			'tiktok'   => [
				'label'   => __( 'TikTok', 'whd' ),
				'on'      => (bool) $s['tt_on'],
				'ready'   => $daily && $s['tt_on'] && $tt_key,
				'replies' => false,
				'how'     => __( 'Uses your stream key. TikTok has no way to answer comments, so the piece’s short link is on screen.', 'whd' ),
				'note'    => $s['tt_on'] ? ( $tt_key ? '' : __( 'Needs a server URL', 'whd' ) ) : __( 'Not switched on', 'whd' ),
			],
		];
	}

	/* ─────────────────────────── the stream ─────────────────────────── */

	public static function blank(): array {
		return [
			'stage'        => 'scheduled', // scheduled | live | ended
			'format'       => 'landscape', // landscape | portrait
			'starts_at'    => 0,
			'started_at'   => 0,
			'ended_at'     => 0,
			'destinations' => [ 'site' ],
			'reply_mode'   => self::settings()['reply_mode'],
			'key'          => '',          // part of the state file's name
			'room'         => [],          // name, url
			'dests'        => [],          // key => [ endpoint, url (secret), state, message, live_url ]
			'fb'           => [],          // live_id, video_id, permalink
			'yt'           => [],          // broadcast_id, stream_id, chat_id
			'tt_key'       => '',          // TikTok's key changes by session: this stream's, if typed in the studio
			'pv'           => 1,           // version of the pieces file
			'blocked'      => [],          // author keys hidden from the page
		];
	}

	public static function get( $id ): array {
		$saved = get_post_meta( (int) $id, self::META, true );
		return wp_parse_args( is_array( $saved ) ? $saved : [], self::blank() );
	}

	public static function save( $id, array $stream ) {
		update_post_meta( (int) $id, self::META, $stream );
	}

	/** Change a few keys of the stream, reading it fresh so nothing else's write is lost. */
	public static function patch( $id, array $changes ): array {
		$stream = array_merge( self::get( $id ), $changes );
		self::save( $id, $stream );
		return $stream;
	}

	public static function rt( $id ): array {
		$saved = get_post_meta( (int) $id, self::RT, true );
		return wp_parse_args( is_array( $saved ) ? $saved : [], [ 'last_seen' => 0, 'viewers' => 0, 'peak' => 0 ] );
	}

	public static function exists( $id ): bool {
		return self::CPT === get_post_type( (int) $id );
	}

	public static function has_site( array $stream ): bool {
		return in_array( 'site', (array) $stream['destinations'], true );
	}

	public static function page_url( $id ): string {
		return (string) get_permalink( (int) $id );
	}

	/** The key that names this stream's public files; made once. */
	private static function file_key( $id ): string {
		$stream = self::get( $id );
		if ( '' === (string) $stream['key'] ) {
			$stream['key'] = wp_generate_password( 14, false );
			self::save( $id, $stream );
		}
		return (string) $stream['key'];
	}

	/**
	 * A stream left "live" after its studio vanished: a crashed laptop, or a tab closed for good.
	 *
	 * The room has an expiry of its own, so this is about the record and the page, which would
	 * otherwise say "live" to everyone indefinitely.
	 */
	public static function expire_if_gone( $id ): array {
		$stream = self::get( $id );
		if ( 'live' !== $stream['stage'] ) {
			return $stream;
		}
		$seen = (int) self::rt( $id )['last_seen'] ?: (int) $stream['started_at'];
		if ( $seen && time() - $seen > self::GONE_AFTER ) {
			$stream = self::patch( $id, [ 'stage' => 'ended', 'ended_at' => $seen ] );
			self::write_state( $id );
		}
		return $stream;
	}

	/* ─────────────────────────── pieces ─────────────────────────── */

	/** @return array[] [ id, keyword ] in order, products that still exist. */
	public static function piece_rows( $id ): array {
		$rows = get_post_meta( (int) $id, self::PIECES, true );
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$pid = absint( $row['id'] ?? 0 );
			if ( $pid && 'product' === get_post_type( $pid ) ) {
				$out[] = [ 'id' => $pid, 'keyword' => (string) ( $row['keyword'] ?? '' ) ];
			}
		}
		return $out;
	}

	public static function set_pieces( $id, $rows ) {
		$clean = [];
		$seen  = [];
		foreach ( (array) $rows as $row ) {
			$pid = absint( is_array( $row ) ? ( $row['id'] ?? 0 ) : $row );
			if ( ! $pid || isset( $seen[ $pid ] ) || 'product' !== get_post_type( $pid ) ) {
				continue;
			}
			$seen[ $pid ] = 1;
			$keyword      = is_array( $row ) ? sanitize_text_field( (string) ( $row['keyword'] ?? '' ) ) : '';
			$clean[]      = [ 'id' => $pid, 'keyword' => mb_substr( $keyword, 0, 40 ) ];
		}
		update_post_meta( (int) $id, self::PIECES, array_slice( $clean, 0, 60 ) );
		$stream = self::get( $id );
		self::patch( $id, [ 'pv' => (int) $stream['pv'] + 1 ] );
		self::write_pieces( $id );
		self::write_state( $id );
	}

	/** A money amount as words the browser can print: no entities, no markup. */
	private static function money( $amount ): string {
		if ( '' === (string) $amount || ! function_exists( 'wc_price' ) ) {
			return '';
		}
		return trim( html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount ) ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Everything the studio's product card and the page's side panel need to show one piece.
	 *
	 * @return array|null id, name, url, link, price, regular, image, thumb, short, stock, type, add,
	 *                    choose, options[ id, label, attrs, stock ], or null when it cannot be sold.
	 */
	public static function piece( $product_id, $code = 0, $keyword = '' ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product_id ) : null;
		if ( ! $product || 'publish' !== $product->get_status() ) {
			return null;
		}

		$image = (int) $product->get_image_id();
		$type  = $product->is_type( 'variable' ) ? 'variable' : ( $product->is_type( 'simple' ) ? 'simple' : 'other' );

		/*
		 * Not get_price_html(): its markup carries screen-reader sentences ("Original price was…"),
		 * which strip_tags leaves in the text. Both figures are worked out here.
		 */
		if ( 'variable' === $type ) {
			$min     = $product->get_variation_price( 'min', true );
			$max     = $product->get_variation_price( 'max', true );
			$price   = $min === $max ? self::money( $min ) : self::money( $min ) . ' – ' . self::money( $max );
			$regular = ( $product->is_on_sale() && $min === $max ) ? self::money( $product->get_variation_regular_price( 'min', true ) ) : '';
		} else {
			$price   = self::money( wc_get_price_to_display( $product ) );
			$regular = $product->is_on_sale() ? self::money( wc_get_price_to_display( $product, [ 'price' => $product->get_regular_price() ] ) ) : '';
		}

		$piece = [
			'id'      => $product->get_id(),
			'code'    => (int) $code,
			'keyword' => (string) $keyword,
			'name'    => html_entity_decode( $product->get_name(), ENT_QUOTES, 'UTF-8' ),
			'url'     => $product->get_permalink(),
			'link'    => self::short_link( $product->get_id() ),
			'shortUrl' => preg_replace( '#^https?://(www\.)?#', '', self::short_link( $product->get_id() ) ),
			'price'   => $price,
			'regular' => $regular,
			'image'   => $image ? (string) wp_get_attachment_image_url( $image, 'woocommerce_single' ) : wc_placeholder_img_src(),
			'thumb'   => $image ? (string) wp_get_attachment_image_url( $image, 'woocommerce_thumbnail' ) : wc_placeholder_img_src(),
			'short'   => mb_substr( trim( html_entity_decode( wp_strip_all_tags( $product->get_short_description() ), ENT_QUOTES, 'UTF-8' ) ), 0, 220 ),
			'stock'   => $product->is_in_stock(),
			'type'    => $type,
			'add'     => false,
			'choose'  => false,
			'options' => [],
		];

		if ( 'simple' === $type ) {
			$piece['add'] = $product->is_purchasable() && $product->is_in_stock() && ! $product->is_sold_individually();
		} elseif ( 'variable' === $type ) {
			foreach ( $product->get_children() as $child ) {
				$variation = wc_get_product( $child );
				if ( ! $variation || ! $variation->variation_is_visible() ) {
					continue;
				}
				$attrs = [];
				foreach ( $variation->get_variation_attributes() as $key => $value ) {
					if ( '' === (string) $value ) {
						// "Any size": the bag needs it spelled out, and only the product page asks.
						$piece['choose'] = true;
						continue 2;
					}
					$attrs[ preg_replace( '/^attribute_/', '', $key ) ] = (string) $value;
				}
				$names = [];
				foreach ( $attrs as $taxonomy => $slug ) {
					$term    = taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', $slug, $taxonomy ) : null;
					$names[] = $term ? html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) : $slug;
				}
				$piece['options'][] = [
					'id'    => $variation->get_id(),
					'label' => implode( ' / ', $names ), // not the formatted string: a colour's name can hold commas
					'attrs' => $attrs,
					'stock' => $variation->is_in_stock() && $variation->is_purchasable(),
				];
			}
			if ( ! $piece['options'] ) {
				$piece['choose'] = true;
			}
		} else {
			$piece['choose'] = true; // grouped, external: the product page knows what to do with them
		}
		return $piece;
	}

	/** This stream's pieces, ready to show, numbered in the host's order. */
	public static function pieces( $id ): array {
		$out = [];
		foreach ( self::piece_rows( $id ) as $i => $row ) {
			$piece = self::piece( $row['id'], $i + 1, $row['keyword'] );
			if ( $piece ) {
				$out[] = $piece;
			}
		}
		return $out;
	}

	/* ─────────────────────────── short links ─────────────────────────── */

	/** Where a comment or the screen sends somebody: short enough to read off a phone. */
	public static function short_link( $product_id, $source = '' ): string {
		// The letter is in the path, not a query string: `s` would make WordPress run a search first.
		return home_url( '/l/' . base_convert( (string) (int) $product_id, 10, 36 ) . ( $source ? '/' . $source : '' ) );
	}

	/** The letters a reply link carries, and what they stand for in the shop's reports. */
	private static function link_sources(): array {
		return [ 'f' => 'facebook', 'y' => 'youtube', 't' => 'tiktok', 'o' => 'live_page' ];
	}

	public static function query_vars( $vars ) {
		$vars[] = 'whd_live_hub';
		$vars[] = 'whd_live_go';
		$vars[] = 'whd_live_via';
		return $vars;
	}

	/** The stream a link click belongs to: the one on air, else the newest that has this piece. */
	private static function stream_for_click( $product_id ): int {
		$current = self::current();
		if ( $current ) {
			return $current;
		}
		$streams = get_posts( [ 'post_type' => self::CPT, 'post_status' => 'publish', 'posts_per_page' => 5, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'DESC' ] );
		foreach ( $streams as $id ) {
			if ( in_array( (int) $product_id, array_column( self::piece_rows( $id ), 'id' ), true ) ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/** The stream on air now, or the next one coming. 0 when there is neither. */
	public static function current( bool $upcoming = false ): int {
		$ids  = get_posts( [ 'post_type' => self::CPT, 'post_status' => 'publish', 'posts_per_page' => 30, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'DESC' ] );
		$next = 0;
		$when = PHP_INT_MAX;
		foreach ( $ids as $id ) {
			$stream = self::expire_if_gone( $id );
			if ( ! self::has_site( $stream ) ) {
				continue;
			}
			if ( 'live' === $stream['stage'] ) {
				return (int) $id;
			}
			if ( $upcoming && 'scheduled' === $stream['stage'] && (int) $stream['starts_at'] && (int) $stream['starts_at'] < $when ) {
				$when = (int) $stream['starts_at'];
				$next = (int) $id;
			}
		}
		return $next;
	}

	/* ─────────────────────────── routes and template ─────────────────────────── */

	/**
	 * The two addresses that only redirect, answered before WordPress runs the main query for them.
	 *
	 * @param WP $wp The request.
	 */
	public static function short_routes( $wp ) {
		/* /l/<code>[/<letter>]: the short link. */
		$go = (string) ( $wp->query_vars['whd_live_go'] ?? '' );
		if ( '' !== $go ) {
			self::follow_link( $go, (string) ( $wp->query_vars['whd_live_via'] ?? '' ) );
		}

		/* /live/: one address to give out, wherever the shop's next live happens to be. */
		if ( ! empty( $wp->query_vars['whd_live_hub'] ) ) {
			$id = self::current( true );
			nocache_headers();
			wp_safe_redirect( $id ? self::page_url( $id ) : (string) apply_filters( 'whd_live_hub_fallback', home_url( '/videos/' ) ), 302 );
			exit;
		}
	}

	public static function route() {
		if ( ! is_singular( self::CPT ) ) {
			return;
		}
		$id     = get_queried_object_id();
		$stream = self::expire_if_gone( $id );
		if ( ! self::has_site( $stream ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	private static function follow_link( string $code, string $letter ) {
		$pid  = (int) base_convert( preg_replace( '/[^a-z0-9]/', '', strtolower( $code ) ), 36, 10 );
		$post = $pid ? get_post( $pid ) : null;
		nocache_headers();
		if ( ! $post || 'product' !== $post->post_type || 'publish' !== $post->post_status ) {
			wp_safe_redirect( home_url( '/' ), 302 );
			exit;
		}
		$sources = self::link_sources();
		$source  = $sources[ $letter ] ?? 'live_page';
		$stream  = self::stream_for_click( $pid );
		if ( $stream ) {
			WHD_Live_Comments::count_click( $stream, $pid, $source );
		}
		$url = add_query_arg( [
			'utm_source'   => $source,
			'utm_medium'   => 'live',
			'utm_campaign' => $stream ? (string) get_post_field( 'post_name', $stream ) : 'live',
		], get_permalink( $pid ) );
		wp_safe_redirect( $url, 302 );
		exit;
	}

	public static function template( $template ) {
		if ( is_singular( self::CPT ) && ! is_404() ) {
			return WHD_DIR . 'templates/live.php';
		}
		return $template;
	}

	/**
	 * This page draws itself, so the theme's stylesheets and scripts only get in the way.
	 *
	 * Both by allow-list. A theme sheet here would restyle the buttons and inputs, and most scripts on
	 * a normal page are for things this one does not have (the stylist and its reCAPTCHA badge, which
	 * sat over the comment box; swatches; the theme's own). What stays is what the shop needs on any
	 * page: jQuery, WooCommerce's cart and its order attribution, and the pixel, which listens for
	 * `added_to_cart`.
	 */
	public static function assets() {
		if ( ! is_singular( self::CPT ) || is_404() ) {
			return;
		}
		$id     = get_queried_object_id();
		$stream = self::get( $id );

		$keep = [ 'whd-live', 'whd-live-fonts', 'admin-bar', 'dashicons' ];
		foreach ( wp_styles()->queue as $handle ) {
			if ( ! in_array( $handle, $keep, true ) ) {
				wp_dequeue_style( $handle );
			}
		}
		$keep = [ 'jquery', 'wc-cart-fragments', 'woocommerce', 'wc-add-to-cart', 'sourcebuster-js', 'wc-order-attribution', 'whd-pixel', 'whd-fit' ];
		foreach ( wp_scripts()->queue as $handle ) {
			if ( ! in_array( $handle, (array) apply_filters( 'whd_live_page_scripts', $keep ), true ) ) {
				wp_dequeue_script( $handle );
			}
		}

		// No version on the fonts: WordPress would rebuild the address and keep only the last family.
		wp_enqueue_style( 'whd-live-fonts', self::FONTS, [], null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_style( 'whd-live', WHD_URL . 'assets/live.css', [], WHD_VERSION );
		wp_enqueue_script( 'whd-live', WHD_URL . 'assets/live.js', [ 'jquery' ], WHD_VERSION, true );
		wp_localize_script( 'whd-live', 'WHD_LIVE', [
			'id'       => $id,
			'format'   => $stream['format'],
			'stateUrl' => self::state_url( $id ),
			'piecesUrl' => self::pieces_url( $id ),
			'comment'  => esc_url_raw( rest_url( 'whd/v1/live/' . $id . '/comment' ) ),
			'state'    => self::state_data( $id ),
			'pieces'   => self::pieces( $id ),
			'daily'    => self::DAILY_JS,
			'addUrl'   => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'add_to_cart' ) : '',
			'cta'      => self::settings()['card_cta'],
			'i18n'     => [
				'live'       => __( 'Live', 'whd' ),
				'startsIn'   => __( 'Starts in', 'whd' ),
				'soon'       => __( 'Starting soon', 'whd' ),
				'sound'      => __( 'Tap for sound', 'whd' ),
				'connecting' => __( 'Joining the live…', 'whd' ),
				'back'       => __( 'We’ll be right back', 'whd' ),
				'full'       => __( 'The live is full right now. Try again in a minute.', 'whd' ),
				'failed'     => __( 'The video could not connect. Check your connection and reload.', 'whd' ),
				'tapPlay'    => __( 'Tap to watch', 'whd' ),
				'closed'     => __( 'Comments open when we go live.', 'whd' ),
				'sendFailed' => __( 'That didn’t send. Try again.', 'whd' ),
				'needName'   => __( 'Your name and a valid email first.', 'whd' ),
				'as'         => __( 'Commenting as %s', 'whd' ),
				'change'     => __( 'change', 'whd' ),
				'add'        => __( 'Add to bag', 'whd' ),
				'adding'     => __( 'Adding…', 'whd' ),
				'added'      => __( 'In your bag', 'whd' ),
				'addFailed'  => __( 'That didn’t go in the bag. Open the piece and try there.', 'whd' ),
				'chooseSize' => __( 'Choose your size', 'whd' ),
				'size'       => __( 'Size', 'whd' ),
				'soldOut'    => __( 'Sold out', 'whd' ),
				'gone'       => __( 'sold out', 'whd' ),
				'view'       => __( 'View the piece', 'whd' ),
				'options'    => __( 'Choose options', 'whd' ),
				'nowShowing' => __( 'Now showing', 'whd' ),
				'getIt'      => __( 'Get it', 'whd' ),
				'yourLink'   => __( 'Your link', 'whd' ),
				'fromLive'   => __( 'From this live', 'whd' ),
				'wrap'       => __( 'That’s a wrap', 'whd' ),
				'wrapText'   => __( 'Thanks for coming.', 'whd' ),
				'wrapPieces' => __( 'Thanks for coming. The pieces from this live are still here.', 'whd' ),
			],
		] );
	}

	/** The count on the page's bag button, kept right by WooCommerce's own fragments. */
	public static function bag_fragment( $fragments ) {
		$count = function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
		$fragments['.whdl-bag__n'] = '<span class="whdl-bag__n" data-n="' . (int) $count . '">' . (int) $count . '</span>';
		return $fragments;
	}

	/** Nothing worth finding once it is over, and the short life of a stream does not suit a sitemap. */
	public static function no_sitemap( $types ) {
		unset( $types[ self::CPT ] );
		return $types;
	}

	public static function robots( $robots ) {
		if ( is_singular( self::CPT ) && 'ended' === self::get( get_queried_object_id() )['stage'] ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}

	public static function meta_description( $description ) {
		if ( is_singular( self::CPT ) ) {
			$text = trim( wp_strip_all_tags( (string) get_post_field( 'post_excerpt', get_queried_object_id() ) ) );
			return '' !== $text ? $text : $description;
		}
		return $description;
	}

	/* ─────────────────────────── the public state ─────────────────────────── */

	/**
	 * Everything a viewer's browser polls for. Nothing private may go in here: it is a public file.
	 * Comments carry a first name and the words, never an address.
	 */
	public static function state_data( $id ): array {
		$stream = self::get( $id );
		$rt     = self::rt( $id );
		$show   = get_post_meta( (int) $id, self::SHOWING, true );
		return [
			'stage'     => (string) $stream['stage'],
			'room'      => 'live' === $stream['stage'] ? (string) ( $stream['room']['url'] ?? '' ) : '',
			'startsAt'  => (int) $stream['starts_at'],
			'startedAt' => (int) $stream['started_at'],
			'pv'        => (int) $stream['pv'],
			'viewers'   => (int) $rt['viewers'],
			// What the host tries out in the preview stays in the studio until the stream is on air.
			'showing'   => 'live' === $stream['stage'] && is_array( $show ) && ! empty( $show['id'] ) ? $show : null,
			'covered'   => 'live' === $stream['stage'] && get_post_meta( (int) $id, self::COVER, true ),
			'comments'  => WHD_Live_Comments::public_recent( $id, 40 ),
		];
	}

	private static function dir(): array {
		$up = wp_upload_dir( null, false );
		return [ trailingslashit( $up['basedir'] ) . self::DIR . '/', set_url_scheme( trailingslashit( $up['baseurl'] ) . self::DIR . '/' ) ];
	}

	/**
	 * Write a public file so a half-written one can never be read: to one side, then renamed over.
	 *
	 * @return bool False where uploads cannot be written; the page then polls REST instead.
	 */
	private static function write_file( string $name, $data ): bool {
		list( $dir ) = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		if ( ! file_exists( $dir . 'index.html' ) ) {
			@file_put_contents( $dir . 'index.html', '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- no directory listing
		}
		$tmp  = $dir . wp_generate_password( 8, false ) . '.tmp';
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- our own small file in uploads
		if ( false === $json || false === @file_put_contents( $tmp, $json ) ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( ! @rename( $tmp, $dir . $name ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return false;
		}
		return true;
	}

	public static function write_state( $id ): bool {
		return self::write_file( (int) $id . '-' . self::file_key( $id ) . '.json', self::state_data( $id ) );
	}

	public static function write_pieces( $id ): bool {
		return self::write_file( (int) $id . '-' . self::file_key( $id ) . '-pieces.json', self::pieces( $id ) );
	}

	private static function file_url( $id, string $suffix, string $rest ): string {
		list( $dir, $url ) = self::dir();
		$name = (int) $id . '-' . self::file_key( $id ) . $suffix . '.json';
		if ( ! file_exists( $dir . $name ) ) {
			if ( '' === $suffix ) {
				self::write_state( $id );
			} else {
				self::write_pieces( $id );
			}
		}
		return file_exists( $dir . $name ) ? $url . $name : esc_url_raw( rest_url( 'whd/v1/live/' . (int) $id . '/' . $rest ) );
	}

	public static function state_url( $id ): string {
		return self::file_url( $id, '', 'state' );
	}

	public static function pieces_url( $id ): string {
		return self::file_url( $id, '-pieces', 'pieces' );
	}

	private static function remove_files( $id ) {
		$stream = self::get( $id );
		if ( '' === (string) $stream['key'] ) {
			return;
		}
		list( $dir ) = self::dir();
		foreach ( [ '', '-pieces' ] as $suffix ) {
			$file = $dir . (int) $id . '-' . $stream['key'] . $suffix . '.json';
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}

	public static function on_delete( $post_id ) {
		if ( self::CPT !== get_post_type( $post_id ) ) {
			return;
		}
		self::remove_files( $post_id );
		WHD_Live_Comments::forget_stream( $post_id );
	}

	/* ─────────────────────────── install ─────────────────────────── */

	public static function install() {
		WHD_Live_Comments::install();
		update_option( 'whd_live_flush', 1, false ); // new addresses: /live/…, /l/…
	}

	/** Rewrite rules can only be rebuilt after the post type exists, which is why this is deferred. */
	public static function maybe_flush() {
		if ( get_option( 'whd_live_flush' ) ) {
			delete_option( 'whd_live_flush' );
			flush_rewrite_rules( false );
		}
	}

	public static function register() {
		register_post_type( self::CPT, [
			'labels'              => [
				'name'          => __( 'Live streams', 'whd' ),
				'singular_name' => __( 'Live stream', 'whd' ),
			],
			'public'              => true,
			'exclude_from_search' => true,
			'show_ui'             => false,
			'show_in_nav_menus'   => false,
			'show_in_rest'        => false,
			'has_archive'         => false,
			'rewrite'             => [ 'slug' => 'live', 'with_front' => false ],
			'supports'            => [ 'title', 'excerpt', 'thumbnail' ],
		] );
		add_rewrite_rule( '^live/?$', 'index.php?whd_live_hub=1', 'top' );
		add_rewrite_rule( '^l/([a-z0-9]+)(?:/([a-z]))?/?$', 'index.php?whd_live_go=$matches[1]&whd_live_via=$matches[2]', 'top' );
	}
}
