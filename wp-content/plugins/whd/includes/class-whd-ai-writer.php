<?php
/**
 * AI Posts and AI Pages: write a page from an idea, and keep it where you can find it again.
 *
 * What it makes is an ordinary post or page — same permalink, same template, same everything the
 * theme does — with one piece of meta on it saying where it came from. That flag does two things:
 * it keeps these out of the normal Posts and Pages lists, so the screens here are the one place
 * they live, and it tells the front end to print the structured data this module writes for them.
 *
 * The copy goes through the same house rules, banned words and keyword check as everything else
 * the AI panel writes; what is different here is that the model is asked for a whole page at once,
 * as data rather than prose, so the title, the search snippet, the sections and the questions can
 * each be put where they belong.
 *
 * @package WHD
 */

defined( 'ABSPATH' ) || exit;

final class WHD_AI_Writer {

	const ORIGIN   = '_whd_ai_origin';     // 'ai' — the flag everything else keys off
	const IDEA     = '_whd_ai_idea';       // what was asked for, kept so it can be rewritten
	const KEYWORDS = '_whd_ai_keywords';
	const FAQ      = '_whd_ai_faq';
	const PAGE_POST = 'whd-ai-posts';
	const PAGE_PAGE = 'whd-ai-pages';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'rest' ] );

		// Out of the ordinary lists, and out of their counts, so the numbers agree with the rows.
		add_action( 'pre_get_posts', [ __CLASS__, 'hide_from_lists' ] );
		add_filter( 'wp_count_posts', [ __CLASS__, 'adjust_counts' ], 10, 2 );
		add_filter( 'display_post_states', [ __CLASS__, 'post_state' ], 10, 2 );

		// Front end: the search snippet and the structured data.
		add_filter( 'omc_meta_description', [ __CLASS__, 'meta_description' ], 10, 2 );
		add_action( 'wp_head', [ __CLASS__, 'schema' ], 9 );
	}

	/* ─────────────────────────── where it lives ─────────────────────────── */

	public static function menu() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		add_menu_page(
			__( 'AI Posts', 'whd' ),
			__( 'AI Posts', 'whd' ),
			'edit_posts',
			self::PAGE_POST,
			[ __CLASS__, 'screen_posts' ],
			'dashicons-edit-large',
			27
		);
		add_menu_page(
			__( 'AI Pages', 'whd' ),
			__( 'AI Pages', 'whd' ),
			'edit_pages',
			self::PAGE_PAGE,
			[ __CLASS__, 'screen_pages' ],
			'dashicons-media-document',
			28
		);
	}

	/** Everything this module made, newest first. */
	public static function all( string $type, int $limit = 50 ): array {
		return get_posts( [
			'post_type'   => $type,
			'numberposts' => $limit,
			'post_status' => [ 'publish', 'draft', 'pending', 'future', 'private' ],
			'orderby'     => 'date',
			'order'       => 'DESC',
			'meta_key'    => self::ORIGIN,   // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'  => 'ai',           // phpcs:ignore WordPress.DB.SlowDBQuery
		] );
	}

	/**
	 * Keep them out of Posts → All Posts and Pages → All Pages.
	 *
	 * They are ordinary posts, so without this they would appear in both places and the whole
	 * point — one screen where these live — would be lost.
	 */
	public static function hide_from_lists( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		if ( 'edit.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) {
			return;
		}
		$type = (string) $query->get( 'post_type' );
		if ( ! in_array( $type, [ 'post', 'page' ], true ) ) {
			return;
		}
		$meta   = (array) $query->get( 'meta_query' );
		$meta[] = [ 'key' => self::ORIGIN, 'compare' => 'NOT EXISTS' ];
		$query->set( 'meta_query', $meta );
	}

	/** The "All (12)" counts above those lists, so they match what is underneath. */
	public static function adjust_counts( $counts, $type ) {
		if ( 'edit.php' !== ( $GLOBALS['pagenow'] ?? '' ) || ! in_array( $type, [ 'post', 'page' ], true ) ) {
			return $counts;
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT p.post_status, COUNT(*) n FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
			 WHERE p.post_type = %s GROUP BY p.post_status",
			self::ORIGIN,
			$type
		) );
		foreach ( $rows as $row ) {
			if ( isset( $counts->{$row->post_status} ) ) {
				$counts->{$row->post_status} = max( 0, (int) $counts->{$row->post_status} - (int) $row->n );
			}
		}

		return $counts;
	}

	/** If one is seen anywhere else — a search, a trash list — say what it is. */
	public static function post_state( $states, $post ) {
		if ( 'ai' === get_post_meta( $post->ID, self::ORIGIN, true ) ) {
			$states['whd_ai'] = __( 'Written with AI', 'whd' );
		}

		return $states;
	}

	/* ─────────────────────────── the screens ─────────────────────────── */

	public static function assets( $hook ) {
		if ( ! in_array( $hook, [ 'toplevel_page_' . self::PAGE_POST, 'toplevel_page_' . self::PAGE_PAGE ], true ) ) {
			return;
		}
		wp_enqueue_style( 'whd-admin', WHD_URL . 'admin/admin.css', [], WHD_VERSION );
		wp_enqueue_style( 'whd-ai-writer', WHD_URL . 'admin/ai-writer.css', [ 'whd-admin' ], WHD_VERSION );
		wp_enqueue_style( 'whd-keyword-picker', WHD_URL . 'admin/keyword-picker.css', [], WHD_VERSION );
		wp_enqueue_script( 'whd-keyword-picker', WHD_URL . 'admin/keyword-picker.js', [], WHD_VERSION, true );
		wp_enqueue_script( 'whd-ai-writer', WHD_URL . 'admin/ai-writer.js', [ 'whd-keyword-picker' ], WHD_VERSION, true );

		$type = ( 'toplevel_page_' . self::PAGE_POST ) === $hook ? 'post' : 'page';
		wp_localize_script( 'whd-ai-writer', 'WHD_AI_WRITER', [
			'rest'  => [ 'root' => esc_url_raw( rest_url( 'whd/v1/ai/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ],
			'type'  => $type,
			'ready' => WHD_AI::ready(),
			'i18n'  => [
				'writing'  => __( 'Writing — this takes a few seconds…', 'whd' ),
				'saving'   => __( 'Saving…', 'whd' ),
				'error'    => __( 'Something went wrong', 'whd' ),
				'noIdea'   => __( 'Say what it should be about first.', 'whd' ),
				'words'    => __( 'words', 'whd' ),
				'sections' => __( 'sections', 'whd' ),
				'faqs'     => __( 'questions', 'whd' ),
			],
		] );
	}

	public static function screen_posts() {
		self::screen( 'post' );
	}

	public static function screen_pages() {
		self::screen( 'page' );
	}

	private static function screen( string $type ) {
		$is_post = 'post' === $type;
		?>
		<div class="wrap whd-wrap whd-writer" data-type="<?php echo esc_attr( $type ); ?>">
			<h1 class="whd-title"><?php echo esc_html( $is_post ? __( 'AI Posts', 'whd' ) : __( 'AI Pages', 'whd' ) ); ?></h1>
			<p class="whd-intro">
				<?php
				echo esc_html(
					$is_post
						? __( 'Say what the post should be about. What comes back is a finished journal post — headings, a search snippet, the keywords worked in and the structured data behind it — saved here rather than in the main Posts list, so you always know where these live.', 'whd' )
						: __( 'Say what the page should be about. What comes back is a finished page — headings, a search snippet, the keywords worked in and the structured data behind it — saved here rather than in the main Pages list, so you always know where these live.', 'whd' )
				);
				?>
			</p>

			<?php if ( ! WHD_AI::ready() ) : ?>
				<div class="whd-note whd-note--warn">
					<?php esc_html_e( 'No model is connected yet, so nothing can be written.', 'whd' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=whd-ai' ) ); ?>"><?php esc_html_e( 'Connect one in WHD → AI content.', 'whd' ); ?></a>
				</div>
			<?php else : ?>
				<?php self::compose_form( $type ); ?>
			<?php endif; ?>

			<?php self::list_table( $type ); ?>
		</div>
		<?php
	}

	private static function compose_form( string $type ) {
		$total = class_exists( 'WHD_AI_Keywords' ) ? WHD_AI_Keywords::count() : 0;
		?>
		<div class="whd-writer__make" id="whd-writer">

			<div class="whd-writer__field">
				<label class="whd-writer__label" for="whd-writer-idea"><?php esc_html_e( 'What should it be about?', 'whd' ); ?></label>
				<textarea id="whd-writer-idea" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'e.g. how to choose between a wrap vest and a cardigan for a Korean winter, for someone who works in an office', 'whd' ); ?>"></textarea>
				<p class="description"><?php esc_html_e( 'A sentence is enough. The more you say about who it is for, the less you will have to edit afterwards.', 'whd' ); ?></p>
			</div>

			<div class="whd-writer__field">
				<span class="whd-writer__label"><?php esc_html_e( 'Keywords to work in', 'whd' ); ?></span>
				<div class="whd-kw" id="whd-writer-keywords" data-whd-keywords
					data-placeholder="<?php esc_attr_e( 'Search your keywords, or type your own', 'whd' ); ?>"></div>
				<p class="description">
					<?php
					printf(
						/* translators: %s: number of keywords in the list */
						esc_html__( 'Type to search all %s of your keywords — monthly volume and how hard each one is to rank for are shown beside it. A phrase that is not in the list works too: type it and press Enter.', 'whd' ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=whd-ai&tab=keywords' ) ); ?>"><?php esc_html_e( 'Manage the list', 'whd' ); ?></a>
				</p>
			</div>

			<div class="whd-writer__row">
				<label class="whd-writer__inline">
					<span><?php esc_html_e( 'Length', 'whd' ); ?></span>
					<select id="whd-writer-length">
						<option value="short"><?php esc_html_e( 'Short — about 400 words', 'whd' ); ?></option>
						<option value="medium" selected><?php esc_html_e( 'Medium — about 700 words', 'whd' ); ?></option>
						<option value="long"><?php esc_html_e( 'Long — about 1,100 words', 'whd' ); ?></option>
					</select>
				</label>
				<label class="whd-writer__inline">
					<span><?php esc_html_e( 'Questions at the end', 'whd' ); ?></span>
					<select id="whd-writer-faq">
						<option value="1" selected><?php esc_html_e( 'Yes — with FAQ structured data', 'whd' ); ?></option>
						<option value="0"><?php esc_html_e( 'No', 'whd' ); ?></option>
					</select>
				</label>
				<button type="button" class="button button-primary button-hero" id="whd-writer-go"><?php esc_html_e( 'Write it', 'whd' ); ?></button>
				<span class="whd-writer__status" id="whd-writer-status" role="status"></span>
			</div>

			<div class="whd-writer__result" id="whd-writer-result" hidden>
				<div class="whd-writer__meta">
					<label class="whd-writer__field">
						<span class="whd-writer__label"><?php esc_html_e( 'Title', 'whd' ); ?></span>
						<input type="text" class="large-text" id="whd-writer-title">
					</label>
					<label class="whd-writer__field">
						<span class="whd-writer__label"><?php esc_html_e( 'Search snippet', 'whd' ); ?></span>
						<textarea rows="2" class="large-text" id="whd-writer-desc"></textarea>
						<span class="whd-writer__count" id="whd-writer-desc-count"></span>
					</label>
				</div>

				<div class="whd-writer__check" id="whd-writer-check"></div>

				<div class="whd-writer__preview">
					<p class="whd-writer__label"><?php esc_html_e( 'The page itself', 'whd' ); ?></p>
					<div class="whd-writer__body" id="whd-writer-body" contenteditable="true" spellcheck="true"></div>
					<p class="description"><?php esc_html_e( 'Edit anything here before you save it — what you save is what is in this box.', 'whd' ); ?></p>
				</div>

				<p class="whd-writer__actions">
					<button type="button" class="button button-primary" id="whd-writer-publish"><?php esc_html_e( 'Publish it', 'whd' ); ?></button>
					<button type="button" class="button" id="whd-writer-draft"><?php esc_html_e( 'Save as a draft', 'whd' ); ?></button>
					<button type="button" class="button" id="whd-writer-again"><?php esc_html_e( 'Write another', 'whd' ); ?></button>
					<span class="whd-writer__status" id="whd-writer-save-status" role="status"></span>
				</p>
			</div>
		</div>
		<?php
	}

	/* ─────────────────────────── writing it ─────────────────────────── */

	/**
	 * Ask for a whole page at once, as data.
	 *
	 * Prose would have to be taken apart again to find the title and the snippet, and taking prose
	 * apart is guesswork. Asking for the parts separately means each one can go where it belongs.
	 *
	 * @return array|WP_Error
	 */
	public static function compose( string $idea, array $keywords, string $type, string $length = 'medium', bool $faq = true ) {
		$idea = trim( wp_strip_all_tags( $idea ) );
		if ( '' === $idea ) {
			return new WP_Error( 'whd_ai_idea', __( 'Say what it should be about first.', 'whd' ) );
		}
		if ( ! WHD_AI::ready() ) {
			return new WP_Error( 'whd_ai_off', __( 'No model is connected.', 'whd' ) );
		}

		$words    = [ 'short' => 400, 'medium' => 700, 'long' => 1100 ][ $length ] ?? 700;
		$sections = [ 'short' => '3', 'medium' => '4 or 5', 'long' => '6 or 7' ][ $length ] ?? '4 or 5';
		$keywords = WHD_AI::clean_keywords( $keywords );

		$system = WHD_AI::system_prompt() . "\n\n"
			. "## This job\n"
			. __( 'You are writing one complete page. Return JSON and nothing else — no prose around it, no code fence. Every string is plain text: no HTML, no markdown.', 'whd' );

		$shape = <<<'JSON'
{
  "title": "the headline, under 65 characters, written for a reader",
  "seo_title": "the browser-tab and search-result title, under 60 characters",
  "meta_description": "one sentence, 140-155 characters, that would make somebody click",
  "slug": "lower-case-words-with-hyphens",
  "excerpt": "two sentences that stand on their own as a summary",
  "intro": ["an opening paragraph with no heading", "a second if it needs one"],
  "sections": [
    { "heading": "a section heading", "paragraphs": ["…", "…"], "bullets": ["optional", "only where a list genuinely helps"] }
  ],
  "faqs": [ { "question": "something a reader would actually type", "answer": "two or three sentences" } ],
  "keywords": ["the phrases you actually worked into the copy, verbatim"]
}
JSON;

		$user = sprintf(
			/* translators: 1: the idea, 2: word count, 3: how many sections, 4: keywords, 5: the JSON shape */
			__(
				"Write a %6\$s about this:\n\n%1\$s\n\nAbout %2\$d words in %3\$s sections. %4\$s\n\nRules that matter here:\n"
				. "- the title says what the reader gets, and is not a question unless the page answers one\n"
				. "- every section heading is a phrase somebody might search, not a label like \"Introduction\" or \"Conclusion\"\n"
				. "- no section called conclusion, summary or final thoughts\n"
				. "- say nothing the shop cannot stand behind: no delivery times, no discounts, no stock claims, no named brands, no invented statistics\n"
				. "- the meta description is a sentence, not a list of keywords\n\n"
				. "Return exactly this shape:\n%5\$s",
				'whd'
			),
			$idea,
			$words,
			$sections,
			$keywords
				? sprintf(
					/* translators: %s: comma separated keywords */
					__( 'Work these in verbatim, each at least once, where they read naturally: %s.', 'whd' ),
					implode( ', ', $keywords )
				)
				: __( 'Choose the two or three phrases a reader would search for and use them in the copy.', 'whd' ),
			$shape,
			'post' === $type ? __( 'journal post', 'whd' ) : __( 'page', 'whd' )
		);

		if ( ! $faq ) {
			$user .= "\n\n" . __( 'Leave "faqs" as an empty list.', 'whd' );
		}

		$raw = WHD_AI::call( $system, $user, [ 'json' => true ] );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$doc = json_decode( trim( preg_replace( '/^```[a-z]*\s*|\s*```$/i', '', (string) $raw ) ), true );
		if ( ! is_array( $doc ) || empty( $doc['sections'] ) ) {
			return new WP_Error( 'whd_ai_shape', __( 'The model did not return a page in the shape asked for. Try again.', 'whd' ) );
		}

		$doc         = self::tidy( $doc, $keywords, $faq );
		$doc['html'] = self::to_html( $doc );

		/*
		 * The same check the rest of the panel uses — banned words, and keywords claimed but not
		 * actually in the copy — except for its length rule, which is set for a product
		 * description. A whole page is meant to be longer than that, so it is judged against what
		 * was asked for here instead.
		 */
		$check            = WHD_AI::check( wp_strip_all_tags( $doc['html'] ), $doc['keywords'] );
		$check['issues']  = array_values( array_filter(
			(array) ( $check['issues'] ?? [] ),
			static fn( $issue ) => 'length' !== ( is_array( $issue ) ? ( $issue['type'] ?? '' ) : '' )
		) );
		$count = (int) ( $check['words'] ?? 0 );
		if ( $count < (int) round( $words * 0.6 ) ) {
			$check['issues'][] = [
				'type'   => 'length',
				/* translators: 1: words written, 2: words asked for */
				'detail' => sprintf( __( 'It is %1$d words against the %2$d asked for — short enough to be worth another go.', 'whd' ), $count, $words ),
			];
		}
		$check['ok']  = empty( $check['issues'] ) && ! in_array( false, (array) ( $check['keywords'] ?? [] ), true );
		$doc['check'] = $check;

		return $doc;
	}

	/** Trim what came back to what will be saved. */
	private static function tidy( array $doc, array $keywords, bool $faq ): array {
		$text = static fn( $v ) => trim( wp_strip_all_tags( (string) $v ) );
		$list = static function ( $v ) use ( $text ) {
			return array_values( array_filter( array_map( $text, (array) $v ) ) );
		};

		$out = [
			'title'            => $text( $doc['title'] ?? '' ),
			'seo_title'        => $text( $doc['seo_title'] ?? '' ),
			'meta_description' => $text( $doc['meta_description'] ?? '' ),
			'slug'             => sanitize_title( $doc['slug'] ?? ( $doc['title'] ?? '' ) ),
			'excerpt'          => $text( $doc['excerpt'] ?? '' ),
			'intro'            => $list( $doc['intro'] ?? [] ),
			'sections'         => [],
			'faqs'             => [],
			'keywords'         => $keywords ?: $list( $doc['keywords'] ?? [] ),
		];

		foreach ( (array) ( $doc['sections'] ?? [] ) as $section ) {
			$heading = $text( $section['heading'] ?? '' );
			$paras   = $list( $section['paragraphs'] ?? [] );
			if ( '' === $heading && ! $paras ) {
				continue;
			}
			$out['sections'][] = [
				'heading'    => $heading,
				'paragraphs' => $paras,
				'bullets'    => $list( $section['bullets'] ?? [] ),
			];
		}

		if ( $faq ) {
			foreach ( (array) ( $doc['faqs'] ?? [] ) as $item ) {
				$q = $text( $item['question'] ?? '' );
				$a = $text( $item['answer'] ?? '' );
				if ( '' !== $q && '' !== $a ) {
					$out['faqs'][] = [ 'question' => $q, 'answer' => $a ];
				}
			}
		}

		return $out;
	}

	/** The body, as the editor will hold it. */
	public static function to_html( array $doc ): string {
		$html = '';
		foreach ( (array) ( $doc['intro'] ?? [] ) as $p ) {
			$html .= '<p>' . esc_html( $p ) . "</p>\n";
		}
		foreach ( (array) ( $doc['sections'] ?? [] ) as $section ) {
			if ( ! empty( $section['heading'] ) ) {
				$html .= '<h2>' . esc_html( $section['heading'] ) . "</h2>\n";
			}
			foreach ( (array) ( $section['paragraphs'] ?? [] ) as $p ) {
				$html .= '<p>' . esc_html( $p ) . "</p>\n";
			}
			if ( ! empty( $section['bullets'] ) ) {
				$html .= "<ul>\n";
				foreach ( $section['bullets'] as $b ) {
					$html .= '<li>' . esc_html( $b ) . "</li>\n";
				}
				$html .= "</ul>\n";
			}
		}
		if ( ! empty( $doc['faqs'] ) ) {
			$html .= '<h2>' . esc_html__( 'Questions people ask', 'whd' ) . "</h2>\n";
			foreach ( $doc['faqs'] as $faq ) {
				$html .= '<h3>' . esc_html( $faq['question'] ) . "</h3>\n";
				$html .= '<p>' . esc_html( $faq['answer'] ) . "</p>\n";
			}
		}

		return trim( $html );
	}

	/**
	 * The questions as the page actually asks them.
	 *
	 * Everything after the FAQ heading is read back: an h3 is a question, the paragraph under it
	 * is the answer. Falls back to what the model returned if the heading has been edited away.
	 */
	public static function faqs_from_html( string $html, array $fallback = [] ): array {
		$heading = preg_quote( __( 'Questions people ask', 'whd' ), '#' );
		if ( ! preg_match( '#<h2[^>]*>\s*' . $heading . '\s*</h2>(.*)$#is', $html, $tail ) ) {
			$clean = [];
			foreach ( $fallback as $f ) {
				if ( ! empty( $f['question'] ) && ! empty( $f['answer'] ) ) {
					$clean[] = [
						'question' => sanitize_text_field( $f['question'] ),
						'answer'   => sanitize_textarea_field( $f['answer'] ),
					];
				}
			}

			return $clean;
		}

		preg_match_all( '#<h3[^>]*>(.*?)</h3>\s*(?:<p[^>]*>(.*?)</p>)?#is', $tail[1], $pairs, PREG_SET_ORDER );
		$out = [];
		foreach ( $pairs as $pair ) {
			$q = sanitize_text_field( html_entity_decode( wp_strip_all_tags( $pair[1] ), ENT_QUOTES, 'UTF-8' ) );
			$a = sanitize_textarea_field( html_entity_decode( wp_strip_all_tags( $pair[2] ?? '' ), ENT_QUOTES, 'UTF-8' ) );
			if ( '' !== $q && '' !== $a ) {
				$out[] = [ 'question' => $q, 'answer' => $a ];
			}
		}

		return $out;
	}

	/**
	 * Save it as a real post or page, flagged as ours.
	 *
	 * @return array|WP_Error
	 */
	public static function save( array $doc, string $type, string $status = 'draft' ) {
		$type   = in_array( $type, [ 'post', 'page' ], true ) ? $type : 'post';
		$status = in_array( $status, [ 'publish', 'draft' ], true ) ? $status : 'draft';
		$title  = trim( wp_strip_all_tags( (string) ( $doc['title'] ?? '' ) ) );
		$html   = (string) ( $doc['html'] ?? '' );
		if ( '' === $title || '' === trim( wp_strip_all_tags( $html ) ) ) {
			return new WP_Error( 'whd_ai_empty', __( 'There is nothing here to save.', 'whd' ) );
		}

		$id = wp_insert_post( [
			'post_type'    => $type,
			'post_status'  => $status,
			'post_title'   => $title,
			'post_name'    => sanitize_title( $doc['slug'] ?? $title ),
			'post_content' => wp_kses_post( $html ),
			'post_excerpt' => sanitize_textarea_field( (string) ( $doc['excerpt'] ?? '' ) ),
		], true );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		update_post_meta( $id, self::ORIGIN, 'ai' );
		update_post_meta( $id, self::IDEA, sanitize_textarea_field( (string) ( $doc['idea'] ?? '' ) ) );
		update_post_meta( $id, self::KEYWORDS, array_map( 'sanitize_text_field', (array) ( $doc['keywords'] ?? [] ) ) );
		/*
		 * Read back out of the copy that is actually being saved rather than kept from the draft:
		 * the box is editable, and structured data that disagrees with the page it describes is
		 * worse than none at all.
		 */
		update_post_meta( $id, self::FAQ, self::faqs_from_html( $html, (array) ( $doc['faqs'] ?? [] ) ) );

		// The two the theme's own SEO head reads.
		if ( ! empty( $doc['meta_description'] ) ) {
			update_post_meta( $id, '_omc_meta_description', sanitize_textarea_field( $doc['meta_description'] ) );
		}
		if ( ! empty( $doc['seo_title'] ) ) {
			update_post_meta( $id, '_omc_seo_title', sanitize_text_field( $doc['seo_title'] ) );
		}

		if ( class_exists( 'WHD_AI_Keywords' ) ) {
			WHD_AI_Keywords::mark_used( (array) ( $doc['keywords'] ?? [] ) );
		}

		return [
			'id'        => (int) $id,
			'edit_url'  => get_edit_post_link( $id, 'raw' ),
			'view_url'  => get_permalink( $id ),
			'status'    => $status,
			'title'     => $title,
		];
	}

	/* ─────────────────────────── what search engines read ─────────────────────────── */

	/** The theme asks every page for its description; ours has one written for it. */
	public static function meta_description( $description, $post_id = 0 ) {
		$post_id = $post_id ?: (int) get_queried_object_id();
		if ( ! $post_id || 'ai' !== get_post_meta( $post_id, self::ORIGIN, true ) ) {
			return $description;
		}
		$own = (string) get_post_meta( $post_id, '_omc_meta_description', true );

		return $own ?: $description;
	}

	/**
	 * Article and FAQ structured data, from what was written rather than scraped back out.
	 *
	 * Only on our own pages, and only when no SEO plugin is doing it already — two Article blocks
	 * on one page is worse than none.
	 */
	public static function schema() {
		if ( ! is_singular( [ 'post', 'page' ] ) ) {
			return;
		}
		$id = (int) get_queried_object_id();
		if ( 'ai' !== get_post_meta( $id, self::ORIGIN, true ) ) {
			return;
		}
		if ( function_exists( 'omc_has_seo_plugin' ) && omc_has_seo_plugin() ) {
			return;
		}

		$post  = get_post( $id );
		$graph = [];

		$article = [
			'@context'         => 'https://schema.org',
			'@type'            => is_page( $id ) ? 'WebPage' : 'BlogPosting',
			'@id'              => get_permalink( $id ) . '#main',
			'headline'         => wp_strip_all_tags( get_the_title( $id ) ),
			'description'      => (string) get_post_meta( $id, '_omc_meta_description', true ),
			'url'              => get_permalink( $id ),
			'datePublished'    => get_the_date( 'c', $post ),
			'dateModified'     => get_the_modified_date( 'c', $post ),
			'inLanguage'       => get_bloginfo( 'language' ),
			'isPartOf'         => [ '@type' => 'WebSite', 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ) ],
			'publisher'        => [ '@type' => 'Organization', 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ) ],
		];
		$keywords = (array) get_post_meta( $id, self::KEYWORDS, true );
		if ( $keywords ) {
			$article['keywords'] = implode( ', ', $keywords );
		}
		if ( has_post_thumbnail( $id ) ) {
			$article['image'] = get_the_post_thumbnail_url( $id, 'large' );
		}
		$graph[] = array_filter( $article );

		$faqs = (array) get_post_meta( $id, self::FAQ, true );
		if ( $faqs ) {
			$entities = [];
			foreach ( $faqs as $faq ) {
				if ( empty( $faq['question'] ) || empty( $faq['answer'] ) ) {
					continue;
				}
				$entities[] = [
					'@type'          => 'Question',
					'name'           => $faq['question'],
					'acceptedAnswer' => [ '@type' => 'Answer', 'text' => $faq['answer'] ],
				];
			}
			if ( $entities ) {
				$graph[] = [
					'@context'   => 'https://schema.org',
					'@type'      => 'FAQPage',
					'mainEntity' => $entities,
				];
			}
		}

		foreach ( $graph as $block ) {
			printf(
				"<script type=\"application/ld+json\">%s</script>\n",
				wp_json_encode( $block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			);
		}
	}

	/* ─────────────────────────── REST ─────────────────────────── */

	public static function rest() {
		$can = static function () {
			return current_user_can( 'edit_posts' );
		};
		register_rest_route( 'whd/v1', '/ai/compose', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_compose' ],
			'permission_callback' => $can,
		] );
		register_rest_route( 'whd/v1', '/ai/write-save', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_save' ],
			'permission_callback' => $can,
		] );
	}

	public static function rest_compose( WP_REST_Request $r ) {
		$doc = self::compose(
			(string) $r->get_param( 'idea' ),
			(array) $r->get_param( 'keywords' ),
			sanitize_key( (string) $r->get_param( 'type' ) ),
			sanitize_key( (string) $r->get_param( 'length' ) ),
			(bool) $r->get_param( 'faq' )
		);
		if ( is_wp_error( $doc ) ) {
			return new WP_REST_Response( [ 'message' => $doc->get_error_message() ], 400 );
		}

		return new WP_REST_Response( $doc, 200 );
	}

	public static function rest_save( WP_REST_Request $r ) {
		$doc = (array) $r->get_param( 'doc' );
		$doc['idea'] = (string) $r->get_param( 'idea' );
		$result = self::save(
			$doc,
			sanitize_key( (string) $r->get_param( 'type' ) ),
			sanitize_key( (string) $r->get_param( 'status' ) )
		);
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'message' => $result->get_error_message() ], 400 );
		}

		return new WP_REST_Response( $result, 200 );
	}

	private static function list_table( string $type ) {
		$items = self::all( $type );
		echo '<h2 class="whd-h2">' . esc_html( 'post' === $type ? __( 'Posts written here', 'whd' ) : __( 'Pages written here', 'whd' ) ) . '</h2>';

		if ( ! $items ) {
			echo '<div class="whd-note">' . esc_html__( 'Nothing yet. Whatever you write above will be listed here, and nowhere else.', 'whd' ) . '</div>';
			return;
		}

		echo '<table class="widefat striped whd-table whd-writer__table"><thead><tr>'
			. '<th>' . esc_html__( 'Title', 'whd' ) . '</th>'
			. '<th>' . esc_html__( 'Keywords', 'whd' ) . '</th>'
			. '<th>' . esc_html__( 'Status', 'whd' ) . '</th>'
			. '<th>' . esc_html__( 'Written', 'whd' ) . '</th>'
			. '<th></th></tr></thead><tbody>';

		foreach ( $items as $item ) {
			$keywords = (array) get_post_meta( $item->ID, self::KEYWORDS, true );
			$status   = get_post_status_object( $item->post_status );
			printf(
				'<tr><td class="whd-writer__cell-title"><strong><a href="%1$s">%2$s</a></strong><div class="whd-writer__idea">%3$s</div></td>'
				. '<td>%4$s</td><td><span class="whd-pill whd-pill--%5$s">%6$s</span></td><td>%7$s</td>'
				. '<td class="whd-writer__cell-actions"><a class="button button-small" href="%1$s">%8$s</a> <a class="button button-small" href="%9$s" target="_blank" rel="noopener">%10$s</a></td></tr>',
				esc_url( get_edit_post_link( $item->ID ) ),
				esc_html( $item->post_title ?: __( '(no title)', 'whd' ) ),
				esc_html( mb_strimwidth( (string) get_post_meta( $item->ID, self::IDEA, true ), 0, 110, '…' ) ),
				$keywords ? esc_html( implode( ', ', array_slice( $keywords, 0, 4 ) ) ) : '<span class="whd-muted">—</span>',
				esc_attr( 'publish' === $item->post_status ? 'on' : 'off' ),
				esc_html( $status ? $status->label : $item->post_status ),
				esc_html( get_the_date( 'j M Y', $item ) ),
				esc_html__( 'Edit', 'whd' ),
				esc_url( get_permalink( $item->ID ) ),
				esc_html__( 'View', 'whd' )
			);
		}
		echo '</tbody></table>';
	}
}
