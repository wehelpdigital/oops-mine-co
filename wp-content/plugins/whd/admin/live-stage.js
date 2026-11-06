/**
 * The live stage: the camera and everything on top of it, drawn onto one canvas.
 *
 * That canvas is what goes out as the camera (live-studio.js hands its track to Daily), so whatever is
 * drawn here is in the video itself: the same on the site, Facebook, YouTube and TikTok. Selection
 * handles are not drawn here; they are HTML on top, so they never reach an audience.
 *
 * The clock is a Web Worker. requestAnimationFrame stops and timers are throttled in a background tab,
 * and a host looking at another tab must not freeze the broadcast. Daily's guide says the same.
 *
 * Everything drawn is same-origin or loaded with crossOrigin, because a single tainted image would make
 * captureStream throw. An image that cannot be loaded that way is left out rather than risked.
 *
 * Static layers are drawn once into their own small canvas and only stamped each frame. Text with
 * shadows at 30 fps on a laptop that is also encoding video is where frames would otherwise go.
 *
 * Top to bottom a picture is: the camera (live-grade.js adjusts it), the light over it, the frame (both from
 * live-frames.js), then the layers, then whatever is falling.
 *
 * @package whd
 */
( function ( root ) {
	'use strict';

	var SERIF = '"Cormorant Garamond", Georgia, serif';
	var SANS = 'Manrope, "Segoe UI", Arial, sans-serif';
	var ROSE = '#b98b7e';
	var ROSE_DEEP = '#9c6f63';
	var INK = '#2b1f1b';
	var CREAM = '#f7f2ed';
	var FPS = 30;

	var LOOKS = {
		natural: { label: 'Natural', filter: '' },
		warm: { label: 'Warm', filter: 'saturate(1.12) sepia(.12) brightness(1.03)' },
		rosy: { label: 'Rosy', filter: 'saturate(1.1) sepia(.08) hue-rotate(-8deg) brightness(1.05) contrast(.98)' },
		soft: { label: 'Soft', filter: 'brightness(1.08) contrast(.9) saturate(1.04)' },
		vivid: { label: 'Vivid', filter: 'saturate(1.35) contrast(1.06)' },
		cool: { label: 'Cool', filter: 'saturate(.96) hue-rotate(8deg) brightness(1.02)' },
		vintage: { label: 'Vintage', filter: 'sepia(.35) saturate(.9) contrast(.94) brightness(1.02)' },
		mono: { label: 'Mono', filter: 'grayscale(1) contrast(1.06)' },
		blush: { label: 'Blush', filter: 'brightness(1.06) saturate(1.06) sepia(.06) hue-rotate(-6deg) contrast(.96)' },
		peach: { label: 'Peach', filter: 'sepia(.14) saturate(1.16) hue-rotate(-8deg) brightness(1.05) contrast(.97)' },
		golden: { label: 'Golden', filter: 'sepia(.22) saturate(1.28) hue-rotate(-6deg) brightness(1.04) contrast(1.02)' },
		seoul: { label: 'Seoul café', filter: 'brightness(1.09) contrast(.9) saturate(.9) sepia(.09)' },
		ivory: { label: 'Ivory', filter: 'brightness(1.1) contrast(.86) saturate(.78)' },
		fresh: { label: 'Fresh', filter: 'saturate(1.1) hue-rotate(3deg) brightness(1.06) contrast(.97)' },
		film: { label: 'Film', filter: 'contrast(.9) saturate(.84) sepia(.16) brightness(1.04)' },
		night: { label: 'Night out', filter: 'saturate(1.25) contrast(1.12) hue-rotate(-5deg) brightness(.97)' },
		sepia: { label: 'Sepia', filter: 'sepia(.5) contrast(.98) brightness(1.01) saturate(.85)' },
		noir: { label: 'Noir', filter: 'grayscale(1) contrast(1.28) brightness(.96)' }
	};

	var FRAMES = root.WHDFrames.FRAMES;
	var LIGHTS = root.WHDFrames.LIGHTS;
	var TINTS = root.WHDFrames.TINTS;
	var GRADE = root.WHDGrade;
	var LIGHT_LEVEL = 0.8; // what a light starts at, of 1

	/* kind: rise (up from the bottom), fall (down from the top), twinkle (in place), burst (out from the middle). */
	var EFFECTS = {
		hearts: { label: 'Hearts', icon: '💖', emoji: [ '💖', '💕', '❤️', '🩷' ], kind: 'rise', count: 34, size: [ 0.05, 0.1 ] },
		confetti: { label: 'Confetti', icon: '🎊', colors: [ ROSE, '#f2d5cb', '#ffffff', '#d4a373', '#e8a0a0', '#3a2b26' ], kind: 'fall', count: 130, size: [ 0.012, 0.024 ] },
		sparkle: { label: 'Sparkle', icon: '✨', emoji: [ '✨', '⭐', '🌟' ], kind: 'twinkle', count: 34, size: [ 0.04, 0.09 ] },
		party: { label: 'Party', icon: '🎉', emoji: [ '🎉', '🎊', '🥳' ], kind: 'burst', count: 30, size: [ 0.05, 0.09 ] },
		money: { label: 'Money', icon: '💸', emoji: [ '💸', '💵' ], kind: 'fall', count: 30, size: [ 0.05, 0.09 ] },
		bags: { label: 'Bags', icon: '🛍️', emoji: [ '🛍️' ], kind: 'rise', count: 20, size: [ 0.06, 0.11 ] },
		balloons: { label: 'Balloons', icon: '🎈', emoji: [ '🎈' ], kind: 'rise', count: 16, size: [ 0.08, 0.14 ] },
		petals: { label: 'Petals', icon: '🌸', emoji: [ '🌸', '🌷', '🌹' ], kind: 'fall', count: 30, size: [ 0.04, 0.08 ] },
		fire: { label: 'Hot', icon: '🔥', emoji: [ '🔥' ], kind: 'rise', count: 26, size: [ 0.05, 0.1 ] },
		snow: { label: 'Snow', icon: '❄️', emoji: [ '❄️' ], kind: 'fall', count: 40, size: [ 0.025, 0.05 ] },
		leaves: { label: 'Leaves', icon: '🍁', emoji: [ '🍁', '🍂' ], kind: 'fall', count: 26, size: [ 0.04, 0.075 ] },
		lanterns: { label: 'Lanterns', icon: '🏮', emoji: [ '🏮' ], kind: 'rise', count: 14, size: [ 0.06, 0.1 ] },
		bats: { label: 'Bats', icon: '🦇', emoji: [ '🦇' ], kind: 'fall', count: 16, size: [ 0.05, 0.09 ] },
		tags: { label: 'Tags', icon: '🏷️', emoji: [ '🏷️' ], kind: 'fall', count: 18, size: [ 0.045, 0.08 ] },
		bolts: { label: 'Bolts', icon: '⚡', emoji: [ '⚡' ], kind: 'twinkle', count: 16, size: [ 0.05, 0.09 ] },
		butterflies: { label: 'Butterflies', icon: '🦋', emoji: [ '🦋' ], kind: 'rise', count: 16, size: [ 0.05, 0.1 ] },
		bows: { label: 'Bows', icon: '🎀', emoji: [ '🎀' ], kind: 'fall', count: 18, size: [ 0.045, 0.085 ] },
		kisses: { label: 'Kisses', icon: '💋', emoji: [ '💋' ], kind: 'rise', count: 20, size: [ 0.045, 0.085 ] },
		flowers: { label: 'Flowers', icon: '🌼', emoji: [ '🌼', '🌻', '🌺' ], kind: 'fall', count: 24, size: [ 0.045, 0.085 ] },
		diamonds: { label: 'Diamonds', icon: '💎', emoji: [ '💎' ], kind: 'twinkle', count: 20, size: [ 0.04, 0.08 ] },
		crowns: { label: 'Crowns', icon: '👑', emoji: [ '👑' ], kind: 'fall', count: 14, size: [ 0.05, 0.09 ] },
		fruit: { label: 'Sweet', icon: '🍓', emoji: [ '🍓', '🍒', '🍑' ], kind: 'fall', count: 22, size: [ 0.04, 0.08 ] },
		music: { label: 'Music', icon: '🎶', emoji: [ '🎵', '🎶' ], kind: 'rise', count: 20, size: [ 0.04, 0.08 ] },
		foil: { label: 'Gold foil', icon: '🥇', colors: [ '#d3ad66', '#f8e7bb', '#9a7433', '#f2d5cb', '#b98b7e' ], kind: 'fall', count: 110, size: [ 0.01, 0.022 ] },
		pearls: { label: 'Pearls', paint: 'pearl', kind: 'fall', count: 26, size: [ 0.025, 0.055 ] },
		bubbles: { label: 'Bubbles', paint: 'bubble', kind: 'rise', count: 26, size: [ 0.04, 0.11 ] }
	};

	var ANIMS = {
		none: 'Still',
		float: 'Float',
		pulse: 'Pulse',
		swing: 'Swing',
		bounce: 'Bounce',
		spin: 'Spin',
		wobble: 'Wobble',
		jelly: 'Jelly',
		shake: 'Shake',
		hop: 'Hop'
	};

	/* What each kind of layer starts as. x, y: its centre as a fraction of the picture. w: fraction of the width. */
	var LAYER_DEFAULTS = {
		image: { w: 0.22, enter: 'pop' },
		text: { w: 0.42, enter: 'pop', style: 'ribbon', text: '' },
		product: { w: 0.42, enter: 'slide' },
		lower: { w: 0.5, enter: 'slide', title: '', subtitle: '' },
		countdown: { w: 0.26, enter: 'pop', label: 'Ends in', endsAt: 0 },
		ticker: { w: 1, enter: 'fade', text: '', speed: 110 },
		source: { w: 0.27, enter: 'pop', shape: 'rounded', sourceId: '' },
		gallery: { w: 0.34, enter: 'pop', at: 0, auto: true, images: [] },
		spot: { w: 0.34, enter: 'fade' }
	};

	/* The slideshow's card, in the units its sprite is drawn in: margin for the shadow, then the photo, then the words. */
	var GALLERY = { W: 600, H: 732, MX: 14, MY: 12, CARD_W: 572, CARD_H: 702, RADIUS: 24, PHOTO_X: 28, PHOTO_Y: 26, PHOTO: 544 };
	var SLIDE_MS = 4200; // how long each photo stays
	var SLIDE_IN_MS = 560;
	var REVEAL_MS = 1900; // how long a piece or its photos keep drawing the eye after appearing

	/* A canvas only ever draws the first frame of an animated picture, so those are decoded. */
	var MAX_GIF_FRAMES = 150;
	var MAX_GIF_EDGE = 420;

	var uid = 0;

	function clamp( n, a, b ) {
		return n < a ? a : n > b ? b : n;
	}
	function rand( a, b ) {
		return a + Math.random() * ( b - a );
	}
	function pick( list ) {
		return list[ Math.floor( Math.random() * list.length ) ];
	}
	function easeOutBack( t ) {
		var c = 1.70158;
		return 1 + ( c + 1 ) * Math.pow( t - 1, 3 ) + c * Math.pow( t - 1, 2 );
	}
	function easeOutCubic( t ) {
		return 1 - Math.pow( 1 - t, 3 );
	}
	function easeInOut( t ) {
		return t < 0.5 ? 2 * t * t : 1 - Math.pow( -2 * t + 2, 2 ) / 2;
	}

	function makeCanvas( w, h ) {
		var c = document.createElement( 'canvas' );
		c.width = Math.max( 1, Math.ceil( w ) );
		c.height = Math.max( 1, Math.ceil( h ) );
		return c;
	}

	function roundRect( ctx, x, y, w, h, r ) {
		r = Math.min( r, w / 2, h / 2 );
		ctx.beginPath();
		ctx.moveTo( x + r, y );
		ctx.arcTo( x + w, y, x + w, y + h, r );
		ctx.arcTo( x + w, y + h, x, y + h, r );
		ctx.arcTo( x, y + h, x, y, r );
		ctx.arcTo( x, y, x + w, y, r );
		ctx.closePath();
	}

	/* Text with even letter spacing, where the browser cannot do it itself. */
	function spaced( ctx, text, spacing ) {
		if ( 'letterSpacing' in ctx ) {
			ctx.letterSpacing = spacing + 'px';
		}
		return text;
	}

	function wrapLines( ctx, text, width, max ) {
		var words = String( text ).split( /\s+/ );
		var lines = [];
		var line = '';
		for ( var i = 0; i < words.length; i++ ) {
			var test = line ? line + ' ' + words[ i ] : words[ i ];
			if ( ctx.measureText( test ).width > width && line ) {
				lines.push( line );
				line = words[ i ];
				if ( lines.length === max - 1 ) {
					// The last line takes the rest, cut with an ellipsis.
					var rest = words.slice( i ).join( ' ' );
					while ( ctx.measureText( rest + '…' ).width > width && rest.length > 1 ) {
						rest = rest.slice( 0, -1 );
					}
					lines.push( rest === words.slice( i ).join( ' ' ) ? rest : rest.trim() + '…' );
					return lines;
				}
			} else {
				line = test;
			}
		}
		if ( line ) {
			lines.push( line );
		}
		return lines;
	}

	/* ─────────────────────────── the stage ─────────────────────────── */

	function Stage( canvas, options ) {
		options = options || {};
		this.canvas = canvas;
		this.W = options.width || 1280;
		this.H = options.height || 720;
		canvas.width = this.W;
		canvas.height = this.H;
		this.ctx = canvas.getContext( '2d', { alpha: false } );
		this.canFilter = 'filter' in this.ctx;

		this.layers = [];
		this.particles = [];
		this.rain = null;
		this.look = 'natural';
		this.adjust = {}; // only the sliders that have been moved
		this.compare = false; // the camera as it comes, for a moment
		this.grader = new GRADE.Grader();
		this.canGrade = this.grader.ok;
		this.frame = 'none';
		this.frameTint = '';
		this.frameWords = {};
		this.light = 'none';
		this.lightLevel = LIGHT_LEVEL;
		this.hideGraphics = false;
		this.placeholder = options.placeholder || '';
		this.logo = options.logo || '';
		this.images = {};
		this.sprites = {};
		this.onTick = null;
		this.onChange = null;
		this.frames = 0;
		this.fps = 0;
		this._fpsAt = 0;
		this._last = 0;
		this._worker = null;
		this._filter = '';
		this._frameCanvas = null;
		this._frameSig = '';
		this._frameLive = {};
		this._lightKey = '';
		this._lightCache = {};
		this._capture = null;

		this.cover = false;
		this.coverLine = '';
		this.coverLogo = options.coverLogo || '';
		this.coverLink = options.coverLink || '';
		this.brand = options.brand || '';
		this._coverOff = 0;
		this._coverBase = null;
		this._coverOrbs = null;
		this._coverText = null;
		this._coverSparks = null;
		if ( this.coverLogo ) {
			this.image( this.coverLogo );
		}

		this.sources = {};
		this.main = '';
		this._snap = null;
		this._snapFrom = 0;
		this._snapMs = 0;
	}

	/* The clock, from a worker so a background tab keeps it running. */
	Stage.prototype.start = function () {
		var self = this;
		if ( this._worker ) {
			return;
		}
		var body = 'var t=null;onmessage=function(e){clearInterval(t);if(e.data>0){t=setInterval(function(){postMessage(0)},e.data)}}';
		try {
			var url = URL.createObjectURL( new Blob( [ body ], { type: 'text/javascript' } ) );
			this._worker = new Worker( url );
			this._worker.onmessage = function () {
				self.tick();
			};
			this._worker.postMessage( Math.round( 1000 / FPS ) );
		} catch ( e ) {
			// A blocked worker: the page still works in the foreground.
			this._timer = setInterval( function () {
				self.tick();
			}, Math.round( 1000 / FPS ) );
		}
	};

	Stage.prototype.tick = function () {
		var now = performance.now();
		this.render( now );
		this.frames++;
		if ( now - this._fpsAt >= 1000 ) {
			this.fps = this.frames;
			this.frames = 0;
			this._fpsAt = now;
		}
		if ( this.onTick ) {
			this.onTick( now );
		}
	};

	/* The canvas as a video track. One track for the life of the stage: the camera behind it can change. */
	Stage.prototype.captureTrack = function () {
		if ( ! this._capture ) {
			this._capture = this.canvas.captureStream( FPS ).getVideoTracks()[ 0 ];
		}
		return this._capture;
	};

	/* ─────────────────────────── sources: every camera, and the screen ─────────────────────────── */

	/**
	 * A source is anything with moving pictures: a camera or a shared screen. One is the main picture;
	 * others can sit on top as `source` layers. They all stay open, which is what makes a switch instant.
	 *
	 * @param {string}           id    Stable across reloads for a camera (cam:<device id>).
	 * @param {HTMLVideoElement} video Playing, in the page.
	 * @param {Object}           meta  label, kind (camera|screen), flip, zoom, fit (cover|contain).
	 */
	Stage.prototype.addSource = function ( id, video, meta ) {
		var source = { id: id, video: video, label: '', kind: 'camera', flip: false, zoom: 1, fit: 'cover' };
		for ( var key in meta || {} ) {
			source[ key ] = meta[ key ];
		}
		this.sources[ id ] = source;
		if ( ! this.main ) {
			this.main = id;
		}
		return source;
	};

	Stage.prototype.removeSource = function ( id ) {
		delete this.sources[ id ];
		this.layers = this.layers.filter( function ( layer ) {
			return ! ( 'source' === layer.type && layer.sourceId === id );
		} );
		if ( this.main === id ) {
			this.main = Object.keys( this.sources )[ 0 ] || '';
		}
		this.changed();
	};

	/* Keep the frame on screen now, to fade away over whatever is drawn next. */
	Stage.prototype.holdFrame = function ( ms ) {
		if ( ! ms || this.cover ) {
			return;
		}
		if ( ! this._snap ) {
			this._snap = makeCanvas( this.W, this.H );
		}
		this._snap.getContext( '2d' ).drawImage( this.canvas, 0, 0 );
		this._snapFrom = performance.now();
		this._snapMs = ms;
	};

	/* Make a source the main picture. @param {number} ms Fade length; 0 is a straight cut. */
	Stage.prototype.setMain = function ( id, ms ) {
		if ( ! this.sources[ id ] || this.main === id ) {
			return;
		}
		this.holdFrame( ms );
		this.main = id;
		this.changed();
	};

	Stage.prototype.setSourceProps = function ( id, props ) {
		var source = this.sources[ id ];
		if ( source ) {
			for ( var key in props ) {
				source[ key ] = props[ key ];
			}
			this.changed();
		}
	};

	Stage.prototype.setLook = function ( key ) {
		this.look = LOOKS[ key ] ? key : 'natural';
		this._rebuildFilter();
		this.changed();
	};

	/* One slider. A slider at zero is not kept, so "has anything been moved" is just whether anything is here. */
	Stage.prototype.setAdjust = function ( key, value ) {
		var def = GRADE.ADJUST.filter( function ( d ) {
			return d.key === key;
		} )[ 0 ];
		if ( ! def ) {
			return;
		}
		value = clamp( Math.round( +value ) || 0, def.min, def.max );
		if ( value ) {
			this.adjust[ key ] = value;
		} else {
			delete this.adjust[ key ];
		}
		this._rebuildFilter();
	};

	Stage.prototype.resetAdjust = function () {
		this.adjust = {};
		this._rebuildFilter();
	};

	/* One look at the camera now, and the sliders it suggests. @return {boolean} Whether there was a picture to look at. */
	Stage.prototype.autoAdjust = function () {
		var source = this.sources[ this.main ];
		var box = source ? this._box( source ) : null;
		if ( ! box ) {
			return false;
		}
		var c = makeCanvas( 64, 36 );
		var x = c.getContext( '2d', { willReadFrequently: true } );
		x.drawImage( source.video, box.sx, box.sy, box.sw, box.sh, 0, 0, 64, 36 );
		var values = GRADE.auto( x.getImageData( 0, 0, 64, 36 ).data );
		for ( var key in values ) {
			this.setAdjust( key, values[ key ] );
		}
		return true;
	};

	Stage.prototype._rebuildFilter = function () {
		var parts = [];
		if ( LOOKS[ this.look ] && LOOKS[ this.look ].filter ) {
			parts.push( LOOKS[ this.look ].filter );
		}
		// With the graphics card the sliders are done before this; without it, what can be done is done here.
		if ( ! this.canGrade ) {
			parts.push( GRADE.cssFilter( this.adjust ) );
		}
		this._filter = parts.join( ' ' ).trim();
	};

	/* @param {string} tint One of TINTS, or nothing for the frame's own colour. */
	Stage.prototype.setFrame = function ( key, tint ) {
		this.frame = FRAMES[ key ] ? key : 'none';
		this.frameTint = TINTS[ tint ] ? tint : '';
		this.occasion = null;
		this.changed();
	};

	Stage.prototype.setFrameTint = function ( tint ) {
		this.frameTint = TINTS[ tint ] ? tint : '';
		this.changed();
	};

	/* The words on the frame on now. Never set, it has the shop's name; emptied, none (a masthead keeps the shop's name). */
	Stage.prototype.setFrameWords = function ( words ) {
		this.frameWords[ this.frame ] = String( words );
		this.changed();
	};

	Stage.prototype.frameOptions = function ( key, tint ) {
		var def = FRAMES[ key ] || {};
		var words = undefined !== this.frameWords[ key ] ? this.frameWords[ key ] : this.brand;
		return { t: TINTS[ tint || def.tint ] || TINTS.rose, words: def.words ? words : '', brand: this.brand };
	};

	Stage.prototype.setLight = function ( key, level ) {
		this.light = LIGHTS[ key ] ? key : 'none';
		if ( undefined !== level ) {
			this.lightLevel = clamp( +level, 0, 1 );
		}
		this.changed();
	};

	/* A frame for a season or celebration: { key, words, font, band: [ a, b ], line, ribbon: [ a, b ], ink, art: [ urls ] }. */
	Stage.prototype.setOccasion = function ( occasion ) {
		var self = this;
		this.occasion = occasion || null;
		this._occasionFrame = null;
		if ( occasion ) {
			this.frame = 'none';
			( occasion.art || [] ).forEach( function ( src ) {
				self.image( src, function () {
					self._occasionFrame = null; // drawn again with this picture in it
				} );
			} );
		}
		this.changed();
	};

	Stage.prototype.changed = function () {
		if ( this.onChange ) {
			this.onChange();
		}
	};

	/* ─────────────────────────── images ─────────────────────────── */

	Stage.prototype.image = function ( src, done ) {
		var self = this;
		var entry = this.images[ src ];
		if ( entry ) {
			this._decodeFrames( src, entry );
			if ( entry.ok && done ) {
				done( entry.img );
			}
			return entry.ok ? entry.img : null;
		}
		var img = new Image();
		img.crossOrigin = 'anonymous';
		entry = this.images[ src ] = { img: img, ok: false };
		this._decodeFrames( src, entry );
		img.onload = function () {
			entry.ok = true;
			// Anything drawn from this image is out of date now.
			self.layers.forEach( function ( layer ) {
				layer._key = '';
			} );
			if ( done ) {
				done( img );
			}
		};
		img.onerror = function () {
			entry.failed = true;
		};
		img.src = src;
		return null;
	};

	/*
	 * The frames of a GIF or animated WebP, each with the moment it ends. Until they are ready, and in a browser with
	 * no ImageDecoder, the picture just shows its first frame. Read with fetch, so a file that does not allow it fails
	 * here instead of tainting the canvas, which would stop the whole stream.
	 */
	Stage.prototype._decodeFrames = function ( src, entry ) {
		var type = /\.gif(\?|$)/i.test( src ) ? 'image/gif' : ( /\.webp(\?|$)/i.test( src ) ? 'image/webp' : '' );
		if ( ! type || entry.frames || entry.decoding || entry.still || ! window.ImageDecoder || ! window.createImageBitmap ) {
			return;
		}
		entry.decoding = true;
		var decoder = null;
		window.fetch( src, { credentials: 'same-origin' } ).then( function ( r ) {
			if ( ! r.ok ) {
				throw new Error( 'HTTP ' + r.status );
			}
			return r.arrayBuffer();
		} ).then( function ( bytes ) {
			decoder = new window.ImageDecoder( { data: bytes, type: type } );
			return decoder.tracks.ready.then( function () {
				return decoder.completed;
			} );
		} ).then( function () {
			var total = decoder.tracks.selectedTrack.frameCount;
			if ( total < 2 ) {
				entry.still = true;
				return null;
			}
			var step = Math.ceil( total / MAX_GIF_FRAMES );
			var frames = [];
			var span = 0;
			var next = function ( i ) {
				if ( i >= total ) {
					return { frames: frames, span: span };
				}
				return decoder.decode( { frameIndex: i } ).then( function ( result ) {
					var image = result.image;
					var ms = ( image.duration || 0 ) / 1000;
					// Browsers play a delay under 20 ms as 100 ms, and GIFs are made expecting that.
					ms = ( ms < 20 ? 100 : ms ) * step;
					var fit = Math.min( 1, MAX_GIF_EDGE / Math.max( image.displayWidth, image.displayHeight ) );
					return window.createImageBitmap( image, {
						resizeWidth: Math.max( 1, Math.round( image.displayWidth * fit ) ),
						resizeHeight: Math.max( 1, Math.round( image.displayHeight * fit ) ),
						resizeQuality: 'medium'
					} ).then( function ( bitmap ) {
						image.close();
						span += ms;
						frames.push( { bitmap: bitmap, until: span } );
						return next( i + step );
					} );
				} );
			};
			return next( 0 );
		} ).then( function ( done ) {
			entry.decoding = false;
			if ( done ) {
				entry.frames = done.frames;
				entry.span = done.span;
			}
		} ).catch( function () {
			entry.decoding = false;
			entry.still = true; // the first frame is still there
		} ).then( function () {
			if ( decoder ) {
				decoder.close();
			}
		} );
	};

	function frameAt( entry, now ) {
		var t = now % entry.span;
		for ( var i = 0; i < entry.frames.length; i++ ) {
			if ( t < entry.frames[ i ].until ) {
				return entry.frames[ i ].bitmap;
			}
		}
		return entry.frames[ entry.frames.length - 1 ].bitmap;
	}

	/* A decoded GIF is a lot of memory, so it goes when nothing is showing it, and is decoded again if it comes back. */
	Stage.prototype._releaseFrames = function ( src ) {
		var entry = this.images[ src ];
		var inUse = this.layers.some( function ( layer ) {
			return layer.src === src && ! layer._gone;
		} );
		if ( ! entry || ! entry.frames || inUse ) {
			return;
		}
		entry.frames.forEach( function ( frame ) {
			frame.bitmap.close();
		} );
		entry.frames = null;
	};

	/* ─────────────────────────── layers ─────────────────────────── */

	Stage.prototype.add = function ( def, quiet ) {
		var base = LAYER_DEFAULTS[ def.type ] || {};
		var layer = {};
		var key;
		for ( key in base ) {
			layer[ key ] = base[ key ];
		}
		layer.x = 0.5;
		layer.y = 0.5;
		layer.rot = 0;
		layer.opacity = 1;
		layer.anim = 'none';
		layer.visible = true;
		for ( key in def ) {
			layer[ key ] = def[ key ];
		}
		layer.id = def.id || 'l' + Date.now().toString( 36 ) + ( ++uid );
		layer._t0 = quiet ? -1e9 : performance.now();
		layer._key = '';
		this.layers.push( layer );
		if ( layer.src ) {
			this.image( layer.src );
		}
		if ( layer.images ) {
			layer.images.forEach( function ( src ) {
				this.image( src );
			}, this );
		}
		if ( ! quiet ) {
			this.changed();
		}
		return layer;
	};

	Stage.prototype.get = function ( id ) {
		for ( var i = 0; i < this.layers.length; i++ ) {
			if ( this.layers[ i ].id === id ) {
				return this.layers[ i ];
			}
		}
		return null;
	};

	Stage.prototype.update = function ( id, props, quiet ) {
		var layer = this.get( id );
		if ( ! layer ) {
			return null;
		}
		for ( var key in props ) {
			layer[ key ] = props[ key ];
		}
		layer._key = '';
		if ( ! quiet ) {
			this.changed();
		}
		return layer;
	};

	/* Leaves with a short fade; drops off the list once that has played. */
	Stage.prototype.remove = function ( id ) {
		var layer = this.get( id );
		if ( layer && ! layer._gone ) {
			layer._gone = performance.now();
			this.changed();
		}
	};

	Stage.prototype.clear = function () {
		var now = performance.now();
		this.layers.forEach( function ( layer ) {
			layer._gone = layer._gone || now;
		} );
		this.setRain( null );
		this.changed();
	};

	Stage.prototype.raise = function ( id ) {
		var layer = this.get( id );
		if ( layer ) {
			this.layers.splice( this.layers.indexOf( layer ), 1 );
			this.layers.push( layer );
			this.changed();
		}
	};

	/* The fraction of the picture a layer covers, ignoring rotation and idle animation. */
	Stage.prototype.rect = function ( id ) {
		var layer = this.get( id );
		if ( ! layer || ! layer._box ) {
			return null;
		}
		var b = layer._box;
		return [ ( b.cx - b.w / 2 ) / this.W, ( b.cy - b.h / 2 ) / this.H, b.w / this.W, b.h / this.H ];
	};

	/* Topmost layer under a point given in picture fractions. */
	Stage.prototype.hit = function ( nx, ny ) {
		var px = nx * this.W;
		var py = ny * this.H;
		for ( var i = this.layers.length - 1; i >= 0; i-- ) {
			var layer = this.layers[ i ];
			if ( layer._gone || false === layer.visible || ! layer._box ) {
				continue;
			}
			var b = layer._box;
			if ( Math.abs( px - b.cx ) <= b.w / 2 && Math.abs( py - b.cy ) <= b.h / 2 ) {
				return layer;
			}
		}
		return null;
	};

	/* What gets saved. Anything starting with an underscore is only for this page's life; `_brief` layers too. */
	Stage.prototype.toJSON = function () {
		return {
			look: this.look,
			adjust: Object.assign( {}, this.adjust ),
			frame: this.frame,
			frameTint: this.frameTint,
			frameWords: Object.assign( {}, this.frameWords ),
			light: this.light,
			lightLevel: this.lightLevel,
			layers: this.layers.filter( function ( layer ) {
				return ! layer._gone && ! layer._brief;
			} ).map( function ( layer ) {
				var out = {};
				for ( var key in layer ) {
					if ( '_' !== key.charAt( 0 ) ) {
						out[ key ] = layer[ key ];
					}
				}
				return out;
			} ),
			occasion: this.occasion
		};
	};

	Stage.prototype.load = function ( scene ) {
		var self = this;
		scene = scene || {};
		this.layers = [];
		( scene.layers || [] ).forEach( function ( def ) {
			self.add( def, true );
		} );
		this.look = LOOKS[ scene.look ] ? scene.look : 'natural';
		this.adjust = {};
		for ( var key in scene.adjust || {} ) {
			this.setAdjust( key, scene.adjust[ key ] );
		}
		if ( ! scene.adjust && +scene.brightness && 1 !== +scene.brightness ) {
			// A scene saved when brightness was the only slider. Same picture: the slider is stops, this was a multiplier.
			this.setAdjust( 'bright', Math.log2( +scene.brightness ) / 0.7 * 100 );
		}
		this.frame = FRAMES[ scene.frame ] ? scene.frame : 'none';
		this.frameTint = TINTS[ scene.frameTint ] ? scene.frameTint : '';
		this.frameWords = scene.frameWords && ! Array.isArray( scene.frameWords ) ? scene.frameWords : {};
		this.light = LIGHTS[ scene.light ] ? scene.light : 'none';
		this.lightLevel = undefined !== scene.lightLevel ? clamp( +scene.lightLevel, 0, 1 ) : LIGHT_LEVEL;
		this.setOccasion( scene.occasion || null );
		this._rebuildFilter();
	};

	/* ─────────────────────────── effects ─────────────────────────── */

	Stage.prototype.spriteFor = function ( emoji ) {
		if ( this.sprites[ emoji ] ) {
			return this.sprites[ emoji ];
		}
		var c = makeCanvas( 160, 160 );
		var x = c.getContext( '2d' );
		x.font = '120px "Segoe UI Emoji","Apple Color Emoji","Noto Color Emoji",sans-serif';
		x.textAlign = 'center';
		x.textBaseline = 'middle';
		x.fillText( emoji, 80, 88 );
		this.sprites[ emoji ] = c;
		return c;
	};

	/* A particle drawn here rather than an emoji, for what emoji fonts do not all have. */
	Stage.prototype.paintedSprite = function ( name ) {
		var key = 'paint:' + name;
		if ( ! this.sprites[ key ] ) {
			var c = makeCanvas( 160, 160 );
			root.WHDFrames.painters[ name ]( c.getContext( '2d' ), 160 );
			this.sprites[ key ] = c;
		}
		return this.sprites[ key ];
	};

	Stage.prototype.emitOne = function ( effect, src, delay ) {
		var size = this.H * rand( effect.size[ 0 ], effect.size[ 1 ] );
		var W = this.W;
		var H = this.H;
		var p = {
			kind: effect.kind,
			size: size,
			delay: delay || 0,
			age: 0,
			rot: rand( -0.4, 0.4 ),
			spin: rand( -1.2, 1.2 ),
			sway: rand( 0, Math.PI * 2 ),
			swayAmp: rand( 10, 46 ),
			swayRate: rand( 1, 2.4 ),
			alpha: 1
		};
		if ( effect.colors ) {
			p.color = pick( effect.colors );
			p.aspect = rand( 0.4, 1 );
			p.spin = rand( -6, 6 );
		} else if ( src ) {
			p.image = src;
		} else if ( effect.paint ) {
			p.sprite = this.paintedSprite( effect.paint );
		} else {
			p.sprite = this.spriteFor( pick( effect.emoji ) );
		}
		switch ( effect.kind ) {
			case 'rise':
				p.x = rand( W * 0.04, W * 0.96 );
				p.y = H + size;
				p.vx = 0;
				p.vy = -rand( H * 0.16, H * 0.34 );
				p.life = ( H + size * 2 ) / -p.vy;
				break;
			case 'fall':
				p.x = rand( 0, W );
				p.y = -size;
				p.vx = rand( -24, 24 );
				p.vy = rand( H * 0.16, H * 0.36 );
				p.life = ( H + size * 2 ) / p.vy;
				break;
			case 'twinkle':
				p.x = rand( W * 0.06, W * 0.94 );
				p.y = rand( H * 0.06, H * 0.9 );
				p.vx = 0;
				p.vy = 0;
				p.swayAmp = 0;
				p.life = rand( 0.7, 1.5 );
				break;
			default: { // burst
				var angle = rand( 0, Math.PI * 2 );
				var speed = rand( H * 0.3, H * 0.75 );
				p.x = W / 2;
				p.y = H * 0.55;
				p.vx = Math.cos( angle ) * speed;
				p.vy = Math.sin( angle ) * speed - H * 0.15;
				p.g = H * 0.7;
				p.swayAmp = 0;
				p.life = rand( 1.2, 1.9 );
			}
		}
		if ( this.particles.length < 500 ) {
			this.particles.push( p );
		}
	};

	/* One burst of an effect, spread over a couple of seconds. */
	Stage.prototype.burst = function ( key, src ) {
		var effect = EFFECTS[ key ];
		if ( ! effect ) {
			return;
		}
		for ( var i = 0; i < effect.count; i++ ) {
			this.emitOne( effect, src, 'burst' === effect.kind ? rand( 0, 0.15 ) : rand( 0, 2.2 ) );
		}
	};

	/* Pictures floating up: a burst of a picture of the host's own. */
	Stage.prototype.floatImage = function ( src, kind ) {
		var self = this;
		this.image( src, function () {
			var effect = { kind: kind || 'rise', size: [ 0.1, 0.2 ], count: 12 };
			for ( var i = 0; i < effect.count; i++ ) {
				self.emitOne( effect, src, rand( 0, 2.4 ) );
			}
		} );
	};

	/* An effect that keeps going until it is turned off. */
	Stage.prototype.setRain = function ( key, src, rate ) {
		this.rain = key && ( EFFECTS[ key ] || src ) ? { key: key, src: src || '', acc: 0, rate: rate || 1 } : null;
		if ( src ) {
			this.image( src );
		}
	};

	Stage.prototype._runRain = function ( dt ) {
		var rain = this.rain;
		if ( ! rain ) {
			return;
		}
		var effect = rain.src ? { kind: 'rise', size: [ 0.1, 0.18 ], count: 12 } : EFFECTS[ rain.key ];
		// A burst spread over 2.2 s, repeating: the same density as pressing it over and over.
		rain.acc += dt * ( effect.count / 2.2 ) * rain.rate;
		while ( rain.acc >= 1 ) {
			rain.acc -= 1;
			this.emitOne( effect, rain.src );
		}
	};

	Stage.prototype._drawParticles = function ( ctx, dt ) {
		var list = this.particles;
		var keep = [];
		for ( var i = 0; i < list.length; i++ ) {
			var p = list[ i ];
			if ( p.delay > 0 ) {
				p.delay -= dt;
				keep.push( p );
				continue;
			}
			p.age += dt;
			if ( p.age > p.life ) {
				continue;
			}
			if ( p.g ) {
				p.vy += p.g * dt;
			}
			p.x += p.vx * dt;
			p.y += p.vy * dt;
			p.rot += p.spin * dt;
			p.sway += p.swayRate * dt;

			var t = p.age / p.life;
			var alpha = 1;
			var scale = 1;
			if ( 'twinkle' === p.kind ) {
				scale = Math.sin( t * Math.PI );
				alpha = scale;
			} else if ( 'burst' === p.kind ) {
				alpha = t > 0.7 ? ( 1 - t ) / 0.3 : 1;
				scale = 0.6 + 0.4 * easeOutCubic( Math.min( 1, t * 3 ) );
			} else {
				alpha = t > 0.85 ? ( 1 - t ) / 0.15 : Math.min( 1, t * 8 );
			}

			ctx.save();
			ctx.globalAlpha = clamp( alpha, 0, 1 );
			ctx.translate( p.x + Math.sin( p.sway ) * p.swayAmp, p.y );
			ctx.rotate( p.rot );
			var s = p.size * scale;
			if ( p.color ) {
				ctx.fillStyle = p.color;
				ctx.fillRect( -s / 2, -s * p.aspect / 2, s, s * p.aspect );
			} else if ( p.sprite ) {
				ctx.drawImage( p.sprite, -s / 2, -s / 2, s, s );
			} else if ( p.image ) {
				var entry = this.images[ p.image ];
				if ( entry && entry.ok ) {
					var ratio = entry.img.naturalHeight / ( entry.img.naturalWidth || 1 );
					ctx.drawImage( entry.img, -s / 2, -s * ratio / 2, s, s * ratio );
				}
			}
			ctx.restore();
			keep.push( p );
		}
		this.particles = keep;
	};

	/* ─────────────────────────── drawing a layer once ─────────────────────────── */

	Stage.prototype._textSprite = function ( layer ) {
		var style = layer.style || 'ribbon';
		var lines = String( layer.text || '' ).split( /\n/ ).slice( 0, 4 );
		var probe = makeCanvas( 4, 4 ).getContext( '2d' );
		var size = 96;
		var font;
		var pad = [ 56, 30 ];
		var spacing = 0;
		switch ( style ) {
			case 'serif':
				font = 'italic 500 ' + size + 'px ' + SERIF;
				pad = [ 36, 24 ];
				break;
			case 'tag':
				font = '600 ' + ( size - 24 ) + 'px ' + SANS;
				size = 72;
				break;
			case 'dark':
				font = '600 ' + ( size - 24 ) + 'px ' + SANS;
				size = 72;
				break;
			case 'neon':
				font = '700 ' + size + 'px ' + SANS;
				pad = [ 60, 50 ];
				break;
			case 'burst':
				font = '800 ' + ( size - 16 ) + 'px ' + SANS;
				size = 80;
				spacing = 2;
				break;
			default: // ribbon
				font = '800 ' + ( size - 28 ) + 'px ' + SANS;
				size = 68;
				spacing = 6;
				lines = lines.map( function ( l ) {
					return l.toUpperCase();
				} );
		}
		probe.font = font;
		spaced( probe, '', spacing );
		var textW = 1;
		lines.forEach( function ( l ) {
			textW = Math.max( textW, probe.measureText( l ).width );
		} );
		var lineH = size * 1.18;
		var textH = lineH * lines.length;

		if ( 'burst' === style ) {
			var d = Math.max( textW + 150, textH + 220 );
			var cb = makeCanvas( d, d );
			var xb = cb.getContext( '2d' );
			var r = d / 2;
			xb.translate( r, r );
			xb.beginPath();
			for ( var i = 0; i < 32; i++ ) {
				var ang = ( i / 32 ) * Math.PI * 2;
				var rad = i % 2 ? r * 0.84 : r * 0.99;
				xb[ i ? 'lineTo' : 'moveTo' ]( Math.cos( ang ) * rad, Math.sin( ang ) * rad );
			}
			xb.closePath();
			xb.fillStyle = ROSE;
			xb.fill();
			xb.lineWidth = 6;
			xb.strokeStyle = 'rgba(255,255,255,.85)';
			xb.beginPath();
			xb.arc( 0, 0, r * 0.74, 0, Math.PI * 2 );
			xb.stroke();
			xb.font = font;
			spaced( xb, '', spacing );
			xb.fillStyle = '#fff';
			xb.textAlign = 'center';
			xb.textBaseline = 'middle';
			lines.forEach( function ( l, n ) {
				xb.fillText( l, 0, ( n - ( lines.length - 1 ) / 2 ) * lineH );
			} );
			return cb;
		}

		var c = makeCanvas( textW + pad[ 0 ] * 2, textH + pad[ 1 ] * 2 );
		var x = c.getContext( '2d' );
		var W = c.width;
		var H = c.height;
		x.font = font;
		spaced( x, '', spacing );
		x.textBaseline = 'middle';
		x.textAlign = 'center';

		if ( 'ribbon' === style ) {
			var g = x.createLinearGradient( 0, 0, W, H );
			g.addColorStop( 0, ROSE );
			g.addColorStop( 1, ROSE_DEEP );
			roundRect( x, 0, 0, W, H, H / 2 );
			x.fillStyle = g;
			x.fill();
			x.fillStyle = '#fff';
		} else if ( 'tag' === style ) {
			roundRect( x, 0, 0, W, H, 18 );
			x.fillStyle = CREAM;
			x.fill();
			x.fillStyle = INK;
		} else if ( 'dark' === style ) {
			roundRect( x, 0, 0, W, H, 18 );
			x.fillStyle = 'rgba(30,22,19,.82)';
			x.fill();
			x.fillStyle = '#fff';
		} else if ( 'neon' === style ) {
			x.shadowColor = '#ff7eb6';
			x.shadowBlur = 34;
			x.fillStyle = '#ffe8f3';
		} else {
			x.shadowColor = 'rgba(0,0,0,.55)';
			x.shadowBlur = 18;
			x.shadowOffsetY = 3;
			x.fillStyle = '#fff';
		}
		lines.forEach( function ( l, n ) {
			var y = H / 2 + ( n - ( lines.length - 1 ) / 2 ) * lineH;
			x.fillText( l, W / 2, y );
			if ( 'neon' === style ) {
				x.fillText( l, W / 2, y ); // twice, for a glow with some body
			}
		} );
		return c;
	};

	Stage.prototype._lowerSprite = function ( layer ) {
		var c = makeCanvas( 900, 170 );
		var x = c.getContext( '2d' );
		roundRect( x, 0, 0, 900, 170, 16 );
		x.fillStyle = 'rgba(247,242,237,.96)';
		x.fill();
		x.fillStyle = ROSE;
		x.fillRect( 0, 18, 10, 134 );
		x.fillStyle = INK;
		x.font = '500 68px ' + SERIF;
		x.textBaseline = 'middle';
		var title = wrapLines( x, layer.title || '', 830, 1 )[ 0 ] || '';
		x.fillText( title, 40, layer.subtitle ? 64 : 85 );
		if ( layer.subtitle ) {
			x.font = '600 30px ' + SANS;
			spaced( x, '', 1 );
			x.fillStyle = ROSE_DEEP;
			x.fillText( wrapLines( x, layer.subtitle, 830, 1 )[ 0 ] || '', 42, 124 );
		}
		return c;
	};

	Stage.prototype._countdownText = function ( layer ) {
		var left = Math.max( 0, Math.ceil( ( ( +layer.endsAt || 0 ) - Date.now() ) / 1000 ) );
		var m = Math.floor( left / 60 );
		var s = left % 60;
		return ( m < 10 ? '0' : '' ) + m + ':' + ( s < 10 ? '0' : '' ) + s;
	};

	Stage.prototype._countdownSprite = function ( layer ) {
		var c = makeCanvas( 520, 220 );
		var x = c.getContext( '2d' );
		var g = x.createLinearGradient( 0, 0, 520, 220 );
		g.addColorStop( 0, 'rgba(43,31,27,.92)' );
		g.addColorStop( 1, 'rgba(90,60,52,.92)' );
		roundRect( x, 0, 0, 520, 220, 26 );
		x.fillStyle = g;
		x.fill();
		x.textAlign = 'center';
		x.textBaseline = 'middle';
		x.font = '700 32px ' + SANS;
		spaced( x, '', 5 );
		x.fillStyle = '#e9c8bc';
		x.fillText( String( layer.label || '' ).toUpperCase(), 260, 58 );
		x.font = '800 104px ' + SANS;
		spaced( x, '', 2 );
		x.fillStyle = '#fff';
		x.fillText( this._countdownText( layer ), 260, 142 );
		return c;
	};

	/**
	 * The product card: photo, name, price, and what to do about it.
	 *
	 * The short link is always on it, because on TikTok it is the only way to the piece.
	 */
	Stage.prototype._productSprite = function ( layer ) {
		var W = 860;
		var H = 384;
		var c = makeCanvas( W, H );
		var x = c.getContext( '2d' );

		x.save();
		x.shadowColor = 'rgba(30,20,16,.35)';
		x.shadowBlur = 24;
		x.shadowOffsetY = 8;
		roundRect( x, 12, 10, W - 24, H - 26, 26 );
		x.fillStyle = '#fffaf6';
		x.fill();
		x.restore();

		x.save();
		roundRect( x, 12, 10, H - 26, H - 26, 26 );
		x.clip();
		x.fillStyle = '#efe6de';
		x.fillRect( 12, 10, H - 26, H - 26 );
		var entry = layer.image ? this.images[ layer.image ] : null;
		if ( entry && entry.ok ) {
			var img = entry.img;
			var box = H - 26;
			var scale = Math.max( box / img.naturalWidth, box / img.naturalHeight );
			var dw = img.naturalWidth * scale;
			var dh = img.naturalHeight * scale;
			x.drawImage( img, 12 + ( box - dw ) / 2, 10 + ( box - dh ) / 2, dw, dh );
		}
		x.restore();

		var tx = H + 8;
		var tw = W - tx - 40;
		var y = 58;
		x.textBaseline = 'alphabetic';
		x.fillStyle = ROSE_DEEP;
		x.font = '800 22px ' + SANS;
		spaced( x, '', 4 );
		x.fillText( String( layer.kicker || '' ).toUpperCase(), tx, y );

		x.font = '500 50px ' + SERIF;
		spaced( x, '', 0 );
		x.fillStyle = INK;
		var lines = wrapLines( x, layer.name || '', tw, 2 );
		y += 14;
		lines.forEach( function ( l ) {
			y += 50;
			x.fillText( l, tx, y );
		} );

		y += 52;
		x.font = '800 42px ' + SANS;
		spaced( x, '', 0 );
		x.fillStyle = INK;
		var price = String( layer.price || '' );
		x.fillText( price, tx, y );
		if ( layer.regular ) {
			var pw = x.measureText( price ).width;
			x.font = '500 28px ' + SANS;
			x.fillStyle = '#9a8a82';
			var rw = x.measureText( layer.regular ).width;
			x.fillText( layer.regular, tx + pw + 16, y );
			x.fillRect( tx + pw + 14, y - 10, rw + 4, 2.5 );
		}

		if ( layer.cta ) {
			x.font = '700 24px ' + SANS;
			var cw = Math.min( x.measureText( layer.cta ).width + 44, tw );
			y += 26;
			roundRect( x, tx, y, cw, 48, 24 );
			x.fillStyle = ROSE;
			x.fill();
			x.fillStyle = '#fff';
			x.textBaseline = 'middle';
			x.fillText( layer.cta, tx + 22, y + 25, tw - 44 );
			x.textBaseline = 'alphabetic';
			y += 48;
		}

		x.font = '600 22px ' + SANS;
		x.fillStyle = ROSE_DEEP;
		x.fillText( String( layer.link || '' ), tx, Math.min( y + 34, H - 34 ), tw );
		return c;
	};

	/* The slideshow's card, without the photo: that changes every frame, the card does not. */
	Stage.prototype._gallerySprite = function ( layer ) {
		var G = GALLERY;
		var c = makeCanvas( G.W, G.H );
		var x = c.getContext( '2d' );

		x.save();
		x.shadowColor = 'rgba(30,20,16,.4)';
		x.shadowBlur = 22;
		x.shadowOffsetY = 7;
		roundRect( x, G.MX, G.MY, G.CARD_W, G.CARD_H, G.RADIUS );
		x.fillStyle = '#fffaf6';
		x.fill();
		x.restore();

		roundRect( x, G.PHOTO_X, G.PHOTO_Y, G.PHOTO, G.PHOTO, 16 );
		x.fillStyle = '#efe6de';
		x.fill();

		var tx = G.PHOTO_X + 4;
		var tw = G.PHOTO - 8;
		var y = G.PHOTO_Y + G.PHOTO + 46;
		x.textBaseline = 'alphabetic';
		x.font = '500 38px ' + SERIF;
		spaced( x, '', 0 );
		x.fillStyle = INK;
		var lines = wrapLines( x, layer.caption || '', tw, 1 );
		x.fillText( lines[ 0 ] || '', tx, y );

		y += 18;
		if ( layer.cta ) {
			x.font = '700 22px ' + SANS;
			var cw = Math.min( x.measureText( layer.cta ).width + 40, tw );
			roundRect( x, tx, y, cw, 46, 23 );
			x.fillStyle = ROSE;
			x.fill();
			x.fillStyle = '#fff';
			x.textBaseline = 'middle';
			x.fillText( layer.cta, tx + 20, y + 24, tw - 40 );
		} else if ( layer.price ) {
			x.font = '800 30px ' + SANS;
			x.fillStyle = INK;
			x.textBaseline = 'middle';
			x.fillText( layer.price, tx, y + 24, tw );
		}
		return c;
	};

	/* The ready-drawn canvas for a layer, redrawn only when what it shows has changed. */
	Stage.prototype._spriteOf = function ( layer ) {
		if ( 'image' === layer.type ) {
			var entry = this.images[ layer.src ];
			return entry && entry.ok ? entry.img : null;
		}
		var key;
		switch ( layer.type ) {
			case 'text':
				key = 't|' + layer.style + '|' + layer.text;
				break;
			case 'lower':
				key = 'l|' + layer.title + '|' + layer.subtitle;
				break;
			case 'countdown':
				key = 'c|' + layer.label + '|' + this._countdownText( layer );
				break;
			case 'product':
				key = 'p|' + layer.name + layer.price + layer.regular + layer.cta + layer.link + layer.kicker + ( layer.image && this.images[ layer.image ] && this.images[ layer.image ].ok ? 1 : 0 );
				break;
			case 'gallery':
				key = 'g|' + layer.caption + layer.cta + layer.price;
				break;
			default:
				return null;
		}
		if ( layer._key === key && layer._sprite ) {
			return layer._sprite;
		}
		switch ( layer.type ) {
			case 'text':
				layer._sprite = this._textSprite( layer );
				break;
			case 'lower':
				layer._sprite = this._lowerSprite( layer );
				break;
			case 'countdown':
				layer._sprite = this._countdownSprite( layer );
				break;
			case 'product':
				if ( layer.image ) {
					this.image( layer.image );
				}
				layer._sprite = this._productSprite( layer );
				break;
			case 'gallery':
				layer._sprite = this._gallerySprite( layer );
				break;
		}
		layer._key = key;
		return layer._sprite;
	};

	Stage.prototype._drawTicker = function ( ctx, layer, now, alpha ) {
		var bandH = Math.round( this.H * 0.075 );
		var cy = layer.y * this.H;
		layer._box = { cx: this.W / 2, cy: cy, w: this.W, h: bandH };
		var key = 'k|' + layer.text;
		if ( layer._key !== key || ! layer._sprite ) {
			var probe = makeCanvas( 4, 4 ).getContext( '2d' );
			var font = '600 ' + Math.round( bandH * 0.46 ) + 'px ' + SANS;
			probe.font = font;
			var text = String( layer.text || '' ) + '      ✦      ';
			var c = makeCanvas( probe.measureText( text ).width + 2, bandH );
			var x = c.getContext( '2d' );
			x.font = font;
			x.textBaseline = 'middle';
			x.fillStyle = '#fff';
			x.fillText( text, 0, bandH / 2 + 1 );
			layer._sprite = c;
			layer._key = key;
		}
		ctx.save();
		ctx.globalAlpha = alpha * ( layer.opacity || 1 );
		ctx.fillStyle = 'rgba(43,31,27,.86)';
		ctx.fillRect( 0, cy - bandH / 2, this.W, bandH );
		ctx.fillStyle = ROSE;
		ctx.fillRect( 0, cy - bandH / 2, this.W, 3 );
		var w = layer._sprite.width;
		var offset = ( now / 1000 * ( layer.speed || 110 ) ) % w;
		for ( var px = -offset; px < this.W; px += w ) {
			ctx.drawImage( layer._sprite, px, cy - bandH / 2 );
		}
		ctx.restore();
	};

	Stage.prototype._drawLayer = function ( ctx, layer, now ) {
		var age = now - layer._t0;
		var leaving = layer._gone ? ( now - layer._gone ) / 260 : 0;
		var showcase = 'product' === layer.type || 'gallery' === layer.type;
		var enter = clamp( age / ( showcase ? 680 : 460 ), 0, 1 );
		var alpha = layer._gone ? 1 - clamp( leaving, 0, 1 ) : clamp( age / 200, 0, 1 );

		// Where the photos will open: the host's own marker, in the page. Nothing of it goes in the picture.
		if ( 'spot' === layer.type ) {
			var spotW = ( +layer.w || 0.34 ) * this.W;
			layer._box = { cx: layer.x * this.W, cy: layer.y * this.H, w: spotW, h: spotW * ( +layer.ratio || GALLERY.H / GALLERY.W ) };
			return;
		}

		if ( 'ticker' === layer.type ) {
			this._drawTicker( ctx, layer, now, alpha );
			return;
		}
		var isSource = 'source' === layer.type;
		var inset = isSource ? this.sources[ layer.sourceId ] : null;
		var sprite = isSource ? ( inset && inset.video.videoWidth ? inset.video : null ) : this._spriteOf( layer );
		if ( ! sprite ) {
			layer._box = null;
			return;
		}
		if ( 'image' === layer.type ) {
			var picture = this.images[ layer.src ];
			if ( picture && picture.frames ) {
				sprite = frameAt( picture, now );
			}
		}
		var sw = sprite.videoWidth || sprite.naturalWidth || sprite.width;
		var sh = sprite.videoHeight || sprite.naturalHeight || sprite.height;
		var dw = ( +layer.w || 0.2 ) * this.W;
		var dh = isSource && 'circle' === layer.shape ? dw : dw * ( sh / sw );
		var cx = layer.x * this.W;
		var cy = layer.y * this.H;
		layer._box = { cx: cx, cy: cy, w: dw, h: dh };

		var t = now / 1000;
		var scale = 1;
		var stretch = 0; // wider and shorter by this much, or narrower and taller when negative
		var rot = ( +layer.rot || 0 ) * Math.PI / 180;
		var dx = 0;
		var dy = 0;
		switch ( layer.anim ) {
			case 'float':
				dy = Math.sin( t * 1.5 ) * this.H * 0.014;
				dx = Math.cos( t * 0.9 ) * this.W * 0.004;
				break;
			case 'pulse':
				scale = 1 + Math.sin( t * 3.2 ) * 0.045;
				break;
			case 'swing':
				rot += Math.sin( t * 2 ) * 0.11;
				break;
			case 'bounce':
				dy = -Math.abs( Math.sin( t * 2.6 ) ) * this.H * 0.03;
				break;
			case 'spin':
				rot += t * 0.9;
				break;
			case 'wobble':
				rot += Math.sin( t * 6 ) * 0.17;
				dy = -Math.abs( Math.sin( t * 6 ) ) * this.H * 0.012;
				break;
			case 'jelly':
				stretch = Math.sin( t * 7 ) * 0.1;
				break;
			case 'shake':
				dx = Math.sin( t * 31 ) * this.W * 0.006;
				rot += Math.sin( t * 23 ) * 0.055;
				break;
			case 'hop':
				var hop = Math.abs( Math.sin( t * 4.4 ) );
				dy = -hop * this.H * 0.05;
				stretch = hop < 0.2 ? ( 0.2 - hop ) * 0.6 : 0; // squashes as it lands
				break;
		}

		if ( enter < 1 ) {
			if ( showcase ) {
				// Rises into place, a little too far, and settles.
				scale *= 0.68 + 0.32 * easeOutBack( enter );
				dy += ( 1 - easeOutCubic( enter ) ) * dh * 0.2;
				if ( 'gallery' === layer.type ) {
					rot += ( 1 - easeOutCubic( enter ) ) * -0.12;
				}
			} else if ( 'slide' === layer.enter ) {
				var fromLeft = layer.x < 0.5;
				dx += ( fromLeft ? -1 : 1 ) * ( 1 - easeOutCubic( enter ) ) * ( dw * 0.6 + this.W * 0.1 );
			} else if ( 'pop' === layer.enter ) {
				scale *= 0.55 + 0.45 * easeOutBack( enter );
			}
		}
		if ( layer._gone ) {
			scale *= 1 - 0.06 * clamp( leaving, 0, 1 );
		}

		ctx.save();
		ctx.globalAlpha = alpha * clamp( +layer.opacity, 0, 1 );
		ctx.translate( cx + dx, cy + dy );
		if ( rot ) {
			ctx.rotate( rot );
		}
		ctx.scale( scale * ( 1 + stretch ), scale * ( 1 - stretch ) );
		var reveal = showcase && ! layer._gone && age < REVEAL_MS ? this._cardBox( layer, dw ) : null;
		if ( reveal ) {
			this._drawRings( ctx, age, reveal );
		}
		if ( isSource ) {
			this._drawInset( ctx, sprite, inset, layer, dw, dh );
		} else {
			ctx.drawImage( sprite, -dw / 2, -dh / 2, dw, dh );
		}
		if ( 'gallery' === layer.type ) {
			this._drawSlides( ctx, layer, now, dw, dh );
		}
		if ( reveal ) {
			this._drawSheen( ctx, age, reveal );
			this._drawSparks( ctx, layer, age, reveal );
		}
		ctx.restore();
	};

	/* ─ the big moment: rings of light opening out, a shine across the card, and a few sparks ─ */

	/* Where the card itself sits inside its sprite, around the middle of the layer. */
	Stage.prototype._cardBox = function ( layer, dw ) {
		if ( 'gallery' === layer.type ) {
			var g = dw / GALLERY.W;
			return { x: ( GALLERY.MX - GALLERY.W / 2 ) * g, y: ( GALLERY.MY - GALLERY.H / 2 ) * g, w: GALLERY.CARD_W * g, h: GALLERY.CARD_H * g, r: GALLERY.RADIUS * g };
		}
		var k = dw / 860;
		return { x: ( 12 - 430 ) * k, y: ( 10 - 192 ) * k, w: 836 * k, h: 358 * k, r: 26 * k };
	};

	Stage.prototype._drawRings = function ( ctx, age, box ) {
		var base = ctx.globalAlpha;
		var reach = Math.min( this.W, this.H ) * 0.09;
		[ [ 140, '#fff6ec' ], [ 460, ROSE ] ].forEach( function ( ring ) {
			var t = clamp( ( age - ring[ 0 ] ) / 1050, 0, 1 );
			if ( t <= 0 || t >= 1 ) {
				return;
			}
			var grow = easeOutCubic( t ) * reach;
			ctx.globalAlpha = base * ( 1 - t ) * 0.95;
			roundRect( ctx, box.x - grow, box.y - grow, box.w + grow * 2, box.h + grow * 2, box.r + grow );
			ctx.lineWidth = 2 + ( 1 - t ) * reach * 0.13;
			ctx.strokeStyle = ring[ 1 ];
			ctx.stroke();
		} );
		ctx.globalAlpha = base;
	};

	Stage.prototype._drawSheen = function ( ctx, age, box ) {
		var t = clamp( ( age - 420 ) / 820, 0, 1 );
		if ( t <= 0 || t >= 1 ) {
			return;
		}
		var band = box.w * 0.24;
		var at = box.x - band + ( box.w + band * 2 ) * easeInOut( t );
		var shine = ctx.createLinearGradient( at - band, box.y, at + band, box.y + box.h * 0.5 );
		shine.addColorStop( 0, 'rgba(240,196,168,0)' );
		shine.addColorStop( 0.5, 'rgba(240,196,168,.62)' );
		shine.addColorStop( 1, 'rgba(240,196,168,0)' );
		ctx.save();
		roundRect( ctx, box.x, box.y, box.w, box.h, box.r );
		ctx.clip();
		ctx.fillStyle = shine;
		ctx.fillRect( box.x, box.y, box.w, box.h );
		ctx.restore();
	};

	Stage.prototype._drawSparks = function ( ctx, layer, age, box ) {
		if ( ! layer._sparks ) {
			layer._sparks = [];
			for ( var n = 0; n < 16; n++ ) {
				var angle = rand( 0, Math.PI * 2 );
				var edge = 1 / Math.max( Math.abs( Math.cos( angle ) ), Math.abs( Math.sin( angle ) ) );
				layer._sparks.push( {
					cos: Math.cos( angle ),
					sin: Math.sin( angle ),
					edge: edge,
					far: rand( 0.05, 0.15 ) * Math.min( this.W, this.H ),
					size: rand( 0.012, 0.026 ) * Math.min( this.W, this.H ),
					delay: rand( 120, 620 ),
					life: rand( 620, 1100 ),
					turn: rand( -2, 2 ),
					colour: pick( [ '#fff6ec', '#f3d9c3', ROSE ] )
				} );
			}
		}
		var base = ctx.globalAlpha;
		var cx = box.x + box.w / 2;
		var cy = box.y + box.h / 2;
		layer._sparks.forEach( function ( spark ) {
			var t = ( age - spark.delay ) / spark.life;
			if ( t <= 0 || t >= 1 ) {
				return;
			}
			var out = easeOutCubic( t ) * spark.far;
			var size = spark.size * Math.sin( Math.PI * t );
			ctx.save();
			ctx.globalAlpha = base * ( t < 0.7 ? 1 : ( 1 - t ) / 0.3 );
			ctx.translate( cx + spark.cos * ( spark.edge * box.w / 2 + out ), cy + spark.sin * ( spark.edge * box.h / 2 + out ) );
			ctx.rotate( spark.turn * t );
			// Four points, drawn thin so it reads as a glint and not a star on a flag.
			ctx.beginPath();
			for ( var i = 0; i < 8; i++ ) {
				var r = i % 2 ? size * 0.22 : size;
				var a = i * Math.PI / 4;
				ctx[ i ? 'lineTo' : 'moveTo' ]( Math.cos( a ) * r, Math.sin( a ) * r );
			}
			ctx.closePath();
			ctx.fillStyle = spark.colour;
			ctx.fill();
			ctx.restore();
		} );
		ctx.globalAlpha = base;
	};

	/* ─ the photos of a piece ─ */

	/* The next photo that has loaded, this way round. Stays put if none has. */
	Stage.prototype.slide = function ( id, step ) {
		var layer = this.get( id );
		if ( ! layer || ! layer.images || layer.images.length < 2 ) {
			return;
		}
		var count = layer.images.length;
		for ( var i = 1; i < count; i++ ) {
			var at = ( ( layer.at || 0 ) + step * i + count * i ) % count;
			var entry = this.images[ layer.images[ at ] ];
			if ( entry && entry.ok ) {
				layer._from = layer.at || 0;
				layer._dir = step < 0 ? -1 : 1;
				layer.at = at;
				layer._slideT = performance.now();
				return;
			}
		}
	};

	Stage.prototype._drawFitted = function ( ctx, src, x, y, w, h, grow, shiftX ) {
		var entry = src ? this.images[ src ] : null;
		if ( ! entry || ! entry.ok ) {
			return;
		}
		var img = entry.img;
		var fit = Math.min( w / img.naturalWidth, h / img.naturalHeight ) * grow;
		var dw = img.naturalWidth * fit;
		var dh = img.naturalHeight * fit;
		ctx.drawImage( img, x + ( w - dw ) / 2 + shiftX, y + ( h - dh ) / 2, dw, dh );
	};

	Stage.prototype._drawSlides = function ( ctx, layer, now, dw, dh ) {
		var images = layer.images || [];
		if ( ! images.length ) {
			return;
		}
		layer._slideT = layer._slideT || now;
		if ( false !== layer.auto && ! layer._gone && now - layer._slideT > SLIDE_MS ) {
			this.slide( layer.id, 1 );
		}
		var k = dw / GALLERY.W;
		var px = ( GALLERY.PHOTO_X - GALLERY.W / 2 ) * k;
		var py = ( GALLERY.PHOTO_Y - GALLERY.H / 2 ) * k;
		var size = GALLERY.PHOTO * k;
		var shown = now - layer._slideT;
		var t = clamp( shown / SLIDE_IN_MS, 0, 1 );
		var move = easeOutCubic( t );
		var dir = layer._dir || 1;
		var base = ctx.globalAlpha;

		ctx.save();
		roundRect( ctx, px, py, size, size, 16 * k );
		ctx.clip();
		if ( t < 1 && undefined !== layer._from ) {
			ctx.globalAlpha = base * ( 1 - move );
			this._drawFitted( ctx, images[ layer._from ], px, py, size, size, 1.04, -move * size * 0.28 * dir );
		}
		// In from the side, large, settling; then a slow drift the whole time it is up.
		var drift = 1 + 0.045 * clamp( shown / SLIDE_MS, 0, 1 );
		ctx.globalAlpha = base * ( undefined === layer._from ? 1 : move );
		this._drawFitted( ctx, images[ layer.at || 0 ], px, py, size, size, drift + ( 1 - move ) * 0.1, undefined === layer._from ? 0 : ( 1 - move ) * size * 0.42 * dir );
		ctx.restore();
		ctx.globalAlpha = base;

		if ( images.length > 1 ) {
			var gap = 15 * k;
			var dot = 4.5 * k;
			var wide = gap * ( images.length - 1 );
			var dotY = py + size - 20 * k;
			var startX = px + size / 2 - wide / 2;
			roundRect( ctx, startX - dot * 2.4, dotY - dot * 2.2, wide + dot * 4.8, dot * 4.4, dot * 2.2 );
			ctx.fillStyle = 'rgba(43,31,27,.42)';
			ctx.fill();
			images.forEach( function ( src, i ) {
				ctx.beginPath();
				ctx.arc( startX + gap * i, dotY, i === ( layer.at || 0 ) ? dot * 1.2 : dot * 0.8, 0, Math.PI * 2 );
				ctx.fillStyle = i === ( layer.at || 0 ) ? '#fff' : 'rgba(255,255,255,.55)';
				ctx.fill();
			} );
		}
	};

	/* A camera in a corner: cropped to fill its shape, with a thin edge so it reads against the picture. */
	Stage.prototype._drawInset = function ( ctx, video, source, layer, dw, dh ) {
		var circle = 'circle' === layer.shape;
		var path = function () {
			if ( circle ) {
				ctx.beginPath();
				ctx.arc( 0, 0, dw / 2, 0, Math.PI * 2 );
				ctx.closePath();
			} else {
				roundRect( ctx, -dw / 2, -dh / 2, dw, dh, Math.min( dw, dh ) * 0.07 );
			}
		};
		var vw = video.videoWidth;
		var vh = video.videoHeight;
		var fit = Math.max( dw / vw, dh / vh ) * ( +source.zoom || 1 );
		var sw = dw / fit;
		var sh = dh / fit;
		ctx.save();
		path();
		ctx.clip();
		if ( source.flip ) {
			ctx.scale( -1, 1 );
		}
		ctx.drawImage( video, ( vw - sw ) / 2, ( vh - sh ) / 2, sw, sh, -dw / 2, -dh / 2, dw, dh );
		ctx.restore();
		path();
		ctx.lineWidth = Math.max( 2, Math.min( this.W, this.H ) * 0.005 );
		ctx.strokeStyle = 'rgba(247,242,237,.92)';
		ctx.stroke();
	};

	/* ─────────────────────────── drawing a frame ─────────────────────────── */

	/* The part of a source that shows and where it goes, or null if it has no picture yet. */
	Stage.prototype._box = function ( source ) {
		var video = source.video;
		if ( ! video || ! video.videoWidth || video.readyState < 2 ) {
			return null;
		}
		var W = this.W;
		var H = this.H;
		var vw = video.videoWidth;
		var vh = video.videoHeight;
		var whole = 'contain' === source.fit;
		var fit = whole ? Math.min( W / vw, H / vh ) : Math.max( W / vw, H / vh );
		var scale = fit * ( +source.zoom || 1 );
		// The part of the camera that fills the frame: zooming in crops from the middle.
		var sw = Math.min( vw, W / scale );
		var sh = Math.min( vh, H / scale );
		var dw = Math.round( sw * scale );
		var dh = Math.round( sh * scale );
		// Whole pixels, so the picture lands in the same place with or without the grader.
		return { sx: ( vw - sw ) / 2, sy: ( vh - sh ) / 2, sw: sw, sh: sh, dx: Math.round( ( W - dw ) / 2 ), dy: Math.round( ( H - dh ) / 2 ), dw: dw, dh: dh };
	};

	Stage.prototype._drawCamera = function ( ctx ) {
		var source = this.sources[ this.main ];
		var W = this.W;
		var H = this.H;
		var box = source ? this._box( source ) : null;
		if ( box ) {
			var video = source.video;
			if ( box.dw < W || box.dh < H ) {
				ctx.fillStyle = '#17110f'; // the bars round a whole screen shown on a differently shaped stream
				ctx.fillRect( 0, 0, W, H );
			}
			ctx.save();
			if ( source.flip ) {
				ctx.translate( W, 0 );
				ctx.scale( -1, 1 );
			}
			// A shared screen keeps its true colours: a price or a photo on it should look as it does.
			var dress = 'screen' !== source.kind && ! this.compare;
			if ( dress && this.canFilter && this._filter ) {
				ctx.filter = this._filter;
			}
			var graded = dress && this.canGrade && Object.keys( this.adjust ).length ? this.grader.run( video, box, W, H, this.adjust ) : null;
			if ( graded ) {
				ctx.drawImage( graded, box.dx, box.dy, box.dw, box.dh, box.dx, box.dy, box.dw, box.dh );
			} else {
				ctx.drawImage( video, box.sx, box.sy, box.sw, box.sh, box.dx, box.dy, box.dw, box.dh );
			}
			ctx.restore();
			return;
		}
		// No picture: the shop's colours and a line, rather than black on a live stream.
		if ( ! this._blank ) {
			var c = makeCanvas( W, H );
			var x = c.getContext( '2d' );
			var g = x.createLinearGradient( 0, 0, W, H );
			g.addColorStop( 0, '#2b1f1b' );
			g.addColorStop( 0.6, '#4b342c' );
			g.addColorStop( 1, '#7a5548' );
			x.fillStyle = g;
			x.fillRect( 0, 0, W, H );
			x.fillStyle = 'rgba(247,242,237,.86)';
			x.font = 'italic 500 ' + Math.round( Math.min( W, H ) * 0.075 ) + 'px ' + SERIF;
			x.textAlign = 'center';
			x.textBaseline = 'middle';
			x.fillText( this.placeholder, W / 2, H / 2 );
			this._blank = c;
		}
		ctx.drawImage( this._blank, 0, 0 );
	};

	/*
	 * Drawn once and kept: a coloured edge, a fine line inside it, the words on a ribbon at the top, and pictures in the
	 * corners, largest on the right because the piece's card sits bottom left and the logo top left.
	 */
	Stage.prototype._buildOccasion = function ( o ) {
		var W = this.W;
		var H = this.H;
		var u = Math.min( W, H );
		var tall = H > W; // a phone's picture: less room across, so the words are smaller and the second picture goes lower
		var c = makeCanvas( W, H );
		var x = c.getContext( '2d' );

		var edge = u * 0.024;
		var band = x.createLinearGradient( 0, 0, W, H );
		band.addColorStop( 0, o.band[ 0 ] );
		band.addColorStop( 1, o.band[ 1 ] );
		x.strokeStyle = band;
		x.lineWidth = edge * 2;
		x.strokeRect( 0, 0, W, H );
		x.strokeStyle = o.line;
		x.lineWidth = Math.max( 1.5, u * 0.0032 );
		var gap = edge + u * 0.009;
		roundRect( x, gap, gap, W - gap * 2, H - gap * 2, u * 0.012 );
		x.stroke();

		var art = o.art || [];
		var images = this.images;
		[
			[ 0, W - u * 0.105, u * 0.11, 0.21, 0.14 ],
			[ 1, tall ? W - u * 0.08 : W - u * 0.245, tall ? u * 0.265 : u * 0.065, 0.1, -0.2 ],
			[ 2, W - u * 0.105, H - u * 0.11, 0.21, -0.1 ],
			[ 3, W - u * 0.245, H - u * 0.06, 0.095, 0.18 ],
			[ 4, u * 0.062, u * 0.27, 0.09, -0.16 ],
			[ 5, u * 0.062, H - u * 0.065, 0.09, 0.12 ]
		].forEach( function ( place ) {
			var entry = art[ place[ 0 ] ] ? images[ art[ place[ 0 ] ] ] : null;
			if ( ! entry || ! entry.ok ) {
				return;
			}
			var size = u * place[ 3 ];
			x.save();
			x.shadowColor = 'rgba(0,0,0,.38)';
			x.shadowBlur = u * 0.014;
			x.shadowOffsetY = u * 0.004;
			x.translate( place[ 1 ], place[ 2 ] );
			x.rotate( place[ 4 ] );
			x.drawImage( entry.img, -size / 2, -size / 2, size, size );
			x.restore();
		} );

		var words = String( o.words || '' ).trim();
		if ( words ) {
			var bold = 'sans' === o.font;
			var size = u * ( bold ? ( tall ? 0.04 : 0.048 ) : ( tall ? 0.05 : 0.062 ) );
			var font = ( bold ? '800 ' : 'italic 500 ' ) + size + 'px ' + ( bold ? SANS : SERIF );
			x.font = font;
			spaced( x, '', bold ? u * 0.006 : 0 );
			var text = bold ? words.toUpperCase() : words;
			var room = W * ( tall ? 0.46 : 0.56 );
			var wide = Math.min( x.measureText( text ).width, room );
			var h = u * ( tall ? 0.078 : 0.092 );
			var w = wide + u * ( tall ? 0.1 : 0.15 );
			var left = ( W - w ) / 2;
			var top = u * 0.018;

			x.save();
			x.shadowColor = 'rgba(0,0,0,.42)';
			x.shadowBlur = u * 0.022;
			x.shadowOffsetY = u * 0.006;
			var ribbon = x.createLinearGradient( left, 0, left + w, 0 );
			ribbon.addColorStop( 0, o.ribbon[ 0 ] );
			ribbon.addColorStop( 1, o.ribbon[ 1 ] );
			roundRect( x, left, top, w, h, h / 2 );
			x.fillStyle = ribbon;
			x.fill();
			x.restore();

			roundRect( x, left + u * 0.007, top + u * 0.007, w - u * 0.014, h - u * 0.014, ( h - u * 0.014 ) / 2 );
			x.strokeStyle = o.line;
			x.lineWidth = Math.max( 1, u * 0.002 );
			x.stroke();

			x.fillStyle = o.ink;
			x.textAlign = 'center';
			x.textBaseline = 'middle';
			x.fillText( text, W / 2, top + h / 2 + u * 0.002, room );
			x.textAlign = 'left';
			spaced( x, '', 0 );

			// A glint either side of the words.
			var inset = u * ( tall ? 0.028 : 0.045 );
			[ left + inset, left + w - inset ].forEach( function ( at ) {
				var r = u * ( tall ? 0.011 : 0.014 );
				x.beginPath();
				for ( var i = 0; i < 8; i++ ) {
					var reach = i % 2 ? r * 0.26 : r;
					var a = i * Math.PI / 4;
					x[ i ? 'lineTo' : 'moveTo' ]( at + Math.cos( a ) * reach, top + h / 2 + Math.sin( a ) * reach );
				}
				x.closePath();
				x.fillStyle = o.line;
				x.fill();
			} );
		}
		return c;
	};

	Stage.prototype._drawFrame = function ( ctx, now ) {
		if ( this.occasion ) {
			if ( ! this._occasionFrame ) {
				this._occasionFrame = this._buildOccasion( this.occasion );
			}
			ctx.drawImage( this._occasionFrame, 0, 0 );
			return;
		}
		var def = FRAMES[ this.frame ];
		if ( ! def || ! def.draw ) {
			return;
		}
		var W = this.W;
		var H = this.H;
		var u = Math.min( W, H );
		var o = this.frameOptions( this.frame, this.frameTint );
		var sig = this.frame + '|' + this.frameTint + '|' + o.words;
		if ( this._frameSig !== sig ) {
			var c = this._frameCanvas || ( this._frameCanvas = makeCanvas( W, H ) );
			c.width = W; // empties it, and whatever a frame before this left set
			def.draw( c.getContext( '2d' ), W, H, u, o );
			this._frameSig = sig;
			this._frameLive = {};
		}
		ctx.drawImage( this._frameCanvas, 0, 0 );
		if ( def.live ) {
			ctx.save();
			def.live( ctx, W, H, u, now / 1000, this._frameLive, o );
			ctx.restore();
		}
	};

	Stage.prototype._drawLight = function ( ctx, now ) {
		var def = LIGHTS[ this.light ];
		if ( ! def || ! def.draw || this.lightLevel <= 0 ) {
			return;
		}
		if ( this._lightKey !== this.light ) {
			this._lightKey = this.light;
			this._lightCache = {};
		}
		ctx.save();
		def.draw( ctx, this.W, this.H, Math.min( this.W, this.H ), now / 1000, this._lightCache, this.lightLevel );
		ctx.restore();
	};

	/* ─────────────────────────── small previews, for the tiles ─────────────────────────── */

	/* A small copy of the camera as it comes, for the tiles to show what a look or a frame does to this picture. */
	Stage.prototype.takeStill = function () {
		var w = Math.round( this.W / 3 );
		var h = Math.round( this.H / 3 );
		var c = this._still || ( this._still = makeCanvas( w, h ) );
		var x = c.getContext( '2d' );
		var source = this.sources[ this.main ];
		var box = source ? this._box( source ) : null;
		x.setTransform( 1, 0, 0, 1, 0, 0 );
		if ( box ) {
			x.fillStyle = '#17110f';
			x.fillRect( 0, 0, w, h );
			x.setTransform( source.flip ? -1 / 3 : 1 / 3, 0, 0, 1 / 3, source.flip ? w : 0, 0 );
			x.drawImage( source.video, box.sx, box.sy, box.sw, box.sh, box.dx, box.dy, box.dw, box.dh );
		} else {
			var g = x.createLinearGradient( 0, 0, w, h );
			g.addColorStop( 0, '#8c6f62' );
			g.addColorStop( 0.5, '#d9bfae' );
			g.addColorStop( 1, '#6f5348' );
			x.fillStyle = g;
			x.fillRect( 0, 0, w, h );
		}
		return c;
	};

	/* @param {Object} spec Any of look, light, frame, tint. Draws on the still from takeStill(). */
	Stage.prototype.paintThumb = function ( canvas, spec ) {
		var w = canvas.width;
		var h = canvas.height;
		var x = canvas.getContext( '2d' );
		var look = LOOKS[ spec.look ];
		var still = this._still || this.takeStill();
		x.save();
		if ( this.canFilter ) {
			x.filter = look && look.filter ? look.filter : 'none';
		}
		x.imageSmoothingQuality = 'high';
		x.drawImage( still, 0, 0, w, h );
		x.restore();
		var light = LIGHTS[ spec.light ];
		if ( light && light.draw ) {
			x.save();
			light.draw( x, w, h, Math.min( w, h ), 1.7, {}, LIGHT_LEVEL );
			x.restore();
		}
		var def = FRAMES[ spec.frame ];
		if ( def && def.draw ) {
			// Drawn at a quarter of its size and shrunk, so its fine lines come out as they will on stage.
			var fw = Math.round( this.W / 4 );
			var fh = Math.round( this.H / 4 );
			var fu = Math.min( fw, fh );
			var c = this._thumbFrame || ( this._thumbFrame = makeCanvas( fw, fh ) );
			c.width = fw;
			var f = c.getContext( '2d' );
			var o = this.frameOptions( spec.frame, spec.tint );
			def.draw( f, fw, fh, fu, o );
			if ( def.live ) {
				f.save();
				def.live( f, fw, fh, fu, 3.2, {}, o );
				f.restore();
			}
			x.imageSmoothingQuality = 'high';
			x.drawImage( c, 0, 0, w, h );
		}
	};

	/* ─────────────────────────── the cover ─────────────────────────── */

	/**
	 * Cover the picture. On is immediate: this is for when something has gone wrong, and a fade would
	 * show another half second of it. Off fades, because nothing is urgent by then.
	 */
	Stage.prototype.setCover = function ( on, line ) {
		var was = this.cover;
		this.cover = !! on;
		if ( 'string' === typeof line ) {
			this.coverLine = line;
			this._coverText = null;
		}
		if ( this.cover ) {
			this.particles = []; // nothing left to burst out from behind it afterwards
			this._coverOff = 0;
			this._snapMs = 0;
		} else if ( was ) {
			this._coverOff = performance.now();
		}
		this.changed();
	};

	Stage.prototype._buildCover = function () {
		var W = this.W;
		var H = this.H;

		var base = makeCanvas( W, H );
		var x = base.getContext( '2d' );
		var g = x.createLinearGradient( 0, 0, W, H );
		g.addColorStop( 0, '#2b1f1b' );
		g.addColorStop( 0.55, '#3f2c26' );
		g.addColorStop( 1, '#5e4238' );
		x.fillStyle = g;
		x.fillRect( 0, 0, W, H );
		this._coverBase = base;

		// Soft light in the shop's colours, drawn once and only moved after that.
		var orb = function ( colour ) {
			var c = makeCanvas( 512, 512 );
			var o = c.getContext( '2d' );
			var r = o.createRadialGradient( 256, 256, 0, 256, 256, 256 );
			r.addColorStop( 0, colour );
			r.addColorStop( 1, 'rgba(0,0,0,0)' );
			o.fillStyle = r;
			o.fillRect( 0, 0, 512, 512 );
			return c;
		};
		this._coverOrbs = [
			{ sprite: orb( 'rgba(185,139,126,.62)' ), size: 1.15, ax: 0.32, ay: 0.3, rx: 0.17, ry: 0.11, phase: 0 },
			{ sprite: orb( 'rgba(242,213,203,.3)' ), size: 0.8, ax: 0.7, ay: 0.68, rx: 0.14, ry: 0.15, phase: 2.1 },
			{ sprite: orb( 'rgba(156,111,99,.55)' ), size: 1.3, ax: 0.78, ay: 0.22, rx: 0.1, ry: 0.13, phase: 4.2 },
			{ sprite: orb( 'rgba(247,242,237,.14)' ), size: 0.7, ax: 0.24, ay: 0.78, rx: 0.12, ry: 0.09, phase: 1.1 }
		];

		this._coverSparks = [];
		for ( var i = 0; i < 26; i++ ) {
			this._coverSparks.push( {
				x: Math.random(),
				y: Math.random(),
				r: rand( 1.2, 3.2 ),
				rate: rand( 0.3, 1 ),
				phase: rand( 0, Math.PI * 2 ),
				drift: rand( 0.004, 0.014 )
			} );
		}
	};

	Stage.prototype._coverLineSprite = function () {
		if ( this._coverText ) {
			return this._coverText;
		}
		var size = Math.round( Math.min( this.W, this.H ) * 0.062 );
		var probe = makeCanvas( 4, 4 ).getContext( '2d' );
		var font = 'italic 500 ' + size + 'px ' + SERIF;
		probe.font = font;
		var c = makeCanvas( Math.min( this.W, probe.measureText( this.coverLine ).width + 40 ), size * 1.6 );
		var x = c.getContext( '2d' );
		x.font = font;
		x.fillStyle = 'rgba(247,242,237,.92)';
		x.textAlign = 'center';
		x.textBaseline = 'middle';
		x.fillText( this.coverLine, c.width / 2, c.height / 2, c.width - 8 );
		this._coverText = c;
		return c;
	};

	Stage.prototype._drawCover = function ( ctx, now, alpha ) {
		if ( ! this._coverBase ) {
			this._buildCover();
		}
		var W = this.W;
		var H = this.H;
		var U = Math.min( W, H );
		var t = now / 1000;
		var i;

		ctx.save();
		ctx.globalAlpha = alpha;
		ctx.drawImage( this._coverBase, 0, 0 );

		ctx.globalCompositeOperation = 'screen';
		for ( i = 0; i < this._coverOrbs.length; i++ ) {
			var orb = this._coverOrbs[ i ];
			var d = Math.max( W, H ) * orb.size;
			var ox = ( orb.ax + Math.sin( t * 0.23 + orb.phase ) * orb.rx ) * W;
			var oy = ( orb.ay + Math.cos( t * 0.19 + orb.phase ) * orb.ry ) * H;
			ctx.drawImage( orb.sprite, ox - d / 2, oy - d / 2, d, d );
		}
		ctx.globalCompositeOperation = 'source-over';

		for ( i = 0; i < this._coverSparks.length; i++ ) {
			var s = this._coverSparks[ i ];
			var sy = ( ( s.y - t * s.drift ) % 1 + 1 ) % 1;
			ctx.globalAlpha = alpha * ( 0.15 + 0.5 * ( 0.5 + 0.5 * Math.sin( t * s.rate * 2 + s.phase ) ) );
			ctx.fillStyle = '#f2d5cb';
			ctx.beginPath();
			ctx.arc( s.x * W, sy * H, s.r * U / 720, 0, Math.PI * 2 );
			ctx.fill();
		}

		var cx = W / 2;
		var cy = H * 0.46;
		var ring = U * 0.235;

		// Two thin arcs turning slowly round the mark: alive, without asking to be looked at.
		ctx.lineCap = 'round';
		ctx.lineWidth = Math.max( 1.5, U * 0.0028 );
		ctx.globalAlpha = alpha * 0.8;
		ctx.strokeStyle = '#d9b3a6';
		ctx.beginPath();
		ctx.arc( cx, cy, ring, t * 0.5, t * 0.5 + Math.PI * 1.25 );
		ctx.stroke();
		ctx.globalAlpha = alpha * 0.4;
		ctx.strokeStyle = '#f7f2ed';
		ctx.beginPath();
		ctx.arc( cx, cy, ring * 1.1, -t * 0.34, -t * 0.34 + Math.PI * 0.8 );
		ctx.stroke();

		ctx.globalAlpha = alpha;
		var breath = 1 + Math.sin( t * 1.2 ) * 0.016;
		var logo = this.coverLogo && this.images[ this.coverLogo ] && this.images[ this.coverLogo ].ok ? this.images[ this.coverLogo ].img : null;
		if ( logo ) {
			var lw = U * 0.3 * breath;
			var lh = lw * ( logo.naturalHeight / ( logo.naturalWidth || 1 ) );
			ctx.drawImage( logo, cx - lw / 2, cy - lh / 2, lw, lh );
		} else if ( this.brand ) {
			ctx.font = '500 ' + Math.round( U * 0.075 * breath ) + 'px ' + SERIF;
			ctx.fillStyle = '#f7f2ed';
			ctx.textAlign = 'center';
			ctx.textBaseline = 'middle';
			ctx.fillText( this.brand, cx, cy, ring * 1.7 );
		}

		if ( this.coverLine ) {
			var line = this._coverLineSprite();
			ctx.globalAlpha = alpha * ( 0.82 + 0.18 * Math.sin( t * 1.2 ) );
			ctx.drawImage( line, cx - line.width / 2, cy + ring * 1.28 );
			if ( this.coverLink ) {
				// Where to watch and shop, for anybody arriving on another platform before it starts.
				ctx.globalAlpha = alpha * 0.85;
				ctx.fillStyle = '#e9c8bc';
				ctx.font = '600 ' + Math.round( U * 0.026 ) + 'px ' + SANS;
				ctx.textAlign = 'center';
				ctx.textBaseline = 'top';
				ctx.fillText( this.coverLink, cx, cy + ring * 1.28 + line.height + U * 0.01, W * 0.9 );
			}
		}
		ctx.restore();
	};

	/* ─────────────────────────── a frame ─────────────────────────── */

	Stage.prototype.render = function ( now ) {
		var ctx = this.ctx;
		var dt = this._last ? Math.min( 0.1, ( now - this._last ) / 1000 ) : 1 / FPS;
		this._last = now;

		// Nothing of the camera is drawn at all while covered, not even underneath.
		if ( this.cover ) {
			this._drawCover( ctx, now, 1 );
			return;
		}

		this._drawCamera( ctx );

		if ( ! this.hideGraphics ) {
			this._drawGraphics( ctx, now, dt );
		}

		// The last frame of what was on before a switch, fading away over the new one.
		if ( this._snapMs ) {
			var left = 1 - ( now - this._snapFrom ) / this._snapMs;
			if ( left > 0 ) {
				ctx.save();
				ctx.globalAlpha = left;
				ctx.drawImage( this._snap, 0, 0 );
				ctx.restore();
			} else {
				this._snapMs = 0;
			}
		}

		if ( this._coverOff ) {
			var fade = 1 - ( now - this._coverOff ) / 450;
			if ( fade > 0 ) {
				this._drawCover( ctx, now, fade );
			} else {
				this._coverOff = 0;
			}
		}
	};

	Stage.prototype._drawGraphics = function ( ctx, now, dt ) {
		this._drawLight( ctx, now );
		this._drawFrame( ctx, now );

		var drop = false;
		for ( var i = 0; i < this.layers.length; i++ ) {
			var layer = this.layers[ i ];
			if ( layer._gone && now - layer._gone > 280 ) {
				drop = true;
				continue;
			}
			if ( false !== layer.visible ) {
				this._drawLayer( ctx, layer, now );
			} else {
				layer._box = null;
			}
		}
		if ( drop ) {
			var dropped = this.layers.filter( function ( l ) {
				return l._gone && now - l._gone > 280;
			} );
			this.layers = this.layers.filter( function ( l ) {
				return ! ( l._gone && now - l._gone > 280 );
			} );
			dropped.forEach( function ( l ) {
				if ( l.src ) {
					this._releaseFrames( l.src );
				}
			}, this );
		}

		this._runRain( dt );
		this._drawParticles( ctx, dt );
	};

	root.WHDStage = {
		Stage: Stage,
		LOOKS: LOOKS,
		FRAMES: FRAMES,
		FRAME_GROUPS: root.WHDFrames.FRAME_GROUPS,
		TINTS: TINTS,
		LIGHTS: LIGHTS,
		THEMES: root.WHDFrames.THEMES,
		LIGHT_LEVEL: LIGHT_LEVEL,
		ADJUST: GRADE.ADJUST,
		EFFECTS: EFFECTS,
		ANIMS: ANIMS
	};
}( window ) );
