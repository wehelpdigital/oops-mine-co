<?php
/**
 * WHD — SEO score.
 *
 * A box on every product, post and page that reads what is written and says how well it will be
 * found, in the shape Yoast made familiar: a focus keyphrase, a list of checks, a traffic light.
 *
 * Three things make it this site's rather than a copy of Yoast's:
 *
 *   1. the keyphrase comes from the researched keyword list, with its volume and difficulty, and
 *      the box says when another page is already aimed at the same phrase;
 *   2. the house rules are checks like any other — the banned-word list, and the claims this shop
 *      cannot back (delivery estimates, carriers, discounts, stock counts), which are the same
 *      rules the AI writer is held to;
 *   3. it knows what structured data the templates emit, so "this page has no schema" is only
 *      ever said when it is true.
 *
 * Nothing here writes to the page. It reads, scores, and stores the score so the posts list can
 * show it. The analysis runs server-side on the saved content and again, live, on whatever the
 * editor currently holds.
 *
 * @package WHD
 */

defined( 'ABSPATH' ) || exit;

class WHD_SEO {

	const KEYPHRASE = '_whd_focus_keyphrase';
	const SCORE     = '_whd_seo_score';
	const TYPES     = [ 'post', 'page', 'product' ];

	public static function init() {
		add_action( 'add_meta_boxes', [ __CLASS__, 'meta_box' ] );
		add_action( 'save_post', [ __CLASS__, 'save' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'rest' ] );

		foreach ( self::TYPES as $type ) {
			add_filter( 'manage_' . ( 'post' === $type ? '' : $type . '_' ) . 'posts_columns', [ __CLASS__, 'column' ] );
			add_action( 'manage_' . ( 'post' === $type ? '' : $type . '_' ) . 'posts_custom_column', [ __CLASS__, 'column_value' ], 10, 2 );
		}
	}

	/* ─────────────────────────── where it appears ─────────────────────────── */

	public static function meta_box() {
		foreach ( self::TYPES as $type ) {
			add_meta_box(
				'whd-seo-box',
				__( 'SEO score', 'whd' ),
				[ __CLASS__, 'box' ],
				$type,
				'product' === $type ? 'normal' : 'side',
				'product' === $type ? 'low' : 'default'
			);
		}
	}

	public static function box( $post ) {
		$keyphrase = (string) get_post_meta( $post->ID, self::KEYPHRASE, true );
		if ( '' === $keyphrase ) {
			$keyphrase = self::researched_for( $post->ID );
		}
		wp_nonce_field( 'whd_seo_' . $post->ID, 'whd_seo_nonce' );
		?>
		<div class="whd-seo" id="whd-seo" data-post="<?php echo esc_attr( $post->ID ); ?>" data-type="<?php echo esc_attr( $post->post_type ); ?>">
			<p class="whd-seo__field">
				<label class="whd-seo__label" for="whd-seo-keyphrase-input"><?php esc_html_e( 'Focus keyphrase', 'whd' ); ?></label>
				<span class="whd-kw whd-kw--single" id="whd-seo-keyphrase" data-whd-keywords data-max="1"
					data-selected="<?php echo esc_attr( $keyphrase ); ?>"
					data-placeholder="<?php esc_attr_e( 'Search your keywords, or type your own', 'whd' ); ?>"></span>
				<input type="hidden" name="whd_seo_keyphrase" id="whd-seo-keyphrase-input" value="<?php echo esc_attr( $keyphrase ); ?>">
			</p>

			<div class="whd-seo__verdict" id="whd-seo-verdict">
				<span class="whd-seo__bullet" id="whd-seo-bullet"></span>
				<span class="whd-seo__score" id="whd-seo-score">—</span>
				<button type="button" class="button button-small" id="whd-seo-refresh"><?php esc_html_e( 'Check again', 'whd' ); ?></button>
			</div>

			<div class="whd-seo__checks" id="whd-seo-checks">
				<p class="description"><?php esc_html_e( 'Reading the page…', 'whd' ); ?></p>
			</div>
		</div>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! in_array( $post->post_type, self::TYPES, true ) || ! isset( $_POST['whd_seo_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['whd_seo_nonce'] ) ), 'whd_seo_' . $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$keyphrase = isset( $_POST['whd_seo_keyphrase'] ) ? sanitize_text_field( wp_unslash( $_POST['whd_seo_keyphrase'] ) ) : '';
		if ( '' === $keyphrase ) {
			delete_post_meta( $post_id, self::KEYPHRASE );
		} else {
			update_post_meta( $post_id, self::KEYPHRASE, $keyphrase );
		}

		// Score what was actually saved, so the list column is never a page behind.
		$result = self::analyse( self::doc_from_post( $post_id, $keyphrase ) );
		update_post_meta( $post_id, self::SCORE, (int) $result['score'] );
	}

	public static function assets( $hook ) {
		/* The list screens need nothing but the dot. */
		if ( 'edit.php' === $hook ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && in_array( $screen->post_type, self::TYPES, true ) ) {
				wp_enqueue_style( 'whd-seo', WHD_URL . 'admin/seo.css', [], WHD_VERSION );
			}
			return;
		}
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, self::TYPES, true ) ) {
			return;
		}

