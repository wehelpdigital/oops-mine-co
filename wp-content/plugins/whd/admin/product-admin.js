/**
 * The checklist's links jump to the panel that fixes each item.
 *
 * A plain #anchor is not enough on this screen: the panel may be collapsed, the field may be on a
 * tab of the Product data box, and on a long product page an unhighlighted jump leaves you
 * wondering what you were supposed to look at.
 */
( function () {
	'use strict';

	/* Which Product data tab owns which field, so "a price" opens the right one. */
	var TABS = {
		'woocommerce-product-data': 'general',
	};

	function reveal( id ) {
		var box = document.getElementById( id );
		if ( ! box ) {
			return false;
		}

		// An open panel, whichever way this WordPress collapses them.
		box.classList.remove( 'closed' );
		var toggle = box.querySelector( '.handlediv' );
		if ( toggle && toggle.getAttribute( 'aria-expanded' ) === 'false' ) {
			toggle.click();
		}

		if ( TABS[ id ] ) {
			var tab = box.querySelector( '.' + TABS[ id ] + '_options a, .' + TABS[ id ] + '_tab a' );
			if ( tab ) {
				tab.click();
			}
		}

		box.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		box.classList.remove( 'whd-flash' );
		// Restart the highlight even if the same item is clicked twice.
		void box.offsetWidth;
		box.classList.add( 'whd-flash' );
		setTimeout( function () { box.classList.remove( 'whd-flash' ); }, 2000 );
		return true;
	}

	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest && e.target.closest( '.whd-ready__jump' );
		if ( ! link ) {
			return;
		}
		var id = ( link.getAttribute( 'href' ) || '' ).replace( /^#/, '' );
		if ( id && reveal( id ) ) {
			e.preventDefault();
		}
	} );
}() );
