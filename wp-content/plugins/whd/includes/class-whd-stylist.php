<?php
/**
 * The AI stylist.
 *
 * A short wizard that asks a few things a shop assistant would ask — how tall you are, what you
 * already reach for, what you are dressing for — and answers with pieces from this catalogue and a
 * line on why each one. It opens by asking for a name and an email, which goes to the newsletter
 * list with source `stylist`, because a recommendation is worth an address and this is the one
 * moment someone is glad to give it.
 *
 * The matching is done here, in PHP, against the real catalogue: attributes, categories, prices and
 * stock. A shortlist of the best scoring pieces is then handed to the model, which orders them and
 * writes the reason lines. That order matters — the model never invents a product, because it only
 * ever sees pieces that exist and is asked to choose among them. With no key connected the
 * shortlist is used as scored and the reasons are written from the same rules, so the wizard works
 * on a shop that has not connected anything.
 *
 * Bots: reCAPTCHA when a key pair is configured, and regardless of that a honeypot, a minimum time
 * on the first step, a per-address limit and a nonce. The email is the thing being protected;
 * everything after it is cheap.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Stylist {

	const DB_VERSION = '1.0';
	const OPTION_ON  = 'whd_stylist_enabled';

	/** How many pieces a session is shown. */
	const PICKS = 6;

	/** What v3 tokens are minted for, so one lifted from another form on the site does not pass here. */
	const CAPTCHA_ACTION = 'whd_stylist';

	/** Nobody fills in a name, an email and a captcha in under this. A bot does. */
	const MIN_SECONDS = 3;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'whd_stylist';
	}

	/* ─────────────────────────── install ─────────────────────────── */

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				email VARCHAR(191) NOT NULL,
				name VARCHAR(100) NOT NULL DEFAULT '',
				answers LONGTEXT NULL,
				picks LONGTEXT NULL,
				used_ai TINYINT(1) NOT NULL DEFAULT 0,
				created DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY email (email),
				KEY created (created)
			) " . $wpdb->get_charset_collate() . ';'
		);
		update_option( 'whd_stylist_db', self::DB_VERSION, false );
	}

	public static function maybe_install() {
		if ( get_option( 'whd_stylist_db' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function enabled() {
		return (bool) apply_filters( 'whd_stylist_enabled', get_option( self::OPTION_ON, '1' ) === '1' );
	}

	public static function init() {
		add_action( 'admin_init', [ __CLASS__, 'maybe_install' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'wp_footer', [ __CLASS__, 'render' ], 6 );
		add_action( 'wp_ajax_whd_stylist_finish', [ __CLASS__, 'ajax_finish' ] );
		add_action( 'wp_ajax_nopriv_whd_stylist_finish', [ __CLASS__, 'ajax_finish' ] );
	}

	/* ─────────────────────────── the questions ─────────────────────────── */

	/**
	 * What the wizard asks.
	 *
	 * Chosen for what the catalogue can actually answer: height and fit decide sizing advice,
	 * colour and silhouette narrow the rail, occasion picks the category, and the last two set how
	 * loud and how expensive. Nothing is asked that the recommendation cannot then use — a question
	 * whose answer changes nothing is a question that wastes a visitor's patience.
	 *
	 * @return array Steps of [ key, type, question, help, options ].
	 */
	public static function questions() {
		return (array) apply_filters( 'whd_stylist_questions', [
			[
				'key'      => 'height',
				'type'     => 'single',
				'question' => __( 'How tall are you?', 'whd' ),
				'help'     => __( 'Sourcing from Korea and Thailand runs short, so this changes what we suggest more than you would think.', 'whd' ),
				'options'  => [
					'petite'  => __( 'Under 5\'3" (160 cm)', 'whd' ),
					'average' => __( '5\'3" – 5\'7" (160–170 cm)', 'whd' ),
					'tall'    => __( '5\'8" and over (173 cm+)', 'whd' ),
				],
			],
			[
				'key'      => 'fit',
				'type'     => 'single',
				'question' => __( 'How do you like things to sit?', 'whd' ),
				'help'     => __( 'There is no right answer — it just decides which half of the rail we look at.', 'whd' ),
				'options'  => [
					'fitted'     => __( 'Close to the body', 'whd' ),
					'easy'       => __( 'Easy and relaxed', 'whd' ),
					'structured' => __( 'Structured, with a shape of its own', 'whd' ),
					'mixed'      => __( 'Depends on the day', 'whd' ),
				],
			],
			[
				'key'      => 'colours',
				'type'     => 'multi',
				'question' => __( 'Which of these do you actually wear?', 'whd' ),
				'help'     => __( 'Pick as many as are true. Be honest rather than aspirational.', 'whd' ),
				'options'  => [
					'black'   => __( 'Black and charcoal', 'whd' ),
					'cream'   => __( 'Cream, ivory, oat', 'whd' ),
					'earth'   => __( 'Browns, rust, olive', 'whd' ),
					'pastel'  => __( 'Soft pinks and blues', 'whd' ),
					'bright'  => __( 'Something with colour in it', 'whd' ),
					'print'   => __( 'Prints and florals', 'whd' ),
				],
			],
			[
				'key'      => 'occasion',
				'type'     => 'multi',
				'question' => __( 'What are you dressing for?', 'whd' ),
				'help'     => __( 'Choose everything that applies over the next month or two.', 'whd' ),
				'options'  => [
					'everyday' => __( 'Ordinary days', 'whd' ),
					'work'     => __( 'Work', 'whd' ),
					'evening'  => __( 'Dinners and evenings out', 'whd' ),
					'event'    => __( 'A wedding or an occasion', 'whd' ),
					'travel'   => __( 'Travelling', 'whd' ),
				],
			],
			[
				'key'      => 'statement',
				'type'     => 'single',
				'question' => __( 'How much do you want to be noticed?', 'whd' ),
				'help'     => '',
				'options'  => [
					'quiet'  => __( 'Quietly — good pieces, no announcements', 'whd' ),
					'middle' => __( 'One thing worth commenting on', 'whd' ),
					'loud'   => __( 'Give me the piece people ask about', 'whd' ),
				],
			],
			[
				'key'      => 'budget',
				'type'     => 'single',
				'question' => __( 'What feels comfortable to spend on one piece?', 'whd' ),
				'help'     => __( 'We will stay inside it.', 'whd' ),
				'options'  => [
					'low'  => __( 'Keep it modest', 'whd' ),
					'mid'  => __( 'The middle of the rail', 'whd' ),
					'high' => __( 'For the right piece, anything', 'whd' ),
				],
			],
		] );
	}

	/* ─────────────────────────── the one call ─────────────────────────── */

	/**
	 * Answers in, an edit out.
	 *
	 * Everything happens here because everything depends on the same check: the details are only
	 * worth keeping if the visitor is real, and the edit is only worth computing if the details
	 * are. Order matters — refuse first, subscribe second, recommend third, send last, so a bot
	 * never reaches the catalogue and a failed send never costs someone their recommendations.
	 */
	public static function ajax_finish() {
		check_ajax_referer( 'whd_stylist', 'nonce' );
		self::maybe_install();

		// A field a person never sees and a script always fills.
		if ( ! empty( $_POST['website'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Something went wrong. Please try again.', 'whd' ) ] );
		}
		if ( ( isset( $_POST['elapsed'] ) ? (int) $_POST['elapsed'] : 0 ) < self::MIN_SECONDS ) {
			wp_send_json_error( [ 'message' => __( 'That was quick. Take another moment and try again.', 'whd' ) ] );
		}
		if ( ! WHD_AI::recaptcha_verify( isset( $_POST['captcha'] ) ? wp_unslash( $_POST['captcha'] ) : '', self::CAPTCHA_ACTION ) ) {
			wp_send_json_error( [
				'field'   => 'captcha',
				'message' => __( 'The robot check did not pass. Tick it again and resend.', 'whd' ),
			] );
		}

		$first = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
		$last  = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );

		if ( mb_strlen( $first ) < 2 ) {
			wp_send_json_error( [ 'field' => 'first_name', 'message' => __( 'Your first name, as you would like it written.', 'whd' ) ] );
		}
		if ( mb_strlen( $last ) < 2 ) {
			wp_send_json_error( [ 'field' => 'last_name', 'message' => __( 'And your last name.', 'whd' ) ] );
		}
		if ( ! is_email( $email ) ) {
			wp_send_json_error( [ 'field' => 'email', 'message' => __( 'That does not look like an email address.', 'whd' ) ] );
		}
		if ( self::too_many( $email ) ) {
			wp_send_json_error( [ 'field' => 'email', 'message' => __( 'You have had a few of these today. Try again tomorrow.', 'whd' ) ] );
		}

		$raw     = isset( $_POST['answers'] ) ? json_decode( wp_unslash( $_POST['answers'] ), true ) : [];
		$answers = self::clean_answers( is_array( $raw ) ? $raw : [] );
		if ( ! $answers ) {
			wp_send_json_error( [ 'message' => __( 'No answers came through. Start the wizard again.', 'whd' ) ] );
		}

		$name = trim( $first . ' ' . $last );
		WHD_Subscribers::add( $email, $name, 'stylist' );

		$result = self::recommend( $answers );
		$picks  = $result['picks'];

		global $wpdb;
		$wpdb->insert( self::table(), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'email'   => strtolower( $email ),
			'name'    => $name,
			'answers' => wp_json_encode( $answers ),
			'picks'   => wp_json_encode( wp_list_pluck( $picks, 'id' ) ),
			'used_ai' => ! empty( $result['used_ai'] ) ? 1 : 0,
			'created' => current_time( 'mysql' ),
		] );

		$mailed = $picks ? self::send_edit( $email, $first, $picks, $result['intro'] ) : false;

		wp_send_json_success( [
			'picks'   => $picks,
			'intro'   => $result['intro'],
			'greeting' => sprintf( /* translators: %s: first name */ __( 'Here you are, %s.', 'whd' ), $first ),
			'mailed'  => (bool) $mailed,
			'mailNote' => $mailed
				/* translators: %s: email address */
				? sprintf( __( 'A copy is on its way to %s.', 'whd' ), $email )
				: __( 'Keep this page open — we could not get the email out just now.', 'whd' ),
			'shopUrl' => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' ),
		] );
	}

	/**
	 * Send the edit.
	 *
	 * Uses the designable `stylist_edit` trigger, with the picks fed in as the item list so the
	 * same block that shows an abandoned bag shows the edit — pictures, names and prices, already
	 * styled for an inbox.
	 */
	private static function clean_answers( array $raw ) {
		$out = [];
		foreach ( self::questions() as $q ) {
			$given = $raw[ $q['key'] ] ?? null;
			if ( 'multi' === $q['type'] ) {
				$given = array_values( array_intersect( array_map( 'sanitize_key', (array) $given ), array_keys( $q['options'] ) ) );
				if ( $given ) {
					$out[ $q['key'] ] = $given;
				}
				continue;
			}
			$given = sanitize_key( (string) $given );
			if ( isset( $q['options'][ $given ] ) ) {
				$out[ $q['key'] ] = $given;
			}
		}

		return $out;
	}

	/** Five sessions an address a day is generous for a person and tedious for a script. */
	private static function too_many( $email ) {
		global $wpdb;
		$n = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'SELECT COUNT(*) FROM ' . self::table() . ' WHERE email = %s AND created > %s',
			strtolower( $email ),
			gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
		) );

		return $n >= 5;
	}

	public static function send_edit( $email, $first, array $picks, $intro ) {
		$items = [];
		foreach ( $picks as $p ) {
			$items[] = [
				'name'         => $p['name'] . ( $p['reason'] ? ' — ' . $p['reason'] : '' ),
				'qty'          => 1,
				'total'        => $p['price'],
				'image'        => $p['image'],
				'url'          => $p['url'],
				'product_id'   => $p['id'],
				'variation_id' => 0,
			];
		}

		$ctx = WHD_Emails::context( [
			'data' => [
				'first_name'    => $first,
				'customer_name' => $first,
				'email'         => $email,
				'stylist_intro' => $intro,
			],
		] );
		$ctx['cart_items'] = $items;
		$ctx['items']      = $items;

		return (bool) WHD_Emails::send_custom( 'stylist_edit', $ctx, $email );
	}

	/* ─────────────────────────── matching ─────────────────────────── */

	/**
	 * Score the catalogue, then let the model order the shortlist.
	 *
	 * @return array [ picks, intro, used_ai ]
	 */
	public static function recommend( array $answers ) {
		$shortlist = self::shortlist( $answers, 18 );
		if ( ! $shortlist ) {
			return [ 'picks' => [], 'intro' => __( 'Nothing on the rail matches that just now — which happens when the runs are this small. Have a look at what has just landed.', 'whd' ), 'used_ai' => false ];
		}

		$ai = WHD_AI::ready() ? self::ask_model( $answers, $shortlist ) : null;
		if ( $ai ) {
			return [ 'picks' => $ai['picks'], 'intro' => $ai['intro'], 'used_ai' => true ];
		}

		$picks = array_slice( $shortlist, 0, self::PICKS );
		foreach ( $picks as &$p ) {
			$p['reason'] = self::reason( $p, $answers );
		}
		unset( $p );

		return [ 'picks' => $picks, 'intro' => self::intro( $answers ), 'used_ai' => false ];
	}

	/**
	 * The best matches in the catalogue, scored against the answers.
	 *
	 * Only published, purchasable, in-stock pieces: recommending something nobody can buy is worse
	 * than recommending nothing.
	 */
	public static function shortlist( array $answers, $limit = 18 ) {
		$products = wc_get_products( [
			'status'       => 'publish',
			'limit'        => 200,
			'stock_status' => 'instock',
			'orderby'      => 'date',
			'order'        => 'DESC',
		] );

		$prices = [];
		foreach ( $products as $p ) {
			$price = (float) $p->get_price();
			if ( $price > 0 ) {
				$prices[] = $price;
			}
		}
		sort( $prices );
		$band = static function ( $q ) use ( $prices ) {
			if ( ! $prices ) {
				return 0;
			}
			$i = (int) floor( ( count( $prices ) - 1 ) * $q );
			return (float) $prices[ $i ];
		};
		$cheap = $band( 0.34 );
		$dear  = $band( 0.67 );

		$scored = [];
		foreach ( $products as $product ) {
			$score = self::score( $product, $answers, $cheap, $dear );
			if ( $score <= 0 ) {
				continue;
			}
			$scored[] = [
				'id'     => $product->get_id(),
				'name'   => $product->get_name(),
				// Decoded, not just stripped: get_price_html() returns the currency as &#36;, and the
				// browser escapes it again on the way into the card, so it arrives as literal text.
				'price'  => html_entity_decode( wp_strip_all_tags( $product->get_price_html() ), ENT_QUOTES, 'UTF-8' ),
				'url'    => $product->get_permalink(),
				'image'  => wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ) ?: wc_placeholder_img_src(),
				'cats'   => wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] ),
				'score'  => $score,
				'reason' => '',
			];
		}

		usort( $scored, static fn( $a, $b ) => $b['score'] <=> $a['score'] );

		return array_slice( $scored, 0, $limit );
	}

	/** One product against one set of answers. */
	private static function score( WC_Product $product, array $answers, $cheap, $dear ) {
		$score = 1; // everything purchasable starts on the board
		$text  = strtolower( $product->get_name() . ' ' . wp_strip_all_tags( $product->get_short_description() ) );
		$cats  = array_map( 'strtolower', (array) wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] ) );
		$catsl = implode( ' ', $cats );

		// Colour, from the product's own colour attribute where it has one.
		$attr    = (string) $product->get_attribute( 'pa_color' );
		$colours = $attr ? array_map( 'strtolower', array_map( 'trim', explode( ',', $attr ) ) ) : [];
		$wants   = (array) ( $answers['colours'] ?? [] );
		$map     = [
			'black'  => [ 'black', 'charcoal', 'graphite', 'onyx' ],
			'cream'  => [ 'cream', 'ivory', 'oat', 'white', 'ecru', 'bone' ],
			'earth'  => [ 'brown', 'mocha', 'rust', 'olive', 'camel', 'tan', 'khaki' ],
			'pastel' => [ 'pink', 'blush', 'blue', 'lilac', 'mint', 'lavender' ],
			'bright' => [ 'red', 'green', 'yellow', 'orange', 'cobalt', 'emerald', 'fuchsia' ],
			'print'  => [ 'floral', 'print', 'paisley', 'stripe', 'check' ],
		];
		foreach ( $wants as $want ) {
			foreach ( $map[ $want ] ?? [] as $needle ) {
				if ( in_array( $needle, $colours, true ) || false !== strpos( $text, $needle ) ) {
					$score += 6;
					break 2;
				}
			}
		}

		// Occasion → the part of the rail it lives on.
		$occasion = [
			'everyday' => [ 'tee', 'top', 'jean', 'knit', 'short', 'skirt' ],
			'work'     => [ 'blouse', 'trouser', 'pant', 'blazer', 'knit', 'shirt' ],
			'evening'  => [ 'dress', 'gown', 'satin', 'silk', 'heel' ],
			'event'    => [ 'dress', 'gown', 'jumpsuit', 'set' ],
			'travel'   => [ 'knit', 'jersey', 'set', 'jumpsuit', 'tee' ],
		];
		foreach ( (array) ( $answers['occasion'] ?? [] ) as $want ) {
			foreach ( $occasion[ $want ] ?? [] as $needle ) {
				if ( false !== strpos( $text, $needle ) || false !== strpos( $catsl, $needle ) ) {
					$score += 5;
					break 2;
				}
			}
		}

		// How loud.
		$loud = [ 'sequin', 'embellish', 'ruffle', 'organza', 'feather', 'metallic', 'crystal', 'print', 'floral' ];
		$hits = 0;
		foreach ( $loud as $needle ) {
			if ( false !== strpos( $text, $needle ) ) {
				$hits++;
			}
		}
		$statement = $answers['statement'] ?? 'middle';
		if ( 'loud' === $statement ) {
			$score += min( 8, $hits * 4 );
		} elseif ( 'quiet' === $statement ) {
			$score += $hits ? -4 : 4;
		}

		// Fit, read from the words a listing uses about shape.
		$shape = [
			'fitted'     => [ 'fitted', 'bodycon', 'slim', 'pencil', 'halter', 'wrap' ],
			'easy'       => [ 'relaxed', 'oversized', 'wide', 'slip', 'a-line', 'flowy' ],
			'structured' => [ 'tailored', 'blazer', 'structured', 'pleat', 'corset' ],
		];
		foreach ( $shape[ $answers['fit'] ?? '' ] ?? [] as $needle ) {
			if ( false !== strpos( $text, $needle ) ) {
				$score += 4;
				break;
			}
		}

		// Height. Long lines are harder on a shorter frame; midi and cropped are kinder.
		$height = $answers['height'] ?? '';
		if ( 'petite' === $height ) {
			$score += preg_match( '/\b(midi|mini|crop|petite|short)\b/', $text ) ? 3 : 0;
			$score -= preg_match( '/\b(maxi|floor|long line|longline)\b/', $text ) ? 3 : 0;
		} elseif ( 'tall' === $height ) {
			$score += preg_match( '/\b(maxi|long|wide leg|floor)\b/', $text ) ? 3 : 0;
		}

		// Price comfort. Outside the band is a soft penalty, not a exclusion.
		$price = (float) $product->get_price();
		if ( $price > 0 && $cheap > 0 ) {
			$budget = $answers['budget'] ?? 'mid';
			if ( 'low' === $budget ) {
				$score += $price <= $cheap ? 6 : ( $price <= $dear ? 0 : -6 );
			} elseif ( 'mid' === $budget ) {
				$score += ( $price > $cheap && $price <= $dear ) ? 5 : 0;
			} else {
				$score += $price > $dear ? 4 : 1;
			}
		}

		// A photograph, because a recommendation without one does not land.
		$score += $product->get_image_id() ? 2 : -4;

		return $score;
	}

	/** The line under a pick when no model wrote one. */
	private static function reason( array $pick, array $answers ) {
		$bits = [];
		if ( ! empty( $answers['occasion'] ) ) {
			$labels = self::option_labels( 'occasion', $answers['occasion'] );
			if ( $labels ) {
				$bits[] = sprintf( __( 'suits %s', 'whd' ), strtolower( $labels[0] ) );
			}
		}
		if ( ! empty( $answers['fit'] ) ) {
			$fit    = self::option_labels( 'fit', [ $answers['fit'] ] );
			$bits[] = $fit ? strtolower( $fit[0] ) : '';
		}
		if ( ! empty( $pick['cats'] ) ) {
			$bits[] = strtolower( $pick['cats'][0] );
		}
		$bits = array_values( array_filter( $bits ) );

		return $bits
			/* translators: %s: a short list of reasons, e.g. "suits work, easy and relaxed" */
			? ucfirst( implode( ', ', array_slice( $bits, 0, 2 ) ) ) . '.'
			: __( 'Close to everything you told us.', 'whd' );
	}

	private static function option_labels( $key, array $values ) {
		foreach ( self::questions() as $q ) {
			if ( $q['key'] !== $key ) {
				continue;
			}
			$out = [];
			foreach ( $values as $v ) {
				if ( isset( $q['options'][ $v ] ) ) {
					$out[] = $q['options'][ $v ];
				}
			}
			return $out;
		}
		return [];
	}

	private static function intro( array $answers ) {
		$occ = self::option_labels( 'occasion', (array) ( $answers['occasion'] ?? [] ) );

		return $occ
			/* translators: %s: what the visitor said they are dressing for */
			? sprintf( __( 'Six pieces on the rail now, chosen for %s and for the way you said you like things to sit.', 'whd' ), strtolower( implode( __( ' and ', 'whd' ), array_slice( $occ, 0, 2 ) ) ) )
			: __( 'Six pieces on the rail now, chosen against what you told us.', 'whd' );
	}

	/**
	 * Hand the shortlist to the model and take back an order and a reason for each.
	 *
	 * It is given ids and asked to return ids, so it cannot invent a product: anything it names
	 * that is not on the shortlist is dropped.
	 *
	 * @return array|null [ picks, intro ] or null if the model could not be used.
	 */
	private static function ask_model( array $answers, array $shortlist ) {
		$lines = [];
		foreach ( $shortlist as $p ) {
			$lines[] = sprintf(
				'%d | %s | %s | %s',
				$p['id'],
				$p['name'],
				$p['price'],
				implode( ', ', array_slice( (array) $p['cats'], 0, 3 ) )
			);
		}

		$said = [];
		foreach ( self::questions() as $q ) {
			$given = $answers[ $q['key'] ] ?? null;
			if ( null === $given ) {
				continue;
			}
			$labels = self::option_labels( $q['key'], (array) $given );
			if ( $labels ) {
				$said[] = '- ' . wp_strip_all_tags( $q['question'] ) . ' ' . implode( '; ', $labels );
			}
		}

		$prompt = "## Who you are helping\n" . implode( "\n", $said )
			. "\n\n## The pieces you may choose from\nid | name | price | categories\n" . implode( "\n", $lines )
			. "\n\n## What to return\n"
			. "Choose the " . self::PICKS . " best for this person, best first. Reply as JSON and nothing else:\n"
			. '{"intro":"one sentence to open with","picks":[{"id":123,"reason":"one short sentence, no more than 18 words"}]}' . "\n"
			. "Use only ids from the list. The reason says why it suits this person — their height, what they said they wear, what they are dressing for. Do not mention delivery, stock or discounts. Do not invent anything about fabric or origin.";

		$raw = WHD_AI::call( WHD_AI::system_prompt(), $prompt, [ 'json' => true ] );
		if ( is_wp_error( $raw ) ) {
			return null;
		}

		if ( preg_match( '/\{.*\}/s', $raw, $m ) ) {
			$raw = $m[0];
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['picks'] ) ) {
			return null;
		}

		$by_id = [];
		foreach ( $shortlist as $p ) {
			$by_id[ (int) $p['id'] ] = $p;
		}

		$picks = [];
		foreach ( (array) $data['picks'] as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( ! isset( $by_id[ $id ] ) ) {
				continue; // not on the shortlist, so not a real recommendation
			}
			$pick           = $by_id[ $id ];
			$pick['reason'] = wp_strip_all_tags( (string) ( $row['reason'] ?? '' ) );
			$picks[]        = $pick;
			unset( $by_id[ $id ] );
			if ( count( $picks ) >= self::PICKS ) {
				break;
			}
		}

		return $picks ? [
			'picks' => $picks,
			'intro' => wp_strip_all_tags( (string) ( $data['intro'] ?? self::intro( $answers ) ) ),
		] : null;
	}

	/* ─────────────────────────── front end ─────────────────────────── */

	public static function assets() {
		if ( is_admin() || ! self::enabled() ) {
			return;
		}
		wp_enqueue_style( 'whd-stylist', WHD_URL . 'assets/stylist.css', [], WHD_VERSION );
		wp_enqueue_script( 'whd-stylist', WHD_URL . 'assets/stylist.js', [], WHD_VERSION, true );

		$o = WHD_AI::get();
		if ( WHD_AI::recaptcha_shown() ) {
			/*
			 * Each kind has its own script and a key the others refuse, so the version the owner
			 * saved decides this rather than a guess: v2 draws a tickbox when the visitor reaches
			 * that step, v3 and Enterprise draw nothing and mint a token when the form is sent.
			 */
			wp_enqueue_script( 'whd-recaptcha', WHD_AI::recaptcha_script(), [], null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}

		wp_localize_script( 'whd-stylist', 'WHD_STYLIST', [
			'ajax'      => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'whd_stylist' ),
			'questions' => self::questions(),
			'recaptcha' => WHD_AI::recaptcha_shown() ? $o['recaptcha_site'] : '',
			'captchaSilent'     => WHD_AI::recaptcha_silent(),
			'captchaEnterprise' => WHD_AI::recaptcha_enterprise(),
			'captchaAction'     => self::CAPTCHA_ACTION,
			'i18n'      => [
				'working'  => __( 'Reading the rail…', 'whd' ),
				'next'     => __( 'Next', 'whd' ),
				'see'      => __( 'Almost there', 'whd' ),
				'again'    => __( 'Start again', 'whd' ),
				'back'     => __( 'Back', 'whd' ),
				'error'    => __( 'Something went wrong. Try again in a moment.', 'whd' ),
				'pickOne'  => __( 'Choose at least one to carry on.', 'whd' ),
				'step'     => __( 'Step %1$d of %2$d', 'whd' ),
				'send'     => __( 'Show me my edit', 'whd' ),
				'tick'     => __( 'Tick the box to show you are human.', 'whd' ),
				'captchaOff' => __( 'The robot check did not load. Reload the page and try again.', 'whd' ),
				'captchaNote' => __( 'Protected by reCAPTCHA — the Google privacy policy and terms apply.', 'whd' ),
				'browse'   => __( 'Browse everything', 'whd' ),
				'yourEdit' => __( 'Your edit', 'whd' ),
				'chosen'   => __( 'Chosen <em>for you</em>', 'whd' ),
			],
		] );
	}

	/** The overlay itself, printed once in the footer so any page can open it. */
	public static function render() {
		if ( is_admin() || ! self::enabled() ) {
			return;
		}
		?>
		<div class="whd-sty" id="whd-stylist" hidden aria-hidden="true">
			<div class="whd-sty__scrim" data-sty-close></div>
			<div class="whd-sty__panel" role="dialog" aria-modal="true" aria-labelledby="whd-sty-title">
				<button type="button" class="whd-sty__x" data-sty-close aria-label="<?php esc_attr_e( 'Close', 'whd' ); ?>">&times;</button>

				<div class="whd-sty__bar" aria-hidden="true"><span class="whd-sty__bar-fill"></span></div>

				<div class="whd-sty__stage">
					<!-- What this is -->
					<section class="whd-sty__step is-on" data-step="intro">
						<p class="whd-sty__eyebrow"><?php esc_html_e( 'The stylist', 'whd' ); ?></p>
						<h2 class="whd-sty__title" id="whd-sty-title"><?php echo wp_kses( __( 'Six questions, and we <em>pick for you</em>', 'whd' ), [ 'em' => [] ] ); ?></h2>
						<p class="whd-sty__lede"><?php esc_html_e( 'How tall you are, what you actually wear, what you are dressing for. Then a handful of pieces from today\'s rail, with a line on why each one is for you — on screen and in your inbox.', 'whd' ); ?></p>
						<ol class="whd-sty__how">
							<li><span>1</span><?php esc_html_e( 'Answer six quick questions', 'whd' ); ?></li>
							<li><span>2</span><?php esc_html_e( 'Tell us where to send it', 'whd' ); ?></li>
							<li><span>3</span><?php esc_html_e( 'See your edit, and keep the email', 'whd' ); ?></li>
						</ol>
						<button type="button" class="whd-sty__go" data-sty-begin><?php esc_html_e( 'Start — it takes two minutes', 'whd' ); ?></button>
					</section>

					<!-- Questions, details and the edit are all built by the script -->
					<section class="whd-sty__step" data-step="q"></section>
					<section class="whd-sty__step" data-step="details"></section>
					<section class="whd-sty__step" data-step="result"></section>
				</div>
			</div>
		</div>
		<?php
	}

	/* ─────────────────────────── admin ─────────────────────────── */

	public static function sessions( $limit = 100 ) {
		global $wpdb;
		self::maybe_install();
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY created DESC LIMIT %d', (int) $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function count() {
		global $wpdb;
		self::maybe_install();
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** What people are telling us, totalled. The reason to read this screen. */
	public static function answer_totals() {
		$totals = [];
		foreach ( self::sessions( 500 ) as $row ) {
			$answers = json_decode( (string) $row->answers, true );
			if ( ! is_array( $answers ) ) {
				continue;
			}
			foreach ( $answers as $key => $value ) {
				foreach ( (array) $value as $v ) {
					$totals[ $key ][ $v ] = ( $totals[ $key ][ $v ] ?? 0 ) + 1;
				}
			}
		}
		return $totals;
	}
}
