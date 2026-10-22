<?php
/**
 * The next drop: a countdown to new arrivals with "Remind me", built from the Facebook Live card.
 *
 * Its date, words, photo and link are the Page Editor's (home page → "Next drop"; off until it is
 * switched on and given a date). The card counts down with the same script as the Live card; its
 * form is the theme's newsletter form with source `drop`, so the sign-up joins the list like any
 * other and the WHD plugin keeps the address for the opening (WHD_Reminders). When the time comes
 * the plugin sends the "Drop reminder — it is open" email, and the card turns into a link to the drop.
 * A week after it opened the card goes away on its own.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

/** How long after it opens the card keeps saying "it's open". */
const OMC_DROP_OPEN_FOR = 7 * DAY_IN_SECONDS;

/**
 * The drop, or null while there is none: enabled, with a date, not over a week old.
 *
 * @return array|null when (DateTimeImmutable), open (bool), image, eyebrow, title, text, button,
 *                    url, open_title, open_text, open_button.
 */
function omc_drop() {
	if ( ! function_exists( 'omc_pe' ) || ! omc_pe( 'home', 'drop.enabled', false ) ) {
		return null;
	}
	$raw  = (string) omc_pe( 'home', 'drop.when', '' );
	$when = $raw ? DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $raw, wp_timezone() ) : false;
	if ( ! $when || $when->getTimestamp() + OMC_DROP_OPEN_FOR < time() ) {
		return null;
	}
	$link = omc_pe_link( 'home', 'drop.link' );
	return [
		'when'        => $when,
		'open'        => $when->getTimestamp() <= time(),
		'image'       => omc_pe( 'home', 'drop.image', omc_pe_default( 'home', 'drop.image' ) ),
		'eyebrow'     => omc_pe_copy( 'home', 'drop.eyebrow' ),
		'title'       => omc_pe_copy( 'home', 'drop.title' ),
		'text'        => omc_pe_copy( 'home', 'drop.text' ),
		'button'      => omc_pe_copy( 'home', 'drop.button' ),
		'url'         => $link['url'] ?: omc_new_arrivals_url(),
		'open_title'  => omc_pe_copy( 'home', 'drop.open_title' ),
		'open_text'   => omc_pe_copy( 'home', 'drop.open_text' ),
		'open_button' => $link['text'] ?: __( 'Shop the drop', 'moderno-child' ),
	];
}

/* The plugin's half: who to write to and when (WHD_Reminders reads this). */
add_filter( 'whd_drop', static function ( $drop ) {
	$d = omc_drop();
	if ( ! $d ) {
		return $drop;
	}
	$image = $d['image'] ? omc_attachment_id( $d['image'] ) : 0;
	return [
		'key'   => md5( $d['when']->format( DATE_ATOM ) . '|' . $d['title'] ),
		'when'  => $d['when']->getTimestamp(),
		'title' => $d['title'],
		'url'   => $d['url'],
		'image' => $image ? (string) wp_get_attachment_image_url( $image, 'large' ) : '',
	];
} );

/** The card itself (the home page prints it under the Facebook Live card). */
function omc_drop_card() {
	$d = omc_drop();
	if ( ! $d ) {
		return;
	}
	$iso = $d['when']->format( DATE_ATOM );
	?>
	<section class="omc-live omc-drop omc-reveal" aria-labelledby="omc-drop-title">
		<div class="l-section__container-wide">
			<div class="omc-live__card omc-drop__card js-omc-countdown<?php echo $d['open'] ? ' is-over' : ''; ?>" data-until="<?php echo esc_attr( $iso ); ?>" data-live-window="0">
				<?php if ( $d['image'] ) : ?>
					<div class="omc-live__media"><?php echo omc_image( $d['image'], 'large', [ 'loading' => 'lazy', 'alt' => omc_image_alt( $d['image'], $d['title'] ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<?php endif; ?>
				<div class="omc-live__body">
					<p class="omc-live__badge">
						<span class="omc-live__dot" aria-hidden="true"></span>
						<span class="omc-drop__soon"><?php echo esc_html( $d['eyebrow'] ); ?></span>
						<span class="omc-drop__now"><?php esc_html_e( 'Open now', 'moderno-child' ); ?></span>
					</p>
					<h2 class="omc-live__title" id="omc-drop-title">
						<span class="omc-drop__soon"><?php echo esc_html( $d['title'] ); ?></span>
						<span class="omc-drop__now"><?php echo esc_html( $d['open_title'] ); ?></span>
					</h2>
					<p class="omc-live__text omc-drop__soon"><?php echo esc_html( $d['text'] ); ?></p>
					<p class="omc-live__text omc-drop__now"><?php echo esc_html( $d['open_text'] ); ?></p>
					<div class="omc-live__count omc-drop__soon" role="timer" aria-label="<?php esc_attr_e( 'Time until the drop opens', 'moderno-child' ); ?>">
						<?php foreach ( [ 'd' => __( 'Days', 'moderno-child' ), 'h' => __( 'Hours', 'moderno-child' ), 'm' => __( 'Min', 'moderno-child' ), 's' => __( 'Sec', 'moderno-child' ) ] as $omc_u => $omc_lbl ) : ?>
							<span class="omc-live__cell"><span class="omc-live__num" data-unit="<?php echo esc_attr( $omc_u ); ?>">00</span><span class="omc-live__unit"><?php echo esc_html( $omc_lbl ); ?></span></span>
						<?php endforeach; ?>
					</div>
					<div class="omc-drop__soon omc-drop__form">
						<?php
						omc_newsletter_form( [
							'class'       => 'omc-live__form',
							'placeholder' => __( 'Your email address', 'moderno-child' ),
							'button'      => $d['button'],
							'source'      => 'drop',
							'name'        => true,
						] );
						?>
					</div>
					<div class="omc-drop__now">
						<a class="omc-btn omc-btn--solid" href="<?php echo esc_url( $d['url'] ); ?>"><?php echo esc_html( $d['open_button'] ); ?></a>
					</div>
					<p class="omc-live__foot">
						<time datetime="<?php echo esc_attr( $iso ); ?>"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' · ' . get_option( 'time_format' ), $d['when']->getTimestamp() ) ); ?></time>
					</p>
				</div>
			</div>
		</div>
	</section>
	<?php
}
