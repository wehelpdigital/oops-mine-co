<?php
/**
 * Template Name: OMC Home
 * Template Post Type: page
 *
 * Editorial storefront home page for Oops, Mine Co. Sections, top to bottom:
 * hero → trust strip → split campaign banner → edit carousel → mirrored campaign banner
 * → shop by category → brand story → new arrivals → two wide banners → most loved
 * → styling notes (journal) → newsletter.
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
		<?php $omc_hero_imgs = omc_hero_images(); ?>
		<div class="omc-hero__media<?php echo count( $omc_hero_imgs ) > 1 ? ' omc-hero__media--fade js-omc-fade' : ''; ?>" data-fade="6000" data-fade-hover="no">
			<?php
			foreach ( $omc_hero_imgs as $omc_i => $omc_hero_img ) {
				echo omc_image( $omc_hero_img, 'full', 0 === $omc_i ? [
					'class'         => 'omc-hero__img is-active',
					'loading'       => 'eager',
					'fetchpriority' => 'high',
					'decoding'      => 'async',
					'alt'           => __( 'Two women in soft knit dresses — the Oops, Mine Co. edit', 'moderno-child' ),
				] : [
					'class'       => 'omc-hero__img',
					'loading'     => 'lazy',
					'decoding'    => 'async',
					'alt'         => '',
					'aria-hidden' => 'true',
				] );
			}
			?>
		</div>
		<div class="omc-hero__content l-section__container">
			<p class="omc-eyebrow omc-eyebrow--light"><?php esc_html_e( 'Oops, Mine Co. · Est. 2023', 'moderno-child' ); ?></p>
			<?php $omc_phrases = omc_hero_phrases(); ?>
			<h1 class="omc-hero__title" id="omc-hero-title">
				<?php esc_html_e( 'Found with intention.', 'moderno-child' ); ?><br>
				<em class="omc-rotate js-omc-rotate" data-phrases="<?php echo esc_attr( implode( '|', array_slice( $omc_phrases, 1 ) ) ); ?>" data-delay="3200"><?php echo esc_html( $omc_phrases[0] ); ?></em>
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

	<!-- ───────────── Next Facebook Live (Customizer → Facebook Live) ───────────── -->
	<?php $omc_live = omc_fb_live(); ?>
	<?php if ( ! empty( $omc_live['enabled'] ) && ! empty( $omc_live['when'] ) ) : ?>
		<section class="omc-live omc-reveal" aria-labelledby="omc-live-title">
			<div class="l-section__container-wide">
				<div class="omc-live__card js-omc-countdown" data-until="<?php echo esc_attr( $omc_live['when']->format( DATE_ATOM ) ); ?>" data-live-window="7200">
					<?php if ( ! empty( $omc_images['live'] ) ) : ?>
						<div class="omc-live__media"><?php echo omc_image( $omc_images['live'], 'large', [ 'loading' => 'lazy', 'alt' => '' ] ); ?></div>
					<?php endif; ?>
					<div class="omc-live__body">
						<p class="omc-live__badge"><span class="omc-live__dot" aria-hidden="true"></span><?php esc_html_e( 'Live on Facebook', 'moderno-child' ); ?></p>
						<h2 class="omc-live__title" id="omc-live-title"><?php echo esc_html( $omc_live['title'] ); ?></h2>
						<p class="omc-live__text"><?php echo esc_html( $omc_live['text'] ); ?></p>
						<div class="omc-live__count" role="timer" aria-label="<?php esc_attr_e( 'Time until we go live', 'moderno-child' ); ?>">
							<?php foreach ( [ 'd' => __( 'Days', 'moderno-child' ), 'h' => __( 'Hours', 'moderno-child' ), 'm' => __( 'Min', 'moderno-child' ), 's' => __( 'Sec', 'moderno-child' ) ] as $omc_u => $omc_lbl ) : ?>
								<span class="omc-live__cell"><span class="omc-live__num" data-unit="<?php echo esc_attr( $omc_u ); ?>">00</span><span class="omc-live__unit"><?php echo esc_html( $omc_lbl ); ?></span></span>
							<?php endforeach; ?>
						</div>
						<p class="omc-live__status omc-live__status--now"><?php esc_html_e( 'We’re live right now — come in.', 'moderno-child' ); ?></p>
						<p class="omc-live__status omc-live__status--over"><?php esc_html_e( 'That one’s wrapped. Leave your email for the next date.', 'moderno-child' ); ?></p>
						<?php
						omc_newsletter_form( [
							'class'       => 'omc-live__form',
							'placeholder' => __( 'Your email address', 'moderno-child' ),
							'button'      => $omc_live['button'],
							'source'      => 'fb_live',
							'after'       => '#omc-live-after',
						] );
						?>
						<div class="omc-live__after" id="omc-live-after" hidden>
							<a class="omc-btn omc-btn--solid omc-live__btn" href="<?php echo esc_url( $omc_live['url'] ); ?>" target="_blank" rel="noopener"><?php echo omc_icon( 'facebook' ); ?><?php esc_html_e( 'Open the live on Facebook', 'moderno-child' ); ?></a>
						</div>
						<p class="omc-live__foot">
							<time datetime="<?php echo esc_attr( $omc_live['when']->format( DATE_ATOM ) ); ?>"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' · ' . get_option( 'time_format' ), $omc_live['when']->getTimestamp() ) ); ?></time>
						</p>
					</div>
				</div>
			</div>
		</section>
	<?php endif; ?>

	<!-- ───────────── Shop-the-look mosaic (fixed balanced pattern; omc_home_mosaic()) ───────────── -->
	<?php $omc_tiles = omc_home_mosaic(); ?>
	<?php if ( count( $omc_tiles ) >= 4 ) : ?>
		<section class="omc-section omc-mosaic-section omc-reveal" aria-labelledby="omc-mosaic-title">
			<div class="l-section__container-wide">
				<?php omc_section_head( __( 'Shop the look', 'moderno-child' ), __( 'Pieces we can’t stop <em>styling</em>', 'moderno-child' ), $omc_shop, __( 'Shop all', 'moderno-child' ) ); ?>
				<div class="omc-mosaic">
					<?php foreach ( $omc_tiles as $omc_t ) :
						$omc_t   = wp_parse_args( $omc_t, [ 'image' => '', 'label' => '', 'url' => $omc_shop, 'size' => '1x1', 'focus' => '' ] );
						$omc_img = omc_image( $omc_t['image'], 'large', array_filter( [ 'loading' => 'lazy', 'alt' => $omc_t['label'], 'style' => $omc_t['focus'] ? 'object-position:' . $omc_t['focus'] : '' ] ) );
						if ( ! $omc_img ) {
							continue;
						}
						?>
						<a class="omc-mosaic__item omc-mosaic__item--<?php echo esc_attr( preg_replace( '/[^0-9x]/', '', $omc_t['size'] ) ?: '1x1' ); ?>" href="<?php echo esc_url( $omc_t['url'] ); ?>">
							<?php echo $omc_img; ?>
							<?php if ( $omc_t['label'] ) : ?>
								<span class="omc-mosaic__label"><?php echo esc_html( $omc_t['label'] ); ?><?php echo omc_icon( 'arrow' ); ?></span>
							<?php endif; ?>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
		</section>
	<?php endif; ?>

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

	<!-- ───────────── Brand story ───────────── -->
	<section class="omc-story omc-reveal" aria-labelledby="omc-story-title">
		<?php if ( ! empty( $omc_images['story'] ) ) : ?>
			<div class="omc-story__bg" aria-hidden="true"><?php echo omc_image( $omc_images['story'], 'large', [ 'loading' => 'lazy', 'alt' => '' ] ); ?></div>
		<?php endif; ?>
		<div class="l-section__container omc-story__inner">
			<p class="omc-eyebrow"><?php esc_html_e( 'Our story', 'moderno-child' ); ?></p>
			<h2 class="omc-story__title" id="omc-story-title"><?php esc_html_e( 'Oops. Mine.', 'moderno-child' ); ?></h2>
			<p class="omc-story__text"><?php esc_html_e( 'The name came from that wonderfully spontaneous moment when you discover something you weren’t planning to buy, but suddenly — oops, mine. That instinctive little claim is at the heart of everything we do.', 'moderno-child' ); ?></p>
			<a class="omc-btn omc-btn--ghost" href="<?php echo esc_url( omc_page_url( 'about-us' ) ); ?>"><?php esc_html_e( 'Read our story', 'moderno-child' ); ?></a>
		</div>
	</section>

	<!-- ───────────── New arrivals ───────────── -->
	<section class="omc-section omc-products omc-reveal" aria-labelledby="omc-new-title">
		<div class="l-section__container-wide">
			<?php omc_section_head( __( 'Just landed', 'moderno-child' ), __( 'New <em>arrivals</em>', 'moderno-child' ), omc_new_arrivals_url(), __( 'Shop all new', 'moderno-child' ) ); ?>
			<div class="omc-products__grid">
				<?php echo do_shortcode( '[products limit="8" columns="4" orderby="date" order="DESC" visibility="visible"' . $omc_cat_at . ']' ); ?>
			</div>
		</div>
	</section>

	<!-- ───────────── Wide banners, back to back: copy left, then copy right ───────────── -->
	<?php if ( ! empty( $omc_banners['wide'] ) ) : ?>
		<section class="omc-section omc-banners omc-reveal" aria-label="<?php echo esc_attr( $omc_banners['wide']['title'] ); ?>">
			<div class="l-section__container-wide">
				<?php omc_banner( $omc_banners['wide'], 'wide' ); ?>
			</div>
		</section>
	<?php endif; ?>
	<?php if ( ! empty( $omc_banners['wide2'] ) ) : ?>
		<section class="omc-banners omc-banners--tight omc-reveal" aria-label="<?php echo esc_attr( $omc_banners['wide2']['title'] ); ?>">
			<div class="l-section__container-wide">
				<?php omc_banner( $omc_banners['wide2'], 'wide' ); ?>
			</div>
		</section>
	<?php endif; ?>
	<?php if ( ! empty( $omc_banners['wide3'] ) ) : ?>
		<section class="omc-banners omc-banners--tight omc-reveal" aria-label="<?php echo esc_attr( $omc_banners['wide3']['title'] ); ?>">
			<div class="l-section__container-wide">
				<?php omc_banner( $omc_banners['wide3'], 'wide' ); ?>
			</div>
		</section>
	<?php endif; ?>

	<!-- ───────────── Most loved ───────────── -->
	<section class="omc-section omc-products omc-reveal" aria-labelledby="omc-loved-title">
		<div class="l-section__container-wide">
			<?php omc_section_head( __( 'Most loved', 'moderno-child' ), __( 'The pieces everyone keeps <em>reaching for</em>', 'moderno-child' ), omc_shop_url( [ 'orderby' => 'popularity' ] ), __( 'Shop best sellers', 'moderno-child' ) ); ?>
			<div class="omc-products__grid">
				<?php echo do_shortcode( '[products limit="4" columns="4" best_selling="true" visibility="visible"' . $omc_cat_at . ']' ); ?>
			</div>
		</div>
	</section>

	<!-- ───────────── Styling notes (journal) ───────────── -->
	<?php
	$omc_posts = get_posts( [ 'numberposts' => 3, 'post_status' => 'publish', 'ignore_sticky_posts' => true ] );
	if ( $omc_posts ) :
		?>
		<section class="omc-section omc-journal omc-reveal" aria-labelledby="omc-journal-title">
			<div class="l-section__container-wide">
				<?php omc_section_head( __( 'Styling notes', 'moderno-child' ), __( 'From the <em>journal</em>', 'moderno-child' ), omc_page_url( 'blog', '/blog/' ), __( 'All stories', 'moderno-child' ) ); ?>
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

	<!-- ───────────── Testimonials: WooCommerce reviews + curated quotes, in the edit carousel ───────────── -->
	<?php $omc_quotes = omc_testimonials(); ?>
	<?php if ( count( $omc_quotes ) >= 3 ) : ?>
		<section class="omc-section omc-quotes omc-reveal" aria-labelledby="omc-quotes-title">
			<div class="omc-quotes__band js-omc-quotes" data-autoplay="7000" aria-roledescription="carousel" aria-label="<?php esc_attr_e( 'Customer reviews', 'moderno-child' ); ?>">
				<?php if ( ! empty( $omc_images['quotes'] ) ) : ?>
					<div class="omc-quotes__bg" aria-hidden="true"><?php echo omc_image( $omc_images['quotes'], 'large', [ 'loading' => 'lazy', 'alt' => '' ] ); ?></div>
				<?php endif; ?>
				<div class="l-section__container omc-quotes__inner">
					<div class="omc-quotes__head">
						<p class="omc-eyebrow"><?php esc_html_e( 'Kind words', 'moderno-child' ); ?></p>
						<h2 class="omc-section__title" id="omc-quotes-title"><?php echo wp_kses( __( 'What they’re <em>saying</em>', 'moderno-child' ), [ 'em' => [] ] ); ?></h2>
					</div>
					<div class="omc-quotes__slides">
						<?php foreach ( $omc_quotes as $omc_i => $omc_q ) :
							$omc_is_review = 'review' === ( $omc_q['source'] ?? '' ) && ! empty( $omc_q['product']['url'] );
							?>
							<article class="omc-quotes__slide<?php echo 0 === $omc_i ? ' is-active' : ''; ?>"<?php echo 0 === $omc_i ? '' : ' aria-hidden="true"'; ?> aria-roledescription="slide" aria-label="<?php echo esc_attr( sprintf( __( 'Review %1$d of %2$d', 'moderno-child' ), $omc_i + 1, count( $omc_quotes ) ) ); ?>">
								<div class="omc-quotes__body">
									<span class="omc-quotes__stars" role="img" aria-label="<?php echo esc_attr( sprintf( __( '%d out of 5 stars', 'moderno-child' ), (int) $omc_q['rating'] ) ); ?>"><?php echo str_repeat( omc_icon( 'star' ), max( 1, min( 5, (int) $omc_q['rating'] ) ) ); ?></span>
									<blockquote class="omc-quotes__text"><p><?php echo esc_html( $omc_q['text'] ); ?></p></blockquote>
									<footer class="omc-quotes__by">
										<span class="omc-quotes__name"><?php echo esc_html( $omc_q['author'] ); ?></span>
										<span class="omc-quotes__meta">
											<?php if ( $omc_is_review ) : ?>
												<?php esc_html_e( 'Verified buyer', 'moderno-child' ); ?> · <a href="<?php echo esc_url( $omc_q['product']['url'] ); ?>"><?php echo esc_html( $omc_q['product']['name'] ); ?></a>
											<?php else : ?>
												<?php esc_html_e( 'Oops, Mine Co. customer', 'moderno-child' ); ?>
											<?php endif; ?>
										</span>
									</footer>
								</div>
							</article>
						<?php endforeach; ?>
					</div>
					<div class="omc-quotes__nav">
						<button type="button" class="omc-quotes__btn omc-quotes__btn--prev" aria-label="<?php esc_attr_e( 'Previous review', 'moderno-child' ); ?>"><?php echo omc_icon( 'arrow' ); ?></button>
						<span class="omc-quotes__count" aria-live="polite"><b>01</b><span aria-hidden="true"> / </span><?php echo esc_html( str_pad( (string) count( $omc_quotes ), 2, '0', STR_PAD_LEFT ) ); ?></span>
						<button type="button" class="omc-quotes__btn omc-quotes__btn--next" aria-label="<?php esc_attr_e( 'Next review', 'moderno-child' ); ?>"><?php echo omc_icon( 'arrow' ); ?></button>
					</div>
				</div>
			</div>
		</section>
	<?php endif; ?>

	<!-- ───────────── Newsletter ───────────── -->
	<section class="omc-newsletter omc-reveal" aria-labelledby="omc-news-title">
		<?php if ( ! empty( $omc_images['newsletter'] ) ) : ?>
			<div class="omc-newsletter__bg" aria-hidden="true"><?php echo omc_image( $omc_images['newsletter'], 'large', [ 'loading' => 'lazy', 'alt' => '' ] ); ?></div>
		<?php endif; ?>
		<div class="l-section__container omc-newsletter__inner">
			<p class="omc-eyebrow"><?php esc_html_e( 'Stay close', 'moderno-child' ); ?></p>
			<h2 class="omc-section__title" id="omc-news-title"><?php echo wp_kses( __( 'Your next <em>“Oops, Mine”</em> moment', 'moderno-child' ), [ 'em' => [] ] ); ?></h2>
			<p class="omc-newsletter__text"><?php esc_html_e( 'New arrivals, quiet restocks and the occasional note from us. No noise.', 'moderno-child' ); ?></p>
			<?php omc_newsletter_form(); ?>
		</div>
	</section>

</div>

<?php
get_footer();
