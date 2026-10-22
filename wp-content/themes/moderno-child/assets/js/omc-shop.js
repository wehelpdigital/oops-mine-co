/**
 * Oops, Mine Co. — the shop kit (inc/shop-kit.php): pick a size and add to the bag from anywhere that
 * is not a product grid, plus the hotspots on photographs.
 *
 *   OMCShop.piece( id )                  the piece printed by the page (name, price, options…)
 *   OMCShop.picker( piece, { compact } ) a size select and an "Add to bag" button, as one element
 *   OMCShop.add( [ { id, parent } ], button )  into the bag, then WooCommerce's added_to_cart
 *   OMCShop.outfit( [ ids ] )             the "Shop the look" sheet: every piece, one size each, one button
 *
 * Adding goes through WooCommerce's own ?wc-ajax=add_to_cart, one piece at a time, and finishes by
 * firing added_to_cart through jQuery — the event the cart drawer, the cart fragments and the Meta
 * pixel already listen to, so this behaves like any add-to-cart button on the site.
 *
 * Sizes start from the size this shopper chose last (the whd_fit cookie), when the piece has it.
 */
( function ( $ ) {
	'use strict';

	var node = document.getElementById( 'omc-shop-data' );
	var D = {};
	try {
		D = node ? JSON.parse( node.textContent ) : {};
	} catch ( e ) {
		D = {};
	}
	var T = D.i18n || {};
	var pieces = D.pieces || {};
	var reduce = !! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = null == s ? '' : String( s );
		return d.innerHTML;
	}

	function fmt( text, value ) {
		return String( text || '' ).replace( /%[sd]/, value );
	}

	/* ─────────────────────────── the size they wear ─────────────────────────── */

	function fit() {
		var m = document.cookie.match( /(?:^|;\s*)whd_fit=([^;]*)/ );
		if ( ! m ) {
			return {};
		}
		var text = m[ 1 ];
		for ( var i = 0; i < 3; i++ ) {
			try {
				var f = JSON.parse( text );
				return f && 'object' === typeof f ? f : {};
			} catch ( e ) {
				try {
					text = decodeURIComponent( text );
				} catch ( e2 ) {
					return {};
				}
			}
		}
		return {};
	}
	var FIT = fit();

	/* The option to start on: the only one left, or the one in their size. */
	function preferred( piece ) {
		var open = ( piece.options || [] ).filter( function ( o ) {
			return o.stock;
		} );
		if ( 1 === open.length ) {
			return open[ 0 ].id;
		}
		var keys = Object.keys( FIT );
		if ( ! keys.length ) {
			return 0;
		}
		var hits = open.filter( function ( o ) {
			return keys.every( function ( k ) {
				return ! o.attrs[ k ] || String( o.attrs[ k ] ).toLowerCase() === String( FIT[ k ] ).toLowerCase();
			} ) && keys.some( function ( k ) {
				return o.attrs[ k ];
			} );
		} );
		// Their size in one colour only is a fair guess; in several, the colour is theirs to choose.
		return 1 === hits.length ? hits[ 0 ].id : 0;
	}

	/* ─────────────────────────── into the bag ─────────────────────────── */

	function post( id ) {
		var body = new FormData();
		body.append( 'product_id', id );
		body.append( 'quantity', 1 );
		return window.fetch( D.ajax, { method: 'POST', body: body, credentials: 'same-origin' } ).then( function ( r ) {
			return r.json();
		} ).then( function ( res ) {
			if ( ! res || res.error || ! res.fragments ) {
				throw new Error( 'refused' );
			}
			return res;
		} );
	}

	/* entries: [ { id: product or variation id, parent: product id } ], one after the other. */
	function add( entries, button ) {
		if ( ! D.ajax || ! entries.length ) {
			return Promise.reject( new Error( 'nothing' ) );
		}
		var last = null;
		return entries.reduce( function ( chain, entry ) {
			return chain.then( function () {
				return post( entry.id ).then( function ( res ) {
					last = res;
				} );
			} );
		}, Promise.resolve() ).then( function () {
			if ( button ) {
				button.setAttribute( 'data-product_id', entries[ 0 ].parent || entries[ 0 ].id );
				button.setAttribute( 'data-quantity', '1' );
			}
			if ( $ && last ) {
				$( document.body ).trigger( 'added_to_cart', [ last.fragments, last.cart_hash, button ? $( button ) : $() ] );
			}
			return last;
		} );
	}

	/* ─────────────────────────── the picker ─────────────────────────── */

	var uid = 0;

	/**
	 * A size select (for a piece with sizes) and an Add button, or a link to the piece when it cannot
	 * be bought from here. `opts.button === false` leaves the button off (the outfit sheet has one
	 * button for everything); `value()` then says what would go in the bag.
	 */
	function picker( piece, opts ) {
		opts = opts || {};
		var box = document.createElement( 'div' );
		box.className = 'omc-pick' + ( opts.compact ? ' omc-pick--compact' : '' );
		var select = null;

		if ( ! piece.stock ) {
			box.innerHTML = '<span class="omc-pick__out">' + esc( T.soldOut ) + '</span>';
			box.value = function () {
				return null;
			};
			return box;
		}
		if ( piece.choose || ( 'variable' !== piece.type && ! piece.add ) ) {
			box.innerHTML = '<a class="omc-pick__go" href="' + esc( piece.url ) + '">' + esc( T.options ) + '</a>';
			box.value = function () {
				return null;
			};
			return box;
		}
		if ( 'variable' === piece.type ) {
			var id = 'omc-pick-' + ( ++uid );
			var start = preferred( piece );
			var html = '<label class="screen-reader-text" for="' + id + '">' + esc( T.size ) + '</label><select class="omc-pick__size" id="' + id + '"><option value="">' + esc( opts.compact ? T.size : T.choose ) + '</option>';
			piece.options.forEach( function ( o ) {
				html += '<option value="' + o.id + '"' + ( o.stock ? '' : ' disabled' ) + ( o.id === start ? ' selected' : '' ) + '>' + esc( o.label ) + ( o.stock ? '' : ' — ' + esc( T.gone ) ) + '</option>';
			} );
			box.innerHTML = html + '</select>';
			select = box.querySelector( 'select' );
		}
		box.value = function () {
			if ( select ) {
				return select.value ? { id: parseInt( select.value, 10 ), parent: piece.id } : null;
			}
			return { id: piece.id, parent: piece.id };
		};
		box.select = select;
		if ( false === opts.button ) {
			return box;
		}

		var go = document.createElement( 'button' );
		go.type = 'button';
		go.className = 'omc-pick__add';
		go.textContent = T.add;
		box.appendChild( go );
		var note = document.createElement( 'p' );
		note.className = 'omc-pick__note';
		note.setAttribute( 'role', 'status' );
		box.appendChild( note );
		go.addEventListener( 'click', function () {
			var v = box.value();
			if ( ! v ) {
				note.textContent = T.choose;
				if ( select ) {
					select.focus();
				}
				return;
			}
			go.disabled = true;
			go.textContent = T.adding;
			note.textContent = '';
			add( [ v ], go ).then( function () {
				go.textContent = T.added;
				go.classList.add( 'is-done' );
				window.setTimeout( function () {
					go.disabled = false;
					go.textContent = T.add;
					go.classList.remove( 'is-done' );
				}, 2600 );
			} ).catch( function () {
				go.disabled = false;
				go.textContent = T.add;
				note.innerHTML = esc( T.failed ) + ' <a href="' + esc( piece.url ) + '">' + esc( T.view ) + '</a>';
			} );
		} );
		if ( select ) {
			select.addEventListener( 'change', function () {
				note.textContent = '';
			} );
		}
		return box;
	}

	/* ─────────────────────────── the card a hotspot opens ─────────────────────────── */

	var card = null;
	var cardFor = null;

	function closeCard() {
		if ( ! card || card.hidden ) {
			return;
		}
		card.hidden = true;
		if ( cardFor ) {
			cardFor.setAttribute( 'aria-expanded', 'false' );
			cardFor.classList.remove( 'is-open' );
			resume( cardFor.closest( '.omc-spots' ) );
			cardFor.focus( { preventScroll: true } );
		}
		cardFor = null;
	}

	function openCard( spot ) {
		var piece = pieces[ spot.getAttribute( 'data-piece' ) ];
		if ( ! piece ) {
			return;
		}
		if ( cardFor === spot && card && ! card.hidden ) {
			closeCard();
			return;
		}
		closeCard();
		if ( ! card ) {
			card = document.createElement( 'div' );
			card.className = 'omc-spotcard';
			card.setAttribute( 'role', 'dialog' );
			card.hidden = true;
			document.body.appendChild( card );
		}
		card.setAttribute( 'aria-label', piece.name );
		card.innerHTML =
			'<button type="button" class="omc-spotcard__x" aria-label="' + esc( T.close ) + '">&times;</button>' +
			'<a class="omc-spotcard__media" href="' + esc( piece.url ) + '"><img src="' + esc( piece.image ) + '" alt=""></a>' +
			'<div class="omc-spotcard__body">' +
				'<a class="omc-spotcard__name" href="' + esc( piece.url ) + '">' + esc( piece.name ) + '</a>' +
				'<p class="omc-spotcard__price">' + esc( piece.price ) + '</p>' +
				'<div class="omc-spotcard__pick"></div>' +
				'<a class="omc-spotcard__view" href="' + esc( piece.url ) + '">' + esc( T.view ) + '</a>' +
			'</div>';
		card.querySelector( '.omc-spotcard__pick' ).appendChild( picker( piece, { compact: true } ) );
		card.querySelector( '.omc-spotcard__x' ).addEventListener( 'click', closeCard );
		card.hidden = false;
		cardFor = spot;
		spot.setAttribute( 'aria-expanded', 'true' );
		spot.classList.add( 'is-open' );
		pause( spot.closest( '.omc-spots' ) );
		place( spot );
		var first = card.querySelector( 'select, .omc-pick__add, .omc-pick__go' );
		if ( first ) {
			first.focus( { preventScroll: true } );
		}
	}

	/* Beside the point on a wide screen; a sheet along the bottom on a phone. */
	function place( spot ) {
		if ( window.innerWidth < 640 ) {
			card.classList.add( 'is-sheet' );
			card.style.left = '';
			card.style.top = '';
			return;
		}
		card.classList.remove( 'is-sheet' );
		var r = spot.getBoundingClientRect();
		var w = card.offsetWidth;
		var h = card.offsetHeight;
		var gap = 14;
		var left = r.right + gap;
		if ( left + w > window.innerWidth - 12 ) {
			left = r.left - gap - w;
		}
		left = Math.max( 12, left );
		var top = r.top + r.height / 2 - h / 2;
		top = Math.max( 12, Math.min( window.innerHeight - h - 12, top ) );
		card.style.left = ( left + window.scrollX ) + 'px';
		card.style.top = ( top + window.scrollY ) + 'px';
	}

	document.addEventListener( 'click', function ( e ) {
		if ( card && ! card.hidden && ! card.contains( e.target ) && ! e.target.closest( '.omc-spot' ) ) {
			closeCard();
		}
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			closeCard();
		}
	} );
	window.addEventListener( 'resize', closeCard );

	/* A slideshow under the points holds still while somebody is looking at one. */
	function pause( layer ) {
		var target = layer && layer.getAttribute( 'data-pause' ) && document.querySelector( layer.getAttribute( 'data-pause' ) );
		if ( target ) {
			target.dispatchEvent( new CustomEvent( 'omc:pause' ) );
		}
	}
	function resume( layer ) {
		var target = layer && layer.getAttribute( 'data-pause' ) && document.querySelector( layer.getAttribute( 'data-pause' ) );
		if ( target ) {
			target.dispatchEvent( new CustomEvent( 'omc:resume' ) );
		}
	}

	/* ─────────────────────────── "Shop the look": every piece in the photo ─────────────────────────── */

	var sheet = null;

	function outfit( ids, opener ) {
		var list = ids.map( function ( id ) {
			return pieces[ id ];
		} ).filter( Boolean );
		if ( ! list.length ) {
			return;
		}
		if ( ! sheet ) {
			sheet = document.createElement( 'dialog' );
			sheet.className = 'omc-outfit';
			document.body.appendChild( sheet );
			sheet.addEventListener( 'click', function ( e ) {
				if ( e.target === sheet ) {
					sheet.close();
				}
			} );
			sheet.addEventListener( 'close', function () {
				if ( sheet.opener ) {
					sheet.opener.focus();
				}
			} );
		}
		sheet.opener = opener || null;
		sheet.innerHTML =
			'<div class="omc-outfit__in">' +
				'<button type="button" class="omc-outfit__x" aria-label="' + esc( T.close ) + '">&times;</button>' +
				'<p class="omc-eyebrow">' + esc( fmt( T.outfit, list.length ) ) + '</p>' +
				'<h2 class="omc-outfit__h">' + esc( T.outfitH ) + '</h2>' +
				'<ul class="omc-outfit__list"></ul>' +
				'<div class="omc-outfit__foot"><button type="button" class="omc-btn omc-btn--solid omc-outfit__all">' + esc( T.addAll ) + '</button><p class="omc-outfit__note" role="status"></p></div>' +
			'</div>';
		var ul = sheet.querySelector( '.omc-outfit__list' );
		var pickers = [];
		list.forEach( function ( piece ) {
			var li = document.createElement( 'li' );
			li.className = 'omc-outfit__row';
			li.innerHTML = '<a class="omc-outfit__media" href="' + esc( piece.url ) + '"><img src="' + esc( piece.image ) + '" alt="" loading="lazy"></a>' +
				'<div class="omc-outfit__body"><a class="omc-outfit__name" href="' + esc( piece.url ) + '">' + esc( piece.name ) + '</a><span class="omc-outfit__price">' + esc( piece.price ) + '</span></div>';
			var p = picker( piece, { compact: true, button: false } );
			li.querySelector( '.omc-outfit__body' ).appendChild( p );
			pickers.push( { piece: piece, el: p } );
			ul.appendChild( li );
		} );
		var all = sheet.querySelector( '.omc-outfit__all' );
		var note = sheet.querySelector( '.omc-outfit__note' );
		var buyable = pickers.filter( function ( x ) {
			return x.piece.stock && ( x.piece.add || ( 'variable' === x.piece.type && ! x.piece.choose ) );
		} );
		all.disabled = ! buyable.length;
		all.addEventListener( 'click', function () {
			var entries = [];
			var missing = null;
			buyable.forEach( function ( x ) {
				var v = x.el.value();
				if ( v ) {
					entries.push( v );
				} else if ( ! missing ) {
					missing = x.el;
				}
			} );
			if ( missing ) {
				note.textContent = T.pickAll;
				if ( missing.select ) {
					missing.select.focus();
				}
				return;
			}
			all.disabled = true;
			all.textContent = T.adding;
			note.textContent = '';
			add( entries, all ).then( function () {
				all.textContent = T.added;
				window.setTimeout( function () {
					if ( sheet.open ) {
						sheet.close();
					}
				}, 900 );
			} ).catch( function () {
				all.disabled = false;
				all.textContent = T.addAll;
				note.textContent = T.failed;
			} );
		} );
		sheet.querySelector( '.omc-outfit__x' ).addEventListener( 'click', function () {
			sheet.close();
		} );
		closeCard();
		if ( sheet.showModal ) {
			sheet.showModal();
		} else {
			sheet.setAttribute( 'open', '' );
		}
	}

	/* ─────────────────────────── hotspots on photographs ─────────────────────────── */

	/* object-position as fractions: "64% 32%" → [ .64, .32 ]; keywords as their percentages. */
	function position( img ) {
		var words = { left: 0, top: 0, center: 0.5, right: 1, bottom: 1 };
		var parts = String( window.getComputedStyle( img ).objectPosition || '50% 50%' ).split( /\s+/ );
		return [ 0, 1 ].map( function ( i ) {
			var p = parts[ i ] || '50%';
			if ( Object.prototype.hasOwnProperty.call( words, p ) ) {
				return words[ p ];
			}
			return /%$/.test( p ) ? parseFloat( p ) / 100 : 0.5;
		} );
	}

	/* Where each point of the photograph's own frame lands once the photo is cropped to its box. */
	function layout( layer ) {
		var img = layer.parentElement && layer.parentElement.querySelector( layer.getAttribute( 'data-img' ) || 'img' );
		if ( ! img || ! img.naturalWidth ) {
			return;
		}
		var box = layer.getBoundingClientRect();
		var r = img.getBoundingClientRect();
		var fitMode = window.getComputedStyle( img ).objectFit;
		var nw = img.naturalWidth;
		var nh = img.naturalHeight;
		var s = 'contain' === fitMode ? Math.min( r.width / nw, r.height / nh ) : Math.max( r.width / nw, r.height / nh );
		var rw = 'fill' === fitMode ? r.width : nw * s;
		var rh = 'fill' === fitMode ? r.height : nh * s;
		var pos = position( img );
		var ox = r.left - box.left + ( r.width - rw ) * pos[ 0 ];
		var oy = r.top - box.top + ( r.height - rh ) * pos[ 1 ];
		layer.querySelectorAll( '.omc-spot' ).forEach( function ( spot ) {
			var x = ox + rw * parseFloat( spot.getAttribute( 'data-x' ) ) / 100;
			var y = oy + rh * parseFloat( spot.getAttribute( 'data-y' ) ) / 100;
			var inside = x > 10 && y > 10 && x < box.width - 10 && y < box.height - 10;
			spot.style.left = x + 'px';
			spot.style.top = y + 'px';
			spot.hidden = ! inside;
		} );
		layer.classList.add( 'is-ready' );
	}

	/* In a slideshow, a slide's points show while that slide does. */
	function follow( layer ) {
		var img = layer.parentElement && layer.parentElement.querySelector( layer.getAttribute( 'data-img' ) || 'img' );
		if ( ! img || ! layer.hasAttribute( 'data-index' ) || ! img.classList.contains( 'omc-hero__img' ) || ! document.querySelector( '.omc-hero__media--fade' ) ) {
			return;
		}
		var sync = function () {
			var on = img.classList.contains( 'is-active' );
			layer.classList.toggle( 'is-off', ! on );
			if ( ! on && cardFor && layer.contains( cardFor ) ) {
				closeCard();
			}
		};
		new MutationObserver( sync ).observe( img, { attributes: true, attributeFilter: [ 'class' ] } );
		sync();
	}

	function bindSpots() {
		var layers = document.querySelectorAll( '.omc-spots' );
		if ( ! layers.length ) {
			return;
		}
		layers.forEach( function ( layer ) {
			var img = layer.parentElement && layer.parentElement.querySelector( layer.getAttribute( 'data-img' ) || 'img' );
			if ( img && ! img.complete ) {
				img.addEventListener( 'load', function () {
					layout( layer );
				}, { once: true } );
			}
			layout( layer );
			follow( layer );
			layer.addEventListener( 'pointerenter', function () {
				pause( layer );
			} );
			layer.addEventListener( 'pointerleave', function () {
				if ( ! cardFor || ! layer.contains( cardFor ) ) {
					resume( layer );
				}
			} );
		} );
		var again = null;
		window.addEventListener( 'resize', function () {
			window.clearTimeout( again );
			again = window.setTimeout( function () {
				layers.forEach( layout );
			}, 120 );
		} );
		if ( window.ResizeObserver ) {
			var ro = new window.ResizeObserver( function ( entries ) {
				entries.forEach( function ( en ) {
					layout( en.target );
				} );
			} );
			layers.forEach( function ( l ) {
				ro.observe( l );
			} );
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var spot = e.target.closest( '.omc-spot' );
		if ( spot ) {
			e.preventDefault();
			e.stopPropagation();
			openCard( spot );
			return;
		}
		var look = e.target.closest( '[data-outfit]' );
		if ( look ) {
			e.preventDefault();
			outfit( String( look.getAttribute( 'data-outfit' ) ).split( ',' ), look );
		}
	} );

	/* Pickers printed by the page (Wear it with, video cards): <div data-omc-picker="123"></div>. */
	function bindPickers( scope ) {
		( scope || document ).querySelectorAll( '[data-omc-picker]' ).forEach( function ( slot ) {
			if ( slot.__omc ) {
				return;
			}
			slot.__omc = true;
			var piece = pieces[ slot.getAttribute( 'data-omc-picker' ) ];
			if ( piece ) {
				slot.appendChild( picker( piece, { compact: slot.hasAttribute( 'data-compact' ) } ) );
			}
		} );
	}

	function boot() {
		bindSpots();
		bindPickers();
	}

	window.OMCShop = {
		piece: function ( id ) {
			return pieces[ id ] || null;
		},
		pieces: pieces,
		picker: picker,
		add: add,
		outfit: outfit,
		fit: FIT,
		bind: bindPickers,
		reduce: reduce,
		i18n: T
	};

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}( window.jQuery ) );
