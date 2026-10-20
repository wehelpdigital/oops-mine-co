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
	/* Words that say nothing about which picture to use, however often they turn up in a heading. */
	const STOPWORDS = [
		'with', 'that', 'this', 'your', 'from', 'what', 'when', 'they', 'them', 'into', 'over',
		'under', 'above', 'after', 'before', 'while', 'their', 'about', 'which', 'where', 'there',
		'these', 'those', 'would', 'could', 'should', 'every', 'other', 'more', 'most', 'very',
		'than', 'then', 'also', 'just', 'like', 'make', 'made', 'take', 'keep', 'need', 'want',
		'know', 'think', 'look', 'good', 'best', 'right', 'left', 'long', 'year', 'years', 'time',
		'times', 'work', 'works', 'page', 'piece', 'pieces', 'size', 'sizes', 'wear', 'wears',
	];

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
		/*
		 * One menu, two screens under it. They were two top-level items sitting next to each
		 * other in the sidebar, which read as two unrelated tools rather than one writer with a
		 * choice of what to write. The slugs are unchanged, so every link and bookmark still
		 * lands where it did.
		 *
		 * The parent carries the same slug and callback as AI Posts, which is how a top-level
		 * item gets a name of its own instead of repeating its first child's.
		 */
		add_menu_page(
			__( 'AI Writer', 'whd' ),
			__( 'AI Writer', 'whd' ),
			'edit_posts',
			self::PAGE_POST,
			[ __CLASS__, 'screen_posts' ],
			'dashicons-edit-large',
			27
		);
		add_submenu_page(
			self::PAGE_POST,
			__( 'AI Posts', 'whd' ),
			__( 'AI Posts', 'whd' ),
			'edit_posts',
			self::PAGE_POST,
			[ __CLASS__, 'screen_posts' ]
		);
		add_submenu_page(
			self::PAGE_POST,
			__( 'AI Pages', 'whd' ),
			__( 'AI Pages', 'whd' ),
			'edit_pages',
			self::PAGE_PAGE,
			[ __CLASS__, 'screen_pages' ]
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
		/*
		 * Which screen this is, from the screen itself rather than from the hook name. The hook
		 * carries where the item sits in the menu — "toplevel_page_…" for a top-level one,
		 * something else once it moves under a parent — so matching on it meant that moving
		 * these two under one AI Writer menu silently stopped the page screen loading its own
		 * JavaScript, leaving a screen that rendered and did nothing.
		 */
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which admin screen this is
		if ( ! in_array( $page, [ self::PAGE_POST, self::PAGE_PAGE ], true ) ) {
			return;
		}
		wp_enqueue_style( 'whd-admin', WHD_URL . 'admin/admin.css', [], WHD_VERSION );
		// The form is the product screen's Write with AI panel, so it wears that panel's styles.
		wp_enqueue_style( 'whd-ai-studio', WHD_URL . 'admin/ai-studio.css', [], WHD_VERSION );
		wp_enqueue_style( 'whd-ai-writer', WHD_URL . 'admin/ai-writer.css', [ 'whd-admin', 'whd-ai-studio' ], WHD_VERSION );
		// The saved line shows the score, which borrows the dot from the SEO box.
		wp_enqueue_style( 'whd-seo', WHD_URL . 'admin/seo.css', [ 'whd-admin' ], WHD_VERSION );
		// The photographs and the pictures chosen by hand come from WordPress's own media window.
		wp_enqueue_media();
		wp_enqueue_style( 'whd-keyword-picker', WHD_URL . 'admin/keyword-picker.css', [], WHD_VERSION );
		wp_enqueue_script( 'whd-keyword-picker', WHD_URL . 'admin/keyword-picker.js', [], WHD_VERSION, true );
		wp_enqueue_script( 'whd-ai-kit', WHD_URL . 'admin/ai-kit.js', [], WHD_VERSION, true );
		wp_enqueue_script( 'whd-ai-writer', WHD_URL . 'admin/ai-writer.js', [ 'whd-ai-kit', 'whd-keyword-picker', 'media-editor' ], WHD_VERSION, true );

		$type = self::PAGE_POST === $page ? 'post' : 'page';
		wp_localize_script( 'whd-ai-writer', 'WHD_AI_WRITER', [
			'rest'    => [ 'root' => esc_url_raw( rest_url( 'whd/v1/ai/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ],
			// The slow steps run as background jobs, asked after where the studio's are.
			'jobs'    => self::jobs() ? esc_url_raw( rest_url( 'whd/v1/ai/studio/job' ) ) : '',
			'last'    => self::jobs() ? esc_url_raw( rest_url( 'whd/v1/ai/studio/last' ) ) : '',
			'type'    => $type,
			'ready'   => WHD_AI::ready(),
			'canDraw' => class_exists( 'WHD_AI_Images' ) && WHD_AI_Images::ready(),
			'lengths' => [
				[ 'key' => 'short', 'name' => __( 'Short', 'whd' ), 'note' => __( 'about 400 words', 'whd' ), 'pictures' => 2 ],
				[ 'key' => 'medium', 'name' => __( 'Medium', 'whd' ), 'note' => __( 'about 700 words', 'whd' ), 'pictures' => 3 ],
				[ 'key' => 'long', 'name' => __( 'Long', 'whd' ), 'note' => __( 'about 1,100 words', 'whd' ), 'pictures' => 4 ],
			],
			'maxPictures' => self::MAX_PICTURES,
			'i18n'    => array_merge(
				class_exists( 'WHD_AI_Studio' ) ? WHD_AI_Studio::strings() : [],
				[
					'writing'   => __( 'Writing — this takes a few seconds…', 'whd' ),
					'saving'    => __( 'Saving…', 'whd' ),
					'error'     => __( 'Something went wrong', 'whd' ),
					'noIdea'    => __( 'Say what it should be about first.', 'whd' ),
					'words'     => __( 'words', 'whd' ),
					'sections'  => __( 'sections', 'whd' ),
					'faqs'      => __( 'questions', 'whd' ),
					'swapAny'   => __( 'Click a picture to see it large; “Change” swaps it for one from your library.', 'whd' ),
					'pickSpare' => __( 'This piece had room for %1$s of them; the rest were left out.', 'whd' ),
					'top'       => __( 'Top of the page', 'whd' ),
					/* translators: %d: picture number */
					'pictureN'  => __( 'Picture %d', 'whd' ),
					'atTop'     => __( 'top of the page', 'whd' ),
					'inBody'    => __( 'in the body', 'whd' ),
					'findDraw'  => __( 'Find or draw', 'whd' ),
					'fromLib'   => __( 'From your library', 'whd' ),
					'chooseOwn' => __( 'Choose my own', 'whd' ),
					'mine'      => __( 'Your own picture', 'whd' ),
					'clearOwn'  => __( 'Use the AI instead', 'whd' ),
					'needPhotos' => __( 'Needs photographs to work from, above', 'whd' ),
					'photosNone' => __( 'Optional. Choose photographs of the pieces this is about, and a picture drawn on a model or a ghost mannequin shows those pieces as they really are.', 'whd' ),
					/* translators: %d: number of photographs */
					'photosSome' => __( '%d chosen. Pictures drawn on a model or a ghost mannequin are drawn from these.', 'whd' ),
					'askAuto'   => [
						__( 'e.g. a woman on a quiet street in autumn light, warm cream tones', 'whd' ),
						__( 'e.g. a close-up of a knit texture, soft daylight', 'whd' ),
						__( 'e.g. an office desk with a coffee, morning light', 'whd' ),
						__( 'e.g. a rail of folded sweaters in neutral colours', 'whd' ),
					],
					'leftBlankW'  => __( 'Left blank, a picture is made to suit the section it sits beside.', 'whd' ),
					'stepCompose' => __( 'Writing the piece', 'whd' ),
					'hintCompose' => __( 'Title, sections, snippet and questions, in the shop’s own voice, with the keywords worked in.', 'whd' ),
					/* translators: 1: picture number, 2: how many */
					'stepPicture' => __( 'Picture %1$d of %2$d', 'whd' ),
					'hintAuto'    => __( 'Your media library is searched first; anything it has no good match for is drawn.', 'whd' ),
					'hintAsked'   => __( 'Drawn to match what you asked for.', 'whd' ),
					'hintModelW'  => __( 'Drawn on a model, from your photographs.', 'whd' ),
					'hintModelN'  => __( 'Drawn on a model, in the look of your shop.', 'whd' ),
					'hintGhostW'  => __( 'Drawn on a ghost mannequin, from your photographs.', 'whd' ),
					'fromLibrary' => __( 'from your library', 'whd' ),
					'drawnTag'    => __( 'drawn', 'whd' ),
					'yours'       => __( 'chosen by you', 'whd' ),
					'change'      => __( 'Change', 'whd' ),
					'emptySlot'   => __( 'Choose a picture', 'whd' ),
					/* translators: %d: how many pictures */
					'wantN'       => __( 'This piece has %d pictures', 'whd' ),
					'wantOne'     => __( 'This piece has 1 picture', 'whd' ),
					/* translators: %s: what went wrong */
					'pictureFail' => __( 'not filled: %s', 'whd' ),
					'doneNote'    => __( 'Read it over below, change anything you like, then publish it or save it as a draft.', 'whd' ),
					'writeIt'     => __( 'Write it', 'whd' ),
					'writeAnother' => __( 'Write another', 'whd' ),
				]
			),
		] );
	}

	/** At most this many pictures in one piece: one at the top and one between sections after that. */
	const MAX_PICTURES = 5;

	/** Whether the slow steps can run as background jobs — the studio's runner is there with WooCommerce. */
	private static function jobs(): bool {
		return class_exists( 'WooCommerce' ) && class_exists( 'WHD_AI_Studio' );
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

	/**
	 * The form, laid out the way Write with AI is on the product screen and on Bulk products: what
	 * it is about, what to write (with keywords and anything to specify), then the pictures — how
	 * many, and for each one whether it is found or drawn, on a model or a ghost mannequin, with its
	 * own instructions. One button, and the same loader while it works.
	 */
	private static function compose_form( string $type ) {
		$total   = class_exists( 'WHD_AI_Keywords' ) ? WHD_AI_Keywords::count() : 0;
		$is_post = 'post' === $type;
		$parts   = [
			'excerpt' => [ __( 'Excerpt', 'whd' ), __( 'the summary in lists and feeds', 'whd' ) ],
			'seo'     => [ __( 'Search snippet', 'whd' ), __( 'SEO title and meta description', 'whd' ) ],
			'faq'     => [ __( 'Questions at the end', 'whd' ), __( 'with FAQ structured data', 'whd' ) ],
			'links'   => [ __( 'Links to your pages', 'whd' ), __( 'into related pages on the site', 'whd' ) ],
		];
		?>
		<div class="whd-writer__make whd-studio" id="whd-writer">

			<section class="whd-studio__step">
				<h3 class="whd-studio__h"><span class="whd-studio__n" aria-hidden="true">1</span><label for="whd-writer-idea"><?php echo esc_html( $is_post ? __( 'What the post is about', 'whd' ) : __( 'What the page is about', 'whd' ) ); ?></label></h3>
				<textarea id="whd-writer-idea" rows="3" class="whd-studio__notes" placeholder="<?php esc_attr_e( 'e.g. how to choose between a wrap vest and a cardigan for a Korean winter, for someone who works in an office', 'whd' ); ?>"></textarea>
				<p class="description"><?php esc_html_e( 'A sentence is enough. The more you say about who it is for, the less you will have to edit afterwards.', 'whd' ); ?></p>
			</section>

			<section class="whd-studio__step">
				<h3 class="whd-studio__h"><span class="whd-studio__n" aria-hidden="true">2</span><?php esc_html_e( 'What to write', 'whd' ); ?></h3>
				<div class="whd-studio__parts" role="group" aria-label="<?php esc_attr_e( 'What to write', 'whd' ); ?>">
					<label class="whd-studio__part is-locked">
						<input type="checkbox" checked disabled>
						<span class="whd-studio__part-name"><?php esc_html_e( 'Title and body', 'whd' ); ?></span>
						<span class="whd-studio__part-note"><?php esc_html_e( 'headings, sections, the words', 'whd' ); ?></span>
					</label>
					<?php foreach ( $parts as $key => $label ) : ?>
						<label class="whd-studio__part">
							<input type="checkbox" data-part="<?php echo esc_attr( $key ); ?>" checked>
							<span class="whd-studio__part-name"><?php echo esc_html( $label[0] ); ?></span>
							<span class="whd-studio__part-note"><?php echo esc_html( $label[1] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>

				<div class="whd-studio__field">
					<p class="whd-studio__label" id="whd-writer-length-label"><?php esc_html_e( 'How long?', 'whd' ); ?></p>
					<div class="whd-studio__counts" id="whd-writer-length" role="radiogroup" aria-labelledby="whd-writer-length-label"></div>
				</div>

				<div class="whd-studio__field">
					<span class="whd-studio__label"><?php esc_html_e( 'Keywords to work in', 'whd' ); ?></span>
					<div class="whd-kw" id="whd-writer-keywords" data-whd-keywords
						data-placeholder="<?php esc_attr_e( 'Search your keyword list, or type your own', 'whd' ); ?>"></div>
					<p class="description">
						<?php
						printf(
							/* translators: %s: number of keywords in the list */
							esc_html__( 'Leave it empty and the AI picks from all %s of your keywords — the phrase that fits best becomes the focus keyphrase, never one another page is already aimed at. Pick some to choose yourself; the first one you pick is the focus.', 'whd' ),
							esc_html( number_format_i18n( $total ) )
						);
						?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=whd-ai&tab=keywords' ) ); ?>"><?php esc_html_e( 'Manage the list', 'whd' ); ?></a>
					</p>
				</div>

				<div class="whd-studio__field">
					<label class="whd-studio__label" for="whd-writer-notes"><?php esc_html_e( 'Anything to specify?', 'whd' ); ?></label>
					<textarea id="whd-writer-notes" rows="3" class="whd-studio__notes" placeholder="<?php esc_attr_e( 'e.g. for women in their twenties who work in an office — mention our wrap vests and cardigans — warm and practical, no jargon', 'whd' ); ?>"></textarea>
					<p class="description"><?php esc_html_e( 'Who it is for, what to mention or leave out, the tone. What you write here wins over everything else.', 'whd' ); ?></p>
				</div>
			</section>

			<section class="whd-studio__step">
				<h3 class="whd-studio__h"><span class="whd-studio__n" aria-hidden="true">3</span><?php esc_html_e( 'Pictures', 'whd' ); ?></h3>

				<?php if ( class_exists( 'WHD_AI_Images' ) && WHD_AI_Images::ready() ) : ?>
					<p class="whd-studio__label"><?php esc_html_e( 'Photographs to work from', 'whd' ); ?> <span class="whd-writer__opt"><?php esc_html_e( 'optional', 'whd' ); ?></span></p>
					<div class="whd-studio__photos" id="whd-writer-photos"></div>
					<p class="description" id="whd-writer-photos-note"></p>
				<?php endif; ?>

				<div class="whd-studio__field">
					<p class="whd-studio__label" id="whd-writer-count-label"><?php esc_html_e( 'How many pictures?', 'whd' ); ?></p>
					<div class="whd-studio__counts" id="whd-writer-counts" role="radiogroup" aria-labelledby="whd-writer-count-label"></div>
					<p class="description"><?php esc_html_e( 'The first sits at the top of the page as its featured image; the rest go in between the sections.', 'whd' ); ?></p>
					<div class="whd-studio__shots" id="whd-writer-shots" hidden></div>
				</div>
			</section>

			<div class="whd-studio__go">
				<button type="button" class="button button-primary button-large" id="whd-writer-go"><?php esc_html_e( 'Write it', 'whd' ); ?></button>
				<span class="whd-studio__status" id="whd-writer-status" role="status" aria-live="polite"></span>
			</div>

			<div class="whd-writer__saved" id="whd-writer-saved" role="status" hidden></div>

			<div class="whd-writer__result" id="whd-writer-result" hidden>
				<div class="whd-writer__meta">
					<label class="whd-writer__field">
						<span class="whd-writer__label"><?php esc_html_e( 'Title', 'whd' ); ?></span>
						<input type="text" class="large-text" id="whd-writer-title">
					</label>
					<label class="whd-writer__field" id="whd-writer-desc-field">
						<span class="whd-writer__label"><?php esc_html_e( 'Search snippet', 'whd' ); ?></span>
						<textarea rows="2" class="large-text" id="whd-writer-desc"></textarea>
						<span class="whd-writer__count" id="whd-writer-desc-count"></span>
					</label>
				</div>

				<div class="whd-writer__check" id="whd-writer-check"></div>

				<div class="whd-writer__images" id="whd-writer-images" hidden>
					<p class="whd-writer__label">
						<span id="whd-writer-images-count"></span>
						<span class="whd-writer__status" id="whd-writer-images-status" role="status"></span>
					</p>
					<div class="whd-writer__slots" id="whd-writer-slots"></div>
				</div>

				<div class="whd-writer__preview">
					<p class="whd-writer__label"><?php echo esc_html( $is_post ? __( 'The post itself', 'whd' ) : __( 'The page itself', 'whd' ) ); ?></p>
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
	 * @param array $more notes (anything to specify — wins over the rest), parts (which of excerpt,
	 *                    seo, faq, links to keep), pictures (how many; left out, the length decides).
	 * @return array|WP_Error
	 */
	public static function compose( string $idea, array $keywords, string $type, string $length = 'medium', bool $faq = true, array $more = [] ) {
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
		$notes    = mb_substr( trim( sanitize_textarea_field( (string) ( $more['notes'] ?? '' ) ) ), 0, 2000 );

		/*
		 * Nothing picked: the keywords come from the shop's list, not from the model's own idea of
		 * what people search. It used to be told to "choose the two or three phrases a reader would
		 * search for", and chose phrases the list had never heard of.
		 */
		$shortlist = ! $keywords && class_exists( 'WHD_AI_Keywords' ) ? WHD_AI_Keywords::shortlist( $idea, 0, 15, true ) : [];
		$from_list = '';
		if ( $shortlist ) {
			$lines = [];
			foreach ( $shortlist as $k ) {
				$lines[] = '- ' . $k['keyword'] . ' (' . number_format_i18n( $k['volume'] ) . ' searches a month)'
					. ( $k['taken'] ? ' — already another page\'s focus keyphrase: fine to work in, never to aim at' : '' );
			}
			$from_list = "Choose this page's keywords from the shop's keyword list below — two or three phrases the page is genuinely about, the best one first, because the first is the page's focus keyphrase and may not be one marked as another page's focus. Copy each exactly as it is written there, list them in \"keywords\" in that order, and work every one into the copy word for word. Capitalise their words the way English does (Korean, Thai); capitals are not counted.\n" . implode( "\n", $lines );
		}

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
				. "- the meta description is a sentence, not a list of keywords, and runs 120 to 155 characters\n"
				. "- the first keyword appears in the title, in the meta description and in the opening sentence,\n"
				. "  and in a section heading too when it can go there in grammatical English\n"
				. "- grammar wins over placement, always. If a keyword will not fit a heading without bending it\n"
				. "  out of shape, leave it out of the heading and put it in the paragraph below. \"Why Korean\n"
				. "  clothes brands popular cuts drape so well\" is not a heading, it is a keyword with a sentence\n"
				. "  wrapped round it, and a reader can tell\n"
				. "- that keyword comes round about once every two hundred words: often enough to be the subject,\n"
				. "  never often enough for a reader to notice it\n"
				. "- most sentences stay under twenty words; no paragraph runs past a hundred and fifty\n"
				. "- write in the active voice, and name who does the thing\n\n"
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
				: ( '' !== $from_list ? $from_list : __( 'Choose the two or three phrases a reader would search for and use them in the copy.', 'whd' ) ),
			$shape,
			'post' === $type ? __( 'journal post', 'whd' ) : __( 'page', 'whd' )
		);

		if ( '' !== $notes ) {
			$user .= "\n\nTHE SHOP'S OWN WORDS — who it is for, what to mention or leave out, the tone. They win over anything above:\n" . $notes;
		}
		if ( ! $faq ) {
			$user .= "\n\n" . __( 'Leave "faqs" as an empty list.', 'whd' );
		}

		/*
		 * Room for the whole thing and the thinking that precedes it. These models reason before
		 * they answer out of the same allowance, so a budget sized for the page alone comes back
		 * truncated — and half a JSON document is not a document.
		 */
		$budget = [ 'short' => 9000, 'medium' => 14000, 'long' => 22000 ][ $length ] ?? 14000;

		// It runs as a background job, with no page held open, so it may wait as long as the studio does.
		$wait = [ 'json' => true, 'max_tokens' => $budget, 'timeout' => 240 ];
		$raw  = WHD_AI::call( $system, $user, $wait );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$doc = self::decode( $raw );
		if ( ! is_array( $doc ) || empty( $doc['sections'] ) ) {
			// Once more before giving up: a mangled reply is usually a one-off.
			$raw = WHD_AI::call( $system, $user, $wait );
			if ( is_wp_error( $raw ) ) {
				return $raw;
			}
			$doc = self::decode( $raw );
		}
		if ( ! is_array( $doc ) || empty( $doc['sections'] ) ) {
			return new WP_Error(
				'whd_ai_shape',
				self::looks_cut_off( $raw )
					? __( 'The reply was cut short, twice. Try a shorter length, or raise “Longest reply” in WHD → AI content.', 'whd' )
					: __( 'The model did not return a page in the shape asked for, twice over. Try again, or reword the idea.', 'whd' )
			);
		}

		$doc         = self::proper( self::tidy( $doc, $keywords, $faq ) );
		$doc['html'] = self::to_html( $doc );
		if ( $shortlist ) {
			$doc['keywords'] = self::from_list( (array) $doc['keywords'], $shortlist, wp_strip_all_tags( $doc['title'] . ' ' . $doc['html'] ) );
		}

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
		$doc['parts'] = self::parts( $more['parts'] ?? null );
		$doc['slots'] = self::image_slots( $doc, isset( $more['pictures'] ) ? (int) $more['pictures'] : -1 );

		return $doc;
	}

	/**
	 * The keywords the model said it used, held to the shop's list.
	 *
	 * Anything not on the list is dropped, and list phrases the copy carries without saying so are
	 * added. The first one is the focus keyphrase, so it has to be a phrase no other page is
	 * already aimed at, and one the copy really contains — the best such phrase goes to the front.
	 * With nothing left, the two best list phrases stand in, and the check that follows says the
	 * copy is missing them.
	 *
	 * @param string[] $said      What the reply listed.
	 * @param array[]  $shortlist WHD_AI_Keywords::shortlist().
	 * @param string   $copy      The page as plain text.
	 * @return string[]
	 */
	private static function from_list( array $said, array $shortlist, string $copy ): array {
		$copy = mb_strtolower( $copy );
		$in   = static fn( array $k ): bool => false !== mb_strpos( $copy, mb_strtolower( trim( $k['keyword'] ) ) );
		$by   = [];
		foreach ( $shortlist as $k ) {
			$by[ mb_strtolower( trim( $k['keyword'] ) ) ] = $k;
		}
		$kept = [];
		foreach ( $said as $word ) {
			$k = $by[ mb_strtolower( trim( (string) $word ) ) ] ?? null;
			if ( $k && ! isset( $kept[ $k['keyword'] ] ) ) {
				$kept[ $k['keyword'] ] = $k;
			}
		}
		foreach ( $shortlist as $k ) {
			if ( count( $kept ) < 4 && ! isset( $kept[ $k['keyword'] ] ) && $in( $k ) ) {
				$kept[ $k['keyword'] ] = $k;
			}
		}
		if ( ! $kept ) {
			foreach ( array_slice( $shortlist, 0, 2 ) as $k ) {
				$kept[ $k['keyword'] ] = $k;
			}
		}
		$kept = array_values( $kept );

		// The focus keyphrase first: in the copy and nobody else's, failing that nobody else's.
		$focus = null;
		foreach ( [ $kept, $shortlist ] as $pool ) {
			foreach ( $pool as $k ) {
				if ( ! $focus && ! $k['taken'] && $in( $k ) ) {
					$focus = $k;
				}
			}
		}
		foreach ( array_merge( $kept, $shortlist ) as $k ) {
			if ( ! $focus && ! $k['taken'] ) {
				$focus = $k;
			}
		}
		$words = wp_list_pluck( $kept, 'keyword' );
		if ( $focus ) {
			$words = array_values( array_diff( $words, [ $focus['keyword'] ] ) );
			array_unshift( $words, $focus['keyword'] );
		}
		return array_slice( $words, 0, 4 );
	}

	/** Proper words capitalised throughout — the keyword list is lower case and copied word for word. */
	private static function proper( array $doc ): array {
		if ( ! class_exists( 'WHD_AI_Studio' ) ) {
			return $doc;
		}
		$fix = [ 'WHD_AI_Studio', 'proper' ];
		foreach ( [ 'title', 'seo_title', 'meta_description', 'excerpt' ] as $field ) {
			$doc[ $field ] = call_user_func( $fix, (string) ( $doc[ $field ] ?? '' ) );
		}
		$doc['intro'] = array_map( $fix, (array) ( $doc['intro'] ?? [] ) );
		foreach ( (array) ( $doc['sections'] ?? [] ) as $n => $section ) {
			$doc['sections'][ $n ]['heading']    = call_user_func( $fix, (string) $section['heading'] );
			$doc['sections'][ $n ]['paragraphs'] = array_map( $fix, (array) $section['paragraphs'] );
			$doc['sections'][ $n ]['bullets']    = array_map( $fix, (array) ( $section['bullets'] ?? [] ) );
		}
		foreach ( (array) ( $doc['faqs'] ?? [] ) as $n => $faq ) {
			foreach ( [ 'question', 'answer' ] as $key ) {
				if ( isset( $faq[ $key ] ) && is_string( $faq[ $key ] ) ) {
					$doc['faqs'][ $n ][ $key ] = call_user_func( $fix, $faq[ $key ] );
				}
			}
		}
		return $doc;
	}

	/**
	 * Read the model's reply, forgiving the usual wrappers.
	 *
	 * Fences, a sentence of preamble, the whole thing inside a key of its own: none of it changes
	 * what was meant, so none of it is worth failing over.
	 */
	private static function decode( $raw ) {
		$text = trim( preg_replace( '/^```[a-z]*\s*|\s*```$/i', '', (string) $raw ) );
		$doc  = json_decode( $text, true );

		if ( ! is_array( $doc ) ) {
			// Anything either side of the object is not ours to care about.
			$first = strpos( $text, '{' );
			$last  = strrpos( $text, '}' );
			if ( false !== $first && false !== $last && $last > $first ) {
				$doc = json_decode( substr( $text, $first, $last - $first + 1 ), true );
			}
		}
		if ( ! is_array( $doc ) ) {
			return null;
		}
		// Wrapped in a key of its own — {"page": {…}} — rather than returned flat.
		if ( empty( $doc['sections'] ) ) {
			foreach ( $doc as $value ) {
				if ( is_array( $value ) && ! empty( $value['sections'] ) ) {
					return $value;
				}
			}
		}

		return $doc;
	}

	/** Did the reply simply stop? Then the budget is the thing to change, not the wording. */
	private static function looks_cut_off( $raw ): bool {
		$text = rtrim( (string) $raw );

		return '' !== $text && ! in_array( substr( $text, -1 ), [ '}', ']', '`' ], true );
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

	/* ─────────────────────────── pictures ───────────────────────────
	   A page of unbroken text is a page nobody finishes. How many it wants is worked out from how
	   long it turned out to be, and then there are two ways to fill them: let this look through the
	   media library and draw whatever is missing, or choose every one by hand. */

	/**
	 * The picture slots this piece wants: one at the top, then one above a section.
	 *
	 * Asked for a number, the ones after the first are spread evenly through the sections — never
	 * the first section, which sits right under the picture at the top, and never two on one. Left
	 * to itself (-1) it is one every other section, four at the most.
	 *
	 * @return array [ { key, subject, heading } ]
	 */
	public static function image_slots( array $doc, int $count = -1 ): array {
		if ( 0 === $count ) {
			return [];
		}
		$sections = array_values( (array) ( $doc['sections'] ?? [] ) );
		$slots    = [ [
			'key'     => 'hero',
			'subject' => (string) ( $doc['title'] ?? '' ),
			'heading' => '',
		] ];

		$at = [];
		if ( $count < 0 ) {
			for ( $i = 1; $i < count( $sections ) && count( $at ) < 3; $i += 2 ) {
				$at[] = $i;
			}
		} else {
			$want = min( $count, self::MAX_PICTURES ) - 1;
			$room = count( $sections ) - 1; // sections 1 … n-1
			for ( $k = 1; $k <= min( $want, $room ); $k++ ) {
				$at[] = 1 + (int) floor( ( $k - 1 ) * $room / min( $want, $room ) + $room / ( 2 * min( $want, $room ) ) - 0.5 );
			}
			$at = array_values( array_unique( array_filter( $at, static fn( $i ) => $i >= 1 && $i < count( $sections ) ) ) );
		}
		foreach ( $at as $i ) {
			$slots[] = [
				'key'     => 'section-' . $i,
				'subject' => (string) ( $sections[ $i ]['heading'] ?? '' ),
				'heading' => (string) ( $sections[ $i ]['heading'] ?? '' ),
			];
		}

		return $slots;
	}

	/** Which of the optional parts to keep; all of them when nothing says otherwise. */
	private static function parts( $given ): array {
		$all = [ 'excerpt', 'seo', 'faq', 'links' ];
		if ( ! is_array( $given ) ) {
			return $all;
		}
		return array_values( array_intersect( $all, array_map( 'sanitize_key', array_filter( $given, 'is_scalar' ) ) ) );
	}

	/**
	 * The closest thing in the media library, or nothing.
	 *
	 * Scored on the words shared with what the slot is about. The floor matters more than the
	 * ceiling: a picture that merely exists is worse than no picture, so a weak match is refused
	 * and the slot goes to the model instead.
	 *
	 * @return array|null { id, url, alt, score }
	 */
	public static function find_image( string $subject, array $keywords = [], array $taken = [] ) {
		$words = array_values( array_unique( array_filter(
			preg_split( '/[^a-z0-9]+/', strtolower( $subject . ' ' . implode( ' ', $keywords ) ) ),
			static fn( $w ) => strlen( $w ) > 3 && ! in_array( $w, self::STOPWORDS, true )
		) ) );
		if ( ! $words ) {
			return null;
		}

		/*
		 * Every image, scored here rather than in SQL. A search that makes the database do the
		 * matching wants every word to appear, which on a library of product shots means nothing
		 * ever matches — "winter coat office" is four words no filename has all of.
		 */
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT p.ID, p.post_title, p.post_excerpt, pm.meta_value AS alt, pf.meta_value AS file
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attachment_image_alt'
			 LEFT JOIN {$wpdb->postmeta} pf ON pf.post_id = p.ID AND pf.meta_key = '_wp_attached_file'
			 WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'
			 ORDER BY p.ID DESC LIMIT 400"
		);

		$haystacks = [];
		foreach ( $rows as $image ) {
			$haystacks[ (int) $image->ID ] = ' ' . trim( preg_replace(
				'/[^a-z0-9]+/',
				' ',
				strtolower( implode( ' ', [
					$image->post_title,
					$image->post_excerpt,
					(string) $image->alt,
					(string) $image->file,
				] ) )
			) ) . ' ';
		}

		/*
		 * A word that half the library carries tells you nothing about which picture to use —
		 * "korean" is on nearly every alt text in this shop. Each word is weighed by how rare it
		 * is, and the ones that are everywhere are dropped.
		 */
		/* Singular and plural are the same word to a reader, so they are the same word here. */
		$variants = static function ( $word ) {
			$forms = [ $word ];
			if ( 's' === substr( $word, -1 ) ) {
				$forms[] = substr( $word, 0, -1 );
				if ( 'es' === substr( $word, -2 ) ) {
					$forms[] = substr( $word, 0, -2 );
				}
			} else {
				$forms[] = $word . 's';
			}

			return array_values( array_unique( array_filter( $forms, static fn( $f ) => strlen( $f ) > 3 ) ) );
		};
		$hit = static function ( $hay, $forms ) {
			foreach ( $forms as $form ) {
				if ( false !== strpos( $hay, ' ' . $form . ' ' ) ) {
					return true;
				}
			}

			return false;
		};

		$weights = [];
		$total   = max( 1, count( $haystacks ) );
		foreach ( $words as $word ) {
			$forms = $variants( $word );
			$seen  = 0;
			foreach ( $haystacks as $hay ) {
				$seen += $hit( $hay, $forms ) ? 1 : 0;
			}
			if ( ! $seen ) {
				continue;
			}
			$share = $seen / $total;
			if ( $share < 0.05 ) {
				// Particular enough to carry a slot on its own: a post about coats wants the coat.
				$weights[ $word ] = [ 1.5, $forms ];
			} elseif ( $share < 0.25 ) {
				$weights[ $word ] = [ 0.5, $forms ];    // common enough to be weak evidence on its own
			}
		}
		if ( ! $weights ) {
			return null;
		}

		$best = null;
		foreach ( $rows as $image ) {
			if ( in_array( (int) $image->ID, array_map( 'intval', $taken ), true ) ) {
				continue;
			}
			$haystack = $haystacks[ (int) $image->ID ];

			$score = 0;
			foreach ( $weights as $weight ) {
				// Whole words only: "coat" should not be satisfied by "coating".
				$score += $hit( $haystack, $weight[1] ) ? $weight[0] : 0;
			}
			if ( $score && ( ! $best || $score > $best['score'] ) ) {
				$best = [
					'id'    => (int) $image->ID,
					'url'   => wp_get_attachment_image_url( $image->ID, 'medium' ),
					'full'  => wp_get_attachment_image_url( $image->ID, 'large' ),
					'alt'   => (string) ( $image->alt ?: $image->post_title ),
					'score' => $score,
				];
			}
		}

		/*
		 * The floor, in weighted terms: one word that is particular to this picture plus anything
		 * else, or two merely plausible ones. Below that it is a coincidence, and a coincidence on
		 * the page is worse than a gap.
		 */
		return ( $best && $best['score'] >= 1.5 ) ? $best : null;
	}

	/**
	 * Fill one slot: the library first, the model only where the library has nothing — or, asked for
	 * a picture on a model or a ghost mannequin, drawn straight away, from the photographs of the
	 * shop's own pieces when there are some.
	 *
	 * @param string $mode auto | model | mannequin.
	 * @param int[]  $refs Photographs of the pieces the piece is about.
	 * @return array|WP_Error
	 */
	public static function fill_slot( array $slot, array $doc, array $taken = [], bool $may_draw = true, string $ask = '', string $mode = 'auto', array $refs = [] ) {
		$ask      = trim( $ask );
		$can_draw = $may_draw && class_exists( 'WHD_AI_Images' ) && WHD_AI_Images::ready();
		$refs     = array_values( array_filter( array_map( 'absint', $refs ), static fn( $id ) => $id && wp_attachment_is_image( $id ) ) );

		if ( in_array( $mode, [ 'model', 'mannequin' ], true ) ) {
			if ( ! $can_draw ) {
				return new WP_Error( 'whd_ai_writer_nodraw', __( 'Drawing pictures needs a Google AI key. Add one in WHD → AI content.', 'whd' ) );
			}
			if ( 'mannequin' === $mode && ! $refs ) {
				return new WP_Error( 'whd_ai_writer_noref', __( 'A ghost mannequin needs photographs of the piece to work from.', 'whd' ) );
			}
			$id = WHD_AI_Images::make(
				sprintf(
					/* translators: 1: what the picture is about, 2: the piece it belongs to */
					__( '%1$s. The photograph sits beside writing about %2$s for an online womenswear boutique.', 'whd' ),
					$slot['subject'],
					(string) ( $doc['title'] ?? '' )
				),
				sanitize_title( $slot['subject'] ?: 'journal' ),
				$slot['subject'],
				$ask,
				[ 'aspect' => '3:2', 'refs' => $refs, 'mode' => $mode ]
			);
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			return [
				'id'     => (int) $id,
				'url'    => wp_get_attachment_image_url( $id, 'medium' ),
				'full'   => wp_get_attachment_image_url( $id, 'large' ),
				'alt'    => $slot['subject'],
				'score'  => 0,
				'source' => 'drawn',
				'key'    => $slot['key'],
			];
		}

		/*
		 * Asking for something specific means the library is not what you want. Searching it first
		 * would hand back whatever happened to match the heading and quietly ignore what was
		 * asked for. Only when nothing can be drawn does the ask become extra words to search on.
		 */
		$hunt = ( '' !== $ask && $can_draw )
			? ''
			: trim( $slot['subject'] . ( '' !== $ask ? ' ' . $ask : '' ) );

		$found = '' === $hunt ? null : self::find_image( $hunt, (array) ( $doc['keywords'] ?? [] ), $taken );
		if ( $found ) {
			$found['source'] = 'library';
			$found['key']    = $slot['key'];

			return $found;
		}
		if ( ! $can_draw ) {
			return new WP_Error( 'whd_ai_writer_nopic', __( 'Nothing in the library fits, and drawing one is not available.', 'whd' ) );
		}

		$id = WHD_AI_Images::make(
			sprintf(
				/* translators: 1: what the picture is about, 2: the piece it belongs to */
				__( '%1$s. The photograph sits beside writing about %2$s for an online womenswear boutique.', 'whd' ),
				$slot['subject'],
				(string) ( $doc['title'] ?? '' )
			),
			sanitize_title( $slot['subject'] ?: 'journal' ),
			$slot['subject'],
			$ask,
			[ 'refs' => $refs ]
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return [
			'id'     => (int) $id,
			'url'    => wp_get_attachment_image_url( $id, 'medium' ),
			'full'   => wp_get_attachment_image_url( $id, 'large' ),
			'alt'    => $slot['subject'],
			'score'  => 0,
			'source' => 'drawn',
			'key'    => $slot['key'],
		];
	}

	/**
	 * Link the piece into the rest of the site.
	 *
	 * A page that links nowhere is a dead end: nothing passes through it, and a reader who
	 * finishes it has to go back the way they came. The model will not do this on its own — it
	 * has no idea what else the site has — so it is done afterwards, from what is published.
	 *
	 * Conservative on purpose. Only a phrase another page is actually aimed at counts, only its
	 * first appearance is linked, never inside a heading or a link that already exists, and three
	 * is the most any piece gets. Anything looser turns prose into a page of blue words.
	 *
	 * @param string $html  The body as it stands.
	 * @param int    $skip  The page being written, which should not link to itself.
	 */
	public static function link_up( string $html, int $skip = 0 ): string {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.post_id, m.meta_value AS phrase
				 FROM {$wpdb->postmeta} m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.meta_key = %s AND m.meta_value <> ''
				   AND p.post_status = 'publish' AND p.post_type IN ( 'post', 'page' )
				   AND m.post_id <> %d",
				class_exists( 'WHD_SEO' ) ? WHD_SEO::KEYPHRASE : '_whd_focus_keyphrase',
				$skip
			),
			ARRAY_A
		);
		if ( ! $rows ) {
			return $html;
		}

		// Longest phrase first: "korean winter coat" should win over "winter coat".
		usort( $rows, static fn( $a, $b ) => mb_strlen( $b['phrase'] ) <=> mb_strlen( $a['phrase'] ) );

		$added = 0;
		foreach ( $rows as $row ) {
			if ( $added >= 3 ) {
				break;
			}
			$phrase = trim( (string) $row['phrase'] );
			if ( mb_strlen( $phrase ) < 8 ) {
				continue;
			}
			$url = get_permalink( (int) $row['post_id'] );
			if ( ! $url ) {
				continue;
			}

			/* Inside a paragraph, not inside a heading, a figure, or another link. The lookahead
			   counts the tags ahead: an even number of <a ...> openings means we are outside one. */
			$pattern = '#(?<![\w>])(' . preg_quote( $phrase, '#' ) . ')(?![^<]*</a>)(?![^<]*</h[1-6]>)(?![^<]*</figcaption>)#iu';
			$done    = 0;
			$html    = preg_replace_callback(
				$pattern,
				static function ( $m ) use ( $url, &$done ) {
					if ( $done ) {
						return $m[0];
					}
					$done = 1;

					return '<a href="' . esc_url( $url ) . '">' . $m[1] . '</a>';
				},
				$html,
				1
			);
			$added += $done;
		}

		return $html;
	}

	/**
	 * Where to go next, when the copy linked nowhere on its own.
	 *
	 * Linking a phrase inside a sentence only works when another page happens to be aimed at a
	 * phrase this one happens to say, which is rarer than it sounds — most pieces came back with
	 * no links at all. A short list at the end is the honest alternative: it is useful to a
	 * reader, it is what every magazine does, and it means nothing published here is a dead end.
	 *
	 * @param array $keywords What this piece is about.
	 * @param int   $skip     The page being written.
	 */
	public static function read_next( array $keywords, int $skip = 0 ): string {
		$wanted = [];
		foreach ( $keywords as $phrase ) {
			foreach ( preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( (string) $phrase ), -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
				if ( mb_strlen( $w ) >= 4 ) {
					$wanted[ rtrim( $w, 's' ) ] = true;
				}
			}
		}
		if ( ! $wanted ) {
			return '';
		}

		$candidates = get_posts( [
			'post_type'      => [ 'post', 'page' ],
			'post_status'    => 'publish',
			'posts_per_page' => 120,
			'post__not_in'   => $skip ? [ $skip ] : [],
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		$scored = [];
		foreach ( $candidates as $other ) {
			// The title is what the link will say, so the title is what it is judged on.
			$words = [];
			foreach ( preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( get_the_title( $other ) ), -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
				if ( mb_strlen( $w ) >= 4 ) {
					$words[ rtrim( $w, 's' ) ] = true;
				}
			}
			$shared = count( array_intersect_key( $words, $wanted ) );
			if ( $shared ) {
				$scored[] = [ 'post' => $other, 'shared' => $shared ];
			}
		}
		if ( ! $scored ) {
			return '';
		}

		usort( $scored, static fn( $a, $b ) => $b['shared'] <=> $a['shared'] );

		$items = '';
		foreach ( array_slice( $scored, 0, 3 ) as $pick ) {
			$items .= sprintf(
				'<li><a href="%s">%s</a></li>',
				esc_url( (string) get_permalink( $pick['post'] ) ),
				esc_html( get_the_title( $pick['post'] ) )
			);
		}

		return '<h2>' . esc_html__( 'Read next', 'whd' ) . '</h2><ul>' . $items . '</ul>';
	}

	/**
	 * Put the chosen pictures into the copy.
	 *
	 * The first belongs to the page rather than to a paragraph, so it becomes the featured image.
	 * The rest go in above the section they were chosen for.
	 */
	public static function place_images( string $html, array $images ): string {
		foreach ( $images as $image ) {
			if ( empty( $image['heading'] ) || empty( $image['full'] ) ) {
				continue;
			}
			$figure = sprintf(
				"\n<figure class=\"wp-block-image size-large\"><img src=\"%s\" alt=\"%s\" loading=\"lazy\" /></figure>\n",
				esc_url( $image['full'] ),
				esc_attr( $image['alt'] ?? '' )
			);
			$heading = preg_quote( $image['heading'], '#' );
			$html    = preg_replace( '#(<h2[^>]*>\s*' . $heading . '\s*</h2>)#i', $figure . '$1', $html, 1 );
		}

		return $html;
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

		/*
		 * The pictures go in before the post is written rather than after: the body saved is then
		 * the body with its figures in it, and nothing has to be edited twice.
		 */
		$parts  = self::parts( $doc['parts'] ?? null );
		$images = array_values( array_filter( (array) ( $doc['images'] ?? [] ), static fn( $i ) => ! empty( $i['id'] ) ) );
		$html   = self::place_images( $html, $images );
		if ( in_array( 'links', $parts, true ) ) {
			$html = self::link_up( $html );
		}

		/* Nothing linked out of its own accord, so give the reader somewhere to go. */
		if ( in_array( 'links', $parts, true ) && false === mb_stripos( $html, '<a href' ) ) {
			$html .= self::read_next( (array) ( $doc['keywords'] ?? [] ) );
		}

		$id = wp_insert_post( [
			'post_type'    => $type,
			'post_status'  => $status,
			'post_title'   => $title,
			'post_name'    => sanitize_title( $doc['slug'] ?? $title ),
			'post_content' => wp_kses_post( $html ),
			'post_excerpt' => in_array( 'excerpt', $parts, true ) ? sanitize_textarea_field( (string) ( $doc['excerpt'] ?? '' ) ) : '',
		], true );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		// The one with no heading of its own belongs to the page: that is the featured image.
		foreach ( $images as $image ) {
			if ( empty( $image['heading'] ) ) {
				set_post_thumbnail( $id, (int) $image['id'] );
				break;
			}
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

		// The two the theme's own SEO head reads — unless the search snippet was left out on purpose.
		if ( ! in_array( 'seo', $parts, true ) ) {
			$doc['meta_description'] = '';
			$doc['seo_title']        = '';
		}
		if ( ! empty( $doc['meta_description'] ) ) {
			update_post_meta( $id, '_omc_meta_description', sanitize_textarea_field( $doc['meta_description'] ) );
		}
		if ( ! empty( $doc['seo_title'] ) ) {
			update_post_meta( $id, '_omc_seo_title', sanitize_text_field( $doc['seo_title'] ) );
		}

		/* The score box measures a page against its focus keyphrase, and the page already knows
		   what it was written for — there is no reason to make somebody type it again. */
		$aimed = array_values( array_filter( (array) ( $doc['keywords'] ?? [] ), 'is_scalar' ) );
		if ( $aimed && class_exists( 'WHD_SEO' ) && '' === (string) get_post_meta( $id, WHD_SEO::KEYPHRASE, true ) ) {
			update_post_meta( $id, WHD_SEO::KEYPHRASE, sanitize_text_field( (string) $aimed[0] ) );
		}

		if ( class_exists( 'WHD_AI_Keywords' ) ) {
			WHD_AI_Keywords::mark_used( (array) ( $doc['keywords'] ?? [] ) );
		}

		/* Score what was just written, so the screen can say how it did rather than leaving
		   somebody to open the page and look. */
		$scored = null;
		if ( class_exists( 'WHD_SEO' ) ) {
			$scored = WHD_SEO::analyse( WHD_SEO::doc_from_post( $id ) );
			update_post_meta( $id, WHD_SEO::SCORE, (int) $scored['score'] );
		}

		return [
			'id'        => (int) $id,
			'edit_url'  => get_edit_post_link( $id, 'raw' ),
			'view_url'  => get_permalink( $id ),
			'status'    => $status,
			'title'     => $title,
			'seo'       => $scored ? [
				'score'  => (int) $scored['score'],
				'rating' => (string) $scored['rating'],
				'label'  => (string) $scored['label'],
			] : null,
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
		register_rest_route( 'whd/v1', '/ai/write-image', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_image' ],
			'permission_callback' => $can,
		] );
		register_rest_route( 'whd/v1', '/ai/write-save', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_save' ],
			'permission_callback' => $can,
		] );
	}

	/**
	 * Write the piece — as a background job where there is a runner for one: a long piece keeps the
	 * model busy for a minute or more, and the live host ends a request that quiet with a bare 500.
	 */
	public static function rest_compose( WP_REST_Request $r ) {
		$work = static function () use ( $r ) {
			$more = [
				'notes' => (string) $r->get_param( 'notes' ),
				'parts' => $r->get_param( 'parts' ),
			];
			if ( null !== $r->get_param( 'pictures' ) ) {
				$more['pictures'] = max( 0, min( self::MAX_PICTURES, (int) $r->get_param( 'pictures' ) ) );
			}
			$doc = self::compose(
				(string) $r->get_param( 'idea' ),
				(array) $r->get_param( 'keywords' ),
				sanitize_key( (string) $r->get_param( 'type' ) ),
				sanitize_key( (string) $r->get_param( 'length' ) ),
				(bool) $r->get_param( 'faq' ),
				$more
			);
			if ( is_wp_error( $doc ) ) {
				return new WP_REST_Response( [ 'message' => $doc->get_error_message() ], 400 );
			}

			return new WP_REST_Response( $doc, 200 );
		};

		return self::jobs() ? WHD_AI_Studio::background( 'compose', $work ) : $work();
	}

	/**
	 * Fill one picture slot.
	 *
	 * One at a time from the browser: drawing takes the best part of fifteen seconds, and a single
	 * request for four of them is a request that times out.
	 */
	public static function rest_image( WP_REST_Request $r ) {
		$doc   = (array) $r->get_param( 'doc' );
		$slot  = (array) $r->get_param( 'slot' );
		$taken = array_map( 'intval', (array) $r->get_param( 'taken' ) );
		if ( empty( $slot['key'] ) ) {
			return new WP_REST_Response( [ 'message' => __( 'No slot was named.', 'whd' ) ], 400 );
		}

		$work = static function () use ( $r, $doc, $slot, $taken ) {
			$filled = self::fill_slot(
				$slot,
				$doc,
				$taken,
				(bool) $r->get_param( 'draw' ),
				mb_substr( sanitize_textarea_field( (string) $r->get_param( 'ask' ) ), 0, 500 ),
				sanitize_key( (string) ( $r->get_param( 'mode' ) ?: 'auto' ) ),
				array_slice( array_map( 'absint', (array) $r->get_param( 'refs' ) ), 0, 6 )
			);
			if ( is_wp_error( $filled ) ) {
				return new WP_REST_Response( [ 'message' => $filled->get_error_message() ], 400 );
			}
			$filled['heading'] = (string) ( $slot['heading'] ?? '' );

			return new WP_REST_Response( $filled, 200 );
		};

		return self::jobs() ? WHD_AI_Studio::background( 'write-image', $work ) : $work();
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
