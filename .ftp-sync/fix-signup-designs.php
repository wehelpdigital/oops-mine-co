<?php
/**
 * Bring the saved popup and email designs in line with what the code now does.
 *
 * Changing a default in the plugin changes nothing for a design that has already been saved, and
 * both popups and the welcome email were saved long ago. Three things are wrong in the saved
 * copies:
 *
 *   the exit popup printed the code    on the screen, under a fifteen-minute countdown, with no
 *                                      email address asked for — and the coupon has no end date,
 *                                      so the clock counted down to nothing. It gets a form.
 *   the welcome popup printed the code in its success line, so the address bought nothing.
 *   the welcome email sent the wrong   one: the modals promise exit_coupon (15%) and the email
 *                                      printed cart_coupon (10%).
 *
 * Every change is checked before it is made, so running this twice does nothing the second time.
 *
 *   php .ftp-sync/fix-signup-designs.php              what would change
 *   php .ftp-sync/fix-signup-designs.php --apply      change it
 *   php .ftp-sync/fix-signup-designs.php --live --apply   …on production
 */

$argv  = $_SERVER['argv'] ?? [];
$apply = in_array( '--apply', $argv, true );

if ( in_array( '--live', $argv, true ) ) {
	putenv( 'OMC_DB=live' );
}

$_SERVER['HTTP_HOST'] = getenv( 'OMC_DB' ) === 'live' ? 'oopsmineco.com' : 'oopsmine.test';
require __DIR__ . '/../wp-load.php';

printf(
	"%s  →  %s   (%s)\n\n",
	getenv( 'OMC_DB' ) === 'live' ? 'LIVE database' : 'local database',
	home_url( '/' ),
	$apply ? 'applying' : 'dry run'
);

if ( ! class_exists( 'WHD_Popups' ) || ! class_exists( 'WHD_Emails' ) ) {
	exit( "The WHD plugin is not active.\n" );
}

printf(
	"The sign-up coupon is %s, worth %s.\n\n",
	WHD_Emails::welcome_coupon() ?: '(none — check WHD → Settings)',
	WHD_Emails::welcome_discount() ?: '(not a percentage)'
);

$changed = 0;
$note    = static function ( $line ) use ( &$changed ) {
	echo '  ' . $line . "\n";
	$changed++;
};

/* ─────────────────────────── the popups ─────────────────────────── */

$popups = get_option( 'whd_popups', [] );
$popups = is_array( $popups ) ? $popups : [];

foreach ( [ 'welcome', 'exit_intent' ] as $id ) {
	if ( empty( $popups[ $id ]['blocks'] ) ) {
		printf( "── %s ──\n  not saved; the plugin default already applies\n\n", $id );
		continue;
	}
	printf( "── %s ──\n", $id );
	$blocks  = $popups[ $id ]['blocks'];
	$default = WHD_Popups::default_design( $id );

	/* 1. a form, asking for a first name */
	$form_at = null;
	foreach ( $blocks as $i => $block ) {
		if ( 'form' === ( $block['type'] ?? '' ) ) {
			$form_at = $i;
			break;
		}
	}

	if ( null === $form_at ) {
		$fresh = null;
		foreach ( $default['blocks'] as $block ) {
			if ( 'form' === ( $block['type'] ?? '' ) ) {
				$fresh = $block;
				break;
			}
		}
		if ( $fresh ) {
			$note( 'no form at all → adding one that asks for a first name and an email' );
			$blocks[] = $fresh;
			$form_at  = count( $blocks ) - 1;
		}
	} elseif ( empty( $blocks[ $form_at ]['props']['show_name'] ) ) {
		$note( 'the form did not ask for a first name → it does now' );
		$blocks[ $form_at ]['props']['show_name'] = 1;
		if ( '' === trim( (string) ( $blocks[ $form_at ]['props']['name_placeholder'] ?? '' ) ) ) {
			$blocks[ $form_at ]['props']['name_placeholder'] = 'First name';
		}
	} else {
		echo "  the form already asks for a first name\n";
	}

	/* 2. the success line must not hand over the code */
	if ( null !== $form_at ) {
		$success = (string) ( $blocks[ $form_at ]['props']['success_text'] ?? '' );
		if ( false !== strpos( $success, '{coupon_code}' ) || false !== strpos( $success, '{exit_coupon}' ) || false !== strpos( $success, '{welcome_coupon}' ) ) {
			$note( 'the success line printed the code → it now says to check the email' );
			$blocks[ $form_at ]['props']['success_text'] = 'exit_intent' === $id
				? 'Sent — check your email for the code, then use it at checkout.'
				: 'You’re in — check your email for the code, then use it at checkout.';
		}
	}

	/* 3. nothing on screen should be the code, or a clock the coupon does not keep */
	foreach ( $blocks as $i => $block ) {
		$type = (string) ( $block['type'] ?? '' );
		if ( 'coupon' === $type ) {
			$note( sprintf( 'block %d is a coupon printing the code on screen → removed', $i ) );
			unset( $blocks[ $i ] );
		} elseif ( 'countdown' === $type ) {
			$note( sprintf( 'block %d counts down to an expiry the coupon does not have → removed', $i ) );
			unset( $blocks[ $i ] );
		}
	}

	/* 4. copy that names an amount by hand drifts from the coupon */
	foreach ( $blocks as $i => $block ) {
		if ( 'text' !== ( $block['type'] ?? '' ) && 'heading' !== ( $block['type'] ?? '' ) ) {
			continue;
		}
		$text = (string) ( $block['props']['text'] ?? '' );
		if ( preg_match( '/\b\d{1,2}%/', $text ) ) {
			$fixed = preg_replace( '/\b\d{1,2}%/', '{welcome_discount}', $text );
			$note( sprintf( 'block %d writes the discount out by hand → reads it from the coupon', $i ) );
			$blocks[ $i ]['props']['text'] = $fixed;
		}
		if ( false !== stripos( $text, 'lasts 15 minutes' ) ) {
			$note( sprintf( 'block %d promises the code expires, which it does not → reworded', $i ) );
			$blocks[ $i ]['props']['text'] = str_ireplace(
				'This code lasts 15 minutes.',
				'Leave your name and email and the code is in your inbox before you are.',
				$blocks[ $i ]['props']['text']
			);
		}
	}

	/* 5. the form comes before the button, not under it */
	$blocks  = array_values( $blocks );
	$form_at = null;
	$button  = null;
	foreach ( $blocks as $i => $block ) {
		$type = (string) ( $block['type'] ?? '' );
		if ( 'form' === $type && null === $form_at ) {
			$form_at = $i;
		}
		if ( 'button' === $type && null === $button ) {
			$button = $i;
		}
	}
	if ( null !== $form_at && null !== $button && $form_at > $button ) {
		$note( 'the form sat below the button → moved above it' );
		$moved = $blocks[ $form_at ];
		unset( $blocks[ $form_at ] );
		$blocks = array_values( $blocks );
		array_splice( $blocks, $button, 0, [ $moved ] );
	}

	$popups[ $id ]['blocks'] = array_values( $blocks );
	echo "\n";
}

