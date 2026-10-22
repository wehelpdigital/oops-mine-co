/**
 * Page Editor: the home and About pages' words and photos, section by section.
 *
 * Built from the schema inc/page-editor.php hands over (OMC_PE.pages: sections → fields, the
 * values the pages show now and their defaults). Every field writes straight into `state`; Save
 * sends both pages, and the server keeps only what differs from the defaults. A field that differs
 * from its default says so and offers "Reset to default"; a section with any such field gets a dot
 * in the list on the left.
 *
 * Pictures are attachment ids; `media` maps an id to its thumbnail, filled from the server and from
 * whatever is picked in the media library.
 */
( function () {
	'use strict';

	var D = window.OMC_PE || {};
	var T = D.i18n || {};
	var app = document.getElementById( 'omc-pe-app' );
	if ( ! app || ! D.pages ) {
		return;
	}

	var media = D.media || {};
	var pieces = D.pieces || {};      // products the hotspots point at: { id: { name, thumb, price } }
	var tab = D.pages[ D.tab ] ? D.tab : 'home';
	var state = {};
	var saved = {};
	var busy = false;
	var rich = [];          // ids of the rich editors on screen, removed before a repaint
	var tops = [];          // [ { page, key, refresh } ] — every top-level field, for its "changed" state
	var spy = null;

	Object.keys( D.pages ).forEach( function ( page ) {
		state[ page ] = clone( D.pages[ page ].values );
		saved[ page ] = JSON.stringify( state[ page ] );
	} );

	/* ─────────────────────────── small things ─────────────────────────── */

	function clone( v ) {
		return JSON.parse( JSON.stringify( v === undefined ? null : v ) );
	}

	function same( a, b ) {
		return JSON.stringify( a ) === JSON.stringify( b );
	}

	function esc( text ) {
		var d = document.createElement( 'div' );
		d.textContent = text == null ? '' : String( text );
		return d.innerHTML;
	}

	function fmt( text, value ) {
		return String( text || '' ).replace( /%[sd]/, value );
	}

	function h( tag, cls, html ) {
		var n = document.createElement( tag );
		if ( cls ) {
			n.className = cls;
		}
		if ( html != null ) {
			n.innerHTML = html;
		}
		return n;
	}

	var ICON = {
		up: '<path d="m6 14 6-6 6 6"/>',
		down: '<path d="m6 10 6 6 6-6"/>',
		left: '<path d="m14 6-6 6 6 6"/>',
		right: '<path d="m10 6 6 6-6 6"/>',
		x: '<path d="M7 7l10 10M17 7 7 17"/>',
		plus: '<path d="M12 5v14M5 12h14"/>',
		reset: '<path d="M4 11.5A8 8 0 1 0 6.3 6"/><path d="M3.5 4v4.5H8"/>',
		photo: '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m20.5 16-4.5-4.5L7 19.5"/>',
		out: '<path d="M14 4h6v6"/><path d="M20 4 11 13"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
		tag: '<path d="M3.5 12.5 11.5 4.5H19.5V12.5L11.5 20.5Z"/><circle cx="15.5" cy="8.5" r="1.4"/>'
	};

	function svg( name, size ) {
		size = size || 16;
		return '<svg viewBox="0 0 24 24" width="' + size + '" height="' + size + '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + ICON[ name ] + '</svg>';
	}

	var uid = 0;
	function nextId() {
		return 'omc-pe-f' + ( ++uid );
	}

	/* ─────────────────────────── the frame ─────────────────────────── */

	app.innerHTML =
		'<div class="omc-pe__bar">' +
			'<div class="omc-pe__tabs" role="tablist"></div>' +
			'<a class="omc-pe__view" target="_blank" rel="noopener">' + svg( 'out', 15 ) + '<span>' + esc( T.view ) + '</span></a>' +
		'</div>' +
		'<div class="omc-pe__layout">' +
			'<nav class="omc-pe__nav" aria-label="' + esc( T.sections ) + '"><p class="omc-pe__nav-h">' + esc( T.sections ) + '</p><ol></ol></nav>' +
			'<div class="omc-pe__main"></div>' +
		'</div>' +
		'<div class="omc-pe__save">' +
			'<span class="omc-pe__status" role="status" aria-live="polite"></span>' +
			'<button type="button" class="omc-pe__go">' + esc( T.save ) + '</button>' +
		'</div>';

	var el = {
		tabs: app.querySelector( '.omc-pe__tabs' ),
		view: app.querySelector( '.omc-pe__view' ),
		nav: app.querySelector( '.omc-pe__nav ol' ),
		main: app.querySelector( '.omc-pe__main' ),
		status: app.querySelector( '.omc-pe__status' ),
		go: app.querySelector( '.omc-pe__go' )
	};

	Object.keys( D.pages ).forEach( function ( page ) {
		var b = h( 'button', 'omc-pe__tab', esc( D.pages[ page ].label ) );
		b.type = 'button';
		b.setAttribute( 'role', 'tab' );
		b.setAttribute( 'data-page', page );
		b.addEventListener( 'click', function () {
			if ( page !== tab ) {
				tab = page;
				paint();
				var url = new URL( window.location.href );
				url.searchParams.set( 'tab', page );
				window.history.replaceState( null, '', url.toString() );
			}
		} );
		el.tabs.appendChild( b );
	} );

	/* ─────────────────────────── one page ─────────────────────────── */

	function paint() {
		rich.forEach( function ( id ) {
			if ( window.wp && wp.editor && wp.editor.remove ) {
				wp.editor.remove( id );
			}
		} );
		rich = [];
		tops = [];
		var page = D.pages[ tab ];
		Array.prototype.forEach.call( el.tabs.children, function ( b ) {
			var on = b.getAttribute( 'data-page' ) === tab;
			b.classList.toggle( 'is-on', on );
			b.setAttribute( 'aria-selected', on ? 'true' : 'false' );
		} );
		el.view.href = page.view;

		el.main.innerHTML = '';
		el.nav.innerHTML = '';
		if ( page.keyphrase ) {
			el.main.appendChild( h( 'p', 'omc-pe__kp', esc( fmt( T.keyphrase, page.keyphrase ) ) ) );
		}
		page.sections.forEach( function ( section, i ) {
			var id = 'omc-pe-' + tab + '-' + section.key;
			var card = h( 'section', 'omc-pe__card' );
			card.id = id;
			card.innerHTML = '<header class="omc-pe__card-head"><span class="omc-pe__num" aria-hidden="true">' + ( i + 1 ) + '</span><div>' +
				'<h2 class="omc-pe__h">' + esc( section.label ) + '</h2>' +
				( section.note ? '<p class="omc-pe__note">' + esc( section.note ) + '</p>' : '' ) +
				'</div></header>';
			var body = h( 'div', 'omc-pe__fields' );
			section.fields.forEach( function ( f ) {
				body.appendChild( top( tab, f ) );
			} );
			card.appendChild( body );
			el.main.appendChild( card );

			var li = h( 'li', '', '<a href="#' + id + '"><span class="omc-pe__nav-n">' + ( i + 1 ) + '</span><span class="omc-pe__nav-t">' + esc( section.label ) + '</span><span class="omc-pe__dot" title="' + esc( T.changed ) + '" hidden></span></a>' );
			li.setAttribute( 'data-section', section.key );
			li.querySelector( 'a' ).addEventListener( 'click', function ( e ) {
				e.preventDefault();
				card.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			} );
			el.nav.appendChild( li );
		} );
		watchSections();
		refresh();
		status();
	}

	/* The section in view is the one marked in the list. */
	function watchSections() {
		if ( spy ) {
			spy.disconnect();
		}
		if ( ! window.IntersectionObserver ) {
			return;
		}
		spy = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					var key = entry.target.id.replace( 'omc-pe-' + tab + '-', '' );
					Array.prototype.forEach.call( el.nav.children, function ( li ) {
						li.classList.toggle( 'is-on', li.getAttribute( 'data-section' ) === key );
					} );
				}
			} );
		}, { rootMargin: '-35% 0px -60% 0px' } );
		Array.prototype.forEach.call( el.main.querySelectorAll( '.omc-pe__card' ), function ( c ) {
			spy.observe( c );
		} );
	}

	/* Which fields differ from their default, and so which sections have a dot. */
	function refresh() {
		var changed = {};
		tops.forEach( function ( t ) {
			var diff = t.refresh();
			if ( diff ) {
				changed[ t.section ] = true;
			}
		} );
		Array.prototype.forEach.call( el.nav.children, function ( li ) {
			li.querySelector( '.omc-pe__dot' ).hidden = ! changed[ li.getAttribute( 'data-section' ) ];
		} );
	}

	function sectionOf( page, key ) {
		var found = '';
		D.pages[ page ].sections.forEach( function ( s ) {
			s.fields.forEach( function ( f ) {
				if ( f.key === key ) {
					found = s.key;
				}
			} );
		} );
		return found;
	}

	/* A top-level field: its label, its control, and "Reset to default" when it has been changed. */
	function top( page, f ) {
		var wrap = h( 'div', 'omc-pe__field is-' + f.type );
		var slot = {
			get: function () {
				return state[ page ][ f.key ];
			},
			set: function ( v ) {
				state[ page ][ f.key ] = v;
				changed();
			}
		};
		var build = function () {
			wrap.innerHTML = '';
			var head = h( 'div', 'omc-pe__label-row' );
			var id = nextId();
			head.innerHTML = '<label class="omc-pe__label" for="' + id + '">' + esc( f.label ) + '</label>' +
				'<span class="omc-pe__changed" hidden>' + esc( T.changed ) + '</span>' +
				'<button type="button" class="omc-pe__reset" hidden>' + svg( 'reset', 13 ) + '<span>' + esc( T.reset ) + '</span></button>';
			wrap.appendChild( head );
			wrap.appendChild( control( f, slot, id ) );
			if ( f.help || 'em' === f.type || f.em ) {
				wrap.appendChild( h( 'p', 'omc-pe__help', esc( [ f.help, ( 'em' === f.type && ! f.help ) ? T.emHint : '' ].filter( Boolean ).join( ' ' ) ) ) );
			}
			head.querySelector( '.omc-pe__reset' ).addEventListener( 'click', function () {
				state[ page ][ f.key ] = clone( D.pages[ page ].defaults[ f.key ] );
				if ( 'rich' === f.type && window.wp && wp.editor ) {
					wp.editor.remove( id );
					rich = rich.filter( function ( r ) {
						return r !== id;
					} );
				}
				build();
				changed();
			} );
		};
		build();
		tops.push( {
			section: sectionOf( page, f.key ),
			refresh: function () {
				var diff = ! same( state[ page ][ f.key ], D.pages[ page ].defaults[ f.key ] );
				wrap.classList.toggle( 'is-changed', diff );
				wrap.querySelector( '.omc-pe__reset' ).hidden = ! diff;
				wrap.querySelector( '.omc-pe__changed' ).hidden = ! diff;
				return diff;
			}
		} );
		return wrap;
	}

	function changed() {
		refresh();
		status();
	}

	/* ─────────────────────────── the controls ─────────────────────────── */

	function control( f, slot, id ) {
		var v = slot.get();
		var n;
		switch ( f.type ) {
			case 'text':
			case 'em':
			case 'url':
				n = h( 'input', 'omc-pe__in' );
				n.type = 'url' === f.type ? 'url' : 'text';
				n.id = id;
				n.value = v || '';
				if ( 'url' === f.type ) {
					n.placeholder = 'https://';
				}
				n.addEventListener( 'input', function () {
					slot.set( n.value );
				} );
				return n;

			case 'textarea':
			case 'lines':
				n = h( 'textarea', 'omc-pe__in' );
				n.id = id;
				n.rows = 'lines' === f.type ? Math.max( 3, ( v || [] ).length + 1 ) : 3;
				n.value = 'lines' === f.type ? ( v || [] ).join( '\n' ) : ( v || '' );
				if ( 'lines' === f.type ) {
					n.placeholder = T.onePerLine;
				}
				n.addEventListener( 'input', function () {
					slot.set( 'lines' === f.type ? n.value.split( /\r?\n/ ).map( function ( l ) {
						return l.trim();
					} ).filter( Boolean ) : n.value );
				} );
				return n;

			case 'select':
				n = h( 'select', 'omc-pe__in' );
				n.id = id;
				Object.keys( f.options || {} ).forEach( function ( k ) {
					var o = h( 'option', '', esc( f.options[ k ] ) );
					o.value = k;
					o.selected = k === v;
					n.appendChild( o );
				} );
				n.addEventListener( 'change', function () {
					slot.set( n.value );
				} );
				return n;

			case 'toggle':
				n = h( 'label', 'omc-pe__switch', '<input type="checkbox" id="' + id + '"' + ( v ? ' checked' : '' ) + '><span class="omc-pe__switch-ui" aria-hidden="true"></span><span>' + esc( T.shown ) + '</span>' );
				n.querySelector( 'input' ).addEventListener( 'change', function () {
					slot.set( this.checked );
				} );
				return n;

			case 'datetime':
				n = h( 'input', 'omc-pe__in omc-pe__in--short' );
				n.type = 'datetime-local';
				n.id = id;
				n.value = v || '';
				n.addEventListener( 'input', function () {
					slot.set( n.value );
				} );
				return n;

			case 'link':
				return linkControl( slot, id );
			case 'image':
				return imageControl( slot, id );
			case 'images':
				return imagesControl( slot, id );
			case 'rich':
				return richControl( slot, id );
			case 'group':
				return groupControl( f, slot );
			case 'list':
				return listControl( f, slot );
			case 'spots':
				return spotsControl( slot, slot.image || function () {
					return 0;
				} );
			case 'spotmap':
				return spotmapControl( f, slot );
		}
		return h( 'span' );
	}

	/* A picture changed somewhere: hotspot editors that sit on it repaint. */
	function pictureChanged( n ) {
		n.dispatchEvent( new CustomEvent( 'omc-pe-image', { bubbles: true } ) );
	}

	function linkControl( slot, id ) {
		var v = slot.get() || { text: '', url: '' };
		var n = h( 'div', 'omc-pe__link',
			'<label class="omc-pe__sub"><span>' + esc( T.linkText ) + '</span><input type="text" class="omc-pe__in" id="' + id + '"></label>' +
			'<label class="omc-pe__sub"><span>' + esc( T.linkUrl ) + '</span><input type="url" class="omc-pe__in" placeholder="https://"></label>' );
		var ins = n.querySelectorAll( 'input' );
		ins[ 0 ].value = v.text || '';
		ins[ 1 ].value = v.url || '';
		var put = function () {
			slot.set( { text: ins[ 0 ].value, url: ins[ 1 ].value } );
		};
		ins[ 0 ].addEventListener( 'input', put );
		ins[ 1 ].addEventListener( 'input', put );
		return n;
	}

	function remember( attachment ) {
		var a = attachment.toJSON ? attachment.toJSON() : attachment;
		var s = a.sizes || {};
		media[ a.id ] = {
			thumb: ( s.medium || s.large || s.full || {} ).url || a.url,
			large: ( s.large || s.full || {} ).url || a.url,
			name: a.filename || a.title || ''
		};
		return a.id;
	}

	function library( many, onPick ) {
		if ( ! window.wp || ! wp.media ) {
			return;
		}
		var frame = wp.media( { title: many ? T.addPhotos : T.choose, button: { text: many ? T.useMany : T.use }, library: { type: 'image' }, multiple: many } );
		frame.on( 'select', function () {
			onPick( frame.state().get( 'selection' ).map( remember ) );
		} );
		frame.open();
	}

	function picture( id ) {
		var m = media[ id ];
		return m && m.thumb ? '<img src="' + esc( m.thumb ) + '" alt="">' : '<span class="omc-pe__nophoto">' + svg( 'photo', 22 ) + '<small>' + esc( T.noImage ) + '</small></span>';
	}

	function imageControl( slot, id ) {
		var n = h( 'div', 'omc-pe__image' );
		var paintIt = function () {
			var v = slot.get();
			n.innerHTML = '<button type="button" class="omc-pe__image-pic" id="' + id + '" aria-label="' + esc( T.change ) + '">' + picture( v ) + '</button>' +
				'<div class="omc-pe__image-side">' +
					( media[ v ] && media[ v ].name ? '<span class="omc-pe__image-name">' + esc( media[ v ].name ) + '</span>' : '' ) +
					'<button type="button" class="omc-pe__btn">' + svg( 'photo', 15 ) + '<span>' + esc( v ? T.change : T.choose ) + '</span></button>' +
				'</div>';
			n.querySelectorAll( 'button' ).forEach( function ( b ) {
				b.addEventListener( 'click', function () {
					library( false, function ( ids ) {
						if ( ids[ 0 ] ) {
							slot.set( ids[ 0 ] );
							paintIt();
							pictureChanged( n );
						}
					} );
				} );
			} );
		};
		paintIt();
		n.repaint = paintIt;
		return n;
	}

	function imagesControl( slot, id ) {
		var n = h( 'div', 'omc-pe__images' );
		var paintIt = function () {
			var list = ( slot.get() || [] ).slice();
			n.innerHTML = '';
			list.forEach( function ( pid, i ) {
				var fig = h( 'figure', 'omc-pe__thumb', picture( pid ) +
					'<span class="omc-pe__thumb-n">' + ( i + 1 ) + '</span>' +
					'<span class="omc-pe__thumb-acts">' +
						( i > 0 ? '<button type="button" data-a="left" aria-label="' + esc( T.moveLeft ) + '">' + svg( 'left', 14 ) + '</button>' : '' ) +
						( i < list.length - 1 ? '<button type="button" data-a="right" aria-label="' + esc( T.moveRight ) + '">' + svg( 'right', 14 ) + '</button>' : '' ) +
						( list.length > 1 ? '<button type="button" data-a="x" aria-label="' + esc( T.remove ) + '">' + svg( 'x', 14 ) + '</button>' : '' ) +
					'</span>' );
				fig.querySelectorAll( '[data-a]' ).forEach( function ( b ) {
					b.addEventListener( 'click', function () {
						var a = b.getAttribute( 'data-a' );
						var next = list.slice();
						if ( 'x' === a ) {
							next.splice( i, 1 );
						} else {
							var j = 'left' === a ? i - 1 : i + 1;
							next[ i ] = list[ j ];
							next[ j ] = list[ i ];
						}
						slot.set( next );
						paintIt();
						pictureChanged( n );
					} );
				} );
				n.appendChild( fig );
			} );
			var add = h( 'button', 'omc-pe__thumb is-add', svg( 'plus', 18 ) + '<span>' + esc( T.addPhotos ) + '</span>' );
			add.type = 'button';
			add.id = id;
			add.addEventListener( 'click', function () {
				library( true, function ( ids ) {
					slot.set( list.concat( ids.filter( function ( x ) {
						return list.indexOf( x ) === -1;
					} ) ).slice( 0, 12 ) );
					paintIt();
					pictureChanged( n );
				} );
			} );
			n.appendChild( add );
		};
		paintIt();
		return n;
	}

	/* The About story: WordPress's own editor, Add Media and all. */
	function richControl( slot, id ) {
		var n = h( 'div', 'omc-pe__rich' );
		var area = h( 'textarea', 'omc-pe__in' );
		area.id = id;
		area.rows = 18;
		area.value = slot.get() || '';
		n.appendChild( area );
		/*
		 * Read back only once somebody has typed, pasted or inserted something. The editor tidies
		 * the HTML it is given as it loads, and reading that back unasked would count as a change —
		 * and save the whole story over the page's own on the next Save of anything else.
		 */
		var sync = function () {
			slot.set( wp.editor.getContent( id ) );
		};
		area.addEventListener( 'input', function () {
			slot.set( area.value );
		} );
		window.setTimeout( function () {
			if ( ! window.wp || ! wp.editor || ! wp.editor.initialize ) {
				return;
			}
			wp.editor.initialize( id, {
				tinymce: {
					wpautop: true,
					height: 460,
					toolbar1: 'formatselect,bold,italic,bullist,numlist,blockquote,link,unlink,undo,redo',
					block_formats: 'Paragraph=p;Heading=h2;Subheading=h3',
					content_style: 'body{font-family:Georgia,serif;font-size:16px;line-height:1.7;max-width:760px;margin:16px auto;color:#2b2523} h2{font-weight:500} .omc-eyebrow{font:600 11px/1.4 sans-serif;letter-spacing:.14em;text-transform:uppercase;color:#9c6f63}',
					setup: function ( ed ) {
						ed.on( 'keyup paste cut undo redo', sync );
						ed.on( 'ExecCommand', function ( e ) {
							if ( ! /^mce(Repaint|Focus|AddUndo|EndUndoLevel|BeginUndoLevel)$/.test( e.command ) ) {
								sync();
							}
						} );
					}
				},
				quicktags: true,
				mediaButtons: true
			} );
			rich.push( id );
		}, 0 );
		return n;
	}

	function groupControl( f, slot, label ) {
		var n = h( 'fieldset', 'omc-pe__group' );
		if ( label ) {
			n.appendChild( h( 'legend', 'omc-pe__group-h', esc( label ) ) );
		}
		Object.keys( f.fields ).forEach( function ( k ) {
			var sub = f.fields[ k ];
			var id = nextId();
			var row = h( 'div', 'omc-pe__sub is-' + sub.type );
			row.appendChild( h( 'label', 'omc-pe__sub-label', esc( sub.label ) ) ).setAttribute( 'for', id );
			row.appendChild( control( sub, {
				get: function () {
					return ( slot.get() || {} )[ k ];
				},
				set: function ( v ) {
					var g = clone( slot.get() || {} );
					g[ k ] = v;
					slot.set( g );
				},
				image: sub.image ? function () {
					return ( slot.get() || {} )[ sub.image ];
				} : null
			}, id ) );
			if ( sub.help ) {
				row.appendChild( h( 'p', 'omc-pe__help', esc( sub.help ) ) );
			}
			n.appendChild( row );
		} );
		n.addEventListener( 'omc-pe-image', function () {
			n.querySelectorAll( '.omc-pe__spots' ).forEach( function ( sp ) {
				if ( sp.repaint ) {
					sp.repaint();
				}
			} );
		} );
		return n;
	}

	function listControl( f, slot ) {
		var n = h( 'div', 'omc-pe__list' );
		var paintIt = function () {
			var list = ( slot.get() || [] ).slice();
			n.innerHTML = '';
			list.forEach( function ( item, i ) {
				var card = h( 'div', 'omc-pe__item' );
				var head = h( 'div', 'omc-pe__item-head',
					'<span class="omc-pe__item-n">' + esc( ( f.item || '' ) + ' ' + ( i + 1 ) ) + '</span>' +
					'<span class="omc-pe__item-acts">' +
						'<button type="button" data-a="up" aria-label="' + esc( T.moveUp ) + '"' + ( i ? '' : ' disabled' ) + '>' + svg( 'up', 15 ) + '</button>' +
						'<button type="button" data-a="down" aria-label="' + esc( T.moveDown ) + '"' + ( i < list.length - 1 ? '' : ' disabled' ) + '>' + svg( 'down', 15 ) + '</button>' +
						'<button type="button" data-a="x" class="is-danger" aria-label="' + esc( T.remove ) + '"' + ( list.length > 1 ? '' : ' disabled' ) + '>' + svg( 'x', 15 ) + '</button>' +
					'</span>' );
				head.querySelectorAll( '[data-a]' ).forEach( function ( b ) {
					b.addEventListener( 'click', function () {
						var a = b.getAttribute( 'data-a' );
						var next = slot.get().slice();
						if ( 'x' === a ) {
							next.splice( i, 1 );
						} else {
							var j = 'up' === a ? i - 1 : i + 1;
							next[ i ] = list[ j ];
							next[ j ] = list[ i ];
						}
						slot.set( next );
						paintIt();
					} );
				} );
				card.appendChild( head );
				card.appendChild( groupControl( f, {
					get: function () {
						return ( slot.get() || [] )[ i ];
					},
					set: function ( v ) {
						var next = clone( slot.get() || [] );
						next[ i ] = v;
						slot.set( next );
					}
				} ) );
				n.appendChild( card );
			} );
			if ( list.length < ( f.max || 12 ) ) {
				var add = h( 'button', 'omc-pe__add', svg( 'plus', 15 ) + '<span>' + esc( fmt( T.add, ( f.item || '' ).toLowerCase() ) ) + '</span>' );
				add.type = 'button';
				add.addEventListener( 'click', function () {
					var blank = {};
					Object.keys( f.fields ).forEach( function ( k ) {
						var t = f.fields[ k ].type;
						blank[ k ] = 'image' === t ? 0 : ( 'select' === t ? Object.keys( f.fields[ k ].options )[ 0 ] : ( 'spots' === t ? [] : '' ) );
					} );
					slot.set( ( slot.get() || [] ).concat( [ blank ] ) );
					paintIt();
					var items = n.querySelectorAll( '.omc-pe__item' );
					items[ items.length - 1 ].scrollIntoView( { behavior: 'smooth', block: 'center' } );
				} );
				n.appendChild( add );
			}
		};
		paintIt();
		return n;
	}

	/* ─────────────────────────── hotspots ─────────────────────────── */

	function pieceOf( id ) {
		return pieces[ id ] || ( window.OMCPiecePicker && window.OMCPiecePicker.known[ id ] ) || null;
	}

	function round1( v ) {
		return Math.round( Math.max( 0, Math.min( 100, v ) ) * 10 ) / 10;
	}

	/*
	 * Points on one photograph: click where a piece is, choose it; drag a point to move it. Positions
	 * are percent of the photo's own frame, which is what the site works from after it crops the photo.
	 */
	function spotsControl( slot, imageOf ) {
		var n = h( 'div', 'omc-pe__spots' );
		var paintIt = function () {
			var list = ( Array.isArray( slot.get() ) ? slot.get() : [] ).map( function ( s ) {
				return { x: s.x, y: s.y, product: s.product };
			} );
			var img = imageOf();
			var m = media[ img ];
			n.innerHTML = '';
			if ( ! img || ! m ) {
				n.appendChild( h( 'p', 'omc-pe__help', esc( T.spotsNone ) ) );
				return;
			}
			var put = function ( next ) {
				slot.set( next.length ? next : [] );
				paintIt();
			};
			var stage = h( 'div', 'omc-pe__stage', '<img src="' + esc( m.large || m.thumb ) + '" alt="" draggable="false">' );
			list.forEach( function ( s, i ) {
				var p = pieceOf( s.product );
				var dot = h( 'button', 'omc-pe__dot', '<span>' + ( i + 1 ) + '</span>' );
				dot.type = 'button';
				dot.style.left = s.x + '%';
				dot.style.top = s.y + '%';
				dot.title = p ? p.name : T.unknown;
				dot.addEventListener( 'pointerdown', function ( e ) {
					e.preventDefault();
					e.stopPropagation();
					var r = stage.getBoundingClientRect();
					var moved = false;
					var move = function ( ev ) {
						moved = true;
						s.x = round1( ( ev.clientX - r.left ) / r.width * 100 );
						s.y = round1( ( ev.clientY - r.top ) / r.height * 100 );
						dot.style.left = s.x + '%';
						dot.style.top = s.y + '%';
					};
					var up = function () {
						document.removeEventListener( 'pointermove', move );
						document.removeEventListener( 'pointerup', up );
						if ( moved ) {
							put( list );
						}
					};
					document.addEventListener( 'pointermove', move );
					document.addEventListener( 'pointerup', up );
				} );
				dot.addEventListener( 'click', function ( e ) {
					e.stopPropagation();
				} );
				stage.appendChild( dot );
			} );
			stage.addEventListener( 'click', function ( e ) {
				if ( list.length >= 12 ) {
					window.alert( T.spotsMax );
					return;
				}
				var r = stage.getBoundingClientRect();
				var s = { x: round1( ( e.clientX - r.left ) / r.width * 100 ), y: round1( ( e.clientY - r.top ) / r.height * 100 ), product: 0 };
				var pending = h( 'span', 'omc-pe__dot is-pending', '<span>' + ( list.length + 1 ) + '</span>' );
				pending.style.left = s.x + '%';
				pending.style.top = s.y + '%';
				stage.appendChild( pending );
				if ( ! window.OMCPiecePicker ) {
					return;
				}
				window.OMCPiecePicker.open( pending, function ( p ) {
					pieces[ p.id ] = p;
					s.product = p.id;
					put( list.concat( [ s ] ) );
				}, function () {
					pending.remove();
				} );
			} );
			n.appendChild( stage );

			var ol = h( 'ol', 'omc-pe__spotlist' );
			if ( ! list.length ) {
				ol.appendChild( h( 'li', 'omc-pe__spotempty', esc( T.spotsEmpty ) ) );
			}
			list.forEach( function ( s, i ) {
				var p = pieceOf( s.product );
				var li = h( 'li', 'omc-pe__spotrow',
					'<span class="omc-pe__spotn">' + ( i + 1 ) + '</span>' +
					( p && p.thumb ? '<img src="' + esc( p.thumb ) + '" alt="">' : '<span class="omc-pe__spotph">' + svg( 'tag', 16 ) + '</span>' ) +
					'<span class="omc-pe__spotname">' + esc( p ? p.name : T.unknown ) + ( p && p.price ? '<small>' + esc( p.price ) + '</small>' : '' ) + '</span>' +
					'<button type="button" class="omc-pe__btn" data-a="change">' + esc( T.changePc ) + '</button>' +
					'<button type="button" class="omc-pe__iconbtn is-danger" data-a="x" aria-label="' + esc( T.remove ) + '">' + svg( 'x', 14 ) + '</button>' );
				li.querySelector( '[data-a="change"]' ).addEventListener( 'click', function ( e ) {
					if ( window.OMCPiecePicker ) {
						window.OMCPiecePicker.open( e.currentTarget, function ( np ) {
							pieces[ np.id ] = np;
							list[ i ].product = np.id;
							put( list );
						} );
					}
				} );
				li.querySelector( '[data-a="x"]' ).addEventListener( 'click', function () {
					list.splice( i, 1 );
					put( list );
				} );
				ol.appendChild( li );
			} );
			n.appendChild( ol );
		};
		paintIt();
		n.repaint = paintIt;
		return n;
	}

	/*
	 * Points on several photographs: the hero's slides, or the journal's photos. One photo at a time
	 * is open for editing; the strip above says how many points each one has.
	 */
	function spotmapControl( f, slot ) {
		var n = h( 'div', 'omc-pe__spotmap' );
		var current = 0;
		var mapOf = function () {
			var v = slot.get();
			return v && ! Array.isArray( v ) && 'object' === typeof v ? v : {};
		};
		var photos = function () {
			if ( f.images ) {
				return ( state[ tab ][ f.images ] || [] ).map( function ( id ) {
					return { image: id, label: '' };
				} );
			}
			return f.choices || [];
		};
		var paintIt = function () {
			var list = photos();
			n.innerHTML = '';
			if ( ! list.length ) {
				n.appendChild( h( 'p', 'omc-pe__help', esc( T.noPhotos ) ) );
				return;
			}
			if ( ! list.some( function ( c ) {
				return c.image === current;
			} ) ) {
				current = list[ 0 ].image;
			}
			var strip = h( 'div', 'omc-pe__spotstrip' );
			list.forEach( function ( c ) {
				var count = ( mapOf()[ c.image ] || [] ).length;
				var b = h( 'button', 'omc-pe__spotthumb' + ( c.image === current ? ' is-on' : '' ),
					picture( c.image ) + ( count ? '<span class="omc-pe__spotcount">' + esc( fmt( T.spotsCount, count ) ) + '</span>' : '' ) +
					( c.label ? '<span class="omc-pe__spotlabel">' + esc( c.label ) + '</span>' : '' ) );
				b.type = 'button';
				b.addEventListener( 'click', function () {
					current = c.image;
					paintIt();
				} );
				strip.appendChild( b );
			} );
			n.appendChild( strip );
			n.appendChild( spotsControl( {
				get: function () {
					return mapOf()[ current ] || [];
				},
				set: function ( v ) {
					var map = clone( mapOf() );
					if ( v && v.length ) {
						map[ current ] = v;
					} else {
						delete map[ current ];
					}
					// Empty is [] on the server too, so an emptied map reads as unchanged.
					slot.set( Object.keys( map ).length ? map : [] );
					var counts = strip.querySelectorAll( '.omc-pe__spotthumb' );
					list.forEach( function ( c, i ) {
						var k = ( ( Object.keys( map ).length ? map : {} )[ c.image ] || [] ).length;
						var badge = counts[ i ].querySelector( '.omc-pe__spotcount' );
						if ( k && ! badge ) {
							badge = h( 'span', 'omc-pe__spotcount' );
							counts[ i ].appendChild( badge );
						}
						if ( badge ) {
							badge.textContent = k ? fmt( T.spotsCount, k ) : '';
							badge.hidden = ! k;
						}
					} );
				}
			}, function () {
				return current;
			} ) );
		};
		paintIt();
		if ( f.images ) {
			app.addEventListener( 'omc-pe-image', function () {
				if ( document.body.contains( n ) ) {
					paintIt();
				}
			} );
		}
		return n;
	}

	/* ─────────────────────────── saving ─────────────────────────── */

	function dirty() {
		return Object.keys( state ).some( function ( page ) {
			return JSON.stringify( state[ page ] ) !== saved[ page ];
		} );
	}

	function status( text, tone ) {
		var d = dirty();
		el.status.textContent = text || ( d ? T.unsaved : T.saved );
		el.status.className = 'omc-pe__status is-' + ( tone || ( d ? 'dirty' : 'ok' ) );
		el.go.disabled = busy || ! d;
		app.classList.toggle( 'is-dirty', d );
	}

	function save() {
		if ( busy || ! dirty() ) {
			return;
		}
		busy = true;
		el.go.textContent = T.saving;
		status( T.saving, 'busy' );
		window.fetch( D.rest, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': D.nonce },
			body: JSON.stringify( { pages: state } )
		} ).then( function ( r ) {
			return r.json().then( function ( j ) {
				if ( ! r.ok ) {
					throw new Error( ( j && j.message ) || T.failed );
				}
				return j;
			} );
		} ).then( function ( j ) {
			Object.keys( j.media || {} ).forEach( function ( id ) {
				media[ id ] = j.media[ id ];
			} );
			Object.keys( j.values || {} ).forEach( function ( page ) {
				// What the server kept is what the page shows; a field it put back to the default
				// (an emptied one) now reads as the default here too.
				var before = JSON.stringify( state[ page ] );
				state[ page ] = j.values[ page ];
				saved[ page ] = JSON.stringify( state[ page ] );
				if ( page === tab && before !== saved[ page ] ) {
					var y = window.scrollY;
					paint();
					window.scrollTo( 0, y );
				}
			} );
			busy = false;
			el.go.textContent = T.save;
			refresh();
			status( T.saved, 'ok' );
		} ).catch( function ( e ) {
			busy = false;
			el.go.textContent = T.save;
			status( T.failed + ': ' + e.message, 'bad' );
			el.go.disabled = false;
		} );
	}

	el.go.addEventListener( 'click', save );
	document.addEventListener( 'keydown', function ( e ) {
		if ( ( e.ctrlKey || e.metaKey ) && 's' === e.key.toLowerCase() ) {
			e.preventDefault();
			save();
		}
	} );
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( dirty() ) {
			e.preventDefault();
			e.returnValue = T.leave;
			return T.leave;
		}
	} );

	/* WordPress prints the editor's own scripts (TinyMCE and its settings) after this one, still
	   before the page is ready — so the first paint, which may start the About story's editor,
	   waits for that. */
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', paint );
	} else {
		paint();
	}
}() );
