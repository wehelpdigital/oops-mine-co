<?php
/**
 * Template Name: OMC Videos
 * Template Post Type: page
 *
 * The video hub: every style video with its poster, length and a line about what is in it.
 * ItemList + VideoObject structured data is emitted from inc/video.php.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

get_header();

$omc_videos = function_exists( 'omc_video_pages' ) ? omc_video_pages() : [];
$omc_hub    = function_exists( 'omc_video_data' ) ? omc_video_data() : null;
$omc_intro  = $omc_hub && ! empty( $omc_hub['intro'] ) ? $omc_hub['intro'] : get_post_meta( get_the_ID(), '_omc_intro', true );
?>

<div class="omc-videos">

	<section class="omc-videos__hero">
		<div class="l-section__container">
			<p class="omc-eyebrow"><?php esc_html_e( 'Style videos', 'moderno-child' ); ?></p>
			<h1 class="omc-videos__title"><?php the_title(); ?></h1>
			<?php if ( $omc_intro ) : ?>
				<p class="omc-videos__lede"><?php echo wp_kses_post( $omc_intro ); ?></p>
			<?php endif; ?>
		</div>
	</section>

	<?php if ( $omc_videos ) : ?>
		<section class="omc-section omc-videos__list omc-reveal">
			<div class="l-section__container-wide">
				<div class="omc-video-cards omc-video-cards--hub">
					<?php
					foreach ( $omc_videos as $omc_pid => $omc_v ) {
						omc_video_card( $omc_pid, $omc_v );
					}
					?>
				</div>
			</div>
		</section>
	<?php endif; ?>

	<?php
	$omc_content = trim( get_the_content() );
	if ( $omc_content ) :
		?>
		<section class="omc-section">
			<div class="l-section__container omc-videos__prose">
				<?php
				while ( have_posts() ) {
					the_post();
					the_content();
				}
				?>
			</div>
		</section>
	<?php endif; ?>

	<section class="omc-section omc-videos__cta omc-reveal">
		<div class="l-section__container omc-videos__cta-inner">
			<p class="omc-eyebrow"><?php esc_html_e( 'Find something yours', 'moderno-child' ); ?></p>
			<h2 class="omc-section__title"><?php echo wp_kses( __( 'Everything you just watched, <em>in the shop</em>', 'moderno-child' ), [ 'em' => [] ] ); ?></h2>
			<p><?php esc_html_e( 'Small batches, one or two of each size. If a piece caught your eye, it is worth a look now.', 'moderno-child' ); ?></p>
			<div class="omc-videos__cta-buttons">
				<a class="omc-btn omc-btn--solid" href="<?php echo esc_url( add_query_arg( 'orderby', 'date', omc_shop_url() ) ); ?>"><?php esc_html_e( 'Shop new arrivals', 'moderno-child' ); ?></a>
				<a class="omc-btn omc-btn--outline" href="<?php echo esc_url( omc_category_url( 'skirts' ) ); ?>"><?php esc_html_e( 'Shop skirts', 'moderno-child' ); ?></a>
			</div>
		</div>
	</section>

	<section class="omc-newsletter omc-reveal" aria-labelledby="omc-videos-news">
		<?php if ( ! empty( omc_images()['newsletter'] ) ) : ?>
			<div class="omc-newsletter__bg" aria-hidden="true"><?php echo omc_image( omc_images()['newsletter'], 'large', [ 'loading' => 'lazy', 'alt' => '' ] ); ?></div>
		<?php endif; ?>
		<div class="l-section__container omc-newsletter__inner">
			<p class="omc-eyebrow"><?php esc_html_e( 'Stay close', 'moderno-child' ); ?></p>
			<h2 class="omc-section__title" id="omc-videos-news"><?php echo wp_kses( __( 'First look at every <em>new rack</em>', 'moderno-child' ), [ 'em' => [] ] ); ?></h2>
			<p class="omc-newsletter__text"><?php esc_html_e( 'We film what lands before it goes online. Join the list and watch it first.', 'moderno-child' ); ?></p>
			<?php omc_newsletter_form( [ 'source' => 'videos-hub' ] ); ?>
		</div>
	</section>

</div>

<?php
get_footer();
