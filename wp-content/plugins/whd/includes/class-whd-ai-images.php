<?php
/**
 * Photographs for the product story, drawn by the model from the ones we already have.
 *
 * The story under the reviews is a run of spreads: copy on one side, a photograph on the other,
 * reversed every row. A product with two pictures and four sections leaves two rows with nothing
 * to put beside the words, and those rows fall back to a centred column — the rhythm breaks.
 *
 * So: send the pictures we do have to an image model as references, ask for the same garment on a
 * different person in a different room, and put what comes back in the gallery. References are the
 * whole trick — without them the model invents a garment that is not the one being sold.
 *
 * Only the gallery is ever added to. Nothing here replaces or deletes a photograph somebody took.
 *
 * @package WHD
 */

defined( 'ABSPATH' ) || exit;

final class WHD_AI_Images {

	/** Marks an attachment this class made, so it can be told from a photograph of the real thing. */
	const META = '_whd_ai_image';

	/** What the story band wants in total. Four sections, four pictures. */
	const ROWS = 4;

	/** Models that return pictures rather than words. The first is the default. */
	public static function models() {
		return [
			'gemini-3.1-flash-image' => __( 'Gemini 3.1 Flash Image — quick, the usual choice', 'whd' ),
			'gemini-3-pro-image'     => __( 'Gemini 3 Pro Image — slower, better with fine detail', 'whd' ),
			'gemini-2.5-flash-image' => __( 'Gemini 2.5 Flash Image', 'whd' ),
		];
	}

	public static function model(): string {
		$chosen = (string) ( WHD_AI::get()['image_model'] ?? '' );
		$models = self::models();

		return isset( $models[ $chosen ] ) ? $chosen : (string) array_key_first( $models );
	}

	/**
	 * How large to ask for: 1K, 2K or 4K.
	 *
	 * Unasked, the model returns about 1,400 pixels across. That is plenty for a product card and
	 * nowhere near enough for a photograph that runs the full width of a screen — the first set of
	 * heroes came back at 1,264 and looked soft for exactly that reason, because the request had
	 * never said otherwise.
	 */
	public static function size(): string {
		$set = strtoupper( (string) ( WHD_AI::get()['image_size'] ?? '' ) );

		return in_array( $set, [ '1K', '2K', '4K' ], true ) ? $set : '1K';
	}

	/**
	 * Drawing pictures goes through Google whichever provider writes the words.
	 *
	 * The other two houses either have no image endpoint or a different one; rather than pretend,
	 * the panel says plainly that a Google key is what this needs.
	 */
	public static function ready(): bool {
		return '' !== trim( (string) ( WHD_AI::get()['google_key'] ?? '' ) );
	}

	/** Every picture attached to a product, featured first, the way the story reads them. */
	public static function gallery( $product_id ): array {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			return [];
		}

