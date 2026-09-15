<?php
/**
 * Template Name: OMC Home
 * Template Post Type: page
 *
 * Editorial storefront home page for Oops, Mine Co. Sections, top to bottom:
 * hero → trust strip → shop by category → new arrivals → the edit (editorial)
 * → most loved → brand story → styling notes (journal) → newsletter.
 *
 * Product grids come from WooCommerce's [products] shortcode so they render
 * with the parent theme's product cards, quick-view, wishlist and swatches.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

$omc_images = omc_images();
$omc_shop   = omc_shop_url();
// Product grids are scoped to one category tree (default: Women — this is a womenswear boutique).
// Change with: add_filter( 'omc_home_product_category', fn() => 'your-slug' ); return '' for all products.
$omc_cat    = apply_filters( 'omc_home_product_category', 'women' );
$omc_cat_at = $omc_cat ? ' category="' . esc_attr( $omc_cat ) . '"' : '';

get_header();
?>

<div class="omc-home">

	<!-- ───────────── Hero ───────────── -->
	<section class="omc-hero" aria-labelledby="omc-hero-title">
		<div class="omc-hero__media">
			<?php
			echo omc_image( $omc_images['hero'], 'full', [
				'class'         => 'omc-hero__img',
				'loading'       => 'eager',
				'fetchpriority' => 'high',
				'decoding'      => 'async',
				'alt'           => __( 'Two women in soft knit dresses — the Oops, Mine Co. edit', 'moderno-child' ),
			] );
			?>
		</div>
		<div class="omc-hero__content l-section__container">
			<p class="omc-eyebrow omc-eyebrow--light"><?php esc_html_e( 'Oops, Mine Co. · Est. 2023', 'moderno-child' ); ?></p>
			<h1 class="omc-hero__title" id="omc-hero-title">
				<?php esc_html_e( 'Found with intention.', 'moderno-child' ); ?><br>
				<em><?php esc_html_e( 'Claimed on instinct.', 'moderno-child' ); ?></em>
			</h1>
			<p class="omc-hero__lede"><?php esc_html_e( 'Curated Korean and Thai fashion for the pieces you weren’t looking for — and can’t leave without.', 'moderno-child' ); ?></p>
			<div class="omc-hero__actions">
				<a class="omc-btn omc-btn--solid" href="<?php echo esc_url( omc_new_arrivals_url() ); ?>"><?php esc_html_e( 'Shop new arrivals', 'moderno-child' ); ?></a>
				<a class="omc-btn omc-btn--ghost" href="<?php echo esc_url( omc_page_url( 'about-us' ) ); ?>"><?php esc_html_e( 'Our story', 'moderno-child' ); ?></a>
			</div>
		</div>
	</section>

	<!-- ───────────── Trust strip ───────────── -->
	<section class="omc-usp" aria-label="<?php esc_attr_e( 'Why shop with us', 'moderno-child' ); ?>">
		<ul class="omc-usp__list l-section__container-wide">
			<?php foreach ( omc_usp_items() as $item ) : ?>
				<li class="omc-usp__item"><?php echo omc_icon( $item['icon'] ); ?><span><?php echo esc_html( $item['text'] ); ?></span></li>
			<?php endforeach; ?>
		</ul>
	</section>

	<!-- ───────────── Banner cluster: split campaign banner + 2-up edit tiles, back to back ───────────── -->
	<?php $omc_banners = omc_home_banners(); ?>
	<?php if ( ! empty( $omc_banners['campaign'] ) ) : ?>
		<section class="omc-campaign omc-reveal" aria-label="<?php echo esc_attr( $omc_banners['campaign']['title'] ); ?>">
			<div class="l-section__container-wide">
				<?php omc_banner( $omc_banners['campaign'], 'split' ); ?>
			</div>
		</section>
	<?php endif; ?>
	<?php if ( ! empty( $omc_banners['carousel'] ) ) : ?>
		<section class="omc-carousel-section omc-reveal" aria-label="<?php esc_attr_e( 'Shop the edits', 'moderno-child' ); ?>">
			<div class="l-section__container-wide">
				<div class="omc-carousel js-omc-carousel" data-autoplay="4500">
					<button type="button" class="omc-carousel__btn omc-carousel__btn--prev" aria-label="<?php esc_attr_e( 'Previous', 'moderno-child' ); ?>"><?php echo omc_icon( 'arrow' ); ?></button>
					<div class="omc-carousel__track" tabindex="0" aria-label="<?php esc_attr_e( 'Edits — use arrow keys or swipe', 'moderno-child' ); ?>">
						<?php foreach ( $omc_banners['carousel'] as $omc_slide ) :
							$omc_slide_img = omc_image( $omc_slide['image'], 'large', [ 'loading' => 'lazy', 'alt' => $omc_slide['title'] ] );
							if ( ! $omc_slide_img ) {
								continue;
							}
							?>
							<a class="omc-carousel__item" href="<?php echo esc_url( $omc_slide['url'] ); ?>">
								<span class="omc-carousel__media"><?php echo $omc_slide_img; ?></span>
								<span class="omc-carousel__label"><?php echo esc_html( $omc_slide['title'] ); ?><?php echo omc_icon( 'arrow' ); ?></span>
							</a>
						<?php endforeach; ?>
					</div>
					<button type="button" class="omc-carousel__btn omc-carousel__btn--next" aria-label="<?php esc_attr_e( 'Next', 'moderno-child' ); ?>"><?php echo omc_icon( 'arrow' ); ?></button>
				</div>
			</div>
		</section>
	<?php endif; ?>

	<!-- ───────────── Mirrored split banner (copy left, cross-fading photos right) ───────────── -->
	<?php if ( ! empty( $omc_banners['campaign2'] ) ) : ?>
		<section class="omc-campaign omc-reveal" aria-label="<?php echo esc_attr( $omc_banners['campaign2']['title'] ); ?>">
			<div class="l-section__container-wide">
				<?php omc_banner( $omc_banners['campaign2'], 'split' ); ?>
			</div>
		</section>
	<?php endif; ?>

	<!-- ───────────── Shop by category ───────────── -->
	<?php
	$omc_terms = [];
	foreach ( omc_home_categories() as $slug ) {
		$term = get_term_by( 'slug', $slug, 'product_cat' );
		if ( $term && ! is_wp_error( $term ) ) {
			$omc_terms[] = $term;
		}
	}
	if ( $omc_terms ) :
		?>
		<section class="omc-section omc-cats omc-reveal" aria-labelledby="omc-cats-title">
			<div class="l-section__container-wide">
				<?php
				// The last word types itself out and cycles through the alternatives in data-words (assets/js/omc.js).
				$omc_type_words = apply_filters( 'omc_home_type_words', [ __( 'obsession', 'moderno-child' ), __( 'go-to', 'moderno-child' ), __( 'statement piece', 'moderno-child' ), __( 'everyday staple', 'moderno-child' ), __( '“oops, mine”', 'moderno-child' ) ] );
				omc_section_head(
					__( 'Shop by category', 'moderno-child' ),
					sprintf( __( 'Find your next %s', 'moderno-child' ), '<span class="omc-type js-omc-type" data-words="' . esc_attr( implode( '|', $omc_type_words ) ) . '">' . esc_html__( 'favourite', 'moderno-child' ) . '</span>' ),
					$omc_shop,
					__( 'View all', 'moderno-child' )
				);
				?>
				<div class="omc-cats__grid">
					<?php foreach ( $omc_terms as $term ) :
						// Prefer a real product photo from the category (the demo's category thumbnails are line-art icons);
						// fall back to the category thumbnail. Override per category with the `omc_category_tile_image` filter.
						$thumb_id = 0;
						if ( function_exists( 'wc_get_products' ) ) {
							$sample = wc_get_products( [ 'category' => [ $term->slug ], 'limit' => 1, 'status' => 'publish', 'orderby' => 'date', 'order' => 'DESC' ] );
							if ( $sample ) {
								$thumb_id = (int) $sample[0]->get_image_id();
							}
						}
						if ( ! $thumb_id ) {
							$thumb_id = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
						}
						$thumb_id = (int) apply_filters( 'omc_category_tile_image', $thumb_id, $term );
						$link     = get_term_link( $term );
						?>
						<a class="omc-cat" href="<?php echo esc_url( $link ); ?>">
							<span class="omc-cat__media">
								<?php if ( $thumb_id ) {
									echo wp_get_attachment_image( $thumb_id, 'large', false, [ 'class' => 'omc-cat__img', 'loading' => 'lazy', 'alt' => sprintf( __( 'Shop %s', 'moderno-child' ), $term->name ) ] );
								} ?>
							</span>
							<span class="omc-cat__body">
								<span class="omc-cat__name"><?php echo esc_html( $term->name ); ?></span>
								<span class="omc-cat__count"><?php echo esc_html( sprintf( _n( '%d piece', '%d pieces', $term->count, 'moderno-child' ), $term->count ) ); ?></span>
							</span>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
		</section>
	<?php endif; ?>

	<!-- ───────────── New arrivals ───────────── -->
	<section class="omc-section omc-products omc-reveal" aria-labelledby="omc-new-title">
		<div class="l-section__container-wide">
			<?php omc_section_head( __( 'Just landed', 'moderno-child' ), __( 'New arrivals', 'moderno-child' ), omc_new_arrivals_url(), __( 'Shop all new', 'moderno-child' ) ); ?>
			<div class="omc-products__grid">
				<?php echo do_shortcode( '[products limit="8" columns="4" orderby="date" order="DESC" visibility="visible"' . $omc_cat_at . ']' ); ?>
			</div>
		</div>
	</section>

	<!-- ───────────── The edit (editorial) ───────────── -->
	<section class="omc-section omc-edit omc-reveal" aria-labelledby="omc-edit-title">
		<div class="l-section__container omc-edit__grid">
			<div class="omc-edit__media">
				<figure class="omc-edit__img omc-edit__img--a">
					<?php echo omc_image( $omc_images['edit_a'], 'large', [ 'loading' => 'lazy', 'alt' => __( 'Cream knit — quiet, considered pieces', 'moderno-child' ) ] ); ?>
				</figure>
				<figure class="omc-edit__img omc-edit__img--b">
					<?php echo omc_image( $omc_images['edit_b'], 'medium_large', [ 'loading' => 'lazy', 'alt' => __( 'Black linen shirt — everyday elegance', 'moderno-child' ) ] ); ?>
				</figure>
			</div>
			<div class="omc-edit__copy">
				<p class="omc-eyebrow"><?php esc_html_e( 'The edit', 'moderno-child' ); ?></p>
				<h2 class="omc-section__title" id="omc-edit-title"><?php esc_html_e( 'Quiet elegance, from Seoul to Bangkok', 'moderno-child' ); ?></h2>
				<p><?php esc_html_e( 'We don’t bring in pieces to fill a collection or chase every trend. Each one is chosen for a reason — the fabric, the fit, a detail you don’t see everywhere — so you find something you’ll reach for again and again.', 'moderno-child' ); ?></p>
				<p><?php esc_html_e( 'Quality over quantity. Personal, never generic. Your closet should feel like you.', 'moderno-child' ); ?></p>
				<a class="omc-btn omc-btn--outline" href="<?php echo esc_url( $omc_shop ); ?>"><?php esc_html_e( 'Explore the collection', 'moderno-child' ); ?></a>
			</div>
		</div>
	</section>

	<!-- ───────────── Editor's picks (framed product feature banners) ───────────── -->
	<?php $omc_features = function_exists( 'omc_feature_products' ) ? omc_feature_products() : []; ?>
	<?php if ( count( $omc_features ) >= 2 ) : ?>
		<section class="omc-section omc-features omc-reveal" aria-labelledby="omc-features-title">
			<div class="l-section__container-wide">
				<?php omc_section_head( __( 'Editor’s picks', 'moderno-child' ), __( 'Two pieces we keep coming back to', 'moderno-child' ) ); ?>
				<div class="omc-banners__grid omc-banners__grid--2">
					<?php foreach ( array_slice( $omc_features, 0, 2 ) as $omc_fp ) : ?>
						<a class="omc-feature" href="<?php echo esc_url( $omc_fp->get_permalink() ); ?>">
							<span class="omc-feature__frame"><?php echo wp_get_attachment_image( $omc_fp->get_image_id(), 'large', false, [ 'loading' => 'lazy', 'alt' => $omc_fp->get_name() ] ); ?></span>
							<span class="omc-feature__caption">
								<span class="omc-eyebrow"><?php esc_html_e( 'Editor’s pick', 'moderno-child' ); ?></span>
								<span class="omc-feature__name"><?php echo esc_html( $omc_fp->get_name() ); ?></span>
								<span class="omc-feature__price"><?php echo wp_kses_post( $omc_fp->get_price_html() ); ?></span>
								<span class="omc-banner__cta"><?php esc_html_e( 'Shop this piece', 'moderno-child' ); ?><?php echo omc_icon( 'arrow' ); ?></span>
							</span>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
		</section>
	<?php endif; ?>

	<!-- ───────────── Most loved ───────────── -->
	<section class="omc-section omc-products omc-reveal" aria-labelledby="omc-loved-title">
		<div class="l-section__container-wide">
			<?php omc_section_head( __( 'Most loved', 'moderno-child' ), __( 'The pieces everyone keeps reaching for', 'moderno-child' ), omc_shop_url( [ 'orderby' => 'popularity' ] ), __( 'Shop best sellers', 'moderno-child' ) ); ?>
			<div class="omc-products__grid">
				<?php echo do_shortcode( '[products limit="4" columns="4" best_selling="true" visibility="visible"' . $omc_cat_at . ']' ); ?>
			</div>
		</div>
	</section>

	<!-- ───────────── Banner cluster: wide banner + 2-up edit tiles, back to back ───────────── -->
	<?php if ( ! empty( $omc_banners['wide'] ) ) : ?>
		<section class="omc-section omc-banners omc-reveal" aria-label="<?php echo esc_attr( $omc_banners['wide']['title'] ); ?>">
			<div class="l-section__container-wide">
				<?php omc_banner( $omc_banners['wide'], 'wide' ); ?>
			</div>
		</section>
	<?php endif; ?>
	<?php if ( ! empty( $omc_banners['more'] ) ) : ?>
		<section class="omc-banners omc-banners--tight omc-reveal" aria-label="<?php esc_attr_e( 'More to explore', 'moderno-child' ); ?>">
			<div class="l-section__container-wide omc-banners__grid omc-banners__grid--2">
				<?php foreach ( $omc_banners['more'] as $omc_b ) { omc_banner( $omc_b, 'tile' ); } ?>
			</div>
		</section>
	<?php endif; ?>

	<!-- ───────────── Brand story ───────────── -->
	<section class="omc-story omc-reveal" aria-labelledby="omc-story-title">
		<div class="l-section__container omc-story__inner">
			<p class="omc-eyebrow"><?php esc_html_e( 'Our story', 'moderno-child' ); ?></p>
			<h2 class="omc-story__title" id="omc-story-title"><?php esc_html_e( 'Oops. Mine.', 'moderno-child' ); ?></h2>
			<p class="omc-story__text"><?php esc_html_e( 'The name came from that wonderfully spontaneous moment when you discover something you weren’t planning to buy, but suddenly — oops, mine. That instinctive little claim is at the heart of everything we do.', 'moderno-child' ); ?></p>
			<a class="omc-btn omc-btn--ghost" href="<?php echo esc_url( omc_page_url( 'about-us' ) ); ?>"><?php esc_html_e( 'Read our story', 'moderno-child' ); ?></a>
		</div>
	</section>

	<!-- ───────────── Styling notes (journal) ───────────── -->
	<?php
	$omc_posts = get_posts( [ 'numberposts' => 3, 'post_status' => 'publish', 'ignore_sticky_posts' => true ] );
	if ( $omc_posts ) :
		?>
		<section class="omc-section omc-journal omc-reveal" aria-labelledby="omc-journal-title">
			<div class="l-section__container-wide">
				<?php omc_section_head( __( 'Styling notes', 'moderno-child' ), __( 'From the journal', 'moderno-child' ), omc_page_url( 'blog', '/blog/' ), __( 'All stories', 'moderno-child' ) ); ?>
				<div class="omc-journal__grid">
					<?php foreach ( $omc_posts as $omc_post ) : ?>
						<article class="omc-card">
							<a class="omc-card__media" href="<?php echo esc_url( get_permalink( $omc_post ) ); ?>" tabindex="-1" aria-hidden="true">
								<?php echo get_the_post_thumbnail( $omc_post, 'large', [ 'loading' => 'lazy', 'class' => 'omc-card__img' ] ); ?>
							</a>
							<div class="omc-card__body">
								<time class="omc-card__date" datetime="<?php echo esc_attr( get_the_date( 'c', $omc_post ) ); ?>"><?php echo esc_html( get_the_date( '', $omc_post ) ); ?></time>
								<h3 class="omc-card__title"><a href="<?php echo esc_url( get_permalink( $omc_post ) ); ?>"><?php echo esc_html( get_the_title( $omc_post ) ); ?></a></h3>
								<p class="omc-card__excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt( $omc_post ), 18, '…' ) ); ?></p>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			</div>
		</section>
	<?php endif; ?>

	<!-- ───────────── Newsletter ───────────── -->
	<section class="omc-newsletter omc-reveal" aria-labelledby="omc-news-title">
		<div class="l-section__container omc-newsletter__inner">
			<p class="omc-eyebrow"><?php esc_html_e( 'Stay close', 'moderno-child' ); ?></p>
			<h2 class="omc-section__title" id="omc-news-title"><?php esc_html_e( 'Your next “Oops, Mine” moment', 'moderno-child' ); ?></h2>
			<p class="omc-newsletter__text"><?php esc_html_e( 'New arrivals, quiet restocks and the occasional note from us. No noise.', 'moderno-child' ); ?></p>
			<?php omc_newsletter_form(); ?>
		</div>
	</section>

</div>

<?php
get_footer();
