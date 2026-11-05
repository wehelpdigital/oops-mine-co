/**
 * WHD → Live Stream: the piece picker and cover picture on the stream form, and the delete confirmation
 * on the list. No dependencies besides wp.media.
 *
 * @package whd
 */
( function () {
	'use strict';

	var D = window.WHD_LIVE_FORM;
	if ( ! D ) {
		return;
	}
	var T = D.i18n;

	function el( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		if ( null !== text && undefined !== text ) {
			node.textContent = text;
		}
		return node;
	}

	document.querySelectorAll( '[data-whdla-confirm]' ).forEach( function ( link ) {
		link.addEventListener( 'click', function ( e ) {
			if ( ! window.confirm( T.confirmDrop ) ) {
				e.preventDefault();
			}
		} );
	} );

	/* ─────────────────────────── the stream's link ─────────────────────────── */

	document.querySelectorAll( '[data-copy]' ).forEach( function ( btn ) {
		var word = btn.textContent;
		btn.addEventListener( 'click', function () {
			var done = function () {
				btn.textContent = T.copied;
				setTimeout( function () {
					btn.textContent = word;
				}, 1600 );
			};
			var text = btn.getAttribute( 'data-copy' );
			var oldWay = function () {
				var box = el( 'textarea' );
				box.value = text;
				box.style.position = 'fixed';
				box.style.opacity = '0';
				document.body.appendChild( box );
				box.select();
				var worked = document.execCommand( 'copy' );
				box.remove();
				if ( worked ) {
					done();
				}
			};
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( text ).then( done, oldWay ); // a browser can refuse it even on https
				return;
			}
			oldWay();
		} );
	} );

	/* ─────────────────────────── cover picture ─────────────────────────── */

	document.querySelectorAll( '[data-whdla-poster]' ).forEach( function ( box ) {
		var input = box.querySelector( 'input' );
		var btn = box.querySelector( 'button' );
		btn.addEventListener( 'click', function () {
			var frame = window.wp.media( { title: T.poster, library: { type: 'image' }, multiple: false, button: { text: T.use } } );
			frame.on( 'select', function () {
				var file = frame.state().get( 'selection' ).first().toJSON();
				input.value = file.id;
				var sizes = file.sizes || {};
				btn.style.backgroundImage = 'url(' + ( ( sizes.medium || sizes.full || file ).url ) + ')';
			} );
			frame.open();
			frame.content.mode( 'browse' );
		} );
	} );

	/* ─────────────────────────── pieces: search and select ─────────────────────────── */

	document.querySelectorAll( '[data-whdla-picker]' ).forEach( function ( box ) {
		var hidden = box.querySelector( 'input[type="hidden"]' );
		var search = box.querySelector( '.whdla-finder__search' );
		var category = box.querySelector( '.whdla-finder__cat select' );
		var results = box.querySelector( '.whdla-finder__results' );
		var counter = box.querySelector( '.whdla-finder__count' );
		var more = box.querySelector( '.whdla-finder__more' );
		var chosen = box.querySelector( '.whdla-picker__chosen' );
		var list = box.querySelector( '.whdla-picker__list' );
		var rows = [];
		try {
			rows = JSON.parse( hidden.value ) || [];
		} catch ( e ) {
			rows = [];
		}
		var timer = null;
		var page = 1;
		var asking = 0;
		var PER_PAGE = 20;

		function write() {
			hidden.value = JSON.stringify( rows.map( function ( r ) {
				return { id: r.id, code: r.code || '' };
			} ) );
		}

		function indexOf( id ) {
			for ( var i = 0; i < rows.length; i++ ) {
				if ( rows[ i ].id === id ) {
					return i;
				}
			}
			return -1;
		}

		/* The ticks in the list follow what is selected, whichever way it got selected or removed. */
		function markResults() {
			results.querySelectorAll( '[data-id]' ).forEach( function ( btn ) {
				var on = indexOf( +btn.getAttribute( 'data-id' ) ) >= 0;
				btn.classList.toggle( 'is-picked', on );
				btn.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			} );
		}

		function renderChosen() {
			chosen.textContent = rows.length ? T.chosen.replace( '%d', rows.length ) : T.chosenNone;
			chosen.classList.toggle( 'is-empty', ! rows.length );
			list.textContent = '';
			rows.forEach( function ( row, i ) {
				var li = el( 'li', 'whdla-pick' );
				li.appendChild( el( 'span', 'whdla-pick__n', String( i + 1 ) ) );
				var img = el( 'img' );
				img.src = row.thumb;
				img.alt = '';
				li.appendChild( img );
				var words = el( 'span', 'whdla-pick__words' );
				words.appendChild( el( 'strong', '', row.name ) );
				words.appendChild( el( 'small', '', row.price ) );
				li.appendChild( words );
				var key = el( 'input', 'whdla-pick__key' );
				key.type = 'text';
				key.placeholder = T.codeOr.replace( '%d', i + 1 );
				key.value = row.code || '';
				key.setAttribute( 'aria-label', T.code );
				var markTaken = function () {
					var code = String( row.code || '' ).trim().toLowerCase();
					var taken = !! code && rows.some( function ( r ) {
						return r !== row && String( r.code || '' ).trim().toLowerCase() === code;
					} );
					key.classList.toggle( 'is-taken', taken );
					key.title = taken ? T.codeDup : '';
				};
				markTaken();
				key.addEventListener( 'input', function () {
					row.code = key.value;
					markTaken();
					write();
				} );
				li.appendChild( key );
				if ( i ) {
					var up = el( 'button', 'whdla-pick__btn', '↑' );
					up.type = 'button';
					up.setAttribute( 'aria-label', T.up );
					up.addEventListener( 'click', function () {
						rows.splice( i - 1, 0, rows.splice( i, 1 )[ 0 ] );
						renderChosen();
						write();
					} );
					li.appendChild( up );
				}
				var x = el( 'button', 'whdla-pick__btn', '×' );
				x.type = 'button';
				x.setAttribute( 'aria-label', T.remove );
				x.addEventListener( 'click', function () {
					rows.splice( i, 1 );
					renderChosen();
					markResults();
					write();
				} );
				li.appendChild( x );
				list.appendChild( li );
			} );
		}

		/* One press adds it, another takes it off. The list stays put, to pick several in a row. */
		function toggle( item ) {
			var at = indexOf( item.id );
			if ( at >= 0 ) {
				rows.splice( at, 1 );
			} else {
				rows.push( { id: item.id, name: item.name, price: item.price, thumb: item.thumb, code: '' } );
			}
			renderChosen();
			markResults();
			write();
		}

		function foundRow( item ) {
			var btn = el( 'button', 'whdla-found' );
			btn.type = 'button';
			btn.setAttribute( 'data-id', item.id );
			var img = el( 'img' );
			img.src = item.thumb;
			img.alt = '';
			img.loading = 'lazy';
			btn.appendChild( img );
			var words = el( 'span', 'whdla-found__words' );
			words.appendChild( el( 'strong', '', item.name ) );
			var line = el( 'small', '', item.price );
			if ( ! item.stock ) {
				line.appendChild( el( 'em', '', T.soldOut ) );
			}
			words.appendChild( line );
			btn.appendChild( words );
			btn.appendChild( el( 'i', 'whdla-found__tick' ) );
			btn.addEventListener( 'click', function () {
				toggle( item );
			} );
			return btn;
		}

		function say( text, isError, onRetry ) {
			results.textContent = '';
			var note = el( 'p', 'whdla-finder__note' + ( isError ? ' is-bad' : '' ), text );
			if ( onRetry ) {
				note.appendChild( document.createTextNode( ' ' ) );
				var again = el( 'button', 'button button-small', T.retry );
				again.type = 'button';
				again.addEventListener( 'click', onRetry );
				note.appendChild( again );
			}
			results.appendChild( note );
		}

		function find( fresh ) {
			var mine = ++asking; // only the newest question's answer is shown
			if ( fresh ) {
				page = 1;
			}
			results.classList.add( 'is-loading' );
			more.disabled = true;
			var query = 'products?per_page=' + PER_PAGE + '&page=' + page + '&q=' + encodeURIComponent( search.value.trim() ) + ( category && +category.value ? '&cat=' + category.value : '' );
			window.fetch( D.rest.root + query, {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': D.rest.nonce }
			} ).then( function ( r ) {
				return r.json().catch( function () {
					return {};
				} ).then( function ( data ) {
					if ( ! r.ok ) {
						throw new Error( data.message || ( 'HTTP ' + r.status ) );
					}
					return data;
				} );
			} ).then( function ( res ) {
				if ( mine !== asking ) {
					return;
				}
				var items = res.items || [];
				if ( fresh ) {
					results.textContent = '';
				}
				if ( ! items.length && fresh ) {
					say( T.none );
				}
				items.forEach( function ( item ) {
					results.appendChild( foundRow( item ) );
				} );
				markResults();
				var shown = results.querySelectorAll( '[data-id]' ).length;
				counter.textContent = shown ? T.found.replace( '%1$d', shown ).replace( '%2$d', res.total ) : '';
				more.hidden = ! res.more;
				more.disabled = false;
				results.classList.remove( 'is-loading' );
			} ).catch( function ( err ) {
				if ( mine !== asking ) {
					return;
				}
				results.classList.remove( 'is-loading' );
				more.disabled = false;
				more.hidden = true;
				counter.textContent = '';
				say( T.failed.replace( '%s', err.message ), true, function () {
					find( true );
				} );
			} );
		}

		search.addEventListener( 'input', function () {
			clearTimeout( timer );
			timer = setTimeout( function () {
				find( true );
			}, 220 );
		} );
		search.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault(); // never submit the whole form from a search box
				clearTimeout( timer );
				find( true );
			}
		} );
		if ( category ) {
			category.addEventListener( 'change', function () {
				find( true );
			} );
		}
		more.addEventListener( 'click', function () {
			page++;
			find( false );
		} );

		renderChosen();
		find( true );
	} );
}() );
