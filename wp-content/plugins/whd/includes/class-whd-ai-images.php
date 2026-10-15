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
	 * A different person and a different room for each picture in a set.
	 *
	 * Left to itself the model returns the same woman in the same window four times. Naming the
	 * variation is what makes a set of four look like a set rather than a contact sheet.
	 */
	private static function casting(): array {
		return [
			__( 'a woman in her twenties, at home in a bright apartment, morning light across the floor', 'whd' ),
			__( 'a woman in her thirties, out on a quiet city street, late afternoon light', 'whd' ),
			__( 'a woman in her forties, sitting at a cafe table by a window', 'whd' ),
			__( 'a woman in her fifties, in a plain studio room with a chair and a plant', 'whd' ),
			__( 'a woman browsing a shop window, seen from a little way off', 'whd' ),
			__( 'a woman on a doorstep with a bag over her shoulder, about to leave', 'whd' ),
		];
	}

	/** What the picture has to show, written for a model rather than for a reader. */
	public static function prompt( $product, int $index, bool $beauty, string $ask = '' ): string {
		$casting = self::casting();
		$scene   = $casting[ $index % count( $casting ) ];

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
			. "- a different person from the reference images and from the others in this set: different age, different hair, different build\n"
			. "- relaxed and unposed, not looking into the lens\n"
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
	 * Draw a picture that belongs to no product and put it in the library.
	 *
	 * The product path above always has the shop's own photographs to copy from. This one has
	 * only words, so it leans on the house look instead — it is for a journal post or a page,
	 * where nothing exists yet to be faithful to.
	 *
	 * @param string $prompt What to draw, in a sentence or two.
	 * @param string $name   File name stem.
	 * @param string $alt    Alt text for the library.
	 * @return int|WP_Error Attachment id.
	 */
	public static function make( string $prompt, string $name = 'illustration', string $alt = '', string $ask = '' ) {
		if ( ! self::ready() ) {
			return new WP_Error( 'whd_ai_images_off', __( 'Drawing pictures needs a Google AI key. Add one in WHD → AI content.', 'whd' ) );
		}

		$brief = sprintf(
			"A photograph for an online boutique's journal.\n\n%3\$sWHAT TO SHOW\n%1\$s\n\nTHE LOOK\n%2\$s\n\n"
			. "RULES\n- one single photograph, landscape 3:2, nothing split or collaged\n"
			. "- no text, no lettering, no logo, no watermark, no border\n"
			. "- a real photograph, not a rendering or an illustration\n"
			. '- nobody looking into the lens',
			trim( $prompt ),
			self::look(),
			self::asked( $ask )
		);

		$image = self::request( [ [ 'text' => $brief ] ] );
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

		self::settle( (int) $id, $file['file'], $alt ?: $name );
		update_post_meta( $id, self::META, 1 );

		return (int) $id;
	}

	/**
	 * One call to the image model.
	 *
	 * @param array $parts inlineData references and the text brief, in the order the model reads them.
	 * @return array|WP_Error { data: string, mime: string }
	 */
	private static function request( array $parts ) {
		$key = (string) WHD_AI::get()['google_key'];

		$response = wp_remote_post(
			'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( self::model() ) . ':generateContent?key=' . rawurlencode( $key ),
			[
				// Drawing takes far longer than writing; the default thirty seconds times out mid-picture.
				'timeout' => 180,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [ 'contents' => [ [ 'role' => 'user', 'parts' => $parts ] ] ] ),
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
		$key   = (string) WHD_AI::get()['google_key'];
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

		$response = wp_remote_post(
			'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( self::model() ) . ':generateContent?key=' . rawurlencode( $key ),
			[
				// Drawing takes far longer than writing; the default thirty seconds times out mid-picture.
				'timeout' => 180,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [ 'contents' => [ [ 'role' => 'user', 'parts' => $parts ] ] ] ),
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
	 * @param int    $id   Attachment just inserted.
	 * @param string $file Path on disk.
	 * @param string $alt  What the picture shows.
	 * @param string $desc Longer sentence, or empty to derive one from the alt.
	 */
	private static function settle( int $id, string $file, string $alt, string $desc = '' ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$editor = wp_get_image_editor( $file );
		if ( ! is_wp_error( $editor ) ) {
			$size = $editor->get_size();
			if ( max( (int) $size['width'], (int) $size['height'] ) > self::MAX_EDGE ) {
				$editor->resize(
					$size['width'] >= $size['height'] ? self::MAX_EDGE : null,
					$size['height'] > $size['width'] ? self::MAX_EDGE : null,
					false
				);
			}
			$editor->set_quality( self::QUALITY );
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
