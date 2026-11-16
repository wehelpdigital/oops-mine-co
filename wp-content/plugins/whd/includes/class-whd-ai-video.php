<?php
/**
 * Video Builder: product photographs in, a short product video out — made by Google's Gemini Omni Flash.
 *
 * One screen under AI Writer, the video twin of Image Builder. The photographs are read the way the
 * product screen reads them (WHD_AI_Studio::look, then ::inspect: the details cut out of the originals at
 * full resolution and counted again), and that checked spec, the shop's choices and its own words become
 * the brief the video model works from, with the photographs attached as its references.
 *
 * Omni is reached through the Interactions API (POST /v1beta/interactions, model gemini-omni-1.2-flash):
 * 3–15 seconds per turn, 9:16 or 16:9, 360p to 4K, native audio. A longer video (up to 30 s) is the first
 * turn and then one or two extensions chained with previous_interaction_id. Veo, which this API used to
 * offer, was shut down there on 22 October 2026.
 *
 * No request waits on the model. Each turn is created with `background: true` and the screen asks after it
 * every few seconds (status → GET /interactions/{id}, throttled). Where Google refuses background mode for
 * the model, the whole run falls back to a job of our own that runs after the response has gone — the way
 * the studio's slow steps already do on the live host.
 *
 * The reply carries the video as base64 inside JSON, tens of megabytes of it at 1080p. It is streamed to a
 * file and the video is lifted out of it without ever holding the JSON in memory (read_reply), so a shared
 * host's memory limit never meets it. Every video made is saved to the media library as soon as it exists;
 * what happens to it next — a GIF, a boomerang, text and music — happens in the browser (video-engine.js).
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_AI_Video {

	const PAGE = 'whd-ai-video-builder';

	const API      = 'https://generativelanguage.googleapis.com/v1beta/';
	const REVISION = '2026-05-20';

	/** On a video made here: that it was, the brief it was made from, the choices, the interaction. */
	const MADE        = '_whd_vb';
	const PROMPT      = '_whd_vb_prompt';
	const CHOICES     = '_whd_vb_choices';
	const INTERACTION = '_whd_vb_interaction';
	/** What a file is: raw (straight from the model), gif, boomerang, edit (from the Video Editor). */
	const KIND = '_whd_vb_kind';

	/** Seconds: one turn of the model, the longest a run makes by extending, an extension's limits. */
	const TURN_MAX   = 15;
	const TOTAL_MAX  = 30;
	const EXTEND_MIN = 3;
	const EXTEND_MAX = 10;
	const SECONDS_MIN = 3;

	/** Most videos one run makes, and most photographs sent as references (the model takes six). */
	const VARIATIONS = 3;
	const PHOTO_EDGE = 1536;

	/** Seconds between two real questions to Google about one turn, however often the screen asks. */
	const POLL_GAP = 8;

	/** How long a run is remembered, and how long a turn may take before it is given up on. */
	const RUN_TTL   = DAY_IN_SECONDS;
	const TURN_WAIT = 20 * MINUTE_IN_SECONDS;

	/** JSON strings longer than this are video data, not something to decode with the rest of the reply. */
	const BIG_STRING = 65536;

	/** How many earlier videos the screen shows when it opens. */
	const RECENT = 12;

	/** Whether Google took background mode for the model, remembered so a refusal is not asked again. */
	const BACKGROUND_OPTION = 'whd_vb_background';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 14 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'rest' ] );
	}

	/* ─────────────────────────── settings ─────────────────────────── */

	/** Models that make videos. The first is the default. */
	public static function models(): array {
		return [
			'gemini-omni-1.2-flash' => __( 'Gemini Omni 1.2 Flash — current, the usual choice', 'whd' ),
			'gemini-omni-1.1-flash' => __( 'Gemini Omni 1.1 Flash — older; Google sends it to 1.2 from 3 December 2026', 'whd' ),
		];
	}

	public static function model(): string {
		$chosen = (string) ( WHD_AI::get()['video_model'] ?? '' );
		$models = self::models();
		return isset( $models[ $chosen ] ) ? $chosen : (string) array_key_first( $models );
	}

	/**
	 * Resolutions, and what a second of each costs on the paid tier — Google's own figures (5,792 output
	 * tokens a second at 720p, $12 per million; 1080p and 4K are upsampled). Shown before anything is spent.
	 */
	public static function resolutions(): array {
		return [
			'360p'  => [ 'label' => __( '360p — a quick, cheap draft', 'whd' ), 'rate' => 0.023 ],
			'720p'  => [ 'label' => __( '720p — the model’s own size; sharp on a phone', 'whd' ), 'rate' => 0.07 ],
			'1080p' => [ 'label' => __( '1080p — full HD, best for Reels', 'whd' ), 'rate' => 0.104 ],
			'4k'    => [ 'label' => __( '4K — biggest and slowest', 'whd' ), 'rate' => 0.209 ],
		];
	}

	public static function resolution( string $want = '' ): string {
		$list = self::resolutions();
		if ( isset( $list[ $want ] ) ) {
			return $want;
		}
		$set = (string) ( WHD_AI::get()['video_resolution'] ?? '' );
		return isset( $list[ $set ] ) ? $set : '720p';
	}

	public static function default_seconds(): int {
		return self::seconds( (int) ( WHD_AI::get()['video_seconds'] ?? 8 ) );
	}

	public static function seconds( int $s ): int {
		return max( self::SECONDS_MIN, min( self::TOTAL_MAX, $s ?: 8 ) );
	}

	/** The look every video is told to have, unless the shop wrote its own in AI content → Settings. */
	public static function house_look(): string {
		$set = trim( (string) ( WHD_AI::get()['video_brief'] ?? '' ) );
		return '' !== $set ? $set : 'A real, premium online-boutique fashion film: true-to-life colours, flattering soft light, realistic skin, hair and fabric, smooth natural movement — nothing glossy, plastic or artificial.';
	}

	/** Videos go through Google whichever provider writes the words; reading the photographs needs that provider. */
	public static function ready(): bool {
		return '' !== trim( (string) ( WHD_AI::get()['google_key'] ?? '' ) );
	}

	/** Called by WHD_AI::sanitize() for the keys this module owns. */
	public static function sanitize_settings( array $input, array $out ): array {
		$model              = sanitize_text_field( $input['video_model'] ?? '' );
		$out['video_model'] = isset( self::models()[ $model ] ) ? $model : '';
		$res                = sanitize_key( $input['video_resolution'] ?? '' );
		$out['video_resolution'] = isset( self::resolutions()[ $res ] ) ? $res : '720p';
		$out['video_seconds']    = self::seconds( (int) ( $input['video_seconds'] ?? 8 ) );
		$sound                   = sanitize_key( $input['video_sound'] ?? '' );
		$out['video_sound']      = isset( self::options()['sounds'][ $sound ] ) ? $sound : 'music_upbeat';
		$out['video_brief']      = sanitize_textarea_field( $input['video_brief'] ?? '' );
		$bg                      = sanitize_key( $input['video_background'] ?? 'auto' );
		$out['video_background'] = in_array( $bg, [ 'auto', 'off' ], true ) ? $bg : 'auto';
		if ( 'auto' === $out['video_background'] ) {
			delete_option( self::BACKGROUND_OPTION ); // saved again: ask Google afresh
		}
		return $out;
	}

	/* ─────────────────────────── the choices ─────────────────────────── */

	/**
	 * Everything the form offers, with the words each choice puts in the brief. The browser sends keys
	 * only; the words are always these, so nothing typed into a select can reach the model as an order.
	 */
	public static function options(): array {
		return [
			'formats'  => [
				'reel'      => [ 'label' => __( 'Reel · TikTok · Story · Short — 9:16', 'whd' ), 'w' => 1080, 'h' => 1920, 'aspect' => '9:16', 'orient' => [ 'portrait' ] ],
				'feed'      => [ 'label' => __( 'Feed post — 4:5', 'whd' ), 'w' => 1080, 'h' => 1350, 'aspect' => '9:16', 'orient' => [ 'portrait' ] ],
				'square'    => [ 'label' => __( 'Square — 1:1', 'whd' ), 'w' => 1080, 'h' => 1080, 'aspect' => '', 'orient' => [ 'portrait', 'landscape' ] ],
				'landscape' => [ 'label' => __( 'Landscape — 16:9', 'whd' ), 'w' => 1920, 'h' => 1080, 'aspect' => '16:9', 'orient' => [ 'landscape' ] ],
				'banner'    => [ 'label' => __( 'Website banner — 21:9', 'whd' ), 'w' => 2520, 'h' => 1080, 'aspect' => '16:9', 'orient' => [ 'landscape' ] ],
			],
			'shows'    => [
				'model'    => [ 'label' => __( 'Worn by a model', 'whd' ), 'say' => '' ],
				'floating' => [ 'label' => __( 'Product only — floating (ghost mannequin)', 'whd' ), 'say' => 'No person in the video. The piece is shown on its own, as if worn by an invisible body — natural shape and volume, sleeves filled out, the inside of the back neckline visible — floating and moving gently. No mannequin, stand or hanger visible.' ],
				'flatlay'  => [ 'label' => __( 'Product only — flat lay', 'whd' ), 'say' => 'No person in the video. The piece is laid flat and neatly styled, seen from above, with a few quiet props that never cover it.' ],
				'hanger'   => [ 'label' => __( 'Product only — on a hanger', 'whd' ), 'say' => 'No person in the video. The piece hangs on a simple wooden hanger on a minimal rail, moving gently.' ],
				'detail'   => [ 'label' => __( 'Close-ups of the details', 'whd' ), 'say' => 'Macro close-ups only: the fabric texture, the fastenings, the stitching and the trims, each sharp and filling the frame in turn. No faces.' ],
				'hands'    => [ 'label' => __( 'Hands only — held and touched', 'whd' ), 'say' => 'Only hands are seen: they hold, turn and touch the piece so its texture and details show. No faces.' ],
			],
			'styles'   => [
				'runway'     => [ 'label' => __( 'Runway walk', 'whd' ), 'say' => 'She walks toward the camera with a confident, natural stride, the piece moving with her; the camera tracks back smoothly to keep her full length in frame.' ],
				'twirl'      => [ 'label' => __( 'Turn and twirl', 'whd' ), 'say' => 'She turns slowly on the spot so the front, the side and the back of the piece are each seen clearly, and ends facing the camera.' ],
				'flow'       => [ 'label' => __( 'Fabric in motion', 'whd' ), 'say' => 'A gentle breeze moves the fabric; slow motion shows how it drapes, swings and falls.' ],
				'orbit'      => [ 'label' => __( '360° showcase', 'whd' ), 'say' => 'The camera circles the piece slowly in one smooth orbit, so every side of it is seen.' ],
				'details'    => [ 'label' => __( 'Detail tour', 'whd' ), 'say' => 'A slow glide across the details — fastenings, stitching, trims and the texture of the fabric — each one sharp and filling the frame in turn, then a step back to show the whole piece.' ],
				'street'     => [ 'label' => __( 'Street style', 'whd' ), 'say' => 'A candid street-style moment: she walks past shop windows and glances back, the camera following handheld.' ],
				'grwm'       => [ 'label' => __( 'Get ready with me (UGC)', 'whd' ), 'say' => 'A casual moment filmed on a phone in front of a mirror at home: she adjusts the piece, turns to check it and smiles. An authentic, user-made feel, not a studio shoot.' ],
				'catalog'    => [ 'label' => __( 'Studio catalog', 'whd' ), 'say' => 'A clean catalog shoot: she moves between two or three relaxed, natural poses against a seamless backdrop while the camera holds steady.' ],
				'unbox'      => [ 'label' => __( 'Unboxing reveal', 'whd' ), 'say' => 'Hands lift the piece out of tissue paper in a gift box, hold it up to the camera, then lay it out to show it whole.' ],
				'transition' => [ 'label' => __( 'Outfit reveal', 'whd' ), 'say' => 'She steps out of frame in a plain everyday outfit and steps back in wearing the piece — a playful, rhythmic reveal on the beat.' ],
				'golden'     => [ 'label' => __( 'Golden hour', 'whd' ), 'say' => 'A dreamy lifestyle moment in warm late-afternoon sun, the light catching the fabric as she moves.' ],
				'montage'    => [ 'label' => __( 'Quick cuts', 'whd' ), 'say' => 'A fast montage of short shots — the full look, a detail, a turn, a final pose — cut on the beat.', 'cuts' => true ],
				'custom'     => [ 'label' => __( 'Just my words', 'whd' ), 'say' => '' ],
			],
			'cameras'  => [
				'auto'     => [ 'label' => __( 'Best for the style', 'whd' ), 'say' => '' ],
				'static'   => [ 'label' => __( 'Still, on a tripod', 'whd' ), 'say' => 'The camera stays perfectly still on a tripod.' ],
				'push'     => [ 'label' => __( 'Slow push in', 'whd' ), 'say' => 'The camera pushes in slowly.' ],
				'pull'     => [ 'label' => __( 'Pull back to reveal', 'whd' ), 'say' => 'The camera starts close on a detail and slowly pulls back to reveal the whole look.' ],
				'orbit'    => [ 'label' => __( 'Orbit around', 'whd' ), 'say' => 'The camera orbits slowly around the subject.' ],
				'tracking' => [ 'label' => __( 'Tracking alongside', 'whd' ), 'say' => 'The camera tracks alongside, keeping pace.' ],
				'pan'      => [ 'label' => __( 'Slow pan', 'whd' ), 'say' => 'The camera pans slowly across the scene.' ],
				'handheld' => [ 'label' => __( 'Handheld', 'whd' ), 'say' => 'Handheld camera, natural and slightly moving.' ],
				'topdown'  => [ 'label' => __( 'From above', 'whd' ), 'say' => 'Filmed from directly above.' ],
			],
			'places'   => [
				'auto'     => [ 'label' => __( 'Best for the style', 'whd' ), 'say' => '' ],
				'studio'   => [ 'label' => __( 'Studio backdrop', 'whd' ), 'say' => 'A seamless warm off-white studio backdrop with soft shadows.' ],
				'boutique' => [ 'label' => __( 'Boutique', 'whd' ), 'say' => 'Inside a bright, minimal boutique in soft neutral tones.' ],
				'street'   => [ 'label' => __( 'City street', 'whd' ), 'say' => 'A stylish city street with softly blurred shopfronts.' ],
				'cafe'     => [ 'label' => __( 'Café', 'whd' ), 'say' => 'A sunny café terrace.' ],
				'garden'   => [ 'label' => __( 'Garden', 'whd' ), 'say' => 'A green garden path with soft foliage.' ],
				'beach'    => [ 'label' => __( 'Beach', 'whd' ), 'say' => 'A calm beach with soft sand and gentle waves.' ],
				'home'     => [ 'label' => __( 'At home, by a mirror', 'whd' ), 'say' => 'A bright, tidy bedroom with a full-length mirror.' ],
				'rooftop'  => [ 'label' => __( 'Rooftop', 'whd' ), 'say' => 'A city rooftop with the skyline behind.' ],
				'custom'   => [ 'label' => __( 'Somewhere else (describe it)', 'whd' ), 'say' => '' ],
			],
			'moods'    => [
				'auto'   => [ 'label' => __( 'Best for the style', 'whd' ), 'say' => '' ],
				'airy'   => [ 'label' => __( 'Bright and airy', 'whd' ), 'say' => 'Bright, airy and natural, in soft daylight.' ],
				'soft'   => [ 'label' => __( 'Soft studio light', 'whd' ), 'say' => 'Soft, even studio light.' ],
				'golden' => [ 'label' => __( 'Golden hour', 'whd' ), 'say' => 'Warm golden-hour sunlight with a gentle lens flare.' ],
				'moody'  => [ 'label' => __( 'Moody and editorial', 'whd' ), 'say' => 'Moody and editorial: deep shadows and rich contrast.' ],
				'neon'   => [ 'label' => __( 'Neon night', 'whd' ), 'say' => 'Night, with neon reflections and a soft city glow.' ],
			],
			'paces'    => [
				'calm'      => [ 'label' => __( 'Calm', 'whd' ), 'say' => 'Unhurried and elegant.' ],
				'medium'    => [ 'label' => __( 'Natural', 'whd' ), 'say' => 'A natural pace.' ],
				'energetic' => [ 'label' => __( 'Energetic', 'whd' ), 'say' => 'Lively and upbeat, with quick movements.' ],
			],
			'sounds'   => [
				'music_upbeat' => [ 'label' => __( 'Upbeat music', 'whd' ), 'say' => 'Sound: upbeat, modern fashion music. No dialogue, no voice-over.' ],
				'music_calm'   => [ 'label' => __( 'Calm music', 'whd' ), 'say' => 'Sound: calm, elegant background music. No dialogue, no voice-over.' ],
				'ambient'      => [ 'label' => __( 'The sounds of the scene only', 'whd' ), 'say' => 'Sound: only the natural ambient sound of the scene. No music, no dialogue.' ],
				'silent'       => [ 'label' => __( 'Quiet — I’ll add music in the editor', 'whd' ), 'say' => 'Sound: near silence. No music, no dialogue, no sound effects.' ],
			],
			'purposes' => [
				'reel'    => [ 'label' => __( 'Instagram or Facebook Reel', 'whd' ), 'say' => 'It is for an Instagram or Facebook Reel: the first second must catch the eye, and the piece stays in the middle of the frame, clear of the top and the bottom where captions sit.' ],
				'tiktok'  => [ 'label' => __( 'TikTok', 'whd' ), 'say' => 'It is for TikTok: an eye-catching first second, the piece in the middle of the frame and clear of the right edge and the bottom, where the app’s buttons sit.' ],
				'story'   => [ 'label' => __( 'Story', 'whd' ), 'say' => 'It is for an Instagram or Facebook Story: the piece in the middle of the frame, clear of the top and bottom edges.' ],
				'short'   => [ 'label' => __( 'YouTube Short', 'whd' ), 'say' => 'It is for a YouTube Short: a strong first second, the piece in the middle of the frame.' ],
				'product' => [ 'label' => __( 'Product page', 'whd' ), 'say' => 'It is for the product’s own page in the shop: clean and honest, the piece the hero of every frame.' ],
				'ad'      => [ 'label' => __( 'Paid social ad', 'whd' ), 'say' => 'It is for a paid social ad: a strong hook in the first second and the piece clearly seen throughout.' ],
				'banner'  => [ 'label' => __( 'Website banner', 'whd' ), 'say' => 'It is for a website banner: wide and calm, with open space on one side where words will go.' ],
				'feed'    => [ 'label' => __( 'Feed post', 'whd' ), 'say' => 'It is for a feed post: the piece clearly seen, framed for a crop to the middle of the picture.' ],
			],
			'outputs'  => [
				'video'     => [ 'label' => __( 'Video', 'whd' ), 'say' => '' ],
				'gif'       => [ 'label' => __( 'GIF', 'whd' ), 'say' => 'It will be shown as a short looping GIF: one simple, clear motion, no cuts, nothing fast.' ],
				'boomerang' => [ 'label' => __( 'Boomerang', 'whd' ), 'say' => 'It will be played forward and then backward as a boomerang: one short, smooth motion with a steady camera, and no cuts.' ],
				'loop'      => [ 'label' => __( 'Seamless loop', 'whd' ), 'say' => 'It must loop seamlessly: it ends on exactly the frame it began with, with no cut, so it can repeat forever.' ],
			],
		];
	}

	/** What the browser sent, held to the lists above and the limits of the model. */
	public static function choices( array $given ): array {
		$o    = self::options();
		$pick = static function ( string $list, $value, string $fallback ) use ( $o ) {
			$value = sanitize_key( (string) $value );
			return isset( $o[ $list ][ $value ] ) ? $value : $fallback;
		};
		$orient  = 'landscape' === ( $given['orient'] ?? '' ) ? 'landscape' : 'portrait';
		$format  = $pick( 'formats', $given['format'] ?? '', 'portrait' === $orient ? 'reel' : 'landscape' );
		if ( ! in_array( $orient, $o['formats'][ $format ]['orient'], true ) ) {
			$format = 'portrait' === $orient ? 'reel' : 'landscape';
		}
		$text = static function ( $value, int $max ) {
			return mb_substr( trim( sanitize_textarea_field( (string) $value ) ), 0, $max );
		};
		return [
			'orient'     => $orient,
			'format'     => $format,
			'show'       => $pick( 'shows', $given['show'] ?? '', 'model' ),
			'style'      => $pick( 'styles', $given['style'] ?? '', 'twirl' ),
			'camera'     => $pick( 'cameras', $given['camera'] ?? '', 'auto' ),
			'place'      => $pick( 'places', $given['place'] ?? '', 'auto' ),
			'place_text' => $text( $given['place_text'] ?? '', 200 ),
			'mood'       => $pick( 'moods', $given['mood'] ?? '', 'auto' ),
			'pace'       => $pick( 'paces', $given['pace'] ?? '', 'medium' ),
			'sound'      => $pick( 'sounds', $given['sound'] ?? '', (string) ( WHD_AI::get()['video_sound'] ?? 'music_upbeat' ) ),
			'purpose'    => $pick( 'purposes', $given['purpose'] ?? '', 'reel' ),
			'output'     => $pick( 'outputs', $given['output'] ?? '', 'video' ),
			'seconds'    => self::seconds( (int) ( $given['seconds'] ?? self::default_seconds() ) ),
			'resolution' => self::resolution( (string) ( $given['resolution'] ?? '' ) ),
			'query'      => $text( $given['query'] ?? '', 1200 ),
			'details'    => $text( $given['details'] ?? '', 1200 ),
			'words'      => $text( $given['words'] ?? '', 120 ),
			'variations' => max( 1, min( self::VARIATIONS, (int) ( $given['variations'] ?? 1 ) ) ),
		];
	}

	/** The aspect ratio the model is asked for: it only makes 9:16 and 16:9, and a square is cut from either. */
	private static function aspect( array $c ): string {
		$format = self::options()['formats'][ $c['format'] ];
		if ( '' !== $format['aspect'] ) {
			return $format['aspect'];
		}
		return 'landscape' === $c['orient'] ? '16:9' : '9:16';
	}

	/**
	 * How a run's length is made: one turn up to 15 s, then extensions of 3–10 s each up to 30 s.
	 *
	 * @return int[] Seconds per turn, the first being the generation itself.
	 */
	public static function turns( int $seconds ): array {
		$seconds = self::seconds( $seconds );
		if ( $seconds <= self::TURN_MAX ) {
			return [ $seconds ];
		}
		// Even halves read better than 15 + a short tail: 22 → 12 + 10, 30 → 10 + 10 + 10.
		$rest  = $seconds;
		$count = 1 + (int) ceil( max( 0, $seconds - self::TURN_MAX ) / self::EXTEND_MAX );
		$first = max( self::EXTEND_MIN, min( self::TURN_MAX, $seconds - ( $count - 1 ) * self::EXTEND_MAX ) );
		$turns = [ $first ];
		$rest -= $first;
		while ( $rest > 0 ) {
			$next    = max( self::EXTEND_MIN, min( self::EXTEND_MAX, $rest ) );
			$turns[] = $next;
			$rest   -= $next;
		}
		return $turns;
	}

	/** What a run will cost on Google's paid tier, in dollars — an estimate, from Google's per-second figures. */
	public static function estimate( array $c ): float {
		$rate = self::resolutions()[ $c['resolution'] ]['rate'];
		return round( $rate * $c['seconds'] * $c['variations'], 2 );
	}

	/* ─────────────────────────── the brief ─────────────────────────── */

	/**
	 * The words the model works from: the checked spec of the piece, then the shot, then the rules.
	 * Built here rather than by a model, so it says exactly what was counted and exactly what was chosen.
	 */
	public static function prompt( array $facts, array $c, int $variant = 0 ): string {
		$o      = self::options();
		$style  = $o['styles'][ $c['style'] ];
		$show   = $c['show'];
		$person = 'model' === $show;
		$cuts   = ! empty( $style['cuts'] );
		$out    = [];

		$what = trim( (string) ( $facts['garment'] ?? '' ) ) ?: 'fashion piece';
		$out[] = sprintf(
			'A %d-second %s product video of a %s we sell in our online boutique, made from the reference photographs attached. %s',
			(int) self::turns( $c['seconds'] )[0],
			'16:9' === self::aspect( $c ) ? 'landscape (16:9)' : 'vertical (9:16)',
			$what,
			$o['purposes'][ $c['purpose'] ]['say']
		);

		$spec = class_exists( 'WHD_AI_Studio' ) ? WHD_AI_Studio::spec( $facts ) : '';
		$out[] = "THE PRODUCT — it must look exactly like the reference photographs in every frame\n"
			. ( '' !== $spec ? $spec . "\n" : '' )
			. "- The same colours, tones and pattern at the same scale as the photographs, the same length, proportions, neckline, sleeves and hem. The names above are approximate; the photographs are exact.\n"
			. '- It never changes between frames: no colour shift, no extra or missing buttons, pockets or trims, nothing added that the photographs do not show.';

		if ( '' !== $c['query'] ) {
			$out[] = "WHAT HAPPENS — asked for by the shop, and it wins over anything else here about the shot:\n" . $c['query'];
		}

		$shot = [];
		if ( '' !== $style['say'] ) {
			$shot[] = $style['say'];
		}
		if ( ! $person ) {
			$shot[] = $o['shows'][ $show ]['say'];
		}
		if ( '' !== $o['cameras'][ $c['camera'] ]['say'] ) {
			$shot[] = $o['cameras'][ $c['camera'] ]['say'];
		}
		$place = 'custom' === $c['place'] ? $c['place_text'] : $o['places'][ $c['place'] ]['say'];
		if ( '' !== $place ) {
			$shot[] = 'Where: ' . $place;
		}
		if ( '' !== $o['moods'][ $c['mood'] ]['say'] ) {
			$shot[] = 'Light and mood: ' . $o['moods'][ $c['mood'] ]['say'];
		}
		$shot[] = 'Pace: ' . $o['paces'][ $c['pace'] ]['say'];
		if ( $cuts ) {
			$shot[] = self::beats( (int) self::turns( $c['seconds'] )[0] );
		} else {
			$shot[] = 'In a single continuous shot, with no scene cuts.';
		}
		if ( '' !== $o['outputs'][ $c['output'] ]['say'] ) {
			$shot[] = $o['outputs'][ $c['output'] ]['say'];
		}
		$out[] = "THE SHOT\n" . implode( "\n", $shot );

		if ( $person && class_exists( 'WHD_AI_Images' ) ) {
			$people = WHD_AI_Images::people();
			$out[]  = "WHO\n" . $people[ ( (int) wp_rand( 0, 999 ) + $variant ) % count( $people ) ]
				. "\nShe wears the product from the photographs: the same piece in every detail, with the rest of her outfit plain and quiet so nothing covers it.";
		}

		$out[] = $o['sounds'][ $c['sound'] ]['say'];
		$out[] = '' !== $c['words']
			? 'Words on screen: show exactly "' . $c['words'] . '" in clean, elegant lettering, spelled exactly like that, and no other text.'
			: 'No captions, titles, logos or watermarks on screen; any lettering that is part of the product itself stays exactly as the photographs show it.';
		if ( '' !== $c['details'] ) {
			$out[] = "ALSO FROM THE SHOP — follow it:\n" . $c['details'];
		}

		$rules = [
			'consider micro-detail, expression and timing: a rich, natural, believable scene',
			'faces, hands, seams and fabric stay intact — nothing warped, melted or doubled',
			'the product is never covered by hair, arms, props or text for long',
		];
		if ( $person ) {
			$rules[] = 'a different person from anyone in the photographs: a different face and different hair';
			$rules[] = WHD_AI_Images::age_rule();
		}
		$out[] = 'THE LOOK' . "\n" . self::house_look() . "\n- " . implode( "\n- ", $rules );

		return implode( "\n\n", $out );
	}

	/** Beats for the quick-cuts style, in Omni's own timecode syntax. */
	private static function beats( int $seconds ): string {
		$shots = [ 'the full look, head to toe', 'a close-up of the best detail', 'a turn that shows the back', 'a final confident pose' ];
		$count = $seconds >= 8 ? 4 : ( $seconds >= 5 ? 3 : 2 );
		$step  = $seconds / $count;
		$lines = [ 'Cut on the beat, one shot per line:' ];
		for ( $i = 0; $i < $count; $i++ ) {
			$lines[] = sprintf( '[%s-%ss] %s', self::num( $i * $step ), self::num( ( $i + 1 ) * $step ), $shots[ $i ] );
		}
		return implode( "\n", $lines );
	}

	private static function num( float $n ): string {
		return rtrim( rtrim( number_format( $n, 1, '.', '' ), '0' ), '.' );
	}

	/** The words for each extension after the first turn. */
	private static function extend_prompt( array $facts, array $c, int $turn, int $of ): string {
		$what = trim( (string) ( $facts['garment'] ?? '' ) ) ?: 'piece';
		$tone = trim( (string) ( $facts['colour'] ?? '' ) );
		$last = $turn === $of - 1;
		return 'Continue the scene seamlessly. '
			. ( '' !== $c['query'] ? 'It goes on as asked: ' . $c['query'] . ' ' : '' )
			. 'The same ' . ( 'model' === $c['show'] ? 'person, ' : '' ) . 'setting, light and sound. The ' . $what . ( '' !== $tone ? ' (' . $tone . ')' : '' )
			. ' stays exactly as before — the same colour, pattern, fastenings and trims in every frame. '
			. ( empty( self::options()['styles'][ $c['style'] ]['cuts'] ) ? 'No scene cuts. ' : 'Keep cutting on the beat. ' )
			. ( $last ? 'End on a calm, confident final frame with the whole piece in view.' : '' );
	}

	/* ─────────────────────────── where it lives ─────────────────────────── */

	/** Under AI Writer, after Image Builder. */
	public static function menu() {
		if ( ! class_exists( 'WHD_AI_Writer' ) || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		add_submenu_page( WHD_AI_Writer::PAGE_POST, __( 'Video Builder', 'whd' ), __( 'Video Builder', 'whd' ), 'upload_files', self::PAGE, [ __CLASS__, 'screen' ] );
	}

	public static function assets() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which screen this is
		if ( ! isset( $_GET['page'] ) || self::PAGE !== sanitize_key( wp_unslash( $_GET['page'] ) ) || ! class_exists( 'WHD_AI_Studio' ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'whd-ai-studio', WHD_URL . 'admin/ai-studio.css', [], WHD_VERSION );
		wp_enqueue_style( 'whd-image-builder', WHD_URL . 'admin/image-builder.css', [ 'whd-ai-studio' ], WHD_VERSION );
		wp_enqueue_style( 'whd-video-builder', WHD_URL . 'admin/video-builder.css', [ 'whd-image-builder' ], WHD_VERSION );
		wp_enqueue_script( 'whd-ai-kit', WHD_URL . 'admin/ai-kit.js', [], WHD_VERSION, true );
		// The Video Editor under the results, opened on whatever was just made.
		WHD_Video_Editor::enqueue( [], true );
		wp_enqueue_script( 'whd-video-builder', WHD_URL . 'admin/video-builder.js', [ 'whd-ai-kit', 'whd-video-engine', 'whd-video-editor', 'media-editor' ], WHD_VERSION, true );

		$o = self::options();
		wp_localize_script( 'whd-video-builder', 'WHD_VB', [
			'rest'      => [
				'root'  => esc_url_raw( rest_url( 'whd/v1/ai/video/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'jobs'  => esc_url_raw( rest_url( 'whd/v1/ai/studio/job' ) ),
				'last'  => esc_url_raw( rest_url( 'whd/v1/ai/studio/last' ) ),
				'media' => esc_url_raw( rest_url( 'wp/v2/media' ) ),
			],
			'ready'     => self::ready(),
			'reader'    => WHD_AI::ready(),
			'photos'    => WHD_AI_Studio::LOOK_MAX,
			'options'   => array_map( static function ( $list ) {
				return array_map( static function ( $item ) {
					unset( $item['say'] );
					return $item;
				}, $list );
			}, $o ),
			'rates'     => array_map( static fn( $r ) => $r['rate'], self::resolutions() ),
			'resLabels' => array_map( static fn( $r ) => $r['label'], self::resolutions() ),
			'defaults'  => [
				'resolution' => self::resolution(),
				'seconds'    => self::default_seconds(),
				'sound'      => (string) ( WHD_AI::get()['video_sound'] ?? 'music_upbeat' ),
			],
			'limits'    => [ 'turn' => self::TURN_MAX, 'total' => self::TOTAL_MAX, 'min' => self::SECONDS_MIN, 'variations' => self::VARIATIONS ],
			'recent'    => self::recent(),
			'model'     => self::model(),
			'i18n'      => array_merge( WHD_AI_Studio::strings(), self::strings() ),
		] );
	}

	private static function strings(): array {
		return [
			'uploading'     => __( 'Uploading…', 'whd' ),
			/* translators: 1: file name, 2: the reason */
			'uploadFailed'  => __( '“%1$s” could not be uploaded: %2$s', 'whd' ),
			/* translators: %s: file name */
			'notImage'      => __( '“%s” is not a JPG, PNG or WebP photo.', 'whd' ),
			/* translators: %d: the most photographs one run reads */
			'photoLimit'    => __( 'Up to %d photos — the first ones are kept.', 'whd' ),
			'library'       => __( 'Choose photos', 'whd' ),
			'useThese'      => __( 'Use these photos', 'whd' ),
			'removePhoto'   => __( 'Remove this photo', 'whd' ),
			'addMore'       => __( 'Add more', 'whd' ),
			/* translators: %d: photo number */
			'photoN'        => __( 'Photo %d', 'whd' ),
			/* translators: %d: photo number */
			'viewPhoto'     => __( 'View photo %d larger', 'whd' ),
			'portrait'      => __( 'Portrait', 'whd' ),
			'landscape'     => __( 'Landscape', 'whd' ),
			/* translators: %d: seconds */
			'secondsN'      => __( '%d s', 'whd' ),
			/* translators: %d: number of parts */
			'madeInParts'   => __( 'Longer than 15 seconds: made in %d parts, each one continuing the last.', 'whd' ),
			'oneTurn'       => __( 'Made in one go.', 'whd' ),
			/* translators: %s: dollars */
			'costAbout'     => __( 'About $%s on your Google bill', 'whd' ),
			/* translators: %d: number of videos */
			'generateN'     => [ __( 'Make %d video', 'whd' ), __( 'Make %d videos', 'whd' ) ],
			'needPhotos'    => __( 'Add at least one photo of the product to start.', 'whd' ),
			'waitUpload'    => __( 'Wait for the photos to finish uploading.', 'whd' ),
			'needReady'     => __( 'Making videos needs a Google AI key, and reading the photos needs a connected model — add them in WHD → AI content.', 'whd' ),
			'needPlace'     => __( 'Describe where it is filmed, or pick one of the places.', 'whd' ),
			'needQuery'     => __( 'With “Just my words”, say what should happen in the video.', 'whd' ),
			'stepLook'      => __( 'Reading your photos', 'whd' ),
			'hintLook'      => __( 'Every detail of the piece, counted one at a time.', 'whd' ),
			'stepZoom'      => __( 'Zooming in on the details', 'whd' ),
			'hintZoom'      => __( 'Close-ups cut from your photos at full size, checked again.', 'whd' ),
			'stepBrief'     => __( 'Writing the shot', 'whd' ),
			'stepReview'    => __( 'Check the brief', 'whd' ),
			'reviewNote'    => __( 'This is exactly what the video model will be told. Change anything you like, then film it.', 'whd' ),
			'filmIt'        => __( 'Film it', 'whd' ),
			'cancel'        => __( 'Cancel', 'whd' ),
			'stepFilm'      => __( 'Filming', 'whd' ),
			/* translators: 1: part, 2: of how many */
			'stepPart'      => __( 'Filming part %1$d of %2$d', 'whd' ),
			/* translators: 1: video number, 2: of how many */
			'stepVariant'   => __( 'Video %1$d of %2$d', 'whd' ),
			'hintFilm'      => __( 'Google is making the video. This usually takes one to three minutes — longer at 1080p and 4K.', 'whd' ),
			'stepSaving'    => __( 'Saving to your media library', 'whd' ),
			'stepGif'       => __( 'Making the GIF', 'whd' ),
			'stepBoomerang' => __( 'Making the boomerang', 'whd' ),
			'hintConvert'   => __( 'In your browser — keep this tab open.', 'whd' ),
			'genDone'       => __( 'Your video is ready — it is in your media library.', 'whd' ),
			'stop'          => __( 'Stop', 'whd' ),
			'stopped'       => __( 'Stopped', 'whd' ),
			'earlier'       => __( 'Made earlier', 'whd' ),
			'download'      => __( 'Download', 'whd' ),
			'openLibrary'   => __( 'Open in media library', 'whd' ),
			'edit'          => __( 'Edit in Video Editor', 'whd' ),
			'makeGif'       => __( 'Make a GIF', 'whd' ),
			'makeBoomerang' => __( 'Make a boomerang', 'whd' ),
			'delete'        => __( 'Delete', 'whd' ),
			'confirmDel'    => __( 'Delete this video from the media library? This cannot be undone.', 'whd' ),
			'notDeleted'    => __( 'This video could not be deleted here. Delete it from the media library instead.', 'whd' ),
			'showBrief'     => __( 'The brief it was made from', 'whd' ),
			'kindRaw'       => __( 'Video', 'whd' ),
			'kindGif'       => __( 'GIF', 'whd' ),
			'kindBoomerang' => __( 'Boomerang', 'whd' ),
			'kindEdit'      => __( 'Edited', 'whd' ),
			'noVideos'      => __( 'Videos you make appear here.', 'whd' ),
			'needBrowser'   => __( 'This browser cannot make GIFs or boomerangs (it has no WebCodecs). The video itself is saved — use Chrome or Edge for the rest.', 'whd' ),
			/* translators: %s: reason */
			'convertFailed' => __( 'The video is saved, but the conversion failed: %s', 'whd' ),
			'partsNote'     => __( 'Google sent the parts separately, so the editor has them in order — export once to save the full video.', 'whd' ),
		];
	}

	/** A video saved here, as a tile on the screen (and in the Video Editor's picker) wants it. */
	public static function tile( int $id ): ?array {
		$mime = (string) get_post_mime_type( $id );
		if ( ! $id || ( 0 !== strpos( $mime, 'video/' ) && 'image/gif' !== $mime ) ) {
			return null;
		}
		$meta   = wp_get_attachment_metadata( $id );
		$poster = (int) get_post_thumbnail_id( $id );
		return [
			'id'      => $id,
			'url'     => (string) wp_get_attachment_url( $id ),
			'mime'    => $mime,
			'poster'  => $poster ? (string) wp_get_attachment_image_url( $poster, 'medium' ) : '',
			'title'   => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'seconds' => (float) ( $meta['length'] ?? 0 ),
			'width'   => (int) ( $meta['width'] ?? 0 ),
			'height'  => (int) ( $meta['height'] ?? 0 ),
			'size'    => size_format( (int) ( $meta['filesize'] ?? @filesize( (string) get_attached_file( $id ) ) ) ), // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a missing file just has no size
			'kind'    => (string) get_post_meta( $id, self::KIND, true ) ?: 'raw',
			'prompt'  => (string) get_post_meta( $id, self::PROMPT, true ),
			'edit'    => class_exists( 'WHD_Video_Editor' ) ? WHD_Video_Editor::edit_url( [ $id ] ) : '',
			'library' => (string) get_edit_post_link( $id, 'raw' ),
			'mine'    => (int) get_post_field( 'post_author', $id ) === get_current_user_id(),
			'date'    => (string) get_the_date( '', $id ),
		];
	}

	private static function recent(): array {
		$ids = get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => self::RECENT,
			'post_mime_type' => [ 'video', 'image/gif' ], // not the covers, which carry the mark too
			'meta_key'       => self::MADE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a dozen rows, on one admin screen
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		] );
		return array_values( array_filter( array_map( [ __CLASS__, 'tile' ], array_map( 'intval', $ids ) ) ) );
	}

	/* ─────────────────────────── the screen ─────────────────────────── */

	public static function screen() {
		$ready = self::ready() && WHD_AI::ready();
		$icon  = static function ( string $paths, int $size = 20 ) {
			return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
		};
		?>
		<div class="wrap whd-wrap whd-ib whd-vb" id="whd-vb">
			<header class="whd-ib__head">
				<p class="whd-ib__eyebrow"><?php esc_html_e( 'AI Writer', 'whd' ); ?></p>
				<h1 class="whd-title"><?php esc_html_e( 'Video Builder', 'whd' ); ?></h1>
				<p class="whd-ib__lead"><?php esc_html_e( 'A short video of a product from the photos you already have — for a Reel, a Story, the product page or an ad. Your photos are read and the details zoomed in on first, so the piece in the video is the piece you sell. Every video is saved to your media library and opens in the Video Editor for text, stickers and music.', 'whd' ); ?></p>
			</header>
			<hr class="wp-header-end">

			<?php if ( ! $ready ) : ?>
				<div class="whd-note whd-note--warn">
					<?php esc_html_e( 'Making videos needs a Google AI key, and reading the photos needs a connected model.', 'whd' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=whd-ai' ) ); ?>"><?php esc_html_e( 'Add them in WHD → AI content.', 'whd' ); ?></a>
				</div>
			<?php endif; ?>

			<div class="whd-ib__layout">
				<div class="whd-ib__main">

					<section class="whd-ib__card" aria-labelledby="whd-vb-h-photos">
						<header class="whd-ib__card-head">
							<span class="whd-ib__num" aria-hidden="true">1</span>
							<div>
								<h2 class="whd-ib__h" id="whd-vb-h-photos"><?php esc_html_e( 'Product photos', 'whd' ); ?></h2>
								<p class="whd-ib__sub">
									<?php
									/* translators: %d: the most photographs one run reads */
									echo esc_html( sprintf( __( 'Upload or choose up to %d. A front, a back and close-ups of the details give the truest video — they are all sent to the video model as references.', 'whd' ), WHD_AI_Studio::LOOK_MAX ) );
									?>
								</p>
							</div>
						</header>
						<div class="whd-ib__drop" id="whd-vb-drop">
							<input type="file" id="whd-vb-file" class="screen-reader-text" accept="image/jpeg,image/png,image/webp" multiple tabindex="-1">
							<div class="whd-ib__drop-empty" id="whd-vb-drop-empty">
								<span class="whd-ib__drop-icon"><?php echo $icon( '<path d="M4 16.5V18a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-1.5"/><path d="M12 15V4"/><path d="m7.5 8.5 4.5-4.5 4.5 4.5"/>', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup ?></span>
								<p class="whd-ib__drop-title"><?php esc_html_e( 'Drag your product photos here', 'whd' ); ?></p>
								<p class="whd-ib__drop-or"><?php esc_html_e( 'or', 'whd' ); ?></p>
								<div class="whd-ib__drop-btns">
									<button type="button" class="whd-ib__btn whd-ib__btn--dark" data-upload><?php echo $icon( '<path d="M12 16V5"/><path d="m7.5 9.5 4.5-4.5 4.5 4.5"/><path d="M5 19h14"/>', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e( 'Upload from computer', 'whd' ); ?></span></button>
									<button type="button" class="whd-ib__btn" data-library><?php echo $icon( '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m20.5 16-4.5-4.5L7 19.5"/>', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e( 'Choose from media library', 'whd' ); ?></span></button>
								</div>
								<p class="whd-ib__drop-note"><?php esc_html_e( 'JPG, PNG or WebP', 'whd' ); ?></p>
							</div>
							<div class="whd-ib__thumbs" id="whd-vb-photos" hidden></div>
							<div class="whd-ib__drop-veil" aria-hidden="true"><?php esc_html_e( 'Drop to upload', 'whd' ); ?></div>
						</div>
						<p class="whd-ib__msg" id="whd-vb-photos-msg" role="status" aria-live="polite"></p>
					</section>

					<section class="whd-ib__card" aria-labelledby="whd-vb-h-idea">
						<header class="whd-ib__card-head">
							<span class="whd-ib__num" aria-hidden="true">2</span>
							<div>
								<h2 class="whd-ib__h" id="whd-vb-h-idea"><?php esc_html_e( 'What happens in it', 'whd' ); ?></h2>
								<p class="whd-ib__sub"><?php esc_html_e( 'Pick a style and say it in your own words if you like. Your words win over everything else.', 'whd' ); ?></p>
							</div>
						</header>
						<div class="whd-vb__styles" id="whd-vb-styles" role="radiogroup" aria-label="<?php esc_attr_e( 'Style', 'whd' ); ?>"></div>
						<div class="whd-vb__field">
							<label class="whd-vb__label" for="whd-vb-query"><?php esc_html_e( 'Your idea', 'whd' ); ?> <span class="whd-vb__opt"><?php esc_html_e( 'optional', 'whd' ); ?></span></label>
							<textarea id="whd-vb-query" class="whd-vb__text" rows="3" maxlength="1200" placeholder="<?php esc_attr_e( 'e.g. She steps out of a café, the coat swings open as she turns, and she smiles over her shoulder.', 'whd' ); ?>"></textarea>
						</div>
						<div class="whd-vb__grid">
							<div class="whd-vb__field"><label class="whd-vb__label" for="whd-vb-show"><?php esc_html_e( 'Who or what is in it', 'whd' ); ?></label><select id="whd-vb-show" class="whd-vb__select" data-list="shows"></select></div>
							<div class="whd-vb__field"><label class="whd-vb__label" for="whd-vb-camera"><?php esc_html_e( 'Camera', 'whd' ); ?></label><select id="whd-vb-camera" class="whd-vb__select" data-list="cameras"></select></div>
							<div class="whd-vb__field"><label class="whd-vb__label" for="whd-vb-place"><?php esc_html_e( 'Where', 'whd' ); ?></label><select id="whd-vb-place" class="whd-vb__select" data-list="places"></select></div>
							<div class="whd-vb__field"><label class="whd-vb__label" for="whd-vb-mood"><?php esc_html_e( 'Light and mood', 'whd' ); ?></label><select id="whd-vb-mood" class="whd-vb__select" data-list="moods"></select></div>
							<div class="whd-vb__field"><label class="whd-vb__label" for="whd-vb-pace"><?php esc_html_e( 'Pace', 'whd' ); ?></label><select id="whd-vb-pace" class="whd-vb__select" data-list="paces"></select></div>
							<div class="whd-vb__field"><label class="whd-vb__label" for="whd-vb-sound"><?php esc_html_e( 'Sound', 'whd' ); ?></label><select id="whd-vb-sound" class="whd-vb__select" data-list="sounds"></select></div>
						</div>
						<div class="whd-vb__field" id="whd-vb-place-text-wrap" hidden>
							<label class="whd-vb__label" for="whd-vb-place-text"><?php esc_html_e( 'Describe the place', 'whd' ); ?></label>
							<input type="text" id="whd-vb-place-text" class="whd-vb__input" maxlength="200" placeholder="<?php esc_attr_e( 'e.g. a sunlit Paris balcony with flower boxes', 'whd' ); ?>">
						</div>
						<div class="whd-vb__grid">
							<div class="whd-vb__field">
								<label class="whd-vb__label" for="whd-vb-words"><?php esc_html_e( 'Words to show in the video', 'whd' ); ?> <span class="whd-vb__opt"><?php esc_html_e( 'optional', 'whd' ); ?></span></label>
								<input type="text" id="whd-vb-words" class="whd-vb__input" maxlength="120" placeholder="<?php esc_attr_e( 'e.g. NEW IN', 'whd' ); ?>">
								<p class="whd-vb__hint"><?php esc_html_e( 'Drawn into the video by the model. For exact fonts and timing, add text in the editor instead.', 'whd' ); ?></p>
							</div>
						</div>
						<div class="whd-vb__field">
							<label class="whd-vb__label" for="whd-vb-details"><?php esc_html_e( 'Anything else?', 'whd' ); ?> <span class="whd-vb__opt"><?php esc_html_e( 'details to keep, things to avoid', 'whd' ); ?></span></label>
							<textarea id="whd-vb-details" class="whd-vb__text" rows="2" maxlength="1200" placeholder="<?php esc_attr_e( 'e.g. Keep the belt tied. No sunglasses. Show the pockets.', 'whd' ); ?>"></textarea>
						</div>
					</section>

					<section class="whd-ib__card" aria-labelledby="whd-vb-h-format">
						<header class="whd-ib__card-head">
							<span class="whd-ib__num" aria-hidden="true">3</span>
							<div>
								<h2 class="whd-ib__h" id="whd-vb-h-format"><?php esc_html_e( 'Shape, length and kind', 'whd' ); ?></h2>
								<p class="whd-ib__sub"><?php esc_html_e( 'Where it will be shown, how long it runs, and whether it is a video, a GIF, a boomerang or a seamless loop.', 'whd' ); ?></p>
							</div>
						</header>
						<div class="whd-vb__grid">
							<div class="whd-vb__field">
								<span class="whd-vb__label" id="whd-vb-orient-l"><?php esc_html_e( 'Orientation', 'whd' ); ?></span>
								<div class="whd-vb__seg" id="whd-vb-orient" role="radiogroup" aria-labelledby="whd-vb-orient-l"></div>
							</div>
							<div class="whd-vb__field"><label class="whd-vb__label" for="whd-vb-format"><?php esc_html_e( 'Format and size', 'whd' ); ?></label><select id="whd-vb-format" class="whd-vb__select"></select></div>
							<div class="whd-vb__field"><label class="whd-vb__label" for="whd-vb-purpose"><?php esc_html_e( 'It’s for', 'whd' ); ?></label><select id="whd-vb-purpose" class="whd-vb__select" data-list="purposes"></select></div>
							<div class="whd-vb__field"><label class="whd-vb__label" for="whd-vb-resolution"><?php esc_html_e( 'Quality', 'whd' ); ?></label><select id="whd-vb-resolution" class="whd-vb__select"></select></div>
						</div>
						<div class="whd-vb__field">
							<label class="whd-vb__label" for="whd-vb-seconds"><?php esc_html_e( 'Length', 'whd' ); ?> <output id="whd-vb-seconds-out" for="whd-vb-seconds"></output></label>
							<input type="range" id="whd-vb-seconds" class="whd-vb__range" min="3" max="30" step="1">
							<p class="whd-vb__hint" id="whd-vb-seconds-note"></p>
						</div>
						<div class="whd-vb__field">
							<span class="whd-vb__label" id="whd-vb-output-l"><?php esc_html_e( 'Make it a', 'whd' ); ?></span>
							<div class="whd-vb__seg whd-vb__seg--wide" id="whd-vb-output" role="radiogroup" aria-labelledby="whd-vb-output-l"></div>
						</div>
						<div class="whd-vb__grid" id="whd-vb-gif" hidden>
							<div class="whd-vb__field"><label class="whd-vb__label" for="whd-vb-gif-width"><?php esc_html_e( 'GIF width', 'whd' ); ?></label>
								<select id="whd-vb-gif-width" class="whd-vb__select"><option value="320">320 px</option><option value="480" selected>480 px</option><option value="640">640 px</option><option value="800">800 px</option></select></div>
							<div class="whd-vb__field"><label class="whd-vb__label" for="whd-vb-gif-fps"><?php esc_html_e( 'Frames per second', 'whd' ); ?></label>
								<select id="whd-vb-gif-fps" class="whd-vb__select"><option value="10">10</option><option value="12" selected>12</option><option value="15">15</option><option value="20">20</option></select></div>
						</div>
						<div class="whd-vb__grid" id="whd-vb-boom" hidden>
							<div class="whd-vb__field"><label class="whd-vb__label" for="whd-vb-boom-repeat"><?php esc_html_e( 'Back and forth', 'whd' ); ?></label>
								<select id="whd-vb-boom-repeat" class="whd-vb__select"><option value="2">2×</option><option value="3" selected>3×</option><option value="4">4×</option></select></div>
							<div class="whd-vb__field"><label class="whd-vb__label" for="whd-vb-boom-speed"><?php esc_html_e( 'Speed', 'whd' ); ?></label>
								<select id="whd-vb-boom-speed" class="whd-vb__select"><option value="1">1×</option><option value="1.5" selected>1.5×</option><option value="2">2×</option></select></div>
						</div>
						<div class="whd-vb__grid">
							<div class="whd-vb__field">
								<span class="whd-vb__label" id="whd-vb-var-l"><?php esc_html_e( 'How many versions', 'whd' ); ?></span>
								<div class="whd-vb__seg" id="whd-vb-variations" role="radiogroup" aria-labelledby="whd-vb-var-l"></div>
							</div>
							<div class="whd-vb__field whd-vb__check">
								<label><input type="checkbox" id="whd-vb-review"> <?php esc_html_e( 'Let me check the brief before it is filmed', 'whd' ); ?></label>
							</div>
						</div>
					</section>
				</div>

				<aside class="whd-ib__side" aria-label="<?php esc_attr_e( 'Summary', 'whd' ); ?>">
					<div class="whd-ib__summary">
						<div class="whd-ib__sum-photos" id="whd-vb-sum-photos" aria-hidden="true"></div>
						<div class="whd-vb__shape" id="whd-vb-shape" aria-hidden="true"><span></span></div>
						<dl class="whd-ib__sum-list">
							<div><dt><?php esc_html_e( 'Photos', 'whd' ); ?></dt><dd id="whd-vb-sum-p"></dd></div>
							<div><dt><?php esc_html_e( 'Video', 'whd' ); ?></dt><dd id="whd-vb-sum-v"></dd></div>
							<div><dt><?php esc_html_e( 'Cost', 'whd' ); ?></dt><dd id="whd-vb-sum-c"></dd></div>
							<div><dt><?php esc_html_e( 'Saved to', 'whd' ); ?></dt><dd><?php esc_html_e( 'Media library', 'whd' ); ?></dd></div>
						</dl>
						<button type="button" class="whd-ib__go" id="whd-vb-go"<?php disabled( ! $ready ); ?>>
							<?php echo $icon( '<rect x="3.5" y="6" width="12" height="12" rx="2"/><path d="m15.5 10 5-3v10l-5-3"/>', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span id="whd-vb-go-label"><?php esc_html_e( 'Make video', 'whd' ); ?></span>
						</button>
						<p class="whd-ib__why" id="whd-vb-why" role="status" aria-live="polite"></p>
						<p class="whd-vb__model"><?php echo esc_html( sprintf( /* translators: %s: model name */ __( 'Made by %s. Every video carries Google’s invisible SynthID watermark.', 'whd' ), self::model() ) ); ?></p>
					</div>
				</aside>
			</div>

			<section class="whd-ib__results" id="whd-vb-results" aria-labelledby="whd-vb-h-results">
				<header class="whd-ib__results-head">
					<h2 class="whd-ib__h" id="whd-vb-h-results"><?php esc_html_e( 'Your videos', 'whd' ); ?></h2>
					<p class="whd-ib__sub"><?php esc_html_e( 'Every video is saved to your media library as soon as it is made. Newest first.', 'whd' ); ?></p>
				</header>
				<div class="whd-vb__tiles" id="whd-vb-tiles"></div>
				<p class="whd-ib__empty" id="whd-vb-empty"><?php esc_html_e( 'Videos you make appear here.', 'whd' ); ?></p>
			</section>

			<section class="whd-ib__edit whd-vb__edit" id="whd-vb-edit" aria-labelledby="whd-vb-h-edit" hidden>
				<header class="whd-ib__results-head">
					<h2 class="whd-ib__h" id="whd-vb-h-edit"><?php esc_html_e( 'Video Editor', 'whd' ); ?></h2>
					<p class="whd-ib__sub"><?php esc_html_e( 'Trim it, add text, stickers, effects and music, then save it to your media library or download it. The video you made is kept as it is.', 'whd' ); ?></p>
					<button type="button" class="button-link" data-vb-edit-close><?php esc_html_e( 'Close the editor', 'whd' ); ?></button>
				</header>
				<div class="whd-ve" id="whd-ve"></div>
			</section>
		</div>
		<?php
	}

	/* ─────────────────────────── the wires ─────────────────────────── */

	public static function rest() {
		$can = static function () {
			return current_user_can( 'upload_files' ) && current_user_can( 'edit_posts' );
		};
		// The photographs are read by the studio's own steps, as background jobs asked after at studio/job.
		$jobs = [
			'look'    => static fn( WP_REST_Request $r ) => WHD_AI_Studio::look( 0, (array) $r->get_param( 'images' ), self::commands( $r ) ),
			'inspect' => static fn( WP_REST_Request $r ) => WHD_AI_Studio::inspect( 0, (array) $r->get_param( 'facts' ), self::commands( $r ) ),
		];
		foreach ( $jobs as $step => $work ) {
			register_rest_route( 'whd/v1', '/ai/video/' . $step, [
				'methods'             => 'POST',
				'callback'            => static function ( WP_REST_Request $r ) use ( $step, $work ) {
					return WHD_AI_Studio::background( 'video-' . $step, static fn() => self::answer( $work( $r ) ) );
				},
				'permission_callback' => $can,
			] );
		}
		$routes = [
			'prompt' => static fn( WP_REST_Request $r ) => self::answer( self::rest_prompt( $r ) ),
			'start'  => static fn( WP_REST_Request $r ) => self::answer( self::start( $r->get_json_params() ?: [] ) ),
			'status' => static fn( WP_REST_Request $r ) => self::answer( self::status( (string) $r->get_param( 'run' ) ) ),
			'cancel' => static fn( WP_REST_Request $r ) => self::answer( self::cancel( (string) $r->get_param( 'run' ) ) ),
			'tile'   => static fn( WP_REST_Request $r ) => self::answer( self::tile( absint( $r->get_param( 'id' ) ) ) ?: new WP_Error( 'whd_vb_gone', __( 'That video is not there any more.', 'whd' ) ) ),
			'delete' => static fn( WP_REST_Request $r ) => self::answer( self::delete( absint( $r->get_param( 'id' ) ) ) ),
			'cover'  => static fn( WP_REST_Request $r ) => self::answer( self::cover( absint( $r->get_param( 'id' ) ), (string) $r->get_param( 'data' ) ) ),
		];
		foreach ( $routes as $step => $work ) {
			register_rest_route( 'whd/v1', '/ai/video/' . $step, [
				'methods'             => 'POST',
				'callback'            => $work,
				'permission_callback' => $can,
			] );
		}
	}

	/** The shop's own words about the piece, which the inspector takes as true. */
	private static function commands( WP_REST_Request $r ): string {
		$c = (array) $r->get_param( 'choices' );
		return mb_substr( trim( sanitize_textarea_field( (string) ( $c['details'] ?? '' ) ) ), 0, 1200 );
	}

	private static function rest_prompt( WP_REST_Request $r ) {
		$c     = self::choices( (array) $r->get_param( 'choices' ) );
		$facts = (array) $r->get_param( 'facts' );
		return [
			'prompt'   => self::prompt( $facts, $c ),
			'turns'    => self::turns( $c['seconds'] ),
			'estimate' => self::estimate( $c ),
		];
	}

	private static function answer( $result ) {
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'message' => $result->get_error_message() ], 400 );
		}
		return new WP_REST_Response( $result, 200 );
	}

	/** Only a video made here, by this person, is deleted from this screen. */
	/**
	 * A cover for a video made here, drawn by the browser from the file (the host has no ffmpeg). Only
	 * for a video that has none, so a cover the shop chose is never replaced.
	 */
	private static function cover( int $id, string $data ) {
		if ( ! $id || ! get_post_meta( $id, self::MADE, true ) || 0 !== strpos( (string) get_post_mime_type( $id ), 'video/' ) ) {
			return new WP_Error( 'whd_vb_cover', __( 'That is not a video made here.', 'whd' ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'whd_vb_cover', __( 'You cannot change this video.', 'whd' ) );
		}
		if ( ! get_post_thumbnail_id( $id ) && class_exists( 'WHD_Video_Editor' ) ) {
			WHD_Video_Editor::poster( $id, $data, html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) );
		}
		return self::tile( $id );
	}

	private static function delete( int $id ) {
		if ( ! $id || ! get_post_meta( $id, self::MADE, true ) ) {
			return new WP_Error( 'whd_vb_delete', __( 'That is not a video made here.', 'whd' ) );
		}
		if ( (int) get_post_field( 'post_author', $id ) !== get_current_user_id() && ! current_user_can( 'delete_others_posts' ) ) {
			return new WP_Error( 'whd_vb_delete', __( 'Only whoever made this video can delete it here.', 'whd' ) );
		}
		$poster = (int) get_post_thumbnail_id( $id );
		$ok     = (bool) wp_delete_attachment( $id, true );
		if ( $ok && $poster && get_post_meta( $poster, self::MADE, true ) ) {
			wp_delete_attachment( $poster, true );
		}
		return [ 'deleted' => $ok ];
	}

	/* ─────────────────────────── a run ─────────────────────────── */

	/**
	 * Start one video: write the brief and ask Google for the first turn.
	 *
	 * @param array $args choices, facts (from look + inspect), images (attachment ids), prompt (optional,
	 *                    the brief as the shop edited it), variant (which of several versions this is).
	 * @return array|WP_Error { run, turns, estimate, mode }
	 */
	public static function start( array $args ) {
		if ( ! self::ready() ) {
			return new WP_Error( 'whd_vb_key', __( 'Add a Google AI key in WHD → AI content first.', 'whd' ) );
		}
		$c       = self::choices( (array) ( $args['choices'] ?? [] ) );
		$facts   = (array) ( $args['facts'] ?? [] );
		$variant = max( 0, (int) ( $args['variant'] ?? 0 ) );
		$images  = array_slice( array_values( array_filter( array_map( 'absint', (array) ( $args['images'] ?? [] ) ), 'wp_attachment_is_image' ) ), 0, WHD_AI_Studio::LOOK_MAX );
		if ( ! $images ) {
			return new WP_Error( 'whd_vb_photos', __( 'Add at least one photo of the product first.', 'whd' ) );
		}
		$edited = trim( sanitize_textarea_field( (string) ( $args['prompt'] ?? '' ) ) );
		$prompt = '' !== $edited ? mb_substr( $edited, 0, 8000 ) : self::prompt( $facts, $c, $variant );
		$turns  = self::turns( $c['seconds'] );

		$run = [
			'id'       => strtolower( wp_generate_password( 16, false, false ) ),
			'user'     => get_current_user_id(),
			'status'   => 'running',
			'mode'     => 'google',
			'choices'  => $c,
			'name'     => trim( (string) ( $facts['garment'] ?? '' ) ),
			'colour'   => trim( (string) ( $facts['colour'] ?? '' ) ),
			'prompt'   => $prompt,
			'extend'   => [],
			'images'   => $images,
			'turns'    => $turns,
			'turn'     => 0,
			'interaction'  => '',
			'interactions' => [],
			'videos'   => [],
			'started'  => time(),
			'turn_at'  => time(),
			'checked'  => 0,
			'doing'    => 'filming',
		];
		for ( $i = 1; $i < count( $turns ); $i++ ) {
			$run['extend'][] = self::extend_prompt( $facts, $c, $i, count( $turns ) );
		}

		$body = self::first_body( $run );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$background = 'off' !== ( WHD_AI::get()['video_background'] ?? 'auto' ) && 'no' !== get_option( self::BACKGROUND_OPTION );
		if ( $background ) {
			$made = self::create( $body + [ 'background' => true ] );
			if ( ! is_wp_error( $made ) ) {
				update_option( self::BACKGROUND_OPTION, 'yes', false );
				$run['interaction']    = (string) ( $made['id'] ?? '' );
				$run['interactions'][] = $run['interaction'];
				if ( '' === $run['interaction'] ) {
					return new WP_Error( 'whd_vb_id', __( 'Google answered without saying which video it is making. Try again.', 'whd' ) );
				}
				self::save_run( $run );
				// A model that finished straight away (it can, for a short draft) answered in full.
				if ( 'completed' === ( $made['status'] ?? '' ) ) {
					self::status( $run['id'] );
				}
				return self::summary( $run );
			}
			if ( ! self::refused_background( $made ) ) {
				return $made;
			}
			update_option( self::BACKGROUND_OPTION, 'no', false );
		}

		// No background mode for this model: our own job, after the answer has gone, waits for Google instead.
		$run['mode'] = 'local';
		self::save_run( $run );
		$id = $run['id'];
		WHD_Plugin::after_response( static function () use ( $id, $body ) {
			self::run_locally( $id, $body );
		} );
		return self::summary( $run );
	}

	/** What the screen is told about a run as it starts. */
	private static function summary( array $run ): array {
		return [
			'run'      => $run['id'],
			'turns'    => $run['turns'],
			'estimate' => self::estimate( $run['choices'] ),
			'mode'     => $run['mode'],
			'prompt'   => $run['prompt'],
		];
	}

	/** The first turn: the photographs, then the brief, then the shape of the video. */
	private static function first_body( array $run ) {
		$input = [];
		foreach ( $run['images'] as $id ) {
			$part = WHD_AI::picture_part( $id, self::PHOTO_EDGE );
			if ( $part ) {
				$input[] = [ 'type' => 'image', 'data' => $part['data'], 'mime_type' => $part['mime'] ];
			}
		}
		if ( ! $input ) {
			return new WP_Error( 'whd_vb_photos', __( 'The photos could not be read from the media library.', 'whd' ) );
		}
		$input[] = [ 'type' => 'text', 'text' => $run['prompt'] ];
		return [
			'model'           => self::model(),
			'input'           => $input,
			'response_format' => self::format( $run, 0 ),
		];
	}

	private static function format( array $run, int $turn ): array {
		return [
			'type'         => 'video',
			'aspect_ratio' => self::aspect( $run['choices'] ),
			'resolution'   => $run['choices']['resolution'],
			'duration'     => (int) $run['turns'][ $turn ] . 's',
		];
	}

	/** One extension: the next part of the same video, chained to the last turn. */
	private static function extend_body( array $run ): array {
		return [
			'model'                   => self::model(),
			'previous_interaction_id' => $run['interaction'],
			'input'                   => [ [ 'type' => 'text', 'text' => (string) ( $run['extend'][ $run['turn'] - 1 ] ?? 'Continue the scene seamlessly.' ) ] ],
			'response_format'         => self::format( $run, $run['turn'] ),
		];
	}

	/** Did Google say no to background mode itself (rather than to the video)? */
	private static function refused_background( WP_Error $error ): bool {
		$data = (array) $error->get_error_data();
		$code = (int) ( $data['status'] ?? 0 );
		return in_array( $code, [ 400, 404, 501 ], true ) && false !== stripos( $error->get_error_message(), 'background' );
	}

	/**
	 * Ask how a run is getting on; when its turn has finished, save the video and start the next turn.
	 *
	 * Google is only asked every POLL_GAP seconds however often the screen asks: its daily limits are low
	 * on the first paid tier (40 videos a day), and a status check should never be what uses them up.
	 */
	public static function status( string $id ) {
		$run = self::load_run( $id );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		if ( 'running' !== $run['status'] || 'local' === $run['mode'] ) {
			return self::report( $run );
		}
		if ( time() - (int) $run['checked'] < self::POLL_GAP ) {
			return self::report( $run );
		}
		if ( time() - (int) $run['turn_at'] > self::TURN_WAIT ) {
			return self::fail( $run, __( 'Google has not finished this video after twenty minutes. Try again, perhaps shorter or at a lower quality.', 'whd' ) );
		}

		// Claim the check, so two tabs asking at once do not both download the same video.
		$run['checked'] = time();
		self::save_run( $run );

		$file  = wp_tempnam( 'whd-video-reply' );
		$reply = self::request( 'GET', 'interactions/' . rawurlencode( $run['interaction'] ), null, $file );
		if ( is_wp_error( $reply ) ) {
			wp_delete_file( $file );
			$data = (array) $reply->get_error_data();
			// A blip (a timeout, a 5xx) is asked again next time; a real refusal ends the run.
			if ( in_array( (int) ( $data['status'] ?? 0 ), [ 0, 429, 500, 502, 503, 504 ], true ) ) {
				return self::report( $run );
			}
			return self::fail( $run, $reply->get_error_message() );
		}
		return self::after_reply( $run, $file );
	}

	/** A reply about a turn has arrived in $file: act on it. */
	private static function after_reply( array $run, string $file ) {
		$read = self::read_reply( $file );
		if ( is_wp_error( $read ) ) {
			wp_delete_file( $file );
			return self::fail( $run, $read->get_error_message() );
		}
		$state = (string) ( $read['json']['status'] ?? '' );
		if ( in_array( $state, [ 'in_progress', 'requires_action', '' ], true ) && ! $read['video'] ) {
			wp_delete_file( $file );
			return self::report( $run );
		}
		if ( in_array( $state, [ 'failed', 'cancelled', 'canceled', 'incomplete' ], true ) ) {
			wp_delete_file( $file );
			return self::fail( $run, self::reason( $read['json'], $state ) );
		}
		if ( ! $read['video'] ) {
			wp_delete_file( $file );
			return self::fail( $run, self::reason( $read['json'], $state ) );
		}

		$run['doing'] = 'saving';
		self::save_run( $run );
		$saved = self::take_video( $read, $file, $run );
		wp_delete_file( $file );
		if ( is_wp_error( $saved ) ) {
			if ( 'whd_vb_not_ready' === $saved->get_error_code() ) {
				$run['doing'] = 'filming';
				self::save_run( $run );
				return self::report( $run ); // the file is still being prepared on Google's side
			}
			return self::fail( $run, $saved->get_error_message() );
		}
		$run['videos'][] = $saved;
		return self::next_turn( $run );
	}

	/** After a turn's video is saved: the next extension, or done. */
	private static function next_turn( array $run ) {
		$run['turn']++;
		if ( $run['turn'] >= count( $run['turns'] ) ) {
			$run['status'] = 'done';
			$run['doing']  = 'done';
			self::save_run( $run );
			return self::report( $run );
		}
		// Google refuses a turn chained to one that is still finishing; it has finished, so ask now.
		$made = self::create( self::extend_body( $run ) + [ 'background' => true ] );
		if ( is_wp_error( $made ) ) {
			// What exists is kept: the first parts are saved, and the screen says the rest did not come.
			$run['status']  = 'done';
			$run['doing']   = 'done';
			$run['partial'] = $made->get_error_message();
			self::save_run( $run );
			return self::report( $run );
		}
		$run['interaction']    = (string) ( $made['id'] ?? '' );
		$run['interactions'][] = $run['interaction'];
		$run['turn_at']        = time();
		$run['checked']        = time();
		$run['doing']          = 'filming';
		self::save_run( $run );
		return self::report( $run );
	}

	/**
	 * The whole run in one go, after the answer has gone: for a model Google will not run in the background.
	 * The screen asks after it the same way, and sees each part arrive as it is saved.
	 */
	private static function run_locally( string $id, array $body ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- some hosts disable it; carry on regardless
		}
		ignore_user_abort( true );
		$run = self::load_run( $id );
		if ( is_wp_error( $run ) ) {
			return;
		}
		$turn_body = $body;
		while ( 'running' === $run['status'] ) {
			$file  = wp_tempnam( 'whd-video-reply' );
			$turn_body['response_format']['delivery'] = 'uri';
			$reply = self::request( 'POST', 'interactions', $turn_body, $file, 900 );
			$run   = self::load_run( $id );
			if ( is_wp_error( $run ) ) {
				wp_delete_file( $file );
				return;
			}
			if ( 'running' !== $run['status'] ) {
				wp_delete_file( $file );
				return; // stopped from the screen while Google worked
			}
			if ( is_wp_error( $reply ) ) {
				wp_delete_file( $file );
				if ( $run['videos'] ) {
					$run['status']  = 'done';
					$run['partial'] = $reply->get_error_message();
					self::save_run( $run );
				} else {
					self::fail( $run, $reply->get_error_message() );
				}
				return;
			}
			$read = self::read_reply( $file );
			if ( ! is_wp_error( $read ) ) {
				$run['interaction']    = (string) ( $read['json']['id'] ?? '' );
				$run['interactions'][] = $run['interaction'];
			}
			if ( is_wp_error( $read ) || ! $read['video'] ) {
				wp_delete_file( $file );
				self::fail( $run, is_wp_error( $read ) ? $read->get_error_message() : self::reason( $read['json'], (string) ( $read['json']['status'] ?? '' ) ) );
				return;
			}
			$run['doing'] = 'saving';
			self::save_run( $run );
			// A file delivered by address can take a moment to be ready: ask a few times before giving up.
			$saved = null;
			for ( $try = 0; $try < 60; $try++ ) {
				$saved = self::take_video( $read, $file, $run );
				if ( ! is_wp_error( $saved ) || 'whd_vb_not_ready' !== $saved->get_error_code() ) {
					break;
				}
				sleep( 5 );
			}
			wp_delete_file( $file );
			if ( is_wp_error( $saved ) ) {
				self::fail( $run, $saved->get_error_message() );
				return;
			}
			$run['videos'][] = $saved;
			$run['turn']++;
			if ( $run['turn'] >= count( $run['turns'] ) ) {
				$run['status'] = 'done';
				$run['doing']  = 'done';
				self::save_run( $run );
				return;
			}
			$run['doing']   = 'filming';
			$run['turn_at'] = time();
			self::save_run( $run );
			$turn_body = self::extend_body( $run );
		}
	}

	/** Stop a run. Google is told to stop too; anything already saved stays. */
	public static function cancel( string $id ) {
		$run = self::load_run( $id );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		if ( 'running' === $run['status'] && 'google' === $run['mode'] && '' !== $run['interaction'] ) {
			self::request( 'POST', 'interactions/' . rawurlencode( $run['interaction'] ) . '/cancel', [] );
		}
		$run['status'] = 'cancelled';
		$run['doing']  = 'cancelled';
		self::save_run( $run );
		return self::report( $run );
	}

	private static function fail( array $run, string $message ) {
		$run['status'] = 'error';
		$run['doing']  = 'error';
		$run['error']  = $message;
		self::save_run( $run );
		return self::report( $run );
	}

	/** Why a turn ended without a video, in words a shop can act on. */
	private static function reason( array $json, string $state ): string {
		$said = '';
		foreach ( [ [ 'error', 'message' ], [ 'status_details', 'message' ], [ 'incomplete_details', 'reason' ] ] as $path ) {
			$v = $json[ $path[0] ][ $path[1] ] ?? '';
			if ( is_string( $v ) && '' !== trim( $v ) ) {
				$said = trim( $v );
				break;
			}
		}
		if ( '' === $said ) {
			foreach ( (array) ( $json['steps'] ?? [] ) as $step ) {
				foreach ( (array) ( $step['content'] ?? [] ) as $part ) {
					if ( 'text' === ( $part['type'] ?? '' ) && 'model_output' === ( $step['type'] ?? '' ) && '' !== trim( (string) ( $part['text'] ?? '' ) ) ) {
						$said = trim( (string) $part['text'] );
					}
				}
			}
		}
		if ( '' === $said ) {
			$said = 'failed' === $state
				? __( 'Google could not make this video. A photo or the brief may have tripped its safety filters — try other photos, or fewer words about people.', 'whd' )
				: __( 'Google sent no video back.', 'whd' );
		}
		return mb_substr( wp_strip_all_tags( $said ), 0, 600 );
	}

	/** What the screen is told: how far it is, and the videos saved so far. */
	private static function report( array $run ): array {
		$out = [
			'run'     => $run['id'],
			'status'  => $run['status'],
			'doing'   => $run['doing'],
			'turn'    => min( (int) $run['turn'], count( $run['turns'] ) - 1 ),
			'turns'   => $run['turns'],
			'seconds' => time() - (int) $run['started'],
			'mode'    => $run['mode'],
			'videos'  => array_values( array_filter( array_map( [ __CLASS__, 'tile' ], array_map( 'intval', $run['videos'] ) ) ) ),
		];
		if ( ! empty( $run['error'] ) ) {
			$out['message'] = $run['error'];
		}
		if ( ! empty( $run['partial'] ) ) {
			$out['partial'] = $run['partial'];
		}
		return $out;
	}

	private static function run_key( string $id ): string {
		return 'whd_vb_run_' . get_current_user_id() . '_' . preg_replace( '/[^a-z0-9]/', '', strtolower( $id ) );
	}

	private static function save_run( array $run ) {
		set_transient( 'whd_vb_run_' . (int) $run['user'] . '_' . $run['id'], $run, self::RUN_TTL );
	}

	private static function load_run( string $id ) {
		$run = '' !== $id ? get_transient( self::run_key( $id ) ) : false;
		if ( ! is_array( $run ) ) {
			return new WP_Error( 'whd_vb_run', __( 'That video is not known here any more. Make it again.', 'whd' ) );
		}
		return $run;
	}

	/* ─────────────────────────── talking to Google ─────────────────────────── */

	/** Create an interaction; the parsed reply (small, since nothing has been made yet) or the error. */
	private static function create( array $body ) {
		$file  = wp_tempnam( 'whd-video-reply' );
		$reply = self::request( 'POST', 'interactions', $body, $file, 120 );
		if ( is_wp_error( $reply ) ) {
			wp_delete_file( $file );
			return $reply;
		}
		$read = self::read_reply( $file );
		wp_delete_file( $file );
		return is_wp_error( $read ) ? $read : $read['json'];
	}

	/**
	 * One call. With $file the reply is streamed to disk rather than held in memory — a finished video
	 * comes back as tens of megabytes of base64 inside the JSON.
	 *
	 * @return true|array|WP_Error true when streamed to $file, else the decoded JSON.
	 */
	private static function request( string $method, string $path, ?array $body = null, string $file = '', int $timeout = 60 ) {
		$args = [
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => [
				'x-goog-api-key' => (string) WHD_AI::get()['google_key'],
				'Content-Type'   => 'application/json',
				'Api-Revision'   => self::REVISION,
			],
		];
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body ?: (object) [], JSON_UNESCAPED_SLASHES );
		}
		if ( '' !== $file ) {
			$args['stream']   = true;
			$args['filename'] = $file;
		}
		$response = wp_remote_request( self::API . $path, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'whd_vb_http', $response->get_error_message(), [ 'status' => 0 ] );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$raw = '' !== $file && file_exists( $file ) ? (string) file_get_contents( $file, false, null, 0, 65536 ) : wp_remote_retrieve_body( $response ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own temp file
			$err = json_decode( $raw, true );
			$msg = is_array( $err ) ? (string) ( $err['error']['message'] ?? $err['message'] ?? '' ) : '';
			return new WP_Error( 'whd_vb_http', sprintf( '%d — %s', $code, '' !== $msg ? $msg : __( 'Google refused the request', 'whd' ) ), [ 'status' => $code ] );
		}
		if ( '' !== $file ) {
			return true;
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $json ) ? $json : [];
	}

	/**
	 * Read a reply streamed to $file without decoding the video data in it.
	 *
	 * The JSON is copied with every very long string (the base64 of a video, or of the photographs echoed
	 * back in the user's own turn) replaced by a placeholder that remembers where in the file it sits; what
	 * is left is small and decoded normally. The video part of the model's output is then found by
	 * structure, and its data — still in the file — decoded to an MP4 in pieces by take_video().
	 *
	 * @return array|WP_Error { json, video: null | { data: [start, end] } | { uri: string } }
	 */
	public static function read_reply( string $file ) {
		$fh = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a temp file
		if ( ! $fh ) {
			return new WP_Error( 'whd_vb_read', __( 'The reply from Google could not be read.', 'whd' ) );
		}
		$small   = '';
		$big     = [];
		$in      = false;  // inside a string
		$escape  = false;  // the last byte inside a string was a backslash
		$buffer  = '';     // the current string while it is still short
		$length  = 0;
		$started = 0;      // file offset of the current string's first byte
		$offset  = 0;      // file offset of $chunk[0]
		while ( ! feof( $fh ) ) {
			$chunk = fread( $fh, 1048576 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$n = strlen( $chunk );
			$i = 0;
			while ( $i < $n ) {
				if ( ! $in ) {
					$q = strpos( $chunk, '"', $i );
					if ( false === $q ) {
						$small .= substr( $chunk, $i );
						break;
					}
					$small  .= substr( $chunk, $i, $q - $i + 1 );
					$in      = true;
					$escape  = false;
					$buffer  = '';
					$length  = 0;
					$started = $offset + $q + 1;
					$i       = $q + 1;
					continue;
				}
				if ( $escape ) {
					if ( $length <= self::BIG_STRING ) {
						$buffer .= $chunk[ $i ];
					}
					$length++;
					$escape = false;
					$i++;
					continue;
				}
				$span = strcspn( $chunk, "\"\\", $i );
				if ( $length <= self::BIG_STRING ) {
					$buffer .= substr( $chunk, $i, $span );
				}
				$length += $span;
				$i      += $span;
				if ( $i >= $n ) {
					break;
				}
				if ( '\\' === $chunk[ $i ] ) {
					if ( $length <= self::BIG_STRING ) {
						$buffer .= '\\';
					}
					$length++;
					$escape = true;
					$i++;
					continue;
				}
				// The closing quote.
				if ( $length > self::BIG_STRING ) {
					$big[]  = [ $started, $offset + $i ];
					$small .= '__WHD_BIG_' . ( count( $big ) - 1 ) . '__"';
				} else {
					$small .= $buffer . '"';
				}
				$in     = false;
				$buffer = '';
				$i++;
			}
			$offset += $n;
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( $in ) {
			return new WP_Error( 'whd_vb_read', __( 'The reply from Google was cut off. Try again.', 'whd' ) );
		}
		$json = json_decode( $small, true );
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'whd_vb_read', __( 'The reply from Google could not be understood.', 'whd' ) );
		}
		return [ 'json' => $json, 'video' => self::find_video( $json, $big ) ];
	}

	/** The model's video in a decoded reply: where its data sits in the file, or the address it is at. */
	private static function find_video( array $json, array $big ): ?array {
		$parts = [];
		foreach ( (array) ( $json['steps'] ?? [] ) as $step ) {
			if ( 'model_output' !== ( $step['type'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $step['content'] ?? [] ) as $part ) {
				if ( 'video' === ( $part['type'] ?? '' ) ) {
					$parts[] = $part;
				}
			}
		}
		// The SDK's own convenience field, should a revision of the API send it to REST callers too.
		if ( ! $parts && is_array( $json['output_video'] ?? null ) ) {
			$parts[] = $json['output_video'];
		}
		$part = end( $parts );
		if ( ! $part ) {
			return null;
		}
		$data = (string) ( $part['data'] ?? '' );
		if ( preg_match( '/^__WHD_BIG_(\d+)__$/', $data, $m ) && isset( $big[ (int) $m[1] ] ) ) {
			return [ 'data' => $big[ (int) $m[1] ] ];
		}
		if ( '' !== $data ) {
			return [ 'inline' => $data ]; // a tiny video, decoded with the rest
		}
		$uri = (string) ( $part['uri'] ?? '' );
		return '' !== $uri ? [ 'uri' => $uri ] : null;
	}

	/**
	 * The video out of a reply, into the media library.
	 *
	 * @return int|WP_Error The attachment id; whd_vb_not_ready when a file sent by address is not ready yet.
	 */
	private static function take_video( array $read, string $file, array $run ) {
		$mp4 = wp_tempnam( 'whd-video' );
		$v   = $read['video'];
		if ( isset( $v['data'] ) ) {
			$ok = self::decode_region( $file, (int) $v['data'][0], (int) $v['data'][1], $mp4 );
		} elseif ( isset( $v['inline'] ) ) {
			$ok = false !== file_put_contents( $mp4, base64_decode( str_replace( [ '\\n', '\\r', '\\/' ], [ '', '', '/' ], $v['inline'] ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		} else {
			$ok = self::download( (string) $v['uri'], $mp4 );
			if ( is_wp_error( $ok ) ) {
				wp_delete_file( $mp4 );
				return $ok;
			}
		}
		if ( ! $ok || ! self::is_mp4( $mp4 ) ) {
			wp_delete_file( $mp4 );
			return new WP_Error( 'whd_vb_file', __( 'What Google sent back is not a video file.', 'whd' ) );
		}
		return self::store( $mp4, $run );
	}

	/** Base64 between two offsets of $file, decoded to $out a few megabytes at a time. */
	private static function decode_region( string $file, int $start, int $end, string $out ): bool {
		$in  = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$to  = fopen( $out, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $in || ! $to ) {
			return false;
		}
		fseek( $in, $start );
		$left  = $end - $start;
		$carry = '';
		while ( $left > 0 ) {
			$read  = fread( $in, (int) min( 4194304, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $read || '' === $read ) {
				break;
			}
			$left -= strlen( $read );
			// JSON may escape a slash or carry line breaks; base64 itself has neither a backslash nor a quote.
			$text  = $carry . str_replace( [ '\\n', '\\r', '\\/' ], [ '', '', '/' ], $read );
			if ( '\\' === substr( $text, -1 ) ) {
				$carry = '\\';
				$text  = substr( $text, 0, -1 );
			} else {
				$carry = '';
			}
			$text  = preg_replace( '/[^A-Za-z0-9+\/=_-]/', '', $text );
			$whole = strlen( $text ) - ( strlen( $text ) % 4 );
			if ( $left > 0 ) {
				$carry = substr( $text, $whole ) . $carry;
				$text  = substr( $text, 0, $whole );
			}
			if ( '' !== $text ) {
				$bytes = base64_decode( strtr( $text, '-_', '+/' ) );
				if ( false === $bytes ) {
					fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $to ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					return false;
				}
				fwrite( $to, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			}
		}
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $to ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return true;
	}

	/**
	 * A video Google keeps as a file: wait until it is ACTIVE, then download it to $out.
	 *
	 * @return true|WP_Error
	 */
	private static function download( string $uri, string $out ) {
		if ( ! preg_match( '#^https://generativelanguage\.googleapis\.com/v1beta/(files/[A-Za-z0-9_-]+)#', $uri, $m ) ) {
			return new WP_Error( 'whd_vb_uri', __( 'Google gave an address for the video that is not one of its own.', 'whd' ) );
		}
		$info = self::request( 'GET', $m[1] );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		$state = strtoupper( (string) ( $info['state'] ?? 'ACTIVE' ) );
		if ( 'FAILED' === $state ) {
			return new WP_Error( 'whd_vb_file', __( 'Google could not prepare the video file.', 'whd' ) );
		}
		if ( 'ACTIVE' !== $state ) {
			return new WP_Error( 'whd_vb_not_ready', __( 'Google is still preparing the file.', 'whd' ) );
		}
		$got = self::request( 'GET', $m[1] . ':download?alt=media', null, $out, 300 );
		return is_wp_error( $got ) ? $got : true;
	}

	private static function is_mp4( string $file ): bool {
		$head = (string) file_get_contents( $file, false, null, 0, 12 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own temp file
		return strlen( $head ) >= 12 && 'ftyp' === substr( $head, 4, 4 );
	}

	/** An MP4 on disk becomes a media-library video, marked as made here. */
	private static function store( string $mp4, array $run ) {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			wp_delete_file( $mp4 );
			return new WP_Error( 'whd_vb_uploads', $uploads['error'] );
		}
		$base = sanitize_title( $run['name'] ?: 'product' ) ?: 'product';
		$name = wp_unique_filename( $uploads['path'], $base . '-video-' . strtolower( wp_generate_password( 4, false, false ) ) . '.mp4' );
		$dest = trailingslashit( $uploads['path'] ) . $name;
		if ( ! @rename( $mp4, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.rename_rename -- a temp file into uploads
			if ( ! @copy( $mp4, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				wp_delete_file( $mp4 );
				return new WP_Error( 'whd_vb_write', __( 'The video could not be written to the uploads folder.', 'whd' ) );
			}
			wp_delete_file( $mp4 );
		}
		$stat = @stat( dirname( $dest ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( $stat ) {
			@chmod( $dest, $stat['mode'] & 0000666 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		}

		$part  = count( $run['videos'] ) + 1;
		$title = sprintf(
			/* translators: 1: what the product is, 2: the style */
			__( '%1$s — %2$s video', 'whd' ),
			'' !== $run['name'] ? ucfirst( $run['name'] ) : __( 'Product', 'whd' ),
			self::options()['styles'][ $run['choices']['style'] ]['label']
		) . ( count( $run['turns'] ) > 1 ? ' (' . $part . '/' . count( $run['turns'] ) . ')' : '' );
		$id = wp_insert_attachment( [
			'post_mime_type' => 'video/mp4',
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
			'guid'           => trailingslashit( $uploads['url'] ) . $name,
		], $dest );
		if ( is_wp_error( $id ) || ! $id ) {
			return is_wp_error( $id ) ? $id : new WP_Error( 'whd_vb_attach', __( 'The video could not be added to the media library.', 'whd' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $dest ) );
		update_post_meta( $id, self::MADE, 1 );
		update_post_meta( $id, self::KIND, 'raw' );
		update_post_meta( $id, self::PROMPT, wp_slash( 1 === $part ? $run['prompt'] : (string) ( $run['extend'][ $part - 2 ] ?? '' ) ) );
		update_post_meta( $id, self::CHOICES, wp_slash( wp_json_encode( $run['choices'] ) ) );
		update_post_meta( $id, self::INTERACTION, sanitize_text_field( $run['interaction'] ) );
		return (int) $id;
	}
}
