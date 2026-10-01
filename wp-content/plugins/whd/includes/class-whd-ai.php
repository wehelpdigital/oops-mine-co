<?php
/**
 * AI copywriting: the engine.
 *
 * One place that knows how to ask a model for a piece of store copy and how to tell whether what
 * came back is usable. Everything a writer would otherwise have to remember is encoded here:
 *
 *   brief    → who the shop is and how it talks, written once (WHD → AI content → Brief)
 *   rules    → the things copy must and must not do, as a list the owner can edit
 *   banned   → words this shop has decided never to publish, checked after generation
 *   keywords → the terms a page is trying to rank for, checked verbatim after generation
 *
 * The check is deterministic and runs on the model's output, not on a promise in the prompt. A
 * draft that misses a keyword or uses a banned word comes back marked, and one repair pass is sent
 * automatically with the failures listed. Nothing is ever published without a person pressing
 * Apply — this writes drafts, not pages.
 *
 * Keys live in the options table as plain text, the same as every other credential in this plugin;
 * WordPress has no secret store. Rotate at the provider if you think one leaked.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_AI {

	const OPTION     = 'whd_ai';
	const LOG_OPTION = 'whd_ai_log';
	const LOG_MAX    = 25;

	/* ─────────────────────────── settings ─────────────────────────── */

	/** Fields holding a credential: password box, blank means "keep what is stored". */
	public static function secret_fields() {
		return [ 'anthropic_key', 'openai_key', 'google_key', 'recaptcha_secret', 'recaptcha_api_key' ];
	}

	public static function defaults() {
		return [
			'provider'        => 'none',
			'anthropic_key'   => '',
			'openai_key'      => '',
			'google_key'      => '',
			'recaptcha_site'  => '',
			'recaptcha_secret'=> '',
			'recaptcha_version' => 'v2',
			'recaptcha_project' => '',
			'recaptcha_api_key' => '',
			'recaptcha_score' => 0.5,
			'model'           => '',
			'max_tokens'      => 1600,
			'temperature'     => 0.7,
			'brief'           => '',
			'rules'           => [],
			'banned_words'    => '',
			'min_words'       => 90,
			'max_words'       => 320,
			'require_keywords'=> 1,
			'auto_repair'     => 1,
			'post_types'      => [ 'product', 'page', 'post' ],
		];
	}

	public static function get() {
		$stored = get_option( self::OPTION, null );
		$stored = is_array( $stored ) ? $stored : [];
		$o      = wp_parse_args( $stored, self::defaults() );

		/*
		 * Seed the lists only when they have never been saved — checked by whether the key exists
		 * in what is stored, not by whether it is empty. Seeding on "empty" meant an owner who
		 * deliberately deleted every rule, or cleared the banned list to publish one of those
		 * words, got the whole default set back on the next page load with no way to refuse it.
		 * The sanitiser always writes both keys, so one save is enough to make a choice stick.
		 */
		if ( ! array_key_exists( 'rules', $stored ) ) {
			$o['rules'] = self::default_rules();
		}
		if ( ! array_key_exists( 'banned_words', $stored ) ) {
			$o['banned_words'] = implode( "\n", self::default_banned_words() );
		}
		return $o;
	}

	public static function providers() {
		return [
			'none'      => __( 'Off — no AI calls are made', 'whd' ),
			'anthropic' => __( 'Anthropic (Claude)', 'whd' ),
			'openai'    => __( 'OpenAI', 'whd' ),
			'google'    => __( 'Google (Gemini)', 'whd' ),
		];
	}

	/** The three flavours. They are not interchangeable: a key issued for one is rejected by the others. */
	public static function recaptcha_versions() {
		return [
			'v2'         => __( 'v2 — the "I am not a robot" tickbox', 'whd' ),
			'v3'         => __( 'v3 — invisible, scores each visitor', 'whd' ),
			'enterprise' => __( 'Enterprise — invisible, scored by a Google Cloud project', 'whd' ),
		];
	}

	/** Enterprise and v3 both draw nothing and score silently. Only v2 asks the visitor to do anything. */
	public static function recaptcha_silent(): bool {
		return 'v2' !== self::recaptcha_version();
	}

	public static function recaptcha_enterprise(): bool {
		return 'enterprise' === self::recaptcha_version();
	}

	/** The script the page has to load. Enterprise is served from its own file. */
	public static function recaptcha_script(): string {
		$site = rawurlencode( trim( self::get()['recaptcha_site'] ) );
		if ( self::recaptcha_enterprise() ) {
			return 'https://www.google.com/recaptcha/enterprise.js?render=' . $site;
		}

		// v2 is rendered explicitly, because its box is built when the visitor reaches that step.
		return 'v3' === self::recaptcha_version()
			? 'https://www.google.com/recaptcha/api.js?render=' . $site
			: 'https://www.google.com/recaptcha/api.js?render=explicit';
	}

	/** Which flavour the saved keys belong to. */
	public static function recaptcha_version(): string {
		$v = sanitize_key( self::get()['recaptcha_version'] ?? 'v2' );

		return isset( self::recaptcha_versions()[ $v ] ) ? $v : 'v2';
	}

	/** Is there a site key at all? That alone decides whether the page draws anything. */
	public static function recaptcha_shown(): bool {
		return '' !== trim( self::get()['recaptcha_site'] );
	}

	/**
	 * Is reCAPTCHA configured well enough to check anything?
	 *
	 * Classic needs the secret that pairs with the site key. Enterprise has no secret at all: it
	 * needs the Cloud project the key belongs to and an API key allowed to write assessments there.
	 */
	public static function recaptcha_ready(): bool {
		$o = self::get();
		if ( '' === trim( $o['recaptcha_site'] ) ) {
			return false;
		}
		if ( self::recaptcha_enterprise() ) {
			return '' !== trim( $o['recaptcha_project'] ) && '' !== trim( $o['recaptcha_api_key'] );
		}

		return '' !== trim( $o['recaptcha_secret'] );
	}

	/** What is still missing, in the owner's words, or '' when nothing is. */
	public static function recaptcha_missing(): string {
		$o = self::get();
		if ( '' === trim( $o['recaptcha_site'] ) ) {
			return __( 'the site key', 'whd' );
		}
		if ( self::recaptcha_enterprise() ) {
			$gaps = [];
			if ( '' === trim( $o['recaptcha_project'] ) ) {
				$gaps[] = __( 'the Google Cloud project ID', 'whd' );
			}
			if ( '' === trim( $o['recaptcha_api_key'] ) ) {
				$gaps[] = __( 'an API key that may write assessments', 'whd' );
			}
			return implode( __( ' and ', 'whd' ), $gaps );
		}

		return '' === trim( $o['recaptcha_secret'] ) ? __( 'the secret key', 'whd' ) : '';
	}

	/**
	 * Ask Google whether a token is genuine.
	 *
	 * Returns true when reCAPTCHA is not configured at all: the wizard has its own defences and
	 * refusing every visitor because a key is missing would be worse than the thing it prevents.
	 * The same mercy covers a reply that says the *configuration* is wrong — a visitor should never
	 * be shut out by an unpaid bill or a mistyped project. Only a real verdict about the token
	 * itself turns people away.
	 */
	public static function recaptcha_verify( $token, $action = '' ) {
		$result = self::recaptcha_check( $token, $action );

		return ! empty( $result['ok'] );
	}

	/**
	 * Check a token and say what came back.
	 *
	 * Returns [ ok, score, reason, message, config ]. recaptcha_verify() reduces it to a yes or no;
	 * the settings screen prints the whole thing, because "it did not work" is not a diagnosis.
	 */
	public static function recaptcha_check( $token, $action = '' ) {
		if ( ! self::recaptcha_ready() ) {
			return [
				'ok'      => true,
				'config'  => false,
				/* translators: %s: what is missing, e.g. "the secret key" */
				'message' => sprintf( __( 'Nothing was checked — still missing: %s.', 'whd' ), self::recaptcha_missing() ),
			];
		}
		$o = self::get();

		return self::recaptcha_enterprise()
			? self::check_enterprise( (string) $token, (string) $action, $o )
			: self::check_classic( (string) $token, (string) $action, $o );
	}

	/** v2 and v3: one secret, one endpoint, one yes or no (plus a score for v3). */
	private static function check_classic( $token, $action, array $o ) {
		$r = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', [
			'timeout' => 10,
			'body'    => [
				'secret'   => $o['recaptcha_secret'],
				'response' => $token,
				'remoteip' => self::visitor_ip(),
			],
		] );
		if ( is_wp_error( $r ) ) {
			return [ 'ok' => true, 'config' => false, 'message' => __( 'Google could not be reached, so the visitor was let through.', 'whd' ) ];
		}
		$body   = json_decode( wp_remote_retrieve_body( $r ), true );
		$body   = is_array( $body ) ? $body : [];
		$errors = implode( ', ', (array) ( $body['error-codes'] ?? [] ) );

		if ( empty( $body['success'] ) ) {
			// A key the site owner got wrong is the site owner's problem, not the visitor's.
			$ours = array_intersect( (array) ( $body['error-codes'] ?? [] ), [ 'invalid-input-secret', 'missing-input-secret', 'bad-request' ] );
			if ( $ours ) {
				self::log( 'recaptcha', 400, $errors, self::recaptcha_version() );
				return [ 'ok' => true, 'config' => false, 'reason' => $errors, 'message' => __( 'The keys are wrong, so nothing could be checked.', 'whd' ) . ' ' . $errors ];
			}
			return [ 'ok' => false, 'config' => true, 'reason' => $errors, 'message' => __( 'Google rejected the token.', 'whd' ) . ( $errors ? ' ' . $errors : '' ) ];
		}
		if ( 'v3' !== self::recaptcha_version() ) {
			return [ 'ok' => true, 'config' => true, 'message' => __( 'Tickbox accepted.', 'whd' ) ];
		}
		if ( '' !== $action && isset( $body['action'] ) && $body['action'] !== $action ) {
			return [ 'ok' => false, 'config' => true, 'message' => __( 'The token was minted for another form.', 'whd' ) ];
		}
		$score = (float) ( $body['score'] ?? 0 );

		return self::verdict( $score, $o );
	}

	/**
	 * Enterprise: an assessment posted to the project the key belongs to.
	 *
	 * The API key authenticates the call, so it is sent as a query parameter exactly as Google's
	 * own examples do — and it is a server-side value that never reaches the page.
	 */
	private static function check_enterprise( $token, $action, array $o ) {
		$url = sprintf(
			'https://recaptchaenterprise.googleapis.com/v1/projects/%s/assessments?key=%s',
			rawurlencode( trim( $o['recaptcha_project'] ) ),
			rawurlencode( trim( $o['recaptcha_api_key'] ) )
		);

		$event = [
			'token'   => $token,
			'siteKey' => trim( $o['recaptcha_site'] ),
		];
		if ( '' !== $action ) {
			$event['expectedAction'] = $action;
		}
		$ip = self::visitor_ip();
		if ( '' !== $ip ) {
			$event['userIpAddress'] = $ip;
		}

		$r = wp_remote_post( $url, [
			'timeout' => 12,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [ 'event' => $event ] ),
		] );
		if ( is_wp_error( $r ) ) {
			return [ 'ok' => true, 'config' => false, 'message' => __( 'Google could not be reached, so the visitor was let through.', 'whd' ) ];
		}

		$code = (int) wp_remote_retrieve_response_code( $r );
		$data = json_decode( wp_remote_retrieve_body( $r ), true );
		$data = is_array( $data ) ? $data : [];

		if ( $code < 200 || $code >= 300 ) {
			$why = (string) ( $data['error']['message'] ?? wp_remote_retrieve_response_message( $r ) );
			self::log( 'recaptcha', $code, $why, 'enterprise' );

			/* translators: 1: HTTP status, 2: Google's explanation */
			return [ 'ok' => true, 'config' => false, 'message' => sprintf( __( 'The project or API key is wrong, so nothing was checked. Google answered %1$d: %2$s', 'whd' ), $code, $why ) ];
		}

		$props = (array) ( $data['tokenProperties'] ?? [] );
		if ( empty( $props['valid'] ) ) {
			$why = (string) ( $props['invalidReason'] ?? '' );

			// A token minted by the wrong site key is a setup mistake; the rest are the token's fault.
			if ( in_array( $why, [ 'UNKNOWN_INVALID_REASON', 'SITE_MISMATCH', 'MISSING' ], true ) ) {
				self::log( 'recaptcha', 200, $why, 'enterprise' );
				return [ 'ok' => true, 'config' => false, 'reason' => $why, 'message' => __( 'The token did not belong to this site key, so nothing was checked.', 'whd' ) . ' ' . $why ];
			}

			return [ 'ok' => false, 'config' => true, 'reason' => $why, 'message' => __( 'Google rejected the token.', 'whd' ) . ( $why ? ' ' . $why : '' ) ];
		}
		if ( '' !== $action && ! empty( $props['action'] ) && $props['action'] !== $action ) {
			return [ 'ok' => false, 'config' => true, 'message' => __( 'The token was minted for another form.', 'whd' ) ];
		}

		return self::verdict( (float) ( $data['riskAnalysis']['score'] ?? 0 ), $o );
	}

	/** Above the line or below it, in words the owner can act on. */
	private static function verdict( $score, array $o ) {
		$min = (float) ( $o['recaptcha_score'] ?? 0.5 );
		$ok  = $score >= $min;

		return [
			'ok'      => $ok,
			'config'  => true,
			'score'   => $score,
			/* translators: 1: the score Google gave, 2: the lowest score allowed through */
			'message' => sprintf(
				$ok ? __( 'Scored %1$.1f, and %2$.1f is the lowest you allow — let through.', 'whd' ) : __( 'Scored %1$.1f, below the %2$.1f you allow — turned away.', 'whd' ),
				$score,
				$min
			),
		];
	}

	private static function visitor_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/** Suggested models per provider. The field is free text, so a newer one can always be typed. */
	public static function model_choices( $provider ) {
		$lists = [
			'anthropic' => [
				'claude-sonnet-5'           => 'Claude Sonnet 5 — the sensible default for store copy',
				'claude-opus-5-5'           => 'Claude Opus 5.5 — the strongest, and the slowest',
				'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5 — quick and cheap, for short fields',
			],
			'openai'    => [],
			'google'    => [
				'gemini-3.8-flash' => 'Gemini 3.8 Flash — quick, and enough for shop copy',
				'gemini-3.8-pro'   => 'Gemini 3.8 Pro — slower, stronger',
			],
		];
		return $lists[ $provider ] ?? [];
	}

	public static function default_model( $provider ) {
		$defaults = [
			'anthropic' => 'claude-sonnet-5',
			'openai'    => 'gpt-4o',
			'google'    => 'gemini-3.8-flash',
		];

		return $defaults[ $provider ] ?? '';
	}

	/** The model a call would use, or '' when nothing is connected to call. */
	public static function model() {
		$o = self::get();
		if ( 'none' === $o['provider'] ) {
			return '';
		}
		return trim( $o['model'] ) ?: self::default_model( $o['provider'] );
	}

	public static function ready(): bool {
		$o = self::get();
		if ( 'anthropic' === $o['provider'] ) {
			return '' !== $o['anthropic_key'];
		}
		if ( 'openai' === $o['provider'] ) {
			return '' !== $o['openai_key'];
		}
		if ( 'google' === $o['provider'] ) {
			return '' !== $o['google_key'];
		}
		return false;
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : [];
		$old   = self::get();
		$out   = self::defaults();
		$clear = isset( $input['clear'] ) && is_array( $input['clear'] ) ? $input['clear'] : [];

		$provider         = sanitize_key( $input['provider'] ?? 'none' );
		$out['provider']  = isset( self::providers()[ $provider ] ) ? $provider : 'none';

		foreach ( self::secret_fields() as $key ) {
			$new         = trim( (string) ( $input[ $key ] ?? '' ) );
			$out[ $key ] = ! empty( $clear[ $key ] ) ? '' : ( '' === $new ? (string) $old[ $key ] : sanitize_text_field( $new ) );
		}

		$out['model']          = sanitize_text_field( $input['model'] ?? '' );
		$out['recaptcha_site'] = sanitize_text_field( $input['recaptcha_site'] ?? '' );

		$version                  = sanitize_key( $input['recaptcha_version'] ?? 'v2' );
		$out['recaptcha_version'] = isset( self::recaptcha_versions()[ $version ] ) ? $version : 'v2';
		$out['recaptcha_project'] = sanitize_text_field( $input['recaptcha_project'] ?? '' );
		$out['recaptcha_score']   = min( 0.9, max( 0.1, round( (float) ( $input['recaptcha_score'] ?? 0.5 ), 1 ) ) );
		$out['max_tokens']  = min( 8000, max( 200, (int) ( $input['max_tokens'] ?? 1600 ) ) );
		$out['temperature'] = min( 1, max( 0, round( (float) ( $input['temperature'] ?? 0.7 ), 2 ) ) );

		// wp_kses_post would be wrong here: the brief is instructions to a model, not markup.
		$out['brief']        = sanitize_textarea_field( $input['brief'] ?? '' );
		$out['banned_words'] = sanitize_textarea_field( $input['banned_words'] ?? '' );

		$rules = [];
		foreach ( (array) ( $input['rules'] ?? [] ) as $rule ) {
			$text = trim( sanitize_textarea_field( is_array( $rule ) ? ( $rule['text'] ?? '' ) : $rule ) );
			if ( '' === $text ) {
				continue;
			}
			$rules[] = [
				'text' => $text,
				'on'   => ( is_array( $rule ) && empty( $rule['on'] ) ) ? 0 : 1,
			];
		}
		$out['rules'] = $rules;

		$out['min_words']        = max( 0, (int) ( $input['min_words'] ?? 0 ) );
		$out['max_words']        = max( $out['min_words'], (int) ( $input['max_words'] ?? 0 ) );
		$out['require_keywords'] = empty( $input['require_keywords'] ) ? 0 : 1;
		$out['auto_repair']      = empty( $input['auto_repair'] ) ? 0 : 1;

		$types             = array_map( 'sanitize_key', (array) ( $input['post_types'] ?? [] ) );
		$out['post_types'] = array_values( array_intersect( $types, [ 'product', 'page', 'post' ] ) );

		return $out;
	}

	/* ─────────────────────────── rules ─────────────────────────── */

	/**
	 * The house rules, seeded on first use.
	 *
	 * These are not generic writing tips. Each one is something this shop has already been caught
	 * by: copy that promised next-day delivery from a store with no shipping zones, review counts
	 * nobody could produce, a second H1 fighting the page title.
	 */
	public static function default_rules() {
		$rules = [
			__( 'Write for one shopper deciding whether this is for her. Not for a search engine, and not for everybody.', 'whd' ),
			__( 'Never state a delivery time, a carrier, a shipping cost or a returns window. The store cannot back those up, and a promise on a page is a promise.', 'whd' ),
			__( 'No invented numbers: no stock counts, review counts, customer counts, percentages or “over 1,000 happy customers”.', 'whd' ),
			__( 'Do not name other brands, other shops, designers or real people.', 'whd' ),
			__( 'No discount, sale or coupon unless the instruction for this piece gives you one.', 'whd' ),
			__( 'Plain English, short sentences, no exclamation marks, no rhetorical questions stacked together.', 'whd' ),
			__( 'Start with the wearer, not the garment: what it solves, where it goes, how it feels to put on.', 'whd' ),
			__( 'Concrete over abstract. “Falls just below the knee” beats “flattering length”.', 'whd' ),
			__( 'Use H2 for section headings and H3 beneath them. Never write an H1 — the page already has one.', 'whd' ),
			__( 'Do not write a conclusion paragraph that restates what was just said.', 'whd' ),
			__( 'British-neutral spelling, sentence case in headings.', 'whd' ),
		];
		return array_map(
			static function ( $text ) {
				return [
					'text' => $text,
					'on'   => 1,
				];
			},
			$rules
		);
	}

	/**
	 * Words and phrases this shop does not publish.
	 *
	 * The list is the one the site's content was written against — the tells of machine-written
	 * retail copy, plus the connectives that make a paragraph read like an essay.
	 */
	public static function default_banned_words() {
		return [
			'by paying attention', 'in summary', 'smooth experience', 'daunting task', 'in this article',
			'in conclusion', 'break the bank', 'breaking the bank', 'today’s digital age', "today's digital age",
			'moreover', 'homework', 'furthermore', 'additionally', 'lastly', 'in addition', 'therefore',
			'ultimately', 'informed decision', 'dive in', 'dive into', 'delve', 'delving', 'fascinating world',
			'performing your research', 'doing your research', 'explore the world of', 'consequently', 'utilize',
			'utilise', 'however', 'implement', 'in order to', 'pertaining to', 'regarding', 'subsequently',
			'thus', 'facilitate', 'prior to', 'in the event of', 'owing to', 'in light of', 'on the contrary',
			'in the midst of', 'despite', 'in accordance with', 'with regard to', 'subsequent to', 'commence',
			'endeavor', 'in lieu of', 'notwithstanding', 'in conjunction with', 'landscape', 'realm',
			'navigating', 'tailored', 'underpins', 'unveil', 'transformative', 'encompass', 'dynamic',
			'ecosystem', 'confluence', 'engaging', 'quest', 'solutions', 'significant', 'specific', 'numerous',
			'unsatisfied', 'craft', 'crafted', 'glean', 'glance', 'enhancing', 'enhance', 'world',
		];
	}

	/** The banned list as an array, from whatever is in the setting. */
	public static function banned_words() {
		$raw = preg_split( '/[\r\n,]+/', (string) self::get()['banned_words'] );
		return array_values( array_filter( array_map( 'trim', (array) $raw ) ) );
	}

	/* ─────────────────────────── what can be written ─────────────────────────── */

	/**
	 * The pieces of copy this module knows how to write, and where each one can be applied.
	 *
	 * @param string $post_type product|page|post
	 */
	public static function targets( $post_type ) {
		$all = [
			'product_story'       => [
				'label' => __( 'Product story (the long block under the gallery)', 'whd' ),
				'types' => [ 'product' ],
				'brief' => __( 'A 3–4 section piece about wearing this garment: how it fits into a real week, what it goes with, fabric and fit in plain words, and who it suits. Give each section an H2 heading.', 'whd' ),
				'apply' => 'story',
				'words' => [ 200, 420 ],
			],
			'product_description' => [
				'label' => __( 'Long description (the product tab)', 'whd' ),
				'types' => [ 'product' ],
				'brief' => __( 'Two or three paragraphs of description: the cut, the fabric and how it wears. No headings, no bullet list of specifications the shop has not given you.', 'whd' ),
				'apply' => 'content',
				'words' => [ 90, 220 ],
			],
			'short_description'   => [
				'label' => __( 'Short description (beside the price)', 'whd' ),
				'types' => [ 'product' ],
				'brief' => __( 'Two sentences, at most 45 words, that make someone want to scroll. No headings.', 'whd' ),
				'apply' => 'excerpt',
				'words' => [ 15, 55 ],
			],
			'page_section'        => [
				'label' => __( 'A section of page copy', 'whd' ),
				'types' => [ 'page', 'post' ],
				'brief' => __( 'One section: an H2 heading and two or three paragraphs beneath it.', 'whd' ),
				'apply' => 'content',
				'words' => [ 120, 320 ],
			],
			'article'             => [
				'label' => __( 'A full journal post', 'whd' ),
				'types' => [ 'post' ],
				'brief' => __( 'An opening paragraph with no heading, then three or four H2 sections. No conclusion section.', 'whd' ),
				'apply' => 'content',
				'words' => [ 400, 800 ],
			],
			'meta_description'    => [
				'label' => __( 'Search snippet — meta description', 'whd' ),
				'types' => [ 'product', 'page', 'post' ],
				'brief' => __( 'One sentence of 140–155 characters that would make someone click from a results page. Plain text, no quotes around it, no ellipsis.', 'whd' ),
				'apply' => 'meta_description',
				'words' => [ 18, 30 ],
			],
			'seo_title'           => [
				'label' => __( 'Search snippet — page title', 'whd' ),
				'types' => [ 'product', 'page', 'post' ],
				'brief' => __( 'A title of at most 60 characters. Lead with the thing being sold, not the shop name. No pipe-separated keyword stuffing.', 'whd' ),
				'apply' => 'seo_title',
				'words' => [ 3, 12 ],
			],
		];
		return array_filter(
			$all,
			static function ( $t ) use ( $post_type ) {
				return in_array( $post_type, $t['types'], true );
			}
		);
	}

	/* ─────────────────────────── the brief ─────────────────────────── */

	/**
	 * A stored string as a model should read it.
	 *
	 * WordPress keeps titles and options HTML-encoded, so "Sweaters & Knits" comes out of the
	 * database as "Sweaters &amp; Knits" and an apostrophe as "&#039;". Left alone those reach the
	 * prompt as literal entity text and come back in the copy.
	 */
	private static function plain( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/[ \t]+/', ' ', $text ) );
	}

	/**
	 * Facts about this shop, read from the site itself.
	 *
	 * Used two ways: to draft the brand brief, and as context on every generation so the model is
	 * never guessing what the shop sells.
	 */
	public static function site_profile() {
		$cats = [];
		if ( taxonomy_exists( 'product_cat' ) ) {
			foreach ( get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 18, 'orderby' => 'count', 'order' => 'DESC' ] ) as $term ) {
				if ( ! is_wp_error( $term ) && 'uncategorized' !== $term->slug ) {
					$cats[] = self::plain( $term->name );
				}
			}
		}

		$titles = [];
		$prices = [];
		foreach ( get_posts( [ 'post_type' => 'product', 'numberposts' => 12, 'post_status' => 'publish', 'orderby' => 'date' ] ) as $p ) {
			$titles[] = self::plain( $p->post_title );
			if ( function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $p->ID );
				$price   = $product ? (float) $product->get_price() : 0;
				if ( $price > 0 ) {
					$prices[] = $price;
				}
			}
		}

		$about = '';
		$page  = get_page_by_path( 'about' );
		if ( $page ) {
			$about = self::plain( wp_trim_words( wp_strip_all_tags( $page->post_content ), 90 ) );
		}

		return [
			'name'        => self::plain( get_bloginfo( 'name' ) ),
			'tagline'     => self::plain( get_bloginfo( 'description' ) ),
			'url'         => home_url( '/' ),
			'categories'  => $cats,
			'products'    => $titles,
			'price_range' => $prices ? [ min( $prices ), max( $prices ) ] : [],
			'currency'    => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'about'       => $about,
		];
	}

	/**
	 * A starter brand brief, assembled from the site without calling anything.
	 *
	 * Deliberately offline: the owner can open the Brief tab, press one button and have something
	 * worth editing before a key is ever pasted in. "Polish with AI" then rewrites it.
	 */
	public static function draft_brief() {
		$p     = self::site_profile();
		$lines = [];

		$lines[] = sprintf(
			/* translators: 1: shop name, 2: tagline or a fallback phrase */
			__( '%1$s is an online womenswear boutique. %2$s', 'whd' ),
			$p['name'],
			$p['tagline'] ?: __( 'Pieces are chosen one at a time rather than bought by the rail.', 'whd' )
		);

		if ( $p['categories'] ) {
			$lines[] = sprintf(
				/* translators: %s: comma-separated category names */
				__( 'What it sells: %s.', 'whd' ),
				implode( ', ', array_slice( $p['categories'], 0, 12 ) )
			);
		}
		if ( $p['products'] ) {
			$lines[] = sprintf(
				/* translators: %s: comma-separated product names */
				__( 'Recent pieces, so you can hear the naming: %s.', 'whd' ),
				implode( '; ', array_slice( $p['products'], 0, 6 ) )
			);
		}
		if ( $p['price_range'] ) {
			$lines[] = sprintf(
				/* translators: 1: lowest price, 2: highest price, 3: currency code */
				__( 'Prices run from about %1$s to %2$s %3$s — everyday money, not investment pieces. Write accordingly: no luxury vocabulary, no bargain vocabulary.', 'whd' ),
				number_format( $p['price_range'][0], 0 ),
				number_format( $p['price_range'][1], 0 ),
				$p['currency']
			);
		}
		if ( $p['about'] ) {
			$lines[] = sprintf(
				/* translators: %s: an excerpt of the About page */
				__( 'From the About page: %s', 'whd' ),
				$p['about']
			);
		}

		$lines[] = __( 'Voice: warm, close, a little dry. The tone of a friend who knows clothes telling you the truth about a piece, including when to size up. Never breathless, never salesy, never a press release.', 'whd' );
		$lines[] = __( 'The reader: a woman buying for her own real week — work, a dinner, a trip — who has been burned by online sizing before and wants to know what she is actually getting.', 'whd' );

		return implode( "\n\n", $lines );
	}

	/** The brief in use: what the owner saved, or the drafted one. */
	public static function brief() {
		$saved = trim( (string) self::get()['brief'] );
		return $saved ?: self::draft_brief();
	}

	/* ─────────────────────────── prompts ─────────────────────────── */

	/** The system prompt: who the shop is, plus every rule that is switched on. */
	public static function system_prompt() {
		$o     = self::get();
		$parts = [];

		$parts[] = __( 'You are the copywriter for this shop. You write the words that go on its website.', 'whd' );
		$parts[] = "## The shop\n" . self::brief();

		$rules = array_values( array_filter( $o['rules'], static fn( $r ) => ! empty( $r['on'] ) ) );
		if ( $rules ) {
			$list    = array_map( static fn( $r ) => '- ' . $r['text'], $rules );
			$parts[] = "## House rules — every one of these is a hard requirement\n" . implode( "\n", $list );
		}

		$banned = self::banned_words();
		if ( $banned ) {
			$parts[] = "## Never use these words or phrases\n"
				. __( 'They are checked automatically after you write, and a draft containing any of them is rejected. Write around them; do not substitute a synonym that means the same connective.', 'whd' )
				. "\n" . implode( ', ', $banned );
		}

		$parts[] = "## Format\n" . __( 'Return the copy itself and nothing else: no preamble, no “here is”, no notes about what you did, no markdown code fences. Use markdown only for structure: ## for section headings, ### beneath them, - for list items, **bold** sparingly.', 'whd' );

		return implode( "\n\n", $parts );
	}

	/**
	 * The user prompt for one piece of copy.
	 *
	 * @param array $args target, post_id, keywords, instruction.
	 */
	public static function user_prompt( array $args ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$post    = $post_id ? get_post( $post_id ) : null;
		$target  = (string) ( $args['target'] ?? '' );
		$spec    = self::targets( $post ? $post->post_type : 'page' )[ $target ] ?? null;
		$o       = self::get();
		$parts   = [];

		if ( ! $spec ) {
			return '';
		}

		$parts[] = '## What to write' . "\n" . $spec['brief'];

		if ( $post ) {
			$about = [ sprintf( __( 'Title: %s', 'whd' ), self::plain( $post->post_title ) ) ];

			if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $post_id );
				if ( $product ) {
					$price = self::plain( $product->get_price_html() );
					if ( $price ) {
						$about[] = sprintf( __( 'Price: %s', 'whd' ), $price );
					}
					$cats = wp_get_post_terms( $post_id, 'product_cat', [ 'fields' => 'names' ] );
					if ( $cats && ! is_wp_error( $cats ) ) {
						$about[] = sprintf( __( 'Categories: %s', 'whd' ), self::plain( implode( ', ', $cats ) ) );
					}
					foreach ( $product->get_attributes() as $attribute ) {
						$name    = wc_attribute_label( $attribute->get_name() );
						$options = $attribute->is_taxonomy()
							? wp_list_pluck( $attribute->get_terms() ?: [], 'name' )
							: $attribute->get_options();
						if ( $options ) {
							$about[] = sprintf( '%s: %s', self::plain( $name ), self::plain( implode( ', ', $options ) ) );
						}
					}
				}
			} else {
				$cats = wp_get_post_terms( $post_id, 'category', [ 'fields' => 'names' ] );
				if ( $cats && ! is_wp_error( $cats ) ) {
					$about[] = sprintf( __( 'Categories: %s', 'whd' ), self::plain( implode( ', ', $cats ) ) );
				}
			}

			$existing = self::plain( $post->post_content );
			if ( $existing ) {
				$about[] = sprintf( __( 'What the page says now (rewrite in your own words, do not copy it): %s', 'whd' ), wp_trim_words( $existing, 120 ) );
			}

			$parts[] = '## The page' . "\n" . implode( "\n", $about );
		}

		$keywords = self::clean_keywords( $args['keywords'] ?? [] );
		if ( $keywords ) {
			$parts[] = '## Keywords' . "\n"
				. ( $o['require_keywords']
					? __( 'Each of these must appear in the copy word for word, at least once, inside a sentence that would read normally to someone who had never heard of SEO. Change nothing about them — not the order of the words, not the plural, not the hyphens. If one is awkward, build a sentence around it rather than bending it.', 'whd' )
					: __( 'Work these in where they fit naturally. Do not force one in that has nowhere to go.', 'whd' ) )
				. "\n" . implode( "\n", array_map( static fn( $k ) => '- ' . $k, $keywords ) );
		}

		[ $min, $max ] = $spec['words'];
		$min           = $o['min_words'] ? max( $min, $o['min_words'] ) : $min;
		$max           = $o['max_words'] ? min( $max, max( $o['max_words'], $min + 20 ) ) : $max;
		$parts[]       = '## Length' . "\n" . sprintf(
			/* translators: 1: minimum words, 2: maximum words */
			__( 'Between %1$d and %2$d words.', 'whd' ),
			$min,
			$max
		);

		$instruction = trim( (string) ( $args['instruction'] ?? '' ) );
		if ( $instruction ) {
			$parts[] = '## Extra instruction for this piece — it wins over the general guidance above' . "\n" . $instruction;
		}

		return implode( "\n\n", $parts );
	}

	/* ─────────────────────────── generation ─────────────────────────── */

	/**
	 * Write one piece of copy, check it, and repair it once if the check fails.
	 *
	 * @param array $args target, post_id, keywords, instruction.
	 * @return array|WP_Error [ text, check, model, attempts ]
	 */
	public static function generate( array $args ) {
		if ( ! self::ready() ) {
			return new WP_Error( 'whd_ai_off', __( 'No AI provider is connected. WHD → AI content → Settings.', 'whd' ) );
		}
		$user = self::user_prompt( $args );
		if ( '' === $user ) {
			return new WP_Error( 'whd_ai_target', __( 'That is not something this module knows how to write for this kind of page.', 'whd' ) );
		}

		$o        = self::get();
		$system   = self::system_prompt();
		$keywords = self::clean_keywords( $args['keywords'] ?? [] );

		$text = self::call( $system, $user );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$text     = self::tidy( $text );
		$check    = self::check( $text, $keywords );
		$attempts = 1;

		if ( ! $check['ok'] && $o['auto_repair'] ) {
			$repair = $user . "\n\n## Your previous draft\n" . $text . "\n\n## What is wrong with it\n"
				. implode( "\n", array_map( static fn( $i ) => '- ' . $i['detail'], $check['issues'] ) )
				. "\n\n" . __( 'Rewrite the whole piece so that none of those apply. Keep everything that was already good. Return only the copy.', 'whd' );

			$second = self::call( $system, $repair );
			if ( ! is_wp_error( $second ) ) {
				$attempts   = 2;
				$second     = self::tidy( $second );
				$new_check  = self::check( $second, $keywords );
				// Keep the repair only if it is actually better — a second pass can trade one fault for two.
				if ( count( $new_check['issues'] ) <= count( $check['issues'] ) ) {
					$text  = $second;
					$check = $new_check;
				}
			}
		}

		return [
			'text'     => $text,
			'check'    => $check,
			'model'    => self::model(),
			'attempts' => $attempts,
		];
	}

	/**
	 * Ask a provider for text.
	 *
	 * @param string $system  Instructions.
	 * @param string $user    The request.
	 * @param array  $options 'json' => true asks the provider for JSON rather than prose, where it
	 *                        supports it. Callers that parse the reply should set it.
	 * @return string|WP_Error The reply, or an error with something a human can act on.
	 */
	public static function call( $system, $user, array $options = [] ) {
		$o     = self::get();
		$model = self::model();
		$json  = ! empty( $options['json'] );

		if ( 'anthropic' === $o['provider'] ) {
			$url  = 'https://api.anthropic.com/v1/messages';
			$args = [
				'headers' => [
					'x-api-key'         => $o['anthropic_key'],
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				],
				'body'    => wp_json_encode( [
					'model'       => $model,
					'max_tokens'  => (int) $o['max_tokens'],
					'temperature' => (float) $o['temperature'],
					'system'      => $system,
					'messages'    => [ [ 'role' => 'user', 'content' => $user ] ],
				] ),
			];
		} elseif ( 'openai' === $o['provider'] ) {
			$url  = 'https://api.openai.com/v1/chat/completions';
			$args = [
				'headers' => [
					'Authorization' => 'Bearer ' . $o['openai_key'],
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( array_filter( [
					'model'           => $model,
					'max_tokens'      => (int) $o['max_tokens'],
					'temperature'     => (float) $o['temperature'],
					'response_format' => $json ? [ 'type' => 'json_object' ] : null,
					'messages'        => [
						[ 'role' => 'system', 'content' => $system ],
						[ 'role' => 'user', 'content' => $user ],
					],
				] ) ),
			];
		} elseif ( 'google' === $o['provider'] ) {
			/*
			 * Gemini takes the key on the query string and has no system role — the instructions go
			 * in systemInstruction instead, which is the same idea under another name.
			 */
			$url    = add_query_arg( 'key', rawurlencode( $o['google_key'] ), 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent' );
			$config = [
				/*
				 * A floor of 4,096 whatever the setting says. These models reason before they
				 * answer and the reasoning is drawn from the same allowance, so a budget sized for
				 * the reply alone comes back cut off part way through a sentence.
				 */
				'maxOutputTokens' => max( 4096, (int) $o['max_tokens'] ),
				'temperature'     => (float) $o['temperature'],
			];
			if ( $json ) {
				$config['responseMimeType'] = 'application/json';
			}
			$args = [
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [
					'systemInstruction' => [ 'parts' => [ [ 'text' => $system ] ] ],
					'contents'          => [ [ 'role' => 'user', 'parts' => [ [ 'text' => $user ] ] ] ],
					'generationConfig'  => $config,
				] ),
			];
		} else {
			return new WP_Error( 'whd_ai_off', __( 'No AI provider is connected.', 'whd' ) );
		}

		$args['timeout'] = 90;
		$response        = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			self::log( $o['provider'], 0, $response->get_error_message(), $model );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = $body['error']['message'] ?? wp_remote_retrieve_response_message( $response );
			self::log( $o['provider'], $code, $message, $model );
			return new WP_Error( 'whd_ai_http', sprintf( '%d — %s', $code, $message ) );
		}

		if ( 'anthropic' === $o['provider'] ) {
			$text = $body['content'][0]['text'] ?? '';
		} elseif ( 'google' === $o['provider'] ) {
			$text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
		} else {
			$text = $body['choices'][0]['message']['content'] ?? '';
		}

		if ( '' === trim( (string) $text ) ) {
			self::log( $o['provider'], $code, __( 'The model returned nothing.', 'whd' ), $model );
			return new WP_Error( 'whd_ai_empty', __( 'The model returned nothing. Try again, or raise the token limit.', 'whd' ) );
		}

		if ( 'anthropic' === $o['provider'] ) {
			$used = (int) ( $body['usage']['output_tokens'] ?? 0 );
		} elseif ( 'google' === $o['provider'] ) {
			$used = (int) ( $body['usageMetadata']['candidatesTokenCount'] ?? 0 );
		} else {
			$used = (int) ( $body['usage']['completion_tokens'] ?? 0 );
		}
		self::log( $o['provider'], $code, sprintf( /* translators: %d: number of tokens */ __( 'ok, %d tokens out', 'whd' ), $used ), $model );

		return (string) $text;
	}

	/** Strip the wrappers models like to add even when told not to. */
	private static function tidy( $text ) {
		$text = trim( (string) $text );
		$text = preg_replace( '/^```[a-z]*\s*\n?|\n?```$/i', '', $text );
		$text = preg_replace( '/^(here(\'s| is)[^\n]*:\s*\n+)/i', '', $text );
		return trim( $text );
	}

	/* ─────────────────────────── the check ─────────────────────────── */

	/** Normalise the way the site's own content checks do, so both agree on what counts as a hit. */
	private static function normalise( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = str_replace( [ '&nbsp;', '’', '‘', '–', '—' ], [ ' ', "'", "'", '-', '-' ], $text );
		$text = strtolower( $text );
		$text = preg_replace( "/[^a-z0-9'\- ]+/", ' ', $text );
		$text = preg_replace( '/\s+/', ' ', $text );
		return ' ' . trim( $text ) . ' ';
	}

	/**
	 * Does this draft obey the rules that can be checked by machine?
	 *
	 * Only the mechanical ones — banned words, keywords verbatim, length, stray H1. Whether the
	 * copy is any good is still a person's judgement, which is why nothing here auto-publishes.
	 *
	 * @return array [ ok, issues[], words, keywords[kw => bool] ]
	 */
	public static function check( $text, $keywords = [] ) {
		$o        = self::get();
		$haystack = self::normalise( $text );
		$words    = str_word_count( wp_strip_all_tags( (string) $text ) );
		$issues   = [];

		foreach ( self::banned_words() as $bad ) {
			$needle = rtrim( self::normalise( $bad ) );
			if ( ' ' === $needle || '' === $needle ) {
				continue;
			}
			if ( false !== strpos( $haystack, $needle . ' ' ) ) {
				$issues[] = [
					'type'   => 'banned',
					'detail' => sprintf( /* translators: %s: a banned word or phrase */ __( 'It uses the banned phrase “%s”. Rewrite that sentence without it.', 'whd' ), $bad ),
				];
			}
		}

		$found = [];
		foreach ( self::clean_keywords( $keywords ) as $keyword ) {
			$needle          = rtrim( self::normalise( $keyword ) );
			$hit             = '' !== trim( $needle ) && false !== strpos( $haystack, $needle . ' ' );
			$found[ $keyword ] = $hit;
			if ( ! $hit && $o['require_keywords'] ) {
				$issues[] = [
					'type'   => 'keyword',
					'detail' => sprintf( /* translators: %s: a keyword */ __( 'The keyword “%s” does not appear word for word. Add a sentence that contains it exactly.', 'whd' ), $keyword ),
				];
			}
		}

		if ( $o['min_words'] && $words < $o['min_words'] ) {
			$issues[] = [
				'type'   => 'length',
				'detail' => sprintf( /* translators: 1: word count, 2: minimum */ __( 'It is %1$d words; the minimum is %2$d. Add substance, not padding.', 'whd' ), $words, $o['min_words'] ),
			];
		}
		if ( $o['max_words'] && $words > $o['max_words'] ) {
			$issues[] = [
				'type'   => 'length',
				'detail' => sprintf( /* translators: 1: word count, 2: maximum */ __( 'It is %1$d words; the maximum is %2$d. Cut, do not summarise.', 'whd' ), $words, $o['max_words'] ),
			];
		}
		if ( preg_match( '/^#\s+\S/m', (string) $text ) ) {
			$issues[] = [
				'type'   => 'structure',
				'detail' => __( 'It contains a top-level heading. The page already has one — use ## instead.', 'whd' ),
			];
		}

		return [
			'ok'       => ! $issues,
			'issues'   => $issues,
			'words'    => $words,
			'keywords' => $found,
		];
	}

	/** Trim, drop blanks, de-duplicate — keywords arrive from a textarea as often as from the table. */
	public static function clean_keywords( $keywords ) {
		if ( is_string( $keywords ) ) {
			$keywords = preg_split( '/[\r\n,]+/', $keywords );
		}
		$out = [];
		foreach ( (array) $keywords as $keyword ) {
			$keyword = trim( wp_strip_all_tags( (string) $keyword ) );
			if ( '' !== $keyword && ! in_array( $keyword, $out, true ) ) {
				$out[] = $keyword;
			}
		}
		return $out;
	}

	/* ─────────────────────────── markdown → HTML ─────────────────────────── */

	/**
	 * Turn the model's markdown into the HTML WordPress stores.
	 *
	 * A deliberately small subset — headings, paragraphs, lists, bold, italic, links. Anything else
	 * the model invents is left as text rather than guessed at.
	 */
	public static function to_html( $markdown ) {
		$lines = preg_split( '/\R/', (string) $markdown );
		$html  = '';
		$list  = '';

		$inline = static function ( $text ) {
			$text = esc_html( trim( $text ) );
			$text = preg_replace( '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', '<a href="$2">$1</a>', $text );
			$text = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text );
			$text = preg_replace( '/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $text );
			return $text;
		};

		$close = static function () use ( &$list, &$html ) {
			if ( $list ) {
				$html .= '</' . $list . ">\n";
				$list  = '';
			}
		};

		foreach ( $lines as $line ) {
			$line = rtrim( $line );
			if ( '' === trim( $line ) ) {
				$close();
				continue;
			}
			if ( preg_match( '/^(#{2,4})\s+(.*)$/', $line, $m ) ) {
				$close();
				$level = min( 4, strlen( $m[1] ) );
				$html .= sprintf( "<h%d>%s</h%d>\n", $level, $inline( $m[2] ), $level );
				continue;
			}
			if ( preg_match( '/^[-*]\s+(.*)$/', $line, $m ) ) {
				if ( 'ul' !== $list ) {
					$close();
					$html .= "<ul>\n";
					$list  = 'ul';
				}
				$html .= '<li>' . $inline( $m[1] ) . "</li>\n";
				continue;
			}
			if ( preg_match( '/^\d+[.)]\s+(.*)$/', $line, $m ) ) {
				if ( 'ol' !== $list ) {
					$close();
					$html .= "<ol>\n";
					$list  = 'ol';
				}
				$html .= '<li>' . $inline( $m[1] ) . "</li>\n";
				continue;
			}
			$close();
			$html .= '<p>' . $inline( $line ) . "</p>\n";
		}
		$close();

		return trim( $html );
	}

	/**
	 * Turn the model's markdown into WHD blocks, for the product story builder.
	 *
	 * Headings become heading blocks and everything between them becomes one text block, so what
	 * lands on the canvas is editable in the same drag-and-drop editor as a hand-written story.
	 */
	public static function to_blocks( $markdown ) {
		$blocks = [];
		$buffer = [];

		$flush = static function () use ( &$buffer, &$blocks ) {
			$body = trim( implode( "\n", $buffer ) );
			$buffer = [];
			if ( '' === $body ) {
				return;
			}
			$blocks[] = [
				'type'  => 'text',
				'props' => [
					'text'  => self::to_html( $body ),
					'align' => 'left',
					'size'  => 17,
				],
			];
		};

		foreach ( preg_split( '/\R/', (string) $markdown ) as $line ) {
			if ( preg_match( '/^(#{2,4})\s+(.*)$/', rtrim( $line ), $m ) ) {
				$flush();
				$blocks[] = [
					'type'  => 'heading',
					'props' => [
						'text'  => trim( wp_strip_all_tags( $m[2] ) ),
						'level' => 2 === strlen( $m[1] ) ? 'h2' : 'h3',
						'align' => 'left',
					],
				];
				continue;
			}
			$buffer[] = $line;
		}
		$flush();

		return $blocks;
	}

	/* ─────────────────────────── log ─────────────────────────── */

	public static function logs() {
		$log = get_option( self::LOG_OPTION, [] );
		return is_array( $log ) ? $log : [];
	}

	private static function log( $provider, $code, $message, $model ) {
		$log   = self::logs();
		$log[] = [
			'time'     => current_time( 'mysql' ),
			'provider' => $provider,
			'model'    => $model,
			'code'     => (int) $code,
			'message'  => wp_trim_words( (string) $message, 30 ),
		];
		update_option( self::LOG_OPTION, array_slice( $log, -self::LOG_MAX ), false );
	}
}
