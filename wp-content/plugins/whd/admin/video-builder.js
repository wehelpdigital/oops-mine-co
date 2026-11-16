/**
 * Video Builder: product photographs in, a short product video out.
 *
 * The photos (dropped, uploaded or picked from the media library), what happens in the video, its shape,
 * length and kind. Then the chain: the photographs read edge to edge and the details cut out and counted
 * again (the studio's own look and inspect steps), the brief written from that checked spec and the
 * shop's choices, and the video made by Gemini Omni Flash — the screen asks after it every few seconds.
 * Every video lands in the media library as soon as it exists; a GIF or a boomerang is then made from it
 * in the browser (video-engine.js) and saved too, and the result opens in the Video Editor underneath.
 */
( function () {
	'use strict';

	var D = window.WHD_VB || {};
	var T = D.i18n || {};
	var Kit = window.WHDAIKit;
	var V = window.WHDVideo;
	var root = document.getElementById( 'whd-vb' );
	if ( ! root || ! Kit || ! D.rest ) {
		return;
	}

	var esc = Kit.esc;
	var fmt = Kit.fmt;
	var client = Kit.client( { root: D.rest.root, nonce: D.rest.nonce, jobs: D.rest.jobs, last: D.rest.last, strings: T } );
	var api = client.api;
	var lightbox = Kit.lightbox( T );
	var loader = Kit.loader( T );
	var O = D.options || {};
	var L = D.limits || { turn: 15, total: 30, min: 3, variations: 3 };
	var PHOTOS = parseInt( D.photos, 10 ) || 6;
	var TYPES = [ 'image/jpeg', 'image/png', 'image/webp' ];

	var photos = [];
	var facts = null;
	var factsFor = '';
	var busy = false;
	var runs = [];
	var tiles = ( D.recent || [] ).slice();
	var c = {
		style: 'twirl',
		show: 'model',
		camera: 'auto',
		place: 'auto',
		place_text: '',
		mood: 'auto',
		pace: 'medium',
		sound: ( D.defaults && D.defaults.sound ) || 'music_upbeat',
		orient: 'portrait',
		format: 'reel',
		purpose: 'reel',
		resolution: ( D.defaults && D.defaults.resolution ) || '720p',
		seconds: ( D.defaults && D.defaults.seconds ) || 8,
		output: 'video',
		variations: 1,
		query: '',
		details: '',
		words: ''
	};

	var el = {
		drop: document.getElementById( 'whd-vb-drop' ),
		empty: document.getElementById( 'whd-vb-drop-empty' ),
		file: document.getElementById( 'whd-vb-file' ),
		photos: document.getElementById( 'whd-vb-photos' ),
		photosMsg: document.getElementById( 'whd-vb-photos-msg' ),
		styles: document.getElementById( 'whd-vb-styles' ),
		query: document.getElementById( 'whd-vb-query' ),
		details: document.getElementById( 'whd-vb-details' ),
		words: document.getElementById( 'whd-vb-words' ),
		placeText: document.getElementById( 'whd-vb-place-text' ),
		placeWrap: document.getElementById( 'whd-vb-place-text-wrap' ),
		orient: document.getElementById( 'whd-vb-orient' ),
		format: document.getElementById( 'whd-vb-format' ),
		resolution: document.getElementById( 'whd-vb-resolution' ),
		seconds: document.getElementById( 'whd-vb-seconds' ),
		secondsOut: document.getElementById( 'whd-vb-seconds-out' ),
		secondsNote: document.getElementById( 'whd-vb-seconds-note' ),
		output: document.getElementById( 'whd-vb-output' ),
		gif: document.getElementById( 'whd-vb-gif' ),
		boom: document.getElementById( 'whd-vb-boom' ),
		variations: document.getElementById( 'whd-vb-variations' ),
		review: document.getElementById( 'whd-vb-review' ),
		sumPhotos: document.getElementById( 'whd-vb-sum-photos' ),
		shape: document.getElementById( 'whd-vb-shape' ),
		sumP: document.getElementById( 'whd-vb-sum-p' ),
		sumV: document.getElementById( 'whd-vb-sum-v' ),
		sumC: document.getElementById( 'whd-vb-sum-c' ),
		go: document.getElementById( 'whd-vb-go' ),
		goLabel: document.getElementById( 'whd-vb-go-label' ),
		why: document.getElementById( 'whd-vb-why' ),
		tiles: document.getElementById( 'whd-vb-tiles' ),
		none: document.getElementById( 'whd-vb-empty' ),
		edit: document.getElementById( 'whd-vb-edit' ),
		editor: document.getElementById( 'whd-ve' )
	};

	var ICON = {
		x: '<path d="M7 7l10 10M17 7 7 17"/>',
		plus: '<path d="M12 5v14M5 12h14"/>',
		library: '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m20.5 16-4.5-4.5L7 19.5"/>',
		download: '<path d="M12 4v11"/><path d="m7.5 10.5 4.5 4.5 4.5-4.5"/><path d="M5 19.5h14"/>',
		edit: '<path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
		trash: '<path d="M4.5 7h15"/><path d="M9.5 7V4.8h5V7"/><path d="M6.5 7l.9 12.2a1.5 1.5 0 0 0 1.5 1.3h6.2a1.5 1.5 0 0 0 1.5-1.3L17.5 7"/>',
		gif: '<rect x="3.5" y="6" width="17" height="12" rx="2"/><path d="M8.5 10.5H7v3h2v-1.2M12 10.5v3M15 13.5v-3h2M15 12h1.6"/>',
		boom: '<path d="M7 12c0-2.2 1.8-4 4-4h6"/><path d="m14.5 5.5 2.5 2.5-2.5 2.5"/><path d="M17 12c0 2.2-1.8 4-4 4H7"/><path d="m9.5 18.5-2.5-2.5 2.5-2.5"/>'
	};
	function svg( name, size ) {
		size = size || 16;
		return '<svg viewBox="0 0 24 24" width="' + size + '" height="' + size + '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + ICON[ name ] + '</svg>';
	}
	function plural( pair, n ) {
		return fmt( Array.isArray( pair ) ? pair[ 1 === n ? 0 : 1 ] : pair, n );
	}
	function seconds( s ) {
		var m = Math.floor( s / 60 );
		var r = Math.round( s % 60 );
		return m ? m + ':' + ( r < 10 ? '0' : '' ) + r : r + ' s';
	}

	/* The same rule as WHD_AI_Video::turns(): one turn up to 15 s, extensions of 3–10 s up to 30. */
	function turns( s ) {
		s = Math.max( L.min, Math.min( L.total, s ) );
		if ( s <= L.turn ) {
			return [ s ];
		}
		var count = 1 + Math.ceil( Math.max( 0, s - L.turn ) / 10 );
		var first = Math.max( 3, Math.min( L.turn, s - ( count - 1 ) * 10 ) );
		var out = [ first ];
		var rest = s - first;
		while ( rest > 0 ) {
			var next = Math.max( 3, Math.min( 10, rest ) );
			out.push( next );
			rest -= next;
		}
		return out;
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
		photoMsg( bad.length ? fmt( T.notImage, bad[ 0 ].name ) : '', !! bad.length );
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
			window.fetch( D.rest.media, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': D.rest.nonce }, body: body } ).then( function ( r ) {
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
					setTimeout( function () {
						URL.revokeObjectURL( local );
					}, 4000 );
				}
				paintPhotos();
			} );
		} );
		paintPhotos();
	}
	function pickPhotos() {
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
			pickPhotos();
		}
	} );
	el.file.addEventListener( 'change', function () {
		upload( el.file.files );
		el.file.value = '';
	} );
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
	[ 'dragover', 'drop' ].forEach( function ( type ) {
		window.addEventListener( type, function ( e ) {
			if ( hasFiles( e ) && ! el.drop.contains( e.target ) ) {
				e.preventDefault();
			}
		} );
	} );

	/* ─────────────────────────── 2. what happens ─────────────────────────── */

	var STYLE_ICON = { runway: '🚶‍♀️', twirl: '💃', flow: '🍃', orbit: '🔄', details: '🔍', street: '🏙️', grwm: '🪞', catalog: '📸', unbox: '🎁', transition: '✨', golden: '🌅', montage: '🎬', custom: '✍️' };

	function paintStyles() {
		el.styles.innerHTML = '';
		Object.keys( O.styles || {} ).forEach( function ( key ) {
			var label = document.createElement( 'label' );
			label.className = 'whd-vb__style' + ( key === c.style ? ' is-on' : '' );
			label.innerHTML = '<input type="radio" name="whd-vb-style" value="' + esc( key ) + '"' + ( key === c.style ? ' checked' : '' ) + '>' +
				'<span class="whd-vb__style-i" aria-hidden="true">' + ( STYLE_ICON[ key ] || '🎞️' ) + '</span><span class="whd-vb__style-l">' + esc( O.styles[ key ].label ) + '</span>';
			label.querySelector( 'input' ).addEventListener( 'change', function () {
				c.style = key;
				paintStyles();
				paintSummary();
			} );
			el.styles.appendChild( label );
		} );
	}

	Array.prototype.forEach.call( root.querySelectorAll( 'select[data-list]' ), function ( sel ) {
		var list = O[ sel.dataset.list ] || {};
		var key = { shows: 'show', cameras: 'camera', places: 'place', moods: 'mood', paces: 'pace', sounds: 'sound', purposes: 'purpose' }[ sel.dataset.list ];
		Object.keys( list ).forEach( function ( k ) {
			var o = document.createElement( 'option' );
			o.value = k;
			o.textContent = list[ k ].label;
			sel.appendChild( o );
		} );
		sel.value = c[ key ];
		sel.addEventListener( 'change', function () {
			c[ key ] = sel.value;
			if ( 'place' === key ) {
				el.placeWrap.hidden = 'custom' !== c.place;
			}
			if ( 'purpose' === key ) {
				// The shape a purpose usually wants.
				var want = { banner: [ 'landscape', 'banner' ], feed: [ 'portrait', 'feed' ], product: null }[ c.purpose ];
				if ( want ) {
					c.orient = want[ 0 ];
					c.format = want[ 1 ];
				} else if ( [ 'reel', 'tiktok', 'story', 'short', 'ad' ].indexOf( c.purpose ) !== -1 && 'landscape' === c.orient ) {
					c.orient = 'portrait';
					c.format = 'reel';
				}
				paintFormat();
			}
			paintSummary();
		} );
	} );
	[ [ el.query, 'query' ], [ el.details, 'details' ], [ el.words, 'words' ], [ el.placeText, 'place_text' ] ].forEach( function ( pair ) {
		pair[ 0 ].addEventListener( 'input', function () {
			c[ pair[ 1 ] ] = pair[ 0 ].value;
			paintSummary();
		} );
	} );

	/* ─────────────────────────── 3. shape, length and kind ─────────────────────────── */

	function seg( box, items, value, onPick ) {
		box.innerHTML = '';
		items.forEach( function ( it ) {
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'whd-vb__seg-b' + ( String( it[ 0 ] ) === String( value ) ? ' is-on' : '' );
			b.setAttribute( 'role', 'radio' );
			b.setAttribute( 'aria-checked', String( it[ 0 ] ) === String( value ) ? 'true' : 'false' );
			b.innerHTML = it[ 1 ];
			b.addEventListener( 'click', function () {
				onPick( it[ 0 ] );
			} );
			box.appendChild( b );
		} );
	}

	function paintFormat() {
		seg( el.orient, [ [ 'portrait', '<span class="whd-vb__o whd-vb__o--p" aria-hidden="true"></span>' + esc( T.portrait ) ], [ 'landscape', '<span class="whd-vb__o whd-vb__o--l" aria-hidden="true"></span>' + esc( T.landscape ) ] ], c.orient, function ( v ) {
			c.orient = v;
			var f = O.formats[ c.format ];
			if ( ! f || f.orient.indexOf( v ) === -1 ) {
				c.format = 'portrait' === v ? 'reel' : 'landscape';
			}
			paintFormat();
			paintSummary();
		} );
		el.format.innerHTML = '';
		Object.keys( O.formats || {} ).forEach( function ( k ) {
			var f = O.formats[ k ];
			if ( f.orient.indexOf( c.orient ) === -1 ) {
				return;
			}
			var o = document.createElement( 'option' );
			o.value = k;
			o.textContent = f.label + ' · ' + f.w + ' × ' + f.h;
			el.format.appendChild( o );
		} );
		el.format.value = c.format;
	}
	el.format.addEventListener( 'change', function () {
		c.format = el.format.value;
		paintSummary();
	} );

	Object.keys( D.resLabels || {} ).forEach( function ( k ) {
		var o = document.createElement( 'option' );
		o.value = k;
		o.textContent = D.resLabels[ k ] + ' · $' + Number( D.rates[ k ] ).toFixed( 3 ) + '/s';
		el.resolution.appendChild( o );
	} );
	el.resolution.value = c.resolution;
	el.resolution.addEventListener( 'change', function () {
		c.resolution = el.resolution.value;
		paintSummary();
	} );

	el.seconds.min = L.min;
	el.seconds.max = L.total;
	el.seconds.value = c.seconds;
	el.seconds.addEventListener( 'input', function () {
		c.seconds = parseInt( el.seconds.value, 10 ) || 8;
		paintSeconds();
		paintSummary();
	} );
	function paintSeconds() {
		el.secondsOut.textContent = fmt( T.secondsN, c.seconds );
		var parts = turns( c.seconds );
		el.secondsNote.textContent = parts.length > 1 ? fmt( T.madeInParts, parts.length ) + ' (' + parts.join( ' + ' ) + ' s)' : T.oneTurn;
	}

	function paintOutput() {
		seg( el.output, Object.keys( O.outputs || {} ).map( function ( k ) {
			return [ k, esc( O.outputs[ k ].label ) ];
		} ), c.output, function ( v ) {
			c.output = v;
			// A GIF or a boomerang is one short motion: keep it short unless the shop says otherwise.
			if ( ( 'gif' === v || 'boomerang' === v ) && c.seconds > 6 ) {
				c.seconds = 'gif' === v ? 5 : 4;
				el.seconds.value = c.seconds;
				paintSeconds();
			}
			paintOutput();
			paintSummary();
		} );
		el.gif.hidden = 'gif' !== c.output;
		el.boom.hidden = 'boomerang' !== c.output;
	}

	function paintVariations() {
		var items = [];
		for ( var n = 1; n <= L.variations; n++ ) {
			items.push( [ n, String( n ) ] );
		}
		seg( el.variations, items, c.variations, function ( v ) {
			c.variations = v;
			paintVariations();
			paintSummary();
		} );
	}

	/* ─────────────────────────── the summary ─────────────────────────── */

	function estimate() {
		return ( Number( D.rates[ c.resolution ] ) || 0 ) * c.seconds * c.variations;
	}

	function paintSummary() {
		var ready = photos.filter( function ( p ) {
			return ! p.busy;
		} );
		el.sumPhotos.innerHTML = ready.slice( 0, 6 ).map( function ( p ) {
			return '<img src="' + esc( p.thumb ) + '" alt="">';
		} ).join( '' );
		el.sumP.textContent = ready.length ? String( ready.length ) : '—';
		var f = O.formats[ c.format ] || { w: 1080, h: 1920, label: '' };
		el.shape.firstChild.style.aspectRatio = f.w + ' / ' + f.h;
		el.shape.firstChild.textContent = f.w + '×' + f.h;
		el.sumV.textContent = c.seconds + ' s · ' + ( O.outputs[ c.output ] || {} ).label + ' · ' + c.resolution + ( c.variations > 1 ? ' · ×' + c.variations : '' );
		el.sumC.textContent = fmt( T.costAbout, estimate().toFixed( 2 ) );
		el.goLabel.textContent = plural( T.generateN, c.variations );
		var why = '';
		if ( ! D.ready || ! D.reader ) {
			why = T.needReady;
		} else if ( ! photos.length ) {
			why = T.needPhotos;
		} else if ( photos.some( function ( p ) {
			return p.busy;
		} ) ) {
			why = T.waitUpload;
		} else if ( 'custom' === c.place && ! c.place_text.trim() ) {
			why = T.needPlace;
		} else if ( 'custom' === c.style && ! c.query.trim() ) {
			why = T.needQuery;
		}
		el.why.textContent = why;
		el.go.disabled = busy || !! why;
	}

	/* ─────────────────────────── making it ─────────────────────────── */

	function ids() {
		return photos.filter( function ( p ) {
			return p.id && ! p.busy;
		} ).map( function ( p ) {
			return p.id;
		} );
	}

	function choices() {
		return Object.assign( {}, c );
	}

	function size() {
		var f = O.formats[ c.format ] || { w: 1080, h: 1920 };
		return { w: f.w, h: f.h };
	}

	/* The brief, shown before anything is spent, when the shop asked to see it. */
	function review( text ) {
		return new Promise( function ( resolve, reject ) {
			var d = document.createElement( 'dialog' );
			d.className = 'whd-vb__review';
			d.innerHTML = '<form method="dialog"><h2>' + esc( T.stepReview ) + '</h2><p>' + esc( T.reviewNote ) + '</p>' +
				'<textarea rows="18"></textarea><p class="whd-vb__review-btns"><button type="button" class="button" data-no>' + esc( T.cancel ) + '</button> ' +
				'<button type="button" class="button button-primary" data-yes>' + esc( T.filmIt ) + '</button></p></form>';
			d.querySelector( 'textarea' ).value = text;
			document.body.appendChild( d );
			d.addEventListener( 'cancel', function ( e ) {
				e.preventDefault();
			} );
			d.querySelector( '[data-no]' ).addEventListener( 'click', function () {
				d.close();
				d.remove();
				reject( new Error( T.stopped ) );
			} );
			d.querySelector( '[data-yes]' ).addEventListener( 'click', function () {
				var v = d.querySelector( 'textarea' ).value;
				d.close();
				d.remove();
				resolve( v );
			} );
			d.showModal();
		} );
	}

	/* A Stop button inside the studio's loader, which otherwise has none on purpose: filming takes minutes. */
	function stopButton( onStop ) {
		var dialog = document.querySelector( 'dialog.whd-studio-loader' );
		if ( ! dialog ) {
			return;
		}
		var p = document.createElement( 'p' );
		p.className = 'whd-vb__stop';
		p.innerHTML = '<button type="button" class="button">' + esc( T.stop ) + '</button>';
		p.firstChild.addEventListener( 'click', function () {
			p.firstChild.disabled = true;
			onStop();
		} );
		dialog.appendChild( p );
	}

	/* Ask after one run until it is done, saying how it is getting on. */
	function follow( run, label ) {
		return new Promise( function ( resolve, reject ) {
			var misses = 0;
			var seenVideos = 0;
			( function poll() {
				if ( run.stopped ) {
					reject( new Error( T.stopped ) );
					return;
				}
				client.send( 'status', { run: run.id } ).then( function ( s ) {
					misses = 0;
					run.state = s;
					var parts = ( s.turns || [] ).length;
					if ( 'running' === s.status ) {
						var head = parts > 1 ? fmt( T.stepPart, ( s.turn || 0 ) + 1, parts ) : ( 'saving' === s.doing ? T.stepSaving : T.stepFilm );
						loader.step( ( label ? label + ' · ' : '' ) + head, T.hintFilm );
					}
					while ( seenVideos < ( s.videos || [] ).length ) {
						seenVideos++;
						loader.tick();
					}
					if ( 'done' === s.status ) {
						resolve( s );
						return;
					}
					if ( 'error' === s.status || 'cancelled' === s.status ) {
						reject( new Error( s.message || T.stopped ) );
						return;
					}
					setTimeout( poll, 5000 );
				}, function ( e ) {
					if ( ++misses > 6 ) {
						reject( e );
					} else {
						setTimeout( poll, 8000 );
					}
				} );
			}() );
		} );
	}

	function canConvert() {
		return V && V.canEncode();
	}

	/* A GIF of a library video, at the chosen width and frame rate, saved to the library. */
	function makeGif( clip, opts ) {
		var p = V.fromClips( [ clip ], opts.size || { w: clip.width || 1080, h: clip.height || 1920 } );
		p.name = ( clip.title || 'Video' ) + ' — GIF';
		return V.exportGif( p, { width: opts.width, fps: opts.fps, onProgress: opts.onProgress } ).then( function ( out ) {
			return V.upload( out.blob, { title: p.name, kind: 'gif', design: p, source: clip.id, poster: out.poster, made: true } );
		} );
	}

	/* A boomerang of a library video: forward and back, saved to the library as a video. */
	function makeBoomerang( clip, opts ) {
		var p = V.boomerang( clip, { repeat: opts.repeat, speed: opts.speed, size: opts.size } );
		return V.exportVideo( p, { onProgress: opts.onProgress } ).then( function ( out ) {
			return V.upload( out.blob, { title: p.name, kind: 'boomerang', design: p, source: clip.id, poster: out.poster, made: true } );
		} );
	}

	function gifOpts() {
		return { width: document.getElementById( 'whd-vb-gif-width' ).value, fps: document.getElementById( 'whd-vb-gif-fps' ).value };
	}
	function boomOpts() {
		return { repeat: document.getElementById( 'whd-vb-boom-repeat' ).value, speed: document.getElementById( 'whd-vb-boom-speed' ).value };
	}

	function generate() {
		if ( busy ) {
			return;
		}
		busy = true;
		paintSummary();
		var key = ids().join( ',' );
		var need = key !== factsFor || ! facts;
		var count = c.variations;
		var units = ( need ? 2 : 0 ) + count * turns( c.seconds ).length + ( 'gif' === c.output || 'boomerang' === c.output ? count : 0 );
		var first = photos[ 0 ];
		loader.open( first.view || first.thumb, units, ( need ? 90 : 0 ) + 120 + 30 * c.seconds / 8 );
		var made = [];
		runs = [];
		/*
		 * Stop counts whenever it is pressed: before a run has an id (it is cancelled the moment the id
		 * arrives), between the steps (the chain goes no further), and while a GIF or boomerang is made.
		 */
		var stop = { on: false };
		function cancelRun( r ) {
			r.stopped = true;
			client.send( 'cancel', { run: r.id } ).catch( function () {} );
		}
		function halt() {
			if ( stop.on ) {
				throw new Error( T.stopped );
			}
		}
		stopButton( function () {
			stop.on = true;
			runs.forEach( cancelRun );
		} );

		var chain = Promise.resolve();
		if ( need ) {
			chain = chain.then( function () {
				loader.step( T.stepLook, T.hintLook );
				return api( 'look', { images: ids(), choices: choices() } );
			} ).then( function ( f ) {
				halt();
				loader.tick();
				loader.step( T.stepZoom, T.hintZoom );
				return api( 'inspect', { facts: f, choices: choices() } ).catch( function () {
					return f; // the close-ups are a second opinion; the first reading stands without them
				} );
			} ).then( function ( f ) {
				loader.tick();
				facts = f;
				factsFor = key;
				halt();
			} );
		}
		var edited = '';
		chain = chain.then( function () {
			if ( ! el.review.checked ) {
				return null;
			}
			loader.step( T.stepBrief, '' );
			return client.send( 'prompt', { facts: facts, choices: choices() } ).then( function ( r ) {
				halt();
				return review( r.prompt );
			} ).then( function ( text ) {
				edited = text;
			} );
		} ).then( function () {
			halt();
			loader.step( T.stepFilm, T.hintFilm );
			var starts = [];
			for ( var v = 0; v < count; v++ ) {
				starts.push( ( function ( variant ) {
					return client.send( 'start', { choices: choices(), facts: facts, images: ids(), prompt: edited, variant: variant } ).then( function ( r ) {
						var run = { id: r.run, variant: variant };
						runs.push( run );
						if ( stop.on ) {
							cancelRun( run );
						}
						return run;
					} );
				}( v ) ) );
			}
			return Promise.all( starts );
		} ).then( function ( started ) {
			return Promise.all( started.map( function ( run, i ) {
				return follow( run, count > 1 ? fmt( T.stepVariant, i + 1, count ) : '' ).then( function ( s ) {
					( s.videos || [] ).forEach( function ( t ) {
						addTile( t, true );
					} );
					made.push( s );
					return s;
				} );
			} ) );
		} ).then( function ( done ) {
			// GIF and boomerang, from each finished video, in the browser.
			if ( 'gif' !== c.output && 'boomerang' !== c.output ) {
				return done;
			}
			if ( ! canConvert() ) {
				loader.note( T.needBrowser );
				return done;
			}
			var seq = Promise.resolve();
			done.forEach( function ( s ) {
				var last = ( s.videos || [] )[ s.videos.length - 1 ];
				if ( ! last ) {
					return;
				}
				seq = seq.then( function () {
					if ( stop.on ) {
						return;
					}
					loader.step( 'gif' === c.output ? T.stepGif : T.stepBoomerang, T.hintConvert );
					var opts = Object.assign( 'gif' === c.output ? gifOpts() : boomOpts(), { size: size() } );
					return ( 'gif' === c.output ? makeGif( last, opts ) : makeBoomerang( last, opts ) ).then( function ( saved ) {
						loader.tick();
						if ( saved && saved.tile ) {
							addTile( saved.tile, true );
							s.converted = saved;
						}
					} ).catch( function ( e ) {
						loader.note( fmt( T.convertFailed, e.message ) );
					} );
				} );
			} );
			return seq.then( function () {
				return done;
			} );
		} ).then( function ( done ) {
			busy = false;
			paintSummary();
			var partial = done.filter( function ( s ) {
				return s.partial;
			} )[ 0 ];
			loader.finish( true, partial ? partial.partial : T.genDone, function () {
				var s = done[ 0 ];
				if ( ! s ) {
					return;
				}
				openEditorFor( s );
			} );
		} ).catch( function ( e ) {
			// Whatever was filmed before it stopped is in the library already: show it.
			runs.forEach( function ( r ) {
				( ( r.state && r.state.videos ) || [] ).forEach( function ( t ) {
					addTile( t, true );
				} );
			} );
			busy = false;
			paintSummary();
			loader.finish( false, e.message );
		} );
	}

	/*
	 * Into the editor with what a run made. When Google sent the parts of a long video separately (the last
	 * part shorter than the whole), the parts go in one after another so one export makes the whole video.
	 */
	function openEditorFor( s ) {
		var vids = s.videos || [];
		if ( ! vids.length ) {
			return;
		}
		var last = vids[ vids.length - 1 ];
		var total = ( s.turns || [] ).reduce( function ( a, b ) {
			return a + b;
		}, 0 );
		var clips = [ last ];
		var note = '';
		if ( s.converted && 'boomerang' === c.output ) {
			clips = [ s.converted ];
		} else if ( vids.length > 1 && last.seconds && last.seconds < total * 0.8 ) {
			clips = vids;
			note = T.partsNote;
		}
		openEditor( clips, { exportAs: 'gif' === c.output ? 'gif' : 'mp4', note: note } );
	}

	function openEditor( clips, opts ) {
		if ( ! window.WHDVideoEditor || ! el.edit ) {
			return;
		}
		opts = opts || {};
		el.edit.hidden = false;
		var sub = el.edit.querySelector( '.whd-vb__note' );
		if ( opts.note ) {
			if ( ! sub ) {
				sub = document.createElement( 'p' );
				sub.className = 'whd-vb__note';
				el.edit.querySelector( '.whd-ib__results-head' ).appendChild( sub );
			}
			sub.textContent = opts.note;
		} else if ( sub ) {
			sub.remove();
		}
		window.WHDVideoEditor.open( el.editor, {
			clips: clips,
			size: size(),
			made: true,
			exportAs: opts.exportAs,
			onSaved: function ( saved ) {
				if ( saved && saved.tile ) {
					addTile( saved.tile, true );
				}
			}
		} );
		setTimeout( function () {
			el.edit.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}, 60 );
	}
	el.edit && el.edit.querySelector( '[data-vb-edit-close]' ).addEventListener( 'click', function () {
		el.edit.hidden = true;
		var ed = window.WHDVideoEditor && window.WHDVideoEditor.current();
		if ( ed ) {
			ed.player.pause();
		}
	} );

	el.go.addEventListener( 'click', generate );

	/* ─────────────────────────── the videos ─────────────────────────── */

	function addTile( t, fresh ) {
		if ( ! t || ! t.id ) {
			return;
		}
		tiles = tiles.filter( function ( x ) {
			return x.id !== t.id;
		} );
		t.fresh = !! fresh;
		tiles.unshift( t );
		paintTiles();
		cover( t );
	}

	/*
	 * A cover for a video Google made: the server cannot read video frames (no ffmpeg on the host), so the
	 * browser draws one from the file — a quarter of the way in, at most 1 s — and it is saved as the
	 * video's featured image, which the library, these tiles and the editor all show. One at a time.
	 */
	var covering = {};
	var coverQueue = Promise.resolve();
	function cover( t ) {
		if ( ! t || t.poster || ! t.mine || covering[ t.id ] || 0 !== String( t.mime ).indexOf( 'video/' ) ) {
			return;
		}
		covering[ t.id ] = true;
		coverQueue = coverQueue.then( function () {
			return frameOf( t.url ).then( function ( data ) {
				return client.send( 'cover', { id: t.id, data: data } );
			} ).then( function ( fresh ) {
				if ( fresh && fresh.poster ) {
					t.poster = fresh.poster;
					var v = el.tiles.querySelector( '[data-id="' + t.id + '"] video' );
					if ( v ) {
						v.poster = fresh.poster;
					}
				}
			} ).catch( function () {} ); // no cover is not a failure: the tile still plays
		} );
	}
	function frameOf( url ) {
		return new Promise( function ( resolve, reject ) {
			var v = document.createElement( 'video' );
			var done = false;
			var timer = setTimeout( function () {
				finish( new Error( 'timeout' ) );
			}, 20000 );
			function finish( err, data ) {
				if ( done ) {
					return;
				}
				done = true;
				clearTimeout( timer );
				v.removeAttribute( 'src' );
				v.load();
				if ( err ) {
					reject( err );
				} else {
					resolve( data );
				}
			}
			v.muted = true;
			v.playsInline = true;
			v.preload = 'auto';
			v.addEventListener( 'loadedmetadata', function () {
				v.currentTime = Math.min( 1, ( v.duration || 2 ) * 0.25 );
			} );
			v.addEventListener( 'seeked', function () {
				try {
					var s = Math.min( 1, 960 / Math.max( v.videoWidth, v.videoHeight ) );
					var c = document.createElement( 'canvas' );
					c.width = Math.max( 1, Math.round( v.videoWidth * s ) );
					c.height = Math.max( 1, Math.round( v.videoHeight * s ) );
					c.getContext( '2d' ).drawImage( v, 0, 0, c.width, c.height );
					finish( null, c.toDataURL( 'image/jpeg', 0.86 ) );
				} catch ( e ) {
					finish( e ); // a file served from another host taints the canvas
				}
			} );
			v.addEventListener( 'error', function () {
				finish( new Error( 'video' ) );
			} );
			v.src = url;
		} );
	}

	function kindName( k ) {
		return { gif: T.kindGif, boomerang: T.kindBoomerang, edit: T.kindEdit }[ k ] || T.kindRaw;
	}

	function paintTiles() {
		el.tiles.innerHTML = '';
		el.none.hidden = tiles.length > 0;
		tiles.forEach( function ( t ) {
			var card = document.createElement( 'article' );
			card.className = 'whd-vb__tile' + ( t.fresh ? ' is-fresh' : '' );
			card.setAttribute( 'data-id', String( t.id ) );
			var gif = 'image/gif' === t.mime;
			var ratio = t.width && t.height ? t.width + ' / ' + t.height : '9 / 16';
			card.innerHTML = '<div class="whd-vb__tile-media" style="aspect-ratio:' + ratio + '">' +
				( gif ? '<img src="' + esc( t.url ) + '" alt="" loading="lazy">' :
					'<video src="' + esc( t.url ) + '" ' + ( t.poster ? 'poster="' + esc( t.poster ) + '" ' : '' ) + 'muted loop playsinline preload="metadata"></video>' ) +
				'<span class="whd-vb__badge">' + esc( kindName( t.kind ) ) + '</span></div>' +
				'<div class="whd-vb__tile-body"><p class="whd-vb__tile-t">' + esc( t.title ) + '</p>' +
				'<p class="whd-vb__tile-m">' + esc( [ t.seconds ? seconds( t.seconds ) : '', t.width ? t.width + '×' + t.height : '', t.size || '', t.date || '' ].filter( Boolean ).join( ' · ' ) ) + '</p>' +
				( t.prompt ? '<details class="whd-vb__brief"><summary>' + esc( T.showBrief ) + '</summary><pre>' + esc( t.prompt ) + '</pre></details>' : '' ) +
				'<div class="whd-vb__tile-acts">' +
					( gif ? '' : '<button type="button" class="button button-primary" data-a="edit">' + svg( 'edit', 14 ) + ' ' + esc( T.edit ) + '</button>' ) +
					'<a class="button" href="' + esc( t.url ) + '" download>' + svg( 'download', 14 ) + '<span class="screen-reader-text">' + esc( T.download ) + '</span></a>' +
					( t.library ? '<a class="button" href="' + esc( t.library ) + '" target="_blank" rel="noopener" title="' + esc( T.openLibrary ) + '">' + svg( 'library', 14 ) + '<span class="screen-reader-text">' + esc( T.openLibrary ) + '</span></a>' : '' ) +
					( gif ? '' : '<button type="button" class="button" data-a="gif" title="' + esc( T.makeGif ) + '">' + svg( 'gif', 14 ) + '<span class="screen-reader-text">' + esc( T.makeGif ) + '</span></button>' +
						'<button type="button" class="button" data-a="boom" title="' + esc( T.makeBoomerang ) + '">' + svg( 'boom', 14 ) + '<span class="screen-reader-text">' + esc( T.makeBoomerang ) + '</span></button>' ) +
					( t.mine ? '<button type="button" class="button whd-vb__del" data-a="del" title="' + esc( T.delete ) + '">' + svg( 'trash', 14 ) + '<span class="screen-reader-text">' + esc( T.delete ) + '</span></button>' : '' ) +
				'</div></div>';
			var v = card.querySelector( 'video' );
			if ( v ) {
				card.addEventListener( 'mouseenter', function () {
					v.play().catch( function () {} );
				} );
				card.addEventListener( 'mouseleave', function () {
					v.pause();
				} );
			}
			card.addEventListener( 'click', function ( e ) {
				var b = e.target.closest( '[data-a]' );
				if ( ! b ) {
					return;
				}
				var a = b.dataset.a;
				if ( 'edit' === a ) {
					openEditor( [ t ], {} );
				} else if ( 'gif' === a || 'boom' === a ) {
					convertTile( t, 'gif' === a ? 'gif' : 'boomerang' );
				} else if ( 'del' === a ) {
					if ( ! window.confirm( T.confirmDel ) ) {
						return;
					}
					client.send( 'delete', { id: t.id } ).then( function ( r ) {
						if ( r && r.deleted ) {
							tiles = tiles.filter( function ( x ) {
								return x.id !== t.id;
							} );
							paintTiles();
						} else {
							window.alert( T.notDeleted );
						}
					} ).catch( function () {
						window.alert( T.notDeleted );
					} );
				}
			} );
			el.tiles.appendChild( card );
		} );
	}

	function convertTile( t, kind ) {
		if ( ! canConvert() ) {
			window.alert( T.needBrowser );
			return;
		}
		loader.open( t.poster || '', 1, 40 );
		loader.step( 'gif' === kind ? T.stepGif : T.stepBoomerang, T.hintConvert );
		var opts = Object.assign( 'gif' === kind ? gifOpts() : boomOpts(), { size: t.width && t.height ? { w: t.width, h: t.height } : size() } );
		( 'gif' === kind ? makeGif( t, opts ) : makeBoomerang( t, opts ) ).then( function ( saved ) {
			loader.tick();
			if ( saved && saved.tile ) {
				addTile( saved.tile, true );
			}
			loader.finish( true, T.genDone );
		} ).catch( function ( e ) {
			loader.finish( false, fmt( T.convertFailed, e.message ) );
		} );
	}

	/* ─────────────────────────── start ─────────────────────────── */

	paintPhotos();
	paintStyles();
	paintFormat();
	paintSeconds();
	paintOutput();
	paintVariations();
	paintSummary();
	paintTiles();
	tiles.forEach( cover ); // videos made before covers were drawn
}() );
