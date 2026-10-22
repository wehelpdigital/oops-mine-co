<?php
/**
 * "Watch & shop": the style videos one at a time on the home page, under the Facebook Live card.
 *
 * A dark band, like a small cinema: one video per slide — swipe (or the arrows) for the next — with
 * the written version beside it: its title, a few lines from its page, the pieces in it and a link
 * to the full video page. Press play and each piece slides in over the video at the moment it is on
 * screen, as on the video page itself (the moments and their seconds are Page Editor → Shoppable
 * videos); its row beside the video lights up at the same time. Only one video plays at a time, and
 * a video that is swiped away stops.
 *
 * Words are the Page Editor's (home → "Watch & shop"); the videos are every published style video,
 * in the hub's order. assets/js/omc-reel.js does the playing and the pop-ups; the pop-up's look is
 * the video page's own (.omc-vpop in omc-video.css, which the home page already loads).
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

/** What a slide needs, for every published video with a file. */
function omc_reel_videos() {
	$out = [];
	foreach ( omc_video_pages() as $pid => $v ) {
		$src = omc_video_src( $v );
		if ( ! $src ) {
			continue;
		}
		list( $moments, $pieces ) = omc_video_shop_moments( $v );
		$out[] = [
			'page'    => (int) $pid,
			'v'       => $v,
			'src'     => $src,
			'poster'  => omc_video_poster( $v, 'large' ),
			'url'     => (string) get_permalink( $pid ),
			'excerpt' => wp_trim_words( wp_strip_all_tags( (string) ( $v['intro'] ?: $v['meta_description'] ) ), 30, '…' ),
			'moments' => $moments,
			'pieces'  => $pieces,
		];
	}
	return $out;
}