		wp_enqueue_style( 'whd-admin', WHD_URL . 'admin/admin.css', [], WHD_VERSION );
		wp_enqueue_style( 'whd-keyword-picker', WHD_URL . 'admin/keyword-picker.css', [], WHD_VERSION );
		wp_enqueue_script( 'whd-keyword-picker', WHD_URL . 'admin/keyword-picker.js', [], WHD_VERSION, true );
		wp_enqueue_style( 'whd-seo', WHD_URL . 'admin/seo.css', [ 'whd-admin' ], WHD_VERSION );
		wp_enqueue_script( 'whd-seo', WHD_URL . 'admin/seo.js', [ 'whd-keyword-picker' ], WHD_VERSION, true );
		wp_localize_script( 'whd-seo', 'WHD_SEO_DATA', [
			'rest'  => [ 'root' => esc_url_raw( rest_url( 'whd/v1/seo/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ],
			'i18n'  => [
				'reading' => __( 'Reading the page…', 'whd' ),
				'error'   => __( 'Could not read the page.', 'whd' ),
				'noKey'   => __( 'Pick a focus keyphrase to see how the page measures up.', 'whd' ),
			],
		] );
	}

	/* ─────────────────────────── the list column ─────────────────────────── */

	public static function column( $columns ) {
		$out = [];
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['whd_seo'] = __( 'SEO', 'whd' );
			}
		}

		return isset( $out['whd_seo'] ) ? $out : $out + [ 'whd_seo' => __( 'SEO', 'whd' ) ];
	}

	public static function column_value( $column, $post_id ) {
		if ( 'whd_seo' !== $column ) {
			return;
		}
		$score = get_post_meta( $post_id, self::SCORE, true );
		if ( '' === $score ) {
			echo '<span class="whd-seo-dot whd-seo-dot--none" title="' . esc_attr__( 'Not scored yet', 'whd' ) . '">–</span>';
			return;
		}
		$score  = (int) $score;
		$rating = self::rating( $score );
		printf(
			'<span class="whd-seo-dot whd-seo-dot--%1$s" title="%2$s">%3$d</span>',
			esc_attr( $rating ),
			esc_attr( self::rating_label( $rating ) ),
			$score
		);
	}

	/* ─────────────────────────── REST ─────────────────────────── */

