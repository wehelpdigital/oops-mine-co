<?php
/**
 * Template Name: OMC Landing
 * Template Post Type: page
 *
 * Keyword landing page (Korean fashion, petite dresses…). The words come from
 * the content document in the post meta `_omc_landing` (see inc/landing.php);
 * the template supplies the frame:
 *
 *   hero → product grid for the page's category → sections → FAQ → related → CTA
 *
 * When the meta is missing the page falls back to the_content(), so the plain
 * HTML the publish script stores is always readable.
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

	// The category grid: only when the document names a category that has products.
	$omc_term = $omc_data['category']['slug'] ? get_term_by( 'slug', $omc_data['category']['slug'], 'product_cat' ) : null;
	$omc_term = ( $omc_term && ! is_wp_error( $omc_term ) ) ? $omc_term : null;
	$omc_grid = '';
	if ( $omc_term && $omc_term->count > 0 && shortcode_exists( 'products' ) ) {
		$omc_grid  = do_shortcode( '[products limit="8" columns="4" category="' . esc_attr( $omc_term->slug ) . '" orderby="date" order="DESC" visibility="visible"]' );
		$omc_empty = false !== strpos( $omc_grid, 'woocommerce-info' ) || false === strpos( $omc_grid, 'product' );
		if ( $omc_empty ) {
			$omc_grid = ''; // no products: hide the whole section rather than show an empty grid
		}
	}
	$omc_cat_name = $omc_data['category']['name'] ?: ( $omc_term ? $omc_term->name : '' );
	$omc_cat_url  = $omc_term ? get_term_link( $omc_term ) : '';
	$omc_cat_url  = ( $omc_cat_url && ! is_wp_error( $omc_cat_url ) ) ? $omc_cat_url : '';

	// The hero's own button often points at the same category (writers use the
	// short /product-category/<slug>/ form): then the second button is dropped.
	$omc_leaf      = static function ( $url ) {
		return basename( untrailingslashit( (string) wp_parse_url( (string) $url, PHP_URL_PATH ) ) );
	};
	$omc_show_more = $omc_cat_url && $omc_leaf( $omc_cat_url ) !== $omc_leaf( $omc_data['hero']['cta_url'] );
	?>

	<article <?php post_class( 'omc-lp omc-lp--landing' ); ?>>

		<!-- ───────────── Hero ───────────── -->
		<header class="omc-lp-hero<?php echo has_post_thumbnail() ? ' omc-lp-hero--photo' : ''; ?>">
			<?php if ( has_post_thumbnail() ) : ?>
				<div class="omc-lp-hero__bg" aria-hidden="true"><?php the_post_thumbnail( 'large', [ 'loading' => 'eager', 'fetchpriority' => 'high', 'alt' => '' ] ); ?></div>
			<?php endif; ?>
			<div class="l-section__container omc-lp-hero__inner">
				<?php if ( $omc_data['hero']['eyebrow'] ) : ?>
					<p class="omc-eyebrow"><?php echo esc_html( $omc_data['hero']['eyebrow'] ); ?></p>
				<?php endif; ?>
				<h1 class="omc-lp-hero__title"><?php the_title(); ?></h1>
				<?php if ( $omc_data['hero']['intro'] ) : ?>
					<p class="omc-lp-hero__intro"><?php echo wp_kses( $omc_data['hero']['intro'], omc_landing_allowed_inline() ); ?></p>
				<?php endif; ?>
				<?php if ( $omc_data['hero']['cta_text'] && $omc_data['hero']['cta_url'] ) : ?>
					<p class="omc-lp-hero__actions">
						<a class="omc-btn omc-btn--solid" href="<?php echo esc_url( $omc_data['hero']['cta_url'] ); ?>"><?php echo esc_html( $omc_data['hero']['cta_text'] ); ?></a>
						<?php if ( $omc_show_more ) : ?>
							<a class="omc-btn omc-btn--outline" href="<?php echo esc_url( $omc_cat_url ); ?>"><?php echo esc_html( $omc_cat_name ? sprintf( __( 'Shop all %s', 'moderno-child' ), $omc_cat_name ) : __( 'Shop all', 'moderno-child' ) ); ?></a>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</div>
		</header>

		<?php if ( ! omc_landing_has_data( $omc_id ) ) : ?>
			<!-- No content document yet: render whatever the editor holds. -->
			<div class="omc-prose omc-lp-prose l-section__container">
				<?php the_content(); ?>
			</div>
		<?php endif; ?>

		<!-- ───────────── The category's pieces ───────────── -->
		<?php if ( $omc_grid ) : ?>
			<section class="omc-section omc-products omc-lp-products omc-reveal" aria-labelledby="omc-lp-products-title">
				<div class="l-section__container-wide">
					<header class="omc-section__head">
						<div>
							<p class="omc-eyebrow"><?php esc_html_e( 'Shop the edit', 'moderno-child' ); ?></p>
							<h2 class="omc-section__title" id="omc-lp-products-title">
								<?php
								echo $omc_cat_name
									? wp_kses( sprintf( __( 'Pieces from <em>%s</em>', 'moderno-child' ), esc_html( $omc_cat_name ) ), [ 'em' => [] ] )
									: wp_kses( __( 'Pieces we <em>love</em>', 'moderno-child' ), [ 'em' => [] ] );
								?>
							</h2>
						</div>
						<?php if ( $omc_cat_url ) : ?>
							<a class="omc-link" href="<?php echo esc_url( $omc_cat_url ); ?>"><?php echo esc_html( $omc_cat_name ? sprintf( __( 'Shop all %s', 'moderno-child' ), $omc_cat_name ) : __( 'Shop all', 'moderno-child' ) ); ?><?php echo omc_icon( 'arrow' ); ?></a>
						<?php endif; ?>
					</header>
					<?php if ( $omc_data['category']['description'] ) : ?>
						<p class="omc-lp-products__intro"><?php echo wp_kses( $omc_data['category']['description'], omc_landing_allowed_inline() ); ?></p>
					<?php endif; ?>
					<div class="omc-products__grid"><?php echo $omc_grid; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — WooCommerce shortcode output ?></div>
					<?php if ( $omc_cat_url ) : ?>
						<p class="omc-lp-products__more"><a class="omc-btn omc-btn--outline" href="<?php echo esc_url( $omc_cat_url ); ?>"><?php echo esc_html( $omc_cat_name ? sprintf( __( 'Shop all %s', 'moderno-child' ), $omc_cat_name ) : __( 'Shop all', 'moderno-child' ) ); ?></a></p>
					<?php endif; ?>
				</div>
			</section>
		<?php endif; ?>

		<!-- ───────────── Sections ───────────── -->
		<?php omc_landing_render_sections( $omc_data['sections'], [ 'layout' => 'bands', 'alternate' => true, 'faqs' => true ] ); ?>

		<!-- ───────────── FAQ ───────────── -->
		<?php if ( $omc_data['faqs'] ) : ?>
			<section class="omc-lp-section omc-lp-faq omc-reveal" id="faq" data-omc-anchor aria-labelledby="omc-lp-faq-title">
				<div class="l-section__container omc-lp-section__inner">
					<p class="omc-eyebrow"><?php esc_html_e( 'Good to know', 'moderno-child' ); ?></p>
					<h2 class="omc-lp-section__title" id="omc-lp-faq-title"><?php echo wp_kses( __( 'Questions, <em>answered</em>', 'moderno-child' ), [ 'em' => [] ] ); ?></h2>
					<?php omc_landing_faq( $omc_data['faqs'], [ 'open_first' => true ] ); ?>
				</div>
			</section>
		<?php endif; ?>

		<!-- ───────────── Related pages ───────────── -->
		<?php omc_landing_related( $omc_data['related_slugs'] ); ?>

		<!-- ───────────── Closing call to action ───────────── -->
		<?php omc_landing_cta( $omc_data['cta'], $omc_data['slug'] ); ?>

	</article>

<?php
endwhile;

get_footer();
