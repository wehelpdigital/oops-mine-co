/**
 * Oops, Mine Co. — "This or that" (inc/swipe.php): swipe right to love, left to skip.
 *
 * A love goes into the theme's wishlist through its own AJAX action — only when the piece is not
 * there already, since that action toggles — and fires the events the rest of the site listens to:
 * the theme's wishlist_added_item and the pixel's added_to_wishlist. A skip is kept in this browser
 * for a month so the deck does not offer the piece again. Pointer events for the drag; the buttons
 * and the arrow keys do the same thing for anyone not swiping.
 */
( function ( $ ) {
	'use strict';

	var deck = document.querySelector( '[data-omc-swipe]' );
	if ( ! deck ) {
		return;
	}
	var root = deck.closest( '.omc-swipe' );
	var cards = Array.prototype.slice.call( deck.querySelectorAll( '.omc-swipe__card' ) );
	var end = deck.querySelector( '.omc-swipe__end' );
	var status = root.querySelector( '.omc-swipe__status' );
	var countEl = root.querySelector( '.omc-swipe__count' );
	var reduce = !! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
	var COOKIE = 'ip-wishlist-items';
	var SKIPS = 'omc_swipe_skips';
	var MONTH = 30 * 864e5;
	var loved = 0;
	var queue = [];

	/* ─────────────────────────── memory ─────────────────────────── */

	function wishlist() {
		var m = document.cookie.match( new RegExp( '(?:^|;\\s*)' + COOKIE + '=([^;]*)' ) );
		if ( ! m ) {
			return [];
		}
		try {
			return ( JSON.parse( decodeURIComponent( m[ 1 ] ) ) || [] ).map( Number );
		} catch ( e ) {
			return [];
		}
	}

	function skips() {
		try {
			var all = JSON.parse( window.localStorage.getItem( SKIPS ) || '{}' );
			var now = Date.now();
			Object.keys( all ).forEach( function ( id ) {
				if ( now - all[ id ] > MONTH ) {
					delete all[ id ];
				}
			} );
			return all;
		} catch ( e ) {
			return {};
		}
	}

	function remember( id ) {
		try {
			var all = skips();
			all[ id ] = Date.now();
			window.localStorage.setItem( SKIPS, JSON.stringify( all ) );
		} catch ( e ) { /* private mode: the deck simply forgets */ }
	}

	/* ─────────────────────────── the deck ─────────────────────────── */

	function paint() {
		queue.forEach( function ( card, i ) {
			card.style.zIndex = String( 100 - i );
			card.classList.toggle( 'is-top', 0 === i );
			card.classList.toggle( 'is-next', 1 === i );
			card.classList.toggle( 'is-third', 2 === i );
			card.classList.toggle( 'is-under', i > 2 );
			card.setAttribute( 'aria-hidden', 0 === i ? 'false' : 'true' );
			card.hidden = false;
		} );
		end.hidden = queue.length > 0;
		root.classList.toggle( 'is-done', ! queue.length );
	}

	function start( fresh ) {
		var have = wishlist();
		var skipped = fresh ? {} : skips();
		cards.forEach( function ( c ) {
			c.hidden = true;
			c.classList.remove( 'is-gone', 'is-love', 'is-skip' );
			c.style.transform = '';
		} );
		queue = cards.filter( function ( c ) {
			var id = Number( c.getAttribute( 'data-id' ) );
			return fresh || ( have.indexOf( id ) === -1 && ! skipped[ id ] );
		} );
		paint();
	}

	function say( text ) {
		if ( status ) {
			status.textContent = text;
		}
	}

	function love( id ) {
		loved++;
		if ( countEl ) {
			countEl.textContent = String( loved );
		}
		if ( wishlist().indexOf( id ) !== -1 || ! window.OMC || ! window.OMC.ajax ) {
			return;
		}
		var body = new FormData();
		body.append( 'action', 'ideapark_wishlist_toggle' );
		body.append( 'product_id', id );
		window.fetch( window.OMC.ajax, { method: 'POST', body: body, credentials: 'same-origin' } ).then( function ( r ) {
			return r.json();
		} ).then( function ( json ) {
			if ( json && '1' === json.status && $ ) {
				$( '.c-wishlist__item-' + id + '-btn' ).addClass( 'c-wishlist__btn--added' );
				$( document.body ).trigger( 'wishlist_added_item' ).trigger( 'added_to_wishlist' ).trigger( 'wc_fragment_refresh' );
			}
		} ).catch( function () {} );
	}

	function decide( kind ) {
		var card = queue[ 0 ];
		if ( ! card ) {
			return;
		}
		var id = Number( card.getAttribute( 'data-id' ) );
		var name = ( card.querySelector( '.omc-swipe__name' ) || {} ).textContent || '';
		queue.shift();
		card.classList.add( 'is-gone', 'love' === kind ? 'is-love' : 'is-skip' );
		card.style.transform = reduce ? '' : 'translate(' + ( 'love' === kind ? 140 : -140 ) + '%, 8%) rotate(' + ( 'love' === kind ? 18 : -18 ) + 'deg)';
		if ( 'love' === kind ) {
			love( id );
			say( name + ' — ' + deck.getAttribute( 'data-saved' ) );
		} else {
			remember( id );
			say( name + ' — ' + deck.getAttribute( 'data-skipped' ) );
		}
		window.setTimeout( function () {
			card.hidden = true;
		}, reduce ? 0 : 420 );
		paint();
		if ( ! queue.length ) {
			deck.focus( { preventScroll: true } );
		}
	}

	/* ─────────────────────────── the drag ─────────────────────────── */

	var drag = null;

	deck.addEventListener( 'pointerdown', function ( e ) {
		var card = queue[ 0 ];
		if ( ! card || ! card.contains( e.target ) || e.target.closest( 'a, button' ) || ( 'mouse' === e.pointerType && 0 !== e.button ) ) {
			return;
		}
		drag = { card: card, x: e.clientX, y: e.clientY, dx: 0, id: e.pointerId, moved: false };
		card.classList.add( 'is-dragging' );
	} );

	deck.addEventListener( 'pointermove', function ( e ) {
		if ( ! drag || e.pointerId !== drag.id ) {
			return;
		}
		drag.dx = e.clientX - drag.x;
		var dy = e.clientY - drag.y;
		// A mostly vertical move is the page scrolling, not a swipe.
		if ( ! drag.moved && Math.abs( dy ) > Math.abs( drag.dx ) && Math.abs( dy ) > 8 ) {
			drag.card.classList.remove( 'is-dragging' );
			drag = null;
			return;
		}
		if ( Math.abs( drag.dx ) > 6 && ! drag.moved ) {
			drag.moved = true;
			try {
				deck.setPointerCapture( e.pointerId );
			} catch ( err ) { /* older browsers */ }
		}
		if ( drag.moved ) {
			e.preventDefault();
			var w = deck.offsetWidth || 300;
			drag.card.style.transform = 'translate(' + drag.dx + 'px, ' + ( dy * 0.15 ) + 'px) rotate(' + ( drag.dx / w * 14 ) + 'deg)';
			drag.card.classList.toggle( 'is-love', drag.dx > 30 );
			drag.card.classList.toggle( 'is-skip', drag.dx < -30 );
		}
	} );

	function release( e ) {
		if ( ! drag || ( e && e.pointerId !== drag.id ) ) {
			return;
		}
		var d = drag;
		drag = null;
		d.card.classList.remove( 'is-dragging' );
		var limit = Math.min( 120, ( deck.offsetWidth || 300 ) * 0.28 );
		if ( d.moved && Math.abs( d.dx ) > limit ) {
			decide( d.dx > 0 ? 'love' : 'skip' );
			return;
		}
		d.card.style.transform = '';
		d.card.classList.remove( 'is-love', 'is-skip' );
	}
	deck.addEventListener( 'pointerup', release );
	deck.addEventListener( 'pointercancel', release );

	/* A click after a drag is not a click on the card's link. */
	deck.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '.omc-swipe__card.is-gone' ) ) {
			e.preventDefault();
		}
	}, true );

	deck.addEventListener( 'keydown', function ( e ) {
		if ( 'ArrowRight' === e.key ) {
			e.preventDefault();
			decide( 'love' );
		} else if ( 'ArrowLeft' === e.key ) {
			e.preventDefault();
			decide( 'skip' );
		}
	} );

	root.querySelectorAll( '[data-swipe]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			decide( b.getAttribute( 'data-swipe' ) );
		} );
	} );

	var again = root.querySelector( '.omc-swipe__again' );
	if ( again ) {
		again.addEventListener( 'click', function () {
			try {
				window.localStorage.removeItem( SKIPS );
			} catch ( e ) { /* nothing kept */ }
			start( true );
		} );
	}

	start( false );
}( window.jQuery ) );