		return array_values( array_unique( array_filter( array_map(
			'intval',
			array_merge( [ $product->get_image_id() ], $product->get_gallery_image_ids() )
		) ) ) );
	}

	/**
	 * How many sections the story on this product actually has.
	 *
	 * A story that has been written tells us; one that has not is assumed to be the usual four, so
	 * the panel can offer pictures before the words exist.
	 */
	public static function rows( $product_id ): int {
		if ( ! class_exists( 'WHD_Product_Story' ) || ! WHD_Product_Story::has( $product_id ) ) {
			return self::ROWS;
		}
		$design = WHD_Product_Story::get( $product_id );
		$rows   = 0;
		foreach ( (array) ( $design['blocks'] ?? [] ) as $block ) {
			$rows += (int) ( 'heading' === ( $block['type'] ?? '' ) );
		}

		return max( 1, $rows );
	}

	/** How many pictures are missing before every row has one. */
	public static function missing( $product_id ): int {
		return max( 0, self::rows( $product_id ) - count( self::gallery( $product_id ) ) );
	}

	/* The long edge and the quality a drawn picture is stored at. The model returns far more
	   than a product card or a journal column can show, and the difference is only weight. */
	const MAX_EDGE = 1600;
	const QUALITY  = 82;

	/* ─────────────────────────── sizes ─────────────────────────── */

	/** The aspect ratios the image model accepts, as width / height. */
	const RATIOS = [
		'1:1'  => 1.0,
		'2:3'  => 2 / 3,
		'3:2'  => 1.5,
		'3:4'  => 0.75,
		'4:3'  => 4 / 3,
		'4:5'  => 0.8,
		'5:4'  => 1.25,
		'9:16' => 9 / 16,
		'16:9' => 16 / 9,
		'21:9' => 21 / 9,
	];

	/**
	 * The sizes a drawn picture can be made at — the Image Editor's list (admin/image-presets.js,
	 * `sizes`) without its "Original", so a picture drawn for a place comes out the size that place
	 * wants. Change one list and change the other.
	 *
	 * @return array key => { name, group, w, h }
	 */
	public static function frames(): array {
		$shop   = __( 'Shop', 'whd' );
		$social = __( 'Social', 'whd' );
		$web    = __( 'Web and email', 'whd' );
		$print  = __( 'Print', 'whd' );
		$any    = __( 'Any use', 'whd' );
		$frames = [
			'product-square'     => [ __( 'Product, square', 'whd' ), $shop, 2000, 2000 ],
			'product-3-4'        => [ __( 'Product, 3:4', 'whd' ), $shop, 1500, 2000 ],
			'instagram-post'     => [ __( 'Instagram post', 'whd' ), $social, 1080, 1080 ],
			'instagram-portrait' => [ __( 'Instagram portrait', 'whd' ), $social, 1080, 1350 ],
			'story'              => [ __( 'Story / Reel', 'whd' ), $social, 1080, 1920 ],
			'facebook-post'      => [ __( 'Facebook post', 'whd' ), $social, 1200, 630 ],
			'facebook-cover'     => [ __( 'Facebook cover', 'whd' ), $social, 1640, 624 ],
			'facebook-story'     => [ __( 'Facebook story', 'whd' ), $social, 1080, 1920 ],
			'pinterest-pin'      => [ __( 'Pinterest pin', 'whd' ), $social, 1000, 1500 ],
			'pinterest-square'   => [ __( 'Pinterest square', 'whd' ), $social, 1000, 1000 ],
			'tiktok-cover'       => [ __( 'TikTok cover', 'whd' ), $social, 1080, 1920 ],
			'youtube-thumbnail'  => [ __( 'YouTube thumbnail', 'whd' ), $social, 1280, 720 ],
			'x-post'             => [ __( 'X / Twitter post', 'whd' ), $social, 1600, 900 ],
			'web-banner'         => [ __( 'Web banner', 'whd' ), $web, 1920, 800 ],
			'email-header'       => [ __( 'Email header', 'whd' ), $web, 1200, 600 ],
			'lookbook-a4'        => [ __( 'Lookbook page A4', 'whd' ), $print, 1240, 1754 ],
			'gift-card'          => [ __( 'Gift card', 'whd' ), $print, 1050, 600 ],
			'landscape-3-2'      => [ __( 'Landscape 3:2', 'whd' ), $any, 1800, 1200 ],
			'portrait-2-3'       => [ __( 'Portrait 2:3', 'whd' ), $any, 1200, 1800 ],
		];

		$out = [];
		foreach ( (array) apply_filters( 'whd_ai_image_frames', $frames ) as $key => $f ) {
			$w = (int) ( $f[2] ?? 0 );
			$h = (int) ( $f[3] ?? 0 );
			// Past 4K the model has nothing larger to give; scaling up that far is not a picture any more.
			if ( '' !== sanitize_key( (string) $key ) && $w >= 64 && $h >= 64 && $w <= 4096 && $h <= 4096 ) {
				$out[ sanitize_key( (string) $key ) ] = [ 'name' => (string) $f[0], 'group' => (string) ( $f[1] ?? '' ), 'w' => $w, 'h' => $h ];
			}
		}
		return $out;
	}

	/**
	 * One size, with what to ask the model for: the accepted shape nearest it, and the smallest of
	 * 1K / 2K / 4K that still covers it once trimmed — never smaller than the size chosen in the
	 * settings. Null when no size was chosen (or one that is not on the list).
	 *
	 * @param string $key A key of frames().
	 * @return array|null { key, name, group, w, h, aspect, size }
	 */
	public static function frame( $key ) {
		$key    = sanitize_key( (string) $key );
		$frames = self::frames();
		if ( '' === $key || ! isset( $frames[ $key ] ) ) {
			return null;
		}
		$f      = $frames[ $key ];
		$aspect = self::nearest_ratio( $f['w'] / $f['h'] );

		return $f + [ 'key' => $key, 'aspect' => $aspect, 'size' => self::size_for( $f['w'], $f['h'], $aspect ) ];
	}

	/** The size picker's words (WHDAIKit.framePicker in admin/ai-kit.js), for every screen that has one. */
	public static function frame_strings(): array {
		return [
			'frameLabel'  => __( 'Size', 'whd' ),
			/* translators: 1: size name, 2: width, 3: height */
			'frameOption' => __( '%1$s — %2$d × %3$d', 'whd' ),
			/* translators: 1: aspect ratio, e.g. 4:5, 2: width, 3: height */
			'frameNote'   => __( 'Drawn in %1$s, then cut and scaled to exactly %2$d × %3$d pixels.', 'whd' ),
		];
	}

	/** Every size, for a picker in the browser: [ { key, name, group, w, h, aspect, size } ]. */
	public static function frame_list(): array {
		return array_values( array_filter( array_map( [ __CLASS__, 'frame' ], array_keys( self::frames() ) ) ) );
	}

	/** The accepted ratio nearest a width / height, by how far apart they look rather than how far apart the numbers are. */
	public static function nearest_ratio( float $want ): string {
		$best = '1:1';
		$gap  = INF;
		foreach ( self::RATIOS as $ratio => $value ) {
			$d = abs( log( $value ) - log( max( 0.01, $want ) ) );
			if ( $d < $gap ) {
				$gap  = $d;
				$best = $ratio;
			}
		}
		return $best;
	}

	/**
	 * The model's sizes run at about a megapixel for 1K, four for 2K and sixteen for 4K, in the shape
	 * asked for (1:1 at 1K is 1024 × 1024, 16:9 is 1376 × 768). A few per cent short is let through
	 * and made up when the picture is scaled to size — 1024 for a 1080 post is not worth four times
	 * the pixels.
	 */
	private static function size_for( int $w, int $h, string $aspect ): string {
		$r    = self::RATIOS[ $aspect ] ?? 1.0;
		$want = $w / $h;
		$need = '4K';
		foreach ( [ '1K' => 1, '2K' => 2, '4K' => 4 ] as $size => $scale ) {
			$mw = 1024 * sqrt( $r ) * $scale;
			$mh = 1024 / sqrt( $r ) * $scale;
			// What is left once the picture is trimmed to the size's own shape.
			$cw = $want > $r ? $mw : $mh * $want;
			$ch = $want > $r ? $mw / $want : $mh;
			if ( $cw >= $w * 0.94 && $ch >= $h * 0.94 ) {
				$need = $size;
				break;
			}
		}
		$rank = [ '1K' => 1, '2K' => 2, '4K' => 3 ];

		return $rank[ self::size() ] > $rank[ $need ] ? self::size() : $need;
	}

	/** The size in the brief's own words, so the model frames for the cut that is coming. */
	public static function framing( array $frame ): string {
		return sprintf(
			'aspect ratio %1$s; it is trimmed afterwards to exactly %2$d × %3$d pixels (%4$s), so keep heads, hands and the whole piece clear of the edges',
			$frame['aspect'],
			$frame['w'],
			$frame['h'],
			$frame['name']
		);
	}

	/**
	 * Cut a picture to a size's shape, from the middle, and scale it to that size exactly — up as well
	 * as down. The model only draws in its own ratios, and a picture for a 1200 × 630 post has to be
	 * 1200 × 630, not "about 16:9".
	 */
	private static function trim( WP_Image_Editor $editor, int $w, int $h ) {
		$size = $editor->get_size();
		$sw   = (int) $size['width'];
		$sh   = (int) $size['height'];
		if ( ! $sw || ! $sh ) {
			return;
		}
		$want = $w / $h;
		if ( $sw / $sh > $want ) {
			$ch = $sh;
			$cw = (int) round( $sh * $want );
		} else {
			$cw = $sw;
			$ch = (int) round( $sw / $want );
		}
		$editor->crop( (int) floor( ( $sw - $cw ) / 2 ), (int) floor( ( $sh - $ch ) / 2 ), $cw, $ch, $w, $h );
	}

	/* ─────────────────────────── the brief ─────────────────────────── */

	/**
	 * What was asked for this time, as its own block in the prompt.
	 *
	 * The look is a setting — it describes the shop's photography and rarely changes. This is the
	 * other half, and it belongs to one run: what this particular set is of. It goes near the top
	 * of the prompt, above the house look, because a model weighs the first thing it reads most.
	 */
	public static function asked( string $ask ): string {
		$ask = trim( wp_strip_all_tags( $ask ) );

		return '' === $ask ? '' : "ASKED FOR\n" . $ask . "\n\n";
	}


	/**
	 * The look every picture has to sit inside.
	 *
	 * Editable, because the day the shop's photography changes this is the one line that has to
	 * change with it.
	 */
	public static function look(): string {
		$set = trim( (string) ( WHD_AI::get()['image_brief'] ?? '' ) );

		return '' !== $set ? $set : __(
			'Soft natural daylight, warm cream and sand tones, a calm uncluttered room or a quiet street, shallow depth of field, nothing styled to look like an advertisement.',
			'whd'
		);
	}

	/**
	 * Who appears in a drawn picture.
	 *
	 * The casting used to run from "a woman in her twenties" to "a woman in her fifties", with a
	 * rule asking for a different age in every picture of a set — so every set had an older woman
	 * in it, and the posts and pages, which named nobody, drifted the same way. The shop dresses
	 * women in their twenties and early thirties. So each person now has an age, stated as a
	 * number (a model keeps to a number better than to a "not"), and a background: mostly East
	 * and Southeast Asian, for a shop of Korean and Thai fashion, alternating with white women.
	 *
	 * Filter `whd_ai_image_people` to change who appears.
	 */
	public static function people(): array {
		$people = (array) apply_filters( 'whd_ai_image_people', [
			__( 'a Korean woman, about 24, with long dark hair', 'whd' ),
			__( 'a white woman, about 27, with light brown hair', 'whd' ),
			__( 'a Thai woman, about 25, with shoulder-length black hair', 'whd' ),
			__( 'a white woman, about 23, with dark blonde hair tied back', 'whd' ),
			__( 'a Japanese woman, about 29, with a short black bob', 'whd' ),
			__( 'a white woman, about 30, with auburn hair', 'whd' ),
			__( 'a Chinese woman, about 26, with dark hair in a low ponytail', 'whd' ),
			__( 'a Filipina woman, about 28, with wavy dark hair', 'whd' ),
		] );
		return $people ?: [ __( 'a woman, about 26', 'whd' ) ];
	}

	/** The line every brief carries, so no picture strays older whatever else it says. */
	public static function age_rule(): string {
		return __( 'anyone in the picture is a young woman between 22 and 32 years old, with the youthful face and skin of that age', 'whd' );
	}

	/** Somebody for a picture that belongs to no set: a different person each time, starting anywhere. */
	private static function next_person(): string {
		static $n = null;
		$people = self::people();
		if ( null === $n ) {
			$n = wp_rand( 0, count( $people ) - 1 );
		}
		return $people[ $n++ % count( $people ) ];
	}

	/**
	 * A different room for each picture in a set.
	 *
	 * Left to itself the model returns the same woman in the same window four times. Naming the
	 * variation is what makes a set of four look like a set rather than a contact sheet.
	 */
	public static function scenes(): array {
		return [
			__( 'at home in a bright apartment, morning light across the floor', 'whd' ),
			__( 'out on a quiet city street, late afternoon light', 'whd' ),
			__( 'sitting at a cafe table by a window', 'whd' ),
			__( 'in a plain studio room with a chair and a plant', 'whd' ),
			__( 'browsing a shop window, seen from a little way off', 'whd' ),
			__( 'on a doorstep with a bag over her shoulder, about to leave', 'whd' ),
		];
	}

	/** What the picture has to show, written for a model rather than for a reader. */
	public static function prompt( $product, int $index, bool $beauty, string $ask = '' ): string {
		$scenes = self::scenes();
		$people = self::people();
		// Offset by the product, so the second picture of every product is not the same woman.
		$person = $people[ ( $index + (int) $product->get_id() ) % count( $people ) ];
		$scene  = $person . ', ' . $scenes[ $index % count( $scenes ) ];

		$facts = array_filter( [
			$product->get_name(),
			wp_strip_all_tags( (string) $product->get_short_description() ),
		] );

		$subject = $beauty
			/*
			 * Turned a little away on purpose: a model drawing a label square to the lens invents
			 * lettering, and invented lettering on a real brand's tube is the one thing that makes
			 * a picture like this unusable.
			 */
			? __( 'Show her using the product the way somebody actually would — holding it, or smoothing it into her arm or face — with the packaging the same shape and the same colours as the reference images, held at a slight angle so the label reads as shape and colour rather than as words.', 'whd' )
			: __( 'Show her wearing the garment from the reference images. It must be the same garment: same colour, same pattern, same length, same trims, same fastenings. The rest of the outfit is plain and quiet so the piece is what you look at.', 'whd' );

		return sprintf(
			"A new photograph for an online boutique, of a product we already have pictures of.\n\n"
			. "%5\$s"
			. "PRODUCT\n%1\$s\n\nWHO AND WHERE\n%2\$s\n\nWHAT TO SHOW\n%3\$s\n\nTHE LOOK\n%4\$s\n\n"
			. "RULES\n- one single photograph, portrait 4:5, nothing split or collaged\n"
			. "- a different person from the reference images and from the others in this set: different face, different hair, different build\n"
			. '- ' . self::age_rule() . "\n"
			. "- relaxed and unposed, not looking into the lens\n"
			. "- frame her whole head: the top of the hair is inside the picture, and the face is not cut off by any edge\n"
			. "- no text, no lettering, no logo, no watermark, no border\n"
			. "- hands, faces and seams all intact; nothing distorted\n"
			. '- it has to look like a photograph, not a rendering or an illustration',
			implode( "\n", $facts ),
			$scene,
			$subject,
			self::look(),
			self::asked( $ask )
		);
	}

	/* ─────────────────────────── making them ─────────────────────────── */

	/**
	 * Draw the missing pictures for a product and attach them.
	 *
	 * @param int $product_id Product to work on.
	 * @param int $count      How many to make. Defaults to however many rows are short.
	 * @return array|WP_Error { made: int[], skipped: string[] }
	 */
	public static function fill( $product_id, $count = 0, string $ask = '' ) {
		if ( ! self::ready() ) {
			return new WP_Error( 'whd_ai_images_off', __( 'Drawing pictures needs a Google AI key. Add one in WHD → AI content.', 'whd' ) );
		}
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			return new WP_Error( 'whd_ai_images_product', __( 'That is not a product.', 'whd' ) );
		}

		$have = self::gallery( $product_id );
		if ( ! $have ) {
			return new WP_Error(
				'whd_ai_images_blank',
				__( 'Add one real photograph first. Everything here is drawn from the pictures you already have — with none, the model would invent a product that is not yours.', 'whd' )
			);
		}

		$count = $count > 0 ? min( (int) $count, self::ROWS ) : self::missing( $product_id );
		if ( ! $count ) {
			return [ 'made' => [], 'skipped' => [ __( 'Every row already has a photograph.', 'whd' ) ] ];
		}

		$beauty  = self::is_beauty( $product_id );
		$made    = [];
		$skipped = [];

		for ( $i = 0; $i < $count; $i++ ) {
			// Count from the pictures already there, so a second run casts somebody new again.
			$image = self::draw( $product, self::prompt( $product, count( $have ) + $i, $beauty, $ask ) );
			if ( is_wp_error( $image ) ) {
				$skipped[] = $image->get_error_message();
				continue;
			}
			$id = self::attach( $image, $product, count( $have ) + $i + 1, $beauty );
			if ( is_wp_error( $id ) ) {
				$skipped[] = $id->get_error_message();
				continue;
			}
			$made[] = $id;
		}

		if ( $made ) {
			self::add_to_gallery( $product, $made );
			// The story keeps the picture it was given when it was written, so a row that had
			// nothing stays empty until it is handed one. Drawing a picture nobody can see would
			// be a strange place to stop.
			self::refresh_story( $product_id );
		}

		return [ 'made' => $made, 'skipped' => $skipped ];
	}

	/**
	 * Hand the story's empty rows the pictures it now has.
	 *
	 * The design stores a URL per row rather than looking the gallery up as it renders, so a
	 * picture added afterwards is invisible until a row is given it. A row keeps whatever it
	 * already has; only the empty ones are filled, in gallery order, skipping anything the story
	 * is already showing.
	 *
	 * @return int How many rows were given a picture.
	 */
	public static function refresh_story( $product_id ): int {
		if ( ! class_exists( 'WHD_Product_Story' ) || ! WHD_Product_Story::has( $product_id ) ) {
			return 0;
		}
		$design = WHD_Product_Story::get( $product_id );
		$blocks = (array) ( $design['blocks'] ?? [] );
		if ( ! $blocks ) {
			return 0;
		}

		$used = [];
		foreach ( $blocks as $block ) {
			if ( 'image' === ( $block['type'] ?? '' ) && ! empty( $block['props']['url'] ) ) {
				// Query strings are cache-busters the import adds, not part of the file.
				$used[] = strtok( (string) $block['props']['url'], '?' );
			}
		}

		$spare = [];
		foreach ( self::gallery( $product_id ) as $id ) {
			$url = wp_get_attachment_image_url( $id, 'large' );
			if ( $url && ! in_array( strtok( $url, '?' ), $used, true ) ) {
				$spare[] = [ 'url' => $url, 'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ];
			}
		}
		if ( ! $spare ) {
			return 0;
		}

		$before = count( $blocks );
		$blocks = self::spread( $blocks, array_map(
			static fn( $p ) => [ 'type' => 'image', 'props' => [ 'url' => $p['url'], 'alt' => $p['alt'], 'width' => 100 ] ],
			$spare
		) );
		$filled = count( $blocks ) - $before;

		if ( $filled ) {
			$design['blocks'] = $blocks;
			WHD_Product_Story::save( $product_id, $design );
		}

		return $filled;
	}

	/**
	 * Put one picture in each section that has none, in order, and keep the rest.
	 *
	 * A heading opens a section and it runs to the next heading. The band reads a section's picture
	 * as the one on the other side of its words, so a heap of images at the end of the design is a
	 * heap of pictures nobody sees — which is exactly what happens when a rewritten story keeps the
	 * old ones by appending them.
	 *
	 * @param array $blocks The design's blocks.
	 * @param array $images Image blocks to place.
	 * @return array The blocks, with the pictures distributed.
	 */
	public static function spread( array $blocks, array $images ): array {
		if ( ! $images ) {
			return $blocks;
		}

		$rows  = [];
		$start = null;
		foreach ( $blocks as $i => $block ) {
			if ( 'heading' !== ( $block['type'] ?? '' ) ) {
				continue;
			}
			if ( null !== $start ) {
				$rows[] = [ $start, $i - 1 ];
			}
			$start = $i;
		}
		if ( null !== $start ) {
			$rows[] = [ $start, count( $blocks ) - 1 ];
		}
		if ( ! $rows ) {
			return array_merge( $blocks, $images );
		}

		$empty = [];
		foreach ( $rows as $row ) {
			$has = false;
			for ( $i = $row[0]; $i <= $row[1]; $i++ ) {
				$has = $has || 'image' === ( $blocks[ $i ]['type'] ?? '' );
			}
			if ( ! $has ) {
				$empty[] = $row;
			}
		}

		/*
		 * From the top down when there are not enough to go round: the first section is the one
		 * everybody reads, so a story one picture short should be short at the bottom.
		 */
		$take  = array_slice( $empty, 0, count( $images ) );
		$left  = array_slice( $images, count( $take ) );
		$pairs = array_combine( array_keys( $take ), $take );

		// Spliced back to front, so inserting into one row does not move the ones still to do.
		foreach ( array_reverse( $pairs, true ) as $n => $row ) {
			array_splice( $blocks, $row[1] + 1, 0, [ $images[ $n ] ] );
		}

		// Anything over goes on the end rather than being thrown away.
		return $left ? array_merge( $blocks, array_values( $left ) ) : $blocks;
	}

	/** Skincare is held and applied; clothes are worn. The prompt needs to know which. */
	private static function is_beauty( $product_id ): bool {
		$beauty = [ 'skincare', 'cleansers', 'toners', 'serums', 'moisturizers', 'eye-care', 'skincare-sets', 'sun-care', 'masks' ];
		foreach ( (array) wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'slugs' ] ) as $slug ) {
			if ( in_array( $slug, $beauty, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Draw a picture that belongs to no product and put it in the library — for a journal post or
	 * a page.
	 *
	 * With nothing but words it leans on the house look. Given photographs of the shop's own pieces
	 * (`refs`), it draws those pieces as they really are: on somebody, or — `mode` mannequin — on a
	 * ghost mannequin with nobody in the picture at all, the way the product screen draws them.
	 *
	 * @param string $prompt  What to draw, in a sentence or two.
	 * @param string $name    File name stem.
	 * @param string $alt     Alt text for the library.
	 * @param string $ask     What the shop asked for this picture, which wins over the rest.
	 * @param array  $options aspect ("16:9"), size ("1K"|"2K"|"4K"), max_edge (how wide to keep the
	 *                        stored file), quality, refs (attachment ids of the pieces to show),
	 *                        mode ("model" | "mannequin"), frame (a key of frames(): drawn for that
	 *                        size and cut to it exactly, in place of aspect, size and max_edge).
	 * @return int|WP_Error Attachment id.
	 */
	public static function make( string $prompt, string $name = 'illustration', string $alt = '', string $ask = '', array $options = [] ) {
		if ( ! self::ready() ) {
			return new WP_Error( 'whd_ai_images_off', __( 'Drawing pictures needs a Google AI key. Add one in WHD → AI content.', 'whd' ) );
		}

		$frame = self::frame( $options['frame'] ?? '' );
		$ratio = $frame ? $frame['aspect'] : (string) ( $options['aspect'] ?? '' );
		$shape = $frame ? self::framing( $frame ) : ( '' === $ratio || '3:2' === $ratio ? 'landscape 3:2' : 'aspect ratio ' . $ratio );

		/* The pieces to show, as the model's first images: a picture of the real thing beats any description of it. */
		$parts  = [];
		$labels = [];
		foreach ( array_slice( array_values( array_filter( array_map( 'absint', (array) ( $options['refs'] ?? [] ) ) ) ), 0, 3 ) as $ref ) {
			$part = WHD_AI::picture_part( $ref, 1536 );
			if ( $part ) {
				$parts[]  = [ 'inlineData' => [ 'mimeType' => $part['mime'], 'data' => $part['data'] ] ];
				$labels[] = sprintf( 'Image %d: a photograph of the real piece, from the shop.', count( $parts ) );
			}
		}
		$piece = $labels
			? "THE PIECE\n" . implode( "\n", $labels ) . "\nWhatever is worn or shown is exactly this piece: the same colour and pattern at the same scale, the same length, neckline, collar, sleeves, fastenings in the same number, the same trims — nothing added, nothing left off. Colours come from these photographs, not from any word in this brief. The photographs are the truth about it.\n\n"
			: '';

		if ( $labels && 'mannequin' === ( $options['mode'] ?? '' ) ) {
			$brief = "A product photograph for an online boutique's journal.\n\n" . $piece . self::asked( $ask )
				. "WHAT IT IS FOR\n" . trim( $prompt ) . "\n\n"
				. "HOW IT IS SHOWN\nA ghost-mannequin product photograph: the piece as if worn by an invisible body — natural shape and volume, the inside of the back neckline visible through the neck opening, sleeves filled out, fastenings done up the way the photographs show them. The whole piece in frame and centred; front view unless asked otherwise. A seamless warm off-white background, soft even studio light, a soft shadow beneath.\n\n"
				. "RULES\n- one single photograph, " . $shape . ", nothing split or collaged\n"
				. "- no person, no mannequin, no stand, no hanger, no props\n"
				. "- no added text, lettering, logo, watermark or border — print that is part of the piece stays as the photographs show it\n"
				. '- a real photograph, not a rendering or an illustration';
		} else {
			$brief = sprintf(
				"A photograph for an online boutique's journal.\n\n%5\$s%3\$sWHAT TO SHOW\n%1\$s\n\nIF SOMEBODY IS IN IT\n%4\$s\n\nTHE LOOK\n%2\$s\n\n"
				. "RULES\n- one single photograph, " . $shape . ", nothing split or collaged\n"
				. '- ' . self::age_rule() . "\n"
				. "- if somebody is in the picture, her whole head is in it too: not cropped at the top, not cut off by an edge\n"
				. ( $labels ? "- she wears the piece from the photographs, and nothing she wears or holds covers its details; a different person from anyone in them\n" : '' )
				. "- no text, no lettering, no logo, no watermark, no border\n"
				. "- a real photograph, not a rendering or an illustration\n"
				. '- nobody looking into the lens',
				trim( $prompt ),
				self::look(),
				self::asked( $ask ),
				self::next_person(),
				$piece
			);
		}
		$parts[] = [ 'text' => $brief ];

		$image = self::request(
			$parts,
			array_filter( [
				'aspectRatio' => $ratio,
				'imageSize'   => $frame ? $frame['size'] : ( $options['size'] ?? self::size() ),
			] )
		);
		if ( is_wp_error( $image ) ) {
			return $image;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$ext  = 'image/png' === $image['mime'] ? 'png' : 'jpg';
		$file = wp_upload_bits( sanitize_file_name( $name . '-' . wp_generate_password( 5, false ) . '.' . $ext ), null, $image['data'] );
		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'whd_ai_images_write', $file['error'] );
		}

		$id = wp_insert_attachment( [
			'post_mime_type' => $image['mime'],
			'post_title'     => $alt ?: $name,
			'post_status'    => 'inherit',
		], $file['file'] );
		if ( is_wp_error( $id ) || ! $id ) {
			return is_wp_error( $id ) ? $id : new WP_Error( 'whd_ai_images_attach', __( 'The picture could not be added to the media library.', 'whd' ) );
		}

		self::settle(
			(int) $id,
			$file['file'],
			$alt ?: $name,
			'',
			(int) ( $options['max_edge'] ?? self::MAX_EDGE ),
			(int) ( $options['quality'] ?? self::QUALITY ),
			$frame ?: []
		);
		update_post_meta( $id, self::META, 1 );

		return (int) $id;
	}

	/**
	 * One call to the image model.
	 *
	 * @param array $parts        inlineData references and the text brief, in reading order.
	 * @param array $image_config aspectRatio ("16:9", "4:5"…) and imageSize ("1K", "2K", "4K").
	 *                            Sending nothing gets whatever the model feels like, which is
	 *                            about 1400 across — fine for a product card, not nearly enough
	 *                            for a photograph that runs the full width of a screen.
	 * @return array|WP_Error { data: string, mime: string }
	 */
	public static function request( array $parts, array $image_config = [] ) {
		$key = (string) WHD_AI::get()['google_key'];

		$response = wp_remote_post(
			'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( self::model() ) . ':generateContent?key=' . rawurlencode( $key ),
			[
				// Drawing takes far longer than writing, and a 4K frame is eight megabytes of it.
				'timeout' => 300,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( array_filter( [
					'contents'         => [ [ 'role' => 'user', 'parts' => $parts ] ],
					'generationConfig' => $image_config ? [ 'imageConfig' => $image_config ] : null,
				] ) ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'whd_ai_images_http', sprintf( '%d — %s', $code, $body['error']['message'] ?? __( 'the image model refused', 'whd' ) ) );
		}

		foreach ( (array) ( $body['candidates'][0]['content']['parts'] ?? [] ) as $part ) {
			if ( ! empty( $part['inlineData']['data'] ) ) {
				return [
					'data' => base64_decode( $part['inlineData']['data'] ),
					'mime' => (string) ( $part['inlineData']['mimeType'] ?? 'image/jpeg' ),
				];
			}
		}

		// A refusal comes back as words where the picture should be, so pass it on rather than
		// reporting an empty success.
		$said = trim( (string) ( $body['candidates'][0]['content']['parts'][0]['text'] ?? '' ) );

		return new WP_Error( 'whd_ai_images_empty', $said ?: __( 'The model sent no picture back.', 'whd' ) );
	}

	/**
	 * One call to the image model: the pictures we have, then the brief.
	 *
	 * @return array|WP_Error { data: string, mime: string }
	 */
	private static function draw( $product, string $prompt ) {
		$parts = [];

		// Three references is enough to pin the garment down and keeps the request a sensible size.
		foreach ( array_slice( self::gallery( $product->get_id() ), 0, 3 ) as $id ) {
			$file = get_attached_file( $id );
			if ( ! $file || ! file_exists( $file ) || filesize( $file ) > 6 * MB_IN_BYTES ) {
				continue;
			}
			$parts[] = [
				'inlineData' => [
					'mimeType' => get_post_mime_type( $id ) ?: 'image/jpeg',
					'data'     => base64_encode( (string) file_get_contents( $file ) ),
				],
			];
		}
		if ( ! $parts ) {
			return new WP_Error( 'whd_ai_images_refs', __( 'None of this product’s pictures could be read from disk.', 'whd' ) );
		}
		$parts[] = [ 'text' => $prompt ];

		/* This was a second copy of request() that read an $image_config it never defined. */
		return self::request( $parts );
	}

	/** Put a drawn picture in the media library, named and described like the rest. */
	private static function attach( array $image, $product, int $position, bool $beauty = false ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$ext  = 'image/png' === $image['mime'] ? 'png' : 'jpg';
		$name = sanitize_file_name( sprintf( '%s-%d.%s', $product->get_slug() ?: 'product', $position, $ext ) );
		$file = wp_upload_bits( $name, null, $image['data'] );
		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'whd_ai_images_write', $file['error'] );
		}

		$alt = sprintf(
			$beauty
				/* translators: %s: product name */
				? __( '%s, in use at home', 'whd' )
				/* translators: %s: product name */
				: __( '%s, worn at home', 'whd' ),
			$product->get_name()
		);

		$id = wp_insert_attachment(
			[
				'post_mime_type' => $image['mime'],
				'post_title'     => $product->get_name(),
				'post_content'   => '',
				'post_status'    => 'inherit',
			],
			$file['file'],
			$product->get_id()
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return is_wp_error( $id ) ? $id : new WP_Error( 'whd_ai_images_attach', __( 'The picture could not be added to the media library.', 'whd' ) );
		}

		$terms = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] );
		$leaf  = ! is_wp_error( $terms ) && $terms ? html_entity_decode( (string) end( $terms ), ENT_QUOTES, 'UTF-8' ) : '';
		self::settle(
			(int) $id,
			$file['file'],
			$alt,
			sprintf(
				/* translators: 1: product name, 2: category, 3: the shop's name */
				__( '%1$s%2$s from %3$s, drawn in the look of our own photographs.', 'whd' ),
				$product->get_name(),
				$leaf ? ', ' . lcfirst( $leaf ) : '',
				rtrim( (string) get_bloginfo( 'name' ), '.' )
			)
		);
		// So a later audit can tell at a glance which pictures were drawn and which were taken.
		update_post_meta( $id, self::META, 1 );

		return (int) $id;
	}

	/**
	 * Settle a freshly drawn picture into the library: weight, alt, description.
	 *
	 * What comes back from the model is a full-size PNG or JPEG around 700 KB — three quarters of
	 * which is detail nobody looking at a 600px product card will ever see, paid for by whoever
	 * is on a phone. It is re-encoded to the same long edge the rest of the catalogue uses before
	 * the thumbnails are built, so every derived size comes from the lighter original.
	 *
	 * The description is the field the media library leaves blank and nothing else fills. It is
	 * written from what the picture is of, because that is the only thing we actually know.
	 *
	 * @param int    $id    Attachment just inserted.
	 * @param string $file  Path on disk.
	 * @param string $alt   What the picture shows.
	 * @param string $desc  Longer sentence, or empty to derive one from the alt.
	 * @param array  $frame A size from frame(): the picture is cut and scaled to exactly that instead.
	 */
	public static function settle( int $id, string $file, string $alt, string $desc = '', int $max_edge = 0, int $quality = 0, array $frame = [] ) {
		$max_edge = $max_edge ?: self::MAX_EDGE;
		$quality  = $quality ?: self::QUALITY;
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$editor = wp_get_image_editor( $file );
		if ( ! is_wp_error( $editor ) ) {
			$size = $editor->get_size();
			if ( ! empty( $frame['w'] ) && ! empty( $frame['h'] ) ) {
				self::trim( $editor, (int) $frame['w'], (int) $frame['h'] );
			} elseif ( max( (int) $size['width'], (int) $size['height'] ) > $max_edge ) {
				$editor->resize(
					$size['width'] >= $size['height'] ? $max_edge : null,
					$size['height'] > $size['width'] ? $max_edge : null,
					false
				);
			}
			$editor->set_quality( $quality );
			$saved = $editor->save( $file, (string) get_post_mime_type( $id ) );
			if ( is_wp_error( $saved ) ) {
				// A picture that will not re-encode is still a picture; keep the original.
				$saved = null;
			}
		}

		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );

		if ( '' === $desc ) {
			$desc = sprintf(
				/* translators: 1: what the picture shows, 2: the shop's name */
				__( '%1$s. Drawn for %2$s in the look of our own photographs.', 'whd' ),
				rtrim( $alt, '.' ),
				rtrim( (string) get_bloginfo( 'name' ), '.' )
			);
		}
		wp_update_post( [ 'ID' => $id, 'post_content' => $desc ] );
	}

	/** Append, never replace: a drawn picture goes after everything somebody photographed. */
	private static function add_to_gallery( $product, array $ids ) {
		if ( ! $product->get_image_id() ) {
			$product->set_image_id( (int) array_shift( $ids ) );
		}
		if ( $ids ) {
			$product->set_gallery_image_ids( array_values( array_unique( array_merge(
				array_map( 'intval', $product->get_gallery_image_ids() ),
				array_map( 'intval', $ids )
			) ) ) );
		}
		$product->save();
	}
}
