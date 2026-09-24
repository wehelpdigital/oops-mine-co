<?php
/**
 * Template Name: OMC Policy
 * Template Post Type: page
 *
 * Long-form pages read from the content document: returns, shipping, privacy,
 * terms and the size guide (whose sections may carry a `table`).
 *
 *   title band (+ "Last updated") → sticky in-page nav + the text → FAQ →
 *   a small help card → related pages
 *
 * Client-supplied legal text is printed as written — the template only frames it.
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
	// Lifts "Last updated …" out of the sections so the line never prints twice.
	$omc_updated = omc_landing_updated( $omc_data );

	$omc_nav = [];
	foreach ( $omc_data['sections'] as $omc_section ) {
		if ( $omc_section['heading'] ) {
			$omc_nav[] = [ 'id' => $omc_section['id'], 'label' => omc_landing_plain( $omc_section['heading'] ) ];
		}
	}
	if ( $omc_data['faqs'] ) {
		$omc_nav[] = [ 'id' => 'faq', 'label' => __( 'Questions', 'moderno-child' ) ];
	}
	?>

	<article <?php post_class( 'omc-lp omc-lp--policy' ); ?>>

		<!-- ───────────── Title band ───────────── -->
		<header class="omc-lp-head omc-lp-head--policy">
			<div class="l-section__container omc-lp-head__inner">
				<p class="omc-eyebrow"><?php echo esc_html( $omc_data['hero']['eyebrow'] ?: __( 'Good to know', 'moderno-child' ) ); ?></p>
				<h1 class="omc-lp-head__title"><?php the_title(); ?></h1>
				<?php if ( $omc_data['hero']['intro'] ) : ?>
					<p class="omc-lp-head__intro"><?php echo wp_kses( $omc_data['hero']['intro'], omc_landing_allowed_inline() ); ?></p>
				<?php endif; ?>
				<?php if ( $omc_updated ) : ?>
					<p class="omc-lp-head__updated"><?php echo esc_html( sprintf( __( 'Last updated %s', 'moderno-child' ), $omc_updated ) ); ?></p>
				<?php endif; ?>
			</div>
		</header>

		<?php if ( ! omc_landing_has_data( $omc_id ) ) : ?>
			<div class="omc-prose omc-lp-prose l-section__container">
				<?php the_content(); ?>
			</div>
		<?php endif; ?>

		<?php if ( $omc_data['sections'] || $omc_data['faqs'] ) : ?>
			<div class="l-section__container omc-lp-cols omc-lp-cols--policy">

				<?php omc_landing_nav( $omc_nav, [ 'title' => __( 'On this page', 'moderno-child' ), 'label' => __( 'Sections of this page', 'moderno-child' ) ] ); ?>

				<div class="omc-lp-cols__main omc-lp-doc">

					<?php omc_landing_render_sections( $omc_data['sections'], [ 'layout' => 'plain', 'faqs' => true ] ); ?>

					<?php if ( $omc_data['faqs'] ) : ?>
						<section class="omc-lp-block omc-reveal" id="faq" data-omc-anchor>
							<h2 class="omc-lp-section__title"><?php esc_html_e( 'Questions', 'moderno-child' ); ?></h2>
							<?php omc_landing_faq( $omc_data['faqs'] ); ?>
						</section>
					<?php endif; ?>

					<?php omc_contact_help_card(); ?>

				</div>
			</div>
		<?php endif; ?>

		<!-- ───────────── Related pages + closing call to action ───────────── -->
		<?php omc_landing_related( $omc_data['related_slugs'] ); ?>
		<?php
		// Legal pages stay quiet unless the document asks for a band of its own.
		if ( $omc_data['cta']['heading'] || $omc_data['cta']['text'] || $omc_data['cta']['button_text'] ) {
			omc_landing_cta( $omc_data['cta'], $omc_data['slug'] );
		}
		?>

	</article>

<?php
endwhile;

get_footer();
