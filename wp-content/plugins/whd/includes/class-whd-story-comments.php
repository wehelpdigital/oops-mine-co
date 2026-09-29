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

	public static function init() {
		// Just after the story, which itself sits at 15.
		add_action( 'woocommerce_after_single_product_summary', [ __CLASS__, 'render' ], 16 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );

		add_action( 'init', [ __CLASS__, 'handle_post' ] );

		// Keep these out of the review count and the star average.
		add_filter( 'comments_clauses', [ __CLASS__, 'exclude_from_reviews' ], 10, 2 );
		add_filter( 'woocommerce_product_get_review_count', [ __CLASS__, 'review_count' ], 10, 2 );
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

	/** The product's review count, with story comments taken out. */
	public static function review_count( $count, $product ) {
		$ours = (int) get_comments( [
			'post_id' => $product->get_id(),
			'type'    => self::TYPE,
			'status'  => 'approve',
			'count'   => true,
		] );
		return max( 0, (int) $count - $ours );
	}

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

		$flag = $id ? ( get_option( 'comment_moderation' ) ? 'pending' : 'ok' ) : 'error';
		wp_safe_redirect( add_query_arg( 'whd_comment', $flag, get_permalink( $post_id ) ) . '#whd-talk' );
		exit;
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
					<p class="whd-talk__lede"><?php esc_html_e( 'How people styled it, what size they took, what they would tell someone thinking about it.', 'whd' ); ?></p>

					<?php if ( is_user_logged_in() ) : ?>
						<form class="whd-talk__form" method="post" action="<?php echo esc_url( get_permalink( $id ) ); ?>#whd-talk">
							<?php wp_nonce_field( 'whd_story_comment_' . $id, 'whd_story_comment_nonce' ); ?>
							<input type="hidden" name="whd_story_post_id" value="<?php echo esc_attr( $id ); ?>">
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
