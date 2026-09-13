<?php
/**
 * @var $is_product    bool
 * @var $is_page       bool
 * @var $format        string
 * @var $video_url     string
 * @var $image_gallery array
 * @var $has_thumb     bool
 */

$with_sidebar = ! empty( $ideapark_var['with_sidebar'] );
extract( ideapark_post_params() );
if ( is_page() ) {
	$meta = '';
} else {
	ob_start(); ?>
	<?php if ( ! ideapark_mod( 'post_hide_author' ) ) { ?>
		<li class="c-page__meta-item">
			<span class="c-page__meta-by"><?php esc_html_e( 'by', 'moderno' ); ?></span>
			<?php the_author_link(); ?>
		</li>
	<?php } ?>

	<?php if ( ! ideapark_mod( 'post_hide_date' ) ) { ?>
		<li class="c-page__meta-item">
			<?php the_time( get_option( 'date_format' ) ); ?>
		</li>
	<?php } ?>

	<?php if ( ! ideapark_mod( 'post_hide_category' ) ) { ?>
		<li class="c-page__meta-item">
			<?php ideapark_category( ', ', null, 'c-page__categories-item-link' ); ?>
		</li>
	<?php } ?>

	<?php if ( ! ideapark_mod( 'post_hide_comment' ) ) { ?>
		<?php $comments_count = wp_count_comments( $post->ID ); ?>
		<?php if ( $comments_count->total_comments > 0 ) { ?>
			<li class="c-page__meta-item">
				<?php esc_html_e( 'Comments:', 'moderno' ); ?><?php echo esc_html( $comments_count->total_comments ); ?>
			</li>
		<?php } ?>
	<?php } ?>
	<?php $meta = ob_get_clean();
}
?>

