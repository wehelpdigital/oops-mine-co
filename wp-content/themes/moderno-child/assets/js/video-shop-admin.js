/**
 * Page Editor → Shoppable videos (inc/video-shop.php, inc/video-writer.php): every style video and its page.
 *
 * "Add a video" turns a video from the media library into a video page; its cover is a frame of the
 * video itself, drawn here in the browser. Each video then has two tabs beside its player:
 *
 *   - Pieces in the video: "Add a piece here" takes the player's time and opens the shop search
 *     (assets/js/omc-piece-picker.js). Every moment can be retimed — typed, or set to wherever the
 *     player is — re-pointed at another piece, played from, or removed.
 *   - Page words: heading, intro, search title and snippet, keyphrase, alt text, chapters, sections
 *     and questions — typed, or written by AI: stills are taken from the video here and sent with what
 *     the shop explains; the answer comes back as a background job (polled at the WHD studio's job
 *     route, as every slow AI step there is) and lands in the form, unsaved.
 *
 * One Save sends every video's moments and the words of the videos whose words changed; the server
 * keeps them sorted and checked.
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
	var state = {}; // slug → moments
	var words = {}; // slug → page words
	var savedMoments = '';
	var savedWords = {};
	var busy = false;
	var leaving = false;
	var cards = [];

	function normal( c ) {
		c = c || {};
		var text = function ( v ) {
			return null == v ? '' : String( v );
		};
		return {
			title: text( c.title ),
			seo_title: text( c.seo_title ),
			meta_description: text( c.meta_description ),
			intro: text( c.intro ),
			alt: text( c.alt ),
			keyphrase: text( c.keyphrase ),
			chapters: ( c.chapters || [] ).map( function ( x ) {
				return { at: parseInt( x.at, 10 ) || 0, label: text( x.label ) };
			} ),
			sections: ( c.sections || [] ).map( function ( s ) {
				return { heading: text( s.heading ), paragraphs: ( s.paragraphs || [] ).map( text ), bullets: ( s.bullets || [] ).map( text ) };
			} ),
			faqs: ( c.faqs || [] ).map( function ( f ) {
				return { q: text( f.q ), a: text( f.a ) };
			} )
		};
	}

	( D.videos || [] ).forEach( function ( v ) {
		state[ v.slug ] = ( v.moments || [] ).map( function ( m ) {
			return { at: m.at, product: m.product, for: m.for || T.forDefault };
		} );
		words[ v.slug ] = normal( v.content );
		savedWords[ v.slug ] = JSON.stringify( words[ v.slug ] );
	} );
	savedMoments = JSON.stringify( state );

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

	function byTime( a, b ) {
		return a.at - b.at;
	}

	function api( url, body ) {
		return window.fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': D.nonce },
			body: JSON.stringify( body || {} )
		} ).then( function ( r ) {
			return r.text().then( function ( raw ) {
				var j = null;
				try {
					j = JSON.parse( raw );
				} catch ( e ) {
					throw new Error( r.ok ? T.failed : 'HTTP ' + r.status );
				}
				if ( ! r.ok ) {
					throw new Error( ( j && j.message ) || 'HTTP ' + r.status );
				}
				return j;
			} );
		} );
	}

	/** One frame of a video as a JPEG data URL, the longest side at most `edge`. */
	function grab( video, edge, quality ) {
		var w = video.videoWidth;
		var h = video.videoHeight;
		if ( ! w || ! h ) {
			return '';
		}
		var k = Math.min( 1, edge / Math.max( w, h ) );
		var c = document.createElement( 'canvas' );
		c.width = Math.round( w * k );
		c.height = Math.round( h * k );
		c.getContext( '2d' ).drawImage( video, 0, 0, c.width, c.height );
		return c.toDataURL( 'image/jpeg', quality );
	}

	/**
	 * Stills at even steps through a video — one every five seconds or so, 3 to 10 — taken from a
	 * player of its own, so the one on screen is not moved. Resolves [ { at, data } ].
	 */
	function stills( src ) {
		return new Promise( function ( resolve, reject ) {
			var vid = document.createElement( 'video' );
			var times = [];
			var shots = [];
			var i = 0;
			var done = false;
			var stop = function ( err ) {
				if ( done ) {
					return;
				}
				done = true;
				clearTimeout( timer );
				vid.removeAttribute( 'src' );
				vid.load();
				if ( err ) {
					reject( err );
				} else {
					resolve( shots );
				}
			};
			var timer = setTimeout( function () {
				stop( shots.length >= 2 ? null : new Error( T.aiNoStills ) );
			}, 90000 );
			var next = function () {
				if ( i >= times.length ) {
					stop( shots.length ? null : new Error( T.aiNoStills ) );
					return;
				}
				vid.currentTime = times[ i ];
			};
			var take = function () {
				try {
					var data = grab( vid, 768, 0.8 );
					if ( data ) {
						shots.push( { at: Math.round( times[ i ] ), data: data } );
					}
				} catch ( e ) {
					stop( new Error( T.aiNoStills ) );
					return;
				}
				i++;
				next();
			};
			vid.muted = true;
			vid.playsInline = true;
			vid.preload = 'auto';
			vid.addEventListener( 'error', function () {
				stop( new Error( T.aiNoStills ) );
			} );
			vid.addEventListener( 'loadedmetadata', function () {
				var d = vid.duration;
				if ( ! isFinite( d ) || d <= 0 ) {
					stop( new Error( T.aiNoStills ) );
					return;
				}
				var n = Math.max( 3, Math.min( 10, Math.round( d / 5 ) ) );
				for ( var k = 0; k < n; k++ ) {
					times.push( d * ( k + 0.5 ) / n );
				}
				next();
			}, { once: true } );
			vid.addEventListener( 'seeked', function () {
				if ( done ) {
					return;
				}
				if ( vid.readyState >= 2 ) {
					take();
				} else {
					vid.addEventListener( 'canplay', take, { once: true } );
				}
			} );
			vid.src = src;
			vid.load();
		} );
	}

	/** Ask after a background job until it is done (or failed, or has taken eight minutes). */
	function poll( job, onTick ) {
		var started = Date.now();
		return new Promise( function ( resolve, reject ) {
			( function tick() {
				api( D.job, { job: job } ).then( function ( s ) {
					if ( 'done' === s.status ) {
						resolve( s.result || {} );
					} else if ( 'error' === s.status ) {
						reject( new Error( s.message || T.aiFail ) );
					} else if ( Date.now() - started > 8 * 60000 ) {
						reject( new Error( T.aiTimeout ) );
					} else {
						onTick( ( Date.now() - started ) / 1000 );
						setTimeout( tick, 2500 );
					}
				} ).catch( reject );
			}() );
		} );
	}

	/* ─────────────────────────── the frame ─────────────────────────── */

	app.innerHTML = ( D.canAdd ? '<section class="omc-vsa__new"></section>' : '' ) +
		'<div class="omc-vsa__list"></div>' +
		'<div class="omc-vsa__save"><span class="omc-vsa__status" role="status" aria-live="polite"></span><button type="button" class="omc-vsa__go">' + esc( T.save ) + '</button></div>';
	var listEl = app.querySelector( '.omc-vsa__list' );
	var statusEl = app.querySelector( '.omc-vsa__status' );
	var goEl = app.querySelector( '.omc-vsa__go' );

	if ( ! ( D.videos || [] ).length ) {
		listEl.innerHTML = '<p class="omc-vsa__none">' + esc( T.noVideos ) + '</p>';
	}

	/* ─────────────────────────── add a video ─────────────────────────── */

	( function addVideo( box ) {
		if ( ! box ) {
			return;
		}
		box.innerHTML =
			'<button type="button" class="omc-vsa__newbtn"><span aria-hidden="true">+</span> ' + esc( T.addVideo ) + '</button>' +
			'<div class="omc-vsa__newform" hidden>' +
				'<header class="omc-vsa__newhead"><h2 class="omc-vsa__h">' + esc( T.addVideo ) + '</h2><button type="button" class="omc-vsa__ib omc-vsa__newx" aria-label="' + esc( T.cancel ) + '">×</button></header>' +
				'<p class="omc-vsa__note">' + esc( T.addLead ) + '</p>' +
				'<div class="omc-vsa__newgrid">' +
					'<div class="omc-vsa__newmedia">' +
						'<video class="omc-vsa__newvideo" controls playsinline muted preload="auto" hidden></video>' +
						'<button type="button" class="button omc-vsa__pickvid">' + esc( T.pickVideo ) + '</button>' +
					'</div>' +
					'<div class="omc-vsa__newside" hidden>' +
						'<label class="omc-vsa__field"><span class="omc-vsa__fl">' + esc( T.title ) + '</span><input type="text" class="omc-vsa__newtitle" maxlength="120"><span class="omc-vsa__fh">' + esc( T.titleHint ) + '</span></label>' +
						'<div class="omc-vsa__field"><span class="omc-vsa__fl">' + esc( T.poster ) + '</span>' +
							'<div class="omc-vsa__poster"><img alt="" hidden><button type="button" class="button omc-vsa__useframe">' + esc( T.useFrame ) + '</button></div>' +
							'<span class="omc-vsa__fh">' + esc( T.posterHint ) + '</span></div>' +
						'<fieldset class="omc-vsa__radios">' +
							'<label><input type="radio" name="omc-vsa-status" value="publish" checked> ' + esc( T.publishNow ) + '</label>' +
							'<label><input type="radio" name="omc-vsa-status" value="draft"> ' + esc( T.asDraft ) + '</label>' +
						'</fieldset>' +
						'<div class="omc-vsa__newacts"><button type="button" class="button button-primary omc-vsa__create">' + esc( T.create ) + '</button><span class="omc-vsa__newline" role="status" aria-live="polite"></span></div>' +
					'</div>' +
				'</div>' +
			'</div>';

		var open = box.querySelector( '.omc-vsa__newbtn' );
		var form = box.querySelector( '.omc-vsa__newform' );
		var preview = box.querySelector( '.omc-vsa__newvideo' );
		var pick = box.querySelector( '.omc-vsa__pickvid' );
		var side = box.querySelector( '.omc-vsa__newside' );
		var titleIn = box.querySelector( '.omc-vsa__newtitle' );
		var posterImg = box.querySelector( '.omc-vsa__poster img' );
		var create = box.querySelector( '.omc-vsa__create' );
		var line = box.querySelector( '.omc-vsa__newline' );
		var chosen = null;
		var poster = '';
		var frame = null;

		var say = function ( text, tone ) {
			line.textContent = text || '';
			line.className = 'omc-vsa__newline' + ( tone ? ' is-' + tone : '' );
		};
		var takePoster = function () {
			try {
				poster = grab( preview, 1600, 0.86 );
			} catch ( e ) {
				poster = '';
			}
			posterImg.hidden = ! poster;
			if ( poster ) {
				posterImg.src = poster;
				say( '' );
			} else {
				say( T.noFrame, 'bad' );
			}
		};

		open.addEventListener( 'click', function () {
			form.hidden = false;
			open.hidden = true;
			if ( ! chosen ) {
				pick.click();
			}
		} );
		box.querySelector( '.omc-vsa__newx' ).addEventListener( 'click', function () {
			form.hidden = true;
			open.hidden = false;
			preview.pause();
		} );
		pick.addEventListener( 'click', function () {
			if ( ! window.wp || ! window.wp.media ) {
				return;
			}
			if ( ! frame ) {
				frame = window.wp.media( { title: T.pickVideo, library: { type: 'video' }, button: { text: T.pickVideo }, multiple: false } );
				// Open on the library (the videos already there), not the upload tab; uploading is one click away.
				frame.on( 'open', function () {
					frame.content.mode( 'browse' );
				} );
				frame.on( 'select', function () {
					var a = frame.state().get( 'selection' ).first().toJSON();
					if ( ! a || 0 !== String( a.mime || '' ).indexOf( 'video/' ) ) {
						say( T.notVideo, 'bad' );
						return;
					}
					chosen = a;
					poster = '';
					posterImg.hidden = true;
					titleIn.value = a.title || '';
					preview.hidden = false;
					side.hidden = false;
					pick.textContent = T.otherVideo;
					say( '' );
					// The first cover: a second in (or a tenth of the way, for a very short video).
					preview.addEventListener( 'loadedmetadata', function () {
						preview.currentTime = Math.min( 1, ( preview.duration || 0 ) * 0.1 );
						preview.addEventListener( 'seeked', takePoster, { once: true } );
					}, { once: true } );
					preview.src = a.url;
					preview.load();
				} );
			}
			frame.open();
		} );
		box.querySelector( '.omc-vsa__useframe' ).addEventListener( 'click', function () {
			preview.pause();
			takePoster();
		} );
		create.addEventListener( 'click', function () {
			if ( ! chosen || create.disabled ) {
				return;
			}
			if ( dirty() && ! window.confirm( T.leaveAdd ) ) {
				return;
			}
			if ( ! poster && preview.readyState >= 2 ) {
				takePoster();
			}
			create.disabled = true;
			say( T.creating, 'busy' );
			var status = box.querySelector( 'input[name="omc-vsa-status"]:checked' );
			api( D.create, {
				attachment: chosen.id,
				title: titleIn.value,
				poster: poster,
				width: preview.videoWidth || 0,
				height: preview.videoHeight || 0,
				duration: isFinite( preview.duration ) ? preview.duration : 0,
				status: status ? status.value : 'publish'
			} ).then( function ( j ) {
				leaving = true;
				// Back on this screen with the new video open on its words: the next thing to do.
				window.location.hash = 'words-' + j.slug;
				window.location.reload();
			} ).catch( function ( e ) {
				create.disabled = false;
				say( T.failed + ': ' + e.message, 'bad' );
			} );
		} );
		if ( ! ( D.videos || [] ).length ) {
			open.click();
		}
	}( app.querySelector( '.omc-vsa__new' ) ) );

	/* ─────────────────────────── a video ─────────────────────────── */

	( D.videos || [] ).forEach( function ( v ) {
		var card = document.createElement( 'section' );
		var tab = 'pieces';
		card.className = 'omc-vsa__video';
		card.id = 'omc-v-' + v.slug;
		card.innerHTML =
			'<header class="omc-vsa__vhead">' +
				'<div class="omc-vsa__vtitle"><h2 class="omc-vsa__h"></h2><span class="omc-vsa__pill"></span></div>' +
				'<div class="omc-vsa__vacts">' + ( D.canAdd ? '<button type="button" class="button omc-vsa__pub"></button>' : '' ) + '<a class="omc-vsa__view" target="_blank" rel="noopener"></a></div>' +
			'</header>' +
			'<div class="omc-vsa__grid">' +
				'<div class="omc-vsa__player"><video controls playsinline preload="metadata"' + ( v.poster ? ' poster="' + esc( v.poster ) + '"' : '' ) + ' src="' + esc( v.src ) + '"></video>' +
					'<p class="omc-vsa__chh"></p><div class="omc-vsa__chips"></div>' +
				'</div>' +
				'<div class="omc-vsa__side">' +
					'<div class="omc-vsa__tabs" role="tablist">' +
						'<button type="button" role="tab" class="omc-vsa__tab" data-tab="pieces">' + esc( T.tabPieces ) + ' <span class="omc-vsa__count"></span></button>' +
						'<button type="button" role="tab" class="omc-vsa__tab" data-tab="words">' + esc( T.tabWords ) + '</button>' +
					'</div>' +
					'<div class="omc-vsa__pane" data-pane="pieces" role="tabpanel">' +
						'<div class="omc-vsa__addrow"><button type="button" class="button button-primary omc-vsa__add">' + esc( T.addHere ) + ' <span class="omc-vsa__clock">0:00</span></button><span class="omc-vsa__hint">' + esc( T.addHint ) + '</span></div>' +
						'<ol class="omc-vsa__moments"></ol>' +
					'</div>' +
					'<div class="omc-vsa__pane" data-pane="words" role="tabpanel" hidden>' +
						( D.ai
							? '<div class="omc-vsa__ai">' +
								'<h3 class="omc-vsa__aih">' + esc( T.aiTitle ) + '</h3>' +
								'<p class="omc-vsa__note">' + esc( T.aiLead ) + '</p>' +
								'<label class="omc-vsa__field"><span class="omc-vsa__fl">' + esc( T.aiNotes ) + '</span><textarea rows="3" class="omc-vsa__ainotes" placeholder="' + esc( T.aiNotesPh ) + '"></textarea></label>' +
								'<label class="omc-vsa__check"><input type="checkbox" class="omc-vsa__aipieces" checked> ' + esc( T.aiPieces ) + '</label>' +
								'<div class="omc-vsa__airow"><button type="button" class="button button-primary omc-vsa__aigo">✦ ' + esc( T.aiGo ) + '</button><span class="omc-vsa__ailine" role="status" aria-live="polite"></span></div>' +
								'<div class="omc-vsa__warn" hidden></div>' +
							'</div>'
							: '<p class="omc-vsa__aioff">' + esc( T.aiOff ) + ( D.aiSetup ? ' <a href="' + esc( D.aiSetup ) + '">' + esc( T.aiSetup ) + ' →</a>' : '' ) + '</p>' ) +
						( v.added ? '' : '<p class="omc-vsa__note">' + esc( T.imported ) + '</p>' ) +
						'<div class="omc-vsa__form"></div>' +
					'</div>' +
				'</div>' +
			'</div>';
		listEl.appendChild( card );

		var video = card.querySelector( 'video' );
		var ol = card.querySelector( '.omc-vsa__moments' );
		var clockEl = card.querySelector( '.omc-vsa__clock' );
		var chips = card.querySelector( '.omc-vsa__chips' );
		var chh = card.querySelector( '.omc-vsa__chh' );
		var formEl = card.querySelector( '.omc-vsa__form' );
		var pub = card.querySelector( '.omc-vsa__pub' );
		video.addEventListener( 'timeupdate', function () {
			clockEl.textContent = clock( video.currentTime );
		} );

		/* The head: title as the words have it, published or draft, and the way to switch. */
		function paintHead() {
			card.querySelector( '.omc-vsa__h' ).textContent = words[ v.slug ].title || v.title;
			var pill = card.querySelector( '.omc-vsa__pill' );
			var live = 'publish' === v.status;
			pill.textContent = live ? T.published : T.draft;
			pill.className = 'omc-vsa__pill is-' + ( live ? 'live' : 'draft' );
			if ( pub ) {
				pub.textContent = live ? T.unpublish : T.publish;
				pub.className = 'button omc-vsa__pub' + ( live ? '' : ' button-primary' );
			}
			var view = card.querySelector( '.omc-vsa__view' );
			view.href = v.view;
			view.textContent = ( live ? T.view : T.preview ) + ' ↗';
		}
		if ( pub ) {
			pub.addEventListener( 'click', function () {
				pub.disabled = true;
				api( D.status, { page: v.page, status: 'publish' === v.status ? 'draft' : 'publish' } ).then( function ( j ) {
					v.status = j.status;
					v.view = j.view;
					paintHead();
				} ).catch( function ( e ) {
					window.alert( T.statusFail + ': ' + e.message );
				} ).then( function () {
					pub.disabled = false;
				} );
			} );
		}

		/* The tabs. */
		function show( which ) {
			tab = which;
			card.querySelectorAll( '.omc-vsa__tab' ).forEach( function ( b ) {
				var on = b.getAttribute( 'data-tab' ) === which;
				b.classList.toggle( 'is-on', on );
				b.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			} );
			card.querySelectorAll( '.omc-vsa__pane' ).forEach( function ( p ) {
				p.hidden = p.getAttribute( 'data-pane' ) !== which;
			} );
			paintChips();
		}
		card.querySelectorAll( '.omc-vsa__tab' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				show( b.getAttribute( 'data-tab' ) );
			} );
		} );

		/* The chapters under the player: a click adds a piece there, or (on the words tab) plays from it. */
		function paintChips() {
			var list = words[ v.slug ].chapters.filter( function ( c ) {
				return c.label;
			} );
			chips.innerHTML = '';
			chh.hidden = ! list.length;
			chh.textContent = 'pieces' === tab ? T.chapters : T.chaptersPlay;
			list.forEach( function ( c ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.className = 'omc-vsa__chip';
				b.innerHTML = '<b>' + esc( clock( c.at ) ) + '</b> ' + esc( c.label );
				b.addEventListener( 'click', function () {
					video.currentTime = c.at;
					if ( 'pieces' === tab ) {
						add( c.at );
					} else {
						video.play().catch( function () {} );
					}
				} );
				chips.appendChild( b );
			} );
		}

		/* ───── pieces ───── */

		function add( at ) {
			var row = { at: Math.round( at ), product: 0, for: T.forDefault };
			state[ v.slug ].push( row );
			state[ v.slug ].sort( byTime );
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
		}
		card.querySelector( '.omc-vsa__add' ).addEventListener( 'click', function () {
			video.pause();
			add( video.currentTime || 0 );
		} );

		function paint() {
			var rows = state[ v.slug ];
			card.querySelector( '.omc-vsa__count' ).textContent = rows.length ? '(' + rows.length + ')' : '';
			ol.innerHTML = rows.length ? '' : '<li class="omc-vsa__empty">' + esc( T.empty ) + '</li>';
			rows.forEach( function ( row, i ) {
				var p = row.product ? piece( row.product ) : null;
				var li = document.createElement( 'li' );
				li.className = 'omc-vsa__row' + ( row.product ? '' : ' is-empty' );
				li.setAttribute( 'data-row', i );
				li.innerHTML =
					'<label class="omc-vsa__time"><span class="screen-reader-text">' + esc( T.time ) + '</span><input type="text" inputmode="numeric" value="' + esc( clock( row.at ) ) + '" aria-label="' + esc( T.time ) + '"></label>' +
					'<button type="button" class="omc-vsa__ib" data-a="now" title="' + esc( T.now ) + '" aria-label="' + esc( T.now ) + '">⟲</button>' +
					'<label class="omc-vsa__for" title="' + esc( T.showFor ) + '"><input type="number" min="3" max="60" step="1" value="' + esc( row.for || T.forDefault ) + '" aria-label="' + esc( T.showFor ) + '"><span>s</span></label>' +
					'<button type="button" class="omc-vsa__pick">' +
						( p ? ( p.thumb ? '<img src="' + esc( p.thumb ) + '" alt="">' : '' ) + '<span>' + esc( p.name ) + '<small>' + esc( p.price || '' ) + '</small></span>' : '<span class="omc-vsa__choose">' + esc( row.product ? '#' + row.product : T.choose ) + '</span>' ) +
					'</button>' +
					'<button type="button" class="omc-vsa__ib" data-a="play" title="' + esc( T.jump ) + '" aria-label="' + esc( T.jump ) + '">▶</button>' +
					'<button type="button" class="omc-vsa__ib is-danger" data-a="x" title="' + esc( T.remove ) + '" aria-label="' + esc( T.remove ) + '">×</button>';
				var input = li.querySelector( '.omc-vsa__time input' );
				var len = li.querySelector( '.omc-vsa__for input' );
				len.addEventListener( 'change', function () {
					row.for = Math.max( 3, Math.min( 60, parseInt( len.value, 10 ) || T.forDefault ) );
					len.value = row.for;
					changed();
				} );
				input.addEventListener( 'change', function () {
					var s = seconds( input.value );
					if ( null === s ) {
						input.value = clock( row.at );
						return;
					}
					row.at = s;
					rows.sort( byTime );
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
							rows.sort( byTime );
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

		/* ───── page words ───── */

		function field( label, control, hint, count ) {
			return '<label class="omc-vsa__field"><span class="omc-vsa__fl">' + esc( label ) +
				( count ? ' <span class="omc-vsa__n" data-n="' + count + '"></span>' : '' ) + '</span>' + control +
				( hint ? '<span class="omc-vsa__fh">' + esc( hint ) + '</span>' : '' ) + '</label>';
		}
		function input( key, value ) {
			return '<input type="text" data-k="' + key + '" value="' + esc( value ) + '">';
		}
		function area( attr, value, rows ) {
			return '<textarea ' + attr + ' rows="' + ( rows || 3 ) + '">' + esc( value ) + '</textarea>';
		}
		function counts() {
			var w = words[ v.slug ];
			var limits = { seo_title: [ 0, 60 ], meta_description: [ 140, 155 ], alt: [ 0, 125 ] };
			formEl.querySelectorAll( '[data-n]' ).forEach( function ( el ) {
				var k = el.getAttribute( 'data-n' );
				var n = w[ k ].length;
				el.textContent = n + ' / ' + limits[ k ][ 1 ];
				el.classList.toggle( 'is-bad', n > limits[ k ][ 1 ] || ( n > 0 && n < limits[ k ][ 0 ] ) );
			} );
		}

		function paintWords() {
			var w = words[ v.slug ];
			var html =
				field( T.fTitle, input( 'title', w.title ) ) +
				field( T.fIntro, area( 'data-k="intro"', w.intro, 3 ), T.fIntroHint ) +
				'<div class="omc-vsa__two">' +
					field( T.fSeoTitle, input( 'seo_title', w.seo_title ), T.fSeoHint, 'seo_title' ) +
					field( T.fKey, input( 'keyphrase', w.keyphrase ), T.fKeyHint ) +
				'</div>' +
				field( T.fMeta, area( 'data-k="meta_description"', w.meta_description, 2 ), T.fMetaHint, 'meta_description' ) +
				field( T.fAlt, input( 'alt', w.alt ), T.fAltHint, 'alt' );

			html += '<div class="omc-vsa__group"><h3 class="omc-vsa__gh">' + esc( T.fChapters ) + '</h3><p class="omc-vsa__fh">' + esc( T.fChapHint ) + '</p><ol class="omc-vsa__chlist">';
			w.chapters.forEach( function ( c, i ) {
				html += '<li class="omc-vsa__chrow" data-list="chapters" data-i="' + i + '">' +
					'<input type="text" class="omc-vsa__chat" inputmode="numeric" data-f="at" value="' + esc( clock( c.at ) ) + '" aria-label="' + esc( T.time ) + '">' +
					'<button type="button" class="omc-vsa__ib" data-do="now" title="' + esc( T.now ) + '" aria-label="' + esc( T.now ) + '">⟲</button>' +
					'<input type="text" data-f="label" value="' + esc( c.label ) + '" placeholder="' + esc( T.chapLabel ) + '" aria-label="' + esc( T.chapLabel ) + '">' +
					'<button type="button" class="omc-vsa__ib" data-do="play" title="' + esc( T.jump ) + '" aria-label="' + esc( T.jump ) + '">▶</button>' +
					'<button type="button" class="omc-vsa__ib is-danger" data-do="x" title="' + esc( T.remove ) + '" aria-label="' + esc( T.remove ) + '">×</button>' +
				'</li>';
			} );
			html += '</ol><button type="button" class="button omc-vsa__more" data-do="chapter">+ ' + esc( T.addChapter ) + '</button></div>';

			html += '<div class="omc-vsa__group"><h3 class="omc-vsa__gh">' + esc( T.fSections ) + '</h3>';
			w.sections.forEach( function ( s, i ) {
				html += '<div class="omc-vsa__block" data-list="sections" data-i="' + i + '">' +
					'<button type="button" class="omc-vsa__ib is-danger omc-vsa__bx" data-do="x" title="' + esc( T.remove ) + '" aria-label="' + esc( T.remove ) + '">×</button>' +
					field( T.heading, '<input type="text" data-f="heading" value="' + esc( s.heading ) + '">' ) +
					field( T.paragraphs, area( 'data-f="paragraphs"', s.paragraphs.join( '\n\n' ), 5 ) ) +
					field( T.bullets, area( 'data-f="bullets"', s.bullets.join( '\n' ), 3 ) ) +
				'</div>';
			} );
			html += '<button type="button" class="button omc-vsa__more" data-do="section">+ ' + esc( T.addSection ) + '</button></div>';

			html += '<div class="omc-vsa__group"><h3 class="omc-vsa__gh">' + esc( T.fFaqs ) + '</h3>';
			w.faqs.forEach( function ( f, i ) {
				html += '<div class="omc-vsa__block" data-list="faqs" data-i="' + i + '">' +
					'<button type="button" class="omc-vsa__ib is-danger omc-vsa__bx" data-do="x" title="' + esc( T.remove ) + '" aria-label="' + esc( T.remove ) + '">×</button>' +
					field( T.q, '<input type="text" data-f="q" value="' + esc( f.q ) + '">' ) +
					field( T.a, area( 'data-f="a"', f.a, 3 ) ) +
				'</div>';
			} );
			html += '<button type="button" class="button omc-vsa__more" data-do="faq">+ ' + esc( T.addFaq ) + '</button></div>';

			formEl.innerHTML = html;
			counts();
			paintChips();
		}

		var lines = function ( text, gap ) {
			return String( text ).split( gap ? /\n\s*\n/ : /\n/ ).map( function ( s ) {
				return s.trim();
			} ).filter( Boolean );
		};
		var holder = function ( el ) {
			var h = el.closest( '[data-list]' );
			return h ? { list: words[ v.slug ][ h.getAttribute( 'data-list' ) ], i: parseInt( h.getAttribute( 'data-i' ), 10 ) } : null;
		};

		formEl.addEventListener( 'input', function ( e ) {
			var t = e.target;
			var w = words[ v.slug ];
			var k = t.getAttribute( 'data-k' );
			var f = t.getAttribute( 'data-f' );
			if ( k ) {
				w[ k ] = t.value;
				if ( 'title' === k ) {
					paintHead();
				}
				counts();
			} else if ( f && 'at' !== f ) {
				var h = holder( t );
				var item = h && h.list[ h.i ];
				if ( ! item ) {
					return;
				}
				if ( 'paragraphs' === f || 'bullets' === f ) {
					item[ f ] = lines( t.value, 'paragraphs' === f );
				} else {
					item[ f ] = t.value;
				}
				if ( 'label' === f ) {
					paintChips();
				}
			}
			changed();
		} );
		formEl.addEventListener( 'change', function ( e ) {
			var t = e.target;
			if ( 'at' !== t.getAttribute( 'data-f' ) ) {
				return;
			}
			var h = holder( t );
			var s = seconds( t.value );
			if ( null === s ) {
				t.value = clock( h.list[ h.i ].at );
				return;
			}
			h.list[ h.i ].at = s;
			h.list.sort( byTime );
			paintWords();
			changed();
		} );
		formEl.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '[data-do]' );
			if ( ! b ) {
				return;
			}
			var w = words[ v.slug ];
			var act = b.getAttribute( 'data-do' );
			var h = holder( b );
			if ( 'x' === act && h ) {
				h.list.splice( h.i, 1 );
			} else if ( 'now' === act && h ) {
				h.list[ h.i ].at = Math.round( video.currentTime || 0 );
				h.list.sort( byTime );
			} else if ( 'play' === act && h ) {
				video.currentTime = h.list[ h.i ].at;
				video.play().catch( function () {} );
				return;
			} else if ( 'chapter' === act ) {
				w.chapters.push( { at: Math.round( video.currentTime || 0 ), label: '' } );
				w.chapters.sort( byTime );
			} else if ( 'section' === act ) {
				w.sections.push( { heading: '', paragraphs: [], bullets: [] } );
			} else if ( 'faq' === act ) {
				w.faqs.push( { q: '', a: '' } );
			} else {
				return;
			}
			paintWords();
			changed();
			if ( 'chapter' === act || 'section' === act || 'faq' === act ) {
				var fresh = 'chapter' === act
					? formEl.querySelector( '.omc-vsa__chrow input[data-f="label"][value=""]' )
					: formEl.querySelector( '[data-list="' + ( 'faq' === act ? 'faqs' : 'sections' ) + '"]:last-of-type input' );
				if ( fresh ) {
					fresh.focus();
				}
			}
		} );

		/* ───── written by AI ───── */

		var aiGo = card.querySelector( '.omc-vsa__aigo' );
		if ( aiGo ) {
			var aiLine = card.querySelector( '.omc-vsa__ailine' );
			var warnEl = card.querySelector( '.omc-vsa__warn' );
			var piecesBox = card.querySelector( '.omc-vsa__aipieces' );
			var say = function ( text, tone ) {
				aiLine.textContent = text || '';
				aiLine.className = 'omc-vsa__ailine' + ( tone ? ' is-' + tone : '' );
			};
			piecesBox.checked = state[ v.slug ].length > 0;
			aiGo.addEventListener( 'click', function () {
				var w = words[ v.slug ];
				var has = w.intro || w.sections.length || w.faqs.length || w.chapters.length;
				if ( has && ! window.confirm( T.aiReplace ) ) {
					return;
				}
				aiGo.disabled = true;
				warnEl.hidden = true;
				card.classList.add( 'is-writing' );
				say( T.aiStills, 'busy' );
				stills( v.src ).then( function ( frames ) {
					say( T.aiSending, 'busy' );
					return api( D.write, {
						page: v.page,
						frames: frames,
						notes: card.querySelector( '.omc-vsa__ainotes' ).value,
						pieces: piecesBox.checked
					} );
				} ).then( function ( j ) {
					return j && j.job ? poll( j.job, function ( s ) {
						say( T.aiWriting.replace( '%s', clock( s ) ), 'busy' );
					} ) : j;
				} ).then( function ( res ) {
					if ( ! res || ! res.content ) {
						throw new Error( T.aiFail );
					}
					words[ v.slug ] = normal( res.content );
					paintWords();
					paintHead();
					changed();
					say( T.aiDone, 'ok' );
					var warn = res.warnings || [];
					warnEl.hidden = ! warn.length;
					warnEl.innerHTML = warn.length ? '<p>' + esc( T.aiWarn ) + '</p><ul><li>' + warn.map( esc ).join( '</li><li>' ) + '</li></ul>' : '';
				} ).catch( function ( e ) {
					say( T.aiFail + ': ' + e.message, 'bad' );
				} ).then( function () {
					aiGo.disabled = false;
					card.classList.remove( 'is-writing' );
				} );
			} );
		}

		card.repaint = function () {
			paint();
			paintWords();
			paintHead();
		};
		card.show = show;
		cards.push( card );
		paintHead();
		paint();
		paintWords();
		show( 'pieces' );
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

	function changedWords() {
		var out = {};
		Object.keys( words ).forEach( function ( slug ) {
			if ( JSON.stringify( words[ slug ] ) !== savedWords[ slug ] ) {
				out[ slug ] = words[ slug ];
			}
		} );
		return out;
	}

	function dirty() {
		return JSON.stringify( state ) !== savedMoments || Object.keys( changedWords() ).length > 0;
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
		api( D.rest, { videos: payload(), content: changedWords() } ).then( function ( j ) {
			Object.keys( j.videos || {} ).forEach( function ( slug ) {
				state[ slug ] = j.videos[ slug ].map( function ( m ) {
					return { at: m.at, product: m.product, for: m.for };
				} );
			} );
			Object.keys( j.content || {} ).forEach( function ( slug ) {
				words[ slug ] = normal( j.content[ slug ] );
				savedWords[ slug ] = JSON.stringify( words[ slug ] );
			} );
			savedMoments = JSON.stringify( state );
			busy = false;
			goEl.textContent = T.save;
			cards.forEach( function ( c ) {
				c.repaint();
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
		if ( ! leaving && dirty() ) {
			e.preventDefault();
			e.returnValue = T.leave;
			return T.leave;
		}
	} );

	/* #words-<slug> (where "Add a video" lands): that video, open on its words. */
	var m = /^#words-([a-z0-9_-]+)$/.exec( window.location.hash || '' );
	if ( m ) {
		cards.forEach( function ( c ) {
			if ( c.id === 'omc-v-' + m[ 1 ] ) {
				c.show( 'words' );
				setTimeout( function () {
					c.scrollIntoView( { block: 'start' } );
				}, 60 );
			}
		} );
	}

	changed();
}() );
