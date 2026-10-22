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
 * Everything here can be changed in the Page Editor (inc/page-editor.php), which wins over the
 * page's own title, tagline, picture and content without touching them.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();
	$omc_tagline = (string) omc_pe( 'about', 'hero.tagline', get_post_meta( get_the_ID(), 'omc_tagline', true ) );
	$omc_heading = (string) omc_pe( 'about', 'hero.heading', get_the_title() );
	$omc_picked  = (int) omc_pe_value( 'about', 'hero.image' );
	$omc_hero_at = [ 'class' => 'omc-about__img', 'loading' => 'eager', 'fetchpriority' => 'high' ];
	if ( $omc_picked ) {
		$omc_hero = wp_get_attachment_image( $omc_picked, 'full', false, $omc_hero_at + [ 'alt' => omc_image_alt( $omc_picked, $omc_heading ) ] );
	} else {
		$omc_hero = has_post_thumbnail()
			? get_the_post_thumbnail( null, 'full', $omc_hero_at + [ 'alt' => get_the_title() ] )
			: omc_image( omc_images()['about_hero'], 'full', $omc_hero_at + [ 'alt' => __( 'Editorial portrait — Oops, Mine Co.', 'moderno-child' ) ] );
	}
	$omc_body = omc_pe_value( 'about', 'body' );
	$omc_btn1 = omc_pe_link( 'about', 'cta.button1' );
	$omc_btn2 = omc_pe_link( 'about', 'cta.button2' );
	?>

	<article <?php post_class( 'omc-about' ); ?>>

		<section class="omc-about__hero" aria-labelledby="omc-about-title">
			<div class="omc-about__media"><?php echo $omc_hero; ?></div>
			<div class="omc-about__content l-section__container">
				<p class="omc-eyebrow omc-eyebrow--light"><?php echo esc_html( omc_pe_copy( 'about', 'hero.eyebrow' ) ); ?></p>
				<h1 class="omc-about__title" id="omc-about-title"><?php echo esc_html( $omc_heading ); ?></h1>
				<?php if ( $omc_tagline ) : ?>
					<p class="omc-about__tagline"><?php echo esc_html( $omc_tagline ); ?></p>
				<?php endif; ?>
			</div>
		</section>

		<div class="omc-prose l-section__container">
			<?php
			if ( null !== $omc_body ) {
				echo apply_filters( 'the_content', $omc_body ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- post HTML, kses'd when saved
			} else {
				the_content();
			}
			?>
		</div>

		<section class="omc-about__cta omc-reveal" aria-label="<?php esc_attr_e( 'Start shopping', 'moderno-child' ); ?>">
			<div class="l-section__container omc-about__cta-inner">
				<p class="omc-eyebrow"><?php echo esc_html( omc_pe_copy( 'about', 'cta.eyebrow' ) ); ?></p>
				<h2 class="omc-section__title"><?php echo omc_pe_em( omc_pe_copy( 'about', 'cta.title' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped, then *x* → <em>x</em> ?></h2>
				<div class="omc-hero__actions">
					<a class="omc-btn omc-btn--solid" href="<?php echo esc_url( $omc_btn1['url'] ); ?>"><?php echo esc_html( $omc_btn1['text'] ); ?></a>
					<a class="omc-btn omc-btn--outline" href="<?php echo esc_url( $omc_btn2['url'] ); ?>"><?php echo esc_html( $omc_btn2['text'] ); ?></a>
				</div>
			</div>
		</section>

	</article>

<?php
endwhile;

get_footer();
