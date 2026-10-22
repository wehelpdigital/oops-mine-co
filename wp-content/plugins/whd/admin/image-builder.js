/**
 * Image Builder: product photographs in, new pictures out.
 *
 * Three steps on one form — the photos (dropped, uploaded or picked from the media library), how
 * many images, and each image's own set-up: on a model or a ghost mannequin, and a prompt. Then the
 * same chain the product screen runs for its new pictures, through the same server steps (run here
 * with no product): the photographs read edge to edge, the details cut out and counted again, then
 * for every image a drawing, a check of it against the real photographs, and one more drawing with
 * the mistakes named if the check found any — the better of the two kept, the other deleted.
 *
 * Every image lands in the media library as it is made, and in the gallery under the form, which
 * also shows what was made here before.
 */
( function () {
	'use strict';

	var D = window.WHD_IB || {};
	var T = D.i18n || {};
	var Kit = window.WHDAIKit;
	var root = document.getElementById( 'whd-ib' );
	if ( ! root || ! Kit || ! D.rest ) {
		return;
	}

	var esc = Kit.esc;
	var fmt = Kit.fmt;
	var client = Kit.client( { root: D.rest.root, nonce: D.rest.nonce, jobs: D.rest.jobs, last: D.rest.last, strings: T } );
	var api = client.api;
	var lightbox = Kit.lightbox( T );
	var loader = Kit.loader( T );
	var MAX = parseInt( D.max, 10 ) || 8;
	var PHOTOS = parseInt( D.photos, 10 ) || 6;
	var PROMPT_MAX = 400;
	var TYPES = [ 'image/jpeg', 'image/png', 'image/webp' ];

	var photos = [];        // [ { id, thumb, view, large, busy } ] — busy while it uploads
	var count = 4;
	var shots = [];         // [ { mode, ask } ] — kept when the count changes, so nothing typed is lost
	var facts = null;       // the reading of the current photographs, kept for the next run and "Draw again"
	var factsFor = '';
	var busy = false;
	var items = [];         // the gallery: [ { pic, verdict, shot, position, ids, facts, error, fresh, tile } ]

	var el = {
		drop: document.getElementById( 'whd-ib-drop' ),
		empty: document.getElementById( 'whd-ib-drop-empty' ),
		file: document.getElementById( 'whd-ib-file' ),
		photos: document.getElementById( 'whd-ib-photos' ),
		photosMsg: document.getElementById( 'whd-ib-photos-msg' ),
		count: document.getElementById( 'whd-ib-count' ),
		shots: document.getElementById( 'whd-ib-shots' ),
		sumPhotos: document.getElementById( 'whd-ib-sum-photos' ),
		sumP: document.getElementById( 'whd-ib-sum-p' ),
		sumI: document.getElementById( 'whd-ib-sum-i' ),
		sumT: document.getElementById( 'whd-ib-sum-t' ),
		go: document.getElementById( 'whd-ib-go' ),
		goLabel: document.getElementById( 'whd-ib-go-label' ),
		why: document.getElementById( 'whd-ib-why' ),
		tiles: document.getElementById( 'whd-ib-tiles' ),
		none: document.getElementById( 'whd-ib-empty' )
	};

	var ICON = {
		model: '<circle cx="12" cy="5" r="2.4"/><path d="M8.5 21 10 13.5 8 11l2.2-2.6h3.6L16 11l-2 2.5 1.5 7.5"/><path d="M10.2 16h3.6"/>',
		mannequin: '<path d="M9 3.8c.8 1.4 1.8 2 3 2s2.2-.6 3-2l4.5 2.4-1.8 4.6-2.2-1V20.5H8.5V9.8l-2.2 1-1.8-4.6z" stroke-dasharray="2.4 1.8"/>',
		view: '<path d="M14 4h6v6"/><path d="M10 20H4v-6"/><path d="m20 4-7 7"/><path d="m4 20 7-7"/>',
		download: '<path d="M12 4v11"/><path d="m7.5 10.5 4.5 4.5 4.5-4.5"/><path d="M5 19.5h14"/>',
		redo: '<path d="M20 11.5A8 8 0 1 1 17.7 6"/><path d="M20.5 4v4.5H16"/>',
		library: '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m20.5 16-4.5-4.5L7 19.5"/>',
		tweak: '<path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
		trash: '<path d="M4.5 7h15"/><path d="M9.5 7V4.8h5V7"/><path d="M6.5 7l.9 12.2a1.5 1.5 0 0 0 1.5 1.3h6.2a1.5 1.5 0 0 0 1.5-1.3L17.5 7"/>',
		ok: '<path d="m5 12.5 4.2 4.2L19 7"/>',
		warn: '<path d="M12 8v5"/><path d="M12 16.5v.5"/>',
		plus: '<path d="M12 5v14M5 12h14"/>',
		x: '<path d="M7 7l10 10M17 7 7 17"/>',
		copy: '<rect x="8.5" y="8.5" width="11" height="11" rx="2"/><path d="M15.5 8.5V6a1.5 1.5 0 0 0-1.5-1.5H6A1.5 1.5 0 0 0 4.5 6v8A1.5 1.5 0 0 0 6 15.5h2.5"/>'
	};

	function svg( name, size ) {
		size = size || 16;
		return '<svg viewBox="0 0 24 24" width="' + size + '" height="' + size + '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + ICON[ name ] + '</svg>';
	}

	/* One string for one, the other for more — the two forms the server sends. */
	function plural( pair, n ) {
		return fmt( Array.isArray( pair ) ? pair[ 1 === n ? 0 : 1 ] : pair, n );
	}

	function modeName( m ) {
		return 'mannequin' === m ? T.mannequin : T.onModel;
	}

	/* ─────────────────────────── 1. photos ─────────────────────────── */

	function fromAttachment( m ) {
		var a = m.toJSON ? m.toJSON() : m;
		var s = a.sizes || {};
		var pick = function () {
			for ( var i = 0; i < arguments.length; i++ ) {
				if ( s[ arguments[ i ] ] ) {
					return s[ arguments[ i ] ].url;
				}
			}
			return a.url;
		};
		return { id: a.id, thumb: pick( 'thumbnail', 'medium' ), view: pick( 'medium', 'large' ), large: pick( 'large', 'full' ) };
	}

	/* What the REST media endpoint answers with, in the same shape. */
	function fromRest( j ) {
		var s = ( j.media_details && j.media_details.sizes ) || {};
		var pick = function () {
			for ( var i = 0; i < arguments.length; i++ ) {
				if ( s[ arguments[ i ] ] ) {
					return s[ arguments[ i ] ].source_url;
				}
			}
			return j.source_url;
		};
		return { id: j.id, thumb: pick( 'thumbnail', 'medium' ), view: pick( 'medium', 'large' ), large: pick( 'large', 'full' ) };
	}

	function photoMsg( text, bad ) {
		el.photosMsg.textContent = text || '';
		el.photosMsg.classList.toggle( 'is-bad', !! bad );
	}

	function paintPhotos() {
		var has = photos.length > 0;
		el.empty.hidden = has;
		el.photos.hidden = ! has;
		el.drop.classList.toggle( 'has-photos', has );
		el.photos.innerHTML = '';
		photos.forEach( function ( p, i ) {
			var fig = document.createElement( 'figure' );
			fig.className = 'whd-ib__thumb' + ( p.busy ? ' is-busy' : '' );
			fig.innerHTML = '<button type="button" class="whd-ib__thumb-view" aria-label="' + esc( fmt( T.viewPhoto, i + 1 ) ) + '"' + ( p.busy ? ' disabled' : '' ) + '><img src="' + esc( p.thumb ) + '" alt=""></button>' +
				'<span class="whd-ib__thumb-n" aria-hidden="true">' + ( i + 1 ) + '</span>' +
				( p.busy ? '<span class="whd-ib__thumb-busy"><span class="whd-ib__spin" aria-hidden="true"></span><span class="screen-reader-text">' + esc( T.uploading ) + '</span></span>' :
					'<button type="button" class="whd-ib__thumb-x" aria-label="' + esc( T.removePhoto ) + '">' + svg( 'x', 14 ) + '</button>' );
			if ( ! p.busy ) {
				fig.querySelector( '.whd-ib__thumb-view' ).addEventListener( 'click', function () {
					var ready = photos.filter( function ( q ) {
						return ! q.busy;
					} );
					lightbox.open( ready.map( function ( q ) {
						return { src: q.large || q.view || q.thumb, caption: fmt( T.photoN, photos.indexOf( q ) + 1 ) };
					} ), ready.indexOf( p ), this );
				} );
				fig.querySelector( '.whd-ib__thumb-x' ).addEventListener( 'click', function () {
					photos.splice( photos.indexOf( p ), 1 );
					photoMsg( '' );
					paintPhotos();
					( el.photos.querySelector( '.whd-ib__thumb-x' ) || el.drop.querySelector( '[data-upload]' ) ).focus();
				} );
			}
			el.photos.appendChild( fig );
		} );
		if ( has && photos.length < PHOTOS ) {
			var add = document.createElement( 'div' );
			add.className = 'whd-ib__thumb-add';
			add.innerHTML = '<button type="button" data-upload>' + svg( 'plus', 18 ) + '<span>' + esc( T.addMore ) + '</span></button>' +
				'<button type="button" data-library>' + svg( 'library', 16 ) + '<span>' + esc( T.library ) + '</span></button>';
			el.photos.appendChild( add );
		}
		paintSummary();
	}

	function room() {
		return Math.max( 0, PHOTOS - photos.length );
	}

	function upload( files ) {
		var list = Array.prototype.slice.call( files || [] );
		var bad = list.filter( function ( f ) {
			return TYPES.indexOf( f.type ) === -1;
		} );
		var good = list.filter( function ( f ) {
			return TYPES.indexOf( f.type ) !== -1;
		} );
		if ( bad.length ) {
			photoMsg( fmt( T.notImage, bad[ 0 ].name ), true );
		} else {
			photoMsg( '' );
		}
		if ( good.length > room() ) {
			photoMsg( fmt( T.photoLimit, PHOTOS ), true );
			good = good.slice( 0, room() );
		}
		good.forEach( function ( file ) {
			var local = window.URL ? URL.createObjectURL( file ) : '';
			var p = { id: 0, thumb: local, view: local, large: local, busy: true };
			photos.push( p );
			var body = new FormData();
			body.append( 'file', file, file.name );
			window.fetch( D.rest.media, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': D.rest.nonce },
				body: body
			} ).then( function ( r ) {
				return r.text().then( function ( raw ) {
					var j = null;
					try {
						j = JSON.parse( raw );
					} catch ( e ) {
						throw new Error( T.error + ' (HTTP ' + r.status + ')' );
					}
					if ( ! r.ok || ! j || ! j.id ) {
						throw new Error( ( j && j.message ) || T.error );
					}
					return j;
				} );
			} ).then( function ( j ) {
				Object.assign( p, fromRest( j ), { busy: false } );
			} ).catch( function ( e ) {
				var at = photos.indexOf( p );
				if ( at !== -1 ) {
					photos.splice( at, 1 );
				}
				photoMsg( fmt( T.uploadFailed, file.name, e.message ), true );
			} ).then( function () {
				if ( local ) {
					// The library's own copy is shown now; the one in memory can go once it has loaded.
					window.setTimeout( function () {
						URL.revokeObjectURL( local );
					}, 4000 );
				}
				paintPhotos();
			} );
		} );
		paintPhotos();
	}

	function pick() {
		if ( ! window.wp || ! wp.media ) {
			return;
		}
		var frame = wp.media( { title: T.library, button: { text: T.useThese }, library: { type: 'image' }, multiple: 'add' } );
		frame.on( 'open', function () {
			var selection = frame.state().get( 'selection' );
			photos.forEach( function ( p ) {
				if ( p.id ) {
					var a = wp.media.attachment( p.id );
					a.fetch();
					selection.add( a );
				}
			} );
		} );
		frame.on( 'select', function () {
			var chosen = frame.state().get( 'selection' ).map( fromAttachment );
			var uploading = photos.filter( function ( p ) {
				return p.busy;
			} );
			photoMsg( chosen.length + uploading.length > PHOTOS ? fmt( T.photoLimit, PHOTOS ) : '', chosen.length + uploading.length > PHOTOS );
			photos = chosen.slice( 0, Math.max( 0, PHOTOS - uploading.length ) ).concat( uploading );
			paintPhotos();
		} );
		frame.open();
	}

	el.drop.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( 'button' );
		if ( ! b ) {
			return;
		}
		if ( b.hasAttribute( 'data-upload' ) ) {
			el.file.click();
		} else if ( b.hasAttribute( 'data-library' ) ) {
			pick();
		}
	} );
	el.file.addEventListener( 'change', function () {
		upload( el.file.files );
		el.file.value = '';
	} );

	/* Dragging files over the zone: a counter, because entering a child fires leave on the parent. */
	var depth = 0;
	function hasFiles( e ) {
		return e.dataTransfer && Array.prototype.indexOf.call( e.dataTransfer.types || [], 'Files' ) !== -1;
	}
	el.drop.addEventListener( 'dragenter', function ( e ) {
		if ( hasFiles( e ) ) {
			e.preventDefault();
			depth++;
			el.drop.classList.add( 'is-over' );
		}
	} );
	el.drop.addEventListener( 'dragover', function ( e ) {
		if ( hasFiles( e ) ) {
			e.preventDefault();
			e.dataTransfer.dropEffect = 'copy';
		}
	} );
	el.drop.addEventListener( 'dragleave', function () {
		depth = Math.max( 0, depth - 1 );
		if ( ! depth ) {
			el.drop.classList.remove( 'is-over' );
		}
	} );
	el.drop.addEventListener( 'drop', function ( e ) {
		if ( ! hasFiles( e ) ) {
			return;
		}
		e.preventDefault();
		depth = 0;
		el.drop.classList.remove( 'is-over' );
		upload( e.dataTransfer.files );
	} );
	// A file dropped just beside the zone would otherwise open in the tab, and the form with it.
	[ 'dragover', 'drop' ].forEach( function ( type ) {
		window.addEventListener( type, function ( e ) {
			if ( hasFiles( e ) && ! el.drop.contains( e.target ) ) {
				e.preventDefault();
			}
		} );
	} );

	/* ─────────────────────────── 2. how many ─────────────────────────── */

	function paintCount() {
		el.count.innerHTML = '';
		for ( var n = 1; n <= MAX; n++ ) {
			var label = document.createElement( 'label' );
			label.className = 'whd-ib__count-n' + ( n === count ? ' is-on' : '' );
			label.innerHTML = '<input type="radio" name="whd-ib-count" value="' + n + '"' + ( n === count ? ' checked' : '' ) + '><span>' + n + '</span>';
			label.querySelector( 'input' ).addEventListener( 'change', function () {
				count = parseInt( this.value, 10 );
				paintCount();
				paintShots();
				el.count.querySelector( 'input:checked' ).focus();
			} );
			el.count.appendChild( label );
		}
	}

	root.querySelectorAll( '[data-all]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			var m = b.getAttribute( 'data-all' );
			shots.forEach( function ( s ) {
				s.mode = m;
			} );
			paintShots();
		} );
	} );

	/* ─────────────────────────── 3. each image ─────────────────────────── */

	/* What an image left blank is asked to be: the nth model shot the nth model view, and so on. */
	function blank( i ) {
		var m = shots[ i ].mode;
		var nth = 0;
		for ( var j = 0; j < i; j++ ) {
			if ( shots[ j ].mode === m ) {
				nth++;
			}
		}
		var list = ( 'mannequin' === m ? T.blankGhost : T.blankModel ) || [];
		return list.length ? list[ nth % list.length ] : '';
	}

	function paintShots() {
		while ( shots.length < count ) {
			shots.push( { mode: 'model', ask: '' } );
		}
		el.shots.innerHTML = '';
		for ( var i = 0; i < count; i++ ) {
			el.shots.appendChild( shotCard( i ) );
		}
		paintSummary();
	}

	function shotCard( i ) {
		var s = shots[ i ];
		var card = document.createElement( 'div' );
		card.className = 'whd-ib__shot is-' + s.mode;
		var id = 'whd-ib-ask-' + i;
		var name = 'whd-ib-mode-' + i;
		card.innerHTML =
			'<div class="whd-ib__shot-head">' +
				'<span class="whd-ib__shot-n">' + esc( fmt( T.imageN, i + 1 ) ) + '</span>' +
				( count > 1 ? '<button type="button" class="whd-ib__link" data-copy title="' + esc( T.copyAllLong ) + '" aria-label="' + esc( T.copyAllLong ) + '">' + svg( 'copy', 14 ) + '<span aria-hidden="true">' + esc( T.copyAll ) + '</span></button>' : '' ) +
			'</div>' +
			'<div class="whd-ib__seg" role="radiogroup" aria-label="' + esc( fmt( T.imageN, i + 1 ) ) + '">' +
				[ 'model', 'mannequin' ].map( function ( m ) {
					return '<label class="whd-ib__seg-opt' + ( s.mode === m ? ' is-on' : '' ) + '">' +
						'<input type="radio" name="' + name + '" value="' + m + '"' + ( s.mode === m ? ' checked' : '' ) + '>' +
						svg( m, 20 ) + '<span>' + esc( modeName( m ) ) + '</span></label>';
				} ).join( '' ) +
			'</div>' +
			'<div class="whd-ib__field">' +
				'<label class="whd-ib__label" for="' + id + '">' + esc( T.prompt ) + ' <span class="whd-ib__opt">' + esc( T.optional ) + '</span></label>' +
				'<textarea id="' + id + '" class="whd-ib__ask" rows="3" maxlength="' + PROMPT_MAX + '"></textarea>' +
				'<div class="whd-ib__ask-foot"><span class="whd-ib__count-chars" aria-hidden="true"></span><button type="button" class="whd-ib__link whd-ib__clear" data-clear>' + esc( T.clear ) + '</button></div>' +
			'</div>' +
			'<div class="whd-ib__chips"></div>';

		var ask = card.querySelector( 'textarea' );
		var chars = card.querySelector( '.whd-ib__count-chars' );
		var clear = card.querySelector( '[data-clear]' );
		var copy = card.querySelector( '[data-copy]' );
		ask.value = s.ask;
		var sync = function () {
			chars.textContent = ask.value.length ? ask.value.length + ' / ' + PROMPT_MAX : '';
			clear.hidden = ! ask.value.length;
			// Only a prompt with something in it is worth copying.
			if ( copy && ! copy.classList.contains( 'is-done' ) ) {
				copy.hidden = ! ask.value.trim();
			}
		};
		var hint = function () {
			ask.placeholder = fmt( T.blankIs, blank( i ) );
		};
		hint();
		sync();
		ask.addEventListener( 'input', function () {
			s.ask = ask.value;
			sync();
		} );
		clear.addEventListener( 'click', function () {
			ask.value = '';
			s.ask = '';
			sync();
			ask.focus();
		} );

		var chipBox = card.querySelector( '.whd-ib__chips' );
		var paintChips = function () {
			var list = ( 'mannequin' === s.mode ? T.chipsGhost : T.chipsModel ) || [];
			chipBox.innerHTML = list.map( function ( c, k ) {
				return '<button type="button" class="whd-ib__chip" data-chip="' + k + '">' + svg( 'plus', 12 ) + esc( c ) + '</button>';
			} ).join( '' );
			chipBox.querySelectorAll( '[data-chip]' ).forEach( function ( b ) {
				b.addEventListener( 'click', function () {
					var text = list[ +b.getAttribute( 'data-chip' ) ];
					var now = ask.value.trim().replace( /[.,;]+$/, '' );
					var add = now ? ', ' + text.charAt( 0 ).toLowerCase() + text.slice( 1 ) : text;
					if ( ( now + add ).length <= PROMPT_MAX ) {
						ask.value = now + add;
						s.ask = ask.value;
						sync();
					}
					ask.focus();
				} );
			} );
		};
		paintChips();

		card.querySelectorAll( 'input[type="radio"]' ).forEach( function ( r ) {
			r.addEventListener( 'change', function () {
				s.mode = r.value;
				card.className = 'whd-ib__shot is-' + s.mode;
				card.querySelectorAll( '.whd-ib__seg-opt' ).forEach( function ( o ) {
					o.classList.toggle( 'is-on', o.querySelector( 'input' ).checked );
				} );
				paintChips();
				// Every blank one after this may now be the nth of a different kind: say so.
				el.shots.querySelectorAll( '.whd-ib__ask' ).forEach( function ( t, k ) {
					t.placeholder = fmt( T.blankIs, blank( k ) );
				} );
				paintSummary();
			} );
		} );

		if ( copy ) {
			copy.addEventListener( 'click', function () {
				var label = copy.querySelector( 'span' );
				copy.classList.add( 'is-done' );
				label.textContent = T.copiedAll;
				el.shots.querySelectorAll( '.whd-ib__ask' ).forEach( function ( t ) {
					t.value = s.ask;
					t.dispatchEvent( new Event( 'input' ) );
				} );
				window.setTimeout( function () {
					label.textContent = T.copyAll;
					copy.classList.remove( 'is-done' );
					sync();
				}, 1800 );
			} );
		}
		return card;
	}

	/* ─────────────────────────── the summary ─────────────────────────── */

	function why() {
		if ( ! D.ready ) {
			return T.needReady;
		}
		if ( ! photos.length ) {
			return T.needPhotos;
		}
		if ( photos.some( function ( p ) {
			return p.busy;
		} ) ) {
			return T.waitUpload;
		}
		return '';
	}

	function seconds( n ) {
		return ( facts && factsFor === key() ? 0 : 70 ) + 55 * n;
	}

	function paintSummary() {
		var ready = photos.filter( function ( p ) {
			return ! p.busy;
		} );
		el.sumPhotos.innerHTML = photos.length
			? photos.slice( 0, 4 ).map( function ( p ) {
				return '<img src="' + esc( p.thumb ) + '" alt="">';
			} ).join( '' )
			: '<span class="whd-ib__sum-ph">' + svg( 'library', 26 ) + '</span>';
		el.sumPhotos.classList.toggle( 'is-empty', ! photos.length );
		el.sumP.textContent = photos.length ? plural( T.sumPhotos, photos.length ) : T.sumNone;
		var models = shots.slice( 0, count ).filter( function ( s ) {
			return 'model' === s.mode;
		} ).length;
		var parts = [];
		if ( models ) {
			parts.push( esc( fmt( T.sumModel, models ) ) );
		}
		if ( count - models ) {
			parts.push( esc( fmt( T.sumGhost, count - models ) ) );
		}
		el.sumI.innerHTML = parts.join( '<br>' );
		var s = seconds( count );
		el.sumT.textContent = fmt( T.sumTime, Math.max( 1, Math.floor( s / 60 ) ), Math.ceil( s / 60 ) + 1 );
		el.goLabel.textContent = plural( T.generateN, count );
		var reason = why();
		el.go.disabled = busy || !! reason;
		el.why.textContent = busy ? '' : reason;
		el.why.classList.toggle( 'is-ok', ! reason && ready.length > 0 );
	}

	/* ─────────────────────────── generating ─────────────────────────── */

	function key() {
		return photos.map( function ( p ) {
			return p.id;
		} ).join( ',' );
	}

	function ids() {
		return photos.map( function ( p ) {
			return p.id;
		} );
	}

	/* What one image is asked to be. Its prompt is both the brief and the shop's own words, which win. */
	function shotFor( i ) {
		var s = shots[ i ];
		var own = s.ask.trim().replace( /\s+/g, ' ' );
		return { mode: s.mode, ask: own.slice( 0, 500 ), brief: ( own || blank( i ) ).replace( /\.?$/, '.' ) };
	}

	function step( label, hint ) {
		loader.step( label, hint );
	}

	/* Read the photographs once per set of them: the whole look, then the close-ups as a second opinion. */
	function read() {
		if ( facts && factsFor === key() ) {
			return Promise.resolve( facts );
		}
		step( T.stepLook, T.hintLook );
		return api( 'look', { images: ids() } ).then( function ( f ) {
			loader.tick();
			step( T.stepZoom, T.hintZoom );
			return api( 'inspect', { facts: f } ).catch( function () {
				return f; // a failed zoom still leaves the first look, which is good enough to draw from
			} );
		} ).then( function ( f ) {
			loader.tick();
			facts = f;
			factsFor = key();
			return f;
		} );
	}

	/* Draw, check, and draw once more with the mistakes named; the better kept, the other deleted. */
	function drawOne( item ) {
		var body = { images: item.ids, facts: item.facts, shot: item.shot, position: item.position, name: item.facts && item.facts.garment ? item.facts.garment : '' };
		var check = function ( pic ) {
			return api( 'check', { image: pic.id, images: item.ids, facts: item.facts, shot: item.shot } ).catch( function ( e ) {
				return { ok: false, score: 0, problems: [], unchecked: e.message };
			} );
		};
		var best = null;
		item.state = 'drawing';
		paintTile( item );
		return api( 'draw', body ).then( function ( pic ) {
			loader.note( T.stepCheck );
			return check( pic ).then( function ( verdict ) {
				best = { pic: pic, verdict: verdict };
				if ( verdict.ok || ! ( verdict.problems || [] ).length ) {
					return null;
				}
				loader.note( T.stepRedraw );
				return api( 'draw', Object.assign( {}, body, { fixes: verdict.problems } ) ).then( function ( again ) {
					return check( again ).then( function ( second ) {
						var better = second.ok || ( ! second.unchecked && ( second.score > verdict.score ||
							( second.score === verdict.score && ( second.problems || [] ).length < verdict.problems.length ) ) );
						api( 'discard', { image: ( better ? pic : again ).id } ).catch( function () {} );
						if ( better ) {
							best = { pic: again, verdict: second };
						}
					} );
				}, function () {
					return null; // the second try failing leaves the first, which is still a picture
				} );
			} );
		} ).then( function () {
			item.pic = best.pic;
			item.verdict = best.verdict;
			item.error = '';
			loader.picture( best.pic.thumb || best.pic.url );
		} ).catch( function ( e ) {
			item.error = e.message;
		} ).then( function () {
			item.state = '';
			paintTile( item );
		} );
	}

	function lock( on ) {
		busy = on;
		root.classList.toggle( 'is-busy', on );
		paintSummary();
	}

	function generate() {
		if ( busy || why() ) {
			paintSummary();
			return;
		}
		lock( true );
		var fresh = ! facts || factsFor !== key();
		loader.open( photos[ 0 ].view || photos[ 0 ].thumb, ( fresh ? 2 : 0 ) + count, seconds( count ) );
		var seed = Math.floor( Math.random() * 997 ); // who models, and where, differs run to run
		var batch = [];
		for ( var i = 0; i < count; i++ ) {
			batch.push( { shot: shotFor( i ), n: i + 1, position: seed + i + 1, ids: ids(), state: 'waiting', fresh: true } );
		}
		// Newest first: the run's images go in at the top, in order.
		items = batch.concat( items );
		paintGallery();
		read().then( function ( f ) {
			batch.forEach( function ( item ) {
				item.facts = f;
			} );
			return batch.reduce( function ( chain, item, j ) {
				return chain.then( function () {
					step( fmt( T.stepImage, j + 1, batch.length ), 'mannequin' === item.shot.mode ? T.hintGhost : T.hintModel );
					return drawOne( item ).then( function () {
						loader.tick();
					} );
				} );
			}, Promise.resolve() );
		} ).then( function () {
			var made = batch.filter( function ( item ) {
				return item.pic;
			} ).length;
			if ( ! made ) {
				throw new Error( batch[ 0 ].error || T.error );
			}
			loader.finish( true, T.genDone, function () {
				el.tiles.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			} );
		} ).catch( function ( e ) {
			if ( ! batch[ 0 ].facts ) {
				// The photos were never read, so nothing was drawn: the loader says why, the gallery stays as it was.
				items = items.filter( function ( item ) {
					return batch.indexOf( item ) === -1;
				} );
				paintGallery();
			} else {
				batch.forEach( function ( item ) {
					if ( ! item.pic ) {
						item.error = item.error || e.message;
						item.state = '';
						paintTile( item );
					}
				} );
			}
			loader.finish( false, e.message );
		} ).then( function () {
			lock( false );
		} );
	}

	el.go.addEventListener( 'click', generate );

	/* ─────────────────────────── the gallery ─────────────────────────── */

	function paintGallery() {
		el.tiles.innerHTML = '';
		var earlier = false;
		items.forEach( function ( item ) {
			if ( ! item.fresh && ! earlier && items.some( function ( x ) {
				return x.fresh;
			} ) ) {
				earlier = true;
				var h = document.createElement( 'p' );
				h.className = 'whd-ib__earlier';
				h.textContent = T.earlier;
				el.tiles.appendChild( h );
			}
			item.tile = document.createElement( 'figure' );
			el.tiles.appendChild( item.tile );
			paintTile( item );
		} );
		el.none.hidden = items.length > 0;
	}

	function good( item ) {
		return item.verdict && item.verdict.ok && ! item.verdict.unchecked;
	}

	function paintTile( item ) {
		var t = item.tile;
		if ( ! t ) {
			return;
		}
		var mode = item.shot ? item.shot.mode : item.pic.mode;
		var ask = item.shot ? item.shot.ask : ( item.pic && item.pic.ask ) || '';
		// A blank prompt made this run shows what it was asked for instead, quietly.
		var said = ask || ( item.shot ? item.shot.brief : '' );
		var meta = '<div class="whd-ib__tile-meta"><span class="whd-ib__tag is-' + mode + '">' + svg( mode, 14 ) + esc( modeName( mode ) ) + '</span>' +
			( item.n ? '<span class="whd-ib__tile-n">' + esc( fmt( T.imageN, item.n ) ) + '</span>' : ( item.pic && item.pic.date ? '<span class="whd-ib__tile-n">' + esc( item.pic.date ) + '</span>' : '' ) ) + '</div>' +
			'<p class="whd-ib__tile-ask' + ( ask ? '' : ' is-none' ) + '">' + esc( said || T.noPrompt ) + '</p>';

		if ( ! item.pic && item.error ) {
			t.className = 'whd-ib__tile is-bad';
			t.innerHTML = '<div class="whd-ib__tile-img"><span class="whd-ib__tile-fail">' + svg( 'x', 28 ) + '</span></div>' +
				'<figcaption>' + meta + '<p class="whd-ib__tile-note">' + esc( T.failedDraw + ': ' + item.error ) + '</p>' +
				'<div class="whd-ib__acts">' +
					( item.facts ? '<button type="button" class="whd-ib__act is-wide" data-redo>' + svg( 'redo' ) + '<span>' + esc( T.redo ) + '</span></button>' : '' ) +
					'<button type="button" class="whd-ib__act" data-dismiss title="' + esc( T.dismiss ) + '">' + svg( 'x' ) + '<span class="screen-reader-text">' + esc( T.dismiss ) + '</span></button>' +
				'</div>' +
				'</figcaption>';
			bind( item );
			return;
		}
		if ( ! item.pic ) {
			t.className = 'whd-ib__tile is-busy' + ( 'drawing' === item.state ? ' is-drawing' : '' );
			t.innerHTML = '<div class="whd-ib__tile-img"><span class="whd-ib__shimmer" aria-hidden="true"></span><span class="whd-ib__tile-wait">' +
				( 'drawing' === item.state ? '<span class="whd-ib__spin" aria-hidden="true"></span>' : '' ) + esc( 'drawing' === item.state ? T.drawing : T.waiting ) + '</span></div>' +
				'<figcaption>' + meta + '</figcaption>';
			return;
		}

		var notes = ( item.verdict && item.verdict.problems || [] ).slice();
		if ( item.verdict && item.verdict.unchecked ) {
			notes.push( item.verdict.unchecked );
		}
		var badge = item.verdict
			? '<span class="whd-ib__badge is-' + ( good( item ) ? 'ok' : 'warn' ) + '">' + svg( good( item ) ? 'ok' : 'warn', 13 ) + esc( good( item ) ? T.matches : T.lookAgain ) + '</span>'
			: '';
		t.className = 'whd-ib__tile' + ( item.verdict ? ( good( item ) ? ' is-ok' : ' is-warn' ) : '' );
		t.innerHTML = '<div class="whd-ib__tile-img">' +
				'<button type="button" class="whd-ib__tile-view" data-view aria-label="' + esc( T.view ) + '"><img src="' + esc( item.pic.url || item.pic.large || item.pic.thumb ) + '" alt="" loading="lazy"></button>' +
				badge +
			'</div>' +
			'<figcaption>' + meta +
				( notes.length ? '<p class="whd-ib__tile-note">' + esc( notes.join( ' · ' ) ) + '</p>' : '' ) +
				'<div class="whd-ib__acts">' +
					'<button type="button" class="whd-ib__act" data-view title="' + esc( T.view ) + '">' + svg( 'view' ) + '<span class="screen-reader-text">' + esc( T.view ) + '</span></button>' +
					'<a class="whd-ib__act" href="' + esc( item.pic.full || item.pic.large ) + '" download title="' + esc( T.download ) + '">' + svg( 'download' ) + '<span class="screen-reader-text">' + esc( T.download ) + '</span></a>' +
					( item.pic.edit ? '<a class="whd-ib__act" href="' + esc( item.pic.edit ) + '" target="_blank" rel="noopener" title="' + esc( T.openLibrary ) + '">' + svg( 'library' ) + '<span class="screen-reader-text">' + esc( T.openLibrary ) + '</span></a>' : '' ) +
					( item.pic.tweak ? '<a class="whd-ib__act" href="' + esc( item.pic.tweak ) + '" target="_blank" rel="noopener" title="' + esc( T.tweak ) + '">' + svg( 'tweak' ) + '<span class="screen-reader-text">' + esc( T.tweak ) + '</span></a>' : '' ) +
					( item.facts ? '<button type="button" class="whd-ib__act" data-redo title="' + esc( T.redo ) + '">' + svg( 'redo' ) + '<span class="screen-reader-text">' + esc( T.redo ) + '</span></button>' : '' ) +
					( false !== item.pic.mine ? '<button type="button" class="whd-ib__act is-danger" data-delete title="' + esc( T.delete ) + '">' + svg( 'trash' ) + '<span class="screen-reader-text">' + esc( T.delete ) + '</span></button>' : '' ) +
				'</div>' +
			'</figcaption>';
		bind( item );
	}

	function bind( item ) {
		var t = item.tile;
		t.querySelectorAll( '[data-view]' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				var shown = items.filter( function ( x ) {
					return x.pic;
				} );
				lightbox.open( shown.map( function ( x ) {
					var mode = x.shot ? x.shot.mode : x.pic.mode;
					var ask = x.shot ? x.shot.ask : x.pic.ask;
					return {
						src: x.pic.large || x.pic.url,
						caption: modeName( mode ) + ( ask ? ' — ' + ask : '' ),
						state: x.verdict ? ( good( x ) ? T.matches : T.lookAgain ) : '',
						tone: x.verdict ? ( good( x ) ? 'ok' : 'warn' ) : '',
						notes: x.verdict ? x.verdict.problems || [] : []
					};
				} ), shown.indexOf( item ), b );
			} );
		} );
		var redo = t.querySelector( '[data-redo]' );
		if ( redo ) {
			redo.addEventListener( 'click', function () {
				again( item );
			} );
		}
		var dismiss = t.querySelector( '[data-dismiss]' );
		if ( dismiss ) {
			dismiss.addEventListener( 'click', function () {
				items.splice( items.indexOf( item ), 1 );
				paintGallery();
			} );
		}
		var del = t.querySelector( '[data-delete]' );
		if ( del ) {
			del.addEventListener( 'click', function () {
				if ( busy || ! window.confirm( T.confirmDel ) ) {
					return;
				}
				del.disabled = true;
				api( 'discard', { image: item.pic.id } ).then( function ( r ) {
					if ( ! r || ! r.deleted ) {
						throw new Error( T.notDeleted );
					}
					items.splice( items.indexOf( item ), 1 );
					paintGallery();
				} ).catch( function ( e ) {
					del.disabled = false;
					window.alert( e.message );
				} );
			} );
		}
	}

	/* Draw just this one again, from the photos and reading it was made with; the old one goes once the new one is in. */
	function again( item ) {
		if ( busy ) {
			return;
		}
		lock( true );
		var old = item.pic;
		var oldVerdict = item.verdict;
		item.pic = null;
		item.verdict = null;
		item.error = '';
		loader.open( old ? old.url || old.thumb : '', 1, 55 );
		step( item.n ? fmt( T.imageN, item.n ) : T.redo, 'mannequin' === item.shot.mode ? T.hintGhost : T.hintModel );
		drawOne( item ).then( function () {
			if ( item.pic && old && item.pic.id !== old.id ) {
				api( 'discard', { image: old.id } ).catch( function () {} );
			} else if ( ! item.pic && old ) {
				// Nothing new: the old one stays as it was.
				item.pic = old;
				item.verdict = oldVerdict;
				paintTile( item );
			}
			loader.tick();
			loader.finish( ! item.error, item.error || T.genDone );
		} ).then( function () {
			lock( false );
		} );
	}

	/* ─────────────────────────── start ─────────────────────────── */

	items = ( D.recent || [] ).map( function ( pic ) {
		return { pic: pic, fresh: false };
	} );
	paintPhotos();
	paintCount();
	paintShots();
	paintGallery();

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( busy ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );
}() );
