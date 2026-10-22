/* WHD — "Just got mine": a story-sized card of the piece just bought, drawn on the order-received page.

   Uses the Image Editor's Fabric.js. With a design chosen in WHD → Settings, that design is loaded,
   its photo (the layer it was started from) swapped for the piece and resized to fill the same
   frame, and {product} / {first_name} filled in its words. Without one, a plain card in the shop's
   colours is drawn. The picture stays on the device until somebody saves or shares it. */
( function () {
	'use strict';

	var D = window.WHD_SHARE;
	var F = window.fabric;
	var root = document.getElementById( 'whd-share' );
	if ( ! D || ! F || ! root || ! D.items || ! D.items.length ) {
		return;
	}
	var T = D.i18n || {};

	var img = root.querySelector( '.whd-share__img' );
	var wait = root.querySelector( '.whd-share__wait' );
	var share = root.querySelector( '.whd-share__btn--share' );
	var save = root.querySelector( '.whd-share__btn--save' );
	var note = root.querySelector( '.whd-share__note' );
	var current = { blob: null, url: '', name: '' };
	var drawing = 0;

	var INK = '#3a2b26';
	var ROSE = '#9c6f63';
	var CREAM = '#f7f2ed';

	/* ─────────────────────────── fonts ─────────────────────────── */

	function fontsOf( node, out ) {
		if ( ! node || 'object' !== typeof node ) {
			return out;
		}
		if ( node.fontFamily ) {
			out.push( ( node.fontStyle || 'normal' ) + ' ' + ( node.fontWeight || 400 ) + ' 48px "' + String( node.fontFamily ).replace( /["']/g, '' ) + '"' );
		}
		Object.keys( node ).forEach( function ( k ) {
			if ( node[ k ] && 'object' === typeof node[ k ] ) {
				fontsOf( node[ k ], out );
			}
		} );
		return out;
	}

	function loadFonts( specs ) {
		if ( ! document.fonts || ! document.fonts.load ) {
			return Promise.resolve();
		}
		return Promise.all( specs.map( function ( s ) {
			return document.fonts.load( s ).catch( function () {} );
		} ) ).then( function () {
			if ( F.util.clearFabricFontCache ) {
				F.util.clearFabricFontCache();
			}
		} );
	}

	/* ─────────────────────────── images ─────────────────────────── */

	function image( url ) {
		return new Promise( function ( resolve, reject ) {
			F.Image.fromURL( url, function ( o ) {
				if ( ! o || ! o.width ) {
					reject( new Error( 'image' ) );
					return;
				}
				resolve( o );
			}, { crossOrigin: 'anonymous' } );
		} );
	}

	/* Scale an image to fill a box, centred on it, cropped to it. */
	function cover( o, box ) {
		var s = Math.max( box.w / o.width, box.h / o.height );
		o.set( { scaleX: s, scaleY: s, left: box.cx, top: box.cy, originX: 'center', originY: 'center' } );
		if ( box.clip ) {
			o.clipPath = new F.Rect( { left: box.cx - box.w / 2, top: box.cy - box.h / 2, width: box.w, height: box.h, absolutePositioned: true } );
		}
		return o;
	}

	/* ─────────────────────────── the owner's design ─────────────────────────── */

	function fill( text, item ) {
		return String( text )
			.replace( /\{product\}/g, item.name )
			.replace( /\{first_name\}/g, D.first || '' )
			.replace( /\{name\}/g, D.first || '' );
	}

	function words( node, item ) {
		if ( ! node || 'object' !== typeof node ) {
			return;
		}
		if ( 'string' === typeof node.text && /\{(product|first_name|name)\}/.test( node.text ) ) {
			node.text = fill( node.text, item ).trim();
			node.styles = {}; // per-letter styling no longer lines up once the words change
		}
		( node.objects || [] ).forEach( function ( c ) {
			words( c, item );
		} );
	}

	function fromDesign( canvas, item ) {
		var d = D.design;
		var json = JSON.parse( JSON.stringify( d.json ) );
		var W = d.W;
		var H = d.H;
		var box = null;
		var objects = json.objects || [];

		objects.forEach( function ( o ) {
			words( o, item );
		} );

		/* The photo the design was made from becomes the piece: same frame, new picture. */
		var base = objects.filter( function ( o ) {
			return 'base' === o.whdKind && 'image' === o.type;
		} )[ 0 ];
		if ( base ) {
			var w = ( base.width || W ) * Math.abs( base.scaleX || 1 );
			var h = ( base.height || H ) * Math.abs( base.scaleY || 1 );
			var cx = 'center' === base.originX ? base.left : ( 'right' === base.originX ? base.left - w / 2 : base.left + w / 2 );
			var cy = 'center' === base.originY ? base.top : ( 'bottom' === base.originY ? base.top - h / 2 : base.top + h / 2 );
			box = { w: w, h: h, cx: cx, cy: cy, clip: ! base.clipPath && ( w < W - 1 || h < H - 1 ) };
			base.src = item.image;
			[ 'width', 'height', 'cropX', 'cropY' ].forEach( function ( k ) {
				delete base[ k ];
			} );
			base.whdShare = true;
		}

		return loadFonts( fontsOf( json, [] ) ).then( function () {
			return new Promise( function ( resolve ) {
				canvas.setWidth( W );
				canvas.setHeight( H );
				canvas.loadFromJSON( json, function () {
					resolve();
				} );
			} );
		} ).then( function () {
			canvas.backgroundColor = d.bg || '#ffffff';
			var placed = canvas.getObjects().filter( function ( o ) {
				return o.whdShare;
			} )[ 0 ];
			if ( placed && box ) {
				cover( placed, box );
				return;
			}
			/* A design with no photo of its own gets the piece behind everything, filling the card. */
			return image( item.image ).then( function ( o ) {
				cover( o, { w: W, h: H, cx: W / 2, cy: H / 2 } );
				canvas.insertAt( o, 0 );
			} );
		} ).then( function () {
			canvas.getObjects().forEach( function ( o ) {
				if ( o.initDimensions ) {
					o.initDimensions();
				}
			} );
		} );
	}

	/* ─────────────────────────── the built-in card ─────────────────────────── */

	function builtIn( canvas, item ) {
		var W = 1080;
		var H = 1920;
		var B = D.brand || {};
		canvas.setWidth( W );
		canvas.setHeight( H );
		canvas.backgroundColor = CREAM;

		var frame = { w: 960, h: 1330, cx: W / 2, cy: 60 + 1330 / 2, clip: true };
		var logo = null;
		var jobs = [ image( item.image ).then( function ( o ) {
			canvas.add( cover( o, frame ) );
		} ) ];
		/* The shop's mark in the band under the photo; its name in letters when there is no mark. */
		if ( B.logo ) {
			jobs.push( image( B.logo ).then( function ( o ) {
				var s = 96 / o.height;
				o.set( { scaleX: s, scaleY: s, left: W / 2, top: 1478, originX: 'center', originY: 'center' } );
				logo = o;
			} ).catch( function () {} ) );
		}

		return loadFonts( [ 'italic 600 48px "Cormorant Garamond"', 'normal 500 48px "Manrope"', 'normal 700 48px "Manrope"' ] ).then( function () {
			return Promise.all( jobs );
		} ).then( function () {
			var text = function ( words, o ) {
				return new F.Textbox( words, Object.assign( { width: 900, left: W / 2, originX: 'center', originY: 'center', textAlign: 'center', fill: INK, fontFamily: 'Manrope' }, o ) );
			};
			canvas.add( logo || text( String( B.name || '' ).toUpperCase(), { top: 1478, fontSize: 28, fontWeight: 700, charSpacing: 320, fill: ROSE } ) );
			canvas.add( text( T.line || 'Just got mine.', { top: 1590, fontSize: 132, fontFamily: 'Cormorant Garamond', fontStyle: 'italic', fontWeight: 600 } ) );
			canvas.add( text( item.name, { top: 1712, fontSize: 36, fontWeight: 500, fill: '#4a3f3a', lineHeight: 1.2 } ) );
			canvas.add( new F.Rect( { width: 90, height: 2, left: W / 2, top: 1790, originX: 'center', originY: 'center', fill: ROSE } ) );
			canvas.add( text( B.handle || B.site || '', { top: 1840, fontSize: 30, fontWeight: 700, charSpacing: 120, fill: ROSE } ) );
		} );
	}

	/* ─────────────────────────── drawing and handing it over ─────────────────────────── */

	function draw( i ) {
		var item = D.items[ i ] || D.items[ 0 ];
		var ticket = ++drawing;
		var el = document.createElement( 'canvas' );
		var canvas = new F.StaticCanvas( el, { enableRetinaScaling: false, renderOnAddRemove: false } );
		wait.hidden = false;
		wait.textContent = T.making || '';
		root.classList.add( 'is-busy' );

		( D.design ? fromDesign( canvas, item ).catch( function () {
			canvas.clear();
			return builtIn( canvas, item ); // a design that will not load still leaves them a card
		} ) : builtIn( canvas, item ) ).then( function () {
			canvas.renderAll();
			return new Promise( function ( resolve, reject ) {
				el.toBlob( function ( blob ) {
					if ( blob ) {
						resolve( blob );
					} else {
						reject( new Error( 'blob' ) );
					}
				}, 'image/png' );
			} );
		} ).then( function ( blob ) {
			if ( ticket !== drawing ) {
				return;
			}
			if ( current.url ) {
				URL.revokeObjectURL( current.url );
			}
			current = { blob: blob, url: URL.createObjectURL( blob ), name: ( T.file || 'just-got-mine' ) + ( D.items.length > 1 ? '-' + ( i + 1 ) : '' ) + '.png' };
			img.src = current.url;
			img.hidden = false;
			wait.hidden = true;
			save.href = current.url;
			save.setAttribute( 'download', current.name );
			save.hidden = false;
			var file = window.File ? new File( [ blob ], current.name, { type: 'image/png' } ) : null;
			share.hidden = ! ( file && navigator.canShare && navigator.canShare( { files: [ file ] } ) );
			root.classList.remove( 'is-busy' );
			canvas.dispose();
		} ).catch( function () {
			if ( ticket === drawing ) {
				wait.textContent = T.failed || '';
				root.classList.remove( 'is-busy' );
			}
		} );
	}

	share.addEventListener( 'click', function () {
		if ( ! current.blob ) {
			return;
		}
		var file = new File( [ current.blob ], current.name, { type: 'image/png' } );
		navigator.share( { files: [ file ], title: T.title || '' } ).then( function () {
			note.textContent = T.shared || '';
		} ).catch( function () { /* closed the sheet: nothing to say */ } );
	} );

	save.addEventListener( 'click', function () {
		note.textContent = T.saved || '';
	} );

	root.querySelectorAll( '.whd-share__pick' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			root.querySelectorAll( '.whd-share__pick' ).forEach( function ( x ) {
				x.classList.toggle( 'is-on', x === b );
				x.setAttribute( 'aria-pressed', x === b ? 'true' : 'false' );
			} );
			note.textContent = '';
			draw( parseInt( b.getAttribute( 'data-i' ), 10 ) || 0 );
		} );
	} );

	/* Drawn when it scrolls near, not on load: the order details come first. */
	if ( 'IntersectionObserver' in window ) {
		var io = new IntersectionObserver( function ( entries ) {
			if ( entries[ 0 ].isIntersecting ) {
				io.disconnect();
				draw( 0 );
			}
		}, { rootMargin: '300px 0px' } );
		io.observe( root );
	} else {
		draw( 0 );
	}
}() );
