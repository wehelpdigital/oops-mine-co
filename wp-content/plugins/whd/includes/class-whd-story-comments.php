<?php
/**
 * Comments under the product story.
 *
 * Deliberately separate from WooCommerce reviews. A review is a verdict with a star rating and it
 * belongs beside the price; this is the conversation underneath the writing — how someone styled
 * it, what size they took, whether it ran small. Keeping them apart means the star average stays a
 * measure of the product rather than of the chatter.
 *
 * Stored as ordinary WordPress comments with comment_type `whd_story`, so moderation, spam tools
 * and the comments screen all work as they already do, and nothing leaks into the review average.
 *
 * Commenting requires an account. That is the point of the feature as asked for: a name attached
 * to a comment is worth more than an anonymous one, and it gives a reason to register.
 *
 * @package WHD
 */

defined( 'ABSPATH' ) || exit;

class WHD_Story_Comments {

	const TYPE = 'whd_story';

	/**
	 * Not 'rating'.
	 *
	 * WooCommerce works out a product's star average with a raw SQL query over comment meta with
	 * meta_key = 'rating', and that query does not filter by comment_type — the comments_clauses
	 * filter this class uses to keep notes out of review queries never sees it. A note written
	 * under the review key would quietly move the product's rating, which is the one thing this
	 * section exists to stay out of.
	 */
	const RATING = 'whd_rating';

	public static function init() {
		// Just after the story, which itself sits at 15.
		add_action( 'woocommerce_after_single_product_summary', [ __CLASS__, 'render' ], 16 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );

		add_action( 'init', [ __CLASS__, 'handle_post' ] );

		// Keep these out of any comment query that did not ask for them.
		add_filter( 'comments_clauses', [ __CLASS__, 'exclude_from_reviews' ], 10, 2 );
	}

	public static function assets() {
		if ( function_exists( 'is_product' ) && is_product() ) {
			wp_enqueue_style( 'whd-story', WHD_URL . 'assets/story.css', [], WHD_VERSION );
		}
	}

	/* ─────────────────────────── Keeping reviews clean ─────────────────────────── */

	/**
	 * WooCommerce counts every comment on a product as a review. Ours are not reviews, so exclude
	 * them from any query that did not explicitly ask for this type.
	 */
	public static function exclude_from_reviews( $clauses, $query ) {
		$asked = $query->query_vars['type'] ?? '';
		if ( self::TYPE === $asked || ( is_array( $asked ) && in_array( self::TYPE, $asked, true ) ) ) {
			return $clauses;
		}
		global $wpdb;
		$clauses['where'] .= $wpdb->prepare( " AND {$wpdb->comments}.comment_type != %s", self::TYPE );
		return $clauses;
	}

	/*
	 * There is deliberately no filter on woocommerce_product_get_review_count.
	 *
	 * WooCommerce counts reviews with its own SQL —
	 *   WHERE comment_approved = '1' AND comment_type IN ( 'review', '', 'comment' )
	 * — which already leaves `whd_story` out. Subtracting the notes from that number as well
	 * counted them twice: a product with three reviews and two notes reported one review.
	 *
	 * The rating average is safe for a different reason: that query keys on meta_key = 'rating',
	 * and notes store theirs under self::RATING. Neither number needs help from us.
	 */

	/* ─────────────────────────── Posting ─────────────────────────── */

