<?php
/*
* If the current post is protected by a password and the visitor has not yet
* entered the password we will return early without loading the comments.
*/
if ( post_password_required() ) {
	return;
}
?>

<?php if ( comments_open() || have_comments() ) { ?>
	<div class="c-post__comments-wrap">
		<div id="comments" class="c-post__comments">
			<div
				class="c-post__comments-row<?php ideapark_class( have_comments(), 'c-post__comments-row--comments', 'c-post__comments-row--no-comments' ); ?>">
				<?php if ( have_comments() ) { ?>
					<div>
						<div class="comments-title">
							<?php
							printf( esc_html( _n( '1 Comment', '%1$s Comments', get_comments_number(), 'moderno' ) ),
								number_format_i18n( get_comments_number() ), get_the_title() );
							?>
						</div>
						<ol class="commentlist">
							<?php
							wp_list_comments( [
								'avatar_size' => 60,
								'max_depth'   => 5,
								'callback'    => 'ideapark_html5_comment',
								'type'        => 'all',
								'style'       => 'ol',
								'short_ping'  => true,
								'format'      => 'html5',
							] );
							?>
						</ol><!-- .comments-list -->
					</div>

					<?php if ( get_comment_pages_count() > 1 && get_option( 'page_comments' ) ) : ?>
						<nav id="comments-nav-below" class="comments-navigation" role="navigation">
							<div
								class="nav-previous"><?php previous_comments_link( '<span class="meta-nav"><i class="ip-menu-left"></i></span>' . esc_html__( 'Older Comments', 'moderno' ) ); ?></div>
							<div
								class="nav-next"><?php next_comments_link( esc_html__( 'Newer Comments', 'moderno' ) . '<span class="meta-nav"><i class="ip-menu-right"></i></span>' ); ?></div>
						</nav><!-- #comments-nav-below -->
					<?php endif; // Check for comment navigation. ?>
				<?php }; // have_comments() ?>

				<?php comment_form([
					'title_reply'          => __( 'Post a Comment', 'moderno' ),
					'submit_button'        => '<button type="submit" name="%1$s" id="%2$s" class="%3$s">%4$s</button>',
				]); ?>

			</div>

		</div><!-- #comments -->
	</div><!-- #comments-wrap -->
<?php } ?>
