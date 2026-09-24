<?php
/**
 * Template Name: OMC Video
 * Template Post Type: page
 *
 * A single style video: the player and its chapters on one side, the written version of the video on
 * the other, then questions, shop links and the rest of the videos. The written part matters — it is
 * what search engines read, and what a visitor with the sound off reads.
 *
 * Data comes from post meta `_omc_video` (see .ftp-sync/content/videos/*.json); structured data and
 * helpers live in inc/video.php.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

get_header();

$omc_v = function_exists( 'omc_video_data' ) ? omc_video_data() : null;

if ( ! $omc_v ) {
	echo '<div class="l-section l-section--container"><div class="l-section__content">';
	while ( have_posts() ) {
		the_post();
		the_content();
	}
	echo '</div></div>';
	get_footer();
	return;
}

$omc_hub = get_page_by_path( 'videos' );
?>

<div class="omc-video">

	<section class="omc-video__top">
		<div class="l-section__container-wide omc-video__grid">

			<div class="omc-video__media">
				<?php omc_video_player( $omc_v ); ?>
			</div>

			<div class="omc-video__intro">
				<p class="omc-eyebrow">
					<?php if ( $omc_hub ) : ?>
						<a href="<?php echo esc_url( get_permalink( $omc_hub ) ); ?>"><?php esc_html_e( 'Style videos', 'moderno-child' ); ?></a>
					<?php else : ?>
						<?php esc_html_e( 'Style videos', 'moderno-child' ); ?>
					<?php endif; ?>
				</p>
				<h1 class="omc-video__title"><?php echo esc_html( $omc_v['title'] ); ?></h1>
				<?php if ( $omc_v['intro'] ) : ?>
					<p class="omc-video__lede"><?php echo wp_kses_post( $omc_v['intro'] ); ?></p>
				<?php endif; ?>
				<?php omc_video_shop_links( $omc_v['shop'] ); ?>
				<?php omc_video_chapters( $omc_v ); ?>
			</div>

		</div>
	</section>

	<section class="omc-section omc-video__body">
		<div class="l-section__container omc-video__prose">
			<?php
			omc_video_sections( $omc_v['sections'] );
			omc_video_faqs( $omc_v['faqs'] );
			?>
		</div>
	</section>

	<?php
	$omc_more = [];
	foreach ( omc_video_pages() as $omc_pid => $omc_other ) {
		if ( $omc_pid !== get_the_ID() ) {
			$omc_more[ $omc_pid ] = $omc_other;
		}
	}
	if ( $omc_more ) :
		?>
		<section class="omc-section omc-video__more omc-reveal" aria-labelledby="omc-video-more">
			<div class="l-section__container-wide">
				<?php
				omc_section_head(
					__( 'Keep watching', 'moderno-child' ),
					__( 'More from the <em>rack</em>', 'moderno-child' ),
					$omc_hub ? get_permalink( $omc_hub ) : '',
					$omc_hub ? __( 'All videos', 'moderno-child' ) : ''
				);
				?>
				<div class="omc-video-cards">
					<?php
					foreach ( array_slice( $omc_more, 0, 3, true ) as $omc_pid => $omc_other ) {
						omc_video_card( $omc_pid, $omc_other );
					}
					?>
				</div>
			</div>
		</section>
	<?php endif; ?>

	<section class="omc-newsletter omc-reveal" aria-labelledby="omc-video-news">
		<?php if ( ! empty( omc_images()['newsletter'] ) ) : ?>
			<div class="omc-newsletter__bg" aria-hidden="true"><?php echo omc_image( omc_images()['newsletter'], 'large', [ 'loading' => 'lazy', 'alt' => '' ] ); ?></div>
		<?php endif; ?>
		<div class="l-section__container omc-newsletter__inner">
			<p class="omc-eyebrow"><?php esc_html_e( 'Stay close', 'moderno-child' ); ?></p>
			<h2 class="omc-section__title" id="omc-video-news"><?php echo wp_kses( __( 'See it before it <em>sells through</em>', 'moderno-child' ), [ 'em' => [] ] ); ?></h2>
			<p class="omc-newsletter__text"><?php esc_html_e( 'New pieces land in small batches. Join the list and you will see the rack before it goes up.', 'moderno-child' ); ?></p>
			<?php omc_newsletter_form( [ 'source' => 'video:' . sanitize_key( $omc_v['slug'] ) ] ); ?>
		</div>
	</section>

</div>

<?php
get_footer();
