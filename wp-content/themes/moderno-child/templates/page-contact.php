<?php
/**
 * Template Name: OMC Contact
 * Template Post Type: page
 *
 * Title band → contact card → the page's sections ("Before you write" and
 * friends) → FAQ → closing call to action.
 *
 * There is no message form. The boutique is one person answering one inbox, and a
 * form that quietly drops into the same inbox only adds a step and a place for the
 * message to go missing. The card links straight to hello@oopsmineco.com.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'omc_landing_data' ) ) {
	// inc/landing.php is not loaded (functions.php must require it): show the
	// plain HTML the publish script stored rather than failing.
	get_template_part( 'singular' );
	return;
}

get_header();

while ( have_posts() ) :
	the_post();

	$omc_id   = get_the_ID();
	$omc_data = omc_landing_data( $omc_id );
	?>

	<article <?php post_class( 'omc-lp omc-lp--contact' ); ?>>

		<!-- ───────────── Title band ───────────── -->
		<header class="omc-lp-head">
			<div class="l-section__container omc-lp-head__inner">
				<p class="omc-eyebrow"><?php echo esc_html( $omc_data['hero']['eyebrow'] ?: __( 'Say hello', 'moderno-child' ) ); ?></p>
				<h1 class="omc-lp-head__title"><?php the_title(); ?></h1>
				<?php if ( $omc_data['hero']['intro'] ) : ?>
					<p class="omc-lp-head__intro"><?php echo wp_kses( $omc_data['hero']['intro'], omc_landing_allowed_inline() ); ?></p>
				<?php endif; ?>
			</div>
		</header>

		<?php if ( ! omc_landing_has_data( $omc_id ) ) : ?>
			<div class="omc-prose omc-lp-prose l-section__container">
				<?php the_content(); ?>
			</div>
		<?php endif; ?>

		<!-- ───────────── How to reach us ───────────── -->
		<div class="l-section__container omc-contact__grid omc-contact__grid--single omc-reveal">
			<?php omc_contact_card(); ?>
		</div>

		<!-- ───────────── Sections ("Before you write", shipping notes…) ───────────── -->
		<?php omc_landing_render_sections( $omc_data['sections'], [ 'layout' => 'bands', 'alternate' => true, 'faqs' => true ] ); ?>

		<!-- ───────────── FAQ ───────────── -->
		<?php if ( $omc_data['faqs'] ) : ?>
			<section class="omc-lp-section omc-lp-faq omc-reveal" id="faq" data-omc-anchor aria-labelledby="omc-contact-faq-title">
				<div class="l-section__container omc-lp-section__inner">
					<p class="omc-eyebrow"><?php esc_html_e( 'Before you write', 'moderno-child' ); ?></p>
					<h2 class="omc-lp-section__title" id="omc-contact-faq-title"><?php echo wp_kses( __( 'The quick <em>answers</em>', 'moderno-child' ), [ 'em' => [] ] ); ?></h2>
					<?php omc_landing_faq( $omc_data['faqs'] ); ?>
				</div>
			</section>
		<?php endif; ?>

		<!-- ───────────── Related pages + closing call to action ───────────── -->
		<?php omc_landing_related( $omc_data['related_slugs'] ); ?>
		<?php omc_landing_cta( $omc_data['cta'], $omc_data['slug'] ); ?>

	</article>

<?php
endwhile;

get_footer();
