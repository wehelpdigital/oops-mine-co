<?php
/**
 * The live page: the video, the pieces and the comments, filling the window.
 *
 * It draws itself rather than going through the theme: a viewer should not scroll to reach the
 * comment box, and nothing here uses the theme's header or footer. wp_head and wp_footer still run, so
 * the pixel, WooCommerce's cart and SEO output all work. Everything that changes comes from assets/live.js,
 * so this HTML is the same for everyone and can be cached.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

$whd_id     = get_queried_object_id();
$whd_stream = WHD_Live::get( $whd_id );
$whd_poster = get_the_post_thumbnail_url( $whd_id, 'large' );
$whd_blurb  = trim( (string) get_post_field( 'post_excerpt', $whd_id ) );
$whd_bag    = function_exists( 'WC' ) && WC()->cart ? (int) WC()->cart->get_cart_contents_count() : 0;
$whd_cart   = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '';
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="theme-color" content="#1b1412">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'whdl-body' ); ?>>
<?php wp_body_open(); ?>
<div class="whdl whdl--<?php echo esc_attr( $whd_stream['format'] ); ?>" id="whdl" data-stage="<?php echo esc_attr( $whd_stream['stage'] ); ?>">

	<header class="whdl-top">
		<a class="whdl-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
			<?php
			if ( function_exists( 'omc_logo_img' ) ) {
				echo omc_logo_img( 'cream', 'whdl-logo__img' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the theme's own escaped markup
			} else {
				echo '<span>' . esc_html( get_bloginfo( 'name' ) ) . '</span>';
			}
			?>
		</a>
		<div class="whdl-title">
			<h1><?php echo esc_html( get_the_title( $whd_id ) ); ?></h1>
			<span class="whdl-badge" id="whdl-badge" hidden><i></i><?php esc_html_e( 'Live', 'whd' ); ?></span>
			<span class="whdl-count" id="whdl-count" hidden>
				<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.800" aria-hidden="true"><path d="M2.500 12S6 5.500 12 5.500 21.500 12 21.500 12 18 18.500 12 18.500 2.500 12 2.500 12Z"/><circle cx="12" cy="12" r="2.800"/></svg>
				<b id="whdl-count-n">0</b>
			</span>
		</div>
		<?php if ( $whd_cart ) : ?>
			<a class="whdl-bag" href="<?php echo esc_url( $whd_cart ); ?>" target="_blank" rel="noopener" aria-label="<?php esc_attr_e( 'Your bag', 'whd' ); ?>">
				<svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.500" stroke-linejoin="round" aria-hidden="true"><path d="M6 8h12l1.200 12H4.800L6 8Z"/><path d="M9 8V6.500a3 3 0 0 1 6 0V8"/></svg>
				<span class="whdl-bag__n" data-n="<?php echo (int) $whd_bag; ?>"><?php echo (int) $whd_bag; ?></span>
			</a>
		<?php endif; ?>
	</header>

	<main class="whdl-grid">
		<section class="whdl-stage">
			<div class="whdl-stagebox">
				<div class="whdl-player" id="whdl-player">
					<video id="whdl-video" playsinline autoplay muted></video>
					<button type="button" class="whdl-hit" id="whdl-hit" hidden aria-label="<?php esc_attr_e( 'See the piece on screen', 'whd' ); ?>"></button>
					<button type="button" class="whdl-sound" id="whdl-sound" hidden>
						<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.800" stroke-linecap="round" aria-hidden="true"><path d="M4 9.500v5h3.500L12 18.500v-13L7.500 9.500H4Z"/><path d="m16 9.500 5 5M21 9.500l-5 5"/></svg>
						<?php esc_html_e( 'Tap for sound', 'whd' ); ?>
					</button>
					<div class="whdl-screen" id="whdl-screen"<?php echo $whd_poster ? ' style="--poster:url(' . esc_url( $whd_poster ) . ')"' : ''; ?>>
						<div class="whdl-screen__inner">
							<p class="whdl-kicker" id="whdl-screen-kicker"></p>
							<p class="whdl-screen__title" id="whdl-screen-title"><?php echo esc_html( get_the_title( $whd_id ) ); ?></p>
							<div class="whdl-clock" id="whdl-clock" hidden></div>
							<p class="whdl-screen__text" id="whdl-screen-text"><?php echo esc_html( $whd_blurb ); ?></p>
							<div class="whdl-share" id="whdl-share" hidden>
								<span><?php echo esc_html( WHD_Live::pretty_url( WHD_Live::page_url( $whd_id ) ) ); ?></span>
								<button type="button" id="whdl-copy" data-url="<?php echo esc_url( WHD_Live::page_url( $whd_id ) ); ?>"><?php esc_html_e( 'Copy link', 'whd' ); ?></button>
							</div>
							<span class="whdl-spinner" id="whdl-spinner" hidden></span>
							<button type="button" class="whdl-cta" id="whdl-tap" hidden></button>
						</div>
					</div>
				</div>
			</div>
			<button type="button" class="whdl-now" id="whdl-now" hidden>
				<img id="whdl-now-img" alt="">
				<span class="whdl-now__words">
					<small><?php esc_html_e( 'Now showing', 'whd' ); ?></small>
					<strong id="whdl-now-name"></strong>
					<em id="whdl-now-price"></em>
				</span>
				<span class="whdl-cta whdl-cta--small"><?php esc_html_e( 'Get it', 'whd' ); ?></span>
			</button>
		</section>

		<aside class="whdl-chat" aria-label="<?php esc_attr_e( 'Comments', 'whd' ); ?>">
			<h2><?php esc_html_e( 'Comments', 'whd' ); ?></h2>
			<ol class="whdl-messages" id="whdl-messages" aria-live="polite"></ol>
			<form class="whdl-form" id="whdl-form" novalidate>
				<?php /* Opened only by "add your name": nobody is asked. */ ?>
				<div class="whdl-who" id="whdl-who" hidden>
					<input type="text" id="whdl-name" name="name" maxlength="60" autocomplete="given-name" placeholder="<?php esc_attr_e( 'Your name', 'whd' ); ?>" aria-label="<?php esc_attr_e( 'Your name', 'whd' ); ?>">
				</div>
				<p class="whdl-as" id="whdl-as" hidden></p>
				<p class="whdl-error" id="whdl-error" role="alert" hidden></p>
				<div class="whdl-say">
					<input type="text" id="whdl-message" name="message" maxlength="300" autocomplete="off" aria-label="<?php esc_attr_e( 'Your comment', 'whd' ); ?>">
					<button type="submit" class="whdl-send" aria-label="<?php esc_attr_e( 'Send', 'whd' ); ?>">
						<svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.800" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true"><path d="M4 12 20 4.500 14.500 20l-3-6.500L4 12Z"/></svg>
					</button>
				</div>
				<?php /* For robots: people never see this field, and anything typed in it is ignored. */ ?>
				<input class="whdl-pot" type="text" name="website" id="whdl-website" tabindex="-1" autocomplete="off" aria-hidden="true">
			</form>
		</aside>
	</main>

	<aside class="whdl-sheet" id="whdl-sheet" aria-hidden="true" aria-label="<?php esc_attr_e( 'The piece', 'whd' ); ?>" tabindex="-1">
		<button type="button" class="whdl-sheet__x" id="whdl-sheet-x" aria-label="<?php esc_attr_e( 'Close', 'whd' ); ?>">×</button>
		<div class="whdl-sheet__photo"><img id="whdl-sheet-img" alt=""></div>
		<div class="whdl-sheet__body">
			<p class="whdl-sheet__note" id="whdl-sheet-note" hidden></p>
			<p class="whdl-kicker" id="whdl-sheet-kicker"></p>
			<h2 id="whdl-sheet-name"></h2>
			<p class="whdl-price"><b id="whdl-sheet-price"></b> <s id="whdl-sheet-regular"></s></p>
			<p class="whdl-sheet__text" id="whdl-sheet-text"></p>
			<div class="whdl-buy" id="whdl-buy"></div>
			<a class="whdl-sheet__link" id="whdl-sheet-link" target="_blank" rel="noopener"></a>
		</div>
	</aside>
</div>
<?php wp_footer(); ?>
</body>
</html>
