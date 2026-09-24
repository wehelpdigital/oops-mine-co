<?php
/**
 * Template Name: OMC FAQ
 * Template Post Type: page
 *
 * Questions and answers from the content document (`_omc_landing`):
 *
 *   title band → intro copy → sticky in-page nav + grouped accordions → CTA
 *
 * Groups come from either shape the writers may use: a flat `faqs` list (each
 * item may carry a "group"), or `sections` that each hold their own `faqs`.
 * The FAQPage JSON-LD is printed in wp_head by inc/landing.php.
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

	// Sections that carry questions become groups; the rest stay as intro copy.
	$omc_intro  = [];
	$omc_groups = [];
	foreach ( $omc_data['sections'] as $omc_section ) {
		if ( ! empty( $omc_section['faqs'] ) ) {
			$omc_groups[] = [
				'id'         => $omc_section['id'],
				'heading'    => $omc_section['heading'],
				'paragraphs' => $omc_section['paragraphs'],
				'bullets'    => $omc_section['bullets'],
				'table'      => $omc_section['table'],
				'faqs'       => $omc_section['faqs'],
			];
		} else {
			$omc_intro[] = $omc_section;
		}
	}

	// The top-level list, split on its optional "group" key.
	foreach ( omc_landing_faq_groups( $omc_data['faqs'], $omc_groups ? '' : __( 'Frequently asked questions', 'moderno-child' ) ) as $omc_group ) {
		$omc_groups[] = [
			'id'         => $omc_group['id'] ?: 'faq',
			'heading'    => $omc_group['heading'],
			'paragraphs' => [],
			'bullets'    => [],
			'table'      => [],
			'faqs'       => $omc_group['faqs'],
		];
	}

	$omc_nav = [];
	foreach ( $omc_groups as $omc_group ) {
		if ( $omc_group['heading'] ) {
			$omc_nav[] = [ 'id' => $omc_group['id'], 'label' => omc_landing_plain( $omc_group['heading'] ) ];
		}
	}
	?>

	<article <?php post_class( 'omc-lp omc-lp--faq' ); ?>>

		<!-- ───────────── Title band ───────────── -->
		<header class="omc-lp-head">
			<div class="l-section__container omc-lp-head__inner">
				<p class="omc-eyebrow"><?php echo esc_html( $omc_data['hero']['eyebrow'] ?: __( 'Help centre', 'moderno-child' ) ); ?></p>
				<h1 class="omc-lp-head__title"><?php the_title(); ?></h1>
				<?php if ( $omc_data['hero']['intro'] ) : ?>
					<p class="omc-lp-head__intro"><?php echo wp_kses( $omc_data['hero']['intro'], omc_landing_allowed_inline() ); ?></p>
				<?php endif; ?>
				<?php if ( $omc_data['hero']['cta_text'] && $omc_data['hero']['cta_url'] ) : ?>
					<p class="omc-lp-head__actions"><a class="omc-btn omc-btn--solid" href="<?php echo esc_url( $omc_data['hero']['cta_url'] ); ?>"><?php echo esc_html( $omc_data['hero']['cta_text'] ); ?></a></p>
				<?php endif; ?>
			</div>
		</header>

		<?php if ( ! omc_landing_has_data( $omc_id ) ) : ?>
			<div class="omc-prose omc-lp-prose l-section__container">
				<?php the_content(); ?>
			</div>
		<?php endif; ?>

		<?php if ( $omc_intro ) : ?>
			<div class="omc-lp-intro l-section__container">
				<?php omc_landing_render_sections( $omc_intro, [ 'layout' => 'plain', 'anchors' => false, 'reveal' => false ] ); ?>
			</div>
		<?php endif; ?>

		<?php if ( $omc_groups ) : ?>
			<div class="l-section__container omc-lp-cols">

				<?php omc_landing_nav( $omc_nav, [ 'title' => __( 'On this page', 'moderno-child' ), 'label' => __( 'Question groups', 'moderno-child' ) ] ); ?>

				<div class="omc-lp-cols__main">
					<?php foreach ( $omc_groups as $omc_i => $omc_group ) : ?>
						<section class="omc-faq-group omc-reveal" id="<?php echo esc_attr( $omc_group['id'] ); ?>" data-omc-anchor>
							<?php if ( $omc_group['heading'] ) : ?>
								<h2 class="omc-lp-section__title"><?php echo wp_kses( $omc_group['heading'], [ 'em' => [], 'i' => [], 'br' => [] ] ); ?></h2>
							<?php endif; ?>
							<?php if ( $omc_group['paragraphs'] || $omc_group['bullets'] || $omc_group['table'] ) : ?>
								<div class="omc-lp-prose">
									<?php foreach ( $omc_group['paragraphs'] as $omc_p ) : ?>
										<p><?php echo wp_kses( $omc_p, omc_landing_allowed_inline() ); ?></p>
									<?php endforeach; ?>
									<?php if ( $omc_group['bullets'] ) : ?>
										<ul class="omc-lp-list">
											<?php foreach ( $omc_group['bullets'] as $omc_b ) : ?>
												<li><?php echo wp_kses( $omc_b, omc_landing_allowed_inline() ); ?></li>
											<?php endforeach; ?>
										</ul>
									<?php endif; ?>
									<?php omc_landing_table( $omc_group['table'] ); ?>
								</div>
							<?php endif; ?>
							<?php omc_landing_faq( $omc_group['faqs'], [ 'open_first' => 0 === $omc_i ] ); ?>
						</section>
					<?php endforeach; ?>

					<p class="omc-lp-cols__foot">
						<?php
						printf(
							/* translators: %s: link to the contact page. */
							esc_html__( 'Still looking for an answer? %s and we will help.', 'moderno-child' ),
							'<a href="' . esc_url( omc_page_url( 'contacts', '/contacts/' ) ) . '">' . esc_html__( 'write to us', 'moderno-child' ) . '</a>'
						);
						?>
					</p>
				</div>
			</div>
		<?php endif; ?>

		<!-- ───────────── Related pages + closing call to action ───────────── -->
		<?php omc_landing_related( $omc_data['related_slugs'] ); ?>
		<?php omc_landing_cta( $omc_data['cta'], $omc_data['slug'] ); ?>

	</article>

<?php
endwhile;

get_footer();