<article
	id="post-<?php the_ID(); ?>" <?php post_class( 'c-post c-post--' . esc_attr( $format ) . ( $with_sidebar ? ' c-post--sidebar' : ' c-post--no-sidebar' ) . ' c-post--' . ( is_page() ? 'page' : 'post' ) ); ?>>
	<div
		class="c-post__wrap <?php ideapark_class(is_page() , 'c-post__wrap--page', 'c-post__wrap--post' ); ?> <?php ideapark_class( $has_thumb, 'c-post__wrap--thumb', 'c-post__wrap--no-thumb' ); ?> <?php ideapark_class( $with_sidebar, 'c-post__wrap--sidebar', 'c-post__wrap--no-sidebar' ); ?>">
		<?php if ( $has_thumb && $format != 'standard' ) { ?>
			<div
				class="c-post__thumb c-post__thumb--<?php echo esc_attr( $format ); ?> <?php ideapark_class( has_post_thumbnail(), 'c-post__thumb--with-image', 'c-post__thumb--no-image' ); ?>">
				<?php if ( $format == 'gallery' && $image_gallery ) { ?>
					<div
						class="c-post__carousel-list js-post-image-carousel h-carousel h-carousel--inner h-carousel--hover h-carousel--dots-hide h-carousel--round">
						<?php $index = 0; ?>
						<?php foreach ( $image_gallery as $image_id ) {
							$image_wrap_open  = '<span class="c-post__carousel-span"></span>';
							$image_wrap_close = '';

							if ( $image_meta = ideapark_image_meta( $image_id ) ) {
								$image_code = ideapark_img( $image_meta, 'c-post__carousel-img', false );
							} else {
								$image_code = '';
							}

							echo sprintf( '<div class="c-post__carousel-item">%s%s%s</div>', $image_wrap_open, $image_code, $image_wrap_close );
						} ?>
					</div>

				<?php } elseif ( $format == 'video' ) { ?>
					<div class="c-post__thumb-inner">
						<a href="<?php echo esc_url( $video_url ); ?>" class="js-video c-post__video"
						   onclick="return false;"
						   data-autoplay="true"
						   data-vbtype="video">
							<?php if ( has_post_thumbnail() ) { ?>
								<?php the_post_thumbnail( 'large', [ 'class' => 'c-post__video-img' ] ); ?>
							<?php } else {
								$pattern = '%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/ ]{11})%i';
								if ( preg_match( $pattern, $video_url, $match ) ) {
									$image_url = 'https://img.youtube.com/vi/' . $match[1] . '/maxresdefault.jpg';
									echo '<img class="c-post-list__img" src="' . esc_url( $image_url ) . '" ' . ( ideapark_mod( 'lazyload' ) ? 'loading="lazy"' : '' ) . ' alt="' . esc_attr( get_the_title() ) . '">';
								}
							} ?>

							<i class="c-play c-play--disabled"></i>
						</a>
					</div>
				<?php } elseif ( $format == 'image' && has_post_thumbnail() ) { ?>
					<?php the_post_thumbnail( 'large', [ 'class' => 'c-post__img' ] ); ?>
				<?php } ?>
			</div>
		<?php } ?>
		<?php
		add_filter( 'embed_defaults', function ( $embed_size ) {
			$embed_size['width']  = 700;
			$embed_size['height'] = 394;

			return $embed_size;
		} );
		?>
		<div class="c-post__inner">
			<?php echo ideapark_wrap( $meta, '<ul class="c-page__meta' . ( $with_sidebar ? ' c-page__meta--sidebar' : ' c-page__meta--no-sidebar' ) . '">', '</ul>' ); ?>
			<div
				class="c-post__content h-clearfix <?php if ( ! ideapark_is_elementor_page() ) { ?>entry-content <?php ideapark_class( ideapark_mod( 'sidebar_post' ), 'entry-content--sidebar', 'entry-content--fullwidth' ); ?><?php } ?>">
				<?php the_content( '<span class="c-post__more-button">' . esc_html__( 'Continue Reading', 'moderno' ) . '</span>' ); ?>
			</div>

			<?php wp_link_pages( [
				'before'           => '<div class="c-post__page-links"><ul class="post-page-numbers"><li>',
				'after'            => '</li></ul></div>',
				'separator'        => '</li><li>',
				'nextpagelink'     => ideapark_pagination_prev(),
				'previouspagelink' => ideapark_pagination_next(),
			] ); ?>

			<?php if ( ! $is_page && ( ! ideapark_mod( 'post_hide_share' ) || ! ideapark_mod( 'post_hide_tags' ) ) ) { ?>

				<?php ob_start(); ?>
				<?php if ( ! ideapark_mod( 'post_hide_tags' ) && has_tag() ) { ?>
					<div class="c-post__tags">
						<div class="c-post__tags-title"><?php esc_html_e( 'Tags', 'moderno' ); ?>:</div>
						<?php the_tags( '', ',&nbsp; ' ); ?>
					</div>
				<?php } ?>
				<?php if ( ! ideapark_mod( 'post_hide_share' ) && shortcode_exists( 'ip-post-share' )  && ( $content = ideapark_shortcode( '[ip-post-share]' ) )) { ?>
					<div class="c-product__share">
						<i class="ip-share c-product__share-icon"></i>
						<div class="c-product__share-title"><?php esc_html_e( 'Share', 'moderno' ); ?></div>
						<?php echo ideapark_wrap( $content ); ?>
					</div>
				<?php } ?>
				<?php $content = trim( ob_get_clean() ); ?>
				<?php echo ideapark_wrap( $content, '<div class="c-post__bottom">', '</div>' ); ?>
			<?php } ?>
		</div>
	</div>
	<div class="c-post__row-2">
		<?php if ( is_single() && ! ideapark_mod( 'post_hide_postnav' ) ) { ?>
			<?php ideapark_post_nav(); ?>
		<?php } ?>
		<?php if ( is_single() && ! ideapark_mod( 'post_hide_author' ) ) { ?>
			<?php get_template_part( 'templates/post-author' ); ?>
		<?php } ?>
		<?php comments_template( '', true ); ?>
	</div>
</article>