function omc_reel_section() {
	if ( ! function_exists( 'omc_pe' ) || ! omc_pe( 'home', 'reel.enabled', true ) ) {
		return;
	}
	$videos = omc_reel_videos();
	if ( ! $videos ) {
		return;
	}
	$hub   = omc_page_url( 'videos', '/videos/' );
	$total = count( $videos );
	?>
	<section class="omc-reel omc-reveal" aria-labelledby="omc-reel-title" data-omc-reel data-label-now="<?php esc_attr_e( 'On screen now', 'moderno-child' ); ?>">
		<div class="l-section__container-wide">
			<div class="omc-reel__head">
				<div class="omc-reel__heading">
					<p class="omc-eyebrow"><?php echo esc_html( omc_pe_copy( 'home', 'reel.eyebrow' ) ); ?></p>
					<h2 class="omc-section__title" id="omc-reel-title"><?php echo omc_pe_em( omc_pe_copy( 'home', 'reel.title' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped, then *x* → <em>x</em> ?></h2>
					<p class="omc-reel__lede"><?php echo esc_html( omc_pe_copy( 'home', 'reel.text' ) ); ?></p>
				</div>
				<div class="omc-reel__nav">
					<?php if ( $total > 1 ) : ?>
						<button type="button" class="omc-reel__arrow" data-reel="prev" aria-label="<?php esc_attr_e( 'Previous video', 'moderno-child' ); ?>"><?php echo omc_icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
						<p class="omc-reel__count" aria-live="polite"><span class="omc-reel__now">01</span> / <?php echo esc_html( str_pad( (string) $total, 2, '0', STR_PAD_LEFT ) ); ?></p>
						<button type="button" class="omc-reel__arrow omc-reel__arrow--next" data-reel="next" aria-label="<?php esc_attr_e( 'Next video', 'moderno-child' ); ?>"><?php echo omc_icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
					<?php endif; ?>
					<?php if ( $hub ) : ?>
						<a class="omc-link omc-reel__all" href="<?php echo esc_url( $hub ); ?>"><?php esc_html_e( 'All videos', 'moderno-child' ); ?></a>
					<?php endif; ?>
				</div>
			</div>

			<div class="omc-reel__track" tabindex="0" role="region" aria-roledescription="<?php esc_attr_e( 'carousel', 'moderno-child' ); ?>" aria-label="<?php esc_attr_e( 'Style videos — swipe, or use the arrow keys', 'moderno-child' ); ?>">
				<?php foreach ( $videos as $i => $s ) : ?>
					<?php
					$v     = $s['v'];
					$ratio = ( ! empty( $v['width'] ) && ! empty( $v['height'] ) ) ? (int) $v['width'] . ' / ' . (int) $v['height'] : '9 / 16';
					?>
					<article class="omc-reel__slide<?php echo 0 === $i ? ' is-current' : ''; ?>" data-index="<?php echo (int) $i; ?>" aria-roledescription="<?php esc_attr_e( 'slide', 'moderno-child' ); ?>"
						aria-label="<?php echo esc_attr( sprintf( /* translators: 1: number, 2: total */ __( '%1$d of %2$d', 'moderno-child' ), $i + 1, $total ) ); ?>">
						<div class="omc-reel__frame" style="--omc-video-ratio:<?php echo esc_attr( $ratio ); ?>">
							<video class="omc-reel__video" playsinline preload="none"<?php echo $s['poster'] ? ' poster="' . esc_url( $s['poster'] ) . '"' : ''; ?>
								aria-label="<?php echo esc_attr( $v['alt'] ?: $v['title'] ); ?>">
								<source src="<?php echo esc_url( $s['src'] ); ?>" type="video/mp4">
							</video>
							<button type="button" class="omc-reel__play" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: video title */ __( 'Play: %s', 'moderno-child' ), html_entity_decode( (string) $v['title'], ENT_QUOTES, 'UTF-8' ) ) ); ?>">
								<svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true"><path d="M8 5.5v13l11-6.5z" fill="currentColor"/></svg>
							</button>
							<?php if ( ! empty( $v['duration'] ) ) : ?>
								<span class="omc-reel__time"><?php echo esc_html( omc_video_clock( $v['duration'] ) ); ?></span>
							<?php endif; ?>
							<?php if ( $s['moments'] ) : ?>
								<span class="omc-reel__badge">
									<?php
									$n = count( array_unique( wp_list_pluck( $s['moments'], 'product' ) ) );
									/* translators: %d: number of pieces */
									echo esc_html( sprintf( _n( '%d piece to shop', '%d pieces to shop', $n, 'moderno-child' ), $n ) );
									?>
								</span>
							<?php endif; ?>
						</div>

						<div class="omc-reel__info">
							<p class="omc-reel__kicker">
								<?php
								/* translators: 1: number, 2: total */
								echo esc_html( sprintf( __( 'Video %1$d of %2$d', 'moderno-child' ), $i + 1, $total ) );
								?>
							</p>
							<h3 class="omc-reel__title"><a href="<?php echo esc_url( $s['url'] ); ?>"><?php echo esc_html( $v['title'] ); ?></a></h3>
							<?php if ( $s['excerpt'] ) : ?>
								<p class="omc-reel__excerpt"><?php echo esc_html( $s['excerpt'] ); ?></p>
							<?php endif; ?>

							<?php if ( $s['moments'] ) : ?>
								<ol class="omc-reel__pieces" aria-label="<?php esc_attr_e( 'In this video', 'moderno-child' ); ?>">
									<?php foreach ( $s['moments'] as $m ) : ?>
										<?php $piece = $s['pieces'][ $m['product'] ]; ?>
										<li class="omc-reel__piece" data-at="<?php echo (int) $m['at']; ?>" data-for="<?php echo (int) $m['for']; ?>" data-piece="<?php echo (int) $m['product']; ?>">
											<a class="omc-reel__piece-media" href="<?php echo esc_url( $piece['url'] ); ?>" tabindex="-1" aria-hidden="true"><img src="<?php echo esc_url( $piece['image'] ); ?>" alt="" loading="lazy" decoding="async"></a>
											<span class="omc-reel__piece-body">
												<a class="omc-reel__piece-name" href="<?php echo esc_url( $piece['url'] ); ?>"><?php echo esc_html( $piece['name'] ); ?></a>
												<span class="omc-reel__piece-price"><?php echo esc_html( $piece['price'] ); ?></span>
											</span>
											<button type="button" class="omc-reel__seek" data-at="<?php echo (int) $m['at']; ?>"
												aria-label="<?php echo esc_attr( sprintf( /* translators: %s: a time in the video, e.g. 0:12 */ __( 'Play from %s', 'moderno-child' ), omc_video_clock( $m['at'] ) ) ); ?>">
												<svg viewBox="0 0 24 24" width="12" height="12" aria-hidden="true"><path d="M8 5.5v13l11-6.5z" fill="currentColor"/></svg>
												<?php echo esc_html( omc_video_clock( $m['at'] ) ); ?>
											</button>
										</li>
									<?php endforeach; ?>
								</ol>
							<?php endif; ?>

							<div class="omc-reel__actions">
								<a class="omc-btn omc-btn--light" href="<?php echo esc_url( $s['url'] ); ?>"><?php esc_html_e( 'Watch the full video', 'moderno-child' ); ?></a>
								<?php $ids = array_values( array_unique( wp_list_pluck( $s['moments'], 'product' ) ) ); ?>
								<?php if ( count( $ids ) > 1 ) : ?>
									<button type="button" class="omc-btn omc-btn--ghost" data-outfit="<?php echo esc_attr( implode( ',', $ids ) ); ?>">
										<?php
										/* translators: %d: number of pieces */
										echo esc_html( sprintf( __( 'Shop all %d', 'moderno-child' ), count( $ids ) ) );
										?>
									</button>
								<?php endif; ?>
							</div>
						</div>
					</article>
				<?php endforeach; ?>
				<?php if ( $hub ) : ?>
					<a class="omc-reel__end" href="<?php echo esc_url( $hub ); ?>">
						<span class="omc-reel__end-k"><?php esc_html_e( 'Keep watching', 'moderno-child' ); ?></span>
						<span class="omc-reel__end-title"><?php esc_html_e( 'Every style video', 'moderno-child' ); ?></span>
						<span class="omc-reel__end-go" aria-hidden="true"><?php echo omc_icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</a>
				<?php endif; ?>
			</div>

			<?php if ( $total > 1 ) : ?>
				<div class="omc-reel__dots" role="tablist" aria-label="<?php esc_attr_e( 'Choose a video', 'moderno-child' ); ?>">
					<?php foreach ( $videos as $i => $s ) : ?>
						<button type="button" class="omc-reel__dot<?php echo 0 === $i ? ' is-on' : ''; ?>" data-go="<?php echo (int) $i; ?>" role="tab" aria-selected="<?php echo 0 === $i ? 'true' : 'false'; ?>"
							aria-label="<?php echo esc_attr( html_entity_decode( (string) $s['v']['title'], ENT_QUOTES, 'UTF-8' ) ); ?>"></button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</section>
	<?php
}

/* The section's own style and script, on the home page only (the video pages' look and the shop kit
   come with the home page already). */
add_action( 'wp_enqueue_scripts', static function () {
	if ( ! function_exists( 'omc_is_home_template' ) || ! omc_is_home_template() ) {
		return;
	}
	$ver = static fn( $rel ) => file_exists( OMC_DIR . $rel ) ? (string) filemtime( OMC_DIR . $rel ) : OMC_VERSION;
	wp_enqueue_style( 'omc-reel', OMC_URI . '/assets/css/omc-reel.css', [ 'omc' ], $ver( '/assets/css/omc-reel.css' ) );
	wp_enqueue_script( 'omc-reel', OMC_URI . '/assets/js/omc-reel.js', [], $ver( '/assets/js/omc-reel.js' ), true );
}, 1001 );