	public static function rest() {
		register_rest_route( 'whd/v1', '/seo/analyse', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_analyse' ],
			'permission_callback' => static function ( WP_REST_Request $r ) {
				$id = (int) $r->get_param( 'post_id' );
				return $id ? current_user_can( 'edit_post', $id ) : current_user_can( 'edit_posts' );
			},
		] );
	}

	public static function rest_analyse( WP_REST_Request $r ) {
		$post_id = (int) $r->get_param( 'post_id' );
		$doc     = self::doc_from_post( $post_id, (string) $r->get_param( 'keyphrase' ) );

		/* Whatever the editor is holding wins over what was last saved — the point of the box is
		   to answer before you publish, not after.

		   Content is the exception. On a landing page or a product the editor field holds a plain
		   fallback copy while the real writing lives in meta, so replacing the whole of it with
		   what the editor has would score the page as a fraction of itself. The editor body
		   replaces the editor body; everything kept elsewhere is added back. */
		foreach ( [ 'title', 'slug', 'meta_description', 'seo_title' ] as $field ) {
			$given = $r->get_param( $field );
			if ( null !== $given ) {
				$doc[ $field ] = (string) $given;
			}
		}
		$typed = $r->get_param( 'content' );
		if ( null !== $typed ) {
			$post  = $post_id ? get_post( $post_id ) : null;
			$saved = $post ? (string) $post->post_content : '';
			$rest  = '' !== $saved ? str_replace( $saved, '', $doc['content'] ) : $doc['content'];
			$doc['content'] = (string) $typed . $rest;
		}

		$result = self::analyse( $doc );
		if ( $post_id ) {
			update_post_meta( $post_id, self::SCORE, (int) $result['score'] );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/* ─────────────────────────── what is being read ─────────────────────────── */

	/** Everything the analyser needs about a saved post. */
	public static function doc_from_post( $post_id, $keyphrase = null ) {
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return [
				'post_id' => 0, 'post_type' => 'post', 'title' => '', 'content' => '', 'slug' => '',
				'meta_description' => '', 'seo_title' => '', 'keyphrase' => (string) $keyphrase,
			];
		}

		$content = (string) $post->post_content;

		/* Three kinds of page on this site keep their copy somewhere other than post_content.
		   Reading only the editor field would score them as empty. */
		$landing = get_post_meta( $post_id, '_omc_landing', true );
		if ( $landing ) {
			$content .= ' ' . self::flatten( $landing );
		}
		if ( 'product' === $post->post_type ) {
			$content .= ' ' . (string) $post->post_excerpt;
			$story = get_post_meta( $post_id, '_whd_story', true );
			if ( $story ) {
				$content .= ' ' . self::flatten( $story );
			}
		}

		/* The featured image is a picture on the page even when the copy never mentions one. */
		$thumb = get_post_thumbnail_id( $post_id );
		if ( $thumb ) {
			$content .= '<img alt="' . esc_attr( (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ) ) . '">';
		}

		/* Nothing chosen yet: start from the phrase the page was researched for, so the box says
		   something useful the first time it is opened rather than asking to be filled in. */
		if ( null === $keyphrase ) {
			$keyphrase = (string) get_post_meta( $post_id, self::KEYPHRASE, true );
		}
		if ( '' === trim( (string) $keyphrase ) ) {
			$keyphrase = self::researched_for( $post_id );
		}

		return [
			'post_id'          => (int) $post_id,
			'post_type'        => $post->post_type,
			'title'            => (string) $post->post_title,
			'content'          => $content,
			'slug'             => (string) $post->post_name,
			'meta_description' => (string) get_post_meta( $post_id, '_omc_meta_description', true ),
			'seo_title'        => (string) get_post_meta( $post_id, '_omc_seo_title', true ),
			'keyphrase'        => (string) $keyphrase,
		];
	}

	/**
	 * The phrase this page was written to answer, from whoever wrote it.
	 *
	 * AI Posts records what it aimed at; the keyword plan records it for the landing pages. Both
	 * are better than an empty box.
	 */
	public static function researched_for( $post_id ) {
		$keywords = get_post_meta( $post_id, '_whd_ai_keywords', true );
		if ( ! $keywords ) {
			$landing = get_post_meta( $post_id, '_omc_landing', true );
			$decoded = is_string( $landing ) ? json_decode( $landing, true ) : $landing;
			$keywords = is_array( $decoded ) ? ( $decoded['keywords'] ?? [] ) : [];
		}
		$keywords = array_values( array_filter( (array) $keywords, 'is_scalar' ) );

		return $keywords ? (string) $keywords[0] : '';
	}

	/**
	 * Copy held as JSON, rebuilt as the markup it becomes.
	 *
	 * Flattening it to a bag of words was the obvious thing and the wrong one: every check that
	 * reads structure — are there subheadings, how long are the paragraphs, are there pictures —
	 * then measures a document that does not exist, and tells you a page full of headings has
	 * none. Headings come back as headings and paragraphs as paragraphs, so the checks see what
	 * the template will actually render.
	 */
	private static function flatten( $value ) {
		$doc = is_string( $value ) ? json_decode( $value, true ) : $value;
		if ( ! is_array( $doc ) ) {
			return is_string( $value ) ? wp_strip_all_tags( $value ) : '';
		}

		$html = '';
		$para = static function ( $text ) use ( &$html ) {
			$text = trim( (string) $text );
			if ( '' !== $text ) {
				$html .= '<p>' . $text . '</p>';
			}
		};

		if ( ! empty( $doc['hero']['heading'] ) ) {
			$html .= '<h2>' . $doc['hero']['heading'] . '</h2>';
		}
		foreach ( [ 'intro', 'lead', 'summary' ] as $opening ) {
			if ( ! empty( $doc[ $opening ] ) && is_scalar( $doc[ $opening ] ) ) {
				$para( $doc[ $opening ] );
			}
		}
		if ( ! empty( $doc['hero']['text'] ) ) {
			$para( $doc['hero']['text'] );
		}

		foreach ( (array) ( $doc['sections'] ?? $doc['rows'] ?? [] ) as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			if ( ! empty( $section['heading'] ) ) {
				$html .= '<h2>' . $section['heading'] . '</h2>';
			}
			foreach ( (array) ( $section['paragraphs'] ?? [] ) as $p ) {
				$para( $p );
			}
			foreach ( [ 'copy', 'text', 'body', 'lead', 'lead_more' ] as $field ) {
				if ( ! empty( $section[ $field ] ) && is_scalar( $section[ $field ] ) ) {
					$para( $section[ $field ] );
				}
			}
			foreach ( (array) ( $section['bullets'] ?? [] ) as $b ) {
				$html .= '<li>' . $b . '</li>';
			}
			foreach ( (array) ( $section['faqs'] ?? [] ) as $faq ) {
				$html .= '<h3>' . ( $faq['q'] ?? '' ) . '</h3>';
				$para( is_array( $faq['a'] ?? '' ) ? implode( ' ', $faq['a'] ) : ( $faq['a'] ?? '' ) );
			}
			// A picture named in the document is a picture on the page.
			if ( ! empty( $section['image'] ) ) {
				$html .= '<img alt="' . esc_attr( (string) ( $section['alt'] ?? $section['heading'] ?? '' ) ) . '">';
			}
		}

		foreach ( (array) ( $doc['faqs'] ?? [] ) as $faq ) {
			$html .= '<h3>' . ( $faq['q'] ?? '' ) . '</h3>';
			$para( is_array( $faq['a'] ?? '' ) ? implode( ' ', $faq['a'] ) : ( $faq['a'] ?? '' ) );
		}
		if ( ! empty( $doc['cta']['text'] ) ) {
			$para( $doc['cta']['text'] );
		}

		return $html;
	}

	/* ─────────────────────────── the analysis ─────────────────────────── */

	/**
	 * Score one document.
	 *
	 * @param array $doc title, content, slug, meta_description, seo_title, keyphrase, post_type, post_id
	 * @return array { score, rating, words, groups[] }
	 */
	public static function analyse( array $doc ) {
		$type      = (string) ( $doc['post_type'] ?? 'post' );
		$keyphrase = trim( (string) ( $doc['keyphrase'] ?? '' ) );
		$title     = (string) ( $doc['title'] ?? '' );
		$seo_title = trim( (string) ( $doc['seo_title'] ?? '' ) ) ?: $title;
		$meta      = trim( (string) ( $doc['meta_description'] ?? '' ) );
		$slug      = (string) ( $doc['slug'] ?? '' );
		$html      = (string) ( $doc['content'] ?? '' );

		$text      = self::plain( $html );
		$words     = self::word_list( $text );
		$count     = count( $words );
		$sentences = self::sentences( $text );

		$key      = [];
		$key_norm = '';
		if ( '' !== $keyphrase ) {
			$key_norm = self::norm( $keyphrase );
			$key      = self::word_list( $keyphrase );
		}

		$checks = [];

		/* ── the keyphrase ── */

		if ( '' === $keyphrase ) {
			$checks[] = self::check( 'keyphrase', 'bad', __( 'No focus keyphrase. Pick one and everything below is measured against it.', 'whd' ), 'keyphrase', 3 );
		} else {
			$checks[] = self::keyphrase_known( $keyphrase, (int) ( $doc['post_id'] ?? 0 ) );
			$checks[] = self::contains( 'key_title', $seo_title, $key_norm, 'keyphrase',
				__( 'The keyphrase is in the SEO title.', 'whd' ),
				__( 'The keyphrase is not in the SEO title — it is the first thing a searcher reads.', 'whd' ), 3 );
			$checks[] = self::contains( 'key_meta', $meta, $key_norm, 'keyphrase',
				__( 'The keyphrase is in the search snippet.', 'whd' ),
				__( 'The keyphrase is not in the search snippet.', 'whd' ), 2 );
			$checks[] = self::contains( 'key_slug', str_replace( '-', ' ', $slug ), $key_norm, 'keyphrase',
				__( 'The keyphrase is in the web address.', 'whd' ),
				__( 'The keyphrase is not in the web address.', 'whd' ), 2 );

			$first = self::first_paragraph( $html, $text );
			$checks[] = self::contains( 'key_first', $first, $key_norm, 'keyphrase',
				__( 'The keyphrase appears in the opening paragraph.', 'whd' ),
				__( 'The keyphrase is not in the opening paragraph — say early what the page is about.', 'whd' ), 2 );

			$headings = self::headings( $html );
			$in_head  = false;
			foreach ( $headings as $h ) {
				if ( false !== mb_strpos( self::norm( $h ), $key_norm ) ) {
					$in_head = true;
					break;
				}
			}
			$checks[] = $headings
				? self::check( 'key_heading', $in_head ? 'good' : 'ok',
					$in_head
						? __( 'The keyphrase appears in a subheading.', 'whd' )
						: __( 'No subheading uses the keyphrase.', 'whd' ), 'keyphrase', 2 )
				: self::check( 'key_heading', 'ok', __( 'There are no subheadings to carry the keyphrase.', 'whd' ), 'keyphrase', 2 );

			$checks[] = self::density( $text, $keyphrase, $count );
			$checks[] = self::keyphrase_in_alt( $html, $key_norm );
		}

		/* ── the writing ── */

		$checks[] = self::length( $count, $type );
		$checks[] = self::meta_length( $meta );
		$checks[] = self::title_length( $seo_title );
		$checks[] = self::subheading_spread( $html, $count );
		$checks[] = self::paragraph_length( $html );
		$checks[] = self::sentence_length( $sentences );
		$checks[] = self::passive_voice( $sentences );
		$checks[] = self::transitions( $sentences );
		$checks[] = self::reading_ease( $words, $sentences );

		/* ── the page itself ── */

		$checks[] = self::images( $html, $doc );
		$checks[] = self::links( $html );
		$checks[] = self::schema_for( $doc );

		/* ── the house rules ── */

		$checks[] = self::banned( $text );
		$checks[] = self::claims( $text );

		$checks = array_values( array_filter( $checks ) );

		$earned = 0;
		$total  = 0;
		foreach ( $checks as $c ) {
			$total  += $c['weight'];
			$earned += $c['weight'] * ( 'good' === $c['status'] ? 1 : ( 'ok' === $c['status'] ? 0.5 : 0 ) );
		}
		$score = $total ? (int) round( 100 * $earned / $total ) : 0;

		$groups = [];
		foreach ( [ 'keyphrase' => __( 'Keyphrase', 'whd' ), 'writing' => __( 'Readability', 'whd' ), 'page' => __( 'The page', 'whd' ), 'house' => __( 'House rules', 'whd' ) ] as $id => $label ) {
			$in = array_values( array_filter( $checks, static fn( $c ) => $id === $c['group'] ) );
			if ( $in ) {
				$groups[] = [ 'id' => $id, 'label' => $label, 'checks' => $in ];
			}
		}

		return [
			'score'  => $score,
			'rating' => self::rating( $score ),
			'label'  => self::rating_label( self::rating( $score ) ),
			'words'  => $count,
			'groups' => $groups,
		];
	}

	/* ─────────────────────────── the checks ─────────────────────────── */

	private static function check( $id, $status, $text, $group = 'writing', $weight = 1 ) {
		return [ 'id' => $id, 'status' => $status, 'text' => $text, 'group' => $group, 'weight' => (float) $weight ];
	}

	private static function contains( $id, $haystack, $needle, $group, $yes, $no, $weight = 1 ) {
		$hit = '' !== $needle && false !== mb_strpos( self::norm( $haystack ), $needle );

		return self::check( $id, $hit ? 'good' : 'bad', $hit ? $yes : $no, $group, $weight );
	}

	/** Is this a phrase we researched, and is another page already aimed at it? */
	private static function keyphrase_known( $keyphrase, $post_id ) {
		global $wpdb;

		$also = [];
		if ( $post_id ) {
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s AND post_id <> %d LIMIT 5",
					self::KEYPHRASE,
					$keyphrase,
					$post_id
				)
			);
			foreach ( (array) $rows as $other ) {
				if ( 'publish' === get_post_status( $other ) || 'draft' === get_post_status( $other ) ) {
					$also[] = get_the_title( $other );
				}
			}
		}
		if ( $also ) {
			return self::check(
				'key_unique',
				'bad',
				sprintf(
					/* translators: %s: other page titles */
					__( 'Another page is already aimed at this phrase: %s. Two pages competing for one phrase beat each other.', 'whd' ),
					implode( ', ', $also )
				),
				'keyphrase',
				2
			);
		}

		$known = null;
		if ( class_exists( 'WHD_AI_Keywords' ) ) {
			$known = $wpdb->get_row(
				$wpdb->prepare( 'SELECT volume, difficulty FROM ' . WHD_AI_Keywords::table() . ' WHERE keyword = %s', $keyphrase ),
				ARRAY_A
			);
		}
		if ( ! $known ) {
			return self::check( 'key_unique', 'ok', __( 'This phrase is not in your keyword list, so there is no volume or difficulty to go on.', 'whd' ), 'keyphrase', 1 );
		}

		return self::check(
			'key_unique',
			'good',
			sprintf(
				/* translators: 1: monthly searches, 2: difficulty out of 100 */
				__( 'From your keyword list: %1$s searches a month, difficulty %2$s.', 'whd' ),
				number_format_i18n( (int) $known['volume'] ),
				(int) $known['difficulty']
			),
			'keyphrase',
			1
		);
	}

	/** Yoast's window is half a percent to three; below reads accidental, above reads stuffed. */
	private static function density( $text, $keyphrase, $count ) {
		if ( ! $count ) {
			return self::check( 'density', 'bad', __( 'There is no copy to measure.', 'whd' ), 'keyphrase', 2 );
		}
		$hits    = substr_count( self::norm( $text ), self::norm( $keyphrase ) );
		$density = 100 * $hits * max( 1, count( self::word_list( $keyphrase ) ) ) / $count;

		if ( ! $hits ) {
			return self::check( 'density', 'bad', __( 'The keyphrase never appears in the copy.', 'whd' ), 'keyphrase', 2 );
		}
		if ( $density > 3 ) {
			return self::check( 'density', 'bad', sprintf(
				/* translators: 1: times used, 2: density */
				__( 'The keyphrase is used %1$d times (%2$s%%) — that reads as stuffing.', 'whd' ), $hits, number_format_i18n( $density, 1 ) ), 'keyphrase', 2 );
		}
		if ( $density < 0.5 ) {
			return self::check( 'density', 'ok', sprintf(
				/* translators: 1: times used, 2: density */
				__( 'The keyphrase is used %1$d time(s) (%2$s%%) — a little thin.', 'whd' ), $hits, number_format_i18n( $density, 1 ) ), 'keyphrase', 2 );
		}

		return self::check( 'density', 'good', sprintf(
			/* translators: 1: times used, 2: density */
			__( 'The keyphrase is used %1$d times (%2$s%%), which is about right.', 'whd' ), $hits, number_format_i18n( $density, 1 ) ), 'keyphrase', 2 );
	}

	private static function keyphrase_in_alt( $html, $key_norm ) {
		preg_match_all( '/<img[^>]*alt=["\']([^"\']*)["\']/i', $html, $m );
		$alts = array_filter( array_map( 'trim', $m[1] ?? [] ) );
		if ( ! $alts ) {
			return self::check( 'key_alt', 'ok', __( 'No picture carries alt text for the keyphrase to sit in.', 'whd' ), 'keyphrase', 1 );
		}
		foreach ( $alts as $alt ) {
			if ( '' !== $key_norm && false !== mb_strpos( self::norm( $alt ), $key_norm ) ) {
				return self::check( 'key_alt', 'good', __( 'A picture’s alt text carries the keyphrase.', 'whd' ), 'keyphrase', 1 );
			}
		}

		return self::check( 'key_alt', 'ok', __( 'No picture’s alt text mentions the keyphrase.', 'whd' ), 'keyphrase', 1 );
	}

	/** How much copy a page of this kind needs before it can rank for anything. */
	private static function length( $count, $type ) {
		$want = [ 'post' => 600, 'page' => 400, 'product' => 200 ][ $type ] ?? 400;
		if ( $count >= $want ) {
			return self::check( 'length', 'good', sprintf(
				/* translators: %d: word count */
				__( '%d words — enough to say something.', 'whd' ), $count ), 'writing', 3 );
		}
		if ( $count >= $want * 0.6 ) {
			return self::check( 'length', 'ok', sprintf(
				/* translators: 1: word count, 2: target */
				__( '%1$d words, against about %2$d for this kind of page.', 'whd' ), $count, $want ), 'writing', 3 );
		}

		return self::check( 'length', 'bad', sprintf(
			/* translators: 1: word count, 2: target */
			__( '%1$d words — thin. Aim for about %2$d.', 'whd' ), $count, $want ), 'writing', 3 );
	}

	private static function meta_length( $meta ) {
		$n = mb_strlen( $meta );
		if ( ! $n ) {
			return self::check( 'meta', 'bad', __( 'No search snippet. Google will invent one from the copy.', 'whd' ), 'page', 3 );
		}
		if ( $n > 158 ) {
			return self::check( 'meta', 'ok', sprintf(
				/* translators: %d: characters */
				__( 'The snippet is %d characters — it will be cut off.', 'whd' ), $n ), 'page', 3 );
		}
		if ( $n < 100 ) {
			return self::check( 'meta', 'ok', sprintf(
				/* translators: %d: characters */
				__( 'The snippet is %d characters — there is room to say more.', 'whd' ), $n ), 'page', 3 );
		}

		return self::check( 'meta', 'good', sprintf(
			/* translators: %d: characters */
			__( 'The snippet is %d characters, which fits.', 'whd' ), $n ), 'page', 3 );
	}

	private static function title_length( $title ) {
		$n = mb_strlen( $title );
		if ( ! $n ) {
			return self::check( 'title', 'bad', __( 'There is no title.', 'whd' ), 'page', 2 );
		}
		if ( $n > 60 ) {
			return self::check( 'title', 'ok', sprintf(
				/* translators: %d: characters */
				__( 'The title is %d characters — the end will be cut off in results.', 'whd' ), $n ), 'page', 2 );
		}
		if ( $n < 30 ) {
			return self::check( 'title', 'ok', sprintf(
				/* translators: %d: characters */
				__( 'The title is %d characters — short enough to be wasting room.', 'whd' ), $n ), 'page', 2 );
		}

		return self::check( 'title', 'good', sprintf(
			/* translators: %d: characters */
			__( 'The title is %d characters, which fits a result.', 'whd' ), $n ), 'page', 2 );
	}

	private static function subheading_spread( $html, $count ) {
		if ( $count < 300 ) {
			return self::check( 'spread', 'good', __( 'Short enough not to need subheadings.', 'whd' ), 'writing', 2 );
		}
		$parts = preg_split( '/<h[23][^>]*>/i', $html );
		if ( count( $parts ) < 2 ) {
			return self::check( 'spread', 'bad', __( 'No subheadings at all. A long page without them is a wall.', 'whd' ), 'writing', 2 );
		}
		$longest = 0;
		foreach ( $parts as $part ) {
			$longest = max( $longest, count( self::word_list( self::plain( $part ) ) ) );
		}
		if ( $longest > 300 ) {
			return self::check( 'spread', 'ok', sprintf(
				/* translators: %d: words */
				__( '%d words run on without a subheading.', 'whd' ), $longest ), 'writing', 2 );
		}

		return self::check( 'spread', 'good', __( 'Subheadings come often enough.', 'whd' ), 'writing', 2 );
	}

	private static function paragraph_length( $html ) {
		preg_match_all( '/<p[^>]*>(.*?)<\/p>/is', $html, $m );
		$paras = $m[1] ?? [];
		if ( ! $paras ) {
			return self::check( 'para', 'good', __( 'No long paragraphs.', 'whd' ), 'writing', 1 );
		}
		$long = 0;
		foreach ( $paras as $p ) {
			if ( count( self::word_list( self::plain( $p ) ) ) > 150 ) {
				$long++;
			}
		}
		if ( $long ) {
			return self::check( 'para', 'ok', sprintf(
				/* translators: %d: paragraphs */
				_n( '%d paragraph is over 150 words.', '%d paragraphs are over 150 words.', $long, 'whd' ), $long ), 'writing', 1 );
		}

		return self::check( 'para', 'good', __( 'No paragraph runs over 150 words.', 'whd' ), 'writing', 1 );
	}

	private static function sentence_length( array $sentences ) {
		if ( ! $sentences ) {
			return self::check( 'sentence', 'bad', __( 'There are no sentences to read.', 'whd' ), 'writing', 2 );
		}
		$long = 0;
		foreach ( $sentences as $s ) {
			if ( count( self::word_list( $s ) ) > 20 ) {
				$long++;
			}
		}
		$share = 100 * $long / count( $sentences );
		if ( $share > 25 ) {
			return self::check( 'sentence', 'ok', sprintf(
				/* translators: %d: percentage */
				__( '%d%% of sentences run over 20 words.', 'whd' ), round( $share ) ), 'writing', 2 );
		}

		return self::check( 'sentence', 'good', sprintf(
			/* translators: %d: percentage */
			__( '%d%% of sentences run long, which is within reach.', 'whd' ), round( $share ) ), 'writing', 2 );
	}

	/** "was worn", "is made", "has been chosen" — be/have plus a past participle. */
	private static function passive_voice( array $sentences ) {
		if ( ! $sentences ) {
			return null;
		}
		$passive = 0;
		foreach ( $sentences as $s ) {
			if ( preg_match( '/\b(is|are|was|were|be|been|being|get|gets|got)\b\s+(\w+ed|born|built|bought|caught|chosen|cut|done|drawn|found|given|held|kept|known|left|made|meant|met|paid|put|read|seen|sent|set|shown|sold|taken|told|thought|worn|written)\b/i', $s ) ) {
				$passive++;
			}
		}
		$share = 100 * $passive / count( $sentences );
		if ( $share > 10 ) {
			return self::check( 'passive', 'ok', sprintf(
				/* translators: %d: percentage */
				__( '%d%% of sentences are passive. Say who does the thing.', 'whd' ), round( $share ) ), 'writing', 1 );
		}

		return self::check( 'passive', 'good', sprintf(
			/* translators: %d: percentage */
			__( '%d%% passive, which is fine.', 'whd' ), round( $share ) ), 'writing', 1 );
	}

	/** Yoast wants a third of sentences to carry one. They are what makes prose read as prose. */
	private static function transitions( array $sentences ) {
		if ( count( $sentences ) < 4 ) {
			return null;
		}
		$words = [ 'and', 'but', 'so', 'because', 'if', 'when', 'while', 'then', 'still', 'though',
			'although', 'instead', 'rather', 'once', 'before', 'after', 'until', 'unless', 'since',
			'that is why', 'which is why', 'even so', 'at the same time', 'in practice', 'for example',
			'for instance', 'first', 'next', 'finally', 'meanwhile', 'otherwise', 'as a result' ];
		$with = 0;
		foreach ( $sentences as $s ) {
			$n = ' ' . self::norm( $s );
			foreach ( $words as $w ) {
				if ( false !== mb_strpos( $n, ' ' . $w . ' ' ) ) {
					$with++;
					break;
				}
			}
		}
		$share = 100 * $with / count( $sentences );
		if ( $share < 30 ) {
			return self::check( 'transitions', 'ok', sprintf(
				/* translators: %d: percentage */
				__( 'Only %d%% of sentences join on to the one before. The copy will read as a list.', 'whd' ), round( $share ) ), 'writing', 1 );
		}

		return self::check( 'transitions', 'good', sprintf(
			/* translators: %d: percentage */
			__( '%d%% of sentences carry a linking word.', 'whd' ), round( $share ) ), 'writing', 1 );
	}

	/** Flesch reading ease: 60 and up is readable by most people without effort. */
	private static function reading_ease( array $words, array $sentences ) {
		$w = count( $words );
		$s = count( $sentences );
		if ( $w < 50 || ! $s ) {
			return null;
		}
		$syllables = 0;
		foreach ( $words as $word ) {
			$syllables += self::syllables( $word );
		}
		$ease = 206.835 - 1.015 * ( $w / $s ) - 84.6 * ( $syllables / $w );
		$ease = max( 0, min( 100, $ease ) );

		if ( $ease >= 60 ) {
			return self::check( 'ease', 'good', sprintf(
				/* translators: %d: score out of 100 */
				__( 'Reading ease %d — easy enough.', 'whd' ), round( $ease ) ), 'writing', 2 );
		}
		if ( $ease >= 50 ) {
			return self::check( 'ease', 'ok', sprintf(
				/* translators: %d: score out of 100 */
				__( 'Reading ease %d — fairly hard going.', 'whd' ), round( $ease ) ), 'writing', 2 );
		}

		return self::check( 'ease', 'bad', sprintf(
			/* translators: %d: score out of 100 */
			__( 'Reading ease %d — hard work. Shorter sentences and plainer words.', 'whd' ), round( $ease ) ), 'writing', 2 );
	}

	private static function images( $html, array $doc ) {
		preg_match_all( '/<img[^>]*>/i', $html, $m );
		$imgs = $m[0] ?? [];

		// A product's pictures live in the gallery, not in the copy.
		if ( 'product' === ( $doc['post_type'] ?? '' ) && ! $imgs && ! empty( $doc['post_id'] ) ) {
			$gallery = array_filter( explode( ',', (string) get_post_meta( $doc['post_id'], '_product_image_gallery', true ) ) );
			$thumb   = get_post_thumbnail_id( $doc['post_id'] );
			$n       = count( $gallery ) + ( $thumb ? 1 : 0 );

			return $n
				? self::check( 'images', 'good', sprintf(
					/* translators: %d: pictures */
					_n( '%d picture in the gallery.', '%d pictures in the gallery.', $n, 'whd' ), $n ), 'page', 2 )
				: self::check( 'images', 'bad', __( 'No pictures at all.', 'whd' ), 'page', 2 );
		}

		if ( ! $imgs ) {
			return self::check( 'images', 'bad', __( 'No pictures. A page of unbroken text is harder to read and harder to share.', 'whd' ), 'page', 2 );
		}
		$without = 0;
		foreach ( $imgs as $img ) {
			if ( ! preg_match( '/alt=["\'][^"\']+["\']/i', $img ) ) {
				$without++;
			}
		}
		if ( $without ) {
			return self::check( 'images', 'ok', sprintf(
				/* translators: 1: pictures without alt, 2: pictures in total */
				__( '%1$d of %2$d pictures have no alt text.', 'whd' ), $without, count( $imgs ) ), 'page', 2 );
		}

		return self::check( 'images', 'good', sprintf(
			/* translators: %d: pictures */
			_n( '%d picture, with alt text.', '%d pictures, all with alt text.', count( $imgs ), 'whd' ), count( $imgs ) ), 'page', 2 );
	}

	private static function links( $html ) {
		preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\']/i', $html, $m );
		$home     = wp_parse_url( home_url(), PHP_URL_HOST );
		$internal = 0;
		$external = 0;
		foreach ( $m[1] ?? [] as $href ) {
			if ( 0 === strpos( $href, '#' ) || 0 === stripos( $href, 'mailto:' ) ) {
				continue;
			}
			$host = wp_parse_url( $href, PHP_URL_HOST );
			if ( ! $host || $host === $home ) {
				$internal++;
			} else {
				$external++;
			}
		}
		if ( ! $internal ) {
			return self::check( 'links', 'bad', __( 'Nothing links out to the rest of the site. A page with no links is a dead end.', 'whd' ), 'page', 2 );
		}

		return self::check( 'links', 'good', sprintf(
			/* translators: 1: internal links, 2: external links */
			__( '%1$d link(s) into the site, %2$d out.', 'whd' ), $internal, $external ), 'page', 2 );
	}

	/**
	 * What structured data this page will actually carry.
	 *
	 * The theme emits it, not this plugin, so the answer depends on the template rather than on
	 * anything typed in the editor. It is still worth saying, because it is part of the score.
	 */
	private static function schema_for( array $doc ) {
		$type = (string) ( $doc['post_type'] ?? '' );
		$id   = (int) ( $doc['post_id'] ?? 0 );

		if ( 'product' === $type ) {
			return self::check( 'schema', 'good', __( 'Product structured data, from WooCommerce.', 'whd' ), 'page', 2 );
		}
		if ( 'post' === $type ) {
			$has = function_exists( 'omc_schema_page_nodes' ) || ( $id && get_post_meta( $id, '_whd_ai_origin', true ) );

			return $has
				? self::check( 'schema', 'good', __( 'BlogPosting structured data.', 'whd' ), 'page', 2 )
				: self::check( 'schema', 'bad', __( 'No article structured data on this post.', 'whd' ), 'page', 2 );
		}

		$landing = $id ? get_post_meta( $id, '_omc_landing', true ) : '';
		$decoded = is_string( $landing ) ? json_decode( $landing, true ) : $landing;
		if ( is_array( $decoded ) && ! empty( $decoded['faqs'] ) ) {
			return self::check( 'schema', 'good', __( 'FAQPage structured data, from the questions on the page.', 'whd' ), 'page', 2 );
		}
		if ( function_exists( 'omc_schema_page_nodes' ) ) {
			return self::check( 'schema', 'good', __( 'WebPage structured data, with a breadcrumb trail.', 'whd' ), 'page', 2 );
		}

		return self::check( 'schema', 'ok', __( 'No structured data beyond the site-wide Organization.', 'whd' ), 'page', 2 );
	}

	/** The list this shop has decided never to publish. */
	private static function banned( $text ) {
		if ( ! class_exists( 'WHD_AI' ) ) {
			return null;
		}
		$found    = [];
		$haystack = ' ' . self::norm( $text ) . ' ';
		foreach ( WHD_AI::banned_words() as $bad ) {
			$needle = trim( self::norm( $bad ) );
			if ( '' === $needle ) {
				continue;
			}
			if ( false !== mb_strpos( $haystack, ' ' . $needle . ' ' ) ) {
				$found[] = $bad;
			}
			if ( count( $found ) >= 5 ) {
				break;
			}
		}
		if ( $found ) {
			return self::check( 'banned', 'bad', sprintf(
				/* translators: %s: the words found */
				__( 'Words from the banned list: %s.', 'whd' ), implode( ', ', $found ) ), 'house', 2 );
		}

		return self::check( 'banned', 'good', __( 'Nothing from the banned-word list.', 'whd' ), 'house', 2 );
	}

	/**
	 * Promises the shop cannot keep.
	 *
	 * There are no shipping zones and no carrier, so a delivery estimate is not optimistic, it is
	 * untrue; the same goes for stock counts and review counts nobody is counting.
	 */
	private static function claims( $text ) {
		$plain = self::norm( $text );
		$rules = [
			'/\b\d+\s*[-–]\s*\d+\s+(business\s+)?days?\b/' => __( 'a delivery estimate', 'whd' ),
			'/\b(free|fast|express|next[- ]day|same[- ]day)\s+(shipping|delivery)\b/' => __( 'a shipping promise', 'whd' ),
			'/\b(dhl|fedex|usps|royal mail|ups courier)\b/' => __( 'a named carrier', 'whd' ),
			'/\b(only|just)\s+\d+\s+(left|remaining|in stock)\b/' => __( 'a stock count', 'whd' ),
			'/\b\d+\s*%\s*(off|discount)\b/' => __( 'a discount', 'whd' ),
			'/\b\d{2,}\s+(reviews|customers|shoppers)\b/' => __( 'a review or customer count', 'whd' ),
		];
		$found = [];
		foreach ( $rules as $pattern => $label ) {
			if ( preg_match( $pattern, $plain ) ) {
				$found[] = $label;
			}
		}
		if ( $found ) {
			return self::check( 'claims', 'bad', sprintf(
				/* translators: %s: the kinds of claim found */
				__( 'The copy makes a promise the shop cannot keep: %s.', 'whd' ), implode( ', ', array_unique( $found ) ) ), 'house', 2 );
		}

		return self::check( 'claims', 'good', __( 'Nothing claimed that the shop cannot back.', 'whd' ), 'house', 2 );
	}

	/* ─────────────────────────── odds and ends ─────────────────────────── */

	public static function rating( $score ) {
		if ( $score >= 80 ) {
			return 'good';
		}

		return $score >= 55 ? 'ok' : 'bad';
	}

	public static function rating_label( $rating ) {
		return [
			'good' => __( 'Good', 'whd' ),
			'ok'   => __( 'Needs work', 'whd' ),
			'bad'  => __( 'Poor', 'whd' ),
		][ $rating ] ?? '';
	}

	private static function plain( $html ) {
		$html = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', ' ', (string) $html );
		$html = preg_replace( '/<[^>]+>/', ' ', (string) $html );

		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( (string) $html, ENT_QUOTES, 'UTF-8' ) ) );
	}

	private static function norm( $text ) {
		return trim( preg_replace( '/\s+/u', ' ', mb_strtolower( self::plain( $text ) ) ) );
	}

	private static function word_list( $text ) {
		return preg_split( '/[^\p{L}\p{N}\'’-]+/u', self::plain( $text ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
	}

	private static function sentences( $text ) {
		$parts = preg_split( '/(?<=[.!?])\s+/u', (string) $text, -1, PREG_SPLIT_NO_EMPTY ) ?: [];

		return array_values( array_filter( array_map( 'trim', $parts ), static fn( $s ) => mb_strlen( $s ) > 1 ) );
	}

	private static function first_paragraph( $html, $fallback ) {
		if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', (string) $html, $m ) ) {
			return self::plain( $m[1] );
		}

		return mb_substr( $fallback, 0, 300 );
	}

	private static function headings( $html ) {
		preg_match_all( '/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', (string) $html, $m );

		return array_map( [ __CLASS__, 'plain' ], $m[1] ?? [] );
	}

	/** Close enough for a reading score: vowel groups, less a silent trailing e. */
	private static function syllables( $word ) {
		$word = mb_strtolower( preg_replace( '/[^a-z]/i', '', $word ) );
		if ( '' === $word ) {
			return 0;
		}
		$n = preg_match_all( '/[aeiouy]+/', $word );
		if ( preg_match( '/e$/', $word ) && $n > 1 ) {
			$n--;
		}

		return max( 1, (int) $n );
	}
}
