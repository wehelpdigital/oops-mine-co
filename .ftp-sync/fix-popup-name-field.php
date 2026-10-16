<?php
/**
 * Turn the first-name box on in the popups that are already saved.
 *
 * The block has always been able to ask for a name, and everything behind it — the subscribe
 * endpoint, the subscriber table, the Klaviyo profile that splits first from last — has always
 * been ready to carry one. It was simply switched off, so every profile arrived with an address
 * and nothing to greet them by.
 *
 * The default is fixed in the plugin, but a design that has already been saved keeps what it was
 * saved with. This flips those. It only ever turns the field on, and only on a form block, so
 * running it twice changes nothing the second time.
 *
 *   php .ftp-sync/fix-popup-name-field.php --dry-run
 *   php .ftp-sync/fix-popup-name-field.php
 *   php .ftp-sync/fix-popup-name-field.php --live      (production write — the owner runs this)
 */

$argv = $_SERVER['argv'] ?? [];
$dry  = in_array( '--dry-run', $argv, true );

/* `--live` is the same thing as OMC_DB=live, set here because PowerShell has no inline prefix. */
if ( in_array( '--live', $argv, true ) ) {
	putenv( 'OMC_DB=live' );
}

$_SERVER['HTTP_HOST'] = getenv( 'OMC_DB' ) === 'live' ? 'oopsmineco.com' : 'oopsmine.test';
require __DIR__ . '/../wp-load.php';

printf(
	"%s  →  %s\n\n",
	getenv( 'OMC_DB' ) === 'live' ? 'LIVE database' : 'local database',
	home_url( '/' )
);

$popups = get_option( 'whd_popups', [] );
if ( ! is_array( $popups ) || ! $popups ) {
	exit( "There are no saved popup designs, so the plugin default already applies.\n" );
}

$changed = 0;
$looked  = 0;

foreach ( $popups as $id => $design ) {
	foreach ( (array) ( $design['blocks'] ?? [] ) as $i => $block ) {
		if ( 'form' !== ( $block['type'] ?? '' ) ) {
			continue;
		}
		$looked++;
		$on = ! empty( $block['props']['show_name'] );
		printf( "  %-14s block %d: first name %s", $id, $i, $on ? "already on\n" : "off → on\n" );
		if ( $on ) {
			continue;
		}
		$popups[ $id ]['blocks'][ $i ]['props']['show_name'] = 1;
		if ( '' === trim( (string) ( $block['props']['name_placeholder'] ?? '' ) ) ) {
			$popups[ $id ]['blocks'][ $i ]['props']['name_placeholder'] = 'First name';
		}
		$changed++;
	}
}

if ( ! $looked ) {
	exit( "No popup has an email form in it, so there is nothing to add a name to.\n" );
}

if ( ! $changed ) {
	exit( "\nEvery form already asks for a first name.\n" );
}

if ( $dry ) {
	printf( "\n%d form(s) would change. Run it without --dry-run to save.\n", $changed );
	exit;
}

update_option( 'whd_popups', $popups );
printf( "\n%d form(s) updated.\n", $changed );
