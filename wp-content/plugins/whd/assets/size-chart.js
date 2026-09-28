/* Size chart dialog: open, close, restore focus. No dependencies. */
( function () {
	'use strict';

	var dialog = document.getElementById( 'whd-size-chart' );
	if ( ! dialog ) {
		return;
	}

	var opener = null;

	function open( trigger ) {
		opener = trigger || null;
		dialog.hidden = false;
		document.body.style.overflow = 'hidden';
		var close = dialog.querySelector( '.whd-sc__close' );
		if ( close ) {
			close.focus();
		}
	}

	function close() {
		dialog.hidden = true;
		document.body.style.overflow = '';
		if ( opener ) {
			opener.focus();
			opener = null;
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var trigger = e.target.closest( '[data-whd-size-chart]' );
		if ( trigger ) {
			e.preventDefault();
			open( trigger );
			return;
		}
		if ( e.target.closest( '[data-whd-size-chart-close]' ) ) {
			e.preventDefault();
			close();
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && ! dialog.hidden ) {
			close();
		}
	} );

	// Keep focus inside the panel while it is open.
	dialog.addEventListener( 'keydown', function ( e ) {
		if ( 'Tab' !== e.key ) {
			return;
		}
		var focusable = dialog.querySelectorAll( 'button, a[href], input, select, textarea, [tabindex]:not([tabindex="-1"])' );
		if ( ! focusable.length ) {
			return;
		}
		var first = focusable[ 0 ];
		var last = focusable[ focusable.length - 1 ];
		if ( e.shiftKey && document.activeElement === first ) {
			e.preventDefault();
			last.focus();
		} else if ( ! e.shiftKey && document.activeElement === last ) {
			e.preventDefault();
			first.focus();
		}
	} );
}() );
