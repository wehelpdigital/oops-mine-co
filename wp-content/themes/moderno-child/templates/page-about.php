<?php
/**
 * Template Name: OMC About
 * Template Post Type: page
 *
 * Brand story page. The template provides the frame — full-bleed hero, the
 * prose column with editorial typography, and a closing call to action —
 * while the page content (edited in WordPress) provides the words.
 *
 * Hero image: the page's featured image, or omc_images()['about_hero'].
 * Sub-title: custom field `omc_tagline` (optional).
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();
	$omc_tagline = get_post_meta( get_the_ID(), 'omc_tagline', true );
	$omc_hero    = has_post_thumbnail()
		? get_the_post_thumbnail( null, 'full', [ 'class' => 'omc-about__img', 'loading' => 'eager', 'fetchpriority' => 'high', 'alt' => get_the_title() ] )
		: omc_image( omc_images()['about_hero'], 'full', [ 'class' => 'omc-about__img', 'loading' => 'eager', 'fetchpriority' => 'high', 'alt' => __( 'Editorial portrait — Oops, Mine Co.', 'moderno-child' ) ] );
	?>

	<article <?php post_class( 'omc-about' ); ?>>

		<section class="omc-about__hero" aria-labelledby="omc-about-title">
			<div class="omc-about__media"><?php echo $omc_hero; ?></div>
			<div class="omc-about__content l-section__container">
				<p class="omc-eyebrow omc-eyebrow--light"><?php esc_html_e( 'About Oops, Mine Co.', 'moderno-child' ); ?></p>
				<h1 class="omc-about__title" id="omc-about-title"><?php the_title(); ?></h1>
				<?php if ( $omc_tagline ) : ?>
					<p class="omc-about__tagline"><?php echo esc_html( $omc_tagline ); ?></p>
				<?php endif; ?>
			</div>
		</section>

		<div class="omc-prose l-section__container">
			<?php the_content(); ?>
		</div>

		<section class="omc-about__cta omc-reveal" aria-label="<?php esc_attr_e( 'Start shopping', 'moderno-child' ); ?>">
			<div class="l-section__container omc-about__cta-inner">
				<p class="omc-eyebrow"><?php esc_html_e( 'When you know, you know', 'moderno-child' ); ?></p>
				<h2 class="omc-section__title"><?php echo wp_kses( __( 'Find something <em>special</em>', 'moderno-child' ), [ 'em' => [] ] ); ?></h2>
				<div class="omc-hero__actions">
					<a class="omc-btn omc-btn--solid" href="<?php echo esc_url( omc_new_arrivals_url() ); ?>"><?php esc_html_e( 'Shop new arrivals', 'moderno-child' ); ?></a>
					<a class="omc-btn omc-btn--outline" href="<?php echo esc_url( omc_page_url( 'contacts', '/contacts/' ) ); ?>"><?php esc_html_e( 'Get in touch', 'moderno-child' ); ?></a>
				</div>
			</div>
		</section>

	</article>

<?php
endwhile;

get_footer();
