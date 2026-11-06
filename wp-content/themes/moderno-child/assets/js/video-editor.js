/**
 * Page Editor → Shoppable videos → Edit video (inc/video-editor.php): a timeline for one video.
 *
 * The preview draws with the player's own code (assets/js/omc-video-play.js), so what is seen here is
 * what shoppers get. Times are in the file's own seconds; cuts are skipped as it plays. One document —
 * cuts, items, moments, the pop-up's place — with undo over all of it, and one Save.
 */
( function () {
	'use strict';

	var D = window.OMC_VE || {};
	var T = D.i18n || {};
	var P = window.OMCVideoPlay;
	var app = document.getElementById( 'omc-ve' );
	if ( ! app || ! P || ! D.video ) {
		return;
	}
	var V = D.video;
	var duration = +V.duration || 0;
	var known = D.products || {};
	var counts = D.counts || {};
	var uid = 0;

	function newId() {
		return 'v' + Date.now().toString( 36 ) + ( ++uid ).toString( 36 );
	}

	function withIds( rows ) {
		return ( rows || [] ).map( function ( m ) {
			return { id: 'm' + ( ++uid ), at: +m.at || 0, product: m.product, for: +m.for || 8 };
		} );
	}

	var doc = {
		cuts: D.cuts || [],
		items: D.items || [],
		moments: withIds( D.moments ),
		place: { x: +( D.place && D.place.x ) || 0, y: +( D.place && D.place.y ) || 0 }
	};
	var selected = null; // { kind: 'item' | 'moment' | 'cut' | 'range', id }
	var range = null; // [ from, to ]: the part marked on the Video row
	var history = [ JSON.stringify( doc ) ];
	var at = 0;
	var saved = history[ 0 ];
	var busy = false;
	var zoom = 0; // 0: the whole video fits; 1: a second is forty pixels
	var scale = 1; // pixels a second

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = null == s ? '' : String( s );
		return d.innerHTML;
	}

	function fmt( text ) {
		var args = [].slice.call( arguments, 1 );
		var n = 0;
		return String( text ).replace( /%(\d\$)?[sd]/g, function ( m, pos ) {
			return args[ pos ? parseInt( pos, 10 ) - 1 : n++ ];
		} );
	}

	function clamp( n, a, b ) {
		return Math.max( a, Math.min( b, n ) );
	}

	function tenth( n ) {
		return Math.round( n * 10 ) / 10;
	}

	function piece( id ) {
		return known[ id ] || ( window.OMCPiecePicker && window.OMCPiecePicker.known[ id ] ) || null;
	}

	function parseTime( text ) {
		var m = /^\s*(?:(\d+):)?(?:(\d+):)?(\d+(?:\.\d+)?)\s*$/.exec( String( text ) );
		if ( ! m ) {
			return null;
		}
		var parts = [ m[ 1 ], m[ 2 ], m[ 3 ] ].filter( function ( x ) {
			return undefined !== x;
		} ).map( parseFloat );
		return parts.reduce( function ( sum, p ) {
			return sum * 60 + p;
		}, 0 );
	}

	/* ─────────────────────────── the screen ─────────────────────────── */

	app.innerHTML =
		'<header class="omc-ve__head">' +
			'<a class="omc-ve__back" href="' + esc( D.back ) + '">← ' + esc( T.back ) + '</a>' +
			'<h1 class="omc-ve__title">' + esc( V.title ) + '</h1><span class="omc-ve__pill"></span>' +
			'<span class="omc-ve__grow"></span>' +
			'<button type="button" class="button omc-ve__undo">' + esc( T.undo ) + '</button>' +
			'<button type="button" class="button omc-ve__redo">' + esc( T.redo ) + '</button>' +
			'<a class="omc-ve__view" target="_blank" rel="noopener"></a>' +
			'<span class="omc-ve__status" role="status" aria-live="polite"></span>' +
			( D.canPub ? '<button type="button" class="button omc-ve__pub"></button>' : '' ) +
			'<button type="button" class="button button-primary omc-ve__save">' + esc( T.save ) + '</button>' +
		'</header>' +
		'<div class="omc-ve__main">' +
			'<div class="omc-ve__left">' +
				'<div class="omc-ve__frame omc-video__frame omc-video__frame--' + esc( V.orient ) + '" style="--omc-video-ratio:' + ( V.width && V.height ? V.width + ' / ' + V.height : '16 / 9' ) + '">' +
					'<video class="omc-video__player omc-ve__video" playsinline preload="metadata"' + ( V.poster ? ' poster="' + esc( V.poster ) + '"' : '' ) + ' src="' + esc( V.src ) + '"></video>' +
					'<div class="omc-vo-layer"></div>' +
				'</div>' +
				'<div class="omc-ve__transport">' +
					'<button type="button" class="button omc-ve__b5" aria-label="' + esc( T.back5 ) + '">−5s</button>' +
					'<button type="button" class="button button-primary omc-ve__play">' + esc( T.play ) + '</button>' +
					'<button type="button" class="button omc-ve__f5" aria-label="' + esc( T.on5 ) + '">+5s</button>' +
					'<span class="omc-ve__now"></span><span class="omc-ve__length"></span>' +
				'</div>' +
			'</div>' +
			'<aside class="omc-ve__panel" aria-live="polite"></aside>' +
		'</div>' +
		'<section class="omc-ve__tl">' +
			'<div class="omc-ve__tlbar">' +
				'<button type="button" class="button omc-ve__in" title="' + esc( T.startTip ) + '">' + esc( T.startHere ) + '</button>' +
				'<button type="button" class="button omc-ve__out" title="' + esc( T.endTip ) + '">' + esc( T.endHere ) + '</button>' +
				'<button type="button" class="button omc-ve__cut" disabled>' + esc( T.cutPart ) + '</button>' +
				'<span class="omc-ve__hint">' + esc( T.cutHint ) + '</span>' +
				'<span class="omc-ve__grow"></span>' +
				'<label class="omc-ve__zoom">' + esc( T.zoom ) + ' <input type="range" min="0" max="100" value="0"></label>' +
			'</div>' +
			'<div class="omc-ve__tlbody">' +
				'<div class="omc-ve__labels"><span></span><span>' + esc( T.trackVideo ) + '</span><span>' + esc( T.trackPieces ) + '</span><span>' + esc( T.trackPlay ) + '</span><span>' + esc( T.trackText ) + '</span></div>' +
				'<div class="omc-ve__scroll"><div class="omc-ve__inner">' +
					'<div class="omc-ve__ruler"></div>' +
					'<div class="omc-ve__track omc-ve__track--video"><canvas class="omc-ve__thumbs" height="60"></canvas><div class="omc-ve__cuts"></div></div>' +
					'<div class="omc-ve__track" data-track="pieces"></div>' +
					'<div class="omc-ve__track" data-track="play"></div>' +
					'<div class="omc-ve__track" data-track="text"></div>' +
					'<div class="omc-ve__playhead"></div>' +
				'</div></div>' +
			'</div>' +
		'</section>';

	function q( s ) {
		return app.querySelector( s );
	}

	var video = q( '.omc-ve__video' );
	var frame = q( '.omc-ve__frame' );
	var layer = q( '.omc-vo-layer' );
	var panel = q( '.omc-ve__panel' );
	var inner = q( '.omc-ve__inner' );
	var scroller = q( '.omc-ve__scroll' );
	var ruler = q( '.omc-ve__ruler' );
	var videoTrack = q( '.omc-ve__track--video' );
	var cutsEl = q( '.omc-ve__cuts' );
	var playhead = q( '.omc-ve__playhead' );
	var statusEl = q( '.omc-ve__status' );
	var saveBtn = q( '.omc-ve__save' );

	/* ─ what the preview's items do: nothing real, this is not a shopper ─ */
	var env = {
		strings: D.play || {},
		piece: piece,
		close: function () {},
		openPiece: function () {},
		act: function () {},
		voted: function () {
			return null;
		},
		counts: function () {
			return [];
		},
		fresh: function () {
			return [];
		},
		vote: function () {
			return Promise.resolve( null );
		},
		done: function () {},
		jump: function () {},
		signup: function () {
			return Promise.reject( new Error( '' ) );
		}
	};

	/* ─────────────────────────── undo, and whether it needs saving ─────────────────────────── */

	function commit() {
		var now = JSON.stringify( doc );
		if ( now !== history[ at ] ) {
			history.length = at + 1;
			history.push( now );
			at = history.length - 1;
			if ( history.length > 80 ) {
				history.shift();
				at--;
			}
		}
		changed();
	}

	function restore( to ) {
		at = clamp( to, 0, history.length - 1 );
		doc = JSON.parse( history[ at ] );
		if ( selected && ! found( selected ) ) {
			selected = null;
		}
		paintAll();
		changed();
	}

	function dirty() {
		return JSON.stringify( doc ) !== saved;
	}

	function changed( text, tone ) {
		var d = dirty();
		statusEl.textContent = text || ( d ? T.unsaved : T.saved );
		statusEl.className = 'omc-ve__status is-' + ( tone || ( d ? 'dirty' : 'ok' ) );
		saveBtn.disabled = busy || ! d;
		q( '.omc-ve__undo' ).disabled = at <= 0;
		q( '.omc-ve__redo' ).disabled = at >= history.length - 1;
	}

	function paintHead() {
		var live = 'publish' === V.status;
		var pill = q( '.omc-ve__pill' );
		pill.textContent = live ? T.published : T.draft;
		pill.classList.toggle( 'is-live', live );
		var view = q( '.omc-ve__view' );
		view.href = V.view;
		view.textContent = ( live ? T.view : T.preview ) + ' ↗';
		var pub = q( '.omc-ve__pub' );
		if ( pub ) {
			pub.textContent = live ? T.unpublish : T.publish;
		}
	}

	/* ─────────────────────────── the part that plays ─────────────────────────── */

	function line() {
		return P.timeline( doc.cuts, duration );
	}

	function normalCuts( list ) {
		var rows = list.map( function ( c ) {
			return [ tenth( Math.max( 0, c[ 0 ] ) ), tenth( Math.min( duration, c[ 1 ] ) ) ];
		} ).filter( function ( c ) {
			return c[ 1 ] - c[ 0 ] >= 0.2;
		} ).sort( function ( a, b ) {
			return a[ 0 ] - b[ 0 ];
		} );
		var out = [];
		rows.forEach( function ( c ) {
			var last = out[ out.length - 1 ];
			if ( last && c[ 0 ] <= last[ 1 ] + 0.1 ) {
				last[ 1 ] = Math.max( last[ 1 ], c[ 1 ] );
			} else {
				out.push( c );
			}
		} );
		return out;
	}

	/* Take a part out, unless that would leave nothing to watch. */
	function cut( from, to ) {
		var next = normalCuts( doc.cuts.concat( [ [ from, to ] ] ) );
		var gone = next.reduce( function ( sum, c ) {
			return sum + c[ 1 ] - c[ 0 ];
		}, 0 );
		if ( duration - gone < 1 ) {
			changed( T.tooMuch, 'bad' );
			return;
		}
		doc.cuts = next;
		range = null;
		if ( selected && 'range' === selected.kind ) {
			selected = null;
		}
		commit();
		paintAll();
	}

	/* ─────────────────────────── the preview ─────────────────────────── */

	var shown = {}; // id → the element over the picture

	function active( item, t ) {
		return t >= item.at && t < item.at + item.for;
	}

	function isSelected( kind, id ) {
		return selected && selected.kind === kind && selected.id === id;
	}

	function select( kind, id ) {
		selected = kind ? { kind: kind, id: id } : null;
		paintPanel();
		paintTimeline();
		paintPreview();
	}

	function found( s ) {
		if ( 'item' === s.kind ) {
			return doc.items.some( function ( i ) {
				return i.id === s.id;
			} );
		}
		if ( 'moment' === s.kind ) {
			return doc.moments.some( function ( m ) {
				return m.id === s.id;
			} );
		}
		if ( 'cut' === s.kind ) {
			return !! doc.cuts[ s.id ];
		}
		return !! range;
	}

	function currentItem() {
		return selected && 'item' === selected.kind ? doc.items.filter( function ( i ) {
			return i.id === selected.id;
		} )[ 0 ] : null;
	}

	function currentMoment() {
		return selected && 'moment' === selected.kind ? doc.moments.filter( function ( m ) {
			return m.id === selected.id;
		} )[ 0 ] : null;
	}

	/* The element for a piece's window: the page's own classes, standing still. */
	function popNode( m ) {
		var p = piece( m.product );
		var node = document.createElement( 'div' );
		node.className = 'omc-vpop is-in omc-ve__pop';
		node.innerHTML = '<span class="omc-vpop__media">' + ( p && p.thumb ? '<img src="' + esc( p.thumb ) + '" alt="">' : '' ) + '</span>' +
			'<div class="omc-vpop__body"><p class="omc-vpop__k">' + esc( T.sample ) + '</p><span class="omc-vpop__name">' + esc( p ? p.name : T.noPiece ) + '</span><span class="omc-vpop__price">' + esc( p ? p.price : '' ) + '</span></div>' +
			'<span class="omc-vpop__bag"></span>';
		node.style.setProperty( '--omc-pop-x', doc.place.x );
		node.style.setProperty( '--omc-pop-y', doc.place.y );
		return node;
	}

	function itemKey( item ) {
		var copy = Object.assign( {}, item );
		delete copy.at;
		delete copy.for;
		delete copy.x;
		delete copy.y;
		return JSON.stringify( copy );
	}

	function paintPreview() {
		var t = video.currentTime || 0;
		var wanted = {};
		doc.items.forEach( function ( item ) {
			var isOn = isSelected( 'item', item.id ) || active( item, t );
			if ( ! isOn ) {
				return;
			}
			wanted[ item.id ] = true;
			var node = shown[ item.id ];
			var key = itemKey( item );
			if ( node && node.whdKey !== key ) {
				node.remove();
				node = null;
			}
			if ( ! node ) {
				node = P.render( item, env );
				node.whdKey = key;
				node.classList.add( 'is-in' );
				if ( 'A' === node.tagName ) {
					node.removeAttribute( 'href' );
				}
				node.setAttribute( 'tabindex', '-1' );
				grabItem( node, item.id );
				layer.appendChild( node );
				shown[ item.id ] = node;
			}
			P.place( node, item );
			node.classList.toggle( 'is-selected', isSelected( 'item', item.id ) );
		} );

		// The piece on now, or the one selected: its window sits in the video's one spot.
		var live = currentMoment();
		if ( ! live ) {
			doc.moments.forEach( function ( m, i ) {
				var next = doc.moments[ i + 1 ];
				if ( t >= m.at && t < Math.min( m.at + m.for, next ? next.at : Infinity ) ) {
					live = m;
				}
			} );
		}
		if ( live ) {
			wanted.pop = true;
			var key = 'pop' + live.product;
			if ( shown.pop && shown.pop.whdKey !== key ) {
				shown.pop.remove();
				shown.pop = null;
			}
			if ( ! shown.pop ) {
				shown.pop = popNode( live );
				shown.pop.whdKey = key;
				grabPop( shown.pop );
				frame.appendChild( shown.pop );
			}
			shown.pop.style.setProperty( '--omc-pop-x', doc.place.x );
			shown.pop.style.setProperty( '--omc-pop-y', doc.place.y );
			shown.pop.whdMoment = live.id;
			shown.pop.classList.toggle( 'is-selected', isSelected( 'moment', live.id ) );
		}
		Object.keys( shown ).forEach( function ( id ) {
			if ( ! wanted[ id ] && shown[ id ] ) {
				shown[ id ].remove();
				delete shown[ id ];
			}
		} );
	}

	/*
	 * A press and drag on anything; move( dx, dy ) as it goes, end( moved ) once it lets go. On: what holds
	 * the pointer, for anything that may be redrawn away as the press selects it.
	 */
	function drag( e, move, end, on ) {
		e.preventDefault();
		e.stopPropagation();
		var target = on || e.currentTarget;
		var sx = e.clientX;
		var sy = e.clientY;
		var moved = false;
		target.setPointerCapture( e.pointerId );
		function onMove( ev ) {
			var dx = ev.clientX - sx;
			var dy = ev.clientY - sy;
			if ( ! moved && Math.abs( dx ) + Math.abs( dy ) < 3 ) {
				return;
			}
			moved = true;
			move( dx, dy, ev );
		}
		function onUp() {
			target.removeEventListener( 'pointermove', onMove );
			target.removeEventListener( 'pointerup', onUp );
			target.removeEventListener( 'pointercancel', onUp );
			if ( end ) {
				end( moved );
			}
		}
		target.addEventListener( 'pointermove', onMove );
		target.addEventListener( 'pointerup', onUp );
		target.addEventListener( 'pointercancel', onUp );
	}

	function grabItem( node, id ) {
		node.addEventListener( 'pointerdown', function ( e ) {
			var item = doc.items.filter( function ( i ) {
				return i.id === id;
			} )[ 0 ];
			if ( ! item ) {
				return;
			}
			if ( ! isSelected( 'item', id ) ) {
				select( 'item', id );
			}
			var from = { x: item.x, y: item.y };
			var isDot = 'spot' === item.type;
			var w = layer.clientWidth;
			var h = layer.clientHeight;
			var roomW = isDot ? w : Math.max( 1, w - 24 - node.offsetWidth );
			var roomH = isDot ? h : Math.max( 1, h - 68 - node.offsetHeight );
			drag( e, function ( dx, dy ) {
				item.x = Math.round( clamp( from.x + dx / roomW, 0, 1 ) * 1000 ) / 1000;
				item.y = Math.round( clamp( from.y + dy / roomH, 0, 1 ) * 1000 ) / 1000;
				P.place( node, item );
			}, function ( moved ) {
				if ( moved ) {
					commit();
				}
			} );
		} );
	}

	function grabPop( node ) {
		node.addEventListener( 'pointerdown', function ( e ) {
			if ( node.whdMoment && ! isSelected( 'moment', node.whdMoment ) ) {
				select( 'moment', node.whdMoment );
			}
			var from = { x: doc.place.x, y: doc.place.y };
			var roomW = Math.max( 1, frame.clientWidth - 24 - node.offsetWidth );
			var roomH = Math.max( 1, frame.clientHeight - 68 - node.offsetHeight );
			drag( e, function ( dx, dy ) {
				doc.place = {
					x: Math.round( clamp( from.x + dx / roomW, 0, 1 ) * 1000 ) / 1000,
					y: Math.round( clamp( from.y + dy / roomH, 0, 1 ) * 1000 ) / 1000
				};
				node.style.setProperty( '--omc-pop-x', doc.place.x );
				node.style.setProperty( '--omc-pop-y', doc.place.y );
			}, function ( moved ) {
				if ( moved ) {
					commit();
				}
			} );
		} );
	}

	/* ─ playing, with the cuts skipped ─ */

	function goTo( t ) {
		video.currentTime = clamp( t, 0, duration );
		paintTime();
		paintPreview();
	}

	function paintTime() {
		var t = video.currentTime || 0;
		playhead.style.left = ( t * scale ) + 'px';
		q( '.omc-ve__now' ).textContent = P.clock( t );
		var l = line();
		q( '.omc-ve__length' ).textContent = l.length < duration - 0.05 ? fmt( T.edited, P.clock( l.length ), P.clock( duration ) ) : fmt( T.whole, P.clock( duration ) );
	}

	function keepInView() {
		var x = ( video.currentTime || 0 ) * scale;
		if ( x < scroller.scrollLeft + 20 || x > scroller.scrollLeft + scroller.clientWidth - 60 ) {
			scroller.scrollLeft = Math.max( 0, x - 80 );
		}
	}

	function tick() {
		if ( video.paused ) {
			return;
		}
		var l = line();
		var t = video.currentTime;
		var to = l.after( t );
		if ( to >= 0 ) {
			if ( to >= duration - 0.05 ) {
				video.pause();
			} else {
				video.currentTime = to;
			}
		} else if ( t >= l.last - 0.04 && l.last < duration ) {
			video.pause();
		}
		paintTime();
		paintPreview();
		keepInView();
		window.requestAnimationFrame( tick );
	}

	function toggle() {
		if ( video.paused ) {
			var l = line();
			if ( video.currentTime >= l.last - 0.1 || video.ended ) {
				video.currentTime = l.first;
			}
			video.play().catch( function () {} );
		} else {
			video.pause();
		}
	}

	video.addEventListener( 'play', function () {
		q( '.omc-ve__play' ).textContent = T.pause;
		tick();
	} );
	video.addEventListener( 'pause', function () {
		q( '.omc-ve__play' ).textContent = T.play;
	} );
	video.addEventListener( 'seeked', function () {
		paintTime();
		paintPreview();
	} );
	video.addEventListener( 'click', toggle );
	video.addEventListener( 'loadedmetadata', function () {
		// What the browser finds in the file beats what was stored, which comes from the studio's clock.
		if ( isFinite( video.duration ) && video.duration > 0 ) {
			duration = video.duration;
			layout();
		}
		makeThumbs();
	} );
	q( '.omc-ve__play' ).addEventListener( 'click', toggle );
	q( '.omc-ve__b5' ).addEventListener( 'click', function () {
		goTo( video.currentTime - 5 );
	} );
	q( '.omc-ve__f5' ).addEventListener( 'click', function () {
		goTo( video.currentTime + 5 );
	} );

	/* ─────────────────────────── the timeline ─────────────────────────── */

	function fitScale() {
		return Math.max( 0.01, ( scroller.clientWidth - 4 ) / ( duration || 1 ) );
	}

	function layout() {
		var fit = fitScale();
		// Everything fits at nought; at full zoom a second is forty pixels.
		scale = fit * Math.pow( Math.max( 1, 40 / fit ), zoom );
		inner.style.width = Math.ceil( duration * scale ) + 'px';
		paintRuler();
		paintTimeline();
		paintTime();
	}

	function paintRuler() {
		var steps = [ 1, 2, 5, 10, 15, 30, 60, 120, 300, 600, 900, 1800, 3600 ];
		var step = steps.filter( function ( s ) {
			return s * scale >= 64;
		} )[ 0 ] || 7200;
		var html = '';
		for ( var s = 0; s <= duration; s += step ) {
			html += '<span style="left:' + ( s * scale ) + 'px">' + P.clock( s ) + '</span>';
		}
		ruler.innerHTML = html;
	}

	function label( item ) {
		var p;
		switch ( item.type ) {
			case 'text':
				return item.text;
			case 'spot':
				p = piece( item.product );
				return '• ' + ( p ? p.name : T.noPiece );
			case 'poll':
			case 'jump':
				return item.question;
			case 'button':
				return item.label;
			case 'signup':
				return item.heading;
		}
		return '';
	}

	function trackOf( item ) {
		if ( 'text' === item.type ) {
			return 'text';
		}
		return 'spot' === item.type ? 'pieces' : 'play';
	}

	function block( track, text, start, length, chosen, kind, thing ) {
		var b = document.createElement( 'button' );
		b.type = 'button';
		b.className = 'omc-ve__block is-' + kind + ( chosen ? ' is-selected' : '' );
		b.style.left = ( start * scale ) + 'px';
		b.style.width = Math.max( 10, length * scale ) + 'px';
		b.innerHTML = '<span>' + esc( text ) + '</span><i class="omc-ve__grip"></i>';
		b.addEventListener( 'pointerdown', function ( e ) {
			var isGrip = e.target.classList.contains( 'omc-ve__grip' );
			var from = { at: thing.at, for: thing.for };
			var sel = 'moment' === kind ? 'moment' : 'item';
			if ( ! isSelected( sel, thing.id ) ) {
				select( sel, thing.id );
			}
			var live = app.querySelector( '.omc-ve__block.is-selected' ) || b;
			drag( e, function ( dx ) {
				var move = dx / scale;
				if ( isGrip ) {
					thing.for = tenth( clamp( from.for + move, 1, 3600 ) );
					live.style.width = Math.max( 10, thing.for * scale ) + 'px';
				} else {
					thing.at = tenth( clamp( from.at + move, 0, duration ) );
					live.style.left = ( thing.at * scale ) + 'px';
				}
			}, function ( moved ) {
				if ( moved ) {
					if ( 'moment' === kind ) {
						thing.at = Math.round( thing.at );
						thing.for = Math.round( thing.for );
						doc.moments.sort( function ( a, b2 ) {
							return a.at - b2.at;
						} );
					}
					commit();
					paintTimeline();
					paintPanel();
					paintPreview();
				}
			}, inner );
		} );
		track.appendChild( b );
	}

	function paintTimeline() {
		q( '[data-track="pieces"]' ).innerHTML = '';
		q( '[data-track="play"]' ).innerHTML = '';
		q( '[data-track="text"]' ).innerHTML = '';
		doc.moments.forEach( function ( m ) {
			var p = piece( m.product );
			block( q( '[data-track="pieces"]' ), p ? p.name : T.noPiece, m.at, m.for, isSelected( 'moment', m.id ), 'moment', m );
		} );
		doc.items.forEach( function ( item ) {
			block( q( '[data-track="' + trackOf( item ) + '"]' ), label( item ), item.at, item.for, isSelected( 'item', item.id ), item.type, item );
		} );
		cutsEl.innerHTML = '';
		doc.cuts.forEach( function ( c, i ) {
			var n = document.createElement( 'div' );
			n.className = 'omc-ve__cutpart' + ( isSelected( 'cut', i ) ? ' is-selected' : '' );
			n.style.left = ( c[ 0 ] * scale ) + 'px';
			n.style.width = Math.max( 4, ( c[ 1 ] - c[ 0 ] ) * scale ) + 'px';
			n.innerHTML = '<i class="omc-ve__edge is-from"></i><i class="omc-ve__edge is-to"></i>';
			n.addEventListener( 'pointerdown', function ( e ) {
				var edge = e.target.classList.contains( 'is-from' ) ? 0 : ( e.target.classList.contains( 'is-to' ) ? 1 : -1 );
				if ( ! isSelected( 'cut', i ) ) {
					select( 'cut', i );
				}
				if ( edge < 0 ) {
					e.stopPropagation();
					return;
				}
				var live = cutsEl.querySelector( '.is-selected' ) || n;
				var start = c[ edge ];
				var part = doc.cuts[ i ];
				drag( e, function ( dx ) {
					part[ edge ] = tenth( clamp( start + dx / scale, 0, duration ) );
					live.style.left = ( Math.min( part[ 0 ], part[ 1 ] ) * scale ) + 'px';
					live.style.width = Math.max( 4, Math.abs( part[ 1 ] - part[ 0 ] ) * scale ) + 'px';
				}, function ( moved ) {
					if ( moved ) {
						doc.cuts = normalCuts( doc.cuts );
						selected = null;
						commit();
						paintAll();
					}
				}, inner );
			} );
			cutsEl.appendChild( n );
		} );
		if ( range ) {
			var r = document.createElement( 'div' );
			r.className = 'omc-ve__mark';
			r.style.left = ( range[ 0 ] * scale ) + 'px';
			r.style.width = ( ( range[ 1 ] - range[ 0 ] ) * scale ) + 'px';
			cutsEl.appendChild( r );
		}
		q( '.omc-ve__cut' ).disabled = ! range;
	}

	function timeFrom( e ) {
		var box = inner.getBoundingClientRect();
		return clamp( ( e.clientX - box.left ) / scale, 0, duration );
	}

	/* The ruler moves the playhead, as does a click anywhere empty. */
	[ ruler, q( '[data-track="pieces"]' ), q( '[data-track="play"]' ), q( '[data-track="text"]' ) ].forEach( function ( row ) {
		row.addEventListener( 'pointerdown', function ( e ) {
			if ( e.target.closest( '.omc-ve__block' ) ) {
				return;
			}
			video.pause();
			goTo( timeFrom( e ) );
			if ( selected && 'range' !== selected.kind ) {
				select( null );
			}
			drag( e, function ( dx, dy, ev ) {
				goTo( timeFrom( ev ) );
			} );
		} );
	} );

	/* The Video row: a click moves the playhead, a drag marks a part. */
	videoTrack.addEventListener( 'pointerdown', function ( e ) {
		video.pause();
		var start = timeFrom( e );
		drag( e, function ( dx, dy, ev ) {
			var now = timeFrom( ev );
			range = [ tenth( Math.min( start, now ) ), tenth( Math.max( start, now ) ) ];
			paintTimeline();
		}, function ( moved ) {
			if ( moved && range && range[ 1 ] - range[ 0 ] >= 0.2 ) {
				select( 'range', 0 );
			} else {
				range = null;
				if ( selected && 'range' === selected.kind ) {
					selected = null;
				}
				goTo( start );
				paintPanel();
				paintTimeline();
			}
		} );
	} );

	q( '.omc-ve__zoom input' ).addEventListener( 'input', function ( e ) {
		var centre = ( scroller.scrollLeft + scroller.clientWidth / 2 ) / scale;
		zoom = parseInt( e.target.value, 10 ) / 100;
		layout();
		scroller.scrollLeft = centre * scale - scroller.clientWidth / 2;
	} );
	window.addEventListener( 'resize', layout );

	q( '.omc-ve__in' ).addEventListener( 'click', function () {
		cut( 0, video.currentTime );
	} );
	q( '.omc-ve__out' ).addEventListener( 'click', function () {
		cut( video.currentTime, duration );
	} );
	q( '.omc-ve__cut' ).addEventListener( 'click', function () {
		if ( range ) {
			cut( range[ 0 ], range[ 1 ] );
		}
	} );

	/* Pictures along the Video row, taken from a second player so the one being watched stays put. */
	function makeThumbs() {
		var canvas = q( '.omc-ve__thumbs' );
		if ( canvas.whdDone ) {
			return;
		}
		canvas.whdDone = true;
		var shape = ( video.videoWidth / video.videoHeight ) || ( 16 / 9 );
		var slots = clamp( Math.ceil( scroller.clientWidth / ( 60 * shape ) ), 4, 30 );
		canvas.width = Math.round( slots * 60 * shape );
		var x = canvas.getContext( '2d' );
		var probe = document.createElement( 'video' );
		var i = 0;
		probe.muted = true;
		probe.preload = 'auto';
		probe.playsInline = true;
		function next() {
			if ( i >= slots ) {
				probe.removeAttribute( 'src' );
				probe.load();
				return;
			}
			probe.currentTime = duration * ( i + 0.5 ) / slots;
		}
		probe.addEventListener( 'seeked', function () {
			try {
				x.drawImage( probe, i * 60 * shape, 0, 60 * shape, 60 );
			} catch ( e ) {
				return;
			}
			i++;
			next();
		} );
		probe.addEventListener( 'loadedmetadata', next, { once: true } );
		probe.src = V.src;
	}

	/* ─────────────────────────── the side panel ─────────────────────────── */

	var MAKE = {
		text: function () {
			return { for: 6, x: 0.5, y: 0.1, text: T.dText, style: 'serif', size: 'm' };
		},
		spot: function () {
			return { for: 10, x: 0.5, y: 0.45, product: 0 };
		},
		poll: function () {
			return { for: 12, x: 0.5, y: 0.25, question: T.dPollQ, options: [ T.dPollA, T.dPollB ], pause: false };
		},
		button: function () {
			return { for: 10, x: 1, y: 1, label: T.dButton, url: D.shopUrl };
		},
		jump: function () {
			return { for: 12, x: 0.5, y: 0.25, question: T.dJumpQ, choices: [ { label: T.dJumpA, to: 0 }, { label: T.dJumpB, to: 0 } ], pause: true };
		},
		signup: function () {
			return { for: 15, x: 0.5, y: 0.25, heading: T.dSignup, button: T.dSignupBtn };
		}
	};

	function addItem( type, btn ) {
		var item = Object.assign( { id: newId(), type: type, at: tenth( video.currentTime || 0 ) }, MAKE[ type ]() );
		var finish = function () {
			doc.items.push( item );
			commit();
			selected = { kind: 'item', id: item.id };
			paintAll();
		};
		if ( 'spot' === type ) {
			window.OMCPiecePicker.open( btn, function ( p ) {
				known[ p.id ] = p;
				item.product = p.id;
				finish();
			} );
			return;
		}
		finish();
	}

	function addMoment( btn ) {
		window.OMCPiecePicker.open( btn, function ( p ) {
			known[ p.id ] = p;
			var m = { id: 'm' + ( ++uid ), at: Math.round( video.currentTime || 0 ), product: p.id, for: 8 };
			doc.moments.push( m );
			doc.moments.sort( function ( a, b ) {
				return a.at - b.at;
			} );
			commit();
			selected = { kind: 'moment', id: m.id };
			paintAll();
		} );
	}

	function field( name, text, html, hint ) {
		return '<label class="omc-ve__field"><span>' + esc( text ) + '</span>' + html + ( hint ? '<small>' + esc( hint ) + '</small>' : '' ) + '</label>';
	}

	function input( name, value, type ) {
		return '<input type="' + ( type || 'text' ) + '" data-f="' + name + '" value="' + esc( value ) + '">';
	}

	function select2( name, value, options ) {
		return '<select data-f="' + name + '">' + options.map( function ( o ) {
			return '<option value="' + o[ 0 ] + '"' + ( o[ 0 ] === value ? ' selected' : '' ) + '>' + esc( o[ 1 ] ) + '</option>';
		} ).join( '' ) + '</select>';
	}

	function check( name, value, text ) {
		return '<label class="omc-ve__check"><input type="checkbox" data-f="' + name + '"' + ( value ? ' checked' : '' ) + '> ' + esc( text ) + '</label>';
	}

	function timing( thing ) {
		return '<div class="omc-ve__two">' +
			field( 'at', T.startsAt, input( 'at', P.clock( thing.at ) ) ) +
			field( 'for', T.staysFor, input( 'for', thing.for, 'number' ) ) +
		'</div>';
	}

	function tally( item ) {
		var row = counts[ item.id ];
		if ( ! row ) {
			return '';
		}
		var sum = Object.keys( row ).reduce( function ( n, k ) {
			return n + ( +row[ k ] || 0 );
		}, 0 );
		if ( ! sum ) {
			return '';
		}
		if ( 'poll' === item.type ) {
			return '<div class="omc-ve__tally"><p>' + esc( fmt( T.votes, sum ) ) + '</p>' + item.options.map( function ( o, i ) {
				return '<div><span>' + esc( o ) + '</span><b>' + ( +row[ i ] || 0 ) + '</b></div>';
			} ).join( '' ) + '</div>';
		}
		return '<div class="omc-ve__tally"><p>' + esc( fmt( T.clicks, sum ) ) + '</p></div>';
	}

	function formFor( item ) {
		var extra = '';
		switch ( item.type ) {
			case 'text':
				extra = field( 'text', T.text, input( 'text', item.text ), T.dragCard ) +
					'<div class="omc-ve__two">' +
					field( 'style', T.style, select2( 'style', item.style, [ [ 'serif', T.styleSerif ], [ 'sans', T.styleSans ], [ 'label', T.styleLabel ] ] ) ) +
					field( 'size', T.size, select2( 'size', item.size, [ [ 's', T.sizeS ], [ 'm', T.sizeM ], [ 'l', T.sizeL ] ] ) ) +
					'</div>';
				break;
			case 'spot':
				var p = piece( item.product );
				extra = '<div class="omc-ve__field"><span>' + esc( T.piece ) + '</span><button type="button" class="omc-ve__pick">' + ( p && p.thumb ? '<img src="' + esc( p.thumb ) + '" alt="">' : '' ) + '<b>' + esc( p ? p.name : T.choose ) + '</b></button><small>' + esc( T.dragDot ) + '</small></div>';
				break;
			case 'poll':
				extra = field( 'question', T.question, input( 'question', item.question ), T.dragCard ) +
					field( 'options', T.answers, '<textarea rows="4" data-f="options">' + esc( item.options.join( '\n' ) ) + '</textarea>' ) +
					check( 'pause', item.pause, T.pauseFor );
				break;
			case 'button':
				extra = field( 'label', T.label, input( 'label', item.label ), T.dragCard ) + field( 'url', T.link, input( 'url', item.url, 'url' ) );
				break;
			case 'jump':
				extra = field( 'question', T.question, input( 'question', item.question ), T.dragCard ) +
					'<div class="omc-ve__field"><span>' + esc( T.choices ) + '</span>' + item.choices.map( function ( c, i ) {
						return '<div class="omc-ve__choice"><input type="text" data-c="' + i + '" data-k="label" value="' + esc( c.label ) + '" aria-label="' + esc( T.label ) + '">' +
							'<input type="text" data-c="' + i + '" data-k="to" value="' + esc( P.clock( c.to ) ) + '" aria-label="' + esc( T.choiceTo ) + '" title="' + esc( T.choiceTo ) + '">' +
							'<button type="button" class="button button-small" data-now="' + i + '" title="' + esc( T.choiceNow ) + '">⟲</button></div>';
					} ).join( '' ) + '</div>' +
					check( 'pause', item.pause, T.pauseFor );
				break;
			case 'signup':
				extra = field( 'heading', T.heading, input( 'heading', item.heading ), T.dragCard ) + field( 'button', T.buttonWord, input( 'button', item.button ), T.signupNote );
				break;
		}
		return extra + timing( item ) + tally( item );
	}

	function paintPanel() {
		var item = currentItem();
		var m = currentMoment();
		if ( item ) {
			panel.innerHTML = '<h2 class="omc-ve__h">' + esc( typeName( item.type ) ) + '</h2>' + formFor( item ) +
				'<div class="omc-ve__row"><button type="button" class="button omc-ve__dup">' + esc( T.copy ) + '</button><button type="button" class="button-link omc-ve__del">' + esc( T.remove ) + '</button></div>';
			wireItem( item );
		} else if ( m ) {
			var p = piece( m.product );
			panel.innerHTML = '<h2 class="omc-ve__h">' + esc( T.addPiece ) + '</h2>' +
				'<div class="omc-ve__field"><span>' + esc( T.piece ) + '</span><button type="button" class="omc-ve__pick">' + ( p && p.thumb ? '<img src="' + esc( p.thumb ) + '" alt="">' : '' ) + '<b>' + esc( p ? p.name : T.choose ) + '</b></button><small>' + esc( T.popNote ) + '</small></div>' +
				timing( m ) +
				'<div class="omc-ve__row"><button type="button" class="button-link omc-ve__del">' + esc( T.remove ) + '</button></div>';
			wireMoment( m );
		} else if ( selected && 'cut' === selected.kind && doc.cuts[ selected.id ] ) {
			var c = doc.cuts[ selected.id ];
			panel.innerHTML = '<h2 class="omc-ve__h">' + esc( T.cutTitle ) + '</h2>' +
				'<p class="omc-ve__big">' + esc( P.clock( c[ 0 ] ) ) + ' – ' + esc( P.clock( c[ 1 ] ) ) + '</p><p class="omc-ve__note">' + esc( T.cutNote ) + '</p>' +
				'<div class="omc-ve__row"><button type="button" class="button button-primary omc-ve__putback">' + esc( T.cutPutBack ) + '</button></div>';
			panel.querySelector( '.omc-ve__putback' ).addEventListener( 'click', function () {
				doc.cuts.splice( selected.id, 1 );
				selected = null;
				commit();
				paintAll();
			} );
		} else if ( range ) {
			panel.innerHTML = '<h2 class="omc-ve__h">' + esc( T.rangeTitle ) + '</h2>' +
				'<p class="omc-ve__big">' + esc( P.clock( range[ 0 ] ) ) + ' – ' + esc( P.clock( range[ 1 ] ) ) + '</p><p class="omc-ve__note">' + esc( T.cutNote ) + '</p>' +
				'<div class="omc-ve__row"><button type="button" class="button button-primary omc-ve__docut">' + esc( T.cutPart ) + '</button></div>';
			panel.querySelector( '.omc-ve__docut' ).addEventListener( 'click', function () {
				cut( range[ 0 ], range[ 1 ] );
			} );
		} else {
			paintAdd();
		}
	}

	function typeName( type ) {
		return { text: T.addText, spot: T.addSpot, poll: T.addPoll, button: T.addButton, jump: T.addJump, signup: T.addSignup }[ type ] || '';
	}

	function paintAdd() {
		var kinds = [ [ 'text', T.addText, T.hintText ], [ 'piece', T.addPiece, T.hintPiece ], [ 'spot', T.addSpot, T.hintSpot ], [ 'poll', T.addPoll, T.hintPoll ], [ 'button', T.addButton, T.hintButton ], [ 'jump', T.addJump, T.hintJump ], [ 'signup', T.addSignup, T.hintSignup ] ];
		panel.innerHTML = '<h2 class="omc-ve__h">' + esc( T.addTitle ) + '</h2><div class="omc-ve__adds">' + kinds.map( function ( k ) {
			return '<button type="button" class="omc-ve__add" data-add="' + k[ 0 ] + '"><b>' + esc( k[ 1 ] ) + '</b><small>' + esc( k[ 2 ] ) + '</small></button>';
		} ).join( '' ) + '</div>' +
		( V.added ? '<h2 class="omc-ve__h">' + esc( T.cover ) + '</h2><div class="omc-ve__cover">' + ( V.poster ? '<img src="' + esc( V.poster ) + '" alt="">' : '' ) + '<div><small>' + esc( T.coverHint ) + '</small><button type="button" class="button omc-ve__usecover">' + esc( T.coverUse ) + '</button></div></div>' : '' ) +
		'<h2 class="omc-ve__h">' + esc( T.keysTitle ) + '</h2><p class="omc-ve__note">' + esc( T.keys ) + '</p>';
		panel.querySelectorAll( '[data-add]' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				if ( 'piece' === b.getAttribute( 'data-add' ) ) {
					addMoment( b );
				} else {
					addItem( b.getAttribute( 'data-add' ), b );
				}
			} );
		} );
		var useCover = panel.querySelector( '.omc-ve__usecover' );
		if ( useCover ) {
			useCover.addEventListener( 'click', takeCover );
		}
	}

	function wireTiming( thing, whole ) {
		var atField = panel.querySelector( '[data-f="at"]' );
		var forField = panel.querySelector( '[data-f="for"]' );
		atField.addEventListener( 'change', function () {
			var s = parseTime( atField.value );
			if ( null !== s ) {
				thing.at = whole ? Math.round( clamp( s, 0, duration ) ) : tenth( clamp( s, 0, duration ) );
			}
			atField.value = P.clock( thing.at );
			commit();
			paintTimeline();
			paintPreview();
		} );
		forField.addEventListener( 'change', function () {
			var n = parseFloat( forField.value );
			thing.for = clamp( isNaN( n ) ? thing.for : n, 'moment' === whole ? 3 : 1, 3600 );
			forField.value = thing.for;
			commit();
			paintTimeline();
			paintPreview();
		} );
	}

	function wirePick( thing ) {
		panel.querySelector( '.omc-ve__pick' ).addEventListener( 'click', function ( e ) {
			window.OMCPiecePicker.open( e.currentTarget, function ( p ) {
				known[ p.id ] = p;
				thing.product = p.id;
				commit();
				paintAll();
			} );
		} );
	}

	function wireItem( item ) {
		wireTiming( item, false );
		if ( 'spot' === item.type ) {
			wirePick( item );
		}
		panel.querySelectorAll( '[data-f]' ).forEach( function ( f ) {
			var key = f.getAttribute( 'data-f' );
			if ( 'at' === key || 'for' === key ) {
				return;
			}
			var read = function () {
				if ( 'options' === key ) {
					item.options = f.value.split( '\n' ).map( function ( s ) {
						return s.trim();
					} ).filter( Boolean ).slice( 0, 4 );
				} else if ( 'checkbox' === f.type ) {
					item[ key ] = f.checked;
				} else {
					item[ key ] = f.value;
				}
				paintPreview();
			};
			f.addEventListener( 'input', read );
			f.addEventListener( 'change', function () {
				read();
				commit();
				paintTimeline();
			} );
		} );
		panel.querySelectorAll( '[data-c]' ).forEach( function ( f ) {
			f.addEventListener( 'change', function () {
				var c = item.choices[ parseInt( f.getAttribute( 'data-c' ), 10 ) ];
				if ( 'to' === f.getAttribute( 'data-k' ) ) {
					var s = parseTime( f.value );
					c.to = null === s ? c.to : tenth( clamp( s, 0, duration ) );
					f.value = P.clock( c.to );
				} else {
					c.label = f.value;
				}
				commit();
				paintPreview();
			} );
		} );
		panel.querySelectorAll( '[data-now]' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				item.choices[ parseInt( b.getAttribute( 'data-now' ), 10 ) ].to = tenth( video.currentTime || 0 );
				commit();
				paintPanel();
			} );
		} );
		panel.querySelector( '.omc-ve__dup' ).addEventListener( 'click', function () {
			var copy = JSON.parse( JSON.stringify( item ) );
			copy.id = newId();
			copy.at = tenth( clamp( item.at + item.for, 0, duration ) );
			doc.items.push( copy );
			selected = { kind: 'item', id: copy.id };
			commit();
			paintAll();
		} );
		panel.querySelector( '.omc-ve__del' ).addEventListener( 'click', removeSelected );
	}

	function wireMoment( m ) {
		wireTiming( m, 'moment' );
		wirePick( m );
		panel.querySelector( '.omc-ve__del' ).addEventListener( 'click', removeSelected );
	}

	function removeSelected() {
		if ( ! selected ) {
			return;
		}
		if ( 'item' === selected.kind ) {
			doc.items = doc.items.filter( function ( i ) {
				return i.id !== selected.id;
			} );
		} else if ( 'moment' === selected.kind ) {
			doc.moments = doc.moments.filter( function ( m ) {
				return m.id !== selected.id;
			} );
		} else if ( 'cut' === selected.kind ) {
			doc.cuts.splice( selected.id, 1 );
		} else if ( 'range' === selected.kind ) {
			range = null;
		}
		selected = null;
		commit();
		paintAll();
	}

	function paintAll() {
		paintPanel();
		paintTimeline();
		paintPreview();
		paintTime();
	}

	/* ─────────────────────────── the wires ─────────────────────────── */

	function api( url, body ) {
		return window.fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': D.rest.nonce },
			body: JSON.stringify( body )
		} ).then( function ( r ) {
			return r.json().then( function ( j ) {
				if ( ! r.ok ) {
					throw new Error( j.message || 'HTTP ' + r.status );
				}
				return j;
			} );
		} );
	}

	function save() {
		if ( busy || ! dirty() ) {
			return;
		}
		busy = true;
		saveBtn.textContent = T.saving;
		changed( T.saving, 'busy' );
		api( D.rest.save, {
			page: V.page,
			cuts: doc.cuts,
			items: doc.items,
			moments: doc.moments.filter( function ( m ) {
				return m.product;
			} ),
			place: doc.place
		} ).then( function ( j ) {
			doc.cuts = j.cuts;
			doc.items = j.items;
			doc.moments = withIds( j.moments );
			doc.place = { x: +j.place.x || 0, y: +j.place.y || 0 };
			if ( selected && ! found( selected ) ) {
				selected = null;
			}
			history[ at ] = JSON.stringify( doc );
			saved = history[ at ];
			busy = false;
			saveBtn.textContent = T.save;
			paintAll();
			changed();
		} ).catch( function ( e ) {
			busy = false;
			saveBtn.textContent = T.save;
			changed( T.failed + ': ' + e.message, 'bad' );
			saveBtn.disabled = false;
		} );
	}
	saveBtn.addEventListener( 'click', save );

	function takeCover() {
		var c = document.createElement( 'canvas' );
		c.width = video.videoWidth;
		c.height = video.videoHeight;
		var data = '';
		try {
			c.getContext( '2d' ).drawImage( video, 0, 0 );
			data = c.toDataURL( 'image/jpeg', 0.88 );
		} catch ( e ) {
			changed( T.coverFail, 'bad' );
			return;
		}
		api( D.rest.cover, { page: V.page, poster: data } ).then( function ( j ) {
			V.poster = j.poster;
			video.setAttribute( 'poster', j.poster );
			paintPanel();
			changed( T.coverDone, 'ok' );
		} ).catch( function ( e ) {
			changed( T.coverFail + ' ' + e.message, 'bad' );
		} );
	}

	var pubBtn = q( '.omc-ve__pub' );
	if ( pubBtn ) {
		pubBtn.addEventListener( 'click', function () {
			if ( dirty() ) {
				changed( T.saveFirst, 'bad' );
				return;
			}
			pubBtn.disabled = true;
			api( D.rest.status, { page: V.page, status: 'publish' === V.status ? 'draft' : 'publish' } ).then( function ( j ) {
				V.status = j.status;
				V.view = j.view;
				paintHead();
			} ).catch( function ( e ) {
				changed( e.message, 'bad' );
			} ).then( function () {
				pubBtn.disabled = false;
			} );
		} );
	}

	q( '.omc-ve__undo' ).addEventListener( 'click', function () {
		restore( at - 1 );
	} );
	q( '.omc-ve__redo' ).addEventListener( 'click', function () {
		restore( at + 1 );
	} );

	document.addEventListener( 'keydown', function ( e ) {
		var typing = /^(INPUT|TEXTAREA|SELECT)$/.test( e.target.tagName ) && 'range' !== e.target.type;
		if ( ( e.ctrlKey || e.metaKey ) && 'z' === e.key.toLowerCase() && ! typing ) {
			e.preventDefault();
			restore( e.shiftKey ? at + 1 : at - 1 );
			return;
		}
		if ( ( e.ctrlKey || e.metaKey ) && 's' === e.key.toLowerCase() ) {
			e.preventDefault();
			save();
			return;
		}
		if ( typing || e.ctrlKey || e.metaKey || e.altKey ) {
			return;
		}
		if ( ' ' === e.key && 'BUTTON' !== e.target.tagName ) {
			e.preventDefault();
			toggle();
		} else if ( 'ArrowLeft' === e.key || 'ArrowRight' === e.key ) {
			e.preventDefault();
			goTo( video.currentTime + ( 'ArrowLeft' === e.key ? -1 : 1 ) * ( e.shiftKey ? 5 : 1 ) );
			keepInView();
		} else if ( 'i' === e.key ) {
			cut( 0, video.currentTime );
		} else if ( 'o' === e.key ) {
			cut( video.currentTime, duration );
		} else if ( ( 'Delete' === e.key || 'Backspace' === e.key ) && selected ) {
			e.preventDefault();
			removeSelected();
		} else if ( 'Escape' === e.key ) {
			range = null;
			select( null );
		}
	} );

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( dirty() ) {
			e.preventDefault();
			e.returnValue = T.leave;
			return T.leave;
		}
	} );

	paintHead();
	layout();
	paintAll();
	changed();
}() );
