/**
 * Image Editor: an editor in the browser for any image in the media library, on the lines of Canva —
 * templates, text in thirty-odd fonts with style presets and text effects, badges and stickers,
 * shapes, filters and adjustments, sizes for every place a picture goes, layers, undo. Built on
 * Fabric.js (admin/vendor), which does the drawing, the handles and the filters.
 *
 * The design is kept at its real size — 1080 × 1350 is 1080 × 1350 — and shown scaled to fit, so
 * what is saved is the size that was chosen, not the size of the screen. Saving writes a new image
 * to the media library and keeps the layers on it; the photo it was made from is never changed.
 *
 *   WHDImageEditor.mount( root, options )   once
 *   WHDImageEditor.open( photo )            a photo object (id, work, full, thumb, title, alt, design)
 *   WHDImageEditor.openIds( ids )           fetched from the library first
 *   WHDImageEditor.pick()                   the library, to choose the images to edit
 *   WHDImageEditor.dirty()                  whether anything is not saved
 */
( function () {
	'use strict';

	var P = window.WHDImagePresets;
	var F = window.fabric;
	if ( ! P || ! F ) {
		return;
	}

	var T = {};
	var O = {};
	var esc = function ( s ) {
		var d = document.createElement( 'div' );
		d.textContent = null == s ? '' : String( s );
		return d.innerHTML;
	};

	var PROPS = [ 'whdKind', 'whdName', 'whdFx', 'whdBadge', 'whdDecor', 'whdCentre', 'whdLocked', 'whdAuto', 'whdFull', 'whdTextColour', 'strokeUniform', 'selectable', 'evented', 'hasControls',
		'lockMovementX', 'lockMovementY', 'lockScalingX', 'lockScalingY', 'lockRotation', 'visible', 'clipPath' ];
	var EMOJI_FONT = '"Segoe UI Emoji","Apple Color Emoji","Noto Color Emoji",sans-serif';

	var el = {};
	var canvas = null;
	var mounted = false;
	var pending = [];
	var W = 1080;
	var H = 1350;
	var zoom = 1;
	var fitted = true;          // the zoom follows the stage until somebody zooms by hand
	var queue = [];             // [ { key, photo, state, past, future, dirty, saved } ]
	var item = null;
	var past = [];
	var future = [];
	var restoring = false;
	var timer = null;
	var tab = 'templates';
	var clipboard = null;
	var guides = [];

	/* ─────────────────────────── small things ─────────────────────────── */

	function fmt( text ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( text || '' ).replace( /%(\d+)\$[sd]|%[sd]/g, function ( m, n ) {
			return String( n ? args[ n - 1 ] : args[ i++ ] );
		} );
	}

	function currency( s ) {
		return O.currency ? String( s ).replace( /₱/g, O.currency ) : s;
	}

	function brandText( piece ) {
		if ( piece.title && item && item.photo && item.photo.title && ! item.photo.blank ) {
			return item.photo.title;
		}
		return piece.brand && O.brand && O.brand.name ? ( /^[A-Z\s—.,]+$/.test( piece.text ) ? O.brand.name.toUpperCase() : O.brand.name ) : piece.text;
	}

	/* A font has to be there before text is measured, or every box comes out the width of the fallback. */
	var fontsReady = {};
	function ensureFont( family, weight, style ) {
		var key = family + '|' + ( weight || 400 ) + '|' + ( style || 'normal' );
		if ( ! family || fontsReady[ key ] ) {
			return Promise.resolve();
		}
		if ( ! document.fonts || ! document.fonts.load ) {
			return Promise.resolve();
		}
		return document.fonts.load( ( style || 'normal' ) + ' ' + ( weight || 400 ) + ' 48px "' + family + '"' ).then( function () {
			fontsReady[ key ] = true;
			F.util.clearFabricFontCache( family );
		}, function () {} );
	}

	function base() {
		return canvas ? canvas.getObjects().filter( function ( o ) {
			return 'base' === o.whdKind;
		} )[ 0 ] || null : null;
	}

	function shadowOf( size, kind ) {
		if ( 'glow' === kind ) {
			return new F.Shadow( { color: 'rgba(255,214,170,0.95)', blur: size * 0.45, offsetX: 0, offsetY: 0 } );
		}
		if ( 'lift' === kind ) {
			return new F.Shadow( { color: 'rgba(0,0,0,0.28)', blur: size * 0.5, offsetX: 0, offsetY: size * 0.12 } );
		}
		if ( 'splice' === kind ) {
			return new F.Shadow( { color: 'rgba(185,139,126,0.9)', blur: 0, offsetX: size * 0.06, offsetY: size * 0.06 } );
		}
		return new F.Shadow( { color: 'rgba(0,0,0,0.35)', blur: size * 0.25, offsetX: 0, offsetY: size * 0.06 } );
	}

	/* A regular polygon with n corners, one corner at the top (turned by `turn` radians). */
	function regular( n, r, turn ) {
		var pts = [];
		for ( var i = 0; i < n; i++ ) {
			var a = 2 * Math.PI * i / n - Math.PI / 2 + ( turn || ( n % 2 ? 0 : Math.PI / n ) );
			pts.push( { x: Math.cos( a ) * r, y: Math.sin( a ) * r } );
		}
		return pts;
	}

	function starPoints( n, outer, inner ) {
		var pts = [];
		for ( var i = 0; i < n * 2; i++ ) {
			var r = i % 2 ? inner : outer;
			var a = Math.PI * i / n - Math.PI / 2;
			pts.push( { x: Math.cos( a ) * r, y: Math.sin( a ) * r } );
		}
		return pts;
	}

	/* ─────────────────────────── the screen ─────────────────────────── */

	var ICONS = {
		templates: '<path d="M3 3h7v7H3zM14 3h7v4h-7zM14 11h7v10h-7zM3 14h7v7H3z"/>',
		text: '<path d="M4 5h16M12 5v15M8 20h8"/>',
		stickers: '<path d="M12 3l2.6 5.6 6.1.7-4.5 4.2 1.2 6L12 16.6 6.6 19.5l1.2-6L3.3 9.3l6.1-.7z"/>',
		shapes: '<rect x="3" y="3" width="8" height="8" rx="1"/><circle cx="17" cy="17" r="4"/><path d="M17 3l4 7h-8z"/>',
		effects: '<path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/>',
		photos: '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/>',
		size: '<path d="M7 3v14h14M3 7h14v14"/>'
	};

	function icon( name ) {
		return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICONS[ name ] + '</svg>';
	}

	function build( root ) {
		var tabs = [ 'templates', 'text', 'stickers', 'shapes', 'effects', 'photos', 'size' ];
		var names = { templates: T.tabTemplates, text: T.tabText, stickers: T.tabStickers, shapes: T.tabShapes, effects: T.tabEffects, photos: T.tabPhotos, size: T.tabSize };
		root.innerHTML =
			'<div class="whd-ie__top">' +
				'<div class="whd-ie__tools">' +
					'<button type="button" class="whd-ie__tool" data-act="undo" title="' + esc( T.undo ) + ' (Ctrl+Z)" aria-label="' + esc( T.undo ) + '"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14L4 9l5-5"/><path d="M4 9h11a5 5 0 010 10h-3"/></svg></button>' +
					'<button type="button" class="whd-ie__tool" data-act="redo" title="' + esc( T.redoE ) + ' (Ctrl+Shift+Z)" aria-label="' + esc( T.redoE ) + '"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 14l5-5-5-5"/><path d="M20 9H9a5 5 0 000 10h3"/></svg></button>' +
					'<span class="whd-ie__sep"></span>' +
					'<button type="button" class="whd-ie__tool" data-act="zoom-out" aria-label="' + esc( T.zoomOut ) + '">−</button>' +
					'<button type="button" class="whd-ie__zoom" data-act="zoom-fit" title="' + esc( T.zoomFit ) + '"></button>' +
					'<button type="button" class="whd-ie__tool" data-act="zoom-in" aria-label="' + esc( T.zoomIn ) + '">+</button>' +
					'<span class="whd-ie__sep"></span>' +
					'<span class="whd-ie__dims" data-dims></span>' +
				'</div>' +
				'<div class="whd-ie__file">' +
					'<label class="screen-reader-text" for="whd-ie-format">' + esc( T.format ) + '</label>' +
					'<select id="whd-ie-format" class="whd-ie__format"><option value="jpeg">JPG</option><option value="png">PNG</option><option value="webp">WebP</option></select>' +
					'<button type="button" class="button" data-act="download">' + esc( T.download ) + '</button>' +
					'<button type="button" class="button button-primary" data-act="save">' + esc( T.save ) + '</button>' +
					'<span class="whd-ie__status" data-status role="status" aria-live="polite"></span>' +
				'</div>' +
			'</div>' +
			'<div class="whd-ie__body">' +
				'<nav class="whd-ie__rail" role="tablist">' + tabs.map( function ( t ) {
					return '<button type="button" class="whd-ie__railbtn' + ( t === tab ? ' is-on' : '' ) + '" data-tab="' + t + '" role="tab">' + icon( t ) + '<span>' + esc( names[ t ] ) + '</span></button>';
				} ).join( '' ) + '</nav>' +
				'<aside class="whd-ie__drawer" data-drawer></aside>' +
				'<main class="whd-ie__stage" data-stage>' +
					'<div class="whd-ie__frame" data-frame><canvas id="whd-ie-canvas"></canvas></div>' +
					'<div class="whd-ie__empty" data-empty>' +
						'<p>' + esc( T.editorEmpty ) + '</p>' +
						'<p class="whd-ie__empty-go">' +
							'<button type="button" class="button button-primary button-large" data-act="open">' + esc( T.openPhoto ) + '</button> ' +
							'<button type="button" class="button button-large" data-act="blank">' + esc( T.blank ) + '</button>' +
						'</p>' +
					'</div>' +
				'</main>' +
				'<aside class="whd-ie__side">' +
					'<section class="whd-ie__props" data-props></section>' +
					'<section class="whd-ie__layers"><h4 class="whd-ie__h">' + esc( T.layers ) + '</h4><ol data-layers></ol></section>' +
				'</aside>' +
			'</div>' +
			'<div class="whd-ie__strip" data-strip></div>';

		el = {
			root: root,
			drawer: root.querySelector( '[data-drawer]' ),
			stage: root.querySelector( '[data-stage]' ),
			frame: root.querySelector( '[data-frame]' ),
			empty: root.querySelector( '[data-empty]' ),
			props: root.querySelector( '[data-props]' ),
			layers: root.querySelector( '[data-layers]' ),
			strip: root.querySelector( '[data-strip]' ),
			status: root.querySelector( '[data-status]' ),
			dims: root.querySelector( '[data-dims]' ),
			zoom: root.querySelector( '.whd-ie__zoom' ),
			format: root.querySelector( '#whd-ie-format' )
		};

		root.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '[data-act]' );
			if ( b && root.contains( b ) && ! b.disabled ) {
				act( b.getAttribute( 'data-act' ), b );
			}
			var t = e.target.closest( '[data-tab]' );
			if ( t ) {
				tab = t.getAttribute( 'data-tab' );
				Array.prototype.forEach.call( root.querySelectorAll( '[data-tab]' ), function ( x ) {
					x.classList.toggle( 'is-on', x === t );
				} );
				paintDrawer();
			}
		} );
	}

	function act( what, button ) {
		switch ( what ) {
			case 'undo': undo(); break;
			case 'redo': redo(); break;
			case 'zoom-in': setZoom( zoom * 1.2 ); break;
			case 'zoom-out': setZoom( zoom / 1.2 ); break;
			case 'zoom-fit': fitted = true; layout(); break;
			case 'download': download(); break;
			case 'save': save( button ); break;
			case 'open': pickPhotos(); break;
			case 'blank': blank(); break;
		}
	}

	function say( text, bad ) {
		el.status.innerHTML = text || '';
		el.status.className = 'whd-ie__status' + ( bad ? ' is-bad' : '' );
	}

	/* ─────────────────────────── the canvas ─────────────────────────── */

	function makeCanvas() {
		F.textureSize = 4096;
		F.Object.prototype.set( {
			transparentCorners: false,
			cornerColor: '#ffffff',
			cornerStrokeColor: '#9c6f63',
			borderColor: '#b98b7e',
			cornerStyle: 'circle',
			cornerSize: 11,
			borderScaleFactor: 1.5,
			padding: 4
		} );
		canvas = new F.Canvas( 'whd-ie-canvas', {
			preserveObjectStacking: true,
			backgroundColor: '#ffffff',
			selectionColor: 'rgba(185,139,126,0.10)',
			selectionBorderColor: '#b98b7e',
			selectionLineWidth: 1
		} );
		var change = function ( e ) {
			if ( e && e.target && e.target.whdGuide ) {
				return;
			}
			commit();
			paintLayers();
		};
		canvas.on( 'object:added', change );
		canvas.on( 'object:removed', change );
		canvas.on( 'object:modified', function ( e ) {
			// Resized by hand: from now on the box keeps the width it was given.
			if ( e && e.target && 'resizing' === e.action ) {
				e.target.whdAuto = false;
			}
			commit();
			paintProps();
		} );
		canvas.on( 'text:changed', function () {
			commit();
			paintLayers();
		} );
		canvas.on( 'selection:created', selected );
		canvas.on( 'selection:updated', selected );
		canvas.on( 'selection:cleared', selected );
		canvas.on( 'object:moving', snap );
		canvas.on( 'mouse:up', clearGuides );
		window.addEventListener( 'resize', function () {
			if ( fitted ) {
				layout();
			}
		} );
		document.addEventListener( 'keydown', keys );
	}

	function selected() {
		paintProps();
		paintLayers();
	}

	/* The design at its real size, shown scaled to the stage. */
	function layout() {
		if ( ! canvas || ! item ) {
			return;
		}
		if ( fitted ) {
			var room = el.stage.getBoundingClientRect();
			var fit = Math.min( ( room.width - 48 ) / W, ( room.height - 48 ) / H );
			zoom = Math.max( 0.05, Math.min( 1, fit ) );
		}
		canvas.setDimensions( { width: Math.round( W * zoom ), height: Math.round( H * zoom ) } );
		canvas.setZoom( zoom );
		canvas.requestRenderAll();
		el.zoom.textContent = Math.round( zoom * 100 ) + '%';
		el.dims.textContent = W + ' × ' + H;
	}

	function setZoom( z ) {
		fitted = false;
		zoom = Math.max( 0.05, Math.min( 4, z ) );
		layout();
	}

	/* Centre guides: an object dragged near the middle snaps to it, and a line says so. */
	function snap( e ) {
		var o = e.target;
		var c = o.getCenterPoint();
		var tol = 8 / zoom;
		var v = Math.abs( c.x - W / 2 ) < tol;
		var h = Math.abs( c.y - H / 2 ) < tol;
		if ( v || h ) {
			o.setPositionByOrigin( new F.Point( v ? W / 2 : c.x, h ? H / 2 : c.y ), 'center', 'center' );
		}
		clearGuides();
		var line = function ( pts ) {
			var g = new F.Line( pts, { stroke: '#e8484d', strokeWidth: 1 / zoom, selectable: false, evented: false, excludeFromExport: true, whdGuide: true, strokeDashArray: [ 6 / zoom, 4 / zoom ] } );
			guides.push( g );
			canvas.add( g );
		};
		if ( v ) {
			line( [ W / 2, 0, W / 2, H ] );
		}
		if ( h ) {
			line( [ 0, H / 2, W, H / 2 ] );
		}
	}

	function clearGuides() {
		guides.forEach( function ( g ) {
			canvas.remove( g );
		} );
		guides = [];
	}

	/* ─────────────────────────── history ─────────────────────────── */

	function snapshot() {
		return JSON.stringify( { W: W, H: H, bg: canvas.backgroundColor, json: canvas.toJSON( PROPS ) } );
	}

	function commit() {
		if ( restoring || ! item ) {
			return;
		}
		window.clearTimeout( timer );
		timer = window.setTimeout( function () {
			var s = snapshot();
			if ( s === past[ past.length - 1 ] ) {
				return;
			}
			past.push( s );
			if ( past.length > 60 ) {
				past.shift();
			}
			future = [];
			item.dirty = true;
			paintStrip();
			paintTools();
		}, 200 );
	}

	function restore( s, done ) {
		var d = JSON.parse( s );
		restoring = true;
		W = d.W;
		H = d.H;
		canvas.loadFromJSON( d.json, function () {
			canvas.backgroundColor = d.bg || '#ffffff';
			afterLoad().then( function () {
				restoring = false;
				layout();
				paintProps();
				paintLayers();
				paintTools();
				if ( done ) {
					done();
				}
			} );
		} );
	}

	/* Text measured again once its fonts are there; images that may not be moved, kept that way. */
	function afterLoad() {
		var waits = [];
		canvas.getObjects().forEach( function ( o ) {
			if ( o.fontFamily ) {
				waits.push( ensureFont( o.fontFamily, o.fontWeight, o.fontStyle ) );
			}
			if ( o._objects ) {
				o._objects.forEach( function ( c ) {
					if ( c.fontFamily ) {
						waits.push( ensureFont( c.fontFamily, c.fontWeight, c.fontStyle ) );
					}
				} );
			}
		} );
		return Promise.all( waits ).then( function () {
			canvas.getObjects().forEach( function ( o ) {
				if ( o.initDimensions ) {
					o.initDimensions();
				}
				o.setCoords();
			} );
			canvas.requestRenderAll();
		} );
	}

	function undo() {
		if ( past.length < 2 ) {
			return;
		}
		future.push( past.pop() );
		restore( past[ past.length - 1 ] );
		item.dirty = true;
		paintStrip();
	}

	function redo() {
		if ( ! future.length ) {
			return;
		}
		var s = future.pop();
		past.push( s );
		restore( s );
		item.dirty = true;
		paintStrip();
	}

	function paintTools() {
		var u = el.root.querySelector( '[data-act="undo"]' );
		var r = el.root.querySelector( '[data-act="redo"]' );
		if ( u ) {
			u.disabled = past.length < 2;
		}
		if ( r ) {
			r.disabled = ! future.length;
		}
	}

	/* ─────────────────────────── the queue of photos ─────────────────────────── */

	function open( photo ) {
		if ( ! mounted ) {
			pending.push( photo );
			return;
		}
		var found = null;
		queue.forEach( function ( q ) {
			if ( photo.id && q.photo.id === photo.id ) {
				found = q;
			}
		} );
		if ( ! found ) {
			found = { key: 'q' + Date.now() + Math.random(), photo: photo, state: null, past: [], future: [], dirty: false, saved: null };
			queue.push( found );
		}
		switchTo( found );
	}

	function stash() {
		if ( item && canvas ) {
			item.state = snapshot();
			item.past = past;
			item.future = future;
		}
	}

	function switchTo( next ) {
		stash();
		item = next;
		past = next.past || [];
		future = next.future || [];
		el.empty.hidden = true;
		el.frame.hidden = false;
		paintStrip();
		fitted = true;
		if ( next.state ) {
			restore( next.state );
			return;
		}
		if ( next.photo.design ) {
			// Saved from here before: its layers come back, on the photo it was made from.
			var d = null;
			try {
				d = JSON.parse( next.photo.design );
			} catch ( e ) {
				d = null;
			}
			if ( d && d.json ) {
				restore( JSON.stringify( d ), function () {
					past = [ snapshot() ];
					paintTools();
				} );
				return;
			}
		}
		if ( next.photo.blank ) {
			W = next.photo.w || 1080;
			H = next.photo.h || 1350;
			restoring = true;
			canvas.clear();
			canvas.backgroundColor = '#ffffff';
			restoring = false;
			layout();
			past = [ snapshot() ];
			paintAll();
			return;
		}
		say( '' );
		F.Image.fromURL( next.photo.work || next.photo.full || next.photo.large, function ( img ) {
			if ( ! img || ! img.width ) {
				say( T.error || 'Something went wrong', true );
				return;
			}
			// The photo's own size, kept under 2160 on its long side: plenty for any place it goes.
			var s = Math.min( 1, 2160 / Math.max( img.width, img.height ) );
			W = Math.round( img.width * s );
			H = Math.round( img.height * s );
			restoring = true;
			canvas.clear();
			canvas.backgroundColor = '#ffffff';
			img.set( { whdKind: 'base', whdName: T.basePhoto, whdFx: { preset: 'none', adjust: {} } } );
			placeBase( img, 'fill' );
			canvas.add( img );
			canvas.sendToBack( img );
			restoring = false;
			layout();
			past = [ snapshot() ];
			future = [];
			paintAll();
		}, { crossOrigin: 'anonymous' } );
	}

	function placeBase( img, how ) {
		var s = 'fit' === how ? Math.min( W / img.width, H / img.height ) : Math.max( W / img.width, H / img.height );
		img.set( { scaleX: s, scaleY: s, left: W / 2, top: H / 2, originX: 'center', originY: 'center', angle: 0, clipPath: null } );
		img.setCoords();
	}

	function blank() {
		open( { id: 0, blank: true, title: T.blank, thumb: '', w: 1080, h: 1350 } );
	}

	function paintStrip() {
		var html = queue.map( function ( q, i ) {
			return '<button type="button" class="whd-ie__thumb' + ( q === item ? ' is-on' : '' ) + '" data-q="' + i + '" title="' + esc( q.photo.title || '' ) + '">' +
				( q.photo.thumb ? '<img src="' + esc( q.photo.thumb ) + '" alt="">' : '<span class="whd-ie__thumb-blank"></span>' ) +
				( q.dirty ? '<span class="whd-ie__dot" title="' + esc( T.switchUnsaved ) + '"></span>' : ( q.saved ? '<span class="whd-ie__tick" title="' + esc( T.savedTag ) + '">✓</span>' : '' ) ) +
			'</button>';
		} ).join( '' );
		el.strip.innerHTML = html +
			'<button type="button" class="whd-ie__thumb is-add" data-act="open" title="' + esc( T.openPhotoD ) + '"><span>+</span><small>' + esc( T.openPhoto ) + '</small></button>' +
			'<button type="button" class="whd-ie__thumb is-add" data-act="blank"><span>▢</span><small>' + esc( T.blank ) + '</small></button>';
		Array.prototype.forEach.call( el.strip.querySelectorAll( '[data-q]' ), function ( b ) {
			b.addEventListener( 'click', function () {
				var q = queue[ parseInt( b.getAttribute( 'data-q' ), 10 ) ];
				if ( q && q !== item ) {
					switchTo( q );
				}
			} );
		} );
	}

	/* The library, and the photos chosen there opened one after another in the strip. */
	function pickPhotos( onPick ) {
		if ( ! window.wp || ! wp.media ) {
			return;
		}
		var frame = wp.media( { title: T.openPhoto, button: { text: T.use }, library: { type: 'image' }, multiple: ! onPick } );
		frame.on( 'open', function () {
			frame.content.mode( 'browse' );
		} );
		frame.on( 'select', function () {
			var ids = frame.state().get( 'selection' ).map( function ( m ) {
				return m.get( 'id' );
			} );
			if ( onPick ) {
				fetchPhoto( ids[ 0 ] ).then( onPick );
			} else {
				openIds( ids );
			}
		} );
		frame.open();
	}

	function fetchPhoto( id ) {
		return O.client.send( 'photo', { id: id } );
	}

	function openIds( ids ) {
		return ids.reduce( function ( chain, id ) {
			return chain.then( function () {
				return fetchPhoto( id ).then( open, function ( e ) {
					say( e.message, true );
				} );
			} );
		}, Promise.resolve() );
	}

	/* ─────────────────────────── making things ─────────────────────────── */

	function add( obj, keepPlace ) {
		if ( ! keepPlace ) {
			obj.set( { left: W / 2, top: H / 2, originX: 'center', originY: 'center' } );
		}
		canvas.add( obj );
		obj.setCoords();
		canvas.setActiveObject( obj );
		canvas.requestRenderAll();
		return obj;
	}

	/* A text box in a style: everything a preset can say, in fractions of the design's width. */
	function makeText( st ) {
		return ensureFont( st.font, st.weight, st.style ).then( function () {
			var size = Math.round( ( st.s || 0.06 ) * W );
			var words = currency( st.caps ? String( st.text ).toUpperCase() : st.text );
			var t = new F.Textbox( words, {
				fontFamily: st.font || 'Manrope',
				fontWeight: st.weight || 400,
				fontStyle: st.style || 'normal',
				fontSize: size,
				fill: st.fill || '#3a2b26',
				textAlign: st.align || 'center',
				charSpacing: st.spacing || 0,
				lineHeight: st.lineHeight || 1.12,
				width: ( st.width || 0.9 ) * W,
				stroke: st.stroke || null,
				strokeWidth: st.strokeWidth ? st.strokeWidth * W : 0,
				paintFirst: 'stroke',
				textBackgroundColor: st.highlight || '',
				shadow: st.shadow ? shadowOf( size ) : null,
				originX: st.origin || 'center',
				originY: 'center',
				left: W * ( null == st.x ? 0.5 : st.x ),
				top: H * ( null == st.y ? 0.5 : st.y ),
				splitByGrapheme: false,
				whdKind: 'text',
				whdName: String( words ).slice( 0, 28 )
			} );
			if ( ! st.width ) {
				// One line wide, not the whole width: a short word should not carry a box across the picture.
				t.whdAuto = true;
				refit( t );
			}
			if ( st.fx ) {
				styleText( t, st.fx );
			}
			return t;
		} );
	}

	/* A badge: a shape with words on it, rebuilt whole when its words or colours change. */
	function makeBadge( def, opts ) {
		opts = opts || {};
		var size = ( opts.s || def.s || 0.22 ) * W;
		return ensureFont( def.font, def.weight ).then( function () {
			var words = currency( def.text );
			var label = new F.Textbox( words, {
				fontFamily: def.font, fontWeight: def.weight || 700, fill: def.color, textAlign: 'center',
				originX: 'center', originY: 'center', left: 0, top: 0, charSpacing: 60, lineHeight: 1, width: size
			} );
			var pieces = [];
			var shape = def.shape;
			var fs;
			if ( 'pill' === shape || 'ribbon' === shape || 'tag' === shape ) {
				var h = size * 0.26;
				fs = h * 0.42;
				label.set( { fontSize: fs, width: 4 * size } );
				var tw = label.calcTextWidth();
				label.set( 'width', tw + 4 );
				var w = tw + h * ( 'tag' === shape ? 1.3 : 0.95 ) + ( 'ribbon' === shape ? h * 0.6 : 0 );
				if ( 'pill' === shape ) {
					pieces.push( new F.Rect( { width: w, height: h, rx: h / 2, ry: h / 2, fill: def.fill, originX: 'center', originY: 'center', left: 0, top: 0 } ) );
				} else if ( 'ribbon' === shape ) {
					var n = h * 0.32;
					pieces.push( new F.Polygon( [ { x: -w / 2, y: -h / 2 }, { x: w / 2, y: -h / 2 }, { x: w / 2 - n, y: 0 }, { x: w / 2, y: h / 2 }, { x: -w / 2, y: h / 2 }, { x: -w / 2 + n, y: 0 } ], { fill: def.fill, originX: 'center', originY: 'center', left: 0, top: 0 } ) );
				} else {
					var p = h * 0.42;
					pieces.push( new F.Polygon( [ { x: -w / 2 + p, y: -h / 2 }, { x: w / 2, y: -h / 2 }, { x: w / 2, y: h / 2 }, { x: -w / 2 + p, y: h / 2 }, { x: -w / 2, y: 0 } ], { fill: def.fill, stroke: 'rgba(0,0,0,0.08)', strokeWidth: 1, originX: 'center', originY: 'center', left: 0, top: 0 } ) );
					pieces.push( new F.Circle( { radius: h * 0.08, fill: 'rgba(0,0,0,0.22)', originX: 'center', originY: 'center', left: -w / 2 + p * 0.9, top: 0 } ) );
					label.set( 'left', p * 0.35 );
				}
			} else {
				var r = size / 2;
				if ( 'circle' === shape ) {
					pieces.push( new F.Circle( { radius: r, fill: def.fill, originX: 'center', originY: 'center', left: 0, top: 0 } ) );
				} else if ( 'burst' === shape ) {
					pieces.push( new F.Polygon( starPoints( 16, r, r * 0.84 ), { fill: def.fill, originX: 'center', originY: 'center', left: 0, top: 0 } ) );
				} else if ( 'star' === shape ) {
					pieces.push( new F.Polygon( starPoints( 5, r, r * 0.5 ), { fill: def.fill, originX: 'center', originY: 'center', left: 0, top: 0 } ) );
				} else if ( 'stamp' === shape ) {
					pieces.push( new F.Circle( { radius: r, fill: def.fill, stroke: def.color, strokeWidth: size * 0.03, originX: 'center', originY: 'center', left: 0, top: 0 } ) );
					pieces.push( new F.Circle( { radius: r * 0.82, fill: '', stroke: def.color, strokeWidth: size * 0.012, strokeDashArray: [ size * 0.03, size * 0.02 ], originX: 'center', originY: 'center', left: 0, top: 0 } ) );
				} else if ( 'hex' === shape ) {
					pieces.push( new F.Polygon( regular( 6, r, 0 ), { fill: def.fill, originX: 'center', originY: 'center', left: 0, top: 0 } ) );
				} else if ( 'seal' === shape ) {
					pieces.push( new F.Polygon( starPoints( 28, r, r * 0.9 ), { fill: def.fill, originX: 'center', originY: 'center', left: 0, top: 0 } ) );
					pieces.push( new F.Circle( { radius: r * 0.74, fill: '', stroke: def.color, strokeWidth: size * 0.01, opacity: 0.6, originX: 'center', originY: 'center', left: 0, top: 0 } ) );
				} else if ( 'square' === shape ) {
					pieces.push( new F.Rect( { width: size * 0.92, height: size * 0.92, rx: size * 0.1, ry: size * 0.1, fill: def.fill, stroke: 'rgba(0,0,0,0.08)', strokeWidth: 1, originX: 'center', originY: 'center', left: 0, top: 0 } ) );
				} else if ( 'oval' === shape ) {
					pieces.push( new F.Ellipse( { rx: r, ry: r * 0.66, fill: def.fill, originX: 'center', originY: 'center', left: 0, top: 0 } ) );
				} else if ( 'bubble' === shape ) {
					pieces.push( new F.Path( 'M-46 -38 H46 Q50 -38 50 -34 V22 Q50 26 46 26 H-6 L-26 46 L-22 26 H-46 Q-50 26 -50 22 V-34 Q-50 -38 -46 -38 Z', {
						fill: def.fill, stroke: 'rgba(0,0,0,0.12)', strokeWidth: 1, scaleX: size / 100, scaleY: size / 100, originX: 'center', originY: 'center', left: 0, top: size * 0.04
					} ) );
				}
				// The words made to fit inside: smaller until the longest line clears the edge.
				var room = ( 'star' === shape ? 0.9 : ( 'square' === shape || 'bubble' === shape || 'oval' === shape ? 1.5 : 1.32 ) ) * r;
				var tall = ( 'oval' === shape || 'bubble' === shape ? 0.8 : 1.25 ) * r;
				fs = size * ( 'star' === shape ? 0.15 : 0.2 );
				label.set( { fontSize: fs, width: room } );
				for ( var i = 0; i < 40 && ( label.calcTextWidth() > room * 0.98 || label.height > tall ); i++ ) {
					fs *= 0.93;
					label.set( 'fontSize', fs );
				}
				if ( 'bubble' === shape ) {
					label.set( 'top', -size * 0.08 );
				}
			}
			pieces.push( label );
			var g = new F.Group( pieces, {
				originX: 'center', originY: 'center',
				left: W * ( null == opts.x ? 0.5 : opts.x ), top: H * ( null == opts.y ? 0.5 : opts.y ),
				angle: opts.angle || def.angle || 0,
				whdKind: 'badge',
				whdBadge: { shape: def.shape, text: def.text, fill: def.fill, color: def.color, font: def.font, weight: def.weight, s: opts.s || def.s || 0.22 },
				whdName: words
			} );
			return g;
		} );
	}

	function makeDecor( d ) {
		var size = ( d.wide ? 0.4 : 0.2 ) * W;
		var obj;
		if ( d.star ) {
			obj = new F.Polygon( starPoints( d.star, 50, 50 * ( d.inner || 0.45 ) ), { fill: d.fill } );
		} else if ( d.sun ) {
			var rays = [];
			for ( var i = 0; i < 12; i++ ) {
				var a = i * Math.PI / 6;
				rays.push( new F.Triangle( { width: 10, height: 16, fill: d.fill, originX: 'center', originY: 'center', left: Math.cos( a ) * 38, top: Math.sin( a ) * 38, angle: i * 30 + 90 } ) );
			}
			obj = new F.Group( rays.concat( [ new F.Circle( { radius: 24, fill: d.fill, originX: 'center', originY: 'center', left: 0, top: 0 } ) ] ) );
		} else if ( d.flower ) {
			var petals = [];
			for ( var j = 0; j < 6; j++ ) {
				var b = j * Math.PI / 3;
				petals.push( new F.Ellipse( { rx: 13, ry: 22, fill: d.fill, originX: 'center', originY: 'center', left: Math.cos( b ) * 22, top: Math.sin( b ) * 22, angle: j * 60 + 90 } ) );
			}
			obj = new F.Group( petals.concat( [ new F.Circle( { radius: 12, fill: d.centre, originX: 'center', originY: 'center', left: 0, top: 0, whdCentre: true } ) ] ) );
		} else if ( d.layers ) {
			obj = new F.Group( d.layers.map( decorPath ) );
		} else {
			obj = decorPath( d );
		}
		var s = size / Math.max( obj.width || 100, obj.height || 100 );
		obj.set( { scaleX: s, scaleY: s, whdKind: 'decor', whdName: d.name, whdDecor: d.name } );
		return obj;
	}

	/* One path of a mark: filled, outlined, or both (a white bubble with a soft edge). */
	function decorPath( d ) {
		var o = { fill: d.fill || '' };
		if ( d.stroke ) {
			o.stroke = d.stroke;
			o.strokeWidth = d.width || 4;
			o.strokeLineCap = 'round';
			o.strokeLineJoin = 'round';
		}
		return new F.Path( d.path, o );
	}

	function makeShape( kind ) {
		var C = P.colors;
		var o;
		switch ( kind ) {
			case 'rect': o = new F.Rect( { width: W * 0.3, height: W * 0.3, fill: C.rose } ); break;
			case 'rounded': o = new F.Rect( { width: W * 0.34, height: W * 0.22, rx: W * 0.03, ry: W * 0.03, fill: C.sand } ); break;
			case 'circle': o = new F.Circle( { radius: W * 0.14, fill: C.rose } ); break;
			case 'triangle': o = new F.Triangle( { width: W * 0.28, height: W * 0.25, fill: C.ink } ); break;
			case 'line': o = new F.Line( [ 0, 0, W * 0.4, 0 ], { stroke: C.ink, strokeWidth: Math.max( 2, W * 0.005 ) } ); break;
			case 'star': o = new F.Polygon( starPoints( 5, W * 0.13, W * 0.055 ), { fill: '#f2c14e' } ); break;
			case 'frame': {
				var inset = W * 0.04;
				o = new F.Rect( { width: W - inset * 2, height: H - inset * 2, fill: '', stroke: C.white, strokeWidth: W * 0.01, strokeUniform: true, whdFull: true } );
				break;
			}
			case 'band': o = new F.Rect( { width: W, height: H * 0.16, fill: C.sand, whdFull: true } ); break;
			case 'shade-bottom':
			case 'shade-top': return shade( 'shade-top' === kind ? 'top' : 'bottom', 0.55 );
			case 'tint': o = new F.Rect( { width: W, height: H, fill: 'rgba(0,0,0,0.3)', whdFull: true } ); break;
			case 'glow': o = new F.Rect( { width: W, height: H, fill: 'rgba(255,255,255,0.3)', whdFull: true } ); break;
			default: o = describedShape( kind );
		}
		o.set( { whdKind: 'shape', whdName: ( P.shapes.filter( function ( s ) {
			return s.kind === kind;
		} )[ 0 ] || {} ).name } );
		return o;
	}

	function shapeDef( kind ) {
		return P.shapes.filter( function ( s ) {
			return s.kind === kind;
		} )[ 0 ] || {};
	}

	/* The shapes the presets describe rather than the cases above: polygons, paths, outlines, lines,
	   frames and the overlays that cover the whole picture. */
	function describedShape( kind ) {
		var C = P.colors;
		var d = shapeDef( kind );
		var thin = Math.max( 2, W * 0.005 );
		var o;
		if ( d.sides ) {
			o = new F.Polygon( regular( d.sides, W * 0.14 ), { fill: d.fill || C.rose } );
		} else if ( d.path ) {
			o = new F.Path( d.path, d.stroke
				? { fill: '', stroke: d.stroke, strokeWidth: 3, strokeUniform: true, strokeLineJoin: 'round' }
				: { fill: d.fill || C.rose, stroke: C.white === d.fill ? '#d9c9c0' : null, strokeWidth: C.white === d.fill ? 1 : 0, strokeUniform: true } );
			var k = ( d.wide ? 0.42 : 0.28 ) * W / Math.max( o.width || 100, o.height || 100 );
			o.set( { scaleX: k, scaleY: k } );
			if ( d.stroke ) {
				o.set( 'strokeWidth', Math.max( 2, W * 0.006 ) );
			}
		} else if ( d.ring ) {
			o = new F.Circle( { radius: W * 0.12, fill: '', stroke: C.rose, strokeWidth: W * 0.035, strokeUniform: true } );
		} else if ( d.outline ) {
			o = 'circle' === d.outline
				? new F.Circle( { radius: W * 0.14, fill: '', stroke: C.ink, strokeWidth: thin, strokeUniform: true } )
				: new F.Rect( { width: W * 0.38, height: W * 0.24, rx: 'rounded' === d.outline ? W * 0.04 : 0, ry: 'rounded' === d.outline ? W * 0.04 : 0, fill: '', stroke: C.ink, strokeWidth: thin, strokeUniform: true } );
		} else if ( d.line ) {
			var len = W * 0.4;
			if ( 'double' === d.line ) {
				o = new F.Group( [
					new F.Line( [ 0, 0, len, 0 ], { stroke: C.ink, strokeWidth: thin } ),
					new F.Line( [ 0, thin * 3, len, thin * 3 ], { stroke: C.ink, strokeWidth: thin } )
				] );
			} else {
				o = new F.Line( [ 0, 0, len, 0 ], {
					stroke: C.ink,
					strokeWidth: 'thick' === d.line ? W * 0.014 : ( 'dot' === d.line ? W * 0.008 : thin ),
					strokeLineCap: 'dot' === d.line || 'thick' === d.line ? 'round' : 'butt',
					strokeDashArray: 'dash' === d.line ? [ W * 0.022, W * 0.014 ] : ( 'dot' === d.line ? [ 0.01, W * 0.02 ] : null )
				} );
			}
		} else {
			o = overlay( kind );
		}
		return o;
	}

	/* Frames and washes: edge to edge, so they stretch with the picture when its size changes. */
	function overlay( kind ) {
		var C = P.colors;
		var o;
		var line = Math.max( 2, W * 0.004 );
		var inset = Math.min( W, H ) * 0.04;
		switch ( kind ) {
			case 'frame-double':
				o = new F.Group( [
					new F.Rect( { width: W - inset * 2, height: H - inset * 2, fill: '', stroke: C.white, strokeWidth: line * 2, originX: 'center', originY: 'center' } ),
					new F.Rect( { width: W - inset * 3.2, height: H - inset * 3.2, fill: '', stroke: C.white, strokeWidth: line, originX: 'center', originY: 'center' } )
				] );
				break;
			case 'frame-thin':
				o = new F.Rect( { width: W - inset * 1.6, height: H - inset * 1.6, fill: '', stroke: C.white, strokeWidth: line, strokeUniform: true } );
				break;
			case 'corners': {
				var a = Math.min( W, H ) * 0.12;
				var x = W / 2 - inset;
				var y = H / 2 - inset;
				var corner = function ( sx, sy ) {
					return new F.Polyline( [ { x: sx * x, y: sy * ( y - a ) }, { x: sx * x, y: sy * y }, { x: sx * ( x - a ), y: sy * y } ], { fill: '', stroke: C.white, strokeWidth: line * 2, strokeLineCap: 'square' } );
				};
				o = new F.Group( [ corner( -1, -1 ), corner( 1, -1 ), corner( 1, 1 ), corner( -1, 1 ) ] );
				break;
			}
			case 'frame-arch':
			case 'frame-circle': {
				// The picture seen through a window: a cream sheet with an arch (or a circle) cut out of it.
				var w = W * 0.62;
				var top = H * 0.16;
				var bottom = H * 0.86;
				var x1 = ( W - w ) / 2;
				var hole = 'frame-arch' === kind
					? 'M' + x1 + ' ' + bottom + ' V' + ( top + w / 2 ) + ' A' + ( w / 2 ) + ' ' + ( w / 2 ) + ' 0 0 1 ' + ( x1 + w ) + ' ' + ( top + w / 2 ) + ' V' + bottom + ' Z'
					: 'M' + ( W / 2 - w / 2 ) + ' ' + ( H / 2 ) + ' a' + ( w / 2 ) + ' ' + ( w / 2 ) + ' 0 1 0 ' + w + ' 0 a' + ( w / 2 ) + ' ' + ( w / 2 ) + ' 0 1 0 ' + ( -w ) + ' 0 Z';
				o = new F.Path( 'M0 0 H' + W + ' V' + H + ' H0 Z ' + hole, { fill: C.sand, fillRule: 'evenodd' } );
				break;
			}
			case 'band-top':
				o = new F.Rect( { width: W, height: H * 0.16, fill: C.sand } );
				break;
			case 'panel-left':
				o = new F.Rect( { width: W * 0.38, height: H, fill: 'rgba(247,242,237,0.92)' } );
				break;
			case 'wash-rose':
			case 'wash-cream':
				o = new F.Rect( { width: W, height: H } );
				o.set( 'fill', new F.Gradient( {
					type: 'linear',
					gradientUnits: 'pixels',
					coords: { x1: 0, y1: H, x2: 0, y2: 0 },
					colorStops: 'wash-rose' === kind
						? [ { offset: 0, color: 'rgba(185,139,126,0.75)' }, { offset: 1, color: 'rgba(185,139,126,0)' } ]
						: [ { offset: 0, color: 'rgba(247,242,237,0.95)' }, { offset: 0.6, color: 'rgba(247,242,237,0.4)' }, { offset: 1, color: 'rgba(247,242,237,0)' } ]
				} ) );
				break;
			case 'shade-left':
				o = new F.Rect( { width: W * 0.6, height: H } );
				o.set( 'fill', new F.Gradient( {
					type: 'linear',
					gradientUnits: 'pixels',
					coords: { x1: 0, y1: 0, x2: W * 0.6, y2: 0 },
					colorStops: [ { offset: 0, color: 'rgba(0,0,0,0.6)' }, { offset: 1, color: 'rgba(0,0,0,0)' } ]
				} ) );
				break;
			default:
				o = new F.Rect( { width: W * 0.3, height: W * 0.3, fill: C.rose } );
		}
		o.set( { whdFull: true } );
		return o;
	}

	/* The drawer's picture of a shape the presets describe. */
	function shapeSvg( d ) {
		var C = P.colors;
		var body = '';
		if ( d.sides ) {
			body = '<polygon points="' + regular( d.sides, 44 ).map( function ( pt ) {
				return ( 50 + pt.x ).toFixed( 1 ) + ',' + ( 50 + pt.y ).toFixed( 1 );
			} ).join( ' ' ) + '" fill="' + ( d.fill || C.rose ) + '"/>';
		} else if ( d.path ) {
			body = '<path d="' + d.path + '" ' + ( d.stroke ? 'fill="none" stroke="' + d.stroke + '" stroke-width="5" stroke-linejoin="round"' : 'fill="' + ( d.fill || C.rose ) + '"' + ( C.white === d.fill ? ' stroke="#d9c9c0" stroke-width="2"' : '' ) ) + '/>';
		} else if ( d.ring ) {
			body = '<circle cx="50" cy="50" r="34" fill="none" stroke="' + C.rose + '" stroke-width="12"/>';
		} else if ( d.outline ) {
			body = 'circle' === d.outline
				? '<circle cx="50" cy="50" r="40" fill="none" stroke="' + C.ink + '" stroke-width="4"/>'
				: '<rect x="8" y="22" width="84" height="56" rx="' + ( 'rounded' === d.outline ? 12 : 0 ) + '" fill="none" stroke="' + C.ink + '" stroke-width="4"/>';
		} else if ( d.line ) {
			body = 'double' === d.line
				? '<path d="M6 44 H94 M6 56 H94" stroke="' + C.ink + '" stroke-width="4"/>'
				: '<path d="M6 50 H94" stroke="' + C.ink + '" stroke-width="' + ( 'thick' === d.line ? 10 : ( 'dot' === d.line ? 7 : 4 ) ) + '" stroke-linecap="' + ( 'dot' === d.line || 'thick' === d.line ? 'round' : 'butt' ) + '"' + ( 'dash' === d.line ? ' stroke-dasharray="12 8"' : ( 'dot' === d.line ? ' stroke-dasharray="0.1 13"' : '' ) ) + '/>';
		} else {
			var pics = {
				'frame-double': '<rect x="8" y="8" width="84" height="84" fill="#d9c9c0"/><rect x="14" y="14" width="72" height="72" fill="none" stroke="#fff" stroke-width="5"/><rect x="21" y="21" width="58" height="58" fill="none" stroke="#fff" stroke-width="2"/>',
				'frame-thin': '<rect x="8" y="8" width="84" height="84" fill="#d9c9c0"/><rect x="15" y="15" width="70" height="70" fill="none" stroke="#fff" stroke-width="2"/>',
				corners: '<rect x="8" y="8" width="84" height="84" fill="#d9c9c0"/><path d="M16 32V16H32M68 16H84V32M84 68V84H68M32 84H16V68" fill="none" stroke="#fff" stroke-width="5"/>',
				'frame-arch': '<path d="M8 8H92V92H8Z M30 84V44A20 20 0 0 1 70 44V84Z" fill="' + C.sand + '" fill-rule="evenodd" stroke="#d9c9c0" stroke-width="1"/>',
				'frame-circle': '<path d="M8 8H92V92H8Z M26 50a24 24 0 1 0 48 0a24 24 0 1 0 -48 0Z" fill="' + C.sand + '" fill-rule="evenodd" stroke="#d9c9c0" stroke-width="1"/>',
				'band-top': '<rect x="8" y="8" width="84" height="84" fill="#d9c9c0"/><rect x="8" y="8" width="84" height="18" fill="' + C.sand + '"/>',
				'panel-left': '<rect x="8" y="8" width="84" height="84" fill="#d9c9c0"/><rect x="8" y="8" width="32" height="84" fill="' + C.sand + '"/>',
				'wash-rose': '<defs><linearGradient id="wr" x1="0" y1="1" x2="0" y2="0"><stop offset="0" stop-color="' + C.rose + '"/><stop offset="1" stop-color="' + C.rose + '" stop-opacity="0"/></linearGradient></defs><rect x="8" y="8" width="84" height="84" fill="#efe6de"/><rect x="8" y="8" width="84" height="84" fill="url(#wr)"/>',
				'wash-cream': '<defs><linearGradient id="wc" x1="0" y1="1" x2="0" y2="0"><stop offset="0" stop-color="#fff"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></linearGradient></defs><rect x="8" y="8" width="84" height="84" fill="#b98b7e"/><rect x="8" y="8" width="84" height="84" fill="url(#wc)"/>',
				'shade-left': '<defs><linearGradient id="sl" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#3a2b26"/><stop offset="1" stop-color="#3a2b26" stop-opacity="0"/></linearGradient></defs><rect x="8" y="8" width="84" height="84" fill="#d9c9c0"/><rect x="8" y="8" width="60" height="84" fill="url(#sl)"/>'
			};
			body = pics[ d.kind ] || '';
		}
		return '<svg viewBox="0 0 100 100" aria-hidden="true">' + body + '</svg>';
	}

	/* A gradient from nothing to dark over half the picture: what makes white words readable on a photo. */
	function shade( side, strength ) {
		var h = H * 0.5;
		var o = new F.Rect( { width: W, height: h, originX: 'center', originY: 'center', left: W / 2, top: 'top' === side ? h / 2 : H - h / 2 } );
		o.set( 'fill', new F.Gradient( {
			type: 'linear',
			gradientUnits: 'pixels',
			coords: { x1: 0, y1: 'top' === side ? h : 0, x2: 0, y2: 'top' === side ? 0 : h },
			colorStops: [ { offset: 0, color: 'rgba(0,0,0,0)' }, { offset: 1, color: 'rgba(0,0,0,' + ( strength || 0.55 ) + ')' } ]
		} ) );
		o.set( { whdKind: 'shape', whdFull: true, whdName: 'top' === side ? 'Shade, top' : 'Shade, bottom' } );
		return o;
	}

	function emojiOf( ch ) {
		return new F.Text( ch, { fontSize: W * 0.12, fontFamily: EMOJI_FONT, whdKind: 'emoji', whdName: ch } );
	}

	/* A template: its pieces laid over the photo, each made by the makers above. */
	function applyTemplate( tpl ) {
		var jobs = tpl.pieces.map( function ( piece ) {
			switch ( piece.kind ) {
				case 'text': return makeText( Object.assign( {}, piece, { text: brandText( piece ) } ) ).then( function ( t ) {
					return [ t, true ];
				} );
				case 'badge': return makeBadge( piece, piece ).then( function ( g ) {
					return [ g, true ];
				} );
				case 'shade': return Promise.resolve( [ shade( piece.side, piece.strength ), true ] );
				case 'tint': {
					var t2 = new F.Rect( { width: W, height: H, left: W / 2, top: H / 2, originX: 'center', originY: 'center', fill: 'rgba(0,0,0,' + piece.strength + ')', whdKind: 'shape', whdFull: true, whdName: 'Darken' } );
					return Promise.resolve( [ t2, true ] );
				}
				case 'box': return Promise.resolve( [ new F.Rect( {
					width: W * piece.w, height: H * piece.h, rx: W * ( piece.radius || 0 ), ry: W * ( piece.radius || 0 ),
					left: W * piece.x, top: H * piece.y, originX: 'center', originY: 'center', fill: piece.fill, whdKind: 'shape', whdFull: 1 === piece.w, whdName: 'Box'
				} ), true ] );
				case 'line': return Promise.resolve( [ new F.Line( [ 0, 0, W * piece.w, 0 ], {
					stroke: piece.color, strokeWidth: Math.max( 2, W * 0.003 ), left: W * piece.x, top: H * piece.y, originX: 'center', originY: 'center', whdKind: 'shape', whdName: 'Line'
				} ), true ] );
				case 'frame': {
					var inset = W * piece.inset;
					return Promise.resolve( [ new F.Rect( {
						width: W - inset * 2, height: H - inset * 2, left: W / 2, top: H / 2, originX: 'center', originY: 'center',
						fill: '', stroke: piece.color, strokeWidth: W * piece.width, strokeUniform: true, whdKind: 'shape', whdFull: true, whdName: 'Frame'
					} ), true ] );
				}
				case 'polaroid': return polaroid( piece );
			}
			return Promise.resolve( null );
		} );
		Promise.all( jobs ).then( function ( made ) {
			made.forEach( function ( m ) {
				if ( m && m[ 0 ] ) {
					canvas.add( m[ 0 ] );
					m[ 0 ].setCoords();
				}
			} );
			var last = made.filter( Boolean ).pop();
			if ( last && last[ 0 ] ) {
				canvas.setActiveObject( last[ 0 ] );
			}
			canvas.requestRenderAll();
		} );
	}

	/* A white print with the photo inside it and a handwritten caption below. */
	function polaroid( piece ) {
		var b = base();
		canvas.backgroundColor = '#ffffff';
		if ( b ) {
			var box = { left: W * 0.06, top: H * 0.05, width: W * 0.88, height: H * 0.75 };
			var s = Math.max( box.width / b.width, box.height / b.height );
			b.set( { scaleX: s, scaleY: s, left: box.left + box.width / 2, top: box.top + box.height / 2, originX: 'center', originY: 'center' } );
			b.clipPath = new F.Rect( { left: box.left, top: box.top, width: box.width, height: box.height, absolutePositioned: true } );
			b.setCoords();
		}
		return makeText( { text: piece.caption, font: piece.font, weight: piece.weight, s: piece.s, fill: piece.fill, x: 0.5, y: 0.89 } ).then( function ( t ) {
			return [ t, true ];
		} );
	}

	/* ─────────────────────────── effects ─────────────────────────── */

	function fxTarget() {
		var a = canvas.getActiveObject();
		return a && 'image' === a.type ? a : base();
	}

	function applyFx( img, fx ) {
		if ( ! img ) {
			return;
		}
		var Fl = F.Image.filters;
		var preset = P.filters.filter( function ( f ) {
			return f.key === fx.preset;
		} )[ 0 ] || P.filters[ 0 ];
		var list = preset.build( Fl );
		var a = fx.adjust || {};
		if ( a.brightness ) {
			list.push( new Fl.Brightness( { brightness: a.brightness } ) );
		}
		if ( a.contrast ) {
			list.push( new Fl.Contrast( { contrast: a.contrast } ) );
		}
		if ( a.saturation ) {
			list.push( new Fl.Saturation( { saturation: a.saturation } ) );
		}
		if ( a.vibrance ) {
			list.push( new Fl.Vibrance( { vibrance: a.vibrance } ) );
		}
		if ( a.warmth ) {
			list.push( new Fl.BlendColor( { color: a.warmth > 0 ? '#ff8a3d' : '#3d8aff', mode: 'tint', alpha: Math.abs( a.warmth ) * 0.3 } ) );
		}
		if ( a.hue ) {
			list.push( new Fl.HueRotation( { rotation: a.hue } ) );
		}
		if ( a.blur ) {
			list.push( new Fl.Blur( { blur: a.blur } ) );
		}
		if ( a.grain ) {
			list.push( new Fl.Noise( { noise: a.grain } ) );
		}
		if ( a.sharpen ) {
			var k = a.sharpen;
			list.push( new Fl.Convolute( { matrix: [ 0, -k, 0, -k, 1 + 4 * k, -k, 0, -k, 0 ] } ) );
		}
		if ( a.fade ) {
			// Lifted blacks, softened whites: the matte look of a faded print.
			var m = 1 - a.fade;
			list.push( new Fl.ColorMatrix( { matrix: [ m, 0, 0, 0, a.fade * 0.6, 0, m, 0, 0, a.fade * 0.6, 0, 0, m, 0, a.fade * 0.62, 0, 0, 0, 1, 0 ] } ) );
		}
		if ( a.tint ) {
			list.push( new Fl.BlendColor( { color: P.colors.rose, mode: 'tint', alpha: a.tint } ) );
		}
		if ( a.pixelate >= 2 ) {
			list.push( new Fl.Pixelate( { blocksize: a.pixelate } ) );
		}
		img.whdFx = { preset: preset.key, adjust: a };
		img.filters = list;
		img.applyFilters();
		vignette( 'base' === img.whdKind ? ( a.vignette || 0 ) : null );
		canvas.requestRenderAll();
		commit();
	}

	/* Darkened corners, as a layer just above the photo. */
	function vignette( strength ) {
		if ( null === strength ) {
			return;
		}
		var old = canvas.getObjects().filter( function ( o ) {
			return 'vignette' === o.whdKind;
		} )[ 0 ];
		if ( old ) {
			canvas.remove( old );
		}
		if ( ! strength ) {
			return;
		}
		var v = new F.Rect( { width: W, height: H, left: 0, top: 0, selectable: false, evented: false, whdKind: 'vignette', whdName: T.vignette } );
		v.set( 'fill', new F.Gradient( {
			type: 'radial',
			gradientUnits: 'pixels',
			coords: { x1: W / 2, y1: H / 2, r1: Math.min( W, H ) * 0.25, x2: W / 2, y2: H / 2, r2: Math.max( W, H ) * 0.75 },
			colorStops: [ { offset: 0, color: 'rgba(0,0,0,0)' }, { offset: 1, color: 'rgba(0,0,0,' + strength + ')' } ]
		} ) );
		canvas.add( v );
		var b = base();
		canvas.moveTo( v, b ? canvas.getObjects().indexOf( b ) + 1 : 0 );
	}

	/* ─────────────────────────── size ─────────────────────────── */

	/* A new size: everything keeps its place in proportion, and the photo fills or fits the frame. */
	function resize( w, h, how ) {
		w = Math.max( 64, Math.min( 6000, Math.round( w ) ) );
		h = Math.max( 64, Math.min( 6000, Math.round( h ) ) );
		var sx = w / W;
		var sy = h / H;
		var s = Math.min( sx, sy );
		W = w;
		H = h;
		restoring = true;
		canvas.getObjects().forEach( function ( o ) {
			if ( 'base' === o.whdKind ) {
				placeBase( o, how || 'fill' );
				return;
			}
			if ( 'vignette' === o.whdKind ) {
				return;
			}
			var c = o.getCenterPoint();
			if ( o.whdFull ) {
				// Edge to edge (a frame, a shade, a band): it stretches to the new shape instead.
				o.set( { scaleX: o.scaleX * sx, scaleY: o.scaleY * sy } );
				o.setPositionByOrigin( new F.Point( c.x * sx, c.y * sy ), 'center', 'center' );
				o.setCoords();
				return;
			}
			o.set( { scaleX: o.scaleX * s, scaleY: o.scaleY * s } );
			o.setPositionByOrigin( new F.Point( c.x * sx, c.y * sy ), 'center', 'center' );
			o.setCoords();
		} );
		var b = base();
		if ( b && b.whdFx ) {
			vignette( b.whdFx.adjust && b.whdFx.adjust.vignette || 0 );
		}
		restoring = false;
		fitted = true;
		layout();
		commit();
	}

	/* ─────────────────────────── the drawer ─────────────────────────── */

	function paintDrawer() {
		var d = el.drawer;
		var html = '';
		if ( 'templates' === tab ) {
			html = '<h4 class="whd-ie__h">' + esc( T.tabTemplates ) + '</h4><div class="whd-ie__grid is-two">' + P.templates.map( function ( t, i ) {
				return '<button type="button" class="whd-ie__tpl" data-tpl="' + i + '"><span class="whd-ie__tpl-art is-' + i % 6 + '"></span><span>' + esc( t.name ) + '</span></button>';
			} ).join( '' ) + '</div>';
		} else if ( 'text' === tab ) {
			html = '<div class="whd-ie__adds">' +
				'<button type="button" class="whd-ie__add is-h" data-text="heading">' + esc( T.addHeading ) + '</button>' +
				'<button type="button" class="whd-ie__add is-s" data-text="sub">' + esc( T.addSub ) + '</button>' +
				'<button type="button" class="whd-ie__add is-b" data-text="body">' + esc( T.addBody ) + '</button>' +
			'</div><h4 class="whd-ie__h">' + esc( T.combos ) + '</h4><div class="whd-ie__grid is-two">' + P.combos.map( function ( c, i ) {
				var style = 'font-family:\'' + c.font + '\';font-weight:' + ( c.weight || 400 ) + ';font-style:' + ( c.style || 'normal' ) + ';' +
					( c.stroke && 'rgba(0,0,0,0)' === c.fill ? '-webkit-text-stroke:1px #3a2b26;color:transparent;' : 'color:' + ( '#ffffff' === c.fill ? '#3a2b26' : c.fill ) + ';' ) +
					( c.spacing ? 'letter-spacing:' + ( c.spacing / 1000 ) + 'em;' : '' );
				return '<button type="button" class="whd-ie__combo" data-combo="' + i + '"><span style="' + style + '">' + esc( currency( c.text ) ) + '</span></button>';
			} ).join( '' ) + '</div>';
		} else if ( 'stickers' === tab ) {
			html = '<h4 class="whd-ie__h">' + esc( T.badges ) + '</h4><div class="whd-ie__grid is-three">' + P.badges.map( function ( b, i ) {
				return '<button type="button" class="whd-ie__badge is-' + b.shape + '" data-badge="' + i + '" style="--bg:' + b.fill + ';--fg:' + b.color + ';font-family:\'' + b.font + '\'"><span>' + esc( currency( b.text ) ) + '</span></button>';
			} ).join( '' ) + '</div>' +
			'<h4 class="whd-ie__h">' + esc( T.decor ) + '</h4><div class="whd-ie__grid is-four">' + P.decor.map( function ( dd, i ) {
				return '<button type="button" class="whd-ie__decor" data-decor="' + i + '" title="' + esc( dd.name ) + '">' + decorSvg( dd ) + '</button>';
			} ).join( '' ) + '</div>' +
			'<h4 class="whd-ie__h">' + esc( T.emoji ) + '</h4><div class="whd-ie__grid is-six">' + P.emoji.map( function ( e ) {
				return '<button type="button" class="whd-ie__emoji" data-emoji="' + esc( e ) + '">' + esc( e ) + '</button>';
			} ).join( '' ) + '</div>';
		} else if ( 'shapes' === tab ) {
			var drawn = [ 'rect', 'rounded', 'circle', 'triangle', 'line', 'star', 'frame', 'band', 'shade-bottom', 'shade-top', 'tint', 'glow' ];
			html = ( P.shapeGroups || [ { key: '', name: T.tabShapes } ] ).map( function ( g ) {
				var list = P.shapes.filter( function ( s ) {
					return ! g.key || ( s.group || 'basic' ) === g.key;
				} );
				return list.length ? '<h4 class="whd-ie__h">' + esc( g.name ) + '</h4><div class="whd-ie__grid is-three">' + list.map( function ( s ) {
					var art = drawn.indexOf( s.kind ) !== -1 ? '<span class="whd-ie__shape-art"></span>' : '<span class="whd-ie__shape-art is-svg">' + shapeSvg( s ) + '</span>';
					return '<button type="button" class="whd-ie__shape is-' + s.kind + '" data-shape="' + s.kind + '">' + art + '<small>' + esc( s.name ) + '</small></button>';
				} ).join( '' ) + '</div>' : '';
			} ).join( '' );
		} else if ( 'effects' === tab ) {
			var target = fxTarget();
			var fx = target && target.whdFx ? target.whdFx : { preset: 'none', adjust: {} };
			var thumb = item && item.photo && ( item.photo.thumb || item.photo.view );
			html = '<p class="description">' + esc( T.fxOn ) + '</p><h4 class="whd-ie__h">' + esc( T.filters ) + '</h4><div class="whd-ie__grid is-three">' + P.filters.map( function ( f ) {
				return '<button type="button" class="whd-ie__fx' + ( f.key === fx.preset ? ' is-on' : '' ) + '" data-fx="' + f.key + '">' +
					( thumb ? '<img src="' + esc( thumb ) + '" alt="" style="filter:' + f.css + '">' : '<span class="whd-ie__fx-blank" style="filter:' + f.css + '"></span>' ) +
					'<small>' + esc( f.name ) + '</small></button>';
			} ).join( '' ) + '</div><h4 class="whd-ie__h">' + esc( T.adjust ) + ' <button type="button" class="button-link whd-ie__reset" data-fx-reset>' + esc( T.resetFx ) + '</button></h4>' +
			P.adjust.map( function ( a ) {
				var v = ( fx.adjust || {} )[ a.key ] || 0;
				return '<label class="whd-ie__slider"><span>' + esc( T[ a.key ] || a.key ) + '</span>' +
					'<input type="range" min="' + a.min + '" max="' + a.max + '" step="' + a.step + '" value="' + v + '" data-adjust="' + a.key + '"></label>';
			} ).join( '' );
		} else if ( 'photos' === tab ) {
			html = '<div class="whd-ie__adds">' +
				'<button type="button" class="whd-ie__add" data-photo="over">' + esc( T.addPhoto ) + '</button>' +
				'<button type="button" class="whd-ie__add" data-photo="replace">' + esc( T.replacePhoto ) + '</button>' +
			'</div>' + ( ( O.logos || [] ).length ? '<h4 class="whd-ie__h">' + esc( T.yourLogo ) + '</h4><div class="whd-ie__grid is-two">' + O.logos.map( function ( l, i ) {
				return '<button type="button" class="whd-ie__logo is-' + esc( l.name.toLowerCase() ) + '" data-logo="' + i + '"><img src="' + esc( l.url ) + '" alt="' + esc( l.name ) + '"></button>';
			} ).join( '' ) + '</div>' : '' );
		} else if ( 'size' === tab ) {
			html = '<h4 class="whd-ie__h">' + esc( T.formats ) + '</h4><div class="whd-ie__sizes">' + P.sizes.map( function ( s, i ) {
				var on = s.w ? ( s.w === W && s.h === H ) : false;
				return '<button type="button" class="whd-ie__size' + ( on ? ' is-on' : '' ) + '" data-size="' + i + '"><span class="whd-ie__size-art" style="aspect-ratio:' + ( s.w ? s.w + '/' + s.h : '3/4' ) + '"></span><span><strong>' + esc( s.name ) + '</strong><small>' + esc( s.note ) + '</small></span></button>';
			} ).join( '' ) + '</div>' +
			'<h4 class="whd-ie__h">' + esc( T.custom ) + '</h4><div class="whd-ie__custom">' +
				'<label>' + esc( T.width ) + '<input type="number" min="64" max="6000" value="' + W + '" data-cw></label>' +
				'<label>' + esc( T.height ) + '<input type="number" min="64" max="6000" value="' + H + '" data-ch></label>' +
				'<button type="button" class="button" data-custom>' + esc( T.apply ) + '</button>' +
			'</div>' +
			'<h4 class="whd-ie__h">' + esc( T.basePhoto ) + '</h4><p class="whd-ie__row"><button type="button" class="button" data-place="fill">' + esc( T.fill ) + '</button> <button type="button" class="button" data-place="fit">' + esc( T.fit ) + '</button></p>' +
			'<h4 class="whd-ie__h">' + esc( T.background ) + '</h4>' + colourRow( 'bg', canvas ? canvas.backgroundColor : '#ffffff' );
		}
		d.innerHTML = html;
		wireDrawer( d );
	}

	function decorSvg( d ) {
		if ( d.star ) {
			var pts = starPoints( d.star, 46, 46 * ( d.inner || 0.45 ) ).map( function ( p ) {
				return ( 50 + p.x ).toFixed( 1 ) + ',' + ( 50 + p.y ).toFixed( 1 );
			} ).join( ' ' );
			return '<svg viewBox="0 0 100 100"><polygon points="' + pts + '" fill="' + d.fill + '"/></svg>';
		}
		if ( d.sun ) {
			return '<svg viewBox="0 0 100 100"><circle cx="50" cy="50" r="22" fill="' + d.fill + '"/><g stroke="' + d.fill + '" stroke-width="6" stroke-linecap="round"><path d="M50 8v12M50 80v12M8 50h12M80 50h12M20 20l8 8M72 72l8 8M80 20l-8 8M28 72l-8 8"/></g></svg>';
		}
		if ( d.flower ) {
			return '<svg viewBox="0 0 100 100"><g fill="' + d.fill + '"><ellipse cx="50" cy="26" rx="13" ry="20"/><ellipse cx="50" cy="74" rx="13" ry="20"/><ellipse cx="29" cy="38" rx="13" ry="20" transform="rotate(-60 29 38)"/><ellipse cx="71" cy="62" rx="13" ry="20" transform="rotate(-60 71 62)"/><ellipse cx="71" cy="38" rx="13" ry="20" transform="rotate(60 71 38)"/><ellipse cx="29" cy="62" rx="13" ry="20" transform="rotate(60 29 62)"/></g><circle cx="50" cy="50" r="12" fill="' + d.centre + '"/></svg>';
		}
		var one = function ( x ) {
			return '<path d="' + x.path + '" fill="' + ( x.fill || 'none' ) + '" ' + ( x.stroke ? 'stroke="' + x.stroke + '" stroke-width="' + ( x.width || 4 ) + '" stroke-linecap="round" stroke-linejoin="round"' : 'stroke="' + ( '#ffffff' === x.fill ? '#d9c9c0' : 'none' ) + '" stroke-width="2"' ) + '/>';
		};
		return '<svg viewBox="0 0 100 100">' + ( d.layers ? d.layers.map( one ).join( '' ) : one( d ) ) + '</svg>';
	}

	function wireDrawer( d ) {
		var on = function ( sel, fn ) {
			Array.prototype.forEach.call( d.querySelectorAll( sel ), function ( b ) {
				b.addEventListener( 'click', function () {
					if ( item ) {
						fn( b );
					}
				} );
			} );
		};
		on( '[data-tpl]', function ( b ) {
			applyTemplate( P.templates[ +b.getAttribute( 'data-tpl' ) ] );
		} );
		on( '[data-text]', function ( b ) {
			makeText( P.text[ b.getAttribute( 'data-text' ) ] ).then( function ( t ) {
				add( t );
			} );
		} );
		on( '[data-combo]', function ( b ) {
			makeText( P.combos[ +b.getAttribute( 'data-combo' ) ] ).then( function ( t ) {
				add( t );
			} );
		} );
		on( '[data-badge]', function ( b ) {
			makeBadge( P.badges[ +b.getAttribute( 'data-badge' ) ] ).then( function ( g ) {
				add( g );
			} );
		} );
		on( '[data-decor]', function ( b ) {
			add( makeDecor( P.decor[ +b.getAttribute( 'data-decor' ) ] ) );
		} );
		on( '[data-emoji]', function ( b ) {
			add( emojiOf( b.getAttribute( 'data-emoji' ) ) );
		} );
		on( '[data-shape]', function ( b ) {
			var kind = b.getAttribute( 'data-shape' );
			var def = shapeDef( kind );
			var o = makeShape( kind );
			add( o, 'shade-top' === kind || 'shade-bottom' === kind );
			if ( 'top' === def.place ) {
				o.set( { top: o.height / 2 } );
			} else if ( 'left' === def.place ) {
				o.set( { left: o.width / 2 } );
			}
			// Washes and bands go just above the photo, under any words; a frame stays on top.
			if ( def.under || [ 'tint', 'glow', 'band', 'shade-top', 'shade-bottom' ].indexOf( kind ) !== -1 ) {
				if ( 'band' === kind ) {
					o.set( { top: H - o.height / 2 } );
				}
				o.setCoords();
				var b0 = base();
				canvas.moveTo( o, b0 ? canvas.getObjects().indexOf( b0 ) + 1 : 0 );
				canvas.requestRenderAll();
			}
		} );
		on( '[data-fx]', function ( b ) {
			var t = fxTarget();
			var fx = t && t.whdFx ? t.whdFx : { preset: 'none', adjust: {} };
			applyFx( t, { preset: b.getAttribute( 'data-fx' ), adjust: fx.adjust || {} } );
			paintDrawer();
		} );
		on( '[data-fx-reset]', function () {
			applyFx( fxTarget(), { preset: 'none', adjust: {} } );
			paintDrawer();
		} );
		Array.prototype.forEach.call( d.querySelectorAll( '[data-adjust]' ), function ( r ) {
			r.addEventListener( 'input', function () {
				var t = fxTarget();
				if ( ! t ) {
					return;
				}
				var fx = t.whdFx ? JSON.parse( JSON.stringify( t.whdFx ) ) : { preset: 'none', adjust: {} };
				fx.adjust = fx.adjust || {};
				fx.adjust[ r.getAttribute( 'data-adjust' ) ] = parseFloat( r.value );
				applyFx( t, fx );
			} );
		} );
		on( '[data-photo]', function ( b ) {
			var how = b.getAttribute( 'data-photo' );
			pickPhotos( function ( photo ) {
				F.Image.fromURL( photo.work || photo.full, function ( img ) {
					if ( 'replace' === how ) {
						var old = base();
						var fx = old && old.whdFx;
						if ( old ) {
							canvas.remove( old );
						}
						img.set( { whdKind: 'base', whdName: T.basePhoto, whdFx: { preset: 'none', adjust: {} } } );
						placeBase( img, 'fill' );
						canvas.add( img );
						canvas.sendToBack( img );
						item.photo.id = item.photo.id || photo.id;
						if ( fx ) {
							applyFx( img, fx );
						}
					} else {
						var s = ( W * 0.4 ) / img.width;
						img.set( { scaleX: s, scaleY: s, whdKind: 'image', whdName: photo.title || T.basePhoto } );
						add( img );
					}
					canvas.requestRenderAll();
				}, { crossOrigin: 'anonymous' } );
			} );
		} );
		on( '[data-logo]', function ( b ) {
			var l = O.logos[ +b.getAttribute( 'data-logo' ) ];
			F.Image.fromURL( l.url, function ( img ) {
				var s = ( W * 0.28 ) / img.width;
				img.set( { scaleX: s, scaleY: s, whdKind: 'image', whdName: T.yourLogo } );
				add( img );
			}, { crossOrigin: 'anonymous' } );
		} );
		on( '[data-size]', function ( b ) {
			var s = P.sizes[ +b.getAttribute( 'data-size' ) ];
			var img = base();
			if ( ! s.w ) {
				if ( img ) {
					var k = Math.min( 1, 2160 / Math.max( img.width, img.height ) );
					resize( img.width * k, img.height * k, 'fill' );
				}
			} else {
				resize( s.w, s.h, 'fill' );
			}
			paintDrawer();
		} );
		on( '[data-custom]', function () {
			resize( +d.querySelector( '[data-cw]' ).value, +d.querySelector( '[data-ch]' ).value, 'fill' );
			paintDrawer();
		} );
		on( '[data-place]', function ( b ) {
			var img = base();
			if ( img ) {
				placeBase( img, b.getAttribute( 'data-place' ) );
				canvas.requestRenderAll();
				commit();
			}
		} );
		wireColours( d, function ( key, value ) {
			if ( 'bg' === key ) {
				canvas.backgroundColor = value;
				canvas.requestRenderAll();
				commit();
			}
		} );
	}

	/* ─────────────────────────── colours ─────────────────────────── */

	function hex( c ) {
		if ( ! c || 'string' !== typeof c ) {
			return '#ffffff';
		}
		if ( /^#[0-9a-f]{6}$/i.test( c ) ) {
			return c;
		}
		if ( /^#[0-9a-f]{3}$/i.test( c ) ) {
			return '#' + c[ 1 ] + c[ 1 ] + c[ 2 ] + c[ 2 ] + c[ 3 ] + c[ 3 ];
		}
		var m = c.match( /rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/i );
		if ( m ) {
			return '#' + [ m[ 1 ], m[ 2 ], m[ 3 ] ].map( function ( n ) {
				return ( '0' + ( +n ).toString( 16 ) ).slice( -2 );
			} ).join( '' );
		}
		return '#ffffff';
	}

	function colourRow( key, value ) {
		var swatches = ( O.brand && O.brand.colors ) || [];
		return '<div class="whd-ie__colours" data-colours="' + key + '">' +
			'<input type="color" value="' + hex( value ) + '" data-colour="' + key + '" aria-label="' + esc( T.colour ) + '">' +
			swatches.map( function ( c ) {
				return '<button type="button" class="whd-ie__swatch" style="background:' + c + '" data-swatch="' + c + '" aria-label="' + c + '"></button>';
			} ).join( '' ) +
			'<button type="button" class="whd-ie__swatch is-none" data-swatch="" title="none" aria-label="none"></button>' +
		'</div>';
	}

	function wireColours( scope, set ) {
		Array.prototype.forEach.call( scope.querySelectorAll( '[data-colours]' ), function ( row ) {
			var key = row.getAttribute( 'data-colours' );
			row.querySelector( '[data-colour]' ).addEventListener( 'input', function () {
				set( key, this.value );
			} );
			Array.prototype.forEach.call( row.querySelectorAll( '[data-swatch]' ), function ( s ) {
				s.addEventListener( 'click', function () {
					set( key, s.getAttribute( 'data-swatch' ) );
					row.querySelector( '[data-colour]' ).value = hex( s.getAttribute( 'data-swatch' ) || '#ffffff' );
				} );
			} );
		} );
	}

	/* ─────────────────────────── the selected thing ─────────────────────────── */

	function isText( o ) {
		return o && ( 'textbox' === o.type || 'i-text' === o.type || ( 'text' === o.type && 'emoji' !== o.whdKind ) );
	}

	function slider( key, label, min, max, step, value ) {
		return '<label class="whd-ie__slider"><span>' + esc( label ) + '</span><input type="range" min="' + min + '" max="' + max + '" step="' + step + '" value="' + value + '" data-prop="' + key + '"></label>';
	}

	function paintProps() {
		var o = canvas ? canvas.getActiveObject() : null;
		var p = el.props;
		if ( ! item ) {
			p.innerHTML = '';
			return;
		}
		if ( ! o ) {
			p.innerHTML = '<h4 class="whd-ie__h">' + esc( T.props ) + '</h4><p class="description">' + esc( T.nothingSel ) + '</p>' +
				'<h4 class="whd-ie__h">' + esc( T.background ) + '</h4>' + colourRow( 'bg', canvas.backgroundColor );
			wireColours( p, function ( key, value ) {
				canvas.backgroundColor = value || '#ffffff';
				canvas.requestRenderAll();
				commit();
			} );
			return;
		}
		var html = '<h4 class="whd-ie__h">' + esc( o.whdName || T.props ) + '</h4>';
		if ( 'activeSelection' === o.type ) {
			html = '<h4 class="whd-ie__h">' + esc( T.props ) + ' · ' + o.size() + '</h4>';
		} else if ( isText( o ) ) {
			html += '<label class="whd-ie__field"><span>' + esc( T.font ) + '</span><select data-prop="fontFamily">' + ( O.fonts || [] ).map( function ( f ) {
				return '<option value="' + esc( f ) + '" style="font-family:\'' + esc( f ) + '\'"' + ( f === o.fontFamily ? ' selected' : '' ) + '>' + esc( f ) + '</option>';
			} ).join( '' ) + '</select></label>' +
			'<div class="whd-ie__inline">' +
				'<label class="whd-ie__field is-num"><span>' + esc( T.size ) + '</span><input type="number" min="6" max="1200" value="' + Math.round( o.fontSize * ( o.scaleY || 1 ) ) + '" data-prop="fontSize"></label>' +
				'<div class="whd-ie__seg">' +
					'<button type="button" data-toggle="bold" class="' + ( +o.fontWeight >= 600 || 'bold' === o.fontWeight ? 'is-on' : '' ) + '" title="' + esc( T.bold ) + '"><b>B</b></button>' +
					'<button type="button" data-toggle="italic" class="' + ( 'italic' === o.fontStyle ? 'is-on' : '' ) + '" title="' + esc( T.italic ) + '"><i>I</i></button>' +
					'<button type="button" data-toggle="underline" class="' + ( o.underline ? 'is-on' : '' ) + '" title="' + esc( T.underline ) + '"><u>U</u></button>' +
					'<button type="button" data-toggle="caps" title="' + esc( T.caps ) + '">AA</button>' +
				'</div>' +
			'</div>' +
			'<div class="whd-ie__seg is-wide">' + [ [ 'left', T.alignL, '⟸' ], [ 'center', T.alignC, '≡' ], [ 'right', T.alignR, '⟹' ] ].map( function ( a ) {
				return '<button type="button" data-align="' + a[ 0 ] + '" class="' + ( o.textAlign === a[ 0 ] ? 'is-on' : '' ) + '" title="' + esc( a[ 1 ] ) + '">' + a[ 2 ] + '</button>';
			} ).join( '' ) + '</div>' +
			'<p class="whd-ie__label">' + esc( T.colour ) + '</p>' + colourRow( 'fill', o.fill ) +
			'<p class="whd-ie__label">' + esc( T.highlight ) + '</p>' + colourRow( 'textBackgroundColor', o.textBackgroundColor || '#ffffff' ) +
			'<p class="whd-ie__label">Effects</p><div class="whd-ie__seg is-wide is-fx">' + [ [ 'none', 'None' ], [ 'shadow', 'Shadow' ], [ 'lift', 'Lift' ], [ 'glow', 'Glow' ], [ 'hollow', 'Hollow' ], [ 'outline', 'Outline' ], [ 'splice', 'Splice' ], [ 'neon', 'Neon' ], [ 'echo', 'Echo' ], [ 'retro', 'Retro' ], [ 'gradient', 'Gradient' ] ].map( function ( e ) {
				return '<button type="button" data-texteffect="' + e[ 0 ] + '">' + e[ 1 ] + '</button>';
			} ).join( '' ) + '</div>' +
			slider( 'charSpacing', T.spacing, -100, 1000, 10, o.charSpacing || 0 ) +
			slider( 'lineHeight', T.lineH, 0.7, 2.5, 0.05, o.lineHeight || 1.1 ) +
			'<p class="whd-ie__label">' + esc( T.outline ) + '</p>' + colourRow( 'stroke', o.stroke || '#3a2b26' ) +
			slider( 'strokeWidth', T.outlineW, 0, Math.max( 2, Math.round( o.fontSize * 0.12 ) ), 0.5, o.strokeWidth || 0 );
		} else if ( 'badge' === o.whdKind ) {
			var bd = o.whdBadge || {};
			html += '<label class="whd-ie__field"><span>' + esc( T.label ) + '</span><input type="text" value="' + esc( bd.text ) + '" data-badgetext></label>' +
				'<p class="whd-ie__label">' + esc( T.fillColour ) + '</p>' + colourRow( 'badgeFill', bd.fill ) +
				'<p class="whd-ie__label">' + esc( T.colour ) + '</p>' + colourRow( 'badgeColor', bd.color );
		} else if ( 'base' !== o.whdKind && 'image' !== o.type && 'emoji' !== o.whdKind ) {
			var stroked = o.stroke && ( ! o.fill || 'line' === o.type );
			if ( ! stroked && 'string' === typeof o.fill || ( 'group' === o.type ) ) {
				html += '<p class="whd-ie__label">' + esc( T.fillColour ) + '</p>' + colourRow( 'fill', 'group' === o.type ? ( o._objects[ 0 ] && o._objects[ 0 ].fill ) : o.fill );
			}
			if ( stroked || 'rect' === o.type || 'circle' === o.type || 'polygon' === o.type || 'path' === o.type ) {
				html += '<p class="whd-ie__label">' + esc( T.outline ) + '</p>' + colourRow( 'stroke', o.stroke || '#3a2b26' ) +
					slider( 'strokeWidth', T.outlineW, 0, Math.max( 4, Math.round( W * 0.03 ) ), 0.5, o.strokeWidth || 0 );
			}
			if ( 'rect' === o.type ) {
				html += slider( 'rx', T.radius, 0, Math.round( Math.min( o.width, o.height ) / 2 ), 1, o.rx || 0 );
			}
		} else if ( 'base' === o.whdKind ) {
			html += '<p class="whd-ie__row"><button type="button" class="button" data-place-sel="fill">' + esc( T.fill ) + '</button> <button type="button" class="button" data-place-sel="fit">' + esc( T.fit ) + '</button></p>';
		}
		if ( 'activeSelection' !== o.type ) {
			html += slider( 'opacity', T.opacity, 0, 1, 0.01, null == o.opacity ? 1 : o.opacity );
			if ( ! isText( o ) ) {
				html += '<label class="whd-ie__check"><input type="checkbox" data-shadow' + ( o.shadow ? ' checked' : '' ) + '> ' + esc( T.shadow ) + '</label>';
			}
		}
		html += '<div class="whd-ie__acts">' +
			btn( 'forward', T.forward, '↑' ) + btn( 'backward', T.backward, '↓' ) + btn( 'centre-h', T.centreH, '↔' ) + btn( 'centre-v', T.centreV, '↕' ) +
			btn( 'flip', T.flipH, '⇋' ) + btn( 'duplicate', T.duplicate, '⧉' ) + btn( 'lock', o.whdLocked ? T.unlock : T.lock, o.whdLocked ? '🔒' : '🔓' ) + btn( 'delete', T.delete, '🗑', 'is-bad' ) +
		'</div>';
		p.innerHTML = html;
		wireProps( p, o );
	}

	function btn( key, label, glyph, cls ) {
		return '<button type="button" class="whd-ie__act ' + ( cls || '' ) + '" data-objact="' + key + '" title="' + esc( label ) + '" aria-label="' + esc( label ) + '">' + glyph + '</button>';
	}

	/* A text box that has not been resized by hand follows its words: a wider font widens it. */
	function refit( t ) {
		if ( ! t.whdAuto || String( t.text ).indexOf( '\n' ) !== -1 ) {
			return;
		}
		var most = 0.92 * W / ( t.scaleX || 1 );
		t.set( 'width', most );
		t.set( 'width', Math.min( most, t.calcTextWidth() + t.fontSize * 0.3 ) );
	}

	function setProp( o, key, value ) {
		if ( 'group' === o.type && 'fill' === key && 'decor' === o.whdKind ) {
			o._objects.forEach( function ( c ) {
				if ( ! c.whdCentre ) {
					c.set( 'fill', value );
				}
			} );
			o.dirty = true;
		} else if ( 'fontSize' === key ) {
			o.set( { fontSize: value / ( o.scaleY || 1 ) } );
		} else {
			o.set( key, value );
		}
		if ( isText( o ) && [ 'fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'charSpacing', 'text' ].indexOf( key ) !== -1 ) {
			refit( o );
		}
		if ( o.initDimensions ) {
			o.initDimensions();
		}
		o.setCoords();
		canvas.requestRenderAll();
		commit();
	}

	function rebuildBadge( o, change ) {
		// Once per badge at a time, and only while it is still there: a field can fire twice.
		if ( o.whdRebuilding || canvas.getObjects().indexOf( o ) === -1 ) {
			return;
		}
		o.whdRebuilding = true;
		var def = Object.assign( {}, o.whdBadge || {}, change );
		var at = { x: o.left / W, y: o.top / H, angle: o.angle, s: def.s };
		var keep = { scaleX: o.scaleX, scaleY: o.scaleY, opacity: o.opacity, shadow: o.shadow };
		var index = canvas.getObjects().indexOf( o );
		makeBadge( def, at ).then( function ( g ) {
			g.set( keep );
			canvas.remove( o );
			canvas.insertAt( g, index );
			canvas.setActiveObject( g );
			canvas.requestRenderAll();
			paintProps();
		} );
	}

	function textEffect( o, kind ) {
		styleText( o, kind );
		canvas.requestRenderAll();
		commit();
		paintProps();
	}

	/* The look itself, without the bookkeeping, so a preset can carry one too. */
	function styleText( o, kind ) {
		var size = o.fontSize;
		var base0 = o.whdTextColour || ( 'string' === typeof o.fill && 'rgba(0,0,0,0)' !== o.fill ? o.fill : ( o.stroke || '#3a2b26' ) );
		var fill = base0;
		o.set( { shadow: null, stroke: null, strokeWidth: 0 } );
		if ( 'string' !== typeof o.fill || 'rgba(0,0,0,0)' === o.fill ) {
			o.set( 'fill', fill );
		}
		o.whdTextColour = null;
		if ( 'shadow' === kind || 'lift' === kind || 'glow' === kind || 'splice' === kind ) {
			o.set( 'shadow', shadowOf( size, kind ) );
		}
		if ( 'hollow' === kind ) {
			o.set( { fill: 'rgba(0,0,0,0)', stroke: fill, strokeWidth: Math.max( 1, size * 0.03 ) } );
		}
		if ( 'outline' === kind ) {
			o.set( { stroke: '#ffffff' === hex( fill ) ? '#3a2b26' : '#ffffff', strokeWidth: Math.max( 1, size * 0.06 ), paintFirst: 'stroke' } );
		}
		if ( 'splice' === kind ) {
			o.set( { fill: 'rgba(0,0,0,0)', stroke: fill, strokeWidth: Math.max( 1, size * 0.025 ) } );
		}
		if ( 'neon' === kind ) {
			// A pale core and the colour as light around it.
			var glow = '#ffffff' === hex( fill ) ? '#f4a6b8' : fill;
			o.set( { fill: '#fff8fb', shadow: new F.Shadow( { color: glow, blur: size * 0.45, offsetX: 0, offsetY: 0 } ), stroke: glow, strokeWidth: Math.max( 1, size * 0.012 ), paintFirst: 'stroke' } );
			o.whdTextColour = glow;
		}
		if ( 'echo' === kind ) {
			var c = hex( fill );
			var rgb = [ 1, 3, 5 ].map( function ( i ) {
				return parseInt( c.substr( i, 2 ), 16 );
			} );
			o.set( 'shadow', new F.Shadow( { color: 'rgba(' + rgb.join( ',' ) + ',0.35)', blur: 0, offsetX: size * 0.07, offsetY: size * 0.07 } ) );
		}
		if ( 'retro' === kind ) {
			o.set( { shadow: new F.Shadow( { color: '#3a2b26', blur: 0, offsetX: size * 0.05, offsetY: size * 0.05 } ), stroke: '#3a2b26', strokeWidth: Math.max( 1, size * 0.02 ), paintFirst: 'stroke' } );
		}
		if ( 'gradient' === kind ) {
			o.set( 'fill', new F.Gradient( {
				type: 'linear',
				gradientUnits: 'percentage',
				coords: { x1: 0, y1: 0, x2: 1, y2: 1 },
				colorStops: [ { offset: 0, color: fill }, { offset: 1, color: '#ffffff' === hex( fill ) ? '#f4a6b8' : '#b98b7e' } ]
			} ) );
			o.whdTextColour = fill;
		}
	}

	function wireProps( p, o ) {
		Array.prototype.forEach.call( p.querySelectorAll( '[data-prop]' ), function ( input ) {
			var key = input.getAttribute( 'data-prop' );
			var ev = 'SELECT' === input.tagName ? 'change' : 'input';
			input.addEventListener( ev, function () {
				var v = 'fontFamily' === key ? input.value : parseFloat( input.value );
				if ( 'fontFamily' === key ) {
					ensureFont( v, o.fontWeight, o.fontStyle ).then( function () {
						setProp( o, key, v );
					} );
					return;
				}
				if ( ! isNaN( v ) ) {
					setProp( o, key, v );
				}
			} );
		} );
		Array.prototype.forEach.call( p.querySelectorAll( '[data-toggle]' ), function ( b ) {
			b.addEventListener( 'click', function () {
				var k = b.getAttribute( 'data-toggle' );
				if ( 'bold' === k ) {
					var bold = +o.fontWeight >= 600 || 'bold' === o.fontWeight;
					ensureFont( o.fontFamily, bold ? 400 : 700, o.fontStyle ).then( function () {
						setProp( o, 'fontWeight', bold ? 400 : 700 );
						paintProps();
					} );
				} else if ( 'italic' === k ) {
					var it = 'italic' === o.fontStyle ? 'normal' : 'italic';
					ensureFont( o.fontFamily, o.fontWeight, it ).then( function () {
						setProp( o, 'fontStyle', it );
						paintProps();
					} );
				} else if ( 'underline' === k ) {
					setProp( o, 'underline', ! o.underline );
					paintProps();
				} else if ( 'caps' === k ) {
					var up = o.text === o.text.toUpperCase() ? o.text.toLowerCase().replace( /(^|[\s.!?])(\S)/g, function ( m, a, c ) {
						return a + c.toUpperCase();
					} ) : o.text.toUpperCase();
					setProp( o, 'text', up );
					paintLayers();
				}
			} );
		} );
		Array.prototype.forEach.call( p.querySelectorAll( '[data-align]' ), function ( b ) {
			b.addEventListener( 'click', function () {
				setProp( o, 'textAlign', b.getAttribute( 'data-align' ) );
				paintProps();
			} );
		} );
		Array.prototype.forEach.call( p.querySelectorAll( '[data-texteffect]' ), function ( b ) {
			b.addEventListener( 'click', function () {
				textEffect( o, b.getAttribute( 'data-texteffect' ) );
			} );
		} );
		var bt = p.querySelector( '[data-badgetext]' );
		if ( bt ) {
			bt.addEventListener( 'change', function () {
				rebuildBadge( o, { text: bt.value } );
			} );
		}
		wireColours( p, function ( key, value ) {
			if ( 'badgeFill' === key ) {
				rebuildBadge( o, { fill: value || 'rgba(0,0,0,0)' } );
			} else if ( 'badgeColor' === key ) {
				rebuildBadge( o, { color: value || '#3a2b26' } );
			} else if ( 'textBackgroundColor' === key ) {
				setProp( o, key, value );
			} else {
				setProp( o, key, value || ( 'fill' === key ? 'rgba(0,0,0,0)' : null ) );
			}
		} );
		var sh = p.querySelector( '[data-shadow]' );
		if ( sh ) {
			sh.addEventListener( 'change', function () {
				setProp( o, 'shadow', sh.checked ? shadowOf( Math.max( o.getScaledWidth(), o.getScaledHeight() ) * 0.25, 'lift' ) : null );
			} );
		}
		Array.prototype.forEach.call( p.querySelectorAll( '[data-place-sel]' ), function ( b ) {
			b.addEventListener( 'click', function () {
				placeBase( o, b.getAttribute( 'data-place-sel' ) );
				canvas.requestRenderAll();
				commit();
			} );
		} );
		Array.prototype.forEach.call( p.querySelectorAll( '[data-objact]' ), function ( b ) {
			b.addEventListener( 'click', function () {
				objAct( b.getAttribute( 'data-objact' ), o );
			} );
		} );
	}

	function objAct( what, o ) {
		o = o || canvas.getActiveObject();
		if ( ! o ) {
			return;
		}
		switch ( what ) {
			case 'forward': canvas.bringForward( o ); break;
			case 'backward': {
				var b = base();
				if ( b && canvas.getObjects().indexOf( o ) - 1 <= canvas.getObjects().indexOf( b ) && o !== b ) {
					break; // never under the photo, where it could not be seen
				}
				canvas.sendBackwards( o );
				break;
			}
			case 'centre-h': o.setPositionByOrigin( new F.Point( W / 2, o.getCenterPoint().y ), 'center', 'center' ); break;
			case 'centre-v': o.setPositionByOrigin( new F.Point( o.getCenterPoint().x, H / 2 ), 'center', 'center' ); break;
			case 'flip': o.set( 'flipX', ! o.flipX ); break;
			case 'duplicate': duplicate( o ); return;
			case 'lock': {
				var lock = ! o.whdLocked;
				o.set( { whdLocked: lock, lockMovementX: lock, lockMovementY: lock, lockScalingX: lock, lockScalingY: lock, lockRotation: lock, hasControls: ! lock } );
				break;
			}
			case 'delete': remove(); return;
		}
		o.setCoords();
		canvas.requestRenderAll();
		commit();
		paintProps();
		paintLayers();
	}

	function duplicate( o ) {
		o.clone( function ( c ) {
			c.set( { left: o.left + W * 0.03, top: o.top + W * 0.03 } );
			[ 'whdKind', 'whdName', 'whdBadge', 'whdDecor', 'whdFx' ].forEach( function ( k ) {
				if ( undefined !== o[ k ] ) {
					c[ k ] = JSON.parse( JSON.stringify( o[ k ] ) );
				}
			} );
			if ( 'base' === c.whdKind ) {
				c.whdKind = 'image';
			}
			canvas.add( c );
			canvas.setActiveObject( c );
			canvas.requestRenderAll();
		}, PROPS );
	}

	function remove() {
		var list = canvas.getActiveObjects();
		canvas.discardActiveObject();
		list.forEach( function ( o ) {
			canvas.remove( o );
		} );
		canvas.requestRenderAll();
		paintProps();
	}

	/* ─────────────────────────── layers ─────────────────────────── */

	var KIND = { base: '🖼', image: '🖼', text: 'T', badge: '◉', decor: '✦', shape: '■', emoji: '☺', vignette: '◌' };

	function paintLayers() {
		if ( ! canvas ) {
			return;
		}
		var active = canvas.getActiveObjects();
		var objs = canvas.getObjects().filter( function ( o ) {
			return ! o.whdGuide;
		} ).slice().reverse();
		el.layers.innerHTML = objs.map( function ( o ) {
			var i = canvas.getObjects().indexOf( o );
			var name = isText( o ) ? String( o.text ).slice( 0, 30 ) : ( o.whdName || o.type );
			return '<li class="whd-ie__layer' + ( active.indexOf( o ) !== -1 ? ' is-on' : '' ) + ( o.visible === false ? ' is-hidden' : '' ) + '">' +
				'<button type="button" class="whd-ie__layer-pick" data-layer="' + i + '"><span class="whd-ie__layer-k">' + ( KIND[ o.whdKind ] || '■' ) + '</span><span class="whd-ie__layer-n">' + esc( name ) + '</span>' + ( o.whdLocked ? '<span class="whd-ie__layer-l">🔒</span>' : '' ) + '</button>' +
				'<button type="button" class="whd-ie__layer-eye" data-eye="' + i + '" title="' + esc( o.visible === false ? T.show : T.hide ) + '">' + ( o.visible === false ? '◌' : '👁' ) + '</button>' +
			'</li>';
		} ).join( '' );
		Array.prototype.forEach.call( el.layers.querySelectorAll( '[data-layer]' ), function ( b ) {
			b.addEventListener( 'click', function () {
				var o = canvas.getObjects()[ +b.getAttribute( 'data-layer' ) ];
				if ( o && false !== o.selectable ) {
					canvas.setActiveObject( o );
					canvas.requestRenderAll();
				}
			} );
		} );
		Array.prototype.forEach.call( el.layers.querySelectorAll( '[data-eye]' ), function ( b ) {
			b.addEventListener( 'click', function () {
				var o = canvas.getObjects()[ +b.getAttribute( 'data-eye' ) ];
				if ( o ) {
					o.set( 'visible', false === o.visible );
					if ( ! o.visible ) {
						canvas.discardActiveObject();
					}
					canvas.requestRenderAll();
					commit();
					paintLayers();
				}
			} );
		} );
	}

	function paintAll() {
		paintDrawer();
		paintProps();
		paintLayers();
		paintTools();
	}

	/* ─────────────────────────── keys ─────────────────────────── */

	function keys( e ) {
		if ( ! canvas || ! item || el.root.closest( '[hidden]' ) ) {
			return;
		}
		var tag = ( e.target.tagName || '' ).toLowerCase();
		var o = canvas.getActiveObject();
		if ( 'input' === tag || 'textarea' === tag || 'select' === tag || ( o && o.isEditing ) || document.querySelector( 'dialog[open]' ) ) {
			return;
		}
		var mod = e.ctrlKey || e.metaKey;
		if ( mod && 'z' === e.key.toLowerCase() ) {
			e.preventDefault();
			if ( e.shiftKey ) {
				redo();
			} else {
				undo();
			}
		} else if ( mod && 'y' === e.key.toLowerCase() ) {
			e.preventDefault();
			redo();
		} else if ( mod && 'd' === e.key.toLowerCase() && o ) {
			e.preventDefault();
			duplicate( o );
		} else if ( mod && 'c' === e.key.toLowerCase() && o ) {
			o.clone( function ( c ) {
				clipboard = c;
			}, PROPS );
		} else if ( mod && 'v' === e.key.toLowerCase() && clipboard ) {
			e.preventDefault();
			clipboard.clone( function ( c ) {
				c.set( { left: c.left + W * 0.02, top: c.top + W * 0.02 } );
				canvas.add( c );
				canvas.setActiveObject( c );
				canvas.requestRenderAll();
			}, PROPS );
		} else if ( ( 'Delete' === e.key || 'Backspace' === e.key ) && o ) {
			e.preventDefault();
			remove();
		} else if ( o && /^Arrow/.test( e.key ) ) {
			e.preventDefault();
			var step = e.shiftKey ? 10 : 1;
			if ( 'ArrowLeft' === e.key ) {
				o.left -= step;
			} else if ( 'ArrowRight' === e.key ) {
				o.left += step;
			} else if ( 'ArrowUp' === e.key ) {
				o.top -= step;
			} else {
				o.top += step;
			}
			o.setCoords();
			canvas.requestRenderAll();
			commit();
		}
	}

	/* ─────────────────────────── out ─────────────────────────── */

	/* The design at its real size: the zoom undone, the handles and guides left out. */
	function exportData( format ) {
		canvas.discardActiveObject();
		clearGuides();
		// At zoom 1 and the design's own size for a moment, so it comes out exactly W × H, not a pixel off.
		var keep = zoom;
		canvas.setDimensions( { width: W, height: H } );
		canvas.setZoom( 1 );
		var url = canvas.toDataURL( { format: format, quality: 0.92 } );
		zoom = keep;
		canvas.setDimensions( { width: Math.round( W * zoom ), height: Math.round( H * zoom ) } );
		canvas.setZoom( zoom );
		canvas.requestRenderAll();
		return url;
	}

	function nameOf() {
		return ( item && item.photo && item.photo.title ) || 'design';
	}

	function download() {
		if ( ! item ) {
			return;
		}
		var format = el.format.value;
		var a = document.createElement( 'a' );
		a.href = exportData( format );
		a.download = nameOf().replace( /[^\w\- ]+/g, '' ).trim().replace( /\s+/g, '-' ).toLowerCase() + '-edited.' + ( 'jpeg' === format ? 'jpg' : format );
		document.body.appendChild( a );
		a.click();
		a.remove();
	}

	function firstText() {
		var t = canvas.getObjects().filter( isText )[ 0 ];
		return t ? String( t.text ).replace( /\s+/g, ' ' ).trim() : '';
	}

	function save( button ) {
		if ( ! item ) {
			return;
		}
		var format = el.format.value;
		var data = exportData( format );
		if ( data.length > 26 * 1024 * 1024 ) {
			say( T.tooBig, true );
			return;
		}
		button.disabled = true;
		say( T.saving );
		var design = JSON.stringify( { v: 1, W: W, H: H, bg: canvas.backgroundColor, json: canvas.toJSON( PROPS ) } );
		var alt = ( item.photo.alt || '' ) + ( firstText() ? ( item.photo.alt ? ' — ' : '' ) + firstText() : '' );
		O.client.send( 'save', {
			data: data,
			name: nameOf(),
			source: item.photo.blank ? 0 : ( item.photo.id || 0 ),
			alt: alt.slice( 0, 240 ),
			design: design
		} ).then( function ( r ) {
			item.dirty = false;
			item.saved = r;
			say( fmt( T.saved, '<a href="' + esc( r.edit ) + '" target="_blank" rel="noopener">' + esc( T.openSaved ) + '</a>' ) );
			paintStrip();
			if ( O.onSaved ) {
				O.onSaved( r );
			}
		} ).catch( function ( e ) {
			say( e.message, true );
		} ).then( function () {
			button.disabled = false;
		} );
	}

	/* ─────────────────────────── the outside ─────────────────────────── */

	window.WHDImageEditor = {
		mount: function ( root, options ) {
			if ( mounted ) {
				layout();
				return;
			}
			O = options || {};
			T = O.strings || {};
			build( root );
			makeCanvas();
			mounted = true;
			el.frame.hidden = true;
			paintStrip();
			paintDrawer();
			paintProps();
			var later = pending.slice();
			pending = [];
			later.forEach( open );
		},
		show: function () {
			if ( mounted ) {
				layout();
			}
		},
		open: open,
		openIds: openIds,
		pick: function () {
			pickPhotos();
		},
		dirty: function () {
			stash();
			return queue.some( function ( q ) {
				return q.dirty;
			} );
		}
	};
}() );
