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

	/* ─────────────────────────── pieces ─────────────────────────── */

	document.querySelectorAll( '[data-whdla-picker]' ).forEach( function ( box ) {
		var hidden = box.querySelector( 'input[type="hidden"]' );
		var search = box.querySelector( 'input[type="search"]' );
		var results = box.querySelector( '.whdla-picker__results' );
		var list = box.querySelector( '.whdla-picker__list' );
		var rows = [];
		try {
			rows = JSON.parse( hidden.value ) || [];
		} catch ( e ) {
			rows = [];
		}
		var timer = null;

		function write() {
			hidden.value = JSON.stringify( rows.map( function ( r ) {
				return { id: r.id, keyword: r.keyword || '' };
			} ) );
		}

		function render() {
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
				key.placeholder = T.keyword;
				key.value = row.keyword || '';
				key.addEventListener( 'input', function () {
					row.keyword = key.value;
					write();
				} );
				li.appendChild( key );
				if ( i ) {
					var up = el( 'button', 'whdla-pick__btn', '↑' );
					up.type = 'button';
					up.setAttribute( 'aria-label', T.up );
					up.addEventListener( 'click', function () {
						rows.splice( i - 1, 0, rows.splice( i, 1 )[ 0 ] );
						render();
						write();
					} );
					li.appendChild( up );
				}
				var x = el( 'button', 'whdla-pick__btn', '×' );
				x.type = 'button';
				x.setAttribute( 'aria-label', T.remove );
				x.addEventListener( 'click', function () {
					rows.splice( i, 1 );
					render();
					write();
				} );
				li.appendChild( x );
				list.appendChild( li );
			} );
		}

		function find( q ) {
			window.fetch( D.rest.root + 'products?q=' + encodeURIComponent( q ), {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': D.rest.nonce }
			} ).then( function ( r ) {
				return r.json();
			} ).then( function ( res ) {
				results.textContent = '';
				var items = res.items || [];
				if ( ! items.length ) {
					results.appendChild( el( 'p', 'whdla-picker__none', T.none ) );
				}
				items.forEach( function ( item ) {
					var btn = el( 'button', 'whdla-result' );
					btn.type = 'button';
					var img = el( 'img' );
					img.src = item.thumb;
					img.alt = '';
					btn.appendChild( img );
					var words = el( 'span' );
					words.appendChild( el( 'strong', '', item.name ) );
					words.appendChild( el( 'small', '', item.price ) );
					btn.appendChild( words );
					btn.addEventListener( 'click', function () {
						if ( ! rows.some( function ( r ) {
							return r.id === item.id;
						} ) ) {
							rows.push( { id: item.id, name: item.name, price: item.price, thumb: item.thumb, keyword: '' } );
							render();
							write();
						}
						results.hidden = true;
						search.value = '';
					} );
					results.appendChild( btn );
				} );
				results.hidden = false;
			} ).catch( function () {} );
		}

		search.addEventListener( 'input', function () {
			clearTimeout( timer );
			var q = search.value.trim();
			timer = setTimeout( function () {
				find( q );
			}, 220 );
		} );
		search.addEventListener( 'focus', function () {
			find( search.value.trim() );
		} );
		search.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault(); // never submit the whole form from a search box
			}
		} );
		document.addEventListener( 'click', function ( e ) {
			if ( ! box.querySelector( '.whdla-picker__search' ).contains( e.target ) ) {
				results.hidden = true;
			}
		} );

		render();
	} );
}() );