/* ─────────────────────────── the welcome email ─────────────────────────── */

echo "── the welcome email ──\n";
$emails = get_option( 'whd_emails', [] );
$emails = is_array( $emails ) ? $emails : [];

if ( empty( $emails['welcome_subscriber']['blocks'] ) ) {
	echo "  not saved; the plugin default already applies\n";
} else {
	/* The subject, while it is still the stock one. Somebody told to look in their inbox for a
	   code should be able to see which email it is from the list of subjects. */
	$subject = (string) ( $emails['welcome_subscriber']['settings']['subject'] ?? '' );
	if ( 'Welcome to Oops, Mine Co.' === $subject ) {
		$note( 'the subject was the stock "Welcome to…" → "Your code is inside"' );
		$emails['welcome_subscriber']['settings']['subject'] = 'Your code is inside';
	}

	/* The name is collected now, so the email may as well use it. Only the stock sentence is
	   touched, and only when the design greets nobody anywhere — copy somebody wrote by hand is
	   left exactly as they wrote it. */
	$whole = wp_json_encode( $emails['welcome_subscriber'] );
	if ( false === strpos( (string) $whole, '{first_name}' ) ) {
		foreach ( $emails['welcome_subscriber']['blocks'] as $i => $block ) {
			$text = (string) ( $block['props']['text'] ?? '' );
			if ( 0 === strpos( $text, 'Thanks for joining the list.' ) ) {
				$note( 'the email greeted nobody → it now uses the first name we ask for' );
				$emails['welcome_subscriber']['blocks'][ $i ]['props']['text'] = str_replace(
					'Thanks for joining the list.',
					'Thanks for joining the list, {first_name}.',
					$text
				);
				break;
			}
		}
	}

	foreach ( $emails['welcome_subscriber']['blocks'] as $i => $block ) {
		if ( 'coupon' !== ( $block['type'] ?? '' ) ) {
			continue;
		}
		$code = (string) ( $block['props']['code'] ?? '' );
		if ( '{welcome_coupon}' !== $code ) {
			$note( sprintf( 'the coupon block sent %s → it now sends the code the modals promise', $code ?: '(nothing)' ) );
			$emails['welcome_subscriber']['blocks'][ $i ]['props']['code'] = '{welcome_coupon}';
		}
		$text = (string) ( $block['props']['note'] ?? '' );
		if ( preg_match( '/\b\d{1,2}%/', $text ) ) {
			$note( 'the note wrote the discount out by hand → reads it from the coupon' );
			$emails['welcome_subscriber']['blocks'][ $i ]['props']['note'] = preg_replace( '/\b\d{1,2}%/', '{welcome_discount}', $text );
		}
	}
}

/* ─────────────────────────── save ─────────────────────────── */

echo "\n";
if ( ! $changed ) {
	exit( "Everything is already as it should be.\n" );
}
if ( ! $apply ) {
	printf( "%d change(s) would be made. Add --apply to make them.\n", $changed );
	exit;
}

update_option( 'whd_popups', $popups );
update_option( 'whd_emails', $emails );
printf( "%d change(s) saved.\n", $changed );
