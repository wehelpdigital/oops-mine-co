/**
 * Choose a piece from the shop — the small search the admin screens share (the Page Editor's photo
 * hotspots, Shoppable videos). Asks REST omc/v1/pieces (inc/shop-kit.php) as you type.
 *
 *   OMCPiecePicker.open( anchor, function ( piece ) { … }, onClose )   piece: { id, name, thumb, price, stock };
 *                                                            onClose runs when it closes without a pick
 *   OMCPiecePicker.known                                       pieces seen so far, by id
 */
( function () {
	'use strict';

	var A = window.OMC_PIECES_API || {};
	var T = A.i18n || {};
	var pop = null;
	var timer = null;
	var asked = 0;
	var known = {};
	var onGone = null;   // called when the search closes without a pick

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = null == s ? '' : String( s );
		return d.innerHTML;
	}

	function search( term ) {
		var url = A.rest + ( A.rest.indexOf( '?' ) === -1 ? '?' : '&' ) + 'search=' + encodeURIComponent( term );
		return window.fetch( url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': A.nonce } } ).then( function ( r ) {
			return r.json();
		} ).then( function ( list ) {
			( list || [] ).forEach( function ( p ) {
				known[ p.id ] = p;
			} );
			return list || [];
		} );
	}

	function close() {
		if ( pop ) {
			pop.remove();
			pop = null;
		}
		if ( onGone ) {
			var gone = onGone;
			onGone = null;
			gone();
		}
		document.removeEventListener( 'mousedown', outside, true );
		document.removeEventListener( 'keydown', onKey, true );
	}

	function outside( e ) {
		if ( pop && ! pop.contains( e.target ) ) {
			close();
		}
	}

	function onKey( e ) {
		if ( 'Escape' === e.key ) {
			e.stopPropagation();
			close();
		}
	}

	function open( anchor, onPick, onClose ) {
		close();
		onGone = onClose || null;
		pop = document.createElement( 'div' );
		pop.className = 'omc-pp';
		pop.innerHTML = '<div class="omc-pp__bar"><input type="search" class="omc-pp__in" placeholder="' + esc( T.search ) + '" aria-label="' + esc( T.search ) + '"><button type="button" class="omc-pp__x" aria-label="' + esc( T.close ) + '">&times;</button></div><ul class="omc-pp__list" role="listbox"></ul>';
		document.body.appendChild( pop );
		var r = anchor.getBoundingClientRect();
		var w = Math.min( 360, window.innerWidth - 24 );
		pop.style.width = w + 'px';
		pop.style.left = Math.max( 12, Math.min( window.innerWidth - w - 12, r.left ) ) + window.scrollX + 'px';
		pop.style.top = ( r.bottom + 6 + window.scrollY ) + 'px';

		var input = pop.querySelector( 'input' );
		var list = pop.querySelector( 'ul' );
		var paint = function ( items, msg ) {
			list.innerHTML = msg ? '<li class="omc-pp__msg">' + esc( msg ) + '</li>' : '';
			items.forEach( function ( p ) {
				var li = document.createElement( 'li' );
				li.innerHTML = '<button type="button" class="omc-pp__item" role="option">' +
					( p.thumb ? '<img src="' + esc( p.thumb ) + '" alt="">' : '<span class="omc-pp__ph"></span>' ) +
					'<span class="omc-pp__name">' + esc( p.name ) + '<small>' + esc( p.price ) + ( p.stock ? '' : ' · ' + esc( T.soldOut ) ) + '</small></span></button>';
				li.querySelector( 'button' ).addEventListener( 'click', function () {
					onGone = null;
					close();
					onPick( p );
				} );
				list.appendChild( li );
			} );
		};
		var run = function () {
			var term = input.value.trim();
			var ticket = ++asked;
			paint( [], T.loading );
			search( term ).then( function ( items ) {
				if ( ticket === asked && pop ) {
					paint( items, items.length ? '' : T.none );
				}
			} ).catch( function () {
				if ( ticket === asked && pop ) {
					paint( [], T.none );
				}
			} );
		};
		input.addEventListener( 'input', function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( run, 250 );
		} );
		input.addEventListener( 'keydown', function ( e ) {
			if ( 'ArrowDown' === e.key ) {
				var first = list.querySelector( 'button' );
				if ( first ) {
					e.preventDefault();
					first.focus();
				}
			}
		} );
		list.addEventListener( 'keydown', function ( e ) {
			var items = Array.prototype.slice.call( list.querySelectorAll( 'button' ) );
			var i = items.indexOf( document.activeElement );
			if ( 'ArrowDown' === e.key && i < items.length - 1 ) {
				e.preventDefault();
				items[ i + 1 ].focus();
			} else if ( 'ArrowUp' === e.key ) {
				e.preventDefault();
				( i > 0 ? items[ i - 1 ] : input ).focus();
			}
		} );
		pop.querySelector( '.omc-pp__x' ).addEventListener( 'click', close );
		window.setTimeout( function () {
			document.addEventListener( 'mousedown', outside, true );
			document.addEventListener( 'keydown', onKey, true );
		}, 0 );
		input.focus();
		run();
	}

	window.OMCPiecePicker = { open: open, close: close, known: known };
}() );