	public static function handle_post() {
		if ( empty( $_POST['whd_story_comment_nonce'] ) || empty( $_POST['whd_story_post_id'] ) ) {
			return;
		}
		$post_id = absint( wp_unslash( $_POST['whd_story_post_id'] ) );
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['whd_story_comment_nonce'] ) ), 'whd_story_comment_' . $post_id ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( add_query_arg( 'whd_comment', 'login', get_permalink( $post_id ) ) );
			exit;
		}
		$text = isset( $_POST['whd_story_comment'] ) ? trim( wp_kses_post( wp_unslash( $_POST['whd_story_comment'] ) ) ) : '';
		if ( '' === $text ) {
			wp_safe_redirect( add_query_arg( 'whd_comment', 'empty', get_permalink( $post_id ) ) . '#whd-talk' );
			exit;
		}

		$rating = isset( $_POST['whd_story_rating'] ) ? (int) $_POST['whd_story_rating'] : 0;
		$rating = ( $rating >= 1 && $rating <= 5 ) ? $rating : 0;

		$user = wp_get_current_user();
		$id   = wp_insert_comment( [
			'comment_post_ID'      => $post_id,
			'comment_author'       => $user->display_name,
			'comment_author_email' => $user->user_email,
			'comment_content'      => $text,
			'comment_type'         => self::TYPE,
			'user_id'              => $user->ID,
			'comment_approved'     => (int) ! get_option( 'comment_moderation' ),
		] );

		if ( $id && $rating ) {
			add_comment_meta( $id, self::RATING, $rating, true );
		}

		$flag = $id ? ( get_option( 'comment_moderation' ) ? 'pending' : 'ok' ) : 'error';
		wp_safe_redirect( add_query_arg( 'whd_comment', $flag, get_permalink( $post_id ) ) . '#whd-talk' );
		exit;
	}

	/* ─────────────────────────── Ratings ─────────────────────────── */

	/** The rating on one note, or 0 when it was left blank. */
	public static function rating( $comment_id ) {
		return (int) get_comment_meta( (int) $comment_id, self::RATING, true );
	}

	/**
	 * The average across the notes that carried a rating, and how many did.
	 *
	 * Its own number, shown under its own label. Not the product's star rating, and never mixed
	 * into it — a note is a styling remark, a review is a verdict.
	 *
	 * @param array $comments Comment objects.
	 * @return array [ average (float, one decimal), count (int) ]
	 */
	public static function average( array $comments ) {
		$scores = [];
		foreach ( $comments as $c ) {
			$score = self::rating( $c->comment_ID );
			if ( $score ) {
				$scores[] = $score;
			}
		}
		return $scores ? [ round( array_sum( $scores ) / count( $scores ), 1 ), count( $scores ) ] : [ 0, 0 ];
	}

	/** Five stars, filled to $score. Decorative — the number beside it is what gets read out. */
	private static function stars( $score ) {
		$out = '<span class="whd-stars" aria-hidden="true">';
		for ( $i = 1; $i <= 5; $i++ ) {
			$out .= '<span class="whd-stars__s' . ( $i <= round( $score ) ? ' is-on' : '' ) . '">&#9733;</span>';
		}
		return $out . '</span>';
	}

	/* ─────────────────────────── Output ─────────────────────────── */

	public static function render() {
		$id = get_the_ID();
		if ( ! apply_filters( 'whd_story_comments_render', true, $id ) ) {
			return;
		}
		$comments = get_comments( [
			'post_id' => $id,
			'type'    => self::TYPE,
			'status'  => 'approve',
			'order'   => 'ASC',
		] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a redirect flag, not an action
		$flag = isset( $_GET['whd_comment'] ) ? sanitize_key( wp_unslash( $_GET['whd_comment'] ) ) : '';
		?>
		<section class="whd-talk" id="whd-talk" aria-labelledby="whd-talk-title">
			<div class="whd-talk__inner">

				<div class="whd-talk__aside">
					<h2 class="whd-talk__title" id="whd-talk-title"><?php esc_html_e( 'Notes from the community', 'whd' ); ?></h2>
					<p class="whd-talk__count">
						<?php
						printf(
							/* translators: %d: number of notes */
							esc_html( _n( '%d note', '%d notes', count( $comments ), 'whd' ) ),
							count( $comments )
						);
						?>
					</p>
					<?php
					[ $whd_avg, $whd_rated ] = self::average( $comments );
					if ( $whd_rated ) :
						?>
						<p class="whd-talk__avg">
							<?php echo self::stars( $whd_avg ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built above from an integer ?>
							<span class="whd-talk__avg-num"><?php echo esc_html( number_format_i18n( $whd_avg, 1 ) ); ?></span>
							<span class="whd-talk__avg-of">
								<?php
								printf(
									/* translators: %d: how many notes carried a rating */
									esc_html( _n( 'from %d note', 'from %d notes', $whd_rated, 'whd' ) ),
									(int) $whd_rated
								);
								?>
							</span>
						</p>
					<?php endif; ?>
					<p class="whd-talk__lede"><?php esc_html_e( 'How people styled it, what size they took, what they would tell someone thinking about it.', 'whd' ); ?></p>

					<?php if ( is_user_logged_in() ) : ?>
						<form class="whd-talk__form" method="post" action="<?php echo esc_url( get_permalink( $id ) ); ?>#whd-talk">
							<?php wp_nonce_field( 'whd_story_comment_' . $id, 'whd_story_comment_nonce' ); ?>
							<input type="hidden" name="whd_story_post_id" value="<?php echo esc_attr( $id ); ?>">
							<fieldset class="whd-rate">
								<legend class="whd-talk__label"><?php esc_html_e( 'How did it work out?', 'whd' ); ?></legend>
								<div class="whd-rate__stars">
									<?php
									/*
									 * Radios in reverse order, so the CSS sibling selector can light up
									 * every star to the left of the one being hovered without any script.
									 * Optional on purpose: a note about sizing is worth reading with or
									 * without a score attached.
									 */
									for ( $whd_i = 5; $whd_i >= 1; $whd_i-- ) :
										?>
										<input type="radio" class="whd-rate__input" id="whd-rate-<?php echo (int) $whd_i; ?>" name="whd_story_rating" value="<?php echo (int) $whd_i; ?>">
										<label class="whd-rate__star" for="whd-rate-<?php echo (int) $whd_i; ?>">
											<span aria-hidden="true">&#9733;</span>
											<span class="screen-reader-text">
												<?php
												printf(
													/* translators: %d: a star rating out of five */
													esc_html( _n( '%d star', '%d stars', $whd_i, 'whd' ) ),
													(int) $whd_i
												);
												?>
											</span>
										</label>
									<?php endfor; ?>
								</div>
								<p class="whd-rate__hint"><?php esc_html_e( 'Optional.', 'whd' ); ?></p>
							</fieldset>
							<label class="whd-talk__label" for="whd-story-comment"><?php esc_html_e( 'Add your note', 'whd' ); ?></label>
							<textarea class="whd-talk__field" id="whd-story-comment" name="whd_story_comment" rows="4" required
								placeholder="<?php esc_attr_e( 'What size did you take? What did you wear it with?', 'whd' ); ?>"></textarea>
							<button type="submit" class="whd-talk__submit"><?php esc_html_e( 'Post my note', 'whd' ); ?></button>
						</form>
					<?php else : ?>
						<div class="whd-talk__gate">
							<p class="whd-talk__gate-text"><?php esc_html_e( 'Notes come from people with an account, so you always know who is talking. It takes a moment.', 'whd' ); ?></p>
							<p class="whd-talk__gate-actions">
								<a class="whd-talk__submit" href="<?php echo esc_url( wp_registration_url() ); ?>"><?php esc_html_e( 'Create an account', 'whd' ); ?></a>
								<a class="whd-talk__signin" href="<?php echo esc_url( wp_login_url( get_permalink( $id ) . '#whd-talk' ) ); ?>"><?php esc_html_e( 'or sign in', 'whd' ); ?></a>
							</p>
						</div>
					<?php endif; ?>
				</div>

				<div class="whd-talk__main">
					<?php if ( $flag ) : ?>
						<p class="whd-talk__flag whd-talk__flag--<?php echo esc_attr( $flag ); ?>" role="status">
							<?php
							$messages = [
								'ok'      => __( 'Posted. Thank you for adding it.', 'whd' ),
								'pending' => __( 'Thank you — your note is waiting to be approved.', 'whd' ),
								'empty'   => __( 'Write something first and then post it.', 'whd' ),
								'login'   => __( 'You need an account to post. Sign in and try again.', 'whd' ),
								'error'   => __( 'Something went wrong saving that. Please try again.', 'whd' ),
							];
							echo esc_html( $messages[ $flag ] ?? '' );
							?>
						</p>
					<?php endif; ?>

					<?php if ( $comments ) : ?>
						<ol class="whd-talk__list">
							<?php foreach ( $comments as $c ) : ?>
								<li class="whd-talk__item">
									<div class="whd-talk__avatar" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( $c->comment_author, 0, 1 ) ) ); ?></div>
									<div class="whd-talk__body">
										<p class="whd-talk__meta">
											<strong><?php echo esc_html( $c->comment_author ); ?></strong>
											<?php $whd_score = self::rating( $c->comment_ID ); ?>
											<?php if ( $whd_score ) : ?>
												<span class="whd-talk__rating">
													<?php echo self::stars( $whd_score ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from an integer above ?>
													<span class="screen-reader-text">
														<?php
														printf(
															/* translators: %d: a star rating out of five */
															esc_html__( '%d out of 5', 'whd' ),
															(int) $whd_score
														);
														?>
													</span>
												</span>
											<?php endif; ?>
											<time datetime="<?php echo esc_attr( mysql2date( 'c', $c->comment_date ) ); ?>"><?php echo esc_html( mysql2date( get_option( 'date_format' ), $c->comment_date ) ); ?></time>
										</p>
										<div class="whd-talk__text"><?php echo wp_kses_post( wpautop( $c->comment_content ) ); ?></div>
									</div>
								</li>
							<?php endforeach; ?>
						</ol>
					<?php else : ?>
						<p class="whd-talk__empty"><?php esc_html_e( 'Nobody has written about this one yet. Yours would be the first.', 'whd' ); ?></p>
					<?php endif; ?>
				</div>

			</div>
		</section>
		<?php
	}
}
