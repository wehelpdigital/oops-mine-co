/**
 * Page Editor → Shoppable videos (inc/video-shop.php): which piece is on screen when.
 *
 * Each video is shown with its own player; "Add a piece here" takes the player's current time and
 * opens the shop search (assets/js/omc-piece-picker.js). Every moment can be retimed — typed, or set
 * to wherever the player is — re-pointed at another piece, played from, or removed. Save sends every
 * video's moments; the server keeps them sorted and checked.
 */
( function () {
	'use strict';

	var D = window.OMC_VSA || {};
	var T = D.i18n || {};
	var app = document.getElementById( 'omc-vsa-app' );
	if ( ! app ) {
		return;
	}
	var known = D.products || {};
	var state = {};
	var saved = '';
	var busy = false;

	( D.videos || [] ).forEach( function ( v ) {
		state[ v.slug ] = ( v.moments || [] ).map( function ( m ) {
			return { at: m.at, product: m.product };
		} );
	} );
	saved = JSON.stringify( state );

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = null == s ? '' : String( s );
		return d.innerHTML;
	}

	function clock( s ) {
		s = Math.max( 0, Math.round( s || 0 ) );
		return Math.floor( s / 60 ) + ':' + ( '0' + ( s % 60 ) ).slice( -2 );
	}

	function seconds( text ) {
		var m = /^\s*(?:(\d+):)?(\d{1,2})(?:\.\d+)?\s*$/.exec( String( text ) );
		if ( ! m ) {
			return null;
		}
		return ( m[ 1 ] ? parseInt( m[ 1 ], 10 ) * 60 : 0 ) + parseInt( m[ 2 ], 10 );
	}

	function piece( id ) {
		return known[ id ] || ( window.OMCPiecePicker && window.OMCPiecePicker.known[ id ] ) || null;
	}

	/* ─────────────────────────── the frame ─────────────────────────── */

	if ( ! ( D.videos || [] ).length ) {
		app.innerHTML = '<p class="omc-vsa__none">' + esc( T.noVideos ) + '</p>';
		return;
	}

	app.innerHTML = '<div class="omc-vsa__list"></div>' +
		'<div class="omc-vsa__save"><span class="omc-vsa__status" role="status" aria-live="polite"></span><button type="button" class="omc-vsa__go">' + esc( T.save ) + '</button></div>';
	var listEl = app.querySelector( '.omc-vsa__list' );
	var statusEl = app.querySelector( '.omc-vsa__status' );
	var goEl = app.querySelector( '.omc-vsa__go' );

	D.videos.forEach( function ( v ) {
		var card = document.createElement( 'section' );
		card.className = 'omc-vsa__video';
		card.innerHTML =
			'<header class="omc-vsa__vhead"><h2 class="omc-vsa__h">' + esc( v.title ) + '</h2>' +
				'<a class="omc-vsa__view" href="' + esc( v.view ) + '" target="_blank" rel="noopener">' + esc( T.view ) + ' ↗</a></header>' +
			'<div class="omc-vsa__grid">' +
				'<div class="omc-vsa__player"><video controls playsinline preload="metadata"' + ( v.poster ? ' poster="' + esc( v.poster ) + '"' : '' ) + ' src="' + esc( v.src ) + '"></video>' +
					( v.chapters.length ? '<p class="omc-vsa__chh">' + esc( T.chapters ) + '</p><div class="omc-vsa__chips"></div>' : '' ) +
				'</div>' +
				'<div class="omc-vsa__side">' +
					'<div class="omc-vsa__addrow"><button type="button" class="button button-primary omc-vsa__add">' + esc( T.addHere ) + ' <span class="omc-vsa__clock">0:00</span></button><span class="omc-vsa__hint">' + esc( T.addHint ) + '</span></div>' +
					'<ol class="omc-vsa__moments"></ol>' +
				'</div>' +
			'</div>';
		listEl.appendChild( card );

		var video = card.querySelector( 'video' );
		var ol = card.querySelector( '.omc-vsa__moments' );
		var clockEl = card.querySelector( '.omc-vsa__clock' );
		video.addEventListener( 'timeupdate', function () {
			clockEl.textContent = clock( video.currentTime );
		} );

		var add = function ( at ) {
			var row = { at: Math.round( at ), product: 0 };
			state[ v.slug ].push( row );
			state[ v.slug ].sort( function ( a, b ) {
				return a.at - b.at;
			} );
			paint();
			changed();
			var btn = ol.querySelector( '[data-row="' + state[ v.slug ].indexOf( row ) + '"] .omc-vsa__pick' );
			if ( btn && window.OMCPiecePicker ) {
				window.OMCPiecePicker.open( btn, function ( p ) {
					known[ p.id ] = p;
					row.product = p.id;
					paint();
					changed();
				} );
			}
		};
		card.querySelector( '.omc-vsa__add' ).addEventListener( 'click', function () {
			video.pause();
			add( video.currentTime || 0 );
		} );
		var chips = card.querySelector( '.omc-vsa__chips' );
		if ( chips ) {
			v.chapters.forEach( function ( c ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.className = 'omc-vsa__chip';
				b.innerHTML = '<b>' + esc( clock( c.at ) ) + '</b> ' + esc( c.label );
				b.addEventListener( 'click', function () {
					video.currentTime = c.at;
					add( c.at );
				} );
				chips.appendChild( b );
			} );
		}

		function paint() {
			var rows = state[ v.slug ];
			ol.innerHTML = rows.length ? '' : '<li class="omc-vsa__empty">' + esc( T.empty ) + '</li>';
			rows.forEach( function ( row, i ) {
				var p = row.product ? piece( row.product ) : null;
				var li = document.createElement( 'li' );
				li.className = 'omc-vsa__row' + ( row.product ? '' : ' is-empty' );
				li.setAttribute( 'data-row', i );
				li.innerHTML =
					'<label class="omc-vsa__time"><span class="screen-reader-text">' + esc( T.time ) + '</span><input type="text" inputmode="numeric" value="' + esc( clock( row.at ) ) + '" aria-label="' + esc( T.time ) + '"></label>' +
					'<button type="button" class="omc-vsa__ib" data-a="now" title="' + esc( T.now ) + '" aria-label="' + esc( T.now ) + '">⟲</button>' +
					'<button type="button" class="omc-vsa__pick">' +
						( p ? ( p.thumb ? '<img src="' + esc( p.thumb ) + '" alt="">' : '' ) + '<span>' + esc( p.name ) + '<small>' + esc( p.price || '' ) + '</small></span>' : '<span class="omc-vsa__choose">' + esc( row.product ? '#' + row.product : T.choose ) + '</span>' ) +
					'</button>' +
					'<button type="button" class="omc-vsa__ib" data-a="play" title="' + esc( T.jump ) + '" aria-label="' + esc( T.jump ) + '">▶</button>' +
					'<button type="button" class="omc-vsa__ib is-danger" data-a="x" title="' + esc( T.remove ) + '" aria-label="' + esc( T.remove ) + '">×</button>';
				var input = li.querySelector( 'input' );
				input.addEventListener( 'change', function () {
					var s = seconds( input.value );
					if ( null === s ) {
						input.value = clock( row.at );
						return;
					}
					row.at = s;
					rows.sort( function ( a, b ) {
						return a.at - b.at;
					} );
					paint();
					changed();
				} );
				li.querySelector( '.omc-vsa__pick' ).addEventListener( 'click', function ( e ) {
					if ( ! window.OMCPiecePicker ) {
						return;
					}
					window.OMCPiecePicker.open( e.currentTarget, function ( np ) {
						known[ np.id ] = np;
						row.product = np.id;
						paint();
						changed();
					} );
				} );
				li.querySelectorAll( '[data-a]' ).forEach( function ( b ) {
					b.addEventListener( 'click', function () {
						var a = b.getAttribute( 'data-a' );
						if ( 'x' === a ) {
							rows.splice( rows.indexOf( row ), 1 );
							paint();
							changed();
						} else if ( 'now' === a ) {
							row.at = Math.round( video.currentTime || 0 );
							rows.sort( function ( x, y ) {
								return x.at - y.at;
							} );
							paint();
							changed();
						} else {
							video.currentTime = row.at;
							video.play().catch( function () {} );
						}
					} );
				} );
				ol.appendChild( li );
			} );
		}
		card.repaint = paint;
		paint();
	} );

	/* ─────────────────────────── saving ─────────────────────────── */

	function payload() {
		var out = {};
		Object.keys( state ).forEach( function ( slug ) {
			out[ slug ] = state[ slug ].filter( function ( m ) {
				return m.product;
			} );
		} );
		return out;
	}

	function dirty() {
		return JSON.stringify( state ) !== saved;
	}

	function changed( text, tone ) {
		var d = dirty();
		statusEl.textContent = text || ( d ? T.unsaved : T.saved );
		statusEl.className = 'omc-vsa__status is-' + ( tone || ( d ? 'dirty' : 'ok' ) );
		goEl.disabled = busy || ! d;
	}

	goEl.addEventListener( 'click', function () {
		if ( busy || ! dirty() ) {
			return;
		}
		busy = true;
		goEl.textContent = T.saving;
		changed( T.saving, 'busy' );
		window.fetch( D.rest, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': D.nonce },
			body: JSON.stringify( { videos: payload() } )
		} ).then( function ( r ) {
			return r.json().then( function ( j ) {
				if ( ! r.ok ) {
					throw new Error( ( j && j.message ) || T.failed );
				}
				return j;
			} );
		} ).then( function ( j ) {
			Object.keys( j.videos || {} ).forEach( function ( slug ) {
				state[ slug ] = j.videos[ slug ].map( function ( m ) {
					return { at: m.at, product: m.product };
				} );
			} );
			saved = JSON.stringify( state );
			busy = false;
			goEl.textContent = T.save;
			listEl.querySelectorAll( '.omc-vsa__video' ).forEach( function ( c ) {
				if ( c.repaint ) {
					c.repaint();
				}
			} );
			changed( T.saved, 'ok' );
		} ).catch( function ( e ) {
			busy = false;
			goEl.textContent = T.save;
			changed( T.failed + ': ' + e.message, 'bad' );
			goEl.disabled = false;
		} );
	} );

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( dirty() ) {
			e.preventDefault();
			e.returnValue = T.leave;
			return T.leave;
		}
	} );

	changed();
}() );
