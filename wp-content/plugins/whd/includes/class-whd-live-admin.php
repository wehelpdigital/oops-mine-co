<?php
/**
 * Live Stream in the dashboard: WHD → Live Stream (the list and the form), the studio (a hidden page
 * that fills the window) and the Live Stream tab of WHD → Settings, where every connection lives.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Live_Admin {

	const PAGE   = 'whd-live';
	const STUDIO = 'whd-live-studio';
	const GROUP  = 'whd_live_group';

	/** A blank box keeps these; they are never printed back. */
	const SECRETS = [ 'daily_api_key', 'fb_token', 'fb_key', 'yt_client_secret', 'yt_key', 'tt_key', 'klipy_key' ];

	public static function init() {
		add_action( 'admin_init', [ __CLASS__, 'register_setting' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_filter( 'admin_body_class', [ __CLASS__, 'body_class' ] );
		add_action( 'load-admin_page_' . self::STUDIO, function () {
			$GLOBALS['title'] = __( 'Live studio', 'whd' ); // hidden pages get no <title> from the menu tables
		} );
		foreach ( [ 'save', 'delete', 'leads', 'test', 'yt_connect', 'yt_oauth', 'yt_disconnect' ] as $action ) {
			add_action( 'admin_post_whd_live_' . $action, [ __CLASS__, 'handle_' . $action ] );
		}
	}

	public static function menu() {
		$cap = 'manage_options';
		add_submenu_page( 'whd', __( 'Live Stream', 'whd' ), __( 'Live Stream', 'whd' ), $cap, self::PAGE, [ __CLASS__, 'screen' ] );
		add_submenu_page( '', __( 'Live studio', 'whd' ), __( 'Live studio', 'whd' ), $cap, self::STUDIO, [ __CLASS__, 'studio' ] );
	}

	public static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::PAGE ], $args ), admin_url( 'admin.php' ) );
	}

	public static function studio_url( $id ): string {
		return add_query_arg( [ 'page' => self::STUDIO, 'id' => (int) $id ], admin_url( 'admin.php' ) );
	}

	public static function settings_url(): string {
		return add_query_arg( [ 'page' => 'whd-settings', 'tab' => 'live' ], admin_url( 'admin.php' ) );
	}

	/* ─────────────────────────── assets ─────────────────────────── */

	public static function body_class( $classes ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which screen this is
		return isset( $_GET['page'] ) && self::STUDIO === $_GET['page'] ? $classes . ' whdls-page' : $classes;
	}

	public static function assets() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which screen this is
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		if ( self::PAGE === $page || ( 'whd-settings' === $page && 'live' === $tab ) ) {
			wp_enqueue_style( 'whd-admin', WHD_URL . 'admin/admin.css', [], WHD_VERSION );
			wp_enqueue_style( 'whd-live-admin', WHD_URL . 'admin/live.css', [ 'whd-admin' ], WHD_VERSION );
		}
		if ( self::PAGE === $page ) {
			wp_enqueue_media();
			wp_enqueue_script( 'whd-live-form', WHD_URL . 'admin/live-form.js', [], WHD_VERSION, true );
			wp_localize_script( 'whd-live-form', 'WHD_LIVE_FORM', [
				'rest' => [ 'root' => esc_url_raw( rest_url( 'whd/v1/live/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ],
				'i18n' => [
					'found'       => __( 'Showing %1$d of %2$d', 'whd' ),
					'failed'      => __( 'Your pieces could not be loaded: %s', 'whd' ),
					'retry'       => __( 'Try again', 'whd' ),
					'soldOut'     => __( 'Sold out', 'whd' ),
					'chosen'      => __( 'Selected: %d', 'whd' ),
					'chosenNone'  => __( 'Nothing selected yet. Tick pieces in the list above.', 'whd' ),
					'none'        => __( 'Nothing matches that.', 'whd' ),
					'remove'      => __( 'Remove', 'whd' ),
					'up'          => __( 'Move up', 'whd' ),
					'code'        => __( 'Code', 'whd' ),
					'codeOr'      => __( 'Code (or %d)', 'whd' ),
					'codeDup'     => __( 'Another piece already uses this code.', 'whd' ),
					'copy'        => __( 'Copy', 'whd' ),
					'poster'      => __( 'Choose a cover picture', 'whd' ),
					'use'         => __( 'Use this picture', 'whd' ),
					'confirmDrop' => __( 'Delete this stream and its comments? This can’t be undone.', 'whd' ),
					'copied'      => __( 'Copied', 'whd' ),
				],
			] );
		}
		if ( self::STUDIO === $page ) {
			self::studio_assets();
		}
	}

	private static function studio_assets() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which stream to open
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( ! WHD_Live::exists( $id ) ) {
			return;
		}
		wp_enqueue_media();
		// No version on the fonts: WordPress would rebuild the address and keep only the last family.
		wp_enqueue_style( 'whd-live-fonts', WHD_Live::FONTS, [], null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_style( 'whd-live-studio', WHD_URL . 'admin/live-studio.css', [], WHD_VERSION );
		wp_enqueue_script( 'whd-live-stage', WHD_URL . 'admin/live-stage.js', [], WHD_VERSION, true );
		wp_enqueue_script( 'whd-live-sound', WHD_URL . 'admin/live-sound.js', [], WHD_VERSION, true );
		wp_enqueue_script( 'whd-live-studio', WHD_URL . 'admin/live-studio.js', [ 'whd-live-stage', 'whd-live-sound' ], WHD_VERSION, true );

		$stream   = WHD_Live::get( $id );
		$channels = WHD_Live::channels();
		$scene    = json_decode( (string) get_post_meta( $id, WHD_Live::SCENE, true ), true );
		$showing  = get_post_meta( $id, WHD_Live::SHOWING, true );
		$settings = WHD_Live::settings();

		$dest_list = [];
		$replies   = false;
		foreach ( (array) $stream['destinations'] as $key ) {
			if ( empty( $channels[ $key ] ) ) {
				continue;
			}
			$dest_list[ $key ] = [
				'label'   => $channels[ $key ]['label'],
				'replies' => (bool) $channels[ $key ]['replies'],
				'ready'   => (bool) $channels[ $key ]['ready'],
				'note'    => $channels[ $key ]['note'],
			];
			$replies = $replies || $channels[ $key ]['replies'];
		}

		wp_localize_script( 'whd-live-studio', 'WHD_STUDIO', [
			'id'        => $id,
			'title'     => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'pageUrl'   => WHD_Live::has_site( $stream ) ? WHD_Live::page_url( $id ) : '',
			'pagePretty' => WHD_Live::has_site( $stream ) ? WHD_Live::pretty_url( WHD_Live::page_url( $id ) ) : '',
			'format'    => $stream['format'],
			'stage'     => $stream['stage'],
			'startedAt' => (int) $stream['started_at'],
			'destinations' => (object) $dest_list,
			'dests'     => (object) WHD_Live_Channels::public_dests( (array) $stream['dests'] ),
			'needsTikTokKey' => in_array( 'tiktok', (array) $stream['destinations'], true ) && '' === (string) $settings['tt_key'] && '' === (string) $stream['tt_key'],
			'dailyReady' => WHD_Live::daily_ready(),
			'dailyJs'   => WHD_Live::DAILY_JS,
			'pieces'    => WHD_Live::pieces( $id ),
			'sounds'    => self::sound_library(),
			'stickers'  => self::sticker_library(),
			// KLIPY requires calls to come straight from the browser, so unlike the stream keys this one does reach it: the host's, on this page only.
			'klipy'     => (string) $settings['klipy_key'],
			'occasions' => (object) self::occasion_library(),
			'scene'     => is_array( $scene ) ? $scene : null,
			'showing'   => is_array( $showing ) ? (int) ( $showing['id'] ?? 0 ) : 0,
			// With nowhere that can answer a comment, the card asks for nothing and shows the link.
			'cta'       => $replies ? (string) $settings['card_cta'] : '',
			'logo'      => (string) apply_filters( 'whd_live_logo', function_exists( 'omc_logo' ) ? omc_logo( 'white' )['url'] : '' ),
			'coverLogo' => (string) apply_filters( 'whd_live_cover_logo', function_exists( 'omc_logo' ) ? omc_logo( 'cream' )['url'] : '' ),
			'brand'     => html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
			'listUrl'   => self::url(),
			'settingsUrl' => self::settings_url(),
			'leadsCsv'  => wp_nonce_url( admin_url( 'admin-post.php?action=whd_live_leads&id=' . $id ), 'whd_live_leads_' . $id ),
			'rest'      => [ 'root' => esc_url_raw( rest_url( 'whd/v1/live/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ],
			'i18n'      => self::studio_words(),
		] );
	}

	/** The ready-made music and effects: public-domain files in the plugin, listed in sounds.json. */
	private static function sound_library(): array {
		$file = WHD_DIR . 'assets/live-sounds/sounds.json';
		$data = file_exists( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own file
		$out  = [ 'music' => [], 'fx' => [] ];
		foreach ( array_keys( $out ) as $kind ) {
			foreach ( (array) ( $data[ $kind ] ?? [] ) as $item ) {
				$item['url'] = WHD_URL . 'assets/live-sounds/' . $item['file'];
				unset( $item['file'], $item['source'], $item['license'] );
				$out[ $kind ][] = $item;
			}
		}
		return $out;
	}

	/** The ready-made moving stickers, listed in stickers.json. */
	private static function sticker_library(): array {
		$file = WHD_DIR . 'assets/live-stickers/stickers.json';
		$data = file_exists( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own file
		$out  = [];
		foreach ( (array) $data as $item ) {
			$item['url'] = WHD_URL . 'assets/live-stickers/' . $item['file'];
			unset( $item['file'], $item['source'] );
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Seasons and celebrations: the frame's colours, words and corner pictures (largest first; the fifth and sixth go on
	 * the left), what falls, and the music from sounds.json. No year in the words, so they are right every year.
	 */
	private static function occasion_library(): array {
		$list = [
			'christmas'   => [ __( 'Christmas', 'whd' ), __( 'Merry Christmas', 'whd' ), 'serif', [ '#8c1c13', '#0f5132' ], '#e3c26b', [ '#a3201a', '#7c1610' ], '#fff4dc', 'snow', [ 'tree', 'bell', 'gift', 'santa', 'star', 'snowman' ] ],
			'newyear'     => [ __( 'New Year', 'whd' ), __( 'Happy New Year', 'whd' ), 'serif', [ '#111a33', '#3b2a12' ], '#e8c872', [ '#161f3d', '#2b2140' ], '#f6dd9a', 'confetti', [ 'fireworks', 'sparkler', 'champagne', 'clink', 'popper', 'balloon' ] ],
			'blackfriday' => [ __( 'Black Friday', 'whd' ), __( 'Black Friday', 'whd' ), 'sans', [ '#050505', '#1d1d1d' ], '#e0b64a', [ '#0a0a0a', '#222222' ], '#f2cd63', 'tags', [ 'bags', 'bolt', 'tag', 'blackheart', 'moneybag', 'fire' ] ],
			'cybermonday' => [ __( 'Cyber Monday', 'whd' ), __( 'Cyber Monday', 'whd' ), 'sans', [ '#0b1e6b', '#8a1cff' ], '#46e8ff', [ '#0d2a8c', '#7a1cf0' ], '#ffffff', 'bolts', [ 'laptop', 'bolt', 'robot', 'phone', 'card', 'box' ] ],
			'valentine'   => [ __( 'Valentine’s', 'whd' ), __( 'Happy Valentine’s Day', 'whd' ), 'serif', [ '#b3123f', '#f27a9b' ], '#ffe3ea', [ '#c1174a', '#e0456f' ], '#fff5f7', 'hearts', [ 'rose', 'hearts', 'letter', 'cupid', 'heart', 'kiss' ] ],
			'lunar'       => [ __( 'Lunar New Year', 'whd' ), __( 'Happy Lunar New Year', 'whd' ), 'serif', [ '#a30e14', '#d4202a' ], '#f4cf6e', [ '#b5121a', '#8f0b11' ], '#ffe9ad', 'lanterns', [ 'lantern', 'firecracker', 'dragonface', 'envelope', 'coin', 'dragon' ] ],
			'mothers'     => [ __( 'Mother’s Day', 'whd' ), __( 'Happy Mother’s Day', 'whd' ), 'serif', [ '#d98aa0', '#f2c6cf' ], '#fff7ef', [ '#c97b92', '#dd98ab' ], '#fffaf5', 'petals', [ 'bouquet', 'ribbon', 'tulip', 'growingheart', 'hibiscus', 'gift' ] ],
			'halloween'   => [ __( 'Halloween', 'whd' ), __( 'Happy Halloween', 'whd' ), 'serif', [ '#1c0f2e', '#d9590a' ], '#ffb04d', [ '#25123d', '#3a1a52' ], '#ffc27a', 'bats', [ 'pumpkin', 'bat', 'ghost', 'spider', 'moon', 'web' ] ],
			'sale'        => [ __( 'Big sale', 'whd' ), __( 'Big Sale', 'whd' ), 'sans', [ '#6f4a3f', '#b98b7e' ], '#fff4e8', [ '#7c5347', '#a87b6e' ], '#ffffff', 'confetti', [ 'bags', 'sparkles', 'tag', 'megaphone', 'gift', 'moneybag' ] ],
			'spring'      => [ __( 'Spring', 'whd' ), __( 'Hello Spring', 'whd' ), 'serif', [ '#7fb77e', '#f7b2c4' ], '#fffdf2', [ '#6ea96d', '#e596ad' ], '#fffef5', 'petals', [ 'blossom', 'butterfly', 'tulip', 'bee', 'rainbow', 'sprout' ] ],
			'summer'      => [ __( 'Summer', 'whd' ), __( 'Hello Summer', 'whd' ), 'serif', [ '#0ea5b7', '#ffb703' ], '#fffbe6', [ '#0c93a3', '#f29e02' ], '#ffffff', '', [ 'palm', 'shades', 'sun', 'watermelon', 'drink', 'wave' ] ],
			'autumn'      => [ __( 'Autumn', 'whd' ), __( 'Hello Autumn', 'whd' ), 'serif', [ '#8a3a12', '#d98324' ], '#ffe9c9', [ '#96421a', '#b5611b' ], '#fff3df', 'leaves', [ 'maple', 'leaf', 'pumpkin', 'mushroom', 'chestnut', 'scarf' ] ],
			'winter'      => [ __( 'Winter', 'whd' ), __( 'Hello Winter', 'whd' ), 'serif', [ '#2f5f96', '#a9d3ee' ], '#ffffff', [ '#2f5f96', '#4f85bd' ], '#ffffff', 'snow', [ 'snowflake', 'cocoa', 'snowman', 'gloves', 'coat', 'scarf' ] ],
		];
		$file   = WHD_DIR . 'assets/live-sounds/sounds.json';
		$sounds = file_exists( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own file
		$out    = [];
		foreach ( $list as $key => $o ) {
			$tracks = [];
			foreach ( (array) ( $sounds['occasions'][ $key ] ?? [] ) as $track ) {
				$tracks[] = [
					'url'     => WHD_URL . 'assets/live-sounds/' . $track['file'],
					'title'   => $track['title'],
					'seconds' => (int) $track['seconds'],
				];
			}
			$out[ $key ] = [
				'label'  => $o[0],
				'words'  => $o[1],
				'font'   => $o[2],
				'band'   => $o[3],
				'line'   => $o[4],
				'ribbon' => $o[5],
				'ink'    => $o[6],
				'rain'   => $o[7],
				'art'    => array_map( static fn( $name ) => WHD_URL . 'assets/live-stickers/frames/' . $name . '.png', $o[8] ),
				'tracks' => $tracks,
			];
		}
		return $out;
	}

	private static function studio_words(): array {
		return [
			'goLive'       => __( 'Go live', 'whd' ),
			'going'        => __( 'Going live…', 'whd' ),
			'endLive'      => __( 'End live', 'whd' ),
			'rejoin'       => __( 'Back on air', 'whd' ),
			'ended'        => __( 'Ended', 'whd' ),
			'preview'      => __( 'Preview', 'whd' ),
			'onAir'        => __( 'On air', 'whd' ),
			'offAir'       => __( 'Not on air', 'whd' ),
			'waiting'      => __( 'Ready', 'whd' ),
			'connecting'   => __( 'Connecting', 'whd' ),
			'connected'    => __( 'Live', 'whd' ),
			'interrupted'  => __( 'Interrupted', 'whd' ),
			'failed'       => __( 'Failed', 'whd' ),
			'needHttps'    => __( 'The camera only works over HTTPS. Open this page at the https:// address of your site.', 'whd' ),
			'noCamera'     => __( 'The camera or microphone could not be opened. Allow them for this site in your browser, then reload.', 'whd' ),
			'noDaily'      => __( 'Add your Daily API key in Settings before going live.', 'whd' ),
			'dailyLoad'    => __( 'Daily’s video library could not be loaded. Check your connection and reload.', 'whd' ),
			'unsupported'  => __( 'This browser can’t stream. Use a current version of Chrome or Edge.', 'whd' ),
			'joinFailed'   => __( 'Could not join the room: %s', 'whd' ),
			'lostHost'     => __( 'Connection dropped. Reconnecting…', 'whd' ),
			'backOn'       => __( 'Connection is back.', 'whd' ),
			'rtmpFailed'   => __( 'The other platforms did not start: %s', 'whd' ),
			'destFailed'   => __( '%1$s: %2$s', 'whd' ),
			'signedOut'    => __( 'You have been signed out, so comments have stopped updating. The video is still on air. Sign in again in another tab.', 'whd' ),
			'noPieces'     => __( 'Search above to add the pieces you’ll show.', 'whd' ),
			'searchPieces' => __( 'Search your pieces…', 'whd' ),
			'show'         => __( 'Show', 'whd' ),
			'hide'         => __( 'Take off', 'whd' ),
			/* translators: %d: how many photos the piece has */
			'photos'       => __( 'Photos (%d)', 'whd' ),
			'photosClose'  => __( 'Close photos', 'whd' ),
			'photosHint'   => __( 'Open this piece’s photos large in the video, at the spot you chose', 'whd' ),
			'photoPrev'    => __( 'Previous photo', 'whd' ),
			'photoNext'    => __( 'Next photo', 'whd' ),
			'photoHold'    => __( 'Hold this one', 'whd' ),
			'photoPlay'    => __( 'Keep going', 'whd' ),
			'spotNames'    => [
				'photos'  => __( 'Where photos open', 'whd' ),
				'card'    => __( 'Where the card appears', 'whd' ),
				'picture' => __( 'Where pictures appear', 'whd' ),
				'gif'     => __( 'Where GIFs pop up', 'whd' ),
			],
			'spotPlaces'   => [
				'left'   => __( 'Left', 'whd' ),
				'centre' => __( 'Centre', 'whd' ),
				'right'  => __( 'Right', 'whd' ),
				'top'    => __( 'Top', 'whd' ),
				'middle' => __( 'Middle', 'whd' ),
				'lower'  => __( 'Lower', 'whd' ),
			],
			'klipyNone'    => __( 'Nothing found. Try another word.', 'whd' ),
			'klipyKey'     => __( 'KLIPY didn’t accept the key. Check it in Settings → Live Stream.', 'whd' ),
			'klipyLimit'   => __( 'That’s all the searches this key allows for now (a test key has 100 calls an hour). Your stickers below still work.', 'whd' ),
			'klipyFail'    => __( 'KLIPY didn’t answer. Check your connection and try again.', 'whd' ),
			'pickGif'      => __( 'Choose GIFs', 'whd' ),
			'useGif'       => __( 'Use these', 'whd' ),
			'pressPlay'    => __( 'Press play to start', 'whd' ),
			'soundFailed'  => __( 'That sound wouldn’t play. Check your connection, then try it again or pick another.', 'whd' ),
			'remove'       => __( 'Remove', 'whd' ),
			'soldOut'      => __( 'Sold out', 'whd' ),
			'requests'     => __( 'asked', 'whd' ),
			'code'         => __( 'Code', 'whd' ),
			'codeOr'       => __( 'Code (or %d)', 'whd' ),
			'codeDup'      => __( 'Another piece already uses this code.', 'whd' ),
			'commentCode'  => __( 'Comment the code', 'whd' ),
			'copy'         => __( 'Copy', 'whd' ),
			'copied'       => __( 'Copied', 'whd' ),
			'pickImage'    => __( 'Choose a picture', 'whd' ),
			'useImage'     => __( 'Put it on stage', 'whd' ),
			'nothingOn'    => __( 'Nothing on stage yet. Add a picture, text or a piece.', 'whd' ),
			'noComments'   => __( 'Comments show up here as they come in.', 'whd' ),
			'noLeads'      => __( 'Names and addresses from the page show up here.', 'whd' ),
			'sendLink'     => __( 'Send link', 'whd' ),
			'hideComment'  => __( 'Hide', 'whd' ),
			'block'        => __( 'Block', 'whd' ),
			'needPiece'    => __( 'Show a piece first, then send its link.', 'whd' ),
			'statusPanel'  => __( 'Opened the piece', 'whd' ),
			'statusDm'     => __( 'Sent in Messenger', 'whd' ),
			'statusComment' => __( 'Replied with link', 'whd' ),
			'statusChat'   => __( 'Answered in chat', 'whd' ),
			'statusQueued' => __( 'Sending…', 'whd' ),
			'statusFailed' => __( 'Not sent', 'whd' ),
			'statusSkipped' => __( 'Already had it', 'whd' ),
			'site'         => __( 'Site', 'whd' ),
			'facebook'     => __( 'Facebook', 'whd' ),
			'youtube'      => __( 'YouTube', 'whd' ),
			'hours'        => __( '%dh %dm', 'whd' ),
			'minutes'      => __( '%d min', 'whd' ),
			'endsIn'       => __( 'Ends in', 'whd' ),
			'nowShowing'   => __( 'Now showing', 'whd' ),
			'confirmLeave' => __( 'You are live. Leaving ends your picture for everyone.', 'whd' ),
			'cover'        => __( 'Cover screen', 'whd' ),
			'uncover'      => __( 'Back to camera', 'whd' ),
			'placeholder'  => __( 'Camera off', 'whd' ),
			'mute'         => __( 'Mute', 'whd' ),
			'unmute'       => __( 'Unmute', 'whd' ),
			'record'       => __( 'Record', 'whd' ),
			'recordStop'   => __( 'Stop', 'whd' ),
			'recordPause'  => __( 'Pause', 'whd' ),
			'recordResume' => __( 'Resume', 'whd' ),
			'recordSaving' => __( 'Saving %d%', 'whd' ),
			'recordSaved'  => __( 'In your media library: %s', 'whd' ),
			'recordOpen'   => __( 'Open it', 'whd' ),
			'recordRetry'  => __( 'The recording is not reaching the server. Still recording, and still trying.', 'whd' ),
			'recordLocal'  => __( 'The recording could not reach the media library, so it has been saved to this computer instead.', 'whd' ),
			'recordFailed' => __( 'This browser can’t record the stream.', 'whd' ),
			'recordLeave'  => __( 'A recording is still running and has not been saved.', 'whd' ),
			'wrapSaving'   => __( 'Saving your recording to the media library. Keep this tab open.', 'whd' ),
			'main'         => __( 'On screen', 'whd' ),
			'makeMain'     => __( 'Put on screen', 'whd' ),
			'corner'       => __( 'In a corner', 'whd' ),
			'cornerOff'    => __( 'Out of the corner', 'whd' ),
			'flip'         => __( 'Mirror', 'whd' ),
			'zoom'         => __( 'Zoom', 'whd' ),
			'whole'        => __( 'Show all of it', 'whd' ),
			'camera'       => __( 'Camera', 'whd' ),
			'screen'       => __( 'Screen', 'whd' ),
			'noMoreCams'   => __( 'No other cameras found', 'whd' ),
			'camFailed'    => __( 'That camera could not be opened. It may be in use by another app.', 'whd' ),
			'shareFailed'  => __( 'Screen sharing did not start.', 'whd' ),
			'sceneName'    => __( 'Scene %d', 'whd' ),
			'sceneRename'  => __( 'Name this scene', 'whd' ),
			'sceneRemove'  => __( 'Remove this scene?', 'whd' ),
			'noTracks'     => __( 'Your list is empty. Add from below.', 'whd' ),
			'allMoods'     => __( 'All', 'whd' ),
			'hear'         => __( 'Preview', 'whd' ),
			'hearStop'     => __( 'Stop preview', 'whd' ),
			'addTrack'     => __( 'Add', 'whd' ),
			'trackAdded'   => __( 'Added', 'whd' ),
			'pickAudio'    => __( 'Choose music', 'whd' ),
			'useAudio'     => __( 'Add it', 'whd' ),
			'pickPad'      => __( 'Choose a sound', 'whd' ),
			'netGood'      => __( 'Connection good', 'whd' ),
			'netWarning'   => __( 'Connection weak', 'whd' ),
			'netBad'       => __( 'Connection poor', 'whd' ),
			'netBanner'    => __( 'Your connection is struggling, so the video quality may drop. Close other apps or move closer to your router.', 'whd' ),
			'tryAgain'     => __( 'Try again', 'whd' ),
			'settings'     => __( 'Settings', 'whd' ),
			'stopped'      => __( 'Stopped', 'whd' ),
			'allSources'   => __( 'All', 'whd' ),
			'wrapPeak'     => __( 'peak on site', 'whd' ),
			'wrapComments' => __( 'comments', 'whd' ),
			'wrapLeads'    => __( 'leads', 'whd' ),
			'wrapLinks'    => __( 'links sent', 'whd' ),
			'wrapClicks'   => __( 'link clicks', 'whd' ),
			'wrapTime'     => __( 'on air', 'whd' ),
			'endedBanner'  => __( 'This stream has ended.', 'whd' ),
			'typeLayer'    => [
				'image'     => __( 'Picture', 'whd' ),
				'text'      => __( 'Text', 'whd' ),
				'product'   => __( 'Piece', 'whd' ),
				'ticker'    => __( 'Ticker', 'whd' ),
				'lower'     => __( 'Name strip', 'whd' ),
				'countdown' => __( 'Countdown', 'whd' ),
				'gallery'   => __( 'Photos', 'whd' ),
			],
		];
	}

	/* ─────────────────────────── settings ─────────────────────────── */

	public static function register_setting() {
		register_setting( self::GROUP, WHD_Live::OPTION, [ 'type' => 'array', 'sanitize_callback' => [ __CLASS__, 'sanitize' ] ] );
	}

	public static function sanitize( $in ): array {
		$in    = is_array( $in ) ? $in : [];
		$old   = (array) get_option( WHD_Live::OPTION, [] );
		$clear = isset( $in['clear'] ) && is_array( $in['clear'] ) ? $in['clear'] : [];
		$out   = [];

		foreach ( self::SECRETS as $key ) {
			$typed       = trim( (string) ( $in[ $key ] ?? '' ) );
			$out[ $key ] = ! empty( $clear[ $key ] ) ? '' : ( '' !== $typed ? sanitize_text_field( $typed ) : (string) ( $old[ $key ] ?? '' ) );
		}
		foreach ( [ 'site_on', 'fb_on', 'yt_on', 'tt_on' ] as $key ) {
			$out[ $key ] = empty( $in[ $key ] ) ? 0 : 1;
		}
		foreach ( [ 'card_cta', 'site_text', 'fb_page_id', 'fb_server', 'fb_dm_text', 'fb_public_text', 'yt_client_id', 'yt_server', 'yt_text', 'tt_server' ] as $key ) {
			$out[ $key ] = sanitize_text_field( (string) ( $in[ $key ] ?? '' ) );
		}
		$out['yt_privacy'] = in_array( $in['yt_privacy'] ?? '', [ 'public', 'unlisted', 'private' ], true ) ? $in['yt_privacy'] : 'public';
		return $out;
	}

	private static function secret_box( string $key, string $id, string $placeholder = '' ) {
		$value = (string) WHD_Live::settings()[ $key ];
		$name  = WHD_Live::OPTION . '[' . $key . ']';
		printf(
			'<input class="regular-text" type="password" autocomplete="new-password" id="%1$s" name="%2$s" value="" placeholder="%3$s">',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( '' !== $value ? __( 'Saved. Leave blank to keep it', 'whd' ) : $placeholder )
		);
		if ( '' !== $value ) {
			echo ' <code class="whd-secret">' . esc_html( substr( $value, 0, 3 ) . str_repeat( '•', 10 ) . substr( $value, -3 ) ) . '</code>';
			echo ' <label class="whd-clear"><input type="checkbox" name="' . esc_attr( WHD_Live::OPTION . '[clear][' . $key . ']' ) . '" value="1"> ' . esc_html__( 'Remove', 'whd' ) . '</label>';
		}
	}

	private static function text_box( string $key, string $id, string $class = 'regular-text', string $placeholder = '' ) {
		printf(
			'<input class="%4$s" id="%1$s" name="%2$s" value="%3$s" placeholder="%5$s">',
			esc_attr( $id ),
			esc_attr( WHD_Live::OPTION . '[' . $key . ']' ),
			esc_attr( (string) WHD_Live::settings()[ $key ] ),
			esc_attr( $class ),
			esc_attr( $placeholder )
		);
	}

	private static function toggle( string $key, string $label ) {
		printf(
			'<label class="whdla-switch"><input type="checkbox" name="%1$s" value="1" %2$s><span></span> %3$s</label>',
			esc_attr( WHD_Live::OPTION . '[' . $key . ']' ),
			checked( (int) WHD_Live::settings()[ $key ], 1, false ),
			esc_html( $label )
		);
	}

	private static function test_button( string $channel, string $label ) {
		printf(
			'<button class="button" type="submit" form="whdla-test-%1$s">%2$s</button>',
			esc_attr( $channel ),
			esc_html( $label )
		);
	}

	/** Printed under WHD → Settings → Live Stream. */
	public static function settings_tab() {
		$s      = WHD_Live::settings();
		$notice = get_transient( 'whd_live_notice_' . get_current_user_id() );
		if ( is_array( $notice ) ) {
			delete_transient( 'whd_live_notice_' . get_current_user_id() );
			echo '<div class="notice notice-' . ( $notice['ok'] ? 'success' : 'error' ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}

		echo '<form method="post" action="options.php" class="whd-form whdla-settings">';
		settings_fields( self::GROUP );

		/* Daily */
		echo '<h2 class="whd-h2">' . esc_html__( 'Daily: the video', 'whd' ) . '</h2>';
		echo '<p class="whd-intro">' . wp_kses_post( sprintf(
			/* translators: %s: link to Daily's developer dashboard */
			__( 'Every live runs through a Daily room. Copy the API key from your <a href="%s" target="_blank" rel="noopener">Daily dashboard → Developers</a>. Sending to Facebook, YouTube or TikTok needs a card on file with Daily. A room holds 200 viewers until Daily raises that for your account.', 'whd' ),
			'https://dashboard.daily.co/developers'
		) ) . '</p>';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="whdla-daily">' . esc_html__( 'Daily API key', 'whd' ) . '</label></th><td>';
		self::secret_box( 'daily_api_key', 'whdla-daily', __( 'Paste the key', 'whd' ) );
		echo ' ';
		self::test_button( 'daily', __( 'Test', 'whd' ) );
		echo '</td></tr></tbody></table>';

		/* The page and the link */
		echo '<h2 class="whd-h2">' . esc_html__( 'The live page and the link', 'whd' ) . '</h2>';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Live page on this site', 'whd' ) . '</th><td>';
		self::toggle( 'site_on', __( 'Offer this website as a place to stream to', 'whd' ) );
		echo '<p class="description">' . esc_html( sprintf(
			/* translators: %s: address */
			__( 'Each stream gets its own page, and %s always leads to the current one. Anyone can comment after leaving a name and email once. A comment with a piece’s code opens it in a side panel.', 'whd' ),
			preg_replace( '#^https?://#', '', home_url( '/live/' ) )
		) ) . '</p></td></tr>';

		echo '<tr><th><label for="whdla-cta">' . esc_html__( 'Line on the product card', 'whd' ) . '</label></th><td>';
		self::text_box( 'card_cta', 'whdla-cta' );
		echo '<p class="description">' . esc_html__( 'Drawn into the video under the price, above the piece’s short link. {code} becomes the code you set for the piece, or its number if you set none.', 'whd' ) . '</p></td></tr>';

		echo '<tr><th><label for="whdla-sitetext">' . esc_html__( 'Side panel greeting', 'whd' ) . '</label></th><td>';
		self::text_box( 'site_text', 'whdla-sitetext' );
		echo '<p class="description">' . esc_html__( 'Replies can use {name}, {product}, {price} and {link}.', 'whd' ) . '</p></td></tr>';
		echo '</tbody></table>';

		/* Facebook */
		echo '<h2 class="whd-h2">' . esc_html__( 'Facebook', 'whd' ) . '</h2><table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Stream to Facebook', 'whd' ) . '</th><td>';
		self::toggle( 'fb_on', __( 'Offer Facebook as a place to stream to', 'whd' ) );
		echo '</td></tr>';
		echo '<tr><th><label for="whdla-fbpage">' . esc_html__( 'Page ID', 'whd' ) . '</label></th><td>';
		self::text_box( 'fb_page_id', 'whdla-fbpage', 'regular-text code', '1234567890' );
		echo '</td></tr>';
		echo '<tr><th><label for="whdla-fbtoken">' . esc_html__( 'Page access token', 'whd' ) . '</label></th><td>';
		self::secret_box( 'fb_token', 'whdla-fbtoken', __( 'A long-lived Page token', 'whd' ) );
		echo ' ';
		self::test_button( 'facebook', __( 'Test', 'whd' ) );
		echo '<p class="description">' . esc_html__( 'From a Meta app, with pages_manage_posts, pages_read_engagement, pages_read_user_content, pages_manage_engagement and pages_messaging. With it, a stream creates its own live video on your Page and comments get answered.', 'whd' ) . '</p>';
		echo '<p class="whd-note">' . esc_html__( 'Until Meta approves pages_messaging for Advanced Access (App Review), a Messenger reply only reaches people with a role on your app. Everyone else gets the link as a reply under their comment instead, automatically. Meta also requires 100 followers on the Page to go live.', 'whd' ) . '</p></td></tr>';
		echo '<tr><th><label for="whdla-fbdm">' . esc_html__( 'Message in Messenger', 'whd' ) . '</label></th><td>';
		self::text_box( 'fb_dm_text', 'whdla-fbdm', 'large-text' );
		echo '</td></tr>';
		echo '<tr><th><label for="whdla-fbpub">' . esc_html__( 'Reply under the comment', 'whd' ) . '</label></th><td>';
		self::text_box( 'fb_public_text', 'whdla-fbpub', 'large-text' );
		echo '<p class="description">' . esc_html__( 'Posted once the message has gone, so they know to look. Leave empty for none.', 'whd' ) . '</p></td></tr>';
		echo '<tr><th><label for="whdla-fbserver">' . esc_html__( 'Stream key instead (optional)', 'whd' ) . '</label></th><td>';
		self::text_box( 'fb_server', 'whdla-fbserver', 'regular-text code' );
		echo '<br><span style="display:inline-block;height:6px"></span><br>';
		self::secret_box( 'fb_key', 'whdla-fbkey', __( 'Persistent stream key', 'whd' ) );
		echo '<p class="description">' . esc_html__( 'From Live Producer. Used when there is no token, or when the token isn’t allowed to create a live video. Without a token it streams, but comments can’t be read.', 'whd' ) . '</p></td></tr>';
		echo '</tbody></table>';

		/* YouTube */
		echo '<h2 class="whd-h2">' . esc_html__( 'YouTube', 'whd' ) . '</h2><table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Stream to YouTube', 'whd' ) . '</th><td>';
		self::toggle( 'yt_on', __( 'Offer YouTube as a place to stream to', 'whd' ) );
		echo '</td></tr>';
		echo '<tr><th><label for="whdla-ytid">' . esc_html__( 'OAuth client ID', 'whd' ) . '</label></th><td>';
		self::text_box( 'yt_client_id', 'whdla-ytid', 'large-text code' );
		echo '<p class="description">' . esc_html__( 'Google Cloud Console: enable YouTube Data API v3, then create an OAuth client of type “Web application” with this redirect URI:', 'whd' ) . '</p>';
		echo '<p><code class="whdla-copy">' . esc_html( WHD_Live_Channels::youtube_redirect_uri() ) . '</code></p></td></tr>';
		echo '<tr><th><label for="whdla-ytsecret">' . esc_html__( 'OAuth client secret', 'whd' ) . '</label></th><td>';
		self::secret_box( 'yt_client_secret', 'whdla-ytsecret' );
		echo '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Channel', 'whd' ) . '</th><td>';
		if ( '' !== (string) $s['yt_refresh'] ) {
			echo '<span class="whd-pill whd-pill--on">' . esc_html( $s['yt_channel'] ?: __( 'Connected', 'whd' ) ) . '</span> ';
			self::test_button( 'youtube', __( 'Test', 'whd' ) );
			echo ' <a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=whd_live_yt_disconnect' ), 'whd_live_yt_disconnect' ) ) . '">' . esc_html__( 'Disconnect', 'whd' ) . '</a>';
		} elseif ( '' !== (string) $s['yt_client_id'] && '' !== (string) $s['yt_client_secret'] ) {
			echo '<a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=whd_live_yt_connect' ), 'whd_live_yt_connect' ) ) . '">' . esc_html__( 'Connect your YouTube channel', 'whd' ) . '</a>';
		} else {
			echo '<span class="whd-muted">' . esc_html__( 'Save the client ID and secret first, then connect here.', 'whd' ) . '</span>';
		}
		echo '<p class="description">' . esc_html__( 'Publish the app’s consent screen in Google Cloud: while it says “Testing”, Google drops the connection every 7 days. The channel must also be enabled for live streaming, which YouTube can take a day to do.', 'whd' ) . '</p></td></tr>';

		echo '<tr><th><label for="whdla-ytpriv">' . esc_html__( 'Who can watch', 'whd' ) . '</label></th><td><select id="whdla-ytpriv" name="' . esc_attr( WHD_Live::OPTION ) . '[yt_privacy]">';
		foreach ( [ 'public' => __( 'Public', 'whd' ), 'unlisted' => __( 'Unlisted', 'whd' ), 'private' => __( 'Private', 'whd' ) ] as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $s['yt_privacy'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th><label for="whdla-yttext">' . esc_html__( 'Answer in the live chat', 'whd' ) . '</label></th><td>';
		self::text_box( 'yt_text', 'whdla-yttext', 'large-text' );
		echo '<p class="description">' . esc_html__( 'YouTube has no direct messages, so the answer is a chat message, cut to YouTube’s 200 characters. The chat is read every 10 seconds to stay inside YouTube’s daily quota.', 'whd' ) . '</p></td></tr>';

		echo '<tr><th><label for="whdla-ytserver">' . esc_html__( 'Stream key instead (optional)', 'whd' ) . '</label></th><td>';
		self::text_box( 'yt_server', 'whdla-ytserver', 'regular-text code' );
		echo '<br><span style="display:inline-block;height:6px"></span><br>';
		self::secret_box( 'yt_key', 'whdla-ytkey', __( 'Stream key', 'whd' ) );
		echo '<p class="description">' . esc_html__( 'From YouTube Studio. Without a connected channel it streams, but the chat can’t be read.', 'whd' ) . '</p></td></tr>';
		echo '</tbody></table>';

		/* TikTok */
		echo '<h2 class="whd-h2">' . esc_html__( 'TikTok', 'whd' ) . '</h2><table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Stream to TikTok', 'whd' ) . '</th><td>';
		self::toggle( 'tt_on', __( 'Offer TikTok as a place to stream to', 'whd' ) );
		echo '<p class="whd-note">' . esc_html__( 'TikTok has no public way to read or answer live comments, so nobody on TikTok can be sent a link. The product card in the video carries the piece’s short link instead. Stream keys are only given to accounts TikTok has approved for them.', 'whd' ) . '</p></td></tr>';
		echo '<tr><th><label for="whdla-ttserver">' . esc_html__( 'Server URL', 'whd' ) . '</label></th><td>';
		self::text_box( 'tt_server', 'whdla-ttserver', 'large-text code', 'rtmp://push-rtmp-…tiktokcdn.com/game/' );
		echo '</td></tr>';
		echo '<tr><th><label for="whdla-ttkey">' . esc_html__( 'Stream key', 'whd' ) . '</label></th><td>';
		self::secret_box( 'tt_key', 'whdla-ttkey', __( 'Or paste it each time in the studio', 'whd' ) );
		echo '<p class="description">' . esc_html__( 'TikTok usually issues a new key each session. Leave this empty and the studio asks for it when you go live.', 'whd' ) . '</p></td></tr>';
		echo '</tbody></table>';

		/* KLIPY */
		echo '<h2 class="whd-h2">' . esc_html__( 'KLIPY: search for GIFs in the studio', 'whd' ) . '</h2>';
		echo '<p class="whd-intro">' . wp_kses_post( sprintf(
			/* translators: %s: link to KLIPY's partner panel */
			__( 'Adds a search to the studio’s GIFs tab. Create a key in the <a href="%s" target="_blank" rel="noopener">KLIPY Partner Panel</a> and leave ads off for it. A test key allows 100 calls an hour; for more, request a production key there.', 'whd' ),
			'https://partner.klipy.com/api-keys'
		) ) . '</p>';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="whdla-klipy">' . esc_html__( 'KLIPY API key', 'whd' ) . '</label></th><td>';
		self::secret_box( 'klipy_key', 'whdla-klipy', __( 'Paste the key', 'whd' ) );
		echo '<p class="description">' . esc_html__( 'KLIPY requires searches to come directly from your browser, so this key is in your studio page. It is never on a public page.', 'whd' ) . '</p>';
		echo '<p class="description">' . wp_kses_post( sprintf(
			/* translators: %s: link to KLIPY's terms */
			__( '<strong>Before using it on air:</strong> <a href="%s" target="_blank" rel="noopener">KLIPY’s terms</a> limit its content to personal, non-commercial use unless the owner allows otherwise. Ask developers@klipy.com whether a selling stream is allowed.', 'whd' ),
			'https://klipy.com/support/terms-services'
		) ) . '</p></td></tr>';
		echo '</tbody></table>';

		submit_button();
		echo '</form>';

		// Their own forms: forms cannot nest, and these post somewhere else. The buttons above point here.
		foreach ( [ 'daily', 'facebook', 'youtube' ] as $channel ) {
			echo '<form id="whdla-test-' . esc_attr( $channel ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" hidden>';
			wp_nonce_field( 'whd_live_test' );
			echo '<input type="hidden" name="action" value="whd_live_test"><input type="hidden" name="channel" value="' . esc_attr( $channel ) . '"></form>';
		}
	}

	private static function notice( bool $ok, string $message ) {
		set_transient( 'whd_live_notice_' . get_current_user_id(), [ 'ok' => $ok, 'message' => $message ], MINUTE_IN_SECONDS );
	}

	private static function guard( string $nonce ) {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( $nonce ) ) {
			wp_die( esc_html__( 'Not allowed', 'whd' ) );
		}
	}

	public static function handle_test() {
		self::guard( 'whd_live_test' );
		$channel = sanitize_key( wp_unslash( $_POST['channel'] ?? '' ) );
		switch ( $channel ) {
			case 'daily':
				$res = WHD_Live_Channels::daily_test();
				self::notice(
					! is_wp_error( $res ),
					is_wp_error( $res )
						? $res->get_error_message()
						/* translators: %s: Daily domain name */
						: sprintf( __( 'Connected to Daily: %s.daily.co', 'whd' ), (string) ( $res['domain_name'] ?? '' ) )
				);
				break;
			case 'facebook':
				$res = WHD_Live_Channels::facebook_test();
				if ( is_wp_error( $res ) ) {
					self::notice( false, $res->get_error_message() );
					break;
				}
				$words = sprintf(
					/* translators: %s: Page name */
					__( 'Connected to %s.', 'whd' ),
					(string) ( $res['name'] ?? '' )
				);
				if ( isset( $res['followers_count'] ) && (int) $res['followers_count'] < 100 ) {
					$words .= ' ' . __( 'It has fewer than 100 followers, so Facebook won’t let it go live yet.', 'whd' );
				}
				if ( empty( $res['can_read_live'] ) ) {
					$words .= ' ' . __( 'The token can’t read live videos, so check its permissions.', 'whd' );
				}
				self::notice( true, $words );
				break;
			case 'youtube':
				$res = WHD_Live_Channels::youtube_test();
				self::notice(
					! is_wp_error( $res ),
					is_wp_error( $res )
						? $res->get_error_message()
						/* translators: %s: channel name */
						: sprintf( __( 'Connected to the channel %s.', 'whd' ), (string) ( $res['snippet']['title'] ?? '' ) )
				);
				break;
		}
		wp_safe_redirect( self::settings_url() );
		exit;
	}

	public static function handle_yt_connect() {
		self::guard( 'whd_live_yt_connect' );
		$state = wp_generate_password( 24, false );
		set_transient( 'whd_live_yt_state_' . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS );
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Google's sign-in, not this site
		wp_redirect( WHD_Live_Channels::youtube_auth_url( $state ) );
		exit;
	}

	/** Google sends the browser back here. The state is what ties this to the click on this site. */
	public static function handle_yt_oauth() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed', 'whd' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the state below is the nonce here
		$state    = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
		$code     = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );
		$refused  = sanitize_text_field( wp_unslash( $_GET['error'] ?? '' ) );
		// phpcs:enable
		$expected = get_transient( 'whd_live_yt_state_' . get_current_user_id() );
		delete_transient( 'whd_live_yt_state_' . get_current_user_id() );

		if ( ! $expected || ! hash_equals( (string) $expected, $state ) ) {
			self::notice( false, __( 'That sign-in link had expired. Try connecting again.', 'whd' ) );
		} elseif ( '' !== $refused || '' === $code ) {
			/* translators: %s: Google's reason */
			self::notice( false, sprintf( __( 'Google did not connect the channel: %s', 'whd' ), $refused ?: __( 'no code returned', 'whd' ) ) );
		} else {
			$name = WHD_Live_Channels::youtube_connect( $code );
			if ( is_wp_error( $name ) ) {
				self::notice( false, $name->get_error_message() );
			} else {
				/* translators: %s: channel name */
				self::notice( true, sprintf( __( 'YouTube connected: %s', 'whd' ), $name ?: __( 'your channel', 'whd' ) ) );
			}
		}
		wp_safe_redirect( self::settings_url() );
		exit;
	}

	public static function handle_yt_disconnect() {
		self::guard( 'whd_live_yt_disconnect' );
		WHD_Live_Channels::youtube_disconnect();
		self::notice( true, __( 'YouTube disconnected.', 'whd' ) );
		wp_safe_redirect( self::settings_url() );
		exit;
	}

	/* ─────────────────────────── the list ─────────────────────────── */

	public static function screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which view
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		if ( 'edit' === $view ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::form( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
			return;
		}
		self::listing();
	}

	private static function stage_pill( array $stream ): string {
		$words = [ 'scheduled' => __( 'Not started', 'whd' ), 'live' => __( 'Live now', 'whd' ), 'ended' => __( 'Ended', 'whd' ) ];
		return '<span class="whdla-pill whdla-pill--' . esc_attr( $stream['stage'] ) . '">' . esc_html( $words[ $stream['stage'] ] ?? $stream['stage'] ) . '</span>';
	}

	private static function when( array $stream ): string {
		$format = get_option( 'date_format' ) . ' · ' . get_option( 'time_format' );
		if ( (int) $stream['started_at'] ) {
			return wp_date( $format, (int) $stream['started_at'] );
		}
		return (int) $stream['starts_at'] ? wp_date( $format, (int) $stream['starts_at'] ) : __( 'No date set', 'whd' );
	}

	private static function listing() {
		$ids = get_posts( [ 'post_type' => WHD_Live::CPT, 'post_status' => 'publish', 'posts_per_page' => 50, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC' ] );

		echo '<div class="wrap whd-wrap whdla">';
		echo '<div class="whdla-head"><div><h1 class="whd-title">' . esc_html__( 'Live Stream', 'whd' ) . '</h1>';
		echo '<p class="whd-intro">' . esc_html__( 'Go live from your browser to your own page, Facebook, YouTube and TikTok at once, with effects and product cards in the video, and a link to the piece for whoever asks.', 'whd' ) . '</p></div>';
		echo '<div class="whdla-head__actions"><a class="button" href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Settings', 'whd' ) . '</a> ';
		echo '<a class="button button-primary button-hero" href="' . esc_url( self::url( [ 'view' => 'edit' ] ) ) . '">' . esc_html__( 'New live stream', 'whd' ) . '</a></div></div>';

		if ( ! WHD_Live::daily_ready() ) {
			echo '<div class="whd-note">' . wp_kses_post( sprintf(
				/* translators: %s: link to settings */
				__( 'Add your Daily API key in <a href="%s">Settings</a> first. That is the only thing the live page on this site needs.', 'whd' ),
				esc_url( self::settings_url() )
			) ) . '</div>';
		}

		$channels = WHD_Live::channels();
		if ( ! $ids ) {
			echo '<div class="whdla-empty"><h2>' . esc_html__( 'No streams yet', 'whd' ) . '</h2><p>' . esc_html__( 'Make one, choose where it goes and which pieces you’ll show, then open the studio when you’re ready.', 'whd' ) . '</p></div>';
		}
		echo '<div class="whdla-list">';
		foreach ( $ids as $id ) {
			$stream = WHD_Live::expire_if_gone( $id );
			$stats  = WHD_Live_Comments::stats( $id );
			$poster = get_the_post_thumbnail_url( $id, 'medium' );
			$peak   = (int) WHD_Live::rt( $id )['peak'];

			echo '<article class="whdla-row' . ( 'live' === $stream['stage'] ? ' is-live' : '' ) . '">';
			echo '<div class="whdla-row__art whdla-row__art--' . esc_attr( $stream['format'] ) . '"' . ( $poster ? ' style="background-image:url(' . esc_url( $poster ) . ')"' : '' ) . '></div>';
			echo '<div class="whdla-row__main">';
			echo '<div class="whdla-row__line">' . self::stage_pill( $stream ) . '<span class="whd-muted">' . esc_html( self::when( $stream ) ) . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in stage_pill
			echo '<h2><a href="' . esc_url( self::studio_url( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a></h2>';
			if ( WHD_Live::has_site( $stream ) ) {
				self::link_line( $id );
			}
			echo '<div class="whdla-chips">';
			foreach ( (array) $stream['destinations'] as $key ) {
				if ( isset( $channels[ $key ] ) ) {
					echo '<span class="whdla-chip whdla-chip--' . esc_attr( $key ) . '">' . esc_html( $channels[ $key ]['label'] ) . '</span>';
				}
			}
			echo '<span class="whdla-chip whdla-chip--plain">' . esc_html( sprintf( _n( '%d piece', '%d pieces', count( WHD_Live::piece_rows( $id ) ), 'whd' ), count( WHD_Live::piece_rows( $id ) ) ) ) . '</span>';
			echo '</div></div>';

			if ( 'scheduled' !== $stream['stage'] ) {
				echo '<dl class="whdla-stats">';
				foreach ( [ [ $peak, __( 'peak', 'whd' ) ], [ $stats['comments'], __( 'comments', 'whd' ) ], [ $stats['leads'], __( 'leads', 'whd' ) ], [ $stats['links'], __( 'links', 'whd' ) ], [ $stats['clicks'], __( 'clicks', 'whd' ) ] ] as $stat ) {
					echo '<div><dd>' . esc_html( number_format_i18n( $stat[0] ) ) . '</dd><dt>' . esc_html( $stat[1] ) . '</dt></div>';
				}
				echo '</dl>';
			}

			echo '<div class="whdla-row__actions">';
			if ( 'ended' !== $stream['stage'] ) {
				echo '<a class="button button-primary" href="' . esc_url( self::studio_url( $id ) ) . '">' . esc_html( 'live' === $stream['stage'] ? __( 'Back to the studio', 'whd' ) : __( 'Open the studio', 'whd' ) ) . '</a>';
			}
			if ( 'scheduled' === $stream['stage'] ) {
				echo '<a class="button" href="' . esc_url( self::url( [ 'view' => 'edit', 'id' => $id ] ) ) . '">' . esc_html__( 'Edit', 'whd' ) . '</a>';
			}
			if ( $stats['leads'] ) {
				echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=whd_live_leads&id=' . $id ), 'whd_live_leads_' . $id ) ) . '">' . esc_html__( 'Leads CSV', 'whd' ) . '</a>';
			}
			$recordings = WHD_Live_Recordings::of_stream( $id );
			if ( $recordings ) {
				$to = 1 === count( $recordings ) ? get_edit_post_link( $recordings[0], 'raw' ) : add_query_arg( [ 'mode' => 'list', 's' => rawurlencode( (string) get_the_title( $id ) ) ], admin_url( 'upload.php' ) );
				/* translators: %d: number of recordings */
				echo '<a class="button" href="' . esc_url( $to ) . '">' . esc_html( sprintf( _n( '%d recording', '%d recordings', count( $recordings ), 'whd' ), count( $recordings ) ) ) . '</a>';
			}
			if ( 'live' !== $stream['stage'] ) {
				echo '<a class="whdla-delete" data-whdla-confirm href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=whd_live_delete&id=' . $id ), 'whd_live_delete_' . $id ) ) . '">' . esc_html__( 'Delete', 'whd' ) . '</a>';
			}
			echo '</div></article>';
		}
		echo '</div></div>';
	}

	/** The stream's own address, to open or to copy. Works from the moment the stream is saved. */
	private static function link_line( $id ) {
		$url = WHD_Live::page_url( $id );
		echo '<p class="whdla-link"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( WHD_Live::pretty_url( $url ) ) . '</a>'
			. ' <button type="button" class="whdla-copy" data-copy="' . esc_url( $url ) . '">' . esc_html__( 'Copy', 'whd' ) . '</button></p>';
	}

	/* ─────────────────────────── the form ─────────────────────────── */

	private static function form( int $id ) {
		$exists = $id && WHD_Live::exists( $id );
		$stream = $exists ? WHD_Live::get( $id ) : WHD_Live::blank();
		$poster = $exists ? (int) get_post_thumbnail_id( $id ) : 0;
		$when   = (int) $stream['starts_at'] ? wp_date( 'Y-m-d\TH:i', (int) $stream['starts_at'] ) : '';
		$rows   = [];
		if ( $exists ) {
			foreach ( WHD_Live::pieces( $id ) as $piece ) {
				$rows[] = [ 'id' => $piece['id'], 'name' => $piece['name'], 'price' => $piece['price'], 'thumb' => $piece['thumb'], 'code' => $piece['code'] ];
			}
		}

		echo '<div class="wrap whd-wrap whdla">';
		echo '<p class="whdla-crumb"><a href="' . esc_url( self::url() ) . '">← ' . esc_html__( 'All streams', 'whd' ) . '</a></p>';
		echo '<h1 class="whd-title">' . esc_html( $exists ? __( 'Edit stream', 'whd' ) : __( 'New live stream', 'whd' ) ) . '</h1>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="whdla-form">';
		wp_nonce_field( 'whd_live_save' );
		echo '<input type="hidden" name="action" value="whd_live_save"><input type="hidden" name="id" value="' . (int) $id . '">';

		echo '<div class="whdla-cols"><div class="whdla-col">';

		echo '<section class="whdla-card"><h2>' . esc_html__( 'What it is', 'whd' ) . '</h2>';
		if ( $exists && WHD_Live::has_site( $stream ) ) {
			echo '<div class="whdla-field"><span>' . esc_html__( 'Its link', 'whd' ) . '</span>';
			self::link_line( $id );
			echo '<small>' . esc_html__( 'Yours to share now: until you go live it shows a waiting page.', 'whd' ) . '</small></div>';
		} elseif ( ! $exists ) {
			echo '<p class="whdla-sub">' . esc_html__( 'Saving gives this live its own link, to share before you start.', 'whd' ) . '</p>';
		}
		echo '<label class="whdla-field"><span>' . esc_html__( 'Title', 'whd' ) . '</span><input type="text" name="title" required maxlength="120" value="' . esc_attr( $exists ? get_the_title( $id ) : '' ) . '" placeholder="' . esc_attr__( 'Friday night new arrivals', 'whd' ) . '"></label>';
		echo '<label class="whdla-field"><span>' . esc_html__( 'What viewers should expect', 'whd' ) . '</span><textarea name="description" rows="3" placeholder="' . esc_attr__( 'Shown on the page, and sent to Facebook and YouTube as the description.', 'whd' ) . '">' . esc_textarea( $exists ? (string) get_post_field( 'post_excerpt', $id ) : '' ) . '</textarea></label>';
		echo '<label class="whdla-field whdla-field--short"><span>' . esc_html__( 'When (optional)', 'whd' ) . '</span><input type="datetime-local" name="starts_at" value="' . esc_attr( $when ) . '"><small>' . esc_html__( 'The page counts down to this. You still go live by hand.', 'whd' ) . '</small></label>';

		echo '<div class="whdla-field"><span>' . esc_html__( 'Cover picture', 'whd' ) . '</span><div class="whdla-poster" data-whdla-poster><input type="hidden" name="poster" value="' . (int) $poster . '">';
		$url = $poster ? wp_get_attachment_image_url( $poster, 'medium' ) : '';
		echo '<button type="button" class="whdla-poster__btn" ' . ( $url ? 'style="background-image:url(' . esc_url( $url ) . ')"' : '' ) . '><span>' . esc_html__( 'Choose', 'whd' ) . '</span></button></div><small>' . esc_html__( 'Shown on the page before you go live.', 'whd' ) . '</small></div>';
		echo '</section>';

		echo '<section class="whdla-card"><h2>' . esc_html__( 'Pieces you’ll show', 'whd' ) . '</h2><p class="whdla-sub">' . esc_html__( 'Viewers see none of these until you show them. You can add more from the studio while you’re live. Give each one a code, like DRESS1. The card in the video tells viewers to comment it. Only a comment with the code gets the link; anything else is just a comment. With no code, its number is the code.', 'whd' ) . '</p>';
		if ( ! function_exists( 'wc_get_product' ) ) {
			echo '<p class="whd-muted">' . esc_html__( 'WooCommerce is not active.', 'whd' ) . '</p>';
		}
		echo '<div class="whdla-picker" data-whdla-picker><input type="hidden" name="pieces_json" value="' . esc_attr( (string) wp_json_encode( $rows ) ) . '">';
		echo '<div class="whdla-finder"><div class="whdla-finder__bar">';
		echo '<input type="search" class="whdla-finder__search" placeholder="' . esc_attr__( 'Search by name or SKU…', 'whd' ) . '" aria-label="' . esc_attr__( 'Search your pieces', 'whd' ) . '" autocomplete="off">';
		if ( taxonomy_exists( 'product_cat' ) ) {
			echo '<span class="whdla-finder__cat">';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core's own escaped markup
			echo wp_dropdown_categories( [
				'taxonomy'        => 'product_cat',
				'hide_empty'      => 1,
				'hierarchical'    => 1,
				'orderby'         => 'name',
				'show_option_all' => __( 'All categories', 'whd' ),
				'name'            => 'whdla_cat', // read by the script, ignored on save
				'id'              => 'whdla-cat',
				'echo'            => 0,
			] );
			echo '</span>';
		}
		echo '</div><div class="whdla-finder__results" aria-live="polite"></div>';
		echo '<div class="whdla-finder__foot"><span class="whdla-finder__count"></span><button type="button" class="button whdla-finder__more" hidden>' . esc_html__( 'Show more', 'whd' ) . '</button></div></div>';
		echo '<h3 class="whdla-picker__chosen"></h3><ol class="whdla-picker__list"></ol></div>';
		echo '</section>';

		echo '</div><div class="whdla-col">';

		echo '<section class="whdla-card"><h2>' . esc_html__( 'Where it goes', 'whd' ) . '</h2>';
		echo '<div class="whdla-dests">';
		foreach ( WHD_Live::channels() as $key => $channel ) {
			$picked = in_array( $key, (array) $stream['destinations'], true );
			$ready  = (bool) $channel['ready'];
			echo '<label class="whdla-dest whdla-dest--' . esc_attr( $key ) . ( $ready ? '' : ' is-off' ) . '">';
			echo '<input type="checkbox" name="destinations[]" value="' . esc_attr( $key ) . '" ' . checked( $picked && $ready, true, false ) . disabled( $ready, false, false ) . '>';
			echo '<span class="whdla-dest__mark"></span><span class="whdla-dest__text"><strong>' . esc_html( $channel['label'] ) . '</strong>';
			echo '<small>' . esc_html( $ready ? $channel['how'] : $channel['note'] ) . '</small></span>';
			if ( ! $ready ) {
				echo '<a class="whdla-dest__link" href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Settings', 'whd' ) . '</a>';
			}
			echo '</label>';
		}
		echo '</div></section>';

		echo '<section class="whdla-card"><h2>' . esc_html__( 'Shape of the video', 'whd' ) . '</h2><div class="whdla-formats">';
		foreach ( [ 'landscape' => [ __( 'Landscape', 'whd' ), __( '16:9, for computers, Facebook and YouTube', 'whd' ) ], 'portrait' => [ __( 'Portrait', 'whd' ), __( '9:16, for phones and TikTok', 'whd' ) ] ] as $value => $words ) {
			echo '<label class="whdla-format whdla-format--' . esc_attr( $value ) . '"><input type="radio" name="format" value="' . esc_attr( $value ) . '" ' . checked( $stream['format'], $value, false ) . '><i></i><span><strong>' . esc_html( $words[0] ) . '</strong><small>' . esc_html( $words[1] ) . '</small></span></label>';
		}
		echo '</div></section>';

		echo '</div></div>';

		echo '<div class="whdla-actions">';
		echo '<button class="button button-primary button-hero" name="then" value="studio">' . esc_html__( 'Save and open the studio', 'whd' ) . '</button> ';
		echo '<button class="button button-hero" name="then" value="list">' . esc_html__( 'Save', 'whd' ) . '</button>';
		echo '</div></form></div>';
	}

	public static function handle_save() {
		self::guard( 'whd_live_save' );
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised field by field below
		$id    = absint( $_POST['id'] ?? 0 );
		$title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$desc  = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$when  = sanitize_text_field( wp_unslash( $_POST['starts_at'] ?? '' ) );
		$poster = absint( $_POST['poster'] ?? 0 );
		$pieces = json_decode( wp_unslash( (string) ( $_POST['pieces_json'] ?? '' ) ), true );
		$picked = array_map( 'sanitize_key', (array) wp_unslash( $_POST['destinations'] ?? [] ) );
		$format = 'portrait' === ( $_POST['format'] ?? '' ) ? 'portrait' : 'landscape';
		$then   = sanitize_key( wp_unslash( $_POST['then'] ?? 'list' ) );
		// phpcs:enable

		$post = [
			'post_type'    => WHD_Live::CPT,
			'post_status'  => 'publish',
			'post_title'   => '' !== $title ? $title : __( 'Live stream', 'whd' ),
			'post_excerpt' => $desc,
		];
		if ( $id && WHD_Live::exists( $id ) ) {
			$post['ID'] = $id;
			wp_update_post( $post );
		} else {
			$post['post_name'] = WHD_Live::new_slug( $post['post_title'] );
			$id                = (int) wp_insert_post( $post );
		}
		if ( ! $id ) {
			wp_die( esc_html__( 'The stream could not be saved.', 'whd' ) );
		}

		if ( $poster && wp_attachment_is_image( $poster ) ) {
			set_post_thumbnail( $id, $poster );
		} else {
			delete_post_thumbnail( $id );
		}

		// A destination that is not ready cannot be picked. A stream has to go somewhere.
		$channels = WHD_Live::channels();
		$dests    = array_values( array_filter( $picked, static fn( $k ) => ! empty( $channels[ $k ]['ready'] ) ) );
		if ( ! $dests && ! empty( $channels['site']['ready'] ) ) {
			$dests = [ 'site' ];
		}

		$starts = 0;
		if ( '' !== $when ) {
			$gmt    = get_gmt_from_date( str_replace( 'T', ' ', $when ) . ':00' );
			$starts = (int) strtotime( $gmt . ' UTC' );
		}

		WHD_Live::patch( $id, [
			'format'       => $format,
			'destinations' => $dests,
			'starts_at'    => $starts,
		] );
		WHD_Live::set_pieces( $id, is_array( $pieces ) ? $pieces : [] );

		wp_safe_redirect( 'studio' === $then ? self::studio_url( $id ) : self::url() );
		exit;
	}

	public static function handle_delete() {
		$id = absint( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next line
		self::guard( 'whd_live_delete_' . $id );
		if ( WHD_Live::exists( $id ) ) {
			$room = (array) WHD_Live::get( $id )['room'];
			if ( ! empty( $room['name'] ) ) {
				WHD_Live_Channels::daily( 'DELETE', '/rooms/' . rawurlencode( (string) $room['name'] ) );
			}
			wp_delete_post( $id, true );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function handle_leads() {
		$id = absint( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next line
		self::guard( 'whd_live_leads_' . $id );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=live-leads-' . $id . '-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'email', 'name', 'comments', 'pieces_asked_about' ] );
		foreach ( WHD_Live_Comments::leads( $id, 5000 ) as $lead ) {
			// A name is typed by a stranger and this opens in a spreadsheet: no formulas.
			$name = preg_match( '/^[=+\-@]/', $lead['name'] ) ? "'" . $lead['name'] : $lead['name'];
			fputcsv( $out, [ $lead['email'], $name, $lead['comments'], implode( ' | ', $lead['pieces'] ) ] );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/* ─────────────────────────── the studio ─────────────────────────── */

	public static function studio() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which stream to open
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( ! WHD_Live::exists( $id ) ) {
			echo '<div class="wrap"><p>' . esc_html__( 'That stream does not exist.', 'whd' ) . ' <a href="' . esc_url( self::url() ) . '">' . esc_html__( 'All streams', 'whd' ) . '</a></p></div>';
			return;
		}
		require WHD_DIR . 'templates/live-studio.php';
	}
}
